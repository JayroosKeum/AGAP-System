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
