<?php

require_once __DIR__ . '/../config/database.php';

class Hearing
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    public function getAll(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date,
                    h.venue, h.remarks, h.rescheduled_from_id, h.reschedule_reason,
                    h.status, h.created_at, h.updated_at,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id
                    ) THEN 1 ELSE 0 END AS is_superseded,
                    (SELECT next_h.hearing_id FROM hearings next_h
                     WHERE next_h.rescheduled_from_id = h.hearing_id
                     ORDER BY next_h.created_at DESC, next_h.hearing_id DESC LIMIT 1) AS superseded_by_id,
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
                    co.complaint_number, co.complaint_title,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id
                    ) THEN 1 ELSE 0 END AS is_superseded,
                    (SELECT next_h.hearing_id FROM hearings next_h
                     WHERE next_h.rescheduled_from_id = h.hearing_id
                     ORDER BY next_h.created_at DESC, next_h.hearing_id DESC LIMIT 1) AS superseded_by_id
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
            $caseQuery = $this->conn->prepare('SELECT * FROM cases WHERE case_id = ? FOR UPDATE');
            $caseQuery->execute([$data['case_id']]);
            $caseRecord = $caseQuery->fetch(PDO::FETCH_ASSOC);
            if (!$caseRecord) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'The selected case does not exist.'];
            }

            // Statutory mediation safeguards
            if ($data['hearing_type'] === 'Mediation') {
                require_once __DIR__ . '/../services/MediationDeadlineService.php';
                $deadlineService = new MediationDeadlineService($this->conn);
                $statusInfo = $deadlineService->computeStatus($caseRecord);

                if (!empty($caseRecord['is_paused'])) {
                    $this->conn->rollBack();
                    $reason = $caseRecord['pause_reason'] ?: 'Suspended';
                    return [
                        'success' => false,
                        'message' => "The mediation clock is currently paused ({$reason}). Please resume the mediation clock before scheduling a hearing."
                    ];
                }

                if ($statusInfo['is_lapsed']) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'message' => 'Mediation period has lapsed (15-day limit reached). You must elevate the case to Pangkat Tagapagkasundo or issue a Certificate to File Action (CFA).'
                    ];
                }
            }

            $counts = $this->conn->prepare(
                "SELECT hearing_type, COUNT(*) AS total FROM hearings WHERE case_id = ? AND hearing_type IN ('Mediation', 'Conciliation') GROUP BY hearing_type"
            );
            $counts->execute([$data['case_id']]);
            $scheduled = ['Mediation' => 0, 'Conciliation' => 0];
            foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $scheduled[$row['hearing_type']] = (int) $row['total'];
            }

            if ($caseRecord['case_status'] === 'Conciliation') {
                $expectedType = $scheduled['Conciliation'] < 3 ? 'Conciliation' : null;
            } else {
                $expectedType = $scheduled['Mediation'] < 3 ? 'Mediation' : ($scheduled['Conciliation'] < 3 ? 'Conciliation' : null);
            }

            if ($expectedType === null) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'All three mediation and all three conciliation schedules have already been completed for this case.'];
            }
            if ($data['hearing_type'] !== $expectedType) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'The next required schedule is ' . ($scheduled[$expectedType] + 1) . ($scheduled[$expectedType] === 0 ? 'st ' : ($scheduled[$expectedType] === 1 ? 'nd ' : 'rd ')) . $expectedType . '.'];
            }

            // Conciliation case team validation: Lupon team must be chosen first; Administrator is for Mediation only.
            if ($expectedType === 'Conciliation' || $data['hearing_type'] === 'Conciliation') {
                require_once __DIR__ . '/Assignment.php';
                $assignmentModel = new Assignment();
                $teamValidation = $assignmentModel->validateConciliationTeam((int) $data['case_id']);
                if (!$teamValidation['valid']) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'message' => $teamValidation['message']
                    ];
                }
            }

            // Initialize clock if 1st Mediation hearing
            if ($expectedType === 'Mediation' && empty($caseRecord['mediation_start_date'])) {
                require_once __DIR__ . '/../services/MediationDeadlineService.php';
                (new MediationDeadlineService($this->conn))->initializeClock((int) $data['case_id'], $data['hearing_date'], $userId);
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
            $reason = trim((string)($data['reschedule_reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 2000) throw new InvalidArgumentException('A rescheduling reason up to 2,000 characters is required.');
            $stmt = $this->conn->prepare(
                'INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, remarks, rescheduled_from_id, reschedule_reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['case_id'],
                $data['hearing_type'],
                $data['hearing_date'],
                $data['venue'],
                $data['remarks'],
                $id,
                $reason,
            ]);

            if ($deadline !== null) {
                $this->upsertDeadline(
                    (int) $data['case_id'],
                    $deadline['deadline_type'],
                    $deadline['due_date']
                );
            }

            $this->conn->commit();
            $newId = (int)$this->conn->lastInsertId();
            $history = $this->conn->prepare("INSERT INTO case_history (case_id,status,remarks,updated_by) SELECT case_id,(SELECT case_status FROM cases WHERE case_id=?),?,? FROM hearings WHERE hearing_id=?");
            $history->execute([$data['case_id'], 'Hearing #' . $id . ' rescheduled as hearing #' . $newId . '. Reason: ' . $reason, $_SESSION['user_id'] ?? null, $newId]);
            return $newId > 0;
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

    public function isSuperseded(int $hearingId): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT EXISTS(SELECT 1 FROM hearings WHERE rescheduled_from_id = ?)'
        );
        $stmt->execute([$hearingId]);
        return (bool) $stmt->fetchColumn();
    }

    public function getPaginatedCombined(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $params = [];
        $selectedDate = trim((string) ($filters['date'] ?? ''));
        $hasCustomDate = ($selectedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate));
        if ($hasCustomDate) {
            $dateConditionHearing = 'DATE(h.hearing_date) = :filter_date_h';
            $dateConditionDeadline = 'd.due_date = :filter_date_d';
            $params[':filter_date_h'] = $selectedDate;
            $params[':filter_date_d'] = $selectedDate;
        } else {
            $dateConditionHearing = 'DATE(h.hearing_date) = CURDATE()';
            $dateConditionDeadline = 'd.due_date = CURDATE()';
        }

        $baseSql = "
        SELECT
            'hearing' AS record_type,
            h.hearing_id AS record_id,
            h.case_id,
            c.case_number,
            co.complaint_number,
            co.complaint_title,
            h.raw_type,
            h.hearing_type,
            h.hearing_date AS schedule_date,
            h.venue,
            CASE
                WHEN EXISTS (SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id) THEN 'Rescheduled'
                WHEN h.status = 'Cancelled' THEN 'Cancelled'
                WHEN h.hearing_date < NOW() OR h.status = 'Completed' THEN 'Completed'
                ELSE 'Scheduled'
            END AS status,
            h.remarks,
            h.rescheduled_from_id,
            CASE WHEN EXISTS (
                SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id
            ) THEN 1 ELSE 0 END AS is_superseded,
            (SELECT next_h.hearing_id FROM hearings next_h
             WHERE next_h.rescheduled_from_id = h.hearing_id
             ORDER BY next_h.created_at DESC, next_h.hearing_id DESC LIMIT 1) AS superseded_by_id,
            CASE WHEN EXISTS (SELECT 1 FROM hearing_nonappearances hn WHERE hn.hearing_id = h.hearing_id AND hn.resolution = 'Pending') THEN '1' ELSE '0' END AS has_pending_nonappearance,
            (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id) AS attendance_count,
            (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id AND ha.attendance_status = 'Present') AS present_count,
            (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id AND ha.attendance_status = 'Absent' AND ha.is_justified = 0) AS unjustified_absent_count,
            (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id AND (ha.attendance_status = 'Excused' OR ha.is_justified = 1)) AS excused_count,
            h.created_at
        FROM (
            SELECT
                h_sub.hearing_id,
                h_sub.case_id,
                h_sub.hearing_type AS raw_type,
                CASE
                    WHEN h_sub.hearing_type IN ('Mediation', 'Conciliation') THEN
                        CONCAT(
                            CASE ROW_NUMBER() OVER (PARTITION BY h_sub.case_id, h_sub.hearing_type ORDER BY h_sub.created_at ASC, h_sub.hearing_id ASC)
                                WHEN 1 THEN '1st '
                                WHEN 2 THEN '2nd '
                                WHEN 3 THEN '3rd '
                                ELSE CONCAT(ROW_NUMBER() OVER (PARTITION BY h_sub.case_id, h_sub.hearing_type ORDER BY h_sub.created_at ASC, h_sub.hearing_id ASC), 'th ')
                            END,
                            h_sub.hearing_type
                        )
                    ELSE h_sub.hearing_type
                END AS hearing_type,
                h_sub.hearing_date,
                h_sub.venue,
                h_sub.remarks,
                h_sub.rescheduled_from_id,
                h_sub.status,
                h_sub.created_at
            FROM hearings h_sub
        ) h
        LEFT JOIN cases c ON c.case_id = h.case_id
        LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
        WHERE {$dateConditionHearing}

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
            NULL AS rescheduled_from_id,
            0 AS is_superseded,
            NULL AS superseded_by_id,
            '0' AS has_pending_nonappearance,
            0 AS attendance_count,
            0 AS present_count,
            0 AS unjustified_absent_count,
            0 AS excused_count,
            d.created_at
        FROM case_deadlines d
        LEFT JOIN cases c ON c.case_id = d.case_id
        LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
        WHERE {$dateConditionDeadline}
        ";

        $where = [];

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

        $attendance = trim((string) ($filters['attendance'] ?? ''));
        if ($attendance === 'present') {
            $where[] = "(record_type = 'hearing' AND present_count >= 2)";
        } elseif ($attendance === 'unjustified') {
            $where[] = "(record_type = 'hearing' AND unjustified_absent_count > 0)";
        } elseif ($attendance === 'excused') {
            $where[] = "(record_type = 'hearing' AND excused_count > 0)";
        } elseif ($attendance === 'pending') {
            $where[] = "(record_type = 'hearing' AND attendance_count = 0)";
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
