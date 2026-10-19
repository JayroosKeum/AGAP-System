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

        if (!empty($complaint['resolved_without_docketing']) || $complaint['status'] === 'Resolved at Paanyaya / Closed') {
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
    public function recordOutcome(int $sessionId, array $data, ?int $userId = null): array
    {
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
                    SET status = 'Resolved at Paanyaya / Closed',
                        resolved_without_docketing = 1,
                        ltia_category = 'Pre-Docket Resolution',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                ");
                $updComplaint->execute([$complaintId]);

                if ($userId) {
                    $this->audit->log($userId, 'Informally Resolved at Paanyaya / Closed (Pre-Docket Resolution)', 'Complaints', $complaintId);
                }

                $this->conn->commit();

                return [
                    'success' => true,
                    'outcome' => 'Settled',
                    'status' => 'Resolved at Paanyaya / Closed',
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
                    if (!MediationDeadlineService::isNonWorkingDay(new DateTimeImmutable($dateCandidate))) {
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
                'remarks' => $notes ?: 'Escalated from Paanyaya (Informal Invitation) pre-docketing stage.'
            ];

            // 3. Issue First Summons (Auto-generates KP Form 8 and KP Form 9)
            $summonsResult = $summonsModel->issueFirstSummon($complaintId, $userId ?? 1, $hearingPayload);

            // If selected slot had unexpected conflict, retry other afternoon slots
            if (empty($summonsResult['success']) && !empty($summonsResult['conflict'])) {
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
            $this->conn->prepare("UPDATE complaints SET status = 'Mediation', updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?")->execute([$complaintId]);

            if ($userId) {
                $this->audit->log($userId, "Escalated to Formal Case Docketing: Case #{$caseNumber} assigned with 1st Mediation Summons (KP Forms 7, 8, 9 generated)", 'Complaints', $complaintId);
            }

            return [
                'success' => true,
                'escalated' => true,
                'case_id' => $caseId,
                'case_number' => $caseNumber,
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

        $isResolved = ($complaint['status'] === 'Resolved at Paanyaya / Closed') || !empty($complaint['resolved_without_docketing']);
        $isEscalated = ($case !== null) || in_array($complaint['status'], ['Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled'], true);
        $maxReached = !empty($complaint['max_paanyaya_reached']) || (count($sessions) >= self::MAX_ATTEMPTS);
        $canScheduleSecond = !$isResolved && !$isEscalated && (count($sessions) === 1) && in_array($latestSession['session_outcome'] ?? '', ['Absent_Retry', 'Continued_Dialogue'], true);

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
        ];
    }
}
