<?php

require_once __DIR__ . '/../config/database.php';

class Hearing
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function getAll(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date,
                    h.venue, h.remarks, h.created_at, h.updated_at,
                    c.case_number, c.case_status, c.docket_date,
                    co.complaint_number, co.complaint_title
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             ORDER BY h.hearing_date ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT h.*, c.case_number, c.case_status, c.docket_date,
                    co.complaint_number, co.complaint_title
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE h.hearing_id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getCase(int $caseId): array|false
    {
        $stmt = $this->conn->prepare(
            'SELECT case_id, case_status, docket_date FROM cases WHERE case_id = ?'
        );
        $stmt->execute([$caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findConflict(
        string $hearingDate,
        string $venue,
        int $caseId,
        ?int $excludeHearingId = null
    ): array|false {
        $sql = "SELECT h.hearing_id, h.case_id, h.hearing_date, h.venue, c.case_number
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                WHERE h.hearing_date = ?
                  AND (LOWER(TRIM(h.venue)) = LOWER(TRIM(?)) OR h.case_id = ?)";
        $params = [$hearingDate, $venue, $caseId];

        if ($excludeHearingId !== null) {
            $sql .= ' AND h.hearing_id <> ?';
            $params[] = $excludeHearingId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createProgression(array $data, ?array $deadline, int $userId): array
    {
        try {
            $this->conn->beginTransaction();
            $case = $this->conn->prepare('SELECT case_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
            $case->execute([$data['case_id']]);
            if (!$case->fetch(PDO::FETCH_ASSOC)) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'The selected case does not exist.'];
            }

            $counts = $this->conn->prepare(
                "SELECT hearing_type, COUNT(*) AS total FROM hearings WHERE case_id = ? AND hearing_type IN ('Mediation', 'Conciliation') GROUP BY hearing_type"
            );
            $counts->execute([$data['case_id']]);
            $scheduled = ['Mediation' => 0, 'Conciliation' => 0];
            foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $scheduled[$row['hearing_type']] = (int) $row['total'];
            }

            $expectedType = $scheduled['Mediation'] < 3 ? 'Mediation' : ($scheduled['Conciliation'] < 3 ? 'Conciliation' : null);
            if ($expectedType === null) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'All three mediation and all three conciliation schedules have already been completed for this case.'];
            }
            if ($data['hearing_type'] !== $expectedType) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'The next required schedule is ' . ($scheduled[$expectedType] + 1) . ($scheduled[$expectedType] === 0 ? 'st ' : ($scheduled[$expectedType] === 1 ? 'nd ' : 'rd ')) . $expectedType . '.'];
            }

            $stmt = $this->conn->prepare(
                'INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, remarks)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['case_id'],
                $data['hearing_type'],
                $data['hearing_date'],
                $data['venue'],
                $data['remarks'],
            ]);
            $hearingId = (int) $this->conn->lastInsertId();

            if ($deadline !== null) {
                $this->upsertDeadline(
                    (int) $data['case_id'],
                    $deadline['deadline_type'],
                    $deadline['due_date']
                );
            }

            if ($expectedType === 'Conciliation' && $scheduled['Conciliation'] === 0) {
                $status = $this->conn->prepare("UPDATE cases SET case_status = 'Conciliation' WHERE case_id = ?");
                $status->execute([$data['case_id']]);
                $complaintStatus = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Conciliation' WHERE c.case_id = ?");
                $complaintStatus->execute([$data['case_id']]);
                $history = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Conciliation', ?, ?)");
                $history->execute([$data['case_id'], 'Case moved to conciliation when the 1st Conciliation was scheduled.', $userId]);
            }

            $this->conn->commit();
            return ['success' => true, 'hearing_id' => $hearingId, 'sequence' => $scheduled[$expectedType] + 1, 'hearing_type' => $expectedType];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $exception;
        }
    }

    public function update(int $id, array $data, ?array $deadline): bool
    {
        try {
            $this->conn->beginTransaction();
            $stmt = $this->conn->prepare(
                'UPDATE hearings
                 SET hearing_type = ?, hearing_date = ?, venue = ?, remarks = ?
                 WHERE hearing_id = ?'
            );
            $stmt->execute([
                $data['hearing_type'],
                $data['hearing_date'],
                $data['venue'],
                $data['remarks'],
                $id,
            ]);

            if ($deadline !== null) {
                $this->upsertDeadline(
                    (int) $data['case_id'],
                    $deadline['deadline_type'],
                    $deadline['due_date']
                );
            }

            $this->conn->commit();
            return $stmt->rowCount() > 0;
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $exception;
        }
    }

    public function getDeadlines(?int $caseId = null): array
    {
        $sql = "SELECT d.deadline_id, d.case_id, d.deadline_type, d.due_date,
                       CASE
                            WHEN d.status = 'Completed' THEN 'Completed'
                            WHEN d.due_date < CURDATE() THEN 'Overdue'
                            ELSE 'Pending'
                       END AS status,
                       d.completed_at, c.case_number
                FROM case_deadlines d
                INNER JOIN cases c ON c.case_id = d.case_id";
        $params = [];
        if ($caseId !== null) {
            $sql .= ' WHERE d.case_id = ?';
            $params[] = $caseId;
        }
        $sql .= ' ORDER BY d.due_date ASC, d.deadline_id ASC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPaginatedCombined(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $baseSql = "
        SELECT
            'hearing' AS record_type,
            h.hearing_id AS record_id,
            h.case_id,
            c.case_number,
            co.complaint_number,
            co.complaint_title,
            h.hearing_type AS raw_type,
            CASE
                WHEN h.hearing_type IN ('Mediation', 'Conciliation') THEN
                    CONCAT(
                        CASE ROW_NUMBER() OVER (PARTITION BY h.case_id, h.hearing_type ORDER BY h.created_at ASC, h.hearing_id ASC)
                            WHEN 1 THEN '1st '
                            WHEN 2 THEN '2nd '
                            WHEN 3 THEN '3rd '
                            ELSE CONCAT(ROW_NUMBER() OVER (PARTITION BY h.case_id, h.hearing_type ORDER BY h.created_at ASC, h.hearing_id ASC), 'th ')
                        END,
                        h.hearing_type
                    )
                ELSE h.hearing_type
            END AS hearing_type,
            h.hearing_date AS schedule_date,
            h.venue,
            CASE WHEN h.hearing_date < NOW() THEN 'Completed' ELSE 'Scheduled' END AS status,
            h.remarks,
            h.created_at
        FROM hearings h
        LEFT JOIN cases c ON c.case_id = h.case_id
        LEFT JOIN complaints co ON co.complaint_id = c.complaint_id

        UNION ALL

        SELECT
            'deadline' AS record_type,
            d.deadline_id AS record_id,
            d.case_id,
            c.case_number,
            co.complaint_number,
            co.complaint_title,
            d.deadline_type AS raw_type,
            d.deadline_type AS hearing_type,
            CAST(CONCAT(d.due_date, ' 00:00:00') AS DATETIME) AS schedule_date,
            NULL AS venue,
            CASE
                WHEN d.status = 'Completed' THEN 'Completed'
                WHEN d.due_date < CURDATE() THEN 'Overdue'
                ELSE 'Pending'
            END AS status,
            NULL AS remarks,
            d.created_at
        FROM case_deadlines d
        LEFT JOIN cases c ON c.case_id = d.case_id
        LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
        ";

        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(case_number LIKE :kw OR complaint_number LIKE :kw OR complaint_title LIKE :kw OR venue LIKE :kw OR hearing_type LIKE :kw)';
            $params[':kw'] = '%' . $q . '%';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['Scheduled', 'Pending', 'Completed', 'Overdue'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $hearingType = trim((string) ($filters['hearing_type'] ?? ''));
        $allowedTypes = [
            'Mediation', 'Conciliation', 'Initial Hearing', 'Arbitration',
            'Mediation Period', 'Conciliation Period', 'Conciliation Extension'
        ];
        if (in_array($hearingType, $allowedTypes, true)) {
            $where[] = '(raw_type = :htype OR hearing_type = :htype)';
            $params[':htype'] = $hearingType;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = 'DATE(schedule_date) >= :date_from';
            $params[':date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = 'DATE(schedule_date) <= :date_to';
            $params[':date_to'] = $dateTo;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM ($baseSql) AS combined $whereClause";
        $stmt = $this->conn->prepare($countSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $totalRecords = (int) $stmt->fetchColumn();

        $totalPages = $totalRecords > 0 ? (int) ceil($totalRecords / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $perPage;

        $dataSql = "SELECT * FROM ($baseSql) AS combined $whereClause ORDER BY schedule_date ASC, record_id ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->conn->prepare($dataSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'records' => $records,
            'pagination' => [
                'total_records' => $totalRecords,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
            ],
        ];
    }

    private function upsertDeadline(int $caseId, string $type, string $dueDate): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
             VALUES (?, ?, ?, 'Pending')
             ON DUPLICATE KEY UPDATE
                due_date = VALUES(due_date),
                status = IF(status = 'Completed', 'Completed', 'Pending'),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$caseId, $type, $dueDate]);
    }
}
