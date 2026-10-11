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

    /**
     * Retrieves the latest active/completed booked hearing for a given case.
     * Excludes cancelled and superseded hearings.
     */
    public function getLatestBookedHearing(int $caseId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.status,
                    DATE(h.hearing_date) AS hearing_day
             FROM hearings h
             WHERE h.case_id = ?
               AND h.status != 'Cancelled'
               AND NOT EXISTS (
                   SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id
               )
             ORDER BY h.hearing_date DESC
             LIMIT 1"
        );
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Validates that if a hearing is already booked for a case, users cannot
     * schedule another hearing for that case on the same day or on any prior date.
     *
     * @param int $caseId
     * @param string $hearingDate Target hearing datetime
     * @param int|null $excludeHearingId Optional hearing ID to exclude (e.g., during rescheduling)
     * @return array ['valid' => bool, 'same_day' => bool, 'prior_date' => bool, 'message' => string, 'latest_hearing' => ?array]
     */
    public function validateHearingDateProgression(int $caseId, string $hearingDate, ?int $excludeHearingId = null): array
    {
        try {
            $targetDateObj = new DateTimeImmutable($hearingDate);
        } catch (Throwable) {
            return [
                'valid' => false,
                'same_day' => false,
                'prior_date' => false,
                'message' => 'Invalid hearing date format.',
                'latest_hearing' => null,
            ];
        }

        $targetDay = $targetDateObj->format('Y-m-d');

        $sql = "SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.status,
                       DATE(h.hearing_date) AS hearing_day, c.case_number
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                WHERE h.case_id = ?
                  AND h.status != 'Cancelled'
                  AND NOT EXISTS (
                      SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id
                  )";
        $params = [$caseId];

        if ($excludeHearingId !== null) {
            $sql .= " AND h.hearing_id != ?";
            $params[] = $excludeHearingId;
        }

        $sql .= " ORDER BY h.hearing_date DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $bookedHearings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($bookedHearings)) {
            return [
                'valid' => true,
                'same_day' => false,
                'prior_date' => false,
                'message' => '',
                'latest_hearing' => null,
            ];
        }

        // Check if the target date is on the same day as ANY existing booked hearing for this case
        foreach ($bookedHearings as $hearing) {
            if ($hearing['hearing_day'] === $targetDay) {
                $matchedDate = date('F j, Y', strtotime($hearing['hearing_date']));
                return [
                    'valid' => false,
                    'same_day' => true,
                    'prior_date' => false,
                    'message' => "A hearing for this case is already booked on this day ({$matchedDate}). You cannot schedule another hearing for this case on the same day or on any prior date.",
                    'latest_hearing' => $hearing,
                ];
            }
        }

        // When scheduling another hearing, check if target date is prior to the latest booked hearing
        $latest = $bookedHearings[0];
        $latestDay = $latest['hearing_day'];
        if ($targetDay < $latestDay) {
            $formattedDate = date('F j, Y', strtotime($latest['hearing_date']));
            return [
                'valid' => false,
                'same_day' => false,
                'prior_date' => true,
                'message' => "A hearing for this case is already booked on {$formattedDate}. You cannot schedule another hearing for this case on the same day or on any prior date.",
                'latest_hearing' => $latest,
            ];
        }

        return [
            'valid' => true,
            'same_day' => false,
            'prior_date' => false,
            'message' => '',
            'latest_hearing' => $latest,
        ];
    }

    /**
     * Validates that the hearing date/time adheres to official operating rules:
     * - Weekdays only (Monday to Friday)
     * - Official Philippine legal holidays blocked
     * - Monday 8:00–9:00 AM administrative block
     * - Approved morning (9:00–11:30 AM) and afternoon (1:30–4:00 PM) blocks
     * - 12:00–1:00 PM lunch hard block
     * - Session duration limits and justification
     */
    public function validateHearingOperatingHours(
        string $hearingDate,
        mixed $endOrDuration = null,
        string $hearingType = 'Mediation',
        ?string $durationExceedReason = null
    ): array {
        require_once __DIR__ . '/../services/OfficeLogisticsService.php';
        $logistics = new OfficeLogisticsService($this->conn);
        return $logistics->validateHearingSchedule($hearingDate, $endOrDuration, $hearingType, $durationExceedReason);
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

            // Progression date validation: cannot schedule on the same day or any prior date relative to already booked hearings.
            $dateProgression = $this->validateHearingDateProgression((int) $data['case_id'], $data['hearing_date']);
            if (!$dateProgression['valid']) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => $dateProgression['message'],
                ];
            }

            // Logistics & Schedule Validation (blocks, lunch, Monday 8-9am, holidays, duration)
            $schedValidation = $this->validateHearingOperatingHours(
                $data['hearing_date'],
                $data['end_time'] ?? ($data['duration_minutes'] ?? null),
                $data['hearing_type'],
                $data['duration_exceed_reason'] ?? null
            );
            if (!$schedValidation['valid']) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => $schedValidation['message'],
                ];
            }

            $startTime = $schedValidation['start_time'];
            $endTime = $schedValidation['end_time'];
            $durationMin = $schedValidation['duration_minutes'];

            // Daily Capacity validation
            require_once __DIR__ . '/../services/OfficeLogisticsService.php';
            $logistics = new OfficeLogisticsService($this->conn);
            $presidingOfficerId = !empty($data['presiding_officer_id']) ? (int) $data['presiding_officer_id'] : null;
            $capacityCheck = $logistics->validateDailyCapacity($startTime, $presidingOfficerId);
            if (!$capacityCheck['valid']) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => $capacityCheck['message'],
                ];
            }

            // Presiding officer conflict check if assigned
            if ($presidingOfficerId) {
                $availCheck = $logistics->validateMemberAvailability($presidingOfficerId, $startTime, $endTime);
                if (!$availCheck['available']) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'message' => $availCheck['message'],
                    ];
                }
            }

            // Default venue validation & assignment
            $venue = trim((string) ($data['venue'] ?? ''));
            if ($venue === '') {
                $venue = OfficeLogisticsService::getDefaultVenue($data['hearing_type']);
            }

            $stmt = $this->conn->prepare(
                'INSERT INTO hearings (case_id, hearing_type, hearing_date, end_time, duration_minutes, duration_exceed_reason, venue, remarks, presiding_officer_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['case_id'],
                $data['hearing_type'],
                $startTime,
                $endTime,
                $durationMin,
                !empty($data['duration_exceed_reason']) ? $data['duration_exceed_reason'] : null,
                $venue,
                $data['remarks'] ?? null,
                $presidingOfficerId,
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
            
            $schedValidation = $this->validateHearingOperatingHours(
                $data['hearing_date'],
                $data['end_time'] ?? ($data['duration_minutes'] ?? null),
                $data['hearing_type'],
                $data['duration_exceed_reason'] ?? null
            );
            if (!$schedValidation['valid']) {
                throw new InvalidArgumentException($schedValidation['message']);
            }

            $startTime = $schedValidation['start_time'];
            $endTime = $schedValidation['end_time'];
            $durationMin = $schedValidation['duration_minutes'];

            // Reschedule limit check per party
            $partyType = trim((string) ($data['rescheduled_by_party'] ?? ''));
            if (in_array($partyType, ['Complainant', 'Respondent'], true) && empty($data['force_reschedule'])) {
                require_once __DIR__ . '/../services/OfficeLogisticsService.php';
                $logistics = new OfficeLogisticsService($this->conn);
                $partyLimit = $logistics->checkPartyRescheduleLimit((int) $data['case_id'], $partyType);
                if ($partyLimit['exceeded']) {
                    throw new InvalidArgumentException($partyLimit['message']);
                }
            }

            $venue = trim((string) ($data['venue'] ?? ''));
            if ($venue === '') {
                require_once __DIR__ . '/../services/OfficeLogisticsService.php';
                $venue = OfficeLogisticsService::getDefaultVenue($data['hearing_type']);
            }

            $stmt = $this->conn->prepare(
                'INSERT INTO hearings (case_id, hearing_type, hearing_date, end_time, duration_minutes, duration_exceed_reason, venue, remarks, rescheduled_from_id, reschedule_reason, rescheduled_by_party, reschedule_justification_category, reschedule_document_path, reschedule_approved_by, reschedule_approved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $data['case_id'],
                $data['hearing_type'],
                $startTime,
                $endTime,
                $durationMin,
                !empty($data['duration_exceed_reason']) ? $data['duration_exceed_reason'] : null,
                $venue,
                $data['remarks'] ?? null,
                $id,
                $reason,
                $partyType ?: null,
                !empty($data['reschedule_justification_category']) ? $data['reschedule_justification_category'] : null,
                !empty($data['reschedule_document_path']) ? $data['reschedule_document_path'] : null,
                $_SESSION['user_id'] ?? null,
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
            h.end_time,
            h.duration_minutes,
            h.actual_end_time,
            h.duration_exceed_reason,
            h.venue,
            h.presiding_officer_id,
            TRIM(CONCAT(po.first_name, ' ', COALESCE(CONCAT(po.middle_name, ' '), ''), po.last_name)) AS presiding_officer_name,
            h.substitute_presider_id,
            TRIM(CONCAT(sp.first_name, ' ', COALESCE(CONCAT(sp.middle_name, ' '), ''), sp.last_name)) AS substitute_presider_name,
            h.substitute_reason,
            h.parties_consent_to_substitute,
            h.cancelled_by,
            h.cancellation_reason,
            h.rescheduled_by_party,
            h.reschedule_justification_category,
            h.reschedule_document_path,
            CASE
                WHEN h.status = 'Office Cancelled' THEN 'Office Cancelled'
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
                h_sub.end_time,
                h_sub.duration_minutes,
                h_sub.actual_end_time,
                h_sub.duration_exceed_reason,
                h_sub.venue,
                h_sub.remarks,
                h_sub.presiding_officer_id,
                h_sub.substitute_presider_id,
                h_sub.substitute_reason,
                h_sub.parties_consent_to_substitute,
                h_sub.rescheduled_from_id,
                h_sub.rescheduled_by_party,
                h_sub.reschedule_justification_category,
                h_sub.reschedule_document_path,
                h_sub.status,
                h_sub.cancelled_by,
                h_sub.cancellation_reason,
                h_sub.created_at
            FROM hearings h_sub
        ) h
        LEFT JOIN cases c ON c.case_id = h.case_id
        LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
        LEFT JOIN users po ON po.user_id = h.presiding_officer_id
        LEFT JOIN users sp ON sp.user_id = h.substitute_presider_id
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
            NULL AS end_time,
            NULL AS duration_minutes,
            NULL AS actual_end_time,
            NULL AS duration_exceed_reason,
            NULL AS venue,
            NULL AS presiding_officer_id,
            NULL AS presiding_officer_name,
            NULL AS substitute_presider_id,
            NULL AS substitute_presider_name,
            NULL AS substitute_reason,
            0 AS parties_consent_to_substitute,
            NULL AS cancelled_by,
            NULL AS cancellation_reason,
            NULL AS rescheduled_by_party,
            NULL AS reschedule_justification_category,
            NULL AS reschedule_document_path,
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
        if (in_array($status, ['Scheduled', 'Pending', 'Completed', 'Overdue', 'Office Cancelled', 'Rescheduled', 'Cancelled'], true)) {
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

    public function officeCancel(int $hearingId, int $cancelledBy, string $reason, ?string $rescheduleDate = null): array
    {
        try {
            $this->conn->beginTransaction();
            $hearing = $this->getById($hearingId);
            if (!$hearing) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Hearing record not found.'];
            }

            $stmt = $this->conn->prepare(
                "UPDATE hearings 
                 SET status = 'Office Cancelled', 
                     cancelled_by = ?, 
                     cancellation_reason = ?, 
                     updated_at = NOW() 
                 WHERE hearing_id = ?"
            );
            $stmt->execute([$cancelledBy, $reason, $hearingId]);

            $historyStmt = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks, updated_by)
                 VALUES (?, ?, ?, ?)"
            );
            $historyStmt->execute([
                $hearing['case_id'],
                $hearing['case_status'],
                "Hearing #{$hearingId} cancelled by office: {$reason}. Not recorded as party absence.",
                $cancelledBy
            ]);

            $newHearingId = null;
            if ($rescheduleDate) {
                $duration = !empty($hearing['duration_minutes']) ? (int) $hearing['duration_minutes'] : 45;
                $schedValidation = $this->validateHearingOperatingHours(
                    $rescheduleDate,
                    $duration,
                    $hearing['hearing_type']
                );
                if ($schedValidation['valid']) {
                    $insertNew = $this->conn->prepare(
                        "INSERT INTO hearings (case_id, hearing_type, hearing_date, end_time, duration_minutes, venue, remarks, rescheduled_from_id, reschedule_reason, reschedule_approved_by, reschedule_approved_at, presiding_officer_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
                    );
                    $insertNew->execute([
                        $hearing['case_id'],
                        $hearing['hearing_type'],
                        $schedValidation['start_time'],
                        $schedValidation['end_time'],
                        $schedValidation['duration_minutes'],
                        $hearing['venue'],
                        "Rescheduled due to office cancellation: {$reason}",
                        $hearingId,
                        "Office cancellation: {$reason}",
                        $cancelledBy,
                        $hearing['presiding_officer_id']
                    ]);
                    $newHearingId = (int) $this->conn->lastInsertId();
                }
            }

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Hearing cancelled by office. Parties are not penalized.',
                'new_hearing_id' => $newHearingId
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Failed to cancel hearing: ' . $e->getMessage()];
        }
    }

    public function assignSubstitutePresider(int $hearingId, int $substitutePresiderId, string $reason, bool $partiesConsent): array
    {
        if (!$partiesConsent) {
            return [
                'success' => false,
                'message' => 'Cannot proceed with substitute presider without consent of both parties. If parties do not consent, the session must be rescheduled or cancelled by the office.'
            ];
        }

        try {
            $hearing = $this->getById($hearingId);
            if (!$hearing) {
                return ['success' => false, 'message' => 'Hearing record not found.'];
            }

            require_once __DIR__ . '/../services/OfficeLogisticsService.php';
            $logistics = new OfficeLogisticsService($this->conn);
            $endTime = $hearing['end_time'] ?? date('Y-m-d H:i:s', strtotime($hearing['hearing_date'] . ' + 45 minutes'));
            $avail = $logistics->validateMemberAvailability($substitutePresiderId, $hearing['hearing_date'], $endTime, $hearingId);
            if (!$avail['available']) {
                return ['success' => false, 'message' => $avail['message']];
            }

            $stmt = $this->conn->prepare(
                "UPDATE hearings 
                 SET substitute_presider_id = ?, 
                     substitute_reason = ?, 
                     parties_consent_to_substitute = 1,
                     updated_at = NOW()
                 WHERE hearing_id = ?"
            );
            $stmt->execute([$substitutePresiderId, $reason, $hearingId]);

            $hist = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks, updated_by)
                 VALUES (?, ?, ?, ?)"
            );
            $hist->execute([
                $hearing['case_id'],
                $hearing['case_status'],
                "Substitute presider designated for Hearing #{$hearingId}. Reason: {$reason}. Both parties consented.",
                $_SESSION['user_id'] ?? null
            ]);

            return ['success' => true, 'message' => 'Substitute presider assigned successfully with mutual party consent.'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Failed to assign substitute presider: ' . $e->getMessage()];
        }
    }

    public function getLatestHearingIdForCase(int $caseId): ?int
    {
        $stmt = $this->conn->prepare(
            "SELECT hearing_id FROM hearings 
             WHERE case_id = ? 
             ORDER BY (status = 'Scheduled') DESC, hearing_date DESC, hearing_id DESC 
             LIMIT 1"
        );
        $stmt->execute([$caseId]);
        $val = $stmt->fetchColumn();
        return $val ? (int) $val : null;
    }

    public function failedMediationToPangkat(int $hearingId, int $userId, array $pangkatData = []): array
    {
        try {
            $this->conn->beginTransaction();
            $hearing = $this->getById($hearingId);
            if (!$hearing) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Hearing record not found.'];
            }

            $caseId = (int) $hearing['case_id'];

            $updHearing = $this->conn->prepare(
                "UPDATE hearings SET status = 'Completed', remarks = CONCAT(COALESCE(remarks, ''), ' [Mediation declared failed; elevated to Pangkat Tagapagkasundo]') WHERE hearing_id = ?"
            );
            $updHearing->execute([$hearingId]);

            $stmtCase = $this->conn->prepare("UPDATE cases SET case_status = 'Conciliation', updated_at = NOW() WHERE case_id = ?");
            $stmtCase->execute([$caseId]);

            $stmtComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Conciliation', co.updated_at = NOW() WHERE c.case_id = ?");
            $stmtComp->execute([$caseId]);

            require_once __DIR__ . '/../services/MediationDeadlineService.php';
            $deadlineService = new MediationDeadlineService($this->conn);
            $pangkatDueDate = $deadlineService->calculateTargetDate(date('Y-m-d'), 15);
            $this->upsertDeadline($caseId, 'Conciliation Period', $pangkatDueDate);

            require_once __DIR__ . '/../services/PDFService.php';
            $pdfService = new PDFService($this->conn);
            $kp10Result = $pdfService->generateKp10($caseId, $userId);

            $selectionMethod = $pangkatData['selection_method'] ?? 'Party Agreement';
            $selectionNotes = $pangkatData['selection_notes'] ?? null;
            $quorumSize = !empty($pangkatData['quorum_size']) ? (int) $pangkatData['quorum_size'] : 3;
            $chairmanId = !empty($pangkatData['chairman_id']) ? (int) $pangkatData['chairman_id'] : null;
            $secretaryId = !empty($pangkatData['secretary_id']) ? (int) $pangkatData['secretary_id'] : null;
            $memberId = !empty($pangkatData['member_id']) ? (int) $pangkatData['member_id'] : null;

            if ($chairmanId && $secretaryId) {
                $insPangkat = $this->conn->prepare(
                    "INSERT INTO pangkat_groups (case_id, formation_date, selection_method, selection_notes, quorum_size)
                     VALUES (?, CURDATE(), ?, ?, ?)
                     ON DUPLICATE KEY UPDATE 
                        selection_method = VALUES(selection_method),
                        selection_notes = VALUES(selection_notes),
                        quorum_size = VALUES(quorum_size)"
                );
                $insPangkat->execute([
                    $caseId,
                    $selectionMethod,
                    $selectionNotes,
                    $quorumSize
                ]);

                $pangkatIdStmt = $this->conn->prepare("SELECT pangkat_id FROM pangkat_groups WHERE case_id = ?");
                $pangkatIdStmt->execute([$caseId]);
                $pId = (int) $pangkatIdStmt->fetchColumn();

                if ($pId > 0) {
                    $this->conn->prepare("DELETE FROM pangkat_members WHERE pangkat_id = ?")->execute([$pId]);
                    $insMem = $this->conn->prepare("INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, ?, ?)");
                    $insMem->execute([$pId, $chairmanId, 'Chairman']);
                    $insMem->execute([$pId, $secretaryId, 'Secretary']);
                    if ($memberId) {
                        $insMem->execute([$pId, $memberId, 'Member']);
                    }
                }
            }

            $hist = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks, updated_by)
                 VALUES (?, 'Conciliation', 'Mediation failed. Case elevated to Pangkat Tagapagkasundo. KP Form 10 generated.', ?)"
            );
            $hist->execute([$caseId, $userId]);

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Mediation declared failed and case elevated to Conciliation/Pangkat. KP Form 10 generated.',
                'kp10' => $kp10Result,
                'case_id' => $caseId
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Elevation failed: ' . $e->getMessage()];
        }
    }

    public function getCaseTransferPackage(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT c.*, co.complaint_number, co.complaint_title, co.narrative,
                    co.filing_date, co.incident_date, co.incident_location,
                    (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', res1.first_name, res1.middle_name, res1.last_name)) SEPARATOR ', ')
                     FROM complaint_parties cp1
                     INNER JOIN residents res1 ON res1.resident_id = cp1.resident_id
                     WHERE cp1.complaint_id = co.complaint_id AND cp1.party_type = 'Complainant') AS complainant_name,
                    (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', res2.first_name, res2.middle_name, res2.last_name)) SEPARATOR ', ')
                     FROM complaint_parties cp2
                     INNER JOIN residents res2 ON res2.resident_id = cp2.resident_id
                     WHERE cp2.complaint_id = co.complaint_id AND cp2.party_type = 'Respondent') AS respondent_name
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE c.case_id = ?"
        );
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);

        $hearingsStmt = $this->conn->prepare(
            "SELECT h.*, hm.opening_conducted, hm.parties_identified, hm.complaint_read_confirmed,
                    hm.complainant_statement_summary, hm.respondent_statement_summary, hm.dispute_summary,
                    hm.settlement_options_discussed, hm.session_outcome, hm.caucus_conducted,
                    hm.previous_proposal, hm.new_proposal, hm.counter_offer, hm.session_notes,
                    TRIM(CONCAT_WS(' ', po.first_name, po.middle_name, po.last_name)) as presider_name,
                    TRIM(CONCAT_WS(' ', sp.first_name, sp.middle_name, sp.last_name)) as substitute_name
             FROM hearings h
             LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
             LEFT JOIN users po ON po.user_id = h.presiding_officer_id
             LEFT JOIN users sp ON sp.user_id = h.substitute_presider_id
             WHERE h.case_id = ?
             ORDER BY h.hearing_date ASC"
        );
        $hearingsStmt->execute([$caseId]);
        $hearings = $hearingsStmt->fetchAll(PDO::FETCH_ASSOC);

        $summonsStmt = $this->conn->prepare(
            "SELECT hps.*, h.hearing_date, h.hearing_type
             FROM hearing_party_services hps
             INNER JOIN hearings h ON h.hearing_id = hps.hearing_id
             WHERE h.case_id = ?
             ORDER BY hps.served_at ASC"
        );
        $summonsStmt->execute([$caseId]);
        $summonsRecords = $summonsStmt->fetchAll(PDO::FETCH_ASSOC);

        $docStmt = $this->conn->prepare(
            "SELECT gd.*, dt.template_name 
             FROM generated_documents gd
             INNER JOIN document_templates dt ON dt.template_id = gd.template_id
             WHERE gd.case_id = ?
             ORDER BY gd.created_at DESC"
        );
        $docStmt->execute([$caseId]);
        $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'case' => $case,
            'hearings' => $hearings,
            'summons_records' => $summonsRecords,
            'documents' => $documents,
        ];
    }
}
