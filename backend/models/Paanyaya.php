<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PDFService.php';
require_once __DIR__ . '/../services/AuditService.php';

class Paanyaya
{
    private PDO $conn;
    private AuditService $audit;
    private PDFService $pdf;

    public const MAX_ATTEMPTS = 2;
    public const STAGE_1 = 'Paanyaya 1';
    public const STAGE_2 = 'Paanyaya 2';
    public const MAX_STAGE_ATTEMPTS = 6;

    public function getMaxStageAttempts(): int
    {
        require_once __DIR__ . '/Setting.php';
        return (int) Setting::getSetting('summons_max_attempts', (int) Setting::getSetting('paanyaya_max_attempts', 6, $this->conn), $this->conn);
    }

    public const VALID_DELIVERY_OUTCOMES = [
        'Successfully delivered',
        'Recipient unavailable',
        'Recipient refused to receive',
        'Respondent not found at the address',
        'Incorrect or incomplete address',
        'Other reason'
    ];

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?: (new Database())->connect();
        $this->audit = new AuditService();
        $this->pdf = new PDFService($this->conn);
    }

    /**
     * Schedules a Paanyaya dialogue session (Attempt #1 or Attempt #2).
     * Enforces the "Dalawang Beses Lang" (Max 2 Attempts) rule.
     */
    public function schedule(array $data, ?int $userId = null): array
    {
        $complaintId = (int) ($data['complaint_id'] ?? 0);
        if ($complaintId <= 0) {
            return ['success' => false, 'message' => 'Invalid complaint ID.'];
        }

        // Fetch complaint
        $cStmt = $this->conn->prepare('SELECT complaint_id, complaint_number, complaint_title, narrative, status, paanyaya_attempt_count, max_paanyaya_reached, resolved_without_docketing FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        if (!empty($complaint['resolved_without_docketing']) || $complaint['status'] === 'Settled' || $complaint['status'] === 'Resolved at Paanyaya / Closed') {
            return ['success' => false, 'message' => 'This dispute has already been resolved at the Paanyaya stage and is closed.'];
        }

        // Count previous sessions
        $countStmt = $this->conn->prepare('SELECT COUNT(*) FROM paanyaya_sessions WHERE complaint_id = ?');
        $countStmt->execute([$complaintId]);
        $existingSessionCount = (int) $countStmt->fetchColumn();

        // Determine attempt number
        $attemptNumber = isset($data['attempt_number']) ? (int) $data['attempt_number'] : ($existingSessionCount + 1);
        if ($attemptNumber < 1) {
            $attemptNumber = 1;
        }

        // Enforce Dalawang Beses Lang (Max 2 attempts)
        if ($attemptNumber > self::MAX_ATTEMPTS || $existingSessionCount >= self::MAX_ATTEMPTS) {
            return [
                'success' => false,
                'max_reached' => true,
                'message' => 'Maximum 2 Paanyaya attempts reached (Dalawang Beses Lang). The informal invitation stage is closed and the dispute must be escalated to formal case docketing.'
            ];
        }

        $invitationDate = trim((string) ($data['invitation_date'] ?? ''));
        $invitationTime = trim((string) ($data['invitation_time'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? 'Tanggapan ng Barangay Tumana'));
        $salaysay = trim((string) ($data['salaysay'] ?? ''));
        $hasESignature = isset($data['has_e_signature']) ? (int) ((bool) $data['has_e_signature']) : 1;

        if ($invitationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invitationDate)) {
            return ['success' => false, 'message' => 'Please provide a valid invitation date (YYYY-MM-DD).'];
        }

        if ($invitationTime === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $invitationTime)) {
            return ['success' => false, 'message' => 'Please provide a valid invitation time (HH:MM).'];
        }

        if (empty($salaysay)) {
            $salaysay = $complaint['narrative'] ?: 'naisin nilang magkausap at magkaliwanagan.';
        }

        $dowMap = [
            0 => 'Linggo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miyerkules',
            4 => 'Huwebes',
            5 => 'Biyernes',
            6 => 'Sabado'
        ];
        $dow = (int) date('w', strtotime($invitationDate));
        $hearingDay = $dowMap[$dow] ?? date('l', strtotime($invitationDate));

        try {
            $this->conn->beginTransaction();

            // Insert into paanyaya_sessions
            $insSession = $this->conn->prepare("
                INSERT INTO paanyaya_sessions (
                    complaint_id, attempt_number, scheduled_date, scheduled_time,
                    venue, has_e_signature, salaysay, scheduled_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insSession->execute([
                $complaintId,
                $attemptNumber,
                $invitationDate,
                $invitationTime,
                $venue,
                $hasESignature,
                $salaysay,
                $userId
            ]);
            $sessionId = (int) $this->conn->lastInsertId();

            // Update complaints table state
            $statusToSet = ($attemptNumber === 1) ? 'Pre-Docketed (Paanyaya)' : 'Paanyaya In Progress';
            $updComplaint = $this->conn->prepare("
                UPDATE complaints
                SET status = ?,
                    paanyaya_attempt_count = ?,
                    max_paanyaya_reached = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE complaint_id = ?
            ");
            $updComplaint->execute([
                $statusToSet,
                $attemptNumber,
                ($attemptNumber >= self::MAX_ATTEMPTS ? 1 : 0),
                $complaintId
            ]);

            // Sync with legacy complaint_paanyaya table for backward-compatibility
            $legacyIns = $this->conn->prepare("
                INSERT INTO complaint_paanyaya (
                    complaint_id, invitation_date, invitation_time, venue,
                    hearing_day, salaysay, status, has_e_signature, scheduled_by
                ) VALUES (?, ?, ?, ?, ?, ?, 'Scheduled', ?, ?)
            ");
            $legacyIns->execute([
                $complaintId,
                $invitationDate,
                $invitationTime,
                $venue,
                $hearingDay,
                $salaysay,
                $hasESignature,
                $userId
            ]);
            $legacyId = (int) $this->conn->lastInsertId();

            // Generate official Paanyaya PDF letter
            $storageDir = __DIR__ . '/../../storage/paanyaya';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0777, true);
            }

            $pdfFileName = sprintf('paanyaya_%d_attempt%d_%s.pdf', $complaintId, $attemptNumber, date('Ymd_His'));
            $pdfFullPath = $storageDir . '/' . $pdfFileName;
            $pdfRelativePath = 'storage/paanyaya/' . $pdfFileName;

            $this->pdf->generatePaanyaya($complaintId, [
                'save_path' => $pdfFullPath,
                'attempt_number' => $attemptNumber,
                'invitation_date' => $invitationDate,
                'invitation_time' => $invitationTime,
                'venue' => $venue,
                'salaysay' => $salaysay,
                'has_e_signature' => $hasESignature,
            ]);

            // Update PDF path in both tables
            $updPdf = $this->conn->prepare('UPDATE paanyaya_sessions SET pdf_path = ? WHERE session_id = ?');
            $updPdf->execute([$pdfRelativePath, $sessionId]);

            if ($legacyId > 0) {
                $updLegPdf = $this->conn->prepare('UPDATE complaint_paanyaya SET pdf_path = ? WHERE paanyaya_id = ?');
                $updLegPdf->execute([$pdfRelativePath, $legacyId]);
            }

            if ($userId) {
                $ordinal = ($attemptNumber === 1) ? '1st' : '2nd';
                $this->audit->log($userId, "Issued {$ordinal} Paanyaya (Informal Invitation)", 'Complaints', $complaintId);
            }

            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notifService = new NotificationService();
                $cNum = $complaint['complaint_number'] ?? "CMP-{$complaintId}";
                $notifService->notifyRoles(
                    ['Administrator', 'Lupon Clerk', 'Summons Server'],
                    "Paanyaya {$attemptNumber} Scheduled",
                    "Paanyaya {$attemptNumber} dialogue for {$cNum} is scheduled on " . date('M j, Y', strtotime($invitationDate)) . " at " . date('g:i A', strtotime($invitationTime)) . " ({$venue}).",
                    $userId
                );
            } catch (Throwable $ne) {
                error_log('Failed to dispatch Paanyaya notification: ' . $ne->getMessage());

            }

            $this->conn->commit();

            return [
                'success' => true,
                'session_id' => $sessionId,
                'attempt_number' => $attemptNumber,
                'pdf_path' => $pdfRelativePath,
                'message' => "Paanyaya session (Attempt #{$attemptNumber} of 2) successfully scheduled."
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Paanyaya scheduling error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error scheduling Paanyaya: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Gets all Paanyaya sessions for a complaint in chronological order.
     */
    public function getSessions(int $complaintId): array
    {
        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM paanyaya_sessions ps
            LEFT JOIN users u ON u.user_id = ps.scheduled_by
            WHERE ps.complaint_id = ?
            ORDER BY ps.attempt_number ASC, ps.session_id ASC
        ");
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gets a single session by session_id.
     */
    public function getSessionById(int $sessionId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM paanyaya_sessions ps
            LEFT JOIN users u ON u.user_id = ps.scheduled_by
            WHERE ps.session_id = ?
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Gets latest session for a complaint.
     */
    public function getByComplaintId(int $complaintId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM paanyaya_sessions ps
            LEFT JOIN users u ON u.user_id = ps.scheduled_by
            WHERE ps.complaint_id = ?
            ORDER BY ps.attempt_number DESC, ps.session_id DESC
            LIMIT 1
        ");
        $stmt->execute([$complaintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $row['invitation_date'] = $row['scheduled_date'] ?? null;
            $row['invitation_time'] = $row['scheduled_time'] ?? null;
            $row['paanyaya_id'] = $row['session_id'];
            if (!empty($row['scheduled_date'])) {
                $ts = strtotime($row['scheduled_date']);
                $dow = (int)date('w', $ts);
                $days = ['Linggo', 'Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado'];
                $row['hearing_day'] = $days[$dow] ?? date('l', $ts);
            }
            if (empty($row['status'])) {
                $row['status'] = (!empty($row['session_outcome'])) ? $row['session_outcome'] : 'Scheduled';
            }
            return $row;
        }

        // Fallback to complaint_paanyaya if paanyaya_sessions is empty
        $legStmt = $this->conn->prepare("
            SELECT cp.*,
                   cp.paanyaya_id AS session_id,
                   1 AS attempt_number,
                   cp.invitation_date AS scheduled_date,
                   cp.invitation_time AS scheduled_time,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS scheduled_by_name
            FROM complaint_paanyaya cp
            LEFT JOIN users u ON u.user_id = cp.scheduled_by
            WHERE cp.complaint_id = ?
            ORDER BY cp.paanyaya_id DESC
            LIMIT 1
        ");
        $legStmt->execute([$complaintId]);
        $legRow = $legStmt->fetch(PDO::FETCH_ASSOC);
        if ($legRow) {
            $legRow['scheduled_date'] = $legRow['invitation_date'] ?? null;
            $legRow['scheduled_time'] = $legRow['invitation_time'] ?? null;
            if (!empty($legRow['invitation_date'])) {
                $ts = strtotime($legRow['invitation_date']);
                $dow = (int)date('w', $ts);
                $days = ['Linggo', 'Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado'];
                $legRow['hearing_day'] = $days[$dow] ?? date('l', $ts);
            }
            return $legRow;
        }
        return null;
    }

    /**
     * Records the outcome of a Paanyaya dialogue session.
     * Implements the "Max 2 Attempts" (`Dalawang Beses Lang`) State Machine:
     *
     * Session 1 Outcomes:
     * - 'Settled': Status -> 'Resolved at Paanyaya / Closed'. LTIA Pre-Docket Resolution. No 2nd Paanyaya.
     * - 'Absent_Retry': Status -> '1st Paanyaya Failed (No-Show)'. Unlocks 2nd Paanyaya.
     * - 'Continued_Dialogue': Status -> 'Paanyaya In Progress'. Unlocks 2nd Paanyaya.
     * - 'Impasse_Escalate': Status -> 'Escalate to Formal Docketing'. Triggers immediate formal docketing.
     *
     * Session 2 Outcomes:
     * - 'Settled': Status -> 'Resolved at Paanyaya / Closed'. LTIA win.
     * - Any failure / unsettled / absent -> Max 2 attempts reached. Triggers auto escalation to Formal Docketing.
     */
    public function recordOutcome(int $sessionId, array|string $data, mixed $userIdOrNotes = null, ?int $maybeUserId = null): array
    {
        if (is_string($data)) {
            $outcomeStr = $data;
            $data = ['outcome' => $outcomeStr];
            if (is_string($userIdOrNotes)) {
                $data['notes'] = $userIdOrNotes;
                $userId = $maybeUserId;
            } else {
                $userId = (int) $userIdOrNotes;
            }
        } else {
            $userId = is_int($userIdOrNotes) ? $userIdOrNotes : $maybeUserId;
        }

        $session = $this->getSessionById($sessionId);
        if (!$session) {
            return ['success' => false, 'message' => 'Paanyaya session record not found.'];
        }

        $complaintId = (int) $session['complaint_id'];
        $attemptNumber = (int) $session['attempt_number'];

        $outcome = trim((string) ($data['outcome'] ?? ''));
        $notes = trim((string) ($data['outcome_notes'] ?? ($data['notes'] ?? '')));
        $compAttended = in_array(trim((string)($data['complainant_attended'] ?? '')), ['Present', 'Absent'], true)
            ? trim((string)$data['complainant_attended']) : 'Present';
        $respAttended = in_array(trim((string)($data['respondent_attended'] ?? '')), ['Present', 'Absent'], true)
            ? trim((string)$data['respondent_attended']) : 'Present';

        $allowedOutcomes = ['Settled', 'Absent_Retry', 'Continued_Dialogue', 'Impasse_Escalate', 'Max_Failed_Escalate'];
        if (!in_array($outcome, $allowedOutcomes, true)) {
            // Map common aliases
            if (stripos($outcome, 'settle') !== false || stripos($outcome, 'resolve') !== false) {
                $outcome = 'Settled';
            } elseif (stripos($outcome, 'absent') !== false || stripos($outcome, 'no-show') !== false) {
                $outcome = 'Absent_Retry';
            } elseif (stripos($outcome, 'continue') !== false || stripos($outcome, 'progress') !== false) {
                $outcome = 'Continued_Dialogue';
            } else {
                $outcome = ($attemptNumber >= self::MAX_ATTEMPTS) ? 'Max_Failed_Escalate' : 'Impasse_Escalate';
            }
        }

        $stageType = ($attemptNumber >= 2) ? self::STAGE_2 : self::STAGE_1;
        try {
            $this->conn->beginTransaction();

            // Outcome 1 / Settle (Applicable to both Attempt 1 and Attempt 2)
            if ($outcome === 'Settled') {
                $updSession = $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET session_outcome = 'Settled',
                        outcome_notes = ?,
                        complainant_attended = ?,
                        respondent_attended = ?,
                        resolved_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                $updComplaint = $this->conn->prepare("
                    UPDATE complaints
                    SET status = 'Settled',
                        resolved_without_docketing = 1,
                        ltia_category = 'Pre-Docket Resolution',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComplaint->execute([$complaintId]);

                $updStages = $this->conn->prepare("
                    UPDATE paanyaya_stages
                    SET status = 'Completed',
                        review_status = 'Case Closed',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ? AND stage_type = ?
                ");
                $updStages->execute([$complaintId, $stageType]);

                if ($stageType === self::STAGE_1) {
                    $this->conn->prepare("
                        UPDATE paanyaya_stages
                        SET status = 'Not Started',
                            review_status = 'None',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE complaint_id = ? AND stage_type = ? AND issued_at IS NULL
                    ")->execute([$complaintId, self::STAGE_2]);
                }

                if ($userId) {
                    $this->audit->log($userId, 'Informally Resolved at Paanyaya / Closed (Pre-Docket Resolution)', 'Complaints', $complaintId);
                }

                $this->conn->commit();

                return [
                    'success' => true,
                    'outcome' => 'Settled',
                    'status' => 'Settled',
                    'resolved_without_docketing' => 1,
                    'ltia_category' => 'Pre-Docket Resolution',
                    'message' => 'Dispute successfully settled during Paanyaya informal dialogue! Resolved without formal court docketing (LTIA performance win).'
                ];
            }

            // ATTEMPT 1 Specific Non-Settled Outcomes:
            if ($attemptNumber === 1) {
                if ($outcome === 'Absent_Retry') {
                    $updSession = $this->conn->prepare("
                        UPDATE paanyaya_sessions
                        SET session_outcome = 'Absent_Retry',
                            outcome_notes = ?,
                            complainant_attended = ?,
                            respondent_attended = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE session_id = ?
                    ");
                    $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                    $updComplaint = $this->conn->prepare("
                        UPDATE complaints
                        SET status = '1st Paanyaya Failed (No-Show)',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE complaint_id = ?
                    ");
                    $updComplaint->execute([$complaintId]);

                    if ($userId) {
                        $this->audit->log($userId, '1st Paanyaya Failed (No-Show) - 2nd Paanyaya Unlocked', 'Complaints', $complaintId);
                    }

                    $this->conn->commit();

                    return [
                        'success' => true,
                        'outcome' => 'Absent_Retry',
                        'status' => '1st Paanyaya Failed (No-Show)',
                        'can_issue_second' => true,
                        'message' => '1st Paanyaya session recorded as failed due to party absence. You may now schedule and issue the 2nd Paanyaya (final informal chance).'
                    ];
                }

                if ($outcome === 'Continued_Dialogue') {
                    $updSession = $this->conn->prepare("
                        UPDATE paanyaya_sessions
                        SET session_outcome = 'Continued_Dialogue',
                            outcome_notes = ?,
                            complainant_attended = ?,
                            respondent_attended = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE session_id = ?
                    ");
                    $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                    $updComplaint = $this->conn->prepare("
                        UPDATE complaints
                        SET status = 'Paanyaya In Progress',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE complaint_id = ?
                    ");
                    $updComplaint->execute([$complaintId]);

                    if ($userId) {
                        $this->audit->log($userId, 'Paanyaya Dialogue In Progress - 2nd Session Requested', 'Complaints', $complaintId);
                    }

                    $this->conn->commit();

                    return [
                        'success' => true,
                        'outcome' => 'Continued_Dialogue',
                        'status' => 'Paanyaya In Progress',
                        'can_issue_second' => true,
                        'message' => 'Parties requested a follow-up meeting. You may now schedule the 2nd Paanyaya session.'
                    ];
                }

                if ($outcome === 'Impasse_Escalate') {
                    // Outright refusal / total impasse -> Do NOT waste time on 2nd Paanyaya.
                    $updSession = $this->conn->prepare("
                        UPDATE paanyaya_sessions
                        SET session_outcome = 'Impasse_Escalate',
                            outcome_notes = ?,
                            complainant_attended = ?,
                            respondent_attended = ?,
                            escalated_at = CURRENT_TIMESTAMP,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE session_id = ?
                    ");
                    $updSession->execute([$notes, $compAttended, $respAttended, $sessionId]);

                    $this->conn->commit();

                    // Trigger immediate formal docketing & 1st Mediation
                    return $this->escalateToFormalDocketing($complaintId, $userId, $notes, $data);
                }
            }

            // ATTEMPT 2: The Final Informal Chance
            if ($attemptNumber >= self::MAX_ATTEMPTS) {
                // Any unsettled outcome at attempt 2 triggers mandatory escalation
                $finalOutcome = 'Max_Failed_Escalate';
                $updSession = $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET session_outcome = ?,
                        outcome_notes = ?,
                        complainant_attended = ?,
                        respondent_attended = ?,
                        escalated_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $updSession->execute([$finalOutcome, $notes, $compAttended, $respAttended, $sessionId]);

                $updComp = $this->conn->prepare("
                    UPDATE complaints
                    SET max_paanyaya_reached = 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComp->execute([$complaintId]);

                $this->conn->commit();

                // Trigger formal docketing
                return $this->escalateToFormalDocketing($complaintId, $userId, $notes ?: '2nd Paanyaya failed to reach settlement. Maximum informal attempts reached (Dalawang Beses Lang).', $data);
            }

            $this->conn->rollBack();
            return ['success' => false, 'message' => 'Unhandled Paanyaya outcome scenario.'];

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Paanyaya outcome recording error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording outcome: ' . $e->getMessage()];
        }
    }

    /**
     * Escalates an unsettled Paanyaya dispute to Formal Case Docketing & 1st Mediation.
     * 1. Assigns Formal Case Docket Number (e.g. 10-12-2026).
     * 2. Sets Case Stage to Mediation (1st Hearing).
     * 3. Starts statutory 15-day Punong Barangay mediation timer.
     * 4. Auto-generates KP Form 7 (Complaint), KP Form 8 (Notice), KP Form 9 (Summons).
     */
    public function escalateToFormalDocketing(int $complaintId, ?int $userId = null, ?string $notes = null, array $escalationData = []): array
    {
        try {
            require_once __DIR__ . '/CaseModel.php';
            require_once __DIR__ . '/Summons.php';

            $caseModel = new CaseModel();
            $summonsModel = new Summons();

            // 1. Check if case already docketed
            $checkCase = $this->conn->prepare('SELECT case_id, case_number, case_status FROM cases WHERE complaint_id = ? LIMIT 1');
            $checkCase->execute([$complaintId]);
            $existingCase = $checkCase->fetch(PDO::FETCH_ASSOC);

            $caseId = 0;
            $caseNumber = '';

            if ($existingCase) {
                $caseId = (int) $existingCase['case_id'];
                $caseNumber = $existingCase['case_number'];
            } else {
                // Fetch complaint details to determine case_type
                $cStmt = $this->conn->prepare('SELECT case_type FROM complaints WHERE complaint_id = ? LIMIT 1');
                $cStmt->execute([$complaintId]);
                $cType = $cStmt->fetchColumn() ?: 'Civil';

                // Allow docketing transition from Pre-Docketed
                $this->conn->prepare("UPDATE complaints SET status = 'Accepted' WHERE complaint_id = ?")->execute([$complaintId]);

                $docketSuccess = $caseModel->create([
                    'complaint_id' => $complaintId,
                    'case_type' => $cType
                ]);

                if (!$docketSuccess) {
                    return ['success' => false, 'message' => 'Failed to initialize formal case docketing in database.'];
                }

                // Retrieve assigned case number
                $fetchCase = $this->conn->prepare('SELECT case_id, case_number FROM cases WHERE complaint_id = ? ORDER BY case_id DESC LIMIT 1');
                $fetchCase->execute([$complaintId]);
                $newCase = $fetchCase->fetch(PDO::FETCH_ASSOC);
                $caseId = (int) ($newCase['case_id'] ?? 0);
                $caseNumber = $newCase['case_number'] ?? '';
            }

            // 2. Determine 1st Mediation Hearing parameters conforming to Barangay Tumana rules:
            // Allowed Day: Tuesdays ONLY.
            // Allowed Time: 1:00 PM to 5:00 PM (13:00, 14:00, 15:00, 16:00).
            // Venue: Lupon Court Room.
            require_once __DIR__ . '/../services/MediationDeadlineService.php';

            $medDate = !empty($escalationData['mediation_date']) ? trim((string)$escalationData['mediation_date']) : '';
            $medTime = !empty($escalationData['mediation_time']) ? trim((string)$escalationData['mediation_time']) : '';
            $venue = !empty($escalationData['venue']) ? trim((string)$escalationData['venue']) : 'Lupon Court Room';

            if (empty($medDate) || empty($medTime)) {
                $allowedSlots = ['13:00', '14:00', '15:00', '16:00'];
                $checkTue = new DateTime('+1 day');
                while ((int)$checkTue->format('w') !== 2) {
                    $checkTue->modify('+1 day');
                }

                $foundDate = null;
                $foundTime = null;

                for ($week = 0; $week < 4; $week++) {
                    $dateCandidate = $checkTue->format('Y-m-d');
                    $candCnt = (int) $this->conn->query("SELECT COUNT(*) FROM hearings WHERE DATE(hearing_date) = '{$dateCandidate}' AND hearing_type = 'Mediation'")->fetchColumn();
                    if ($candCnt < 8 && !MediationDeadlineService::isNonWorkingDay(new DateTimeImmutable($dateCandidate))) {
                        foreach ($allowedSlots as $slot) {
                            $candDateTime = $dateCandidate . ' ' . $slot . ':00';
                            $conflict = $summonsModel->findMediationConflict($candDateTime, $venue, null, $caseId);
                            if (!$conflict) {
                                $foundDate = $dateCandidate;
                                $foundTime = $slot;
                                break 2;
                            }
                        }
                    }
                    $checkTue->modify('+7 days');
                }

                $medDate = $foundDate ?: $checkTue->format('Y-m-d');
                $medTime = $foundTime ?: '13:00';
            }

            $hearingPayload = [
                'mediation_date' => $medDate,
                'mediation_time' => $medTime,
                'venue' => $venue,
                'remarks' => $notes ?: 'Escalated from Paanyaya (Informal Invitation) pre-docketing stage.',
                'override_paanyaya' => true
            ];

            // 3. Issue First Summons (Auto-generates KP Form 8 and KP Form 9)
            $summonsResult = $summonsModel->issueFirstSummon($complaintId, $userId ?? 1, $hearingPayload);

            // If selected slot had unexpected conflict, retry other afternoon slots
            if (empty($summonsResult['success'])) {
                $allowedSlots = ['13:00', '14:00', '15:00', '16:00'];
                foreach ($allowedSlots as $slot) {
                    if ($slot === $medTime) {
                        continue;
                    }
                    $candDateTime = $medDate . ' ' . $slot . ':00';
                    if (!$summonsModel->findMediationConflict($candDateTime, $venue, null, $caseId)) {
                        $hearingPayload['mediation_time'] = $slot;
                        $medTime = $slot;
                        $summonsResult = $summonsModel->issueFirstSummon($complaintId, $userId ?? 1, $hearingPayload);
                        if (!empty($summonsResult['success'])) {
                            break;
                        }
                    }
                }
            }

            // 4. Auto-generate KP Form 7 (Formal Complaint)
            $kp7Path = null;
            try {
                $kp7Dir = dirname(__DIR__, 2) . '/storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber);
                if (!is_dir($kp7Dir)) {
                    @mkdir($kp7Dir, 0750, true);
                }

                $kp7Relative = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber) . '/KP-Form-7-Complaint-' . date('Ymd-His') . '.pdf';
                $kp7Absolute = dirname(__DIR__, 2) . '/' . $kp7Relative;

                // Load complaint data for KP Form 7
                $cDetailsStmt = $this->conn->prepare("
                    SELECT c.*, cat.category_name 
                    FROM complaints c 
                    LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id 
                    WHERE c.complaint_id = ? LIMIT 1
                ");
                $cDetailsStmt->execute([$complaintId]);
                $cDetails = $cDetailsStmt->fetch(PDO::FETCH_ASSOC);

                $partyStmt = $this->conn->prepare("
                    SELECT cp.*, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
                           r.address, r.purok
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = ?
                    ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name
                ");
                $partyStmt->execute([$complaintId]);
                $parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

                $complainants = [];
                $respondents = [];
                foreach ($parties as $p) {
                    $item = [
                        'full_name' => $p['full_name'],
                        'address' => trim(implode(', ', array_filter([$p['address'] ?? '', $p['purok'] ?? '']))),
                    ];
                    if ($p['party_type'] === 'Complainant') $complainants[] = $item;
                    if ($p['party_type'] === 'Respondent') $respondents[] = $item;
                }

                $kp7Data = [
                    'complaint_id' => $complaintId,
                    'case_number' => $caseNumber,
                    'complaint_number' => $caseNumber,
                    'complaint_title' => $cDetails['complaint_title'] ?? '',
                    'category_name' => $cDetails['category_name'] ?? '',
                    'narrative' => $cDetails['narrative'] ?? '',
                    'requested_resolution' => $cDetails['requested_resolution'] ?? '',
                    'incident_date' => $cDetails['incident_date'] ?? date('Y-m-d'),
                    'incident_time' => $cDetails['incident_time'] ?? null,
                    'incident_location' => $cDetails['incident_location'] ?? 'Barangay Tumana',
                    'filed_date' => date('Y-m-d'),
                    'complainants' => $complainants,
                    'respondents' => $respondents,
                ];

                $this->pdf->generateKp7($kp7Data, $kp7Absolute);

                if (file_exists($kp7Absolute)) {
                    $kp7Path = $kp7Relative;
                    // Register KP Form 7 in generated_documents
                    $tplStmt = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 7' LIMIT 1");
                    $tplStmt->execute();
                    $kp7TplId = (int) $tplStmt->fetchColumn() ?: 1;

                    $insDoc = $this->conn->prepare("
                        INSERT INTO generated_documents (
                            case_id, template_id, file_path, generated_by, service_status
                        ) VALUES (?, ?, ?, ?, 'Served')
                    ");
                    $insDoc->execute([$caseId, $kp7TplId, $kp7Relative, $userId ?? 1]);
                }
            } catch (Throwable $docErr) {
                error_log('Failed to generate KP Form 7 on escalation: ' . $docErr->getMessage());
            }

            // 5. Update complaints status to Mediation
            $this->conn->prepare("UPDATE complaints SET status = 'Mediation', complaint_number = ?, updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?")->execute([$caseNumber, $complaintId]);
            $this->conn->prepare("UPDATE paanyaya_stages SET status = 'Completed', review_status = 'Approved for Summons', updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?")->execute([$complaintId]);

            if ($userId) {
                $this->audit->log($userId, "Escalated to Formal Case Docketing: Case #{$caseNumber} assigned with 1st Mediation Summons (KP Forms 7, 8, 9 generated)", 'Complaints', $complaintId);
            }

            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notifService = new NotificationService();
                $notifService->notifyRoles(
                    ['Administrator', 'Lupon Clerk'],
                    "Dispute Escalated to Formal Mediation",
                    "Dispute was escalated to Formal Mediation. Case #{$caseNumber} officially docketed with 1st Mediation hearing on {$medDate} at {$medTime}.",
                    $userId
                );
            } catch (Throwable $ne) {
                error_log('Failed to dispatch escalation notification: ' . $ne->getMessage());
            }


            return [
                'success' => true,
                'escalated' => true,
                'case_id' => $caseId,
                'action' => 'escalate_mediation',
                'case_number' => $caseNumber,

                'hearing_id' => (int) ($summonsResult['hearing_id'] ?? 0),
                'document_id' => $summonsResult['document_id'] ?? null,
                'kp8_document_id' => $summonsResult['kp8_document_id'] ?? null,
                'kp9_document_id' => $summonsResult['kp9_document_id'] ?? ($summonsResult['document_id'] ?? null),
                'complaint_id' => $complaintId,
                'hearing_date' => $medDate,
                'hearing_time' => $medTime,
                'venue' => 'Lupon Court Room',
                'kp7_generated' => ($kp7Path !== null),
                'kp8_generated' => ($summonsResult['kp8_document_id'] ?? null) !== null,
                'kp9_generated' => ($summonsResult['document_id'] ?? null) !== null,
                'workspace_url' => "../cases/mediation-workspace.php?case_id={$caseId}",
                'message' => "Formal Docketing complete! Official Case #{$caseNumber} assigned. Dispute escalated to Mediation stage. KP Forms 7, 8, and 9 generated."
            ];

        } catch (Throwable $e) {
            error_log('Escalation error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Escalation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Gets full workspace state for the Paanyaya Pre-Docketing interface.
     */
    public function getWorkspaceData(int $complaintId): ?array
    {
        $cStmt = $this->conn->prepare("
            SELECT c.*, cat.category_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS encoder_name
            FROM complaints c
            LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
            LEFT JOIN users u ON u.user_id = c.encoded_by
            WHERE c.complaint_id = ?
            LIMIT 1
        ");
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return null;
        }

        // Fetch parties
        $pStmt = $this->conn->prepare("
            SELECT cp.*, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
                   r.address, r.purok, r.contact_no, r.email, r.gender, r.civil_status
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
        ");
        $pStmt->execute([$complaintId]);
        $parties = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainants = [];
        $respondents = [];
        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant') $complainants[] = $p;
            if ($p['party_type'] === 'Respondent') $respondents[] = $p;
        }

        // Fetch all sessions
        $sessions = $this->getSessions($complaintId);
        $latestSession = !empty($sessions) ? end($sessions) : null;
        $attemptCount = (int) ($complaint['paanyaya_attempt_count'] ?? count($sessions) ?: 1);

        // Check if formally docketed
        $caseStmt = $this->conn->prepare('SELECT case_id, case_number, case_status, created_at FROM cases WHERE complaint_id = ? LIMIT 1');
        $caseStmt->execute([$complaintId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $isResolved = ($complaint['status'] === 'Settled' && empty($case)) || ($complaint['status'] === 'Resolved at Paanyaya / Closed') || !empty($complaint['resolved_without_docketing']);
        $isEscalated = ($case !== null) || in_array($complaint['status'], ['Docketed', 'Mediation', 'Conciliation', 'Arbitration'], true) || ($complaint['status'] === 'Settled' && $case !== null);
        $maxReached = !empty($complaint['max_paanyaya_reached']) || (count($sessions) >= self::MAX_ATTEMPTS);
        $canScheduleSecond = !$isResolved && !$isEscalated && (count($sessions) === 1) && in_array($latestSession['session_outcome'] ?? '', ['Absent_Retry', 'Continued_Dialogue'], true);

        $twoStages = $this->getTwoStagesData($complaintId);

        return [
            'complaint' => $complaint,
            'complainants' => $complainants,
            'respondents' => $respondents,
            'sessions' => $sessions,
            'latest_session' => $latestSession,
            'attempt_count' => $attemptCount,
            'max_attempts' => self::MAX_ATTEMPTS,
            'max_reached' => $maxReached,
            'can_schedule_second' => $canScheduleSecond,
            'is_resolved' => $isResolved,
            'is_escalated' => $isEscalated,
            'case' => $case,
            'two_stages' => $twoStages,
            'can_issue_summons' => $twoStages['summons_prerequisite']['allowed'] ?? false,
            'summons_block_reason' => $twoStages['summons_prerequisite']['message'] ?? '',
        ];
    }

    /**
     * Gets all serving officers (Summons Servers, Clerks, Admins).
     */
    public function getServingOfficers(): array
    {
        $stmt = $this->conn->query("
            SELECT u.user_id, u.username, u.role_id, r.role_name,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.status = 'Active' AND u.role_id IN (1, 2, 4)
            ORDER BY u.role_id DESC, u.last_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gets or initializes a Paanyaya stage record ('Paanyaya 1' or 'Paanyaya 2') for a complaint.
     */
    public function getOrCreateStage(int $complaintId, string $stageType): array
    {
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            throw new InvalidArgumentException("Invalid stage type: {$stageType}");
        }

        $stmt = $this->conn->prepare("
            SELECT ps.*,
                   TRIM(CONCAT_WS(' ', u_iss.first_name, u_iss.middle_name, u_iss.last_name)) AS issued_by_name,
                   TRIM(CONCAT_WS(' ', u_off.first_name, u_off.middle_name, u_off.last_name)) AS assigned_officer_name,
                   TRIM(CONCAT_WS(' ', u_rev.first_name, u_rev.middle_name, u_rev.last_name)) AS reviewed_by_name
            FROM paanyaya_stages ps
            LEFT JOIN users u_iss ON u_iss.user_id = ps.issued_by
            LEFT JOIN users u_off ON u_off.user_id = ps.assigned_officer_id
            LEFT JOIN users u_rev ON u_rev.user_id = ps.reviewed_by
            WHERE ps.complaint_id = ? AND ps.stage_type = ?
            LIMIT 1
        ");
        $stmt->execute([$complaintId, $stageType]);
        $stage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stage) {
            $ins = $this->conn->prepare("
                INSERT INTO paanyaya_stages (complaint_id, stage_type, status)
                VALUES (?, ?, 'Not Started')
            ");
            $ins->execute([$complaintId, $stageType]);

            $stmt->execute([$complaintId, $stageType]);
            $stage = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $stage;
    }

    /**
     * Gets all delivery attempts (Officer's Returns) for a stage in chronological order.
     */
    public function getStageAttempts(int $paanyayaStageId): array
    {
        $stmt = $this->conn->prepare("
            SELECT por.*, por.return_id AS id,
                   TRIM(CONCAT_WS(' ', u_off.first_name, u_off.middle_name, u_off.last_name)) AS serving_officer_name,
                   TRIM(CONCAT_WS(' ', u_rec.first_name, u_rec.middle_name, u_rec.last_name)) AS recorded_by_name
            FROM paanyaya_officer_returns por
            LEFT JOIN users u_off ON u_off.user_id = por.serving_officer_id
            LEFT JOIN users u_rec ON u_rec.user_id = por.recorded_by
            WHERE por.paanyaya_stage_id = ?
            ORDER BY por.attempt_number ASC, por.return_id ASC
        ");
        $stmt->execute([$paanyayaStageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Checks if the required Paanyaya stages have been satisfied to unlock the formal summons workflow.
     */
    public function checkSummonsPrerequisite(int $complaintId): array
    {
        $stages = $this->conn->prepare("SELECT * FROM paanyaya_stages WHERE complaint_id = ?");
        $stages->execute([$complaintId]);
        $rows = $stages->fetchAll(PDO::FETCH_ASSOC);

        $stage1 = null;
        $stage2 = null;
        foreach ($rows as $r) {
            if ($r['stage_type'] === self::STAGE_1) $stage1 = $r;
            if ($r['stage_type'] === self::STAGE_2) $stage2 = $r;
        }

        // If Paanyaya 1 doesn't exist or is Not Started
        if (!$stage1 || $stage1['status'] === 'Not Started') {
            return [
                'allowed' => false,
                'reason' => 'paanyaya_not_started',
                'message' => 'Complete the required Paanyaya preliminary delivery attempts before issuing the first formal summons.'
            ];
        }

        // Check attempts and outcomes for Stage 1
        $countStmt = $this->conn->prepare("
            SELECT COUNT(*) AS total_count,
                   SUM(CASE WHEN delivery_outcome = 'Successfully delivered' THEN 1 ELSE 0 END) AS delivered_count
            FROM paanyaya_officer_returns
            WHERE paanyaya_stage_id = ?
        ");
        $countStmt->execute([(int)$stage1['id']]);
        $stats1 = $countStmt->fetch(PDO::FETCH_ASSOC);
        $maxAttempts = $this->getMaxStageAttempts();
        $count1 = (int)($stats1['total_count'] ?? 0);
        $isDelivered1 = ((int)($stats1['delivered_count'] ?? 0) > 0) || ($stage1['status'] === 'Successfully Delivered');
        $isExhausted1 = ($count1 >= self::MAX_STAGE_ATTEMPTS && !$isDelivered1) || ($stage1['status'] === 'Attempts Exhausted – For Review');

        // Check if explicitly approved by Lupon review
        if ($stage1['review_status'] === 'Approved for Summons' || ($stage2 && $stage2['review_status'] === 'Approved for Summons')) {
            return [
                'allowed' => true,
                'message' => 'Formal summons unlocked via authorized Lupon review approval.'
            ];
        }

        // If Paanyaya 2 was initiated or required
        $isPaanyaya2Active = ($stage1['review_status'] === 'Approved for Paanyaya 2') || ($stage2 && in_array($stage2['status'], ['In Progress', 'Successfully Delivered', 'Attempts Exhausted – For Review', 'Completed'], true));

        if ($isPaanyaya2Active) {
            if (!$stage2 || $stage2['status'] === 'Not Started') {
                return [
                    'allowed' => false,
                    'reason' => 'paanyaya_2_not_started',
                    'message' => 'Complete the required Paanyaya 2 delivery attempts before issuing the first formal summons.'
                ];
            }

            $countStmt->execute([(int)$stage2['id']]);
            $stats2 = $countStmt->fetch(PDO::FETCH_ASSOC);
            $count2 = (int)($stats2['total_count'] ?? 0);
            $isDelivered2 = ((int)($stats2['delivered_count'] ?? 0) > 0) || ($stage2['status'] === 'Successfully Delivered');
            $isExhausted2 = ($count2 >= $maxAttempts && !$isDelivered2) || ($stage2['status'] === 'Attempts Exhausted – For Review');

            // 1. If Paanyaya 2 was served -> UNLOCKED!
            if ($isDelivered2 || $stage2['status'] === 'Completed') {
                return [
                    'allowed' => true,
                    'message' => 'Paanyaya 2 successfully served. Formal summons unlocked.'
                ];
            }

            // 2. If 6 attempts failed on Paanyaya 2 -> UNLOCKED!
            if ($isExhausted2 || $count2 >= $maxAttempts) {
                return [
                    'allowed' => true,
                    'message' => "{$maxAttempts} Paanyaya 2 delivery attempts completed without service. Formal summons unlocked to proceed with formal docketing."
                ];
            }

            // Still in progress (< 6 attempts and unserved)
            return [
                'allowed' => false,
                'reason' => 'paanyaya_2_in_progress',
                'message' => 'Paanyaya 2 delivery attempt ' . $count2 . ' of ' . $maxAttempts . ' recorded. Must be served or complete ' . $maxAttempts . ' failed attempts before issuing summons.'
            ];
        }

        // 1. If Paanyaya 1 was served -> UNLOCKED!
        if ($isDelivered1 || $stage1['status'] === 'Completed') {
            return [
                'allowed' => true,
                'message' => 'Paanyaya 1 successfully served. Formal summons unlocked.'
            ];
        }

        // 2. If 6 attempts failed on Paanyaya 1 -> UNLOCKED!
        if ($isExhausted1 || $count1 >= $maxAttempts) {
            return [
                'allowed' => true,
                'message' => "{$maxAttempts} Paanyaya delivery attempts completed without service. Formal summons unlocked to proceed with formal docketing."
            ];
        }

        // Paanyaya 1 is still in progress (< 6 attempts and unserved)
        return [
            'allowed' => false,
            'reason' => 'paanyaya_in_progress',
            'message' => 'Paanyaya delivery attempt ' . $count1 . ' of ' . $maxAttempts . ' recorded. Must be served or complete ' . $maxAttempts . ' failed attempts before issuing formal summons.'
        ];
    }

    /**
     * Gets complete Paanyaya workflow status for both stages.
     */
    public function getTwoStagesData(int $complaintId): array
    {
        $stage1 = $this->getOrCreateStage($complaintId, self::STAGE_1);
        $stage2 = $this->getOrCreateStage($complaintId, self::STAGE_2);

        $attempts1 = $this->getStageAttempts((int)$stage1['id']);
        $attempts2 = $this->getStageAttempts((int)$stage2['id']);

        $count1 = count($attempts1);
        $count2 = count($attempts2);

        $latest1 = !empty($attempts1) ? end($attempts1) : null;
        $latest2 = !empty($attempts2) ? end($attempts2) : null;

        $isDelivered1 = ($stage1['status'] === 'Successfully Delivered') || ($latest1 && $latest1['delivery_outcome'] === 'Successfully delivered');
        $isDelivered2 = ($stage2['status'] === 'Successfully Delivered') || ($latest2 && $latest2['delivery_outcome'] === 'Successfully delivered');

        $isExhausted1 = ($count1 >= self::MAX_STAGE_ATTEMPTS && !$isDelivered1);
        $isExhausted2 = ($count2 >= self::MAX_STAGE_ATTEMPTS && !$isDelivered2);

        $minutes1 = $this->getStageMinutes($complaintId, self::STAGE_1);
        $minutes2 = $this->getStageMinutes($complaintId, self::STAGE_2);

        $cStmt = $this->conn->prepare('SELECT status, paanyaya_1_outcome FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $compRow = $cStmt->fetch(PDO::FETCH_ASSOC);

        $isP1Solved = (($compRow['status'] ?? '') === 'Settled') ||
                      (($compRow['status'] ?? '') === 'Resolved at Paanyaya / Closed') ||
                      (($compRow['paanyaya_1_outcome'] ?? '') === 'Settled') ||
                      ($minutes1 && $minutes1['outcome'] === 'Settled');

        $p2WasIssued = ($stage2['issued_at'] !== null) || ($count2 > 0);

        $canUnlockP2FromMinutes = !$isP1Solved && ($minutes1 !== null) &&
            in_array($minutes1['outcome'], ['No_Agreement', 'Party_Absent'], true) &&
            ($minutes1['complainant_choice'] === 'Request_Paanyaya_2');

        $isEligible2 = !$isP1Solved && ($p2WasIssued || $canUnlockP2FromMinutes || ($stage1['review_status'] === 'Approved for Paanyaya 2'));

        $isCompleted1 = ($minutes1 !== null) ||
                        ($stage1['status'] === 'Completed') ||
                        in_array($stage1['review_status'], ['Approved for Paanyaya 2', 'Approved for Summons', 'Case Closed'], true);

        $canRecordAttempt1 = ($stage1['status'] === 'In Progress') && ($count1 < self::MAX_STAGE_ATTEMPTS) && !$isDelivered1;
        $canRecordAttempt2 = ($stage2['status'] === 'In Progress') && ($count2 < self::MAX_STAGE_ATTEMPTS) && !$isDelivered2;

        $prereq = $this->checkSummonsPrerequisite($complaintId);

        return [
            'stage_1' => [
                'details' => $stage1,
                'attempts' => $attempts1,
                'attempts_count' => $count1,
                'max_attempts' => self::MAX_STAGE_ATTEMPTS,
                'next_attempt_number' => min(self::MAX_STAGE_ATTEMPTS, $count1 + 1),
                'latest_attempt' => $latest1,
                'is_delivered' => $isDelivered1,
                'is_exhausted' => $isExhausted1,
                'is_completed' => $isCompleted1,
                'can_issue' => ($stage1['status'] === 'Not Started'),
                'can_record_attempt' => $canRecordAttempt1,
                'needs_review' => ($stage1['status'] === 'Attempts Exhausted – For Review' || ($isExhausted1 && $stage1['review_status'] === 'None')),
                'minutes_recorded' => ($minutes1 !== null),
                'minutes' => $minutes1,
                'outcome' => $minutes1['outcome'] ?? null,
                'complainant_choice' => $minutes1['complainant_choice'] ?? null,
            ],
            'stage_2' => [
                'details' => $stage2,
                'attempts' => $attempts2,
                'attempts_count' => $count2,
                'max_attempts' => self::MAX_STAGE_ATTEMPTS,
                'next_attempt_number' => min(self::MAX_STAGE_ATTEMPTS, $count2 + 1),
                'latest_attempt' => $latest2,
                'is_eligible' => $isEligible2,
                'can_unlock_p2' => $canUnlockP2FromMinutes,
                'is_delivered' => $isDelivered2,
                'is_exhausted' => $isExhausted2,
                'is_completed' => ($p2WasIssued && ($minutes2 !== null || $stage2['status'] === 'Completed' || in_array($stage2['review_status'], ['Approved for Summons', 'Case Closed'], true))),
                'can_issue' => ($isEligible2 && $stage2['status'] === 'Not Started'),
                'can_record_attempt' => ($isEligible2 && $canRecordAttempt2),
                'needs_review' => ($stage2['status'] === 'Attempts Exhausted – For Review' || ($isExhausted2 && $stage2['review_status'] === 'None')),
                'minutes_recorded' => ($minutes2 !== null),
                'minutes' => $minutes2,
                'outcome' => $minutes2['outcome'] ?? null,
                'complainant_choice' => $minutes2['complainant_choice'] ?? null,
                'bypassed' => ($isP1Solved && !$p2WasIssued),
            ],
            'summons_prerequisite' => $prereq,
            'serving_officers' => $this->getServingOfficers()
        ];
    }

    /**
     * Issues Paanyaya 1 or Paanyaya 2 preliminary invitation.
     */
    public function issueStage(int $complaintId, string $stageType, array $data, ?int $userId = null): array
    {
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        $cStmt = $this->conn->prepare('SELECT complaint_id, complaint_number, complaint_title, narrative, status FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        $stage = $this->getOrCreateStage($complaintId, $stageType);

        if ($stageType === self::STAGE_2) {
            $stage1 = $this->getOrCreateStage($complaintId, self::STAGE_1);
            $stage1Minutes = $this->getStageMinutes($complaintId, self::STAGE_1);
            $approved1 = in_array($stage1['review_status'], ['Approved for Paanyaya 2', 'Approved for Summons'], true);
            $canIssueP2 = ($stage1Minutes && in_array($stage1Minutes['outcome'], ['No_Agreement', 'Party_Absent'], true) && $stage1Minutes['complainant_choice'] === 'Request_Paanyaya_2');

            if (!$approved1 && !$canIssueP2 && $stage['status'] === 'Not Started') {
                return [
                    'success' => false,
                    'message' => 'Paanyaya 2 is locked. It unlocks ONLY if Paanyaya 1 concludes with No Agreement and the complainant requests a second invitation.'
                ];
            }
        }

        $invitationDate = trim((string) ($data['invitation_date'] ?? ''));
        $invitationTime = trim((string) ($data['invitation_time'] ?? ''));
        $venue = trim((string) ($data['venue'] ?? 'Tanggapan ng Barangay Tumana'));
        $salaysay = trim((string) ($data['salaysay'] ?? ''));
        $hasESignature = isset($data['has_e_signature']) ? (int) ((bool) $data['has_e_signature']) : 1;
        $assignedOfficerId = !empty($data['assigned_officer_id']) ? (int) $data['assigned_officer_id'] : null;

        if ($invitationDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $invitationDate)) {
            $invitationDate = date('Y-m-d', strtotime('+3 days'));
        }

        if ($invitationTime === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $invitationTime)) {
            $invitationTime = '14:00';
        }

        if (empty($salaysay)) {
            $salaysay = $complaint['narrative'] ?: 'naisin nilang magkausap at magkaliwanagan.';
        }

        try {
            $this->conn->beginTransaction();

            $storageDir = __DIR__ . '/../../storage/paanyaya';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0777, true);
            }

            $stageSlug = ($stageType === self::STAGE_1) ? 'paanyaya1' : 'paanyaya2';
            $attemptNum = ($stageType === self::STAGE_1) ? 1 : 2;
            $pdfFileName = sprintf('paanyaya_%d_%s_%s.pdf', $complaintId, $stageSlug, date('Ymd_His'));
            $pdfFullPath = $storageDir . '/' . $pdfFileName;
            $pdfRelativePath = 'storage/paanyaya/' . $pdfFileName;

            $this->pdf->generatePaanyaya($complaintId, [
                'save_path' => $pdfFullPath,
                'attempt_number' => $attemptNum,
                'stage_type' => $stageType,
                'invitation_date' => $invitationDate,
                'invitation_time' => $invitationTime,
                'venue' => $venue,
                'salaysay' => $salaysay,
                'has_e_signature' => $hasESignature,
            ]);

            $upd = $this->conn->prepare("
                UPDATE paanyaya_stages
                SET status = 'In Progress',
                    issued_at = CURRENT_TIMESTAMP,
                    issued_by = ?,
                    assigned_officer_id = ?,
                    invitation_date = ?,
                    invitation_time = ?,
                    venue = ?,
                    salaysay = ?,
                    has_e_signature = ?,
                    pdf_path = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([
                $userId,
                $assignedOfficerId,
                $invitationDate,
                $invitationTime,
                $venue,
                $salaysay,
                $hasESignature,
                $pdfRelativePath,
                (int)$stage['id']
            ]);

            $updComp = $this->conn->prepare("
                UPDATE complaints
                SET status = 'Pre-Docketed (Paanyaya)',
                    paanyaya_attempt_count = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE complaint_id = ?
            ");
            $updComp->execute([$attemptNum, $complaintId]);

            if ($userId) {
                $this->audit->log($userId, "Issued {$stageType} (Preliminary Invitation)", 'Paanyaya', $complaintId);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'stage_type' => $stageType,
                'stage_id' => (int)$stage['id'],
                'pdf_path' => $pdfRelativePath,
                'message' => "{$stageType} successfully issued. You may now record delivery attempts via Officer's Return."
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Paanyaya stage issue error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error issuing ' . $stageType . ': ' . $e->getMessage()];
        }
    }

    /**
     * Records an Officer's Return attempt for Paanyaya 1 or Paanyaya 2.
     * Enforces up to 6 delivery attempts with independent attempt counter per stage.
     */
    public function recordOfficerReturn(int $complaintId, string $stageType, array $data, ?int $userId = null, ?array $proofFile = null): array
    {
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        $stage = $this->getOrCreateStage($complaintId, $stageType);
        $stageId = (int) $stage['id'];

        if ($stage['status'] === 'Not Started') {
            return [
                'success' => false,
                'message' => "Cannot record an Officer's Return because {$stageType} has not been issued yet. Please issue the invitation first."
            ];
        }

        $countStmt = $this->conn->prepare("SELECT COUNT(*) FROM paanyaya_officer_returns WHERE paanyaya_stage_id = ?");
        $countStmt->execute([$stageId]);
        $existingCount = (int) $countStmt->fetchColumn();

        $maxAttempts = $this->getMaxStageAttempts();
        if ($existingCount >= $maxAttempts) {
            return [
                'success' => false,
                'message' => "Maximum {$maxAttempts} delivery attempts have already been recorded for {$stageType}. No further attempts may be added."
            ];
        }

        $attemptNumber = $existingCount + 1;

        $serviceDatetime = trim((string) ($data['service_datetime'] ?? ''));
        if ($serviceDatetime === '') {
            $serviceDatetime = date('Y-m-d H:i:s');
        } else {
            $ts = strtotime($serviceDatetime);
            if ($ts === false) {
                return ['success' => false, 'message' => 'Invalid date and time for service attempt.'];
            }
            $serviceDatetime = date('Y-m-d H:i:s', $ts);
        }

        $servingOfficerId = !empty($data['serving_officer_id']) ? (int) $data['serving_officer_id'] : ($stage['assigned_officer_id'] ?: $userId);
        if (!$servingOfficerId) {
            return ['success' => false, 'message' => 'Please select an assigned serving officer.'];
        }

        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        if ($recipientName === '') {
            return ['success' => false, 'message' => 'Respondent or intended recipient name is required.'];
        }

        $serviceAddress = trim((string) ($data['service_address'] ?? ''));
        if ($serviceAddress === '') {
            return ['success' => false, 'message' => 'Address visited is required.'];
        }

        $deliveryOutcome = trim((string) ($data['delivery_outcome'] ?? ''));
        if (!in_array($deliveryOutcome, self::VALID_DELIVERY_OUTCOMES, true)) {
            return [
                'success' => false,
                'message' => 'Invalid delivery outcome selected. Please choose a valid outcome.'
            ];
        }

        $unsuccessfulReason = trim((string) ($data['unsuccessful_reason'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $latitude = !empty($data['latitude']) ? (float) $data['latitude'] : null;
        $longitude = !empty($data['longitude']) ? (float) $data['longitude'] : null;
        $serviceLocation = trim((string) ($data['service_location'] ?? ''));

        $proofFilePath = null;
        if (!empty($proofFile) && !empty($proofFile['tmp_name']) && is_uploaded_file($proofFile['tmp_name'])) {
            $ext = strtolower(pathinfo($proofFile['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            if (!in_array($ext, $allowedExts, true)) {
                return ['success' => false, 'message' => 'Invalid proof file type. Allowed formats: JPG, PNG, WEBP, PDF.'];
            }
            if ($proofFile['size'] > 10 * 1024 * 1024) {
                return ['success' => false, 'message' => 'Proof file size exceeds 10MB limit.'];
            }

            $storageProofDir = __DIR__ . '/../../storage/paanyaya/proofs';
            if (!is_dir($storageProofDir)) {
                @mkdir($storageProofDir, 0777, true);
            }
            $stageSlug = ($stageType === self::STAGE_1) ? 'p1' : 'p2';
            $fileName = sprintf('proof_%d_%s_att%d_%s.%s', $complaintId, $stageSlug, $attemptNumber, date('Ymd_His'), $ext);
            $targetPath = $storageProofDir . '/' . $fileName;

            if (move_uploaded_file($proofFile['tmp_name'], $targetPath)) {
                $proofFilePath = 'storage/paanyaya/proofs/' . $fileName;
            }
        } elseif (!empty($data['proof_file_path'])) {
            $proofFilePath = trim((string)$data['proof_file_path']);
        }

        $isDelivered = ($deliveryOutcome === 'Successfully delivered');

        try {
            $this->conn->beginTransaction();

            $insStmt = $this->conn->prepare("
                INSERT INTO paanyaya_officer_returns (
                    paanyaya_stage_id, complaint_id, stage_type, attempt_number,
                    service_datetime, serving_officer_id, recipient_name, service_address,
                    delivery_outcome, unsuccessful_reason, remarks, proof_file_path,
                    latitude, longitude, service_location, recorded_by, submitted_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, CURRENT_TIMESTAMP
                )
            ");
            $insStmt->execute([
                $stageId,
                $complaintId,
                $stageType,
                $attemptNumber,
                $serviceDatetime,
                $servingOfficerId,
                $recipientName,
                $serviceAddress,
                $deliveryOutcome,
                $unsuccessfulReason ?: null,
                $remarks ?: null,
                $proofFilePath,
                $latitude,
                $longitude,
                $serviceLocation ?: null,
                $userId ?? 1
            ]);
            $returnId = (int) $this->conn->lastInsertId();

            if ($isDelivered) {
                $newStageStatus = 'Successfully Delivered';
            } elseif ($attemptNumber >= self::MAX_STAGE_ATTEMPTS) {
                $newStageStatus = 'Attempts Exhausted – For Review';
            } else {
                $newStageStatus = 'In Progress';
            }

            $updStage = $this->conn->prepare("
                UPDATE paanyaya_stages
                SET status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updStage->execute([$newStageStatus, $stageId]);

            if ($userId) {
                $this->audit->log(
                    $userId,
                    "Recorded Officer's Return: {$stageType} Attempt {$attemptNumber}/{$maxAttempts} — Outcome: {$deliveryOutcome}",
                    'Paanyaya',
                    $complaintId
                );
            }

            $this->conn->commit();

            return [
                'success' => true,
                'return_id' => $returnId,
                'stage_type' => $stageType,
                'attempt_number' => $attemptNumber,
                'delivery_outcome' => $deliveryOutcome,
                'stage_status' => $newStageStatus,
                'is_delivered' => $isDelivered,
                'is_exhausted' => ($attemptNumber >= $maxAttempts && !$isDelivered),
                'message' => $isDelivered
                    ? "Officer's Return recorded: {$stageType} successfully delivered!"
                    : ($attemptNumber >= $maxAttempts
                        ? "Officer's Return recorded. All {$maxAttempts} attempts for {$stageType} are exhausted. Case flagged for review."
                        : "Officer's Return recorded for Attempt {$attemptNumber} of {$maxAttempts}.")
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Record Officer's Return error: " . $e->getMessage());
            return ['success' => false, 'message' => "Error recording Officer's Return: " . $e->getMessage()];
        }
    }

    /**
     * Gets complete details of a single Officer's Return record.
     */
    public function getOfficerReturnDetails(int $returnId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT por.*, por.return_id AS id,
                   c.complaint_number, c.complaint_title,
                   TRIM(CONCAT_WS(' ', u_off.first_name, u_off.middle_name, u_off.last_name)) AS serving_officer_name,
                   TRIM(CONCAT_WS(' ', u_rec.first_name, u_rec.middle_name, u_rec.last_name)) AS recorded_by_name
            FROM paanyaya_officer_returns por
            INNER JOIN complaints c ON c.complaint_id = por.complaint_id
            LEFT JOIN users u_off ON u_off.user_id = por.serving_officer_id
            LEFT JOIN users u_rec ON u_rec.user_id = por.recorded_by
            WHERE por.return_id = ?
            LIMIT 1
        ");
        $stmt->execute([$returnId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Fetch audit log corrections for this return
        $auditStmt = $this->conn->prepare("
            SELECT at.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS user_name
            FROM audit_trails at
            LEFT JOIN users u ON u.user_id = at.user_id
            WHERE at.affected_record = ? AND at.action LIKE ?
            ORDER BY at.audit_id DESC
        ");
        $auditStmt->execute([(int)$row['complaint_id'], "%Edited Officer's Return #{$returnId}%"]);
        $row['corrections'] = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

        return $row;
    }

    /**
     * Updates permitted fields of an Officer's Return (correction workflow) and logs to audit.
     */
    public function updateOfficerReturn(int $returnId, array $data, ?int $userId = null): array
    {
        $existing = $this->getOfficerReturnDetails($returnId);
        if (!$existing) {
            return ['success' => false, 'message' => "Officer's Return record not found."];
        }

        $remarks = isset($data['remarks']) ? trim((string)$data['remarks']) : $existing['remarks'];
        $unsuccessfulReason = isset($data['unsuccessful_reason']) ? trim((string)$data['unsuccessful_reason']) : $existing['unsuccessful_reason'];
        $serviceAddress = isset($data['service_address']) ? trim((string)$data['service_address']) : $existing['service_address'];
        $recipientName = !empty($data['recipient_name']) ? trim((string)$data['recipient_name']) : $existing['recipient_name'];
        $deliveryOutcome = !empty($data['delivery_outcome']) ? trim((string)$data['delivery_outcome']) : $existing['delivery_outcome'];
        $serviceDatetime = !empty($data['service_datetime']) ? date('Y-m-d H:i:s', strtotime($data['service_datetime'])) : $existing['service_datetime'];
        $proofFilePath = !empty($data['proof_file_path']) ? $data['proof_file_path'] : $existing['proof_file_path'];
        $correctionNotes = trim((string)($data['correction_notes'] ?? 'Administrative correction'));

        $upd = $this->conn->prepare("
            UPDATE paanyaya_officer_returns
            SET remarks = ?,
                unsuccessful_reason = ?,
                service_address = ?,
                recipient_name = ?,
                delivery_outcome = ?,
                service_datetime = ?,
                proof_file_path = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE return_id = ?
        ");
        $upd->execute([
            $remarks,
            $unsuccessfulReason,
            $serviceAddress,
            $recipientName,
            $deliveryOutcome,
            $serviceDatetime,
            $proofFilePath,
            $returnId
        ]);

        // If outcome was updated to Successfully delivered, update stage status
        if ($deliveryOutcome === 'Successfully delivered') {
            $stgUpd = $this->conn->prepare("UPDATE paanyaya_stages SET status = 'Successfully Delivered', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stgUpd->execute([$existing['paanyaya_stage_id']]);
        }

        if ($userId) {
            $this->audit->log(
                $userId,
                "Edited Officer's Return #{$returnId} ({$existing['stage_type']} Attempt {$existing['attempt_number']}): {$correctionNotes}",
                'Paanyaya',
                (int)$existing['complaint_id']
            );
        }

        return [
            'success' => true,
            'message' => "Officer's Return record updated successfully."
        ];
    }

    /**
     * Records an authorized Lupon review decision when attempts are exhausted or custom transition needed.
     */
    public function reviewStage(int $complaintId, string $stageType, array $data, ?int $userId = null): array
    {
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        $decision = trim((string) ($data['decision'] ?? ''));
        $allowedDecisions = ['Approved for Paanyaya 2', 'Approved for Summons', 'Case Closed'];
        if (!in_array($decision, $allowedDecisions, true)) {
            return ['success' => false, 'message' => 'Invalid review decision. Please select an approved action.'];
        }

        $notes = trim((string) ($data['review_notes'] ?? ''));
        if ($notes === '') {
            return ['success' => false, 'message' => 'Review notes / justification are required for this decision.'];
        }

        $stage = $this->getOrCreateStage($complaintId, $stageType);
        $stageId = (int) $stage['id'];

        try {
            $this->conn->beginTransaction();

            $upd = $this->conn->prepare("
                UPDATE paanyaya_stages
                SET review_status = ?,
                    review_notes = ?,
                    reviewed_by = ?,
                    reviewed_at = CURRENT_TIMESTAMP,
                    status = 'Completed',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([$decision, $notes, $userId, $stageId]);

            if ($decision === 'Approved for Paanyaya 2') {
                $this->getOrCreateStage($complaintId, self::STAGE_2);
            }

            if ($userId) {
                $this->audit->log(
                    $userId,
                    "Lupon Review Decision for {$stageType}: {$decision}. Justification: {$notes}",
                    'Paanyaya',
                    $complaintId
                );
            }

            $this->conn->commit();

            return [
                'success' => true,
                'decision' => $decision,
                'message' => "Review decision ({$decision}) recorded successfully."
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Paanyaya review error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording review: ' . $e->getMessage()];
        }
    }
    /**
     * Gets dialogue minutes for a specific Paanyaya stage.
     */
    public function getStageMinutes(int $complaintId, string $stageType): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT pm.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name
            FROM paanyaya_minutes pm
            LEFT JOIN users u ON u.user_id = pm.recorded_by
            WHERE pm.complaint_id = ? AND pm.stage_type = ?
            ORDER BY pm.minutes_id DESC
            LIMIT 1
        ");
        $stmt->execute([$complaintId, $stageType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Gets all recorded dialogue minutes for a complaint.
     */
    public function getAllMinutes(int $complaintId): array
    {
        $stmt = $this->conn->prepare("
            SELECT pm.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name
            FROM paanyaya_minutes pm
            LEFT JOIN users u ON u.user_id = pm.recorded_by
            WHERE pm.complaint_id = ?
            ORDER BY pm.minutes_id ASC
        ");
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Records the Temporary Minutes of the Meeting and Complainant Decision.
     */
    public function recordMinutes(int $complaintId, string $stageType, array $data, int $userId, ?array $proofFile = null): array
    {
        if (!in_array($stageType, [self::STAGE_1, self::STAGE_2], true)) {
            return ['success' => false, 'message' => 'Invalid Paanyaya stage specified.'];
        }

        $cStmt = $this->conn->prepare('SELECT complaint_id, status FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $complaint = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$complaint) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        $dialogueDate = !empty($data['dialogue_date']) ? date('Y-m-d H:i:s', strtotime($data['dialogue_date'])) : date('Y-m-d H:i:s');
        $compAttended = in_array(trim((string)($data['complainant_attended'] ?? '')), ['Present', 'Absent'], true)
            ? trim((string)$data['complainant_attended']) : 'Present';
        $respAttended = in_array(trim((string)($data['respondent_attended'] ?? '')), ['Present', 'Absent'], true)
            ? trim((string)$data['respondent_attended']) : 'Present';

        $discussionSummary = trim((string)($data['discussion_summary'] ?? ($data['summary'] ?? '')));
        $sessionRemarks = trim((string)($data['session_remarks'] ?? ($data['delay_remarks'] ?? ($data['remarks'] ?? ''))));
        $actualStartTime = !empty($data['actual_start_time']) ? trim((string)$data['actual_start_time']) : null;
        if ($actualStartTime) {
            $parsedTime = strtotime($actualStartTime);
            if ($parsedTime !== false) {
                $actualStartTime = date('H:i:s', $parsedTime);
            }
        }
        $agreedTerms = trim((string)($data['agreed_terms'] ?? ($data['terms'] ?? '')));

        if ($discussionSummary === '') {
            $discussionSummary = 'Informal dialogue conducted. Summary of discussion recorded.';
        }

        // Determine outcome
        $rawOutcome = trim((string)($data['outcome'] ?? ''));
        if (stripos($rawOutcome, 'settle') !== false || stripos($rawOutcome, 'nagkasundo') !== false) {
            $outcome = 'Settled';
        } elseif ($respAttended === 'Absent') {
            $outcome = 'Party_Absent';
        } else {
            $outcome = 'No_Agreement';
        }

        // Complainant choice
        if ($outcome === 'Settled') {
            $complainantChoice = 'None';
        } else {
            $rawChoice = trim((string)($data['complainant_choice'] ?? ''));
            if ($rawChoice === 'Escalate_Mediation' || stripos($rawChoice, 'escalate') !== false || stripos($rawChoice, 'akyat') !== false) {
                $complainantChoice = 'Escalate_Mediation';
            } else {
                $complainantChoice = ($stageType === self::STAGE_2) ? 'Escalate_Mediation' : 'Request_Paanyaya_2';
            }
        }

        // File upload handling for scanned minutes
        $scannedMinutesPath = null;
        if (!empty($proofFile) && !empty($proofFile['tmp_name']) && is_uploaded_file($proofFile['tmp_name'])) {
            $ext = strtolower(pathinfo($proofFile['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            if (!in_array($ext, $allowedExts, true)) {
                return ['success' => false, 'message' => 'Invalid file format. Allowed formats: JPG, PNG, WEBP, PDF.'];
            }
            if ($proofFile['size'] > 10 * 1024 * 1024) {
                return ['success' => false, 'message' => 'File size exceeds 10MB limit.'];
            }

            $storageDir = __DIR__ . '/../../storage/paanyaya/minutes';
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0777, true);
            }
            $stageSlug = ($stageType === self::STAGE_1) ? 'p1' : 'p2';
            $fileName = sprintf('minutes_%d_%s_%s.%s', $complaintId, $stageSlug, date('Ymd_His'), $ext);
            $targetPath = $storageDir . '/' . $fileName;

            if (move_uploaded_file($proofFile['tmp_name'], $targetPath)) {
                $scannedMinutesPath = 'storage/paanyaya/minutes/' . $fileName;
            }
        } elseif (!empty($data['scanned_minutes_path'])) {
            $scannedMinutesPath = trim((string)$data['scanned_minutes_path']);
        }

        try {
            $this->conn->beginTransaction();

            $insStmt = $this->conn->prepare("
                INSERT INTO paanyaya_minutes (
                    complaint_id, stage_type, dialogue_date, actual_start_time,
                    complainant_attended, respondent_attended,
                    discussion_summary, agreed_terms, session_remarks,
                    outcome, complainant_choice,
                    scanned_minutes_path, recorded_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, CURRENT_TIMESTAMP
                )
            ");
            $insStmt->execute([
                $complaintId,
                $stageType,
                $dialogueDate,
                $actualStartTime,
                $compAttended,
                $respAttended,
                $discussionSummary,
                $agreedTerms ?: null,
                $sessionRemarks ?: null,
                $outcome,
                $complainantChoice,
                $scannedMinutesPath,
                $userId
            ]);
            $minutesId = (int) $this->conn->lastInsertId();

            if ($stageType === self::STAGE_1) {
                $updCompCols = $this->conn->prepare("
                    UPDATE complaints
                    SET paanyaya_1_outcome = ?,
                        paanyaya_complainant_choice = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updCompCols->execute([$outcome, $complainantChoice, $complaintId]);
            }

            // Also keep session updated if any exists
            $sess = $this->getByComplaintId($complaintId);
            if ($sess && !empty($sess['session_id'])) {
                $sessOutcome = ($outcome === 'Settled') ? 'Settled' : (($complainantChoice === 'Request_Paanyaya_2') ? 'Continued_Dialogue' : 'Impasse_Escalate');
                $updSess = $this->conn->prepare("
                    UPDATE paanyaya_sessions
                    SET session_outcome = ?,
                        outcome_notes = ?,
                        session_remarks = COALESCE(?, session_remarks),
                        actual_start_time = COALESCE(?, actual_start_time),
                        complainant_attended = ?,
                        respondent_attended = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE session_id = ?
                ");
                $fullActualStart = $actualStartTime ? (date('Y-m-d', strtotime($dialogueDate)) . ' ' . $actualStartTime) : null;
                $updSess->execute([$sessOutcome, $discussionSummary, $sessionRemarks ?: null, $fullActualStart, $compAttended, $respAttended, (int)$sess['session_id']]);
            }

            // Case 1: Settled
            if ($outcome === 'Settled') {
                $updComp = $this->conn->prepare("
                    UPDATE complaints
                    SET status = 'Settled',
                        resolved_without_docketing = 1,
                        ltia_category = 'Pre-Docket Resolution',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComp->execute([$complaintId]);

                $updStages = $this->conn->prepare("
                    UPDATE paanyaya_stages
                    SET status = 'Completed',
                        review_status = 'Case Closed',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updStages->execute([$complaintId]);

                $this->audit->log($userId, "Recorded Minutes for {$stageType}: Amicably Settled (Pre-Docket Resolution)", 'Paanyaya', $complaintId);

                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifService = new NotificationService();
                    $cNum = $complaint['complaint_number'] ?? "CMP-#{$complaintId}";
                    $notifService->notifyPaanyayaOutcome($complaintId, $cNum, 'Settled', $userId);
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch Paanyaya settled notification: ' . $ne->getMessage());
                }

                $this->conn->commit();

                return [
                    'success' => true,
                    'minutes_id' => $minutesId,
                    'action' => 'settled',
                    'outcome' => 'Settled',
                    'complainant_choice' => 'None',
                    'message' => 'Dispute amicably settled during informal Paanyaya dialogue! Closed without formal court docketing (LTIA performance win).'
                ];
            }

            // Case 2: No Agreement - Choice A: Request 2nd Paanyaya
            if ($stageType === self::STAGE_1 && $complainantChoice === 'Request_Paanyaya_2') {
                $stage1 = $this->getOrCreateStage($complaintId, self::STAGE_1);
                $this->conn->prepare("UPDATE paanyaya_stages SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$stage1['id']]);

                $this->audit->log($userId, "Recorded Minutes for {$stageType}: No Agreement. Complainant requested 2nd Paanyaya.", 'Paanyaya', $complaintId);

                $this->conn->commit();

                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notifService = new NotificationService();
                    $cNum = $complaint['complaint_number'] ?? "CMP-#{$complaintId}";
                    $notifService->notifyPaanyayaOutcome($complaintId, $cNum, $outcome, $userId);
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch Paanyaya notification: ' . $ne->getMessage());
                }

                return [
                    'success' => true,
                    'minutes_id' => $minutesId,
                    'action' => 'request_paanyaya_2',
                    'outcome' => $outcome,
                    'complainant_choice' => 'Request_Paanyaya_2',
                    'message' => 'Dialogue minutes recorded: No agreement reached. Complainant requested 2nd Paanyaya. Paanyaya 2 card is now unlocked!'
                ];
            }

            // Case 3: No Agreement - Choice B: Escalate to Formal Mediation (or Stage 2 failure)
            $stage1 = $this->getOrCreateStage($complaintId, $stageType);
            $this->conn->prepare("UPDATE paanyaya_stages SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$stage1['id']]);

            $this->audit->log($userId, "Recorded Minutes for {$stageType}: Escalating to Formal Mediation per complainant choice.", 'Paanyaya', $complaintId);

            $this->conn->commit();

            // Trigger formal docketing
            $escResult = $this->escalateToFormalDocketing($complaintId, $userId, $discussionSummary, $data);
            $escResult['minutes_id'] = $minutesId;
            $escResult['action'] = 'escalate_mediation';
            $escResult['stage_type'] = $stageType;
            $escResult['outcome'] = $outcome;
            $escResult['complainant_choice'] = 'Escalate_Mediation';
            $escResult['message'] = 'Dialogue minutes recorded and dispute escalated to Mediation.';

            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notifService = new NotificationService();
                $cNum = $complaint['complaint_number'] ?? "CMP-#{$complaintId}";
                $notifService->notifyPaanyayaOutcome($complaintId, $cNum, 'Escalate_Mediation', $userId);
            } catch (Throwable $ne) {
                error_log('Failed to dispatch Paanyaya escalated notification: ' . $ne->getMessage());
            }

            return $escResult;

        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Paanyaya recordMinutes error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error recording dialogue minutes: ' . $e->getMessage()];
        }
    }

}
