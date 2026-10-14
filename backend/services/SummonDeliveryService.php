<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/MediationDeadlineService.php';

class SummonDeliveryService
{
    private PDO $conn;
    private AuditService $audit;
    private MediationDeadlineService $deadlineService;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService();
        $this->deadlineService = new MediationDeadlineService($this->conn);
    }

    /**
     * Requirement 4: Strictly links and auto-initializes service delivery records for ANY hearing
     * (1st Mediation, later Mediation, Pangkat / Conciliation, Arbitration, Show Cause, or Rescheduled).
     *
     * Ensures an equally strict issuance and service workflow across all proceedings:
     * - Complainant receives Notice of Hearing / Notice of Pangkat Hearing / Notice of Rescheduled Hearing
     * - Respondent receives Summon (KP Form 9) / Notice of Hearing / Notice of Pangkat Hearing / Notice of Rescheduled Hearing
     * - Witnesses receive Subpoena (KP Form 13)
     * All start as 'Pending' and must have Officer's Return recorded.
     */
    public function initializeHearingDeliveries(int $hearingId, ?int $actorUserId = null): array
    {
        $hearingStmt = $this->conn->prepare("
            SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.rescheduled_from_id,
                   c.complaint_id, c.case_number, c.case_status
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            WHERE h.hearing_id = ?
        ");
        $hearingStmt->execute([$hearingId]);
        $hearing = $hearingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }

        $caseId = (int) $hearing['case_id'];
        $complaintId = (int) $hearing['complaint_id'];
        $isRescheduled = !empty($hearing['rescheduled_from_id']);
        $hType = $hearing['hearing_type'] ?? 'Mediation';

        // Count sequence for this hearing type
        $seqStmt = $this->conn->prepare("
            SELECT COUNT(*) FROM hearings
            WHERE case_id = ? AND hearing_type = ? AND hearing_id <= ?
        ");
        $seqStmt->execute([$caseId, $hType, $hearingId]);
        $sequence = (int) $seqStmt->fetchColumn();

        // Determine statutory form types
        if ($isRescheduled) {
            $complainantForm = 'Notice of Rescheduled Hearing';
            $respondentForm  = 'Notice of Rescheduled Hearing';
            $witnessForm     = 'Notice of Rescheduled Hearing';
        } elseif ($hType === 'Conciliation') {
            $complainantForm = 'Notice of Pangkat Hearing';
            $respondentForm  = 'Notice of Pangkat Hearing';
            $witnessForm     = 'Subpoena';
        } elseif ($hType === 'Show Cause (Complainant)') {
            $complainantForm = 'KP Form 18';
            $respondentForm  = 'Summon';
            $witnessForm     = 'Subpoena';
        } elseif ($hType === 'Show Cause (Respondent)') {
            $complainantForm = 'Notice of Hearing';
            $respondentForm  = 'KP Form 19';
            $witnessForm     = 'Subpoena';
        } else {
            // Mediation or general
            $complainantForm = 'Notice of Hearing';
            $respondentForm  = ($sequence <= 1) ? 'Summon' : 'Notice of Hearing';
            $witnessForm     = 'Subpoena';
        }

        // Fetch all registered parties for this complaint
        $partiesStmt = $this->conn->prepare("
            SELECT cp.resident_id, cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name,
                   r.address, r.purok, r.contact_no
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent', 'Witness')
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
        ");
        $partiesStmt->execute([$complaintId]);
        $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        $initialized = [];

        foreach ($parties as $p) {
            $pType = $p['party_type'];
            $resId = (int) $p['resident_id'];
            $pName = $p['resident_name'] ?? $pType;

            $formType = match ($pType) {
                'Complainant' => $complainantForm,
                'Respondent'  => $respondentForm,
                'Witness'     => $witnessForm,
                default       => 'Notice of Hearing',
            };

            $checkExisting = $this->conn->prepare("
                SELECT delivery_id FROM summon_deliveries
                WHERE hearing_id = ? AND party_type = ? AND resident_id = ?
                LIMIT 1
            ");
            $checkExisting->execute([$hearingId, $pType, $resId]);
            $existingId = $checkExisting->fetchColumn();

            if (!$existingId) {
                $ins = $this->conn->prepare("
                    INSERT INTO summon_deliveries (
                        hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                        delivery_status, recipient_name, created_by
                    ) VALUES (?, ?, ?, ?, ?, 1, 'Pending', ?, ?)
                ");
                $ins->execute([
                    $hearingId,
                    $caseId,
                    $pType,
                    $resId,
                    $formType,
                    $pName,
                    $actorUserId
                ]);
                $initialized[] = [
                    'delivery_id' => (int) $this->conn->lastInsertId(),
                    'party_type'  => $pType,
                    'resident_id' => $resId,
                    'form_type'   => $formType,
                    'status'      => 'Pending',
                ];
            }
        }

        return [
            'success'        => true,
            'hearing_id'     => $hearingId,
            'is_rescheduled' => $isRescheduled,
            'sequence'       => $sequence,
            'initialized'    => $initialized,
        ];
    }

    /**
     * Retrieves or auto-initializes summon delivery records for a hearing.
     * Supports multiple attempts for Respondent, linked notices for Complainant,
     * Subpoenas for Witnesses, and CFA unlocking evaluation.
     */
    public function getHearingDeliveries(int $hearingId): array
    {
        // First ensure deliveries are initialized for all parties on this hearing
        $this->initializeHearingDeliveries($hearingId);

        $hearingStmt = $this->conn->prepare("
            SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.status AS hearing_status,
                   h.rescheduled_from_id, c.complaint_id, c.case_number, c.case_status, c.is_paused, c.pause_reason
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            WHERE h.hearing_id = ?
        ");
        $hearingStmt->execute([$hearingId]);
        $hearing = $hearingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }

        $caseId = (int) $hearing['case_id'];

        // Get party profiles
        $partiesStmt = $this->conn->prepare("
            SELECT cp.resident_id, cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name,
                   r.address, r.purok, r.contact_no
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent', 'Witness')
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name
        ");
        $partiesStmt->execute([$hearing['complaint_id']]);
        $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainantParty = null;
        $respondentParty = null;
        $witnessParties = [];
        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant' && $complainantParty === null) {
                $complainantParty = $p;
            } elseif ($p['party_type'] === 'Respondent' && $respondentParty === null) {
                $respondentParty = $p;
            } elseif ($p['party_type'] === 'Witness') {
                $witnessParties[] = $p;
            }
        }

        // 1. Fetch Complainant delivery
        $compStmt = $this->conn->prepare("
            SELECT sd.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name
            FROM summon_deliveries sd
            LEFT JOIN users u ON u.user_id = COALESCE(sd.served_by_user_id, sd.served_by)
            WHERE sd.hearing_id = ? AND sd.party_type = 'Complainant'
            ORDER BY sd.delivery_id DESC
            LIMIT 1
        ");
        $compStmt->execute([$hearingId]);
        $complainantDeliv = $compStmt->fetch(PDO::FETCH_ASSOC);
        if ($complainantDeliv && $complainantParty) {
            $complainantDeliv['party_profile'] = $complainantParty;
        }

        // 2. Fetch all Respondent attempts
        $attempts = $this->getSummonsAttempts($caseId, $hearingId);
        foreach ($attempts as &$att) {
            if ($respondentParty) {
                $att['party_profile'] = $respondentParty;
            }
        }
        unset($att);

        $latestRespAttempt = !empty($attempts) ? end($attempts) : null;

        // Calculate next attempt eligibility
        $attemptsCount = count($attempts);
        $anyServed = false;
        $failedCount = 0;
        $hasPending = false;

        foreach ($attempts as $a) {
            if ($a['is_served']) {
                $anyServed = true;
            } elseif ($a['is_pending']) {
                $hasPending = true;
            } elseif ($a['is_failed']) {
                $failedCount++;
            }
        }

        $canInitiateNextAttempt = false;
        $nextAttemptNumber = null;
        if (!$anyServed && !$hasPending && $failedCount < 3) {
            $canInitiateNextAttempt = true;
            $nextAttemptNumber = $failedCount + 1;
        }

        // 3. Fetch Witness deliveries
        $witStmt = $this->conn->prepare("
            SELECT sd.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name,
                   r.address, r.purok, r.contact_no
            FROM summon_deliveries sd
            INNER JOIN residents r ON r.resident_id = sd.resident_id
            LEFT JOIN users u ON u.user_id = COALESCE(sd.served_by_user_id, sd.served_by)
            WHERE sd.hearing_id = ? AND sd.party_type = 'Witness'
            ORDER BY sd.delivery_id ASC
        ");
        $witStmt->execute([$hearingId]);
        $witnessDeliveries = $witStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. CFA Eligibility evaluation
        $cfaEligibility = $this->getCfaEligibility($caseId);

        // 5. Hearing-agnostic attendance lock — applies to ALL hearing types
        $serviceCheck = $this->checkServiceStatus($hearingId);

        // 6. Next-working-day SLA check (RA 7160, Sec. 410)
        $slaStatus = $this->getServiceSlaStatus($caseId, $hearingId);

        return [
            'success' => true,
            'hearing' => $hearing,
            'deliveries' => [
                $complainantDeliv ?: [],
                $latestRespAttempt ?: []
            ],
            'complainant_delivery' => $complainantDeliv,
            'respondent_attempts' => $attempts,
            'latest_respondent_attempt' => $latestRespAttempt,
            'witness_deliveries' => $witnessDeliveries,
            'attempts_count' => $attemptsCount,
            'failed_attempts_count' => $failedCount,
            'can_initiate_next_attempt' => $canInitiateNextAttempt,
            'next_attempt_number' => $nextAttemptNumber,
            'cfa_eligibility' => $cfaEligibility,
            'attendance_unlocked' => $serviceCheck['allowed'],
            'service_notice' => $serviceCheck['message'],
            'sla_status' => $slaStatus,
            'is_rescheduled' => !empty($hearing['rescheduled_from_id']),
            'hearing_type' => $hearing['hearing_type'],
        ];
    }

    /**
     * Checks whether both Complainant and Respondent notices/summons are served for a given hearing.
     * This check is hearing-type-agnostic: it applies equally to Mediation, Pangkat/Conciliation,
     * Show Cause, witness notices, rescheduling notices, and substitution summons.
     * Attendance/default logic MUST NOT be unlocked until this returns allowed=true.
     */
    public function checkServiceStatus(int $hearingId): array
    {
        // Fetch hearing type so messages can be contextual
        $htStmt = $this->conn->prepare("SELECT hearing_type FROM hearings WHERE hearing_id = ? LIMIT 1");
        $htStmt->execute([$hearingId]);
        $hearingType = (string) ($htStmt->fetchColumn() ?: 'Hearing');

        $stmt = $this->conn->prepare("
            SELECT party_type, resident_id, recipient_name, delivery_status, unserved_reason, failure_notes, attempt_number, form_type
            FROM summon_deliveries
            WHERE hearing_id = ?
            ORDER BY attempt_number DESC, delivery_id DESC
        ");
        $stmt->execute([$hearingId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // A delivery is considered served for attendance purposes under these statuses.
        // 'Served Refused' is deliberately included: a party who refuses to accept is still
        // legally deemed served under KP rules (DILG KP Training Manual, p. 34).
        $servedStatuses = ['Served', 'Served Personal', 'Served Substituted', 'Served Refused'];

        $complainantServed = false;
        $respondentServed = false;
        $unservedParties  = [];
        $pendingParties   = [];

        // Walk rows highest-attempt-first; first match per (party_type, resident_id) wins
        $seen = [];
        foreach ($rows as $r) {
            $key = $r['party_type'] . '_' . ($r['resident_id'] ?? 0);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pType = $r['party_type'];
            $st = $r['delivery_status'];
            $isServed = in_array($st, $servedStatuses, true);

            if ($pType === 'Complainant') {
                if ($isServed) {
                    $complainantServed = true;
                } elseif ($st === 'Unserved') {
                    $unservedParties[] = "Complainant ({$r['unserved_reason']})";
                } else {
                    $pendingParties[] = 'Complainant';
                }
            } elseif ($pType === 'Respondent') {
                if ($isServed) {
                    $respondentServed = true;
                } elseif ($st === 'Unserved') {
                    $unservedParties[] = "Respondent (Attempt #{$r['attempt_number']} — {$r['unserved_reason']})";
                } else {
                    $pendingParties[] = "Respondent (Attempt #{$r['attempt_number']})";
                }
            } elseif ($pType === 'Witness') {
                $wName = $r['recipient_name'] ?: 'Witness';
                if ($st === 'Unserved') {
                    $unservedParties[] = "Witness {$wName} ({$r['unserved_reason']})";
                } elseif (!$isServed) {
                    $pendingParties[] = "Witness {$wName}";
                }
            }
        }

        // Must have at least Complainant and Respondent records checked
        $hasComplainant = false;
        $hasRespondent = false;
        foreach (array_keys($seen) as $k) {
            if (str_starts_with($k, 'Complainant_')) $hasComplainant = true;
            if (str_starts_with($k, 'Respondent_')) $hasRespondent = true;
        }

        if (!$hasComplainant) {
            $pendingParties[] = 'Complainant (no service record)';
        }
        if (!$hasRespondent) {
            $pendingParties[] = 'Respondent (no service record)';
        }

        if (!empty($unservedParties)) {
            return [
                'allowed'  => false,
                'status'   => 'UNSERVED',
                'message'  => "{$hearingType} attendance locked — delivery failed for: "
                    . implode(', ', $unservedParties)
                    . '. Resolve service or obtain a CFA before proceeding.',
            ];
        }

        if (!empty($pendingParties) || !$complainantServed || !$respondentServed) {
            return [
                'allowed'  => false,
                'status'   => 'PENDING',
                'message'  => "{$hearingType} attendance locked — service pending for: "
                    . implode(', ', $pendingParties ?: array_filter(['Complainant' => !$complainantServed ? 'Complainant' : null, 'Respondent' => !$respondentServed ? 'Respondent' : null]))
                    . '. Record the Officer\'s Return before registering attendance.',
            ];
        }

        return [
            'allowed'  => true,
            'status'   => 'SERVED',
            'message'  => 'All summons, notices, and subpoenas for this hearing are served. Attendance tracking is unlocked.',
        ];
    }

    // -------------------------------------------------------------------------
    // SLA / Overdue Escalation  (RA 7160, Sec. 410)
    // -------------------------------------------------------------------------

    /**
     * Calculates the next Philippine working day after a given date,
     * skipping Sundays and known public holidays tracked in MediationDeadlineService.
     */
    public function getNextWorkingDay(string $fromDate): string
    {
        $dt = new DateTimeImmutable($fromDate);
        do {
            $dt = $dt->modify('+1 day');
        } while (
            (int) $dt->format('w') === 0              // Sunday
            || MediationDeadlineService::isNonWorkingDay($dt)
        );
        return $dt->format('Y-m-d');
    }

    /**
     * Returns the SLA status for summons issuance on the first hearing of a case.
     *
     * RA 7160, Section 410 requires the Punong Barangay to summon the respondent
     * (with notice to the complainant) by the NEXT WORKING DAY after receiving
     * the complaint. This method surfaces whether that deadline was met.
     *
     * Returns:
     *   sla_deadline        – ISO date of the next working day after complaint filing
     *   sla_met             – bool: was the first summons issued by the deadline?
     *   sla_overdue         – bool: deadline passed with no summons issued
     *   days_overdue        – int (0 when not overdue)
     *   first_issued_at     – ISO datetime of the first summons_deliveries row, or null
     *   message             – human-readable status string
     */
    public function getServiceSlaStatus(int $caseId, ?int $hearingId = null): array
    {
        // Fetch complaint filing date for this case
        $cStmt = $this->conn->prepare("
            SELECT c.case_id, c.case_number, COALESCE(cm.created_at, c.docket_date, c.created_at) AS filed_at
            FROM cases c
            LEFT JOIN complaints cm ON cm.complaint_id = c.complaint_id
            WHERE c.case_id = ?
        ");
        $cStmt->execute([$caseId]);
        $caseRow = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caseRow || empty($caseRow['filed_at'])) {
            return [
                'sla_deadline'    => null,
                'sla_met'         => null,
                'sla_overdue'     => false,
                'days_overdue'    => 0,
                'first_issued_at' => null,
                'message'         => 'Complaint filing date unavailable; SLA cannot be computed.',
            ];
        }

        $filedDate   = substr($caseRow['filed_at'], 0, 10);
        $slaDeadline = $this->getNextWorkingDay($filedDate); // next working day

        // Fetch the earliest summons delivery record for this case
        $sdSql = "SELECT MIN(created_at) FROM summon_deliveries WHERE case_id = ?";
        $sdParams = [$caseId];
        if ($hearingId) {
            $sdSql    = "SELECT MIN(created_at) FROM summon_deliveries WHERE case_id = ? AND hearing_id = ?";
            $sdParams = [$caseId, $hearingId];
        }
        $sdStmt = $this->conn->prepare($sdSql);
        $sdStmt->execute($sdParams);
        $firstIssuedAt = $sdStmt->fetchColumn() ?: null;

        $today = date('Y-m-d');

        if ($firstIssuedAt) {
            $issuedDate = substr($firstIssuedAt, 0, 10);
            $slaMet     = ($issuedDate <= $slaDeadline);
            return [
                'sla_deadline'    => $slaDeadline,
                'sla_due_at'      => $slaDeadline,
                'sla_met'         => $slaMet,
                'sla_overdue'     => false,
                'is_overdue'      => false,
                'days_overdue'    => 0,
                'first_issued_at' => $firstIssuedAt,
                'statutory_basis' => 'RA 7160, Section 410',
                'message'         => $slaMet
                    ? "Summons issued within the RA 7160 §410 next-working-day deadline ({$slaDeadline})."
                    : "Summons were issued on {$issuedDate}, after the §410 deadline of {$slaDeadline}. Review is advised.",
            ];
        }

        // No summons issued yet
        $overdue    = ($today > $slaDeadline);
        $daysOver   = $overdue ? (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($slaDeadline))->days : 0;
        return [
            'sla_deadline'    => $slaDeadline,
            'sla_due_at'      => $slaDeadline,
            'sla_met'         => false,
            'sla_overdue'     => $overdue,
            'is_overdue'      => $overdue,
            'days_overdue'    => $daysOver,
            'first_issued_at' => null,
            'statutory_basis' => 'RA 7160, Section 410',
            'message'         => $overdue
                ? "OVERDUE: No summons issued yet. The RA 7160 §410 next-working-day deadline ({$slaDeadline}) has passed by {$daysOver} day(s). Immediate action required."
                : "Summons not yet issued. Deadline is {$slaDeadline} (next working day per RA 7160 §410).",
        ];
    }

    /**
     * Escalates an overdue next-working-day summons SLA (RA 7160 §410).
     * If the SLA deadline has lapsed without summons issuance, this logs the escalation
     * in case_history, updates case_deadlines, and dispatches notifications to Lupon officers.
     */
    public function escalateOverdueSla(int $caseId, ?int $actorUserId = null): array
    {
        $status = $this->getServiceSlaStatus($caseId);
        if (!$status['sla_overdue']) {
            return [
                'escalated' => false,
                'message'   => 'SLA is not overdue. No escalation required.',
                'status'    => $status,
            ];
        }

        $actorUserId = $actorUserId ?: (int) ($_SESSION['user_id'] ?? 1);
        $daysOver = (int) $status['days_overdue'];
        $deadline = $status['sla_deadline'];

        // 1. Check if already escalated recently
        $chk = $this->conn->prepare("
            SELECT 1 FROM case_history
            WHERE case_id = ? AND remarks LIKE '%Next-working-day summons SLA overdue%'
            LIMIT 1
        ");
        $chk->execute([$caseId]);
        if ($chk->fetchColumn()) {
            return [
                'escalated' => true,
                'already_escalated' => true,
                'message'   => "Overdue SLA was already escalated previously ({$daysOver} day(s) overdue).",
                'status'    => $status,
            ];
        }

        // 2. Insert or update case_deadlines
        $dlStmt = $this->conn->prepare("
            INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
            VALUES (?, 'Next-Working-Day Summons SLA', ?, 'Overdue')
            ON DUPLICATE KEY UPDATE status = 'Overdue', due_date = VALUES(due_date), updated_at = CURRENT_TIMESTAMP
        ");
        $dlStmt->execute([$caseId, $deadline]);

        // 3. Log to case_history
        $remarks = "ESCALATION: Next-working-day summons SLA overdue by {$daysOver} day(s) (Statutory deadline was {$deadline}, RA 7160 §410). Immediate issuance of summons required.";
        $histStmt = $this->conn->prepare("
            INSERT INTO case_history (case_id, status, remarks, updated_by)
            SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?
        ");
        $histStmt->execute([$remarks, $actorUserId, $caseId]);

        // 4. Audit trail
        $this->audit->log(
            $actorUserId,
            $remarks,
            'Summons',
            $caseId
        );

        // 5. Notify administrators and clerks
        try {
            require_once __DIR__ . '/NotificationService.php';
            $notif = new NotificationService();
            $caseNum = $notif->caseNumber($caseId);
            $notif->notifyRoles(
                ['Administrator', 'Lupon Clerk'],
                'Overdue Summons SLA Escalation',
                "Case {$caseNum}: Summons issuance is {$daysOver} day(s) overdue under RA 7160 §410 (deadline was {$deadline}).",
                $actorUserId
            );
        } catch (Throwable $ne) {
            error_log('Failed to dispatch SLA escalation notification: ' . $ne->getMessage());
        }

        return [
            'escalated'         => true,
            'already_escalated' => false,
            'message'           => "SLA overdue escalation recorded. Officers notified ({$daysOver} day(s) overdue).",
            'status'            => $status,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Records that KP Form No. 8 has been printed.
     * NOTE: Printing alone does NOT constitute service (RA 7160, Sec. 410).
     * This method only logs the print event; service status remains Pending
     * until explicitly confirmed via recordDelivery().
     */
    public function markKp8Printed(int $caseId, ?int $documentId = null, ?int $hearingId = null, ?int $actorUserId = null): array
    {
        $actorUserId = $actorUserId ?: (int) ($_SESSION['user_id'] ?? 1);
        $startedTrans = false;
        if (!$this->conn->inTransaction()) {
            $this->conn->beginTransaction();
            $startedTrans = true;
        }

        try {
            // 1. Fetch case details
            $caseStmt = $this->conn->prepare("
                SELECT c.case_id, c.case_number, c.case_status, c.complaint_id
                FROM cases c
                WHERE c.case_id = ?
                FOR UPDATE
            ");
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'Case not found.'];
            }

            // 2. Resolve or verify hearing (must be Mediation)
            if (!$hearingId) {
                $hStmt = $this->conn->prepare("
                    SELECT hearing_id, hearing_type, hearing_date
                    FROM hearings
                    WHERE case_id = ? AND hearing_type = 'Mediation'
                    ORDER BY hearing_date ASC, hearing_id ASC
                    LIMIT 1
                ");
                $hStmt->execute([$caseId]);
                $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
                $hearingId = $hearing ? (int) $hearing['hearing_id'] : null;
            } else {
                $hStmt = $this->conn->prepare("
                    SELECT hearing_id, hearing_type, hearing_date
                    FROM hearings
                    WHERE hearing_id = ? AND case_id = ?
                ");
                $hStmt->execute([$hearingId, $caseId]);
                $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$hearing || ($hearing['hearing_type'] ?? '') !== 'Mediation') {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'KP Form No. 8 automatic service rule only applies to First Mediation hearings.'];
            }

            // 3. Verify document exists and is KP Form 8, or auto-resolve for this case
            $doc = null;
            if ($documentId) {
                $docStmt = $this->conn->prepare("
                    SELECT gd.document_id, gd.case_id, gd.service_status, dt.template_name
                    FROM generated_documents gd
                    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                    WHERE gd.document_id = ? AND gd.case_id = ?
                ");
                $docStmt->execute([$documentId, $caseId]);
                $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$doc || ($doc['template_name'] ?? '') !== 'KP Form 8') {
                $findKp8 = $this->conn->prepare("
                    SELECT gd.document_id, gd.case_id, gd.service_status, dt.template_name
                    FROM generated_documents gd
                    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                    WHERE gd.case_id = ? AND dt.template_name = 'KP Form 8'
                    ORDER BY gd.document_id DESC
                    LIMIT 1
                ");
                $findKp8->execute([$caseId]);
                $doc = $findKp8->fetch(PDO::FETCH_ASSOC);
                if ($doc) {
                    $documentId = (int) $doc['document_id'];
                }
            }

            if (!$doc || ($doc['template_name'] ?? '') !== 'KP Form 8') {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'No valid KP Form No. 8 document found for this case.'];
            }

            // 4. Identify complainant resident
            $compStmt = $this->conn->prepare("
                SELECT cp.resident_id, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
                ORDER BY cp.party_id ASC
                LIMIT 1
            ");
            $compStmt->execute([(int) $case['complaint_id']]);
            $complainant = $compStmt->fetch(PDO::FETCH_ASSOC);

            // 5. Check if already served (Idempotency safeguard)
            $checkDeliv = $this->conn->prepare("
                SELECT delivery_id, delivery_status
                FROM summon_deliveries
                WHERE hearing_id = ? AND party_type = 'Complainant'
                LIMIT 1
            ");
            $checkDeliv->execute([$hearingId]);
            $existingDeliv = $checkDeliv->fetch(PDO::FETCH_ASSOC);

            if ($existingDeliv && $existingDeliv['delivery_status'] === 'Served') {
                // Ensure document status is also Served
                $this->conn->prepare("UPDATE generated_documents SET service_status = 'Served' WHERE document_id = ?")->execute([$documentId]);
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->commit();
                return [
                    'success' => true,
                    'already_served' => true,
                    'message' => 'KP Form No. 8 (Complainant\'s Notice) is already marked as Served.',
                    'delivery_id' => (int) $existingDeliv['delivery_id'],
                    'service_status' => 'Served',
                ];
            }

            // Printing is not proof of service. We only log the print event.
            // Service status on generated_documents is left unchanged ('For Service' / 'Pending').
            // The officer must record actual service via recordDelivery() after physical delivery.
            $remarks = 'KP Form No. 8 printed. Service pending — officer must record actual receipt separately.';

            // 6. Mark document as printed (not served)
            $updDoc = $this->conn->prepare("UPDATE generated_documents SET service_status = 'Printed' WHERE document_id = ? AND service_status NOT IN ('Served','Service Failed')");
            $updDoc->execute([$documentId]);

            // 7. Link document_id to any existing Pending delivery row; do NOT flip delivery_status to Served.
            // Actual service must be confirmed by recording an Officer's Return via recordDelivery().
            if ($existingDeliv) {
                $updDeliv = $this->conn->prepare("
                    UPDATE summon_deliveries
                    SET document_id = COALESCE(document_id, ?),
                        remarks = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE delivery_id = ? AND delivery_status = 'Pending'
                ");
                $updDeliv->execute([$documentId, $remarks, $existingDeliv['delivery_id']]);
                $delivId = (int) $existingDeliv['delivery_id'];
            } else {
                // Create a Pending delivery row so the officer knows to record actual service
                $insDeliv = $this->conn->prepare("
                    INSERT INTO summon_deliveries (
                        hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                        delivery_status, service_method, served_at, served_by, served_by_user_id,
                        recipient_name, remarks, document_id, created_by
                    ) VALUES (?, ?, 'Complainant', ?, 'Notice of Hearing', 1, 'Pending', NULL, NULL, NULL, NULL, ?, ?, ?, ?)
                ");
                $insDeliv->execute([
                    $hearingId,
                    $caseId,
                    $complainant ? (int) $complainant['resident_id'] : null,
                    $complainant['resident_name'] ?? 'Complainant',
                    $remarks,
                    $documentId,
                    $actorUserId
                ]);
                $delivId = (int) $this->conn->lastInsertId();
            }

            // 8. Note: Proof of Service is NOT created on printing.
            // Proof of Service is exclusively created when actual receipt is confirmed via recordDelivery().

            // 9. Audit log — note print only, not service
            $this->audit->log(
                $actorUserId,
                "KP Form No. 8 printed for Case #{$case['case_number']} — service status remains Pending until Officer's Return is recorded.",
                'Summons',
                $documentId
            );

            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            return [
                'success'        => true,
                'already_served' => false,
                'message'        => 'KP Form No. 8 printed. Service status is Pending — record the Officer\'s Return after actual delivery to the complainant.',
                'delivery_id'    => $delivId,
                'document_id'    => $documentId,
                'service_status' => 'Pending',
            ];
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Error marking KP Form 8 served: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to record service for KP Form 8: ' . $e->getMessage()];
        }
    }

    /**
     * Requirement C: Retrieves all summons attempts for the respondent for a case/hearing.
     */
    public function getSummonsAttempts(int $caseId, ?int $hearingId = null): array
    {
        $params = [$caseId];
        $hSql = "";
        if ($hearingId) {
            $hSql = "AND sd.hearing_id = ?";
            $params[] = $hearingId;
        }

        $stmt = $this->conn->prepare("
            SELECT sd.*, 
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name,
                   h.hearing_type, h.hearing_date, h.venue
            FROM summon_deliveries sd
            LEFT JOIN users u ON u.user_id = COALESCE(sd.served_by_user_id, sd.served_by)
            LEFT JOIN hearings h ON h.hearing_id = sd.hearing_id
            WHERE sd.case_id = ? AND sd.party_type = 'Respondent' $hSql
            ORDER BY sd.attempt_number ASC, sd.delivery_id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        $ordinals = ['', '1st', '2nd', '3rd', '4th'];

        foreach ($rows as $row) {
            $num = (int) ($row['attempt_number'] ?: 1);
            $ordinal = $ordinals[$num] ?? $num . 'th';
            $status = $row['delivery_status'] ?: 'Pending';
            $isFailed = ($status === 'Unserved');
            $isServed = in_array($status, ['Served', 'Served Personal', 'Served Substituted', 'Served Refused'], true);
            $isPending = ($status === 'Pending');

            $formatted[] = [
                'delivery_id' => (int) $row['delivery_id'],
                'hearing_id' => (int) $row['hearing_id'],
                'document_id' => $row['document_id'] ? (int) $row['document_id'] : null,
                'case_id' => (int) $row['case_id'],
                'party_type' => $row['party_type'],
                'resident_id' => $row['resident_id'] ? (int) $row['resident_id'] : null,
                'form_type' => $row['form_type'],
                'attempt_number' => $num,
                'attempt_ordinal' => $ordinal,
                'delivery_status' => $status,
                'is_failed' => $isFailed,
                'is_served' => $isServed,
                'is_pending' => $isPending,
                'unserved_reason' => $row['unserved_reason'],
                'failure_notes' => $row['failure_notes'],
                'remarks' => $row['remarks'],
                'recipient_name' => $row['recipient_name'],
                'relationship' => $row['relationship'],
                'service_method' => $row['service_method'],
                'served_at' => $row['served_at'],
                'attempted_at' => $row['attempted_at'] ?: $row['served_at'] ?: $row['created_at'],
                'served_by' => $row['served_by'],
                'served_by_name' => $row['served_by_name'],
                'proof_file_path' => $row['proof_file_path'],
                'hearing_type' => $row['hearing_type'] ?? 'Mediation',
                'hearing_date' => $row['hearing_date'] ?? null,
            ];
        }

        return $formatted;
    }

    /**
     * Requirement C: Initiate the next summons delivery attempt for respondent (max 3 attempts).
     */
    public function initiateNextAttempt(int $caseId, int $actorUserId, ?int $hearingId = null): array
    {
        $startedTrans = false;
        if (!$this->conn->inTransaction()) {
            $this->conn->beginTransaction();
            $startedTrans = true;
        }

        try {
            // 1. Fetch case
            $cStmt = $this->conn->prepare("SELECT * FROM cases WHERE case_id = ? FOR UPDATE");
            $cStmt->execute([$caseId]);
            $case = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'Case not found.'];
            }

            if (in_array($case['case_status'], ['Settled', 'Dismissed', 'CFA Issued', 'Archived'], true)) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => "Cannot initiate summons attempt for a case that is {$case['case_status']}."];
            }

            // 2. Resolve hearing
            if (!$hearingId) {
                $hStmt = $this->conn->prepare("
                    SELECT hearing_id, hearing_type, hearing_date, venue
                    FROM hearings
                    WHERE case_id = ? AND hearing_type = 'Mediation'
                    ORDER BY hearing_date DESC, hearing_id DESC
                    LIMIT 1
                ");
                $hStmt->execute([$caseId]);
                $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
                $hearingId = $hearing ? (int) $hearing['hearing_id'] : null;
            } else {
                $hStmt = $this->conn->prepare("SELECT hearing_id, hearing_type, hearing_date, venue FROM hearings WHERE hearing_id = ? AND case_id = ?");
                $hStmt->execute([$hearingId, $caseId]);
                $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$hearing) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'No active hearing found for this case to associate summons attempt with.'];
            }

            // 3. Fetch existing attempts for Respondent
            $attemptsStmt = $this->conn->prepare("
                SELECT * FROM summon_deliveries
                WHERE hearing_id = ? AND party_type = 'Respondent'
                ORDER BY attempt_number ASC, delivery_id ASC
                FOR UPDATE
            ");
            $attemptsStmt->execute([$hearingId]);
            $existingAttempts = $attemptsStmt->fetchAll(PDO::FETCH_ASSOC);
            $count = count($existingAttempts);

            // 4. Check if any attempt is already successfully served
            foreach ($existingAttempts as $att) {
                if (in_array($att['delivery_status'], ['Served', 'Served Personal', 'Served Substituted', 'Served Refused'], true)) {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Respondent has already been successfully served. No further attempts are permitted.'];
                }
            }

            // 5. Evaluate current attempts progression
            if ($count === 0) {
                $targetAttempt = 1;
            } elseif ($count === 1) {
                $att1 = $existingAttempts[0];
                if ($att1['delivery_status'] === 'Pending') {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Attempt 1 is still pending. Record the Officer\'s Return for Attempt 1 before initiating the 2nd attempt.'];
                }
                if ($att1['delivery_status'] !== 'Unserved') {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Attempt 1 was not recorded as a qualifying delivery failure.'];
                }
                $targetAttempt = 2;
            } elseif ($count === 2) {
                $att1 = $existingAttempts[0];
                $att2 = $existingAttempts[1];
                if ($att2['delivery_status'] === 'Pending') {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Attempt 2 is still pending. Record the Officer\'s Return for Attempt 2 before initiating the 3rd attempt.'];
                }
                if ($att1['delivery_status'] !== 'Unserved' || $att2['delivery_status'] !== 'Unserved') {
                    if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                    return ['success' => false, 'message' => 'Both previous attempts must be recorded as qualifying delivery failures before initiating Attempt 3.'];
                }
                $targetAttempt = 3;
            } else {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'Maximum of 3 summons delivery attempts allowed for this hearing. A 4th attempt cannot be created.'];
            }

            // 6. Find respondent resident_id
            $rStmt = $this->conn->prepare("
                SELECT cp.resident_id, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type = 'Respondent'
                LIMIT 1
            ");
            $rStmt->execute([(int) $case['complaint_id']]);
            $respondent = $rStmt->fetch(PDO::FETCH_ASSOC);

            // 7. Generate or find KP Form 9 document for this attempt
            $docId = null;
            $tStmt = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 9' LIMIT 1");
            $tStmt->execute();
            $tplId = (int) $tStmt->fetchColumn() ?: 3;

            // Generate fresh PDF for this attempt
            require_once __DIR__ . '/PDFService.php';
            $pdfService = new PDFService();
            $root = dirname(__DIR__, 2);
            $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $case['case_number']);
            $fullDir = $root . '/' . $relativeDir;
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0750, true);
            }
            $fileName = sprintf('KP-Form-9-Summons-%d-%s.pdf', $targetAttempt, date('Ymd-His'));
            $filePath = $relativeDir . '/' . $fileName;
            $fullPath = $fullDir . '/' . $fileName;

            // Fetch parties for PDF
            $partiesStmt = $this->conn->prepare("
                SELECT cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
            ");
            $partiesStmt->execute([(int) $case['complaint_id']]);
            $allParties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);
            $complainants = array_values(array_filter($allParties, fn($p) => $p['party_type'] === 'Complainant'));
            $respondents = array_values(array_filter($allParties, fn($p) => $p['party_type'] === 'Respondent'));

            $pdfData = [
                'case_number' => $case['case_number'],
                'complaint_title' => $case['case_number'],
                'complainants' => $complainants,
                'respondents' => $respondents,
                'target_party_name' => $respondent['resident_name'] ?? 'Respondent',
                'venue' => $hearing['venue'] ?? 'Barangay Hall',
                'hearing_date' => $hearing['hearing_date'] ?? date('Y-m-d 09:00:00'),
                'notice_date' => date('Y-m-d'),
            ];

            try {
                $pdfService->generateNotice('KP Form 9', $pdfData, $fullPath);
            } catch (Throwable $pe) {
                @file_put_contents($fullPath, '%PDF-1.4 Summons Attempt ' . $targetAttempt);
            }

            $insDoc = $this->conn->prepare("
                INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status, regeneration_reason)
                VALUES (?, ?, ?, ?, 'For Service', ?)
            ");
            $insDoc->execute([$caseId, $tplId, $actorUserId, $filePath, "Summons delivery attempt #{$targetAttempt} issued."]);
            $docId = (int) $this->conn->lastInsertId();

            // 8. Insert new delivery record in summon_deliveries for this attempt
            $insDeliv = $this->conn->prepare("
                INSERT INTO summon_deliveries (
                    hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                    delivery_status, recipient_name, document_id, created_by, created_at
                ) VALUES (?, ?, 'Respondent', ?, 'Summon', ?, 'Pending', ?, ?, ?, NOW())
            ");
            $insDeliv->execute([
                $hearingId,
                $caseId,
                $respondent ? (int) $respondent['resident_id'] : null,
                $targetAttempt,
                $respondent['resident_name'] ?? 'Respondent',
                $docId,
                $actorUserId
            ]);
            $deliveryId = (int) $this->conn->lastInsertId();

            // 9. Case history & Audit log
            $ordinals = ['', '1st', '2nd', '3rd'];
            $targetOrd = $ordinals[$targetAttempt] ?? $targetAttempt . 'th';
            $auditAction = "Initiated Summons {$targetOrd} Attempt for Respondent in Case #{$case['case_number']}";
            $this->audit->log($actorUserId, $auditAction, 'Summons', $docId);

            $histStmt = $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, ?, ?, ?)
            ");
            $histStmt->execute([
                $caseId,
                $case['case_status'],
                "Issued KP Form 9 Summons {$targetOrd} Attempt. Assigned to process server for service.",
                $actorUserId
            ]);

            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'message' => "Summons {$targetOrd} Attempt initiated successfully.",
                'attempt_number' => $targetAttempt,
                'delivery_id' => $deliveryId,
                'document_id' => $docId,
                'hearing_id' => $hearingId,
                'case_id' => $caseId,
            ];
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Error initiating next summons attempt: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to initiate summons attempt: ' . $e->getMessage()];
        }
    }

    /**
     * Evaluates CFA (Certificate to File Action) eligibility per RA 7160 and DILG KP Training Manual.
     *
     * The scenarios that unlock a CFA during the summons and hearing stages are:
     *
     *   SCENARIO 1 — Three failed service attempts:
     *     All three (3) summons attempts for the respondent resulted in 'Unserved'.
     *     (RA 7160 §410 diligence requirement satisfied; CFA is unlocked.)
     *
     *   SCENARIO 2 — Served but persistently refuses to appear:
     *     Respondent was validly served ('Served Refused' or 'Served Personal'/'Served Substituted')
     *     but failed to appear at two or more scheduled hearings without justification.
     *     Service has been completed; non-attendance triggers the CFA.
     *
     * References:
     *   RA 7160, Section 410 — summons duty on PB
     *   DILG KP Enhanced Training Manual (2024), pp. 33–37
     */
    public function getCfaEligibility(int $caseId): array
    {
        $cStmt = $this->conn->prepare("SELECT case_id, case_number, case_status FROM cases WHERE case_id = ?");
        $cStmt->execute([$caseId]);
        $case = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return $this->cfaLocked('CASE_NOT_FOUND', 'Case not found.', 0, 0);
        }
        if ($case['case_status'] === 'Archived') {
            return $this->cfaLocked('CASE_ARCHIVED', 'Cannot issue CFA for an archived case.', 0, 0);
        }
        if ($case['case_status'] === 'Settled') {
            return $this->cfaLocked('CASE_SETTLED', 'Case has reached an Amicable Settlement.', 0, 0);
        }

        // Fetch all respondent summons attempts for this case (all hearings)
        $attempts      = $this->getSummonsAttempts($caseId);
        $totalAttempts = count($attempts);

        if ($totalAttempts === 0) {
            return $this->cfaLocked(
                'NO_ATTEMPTS',
                'CFA Locked — No summons delivery attempts recorded for the respondent.',
                0, 0, $attempts
            );
        }

        $anyServed     = false;   // respondent received valid service
        $hasPending    = false;   // at least one attempt not yet resolved
        $failedCount   = 0;       // 'Unserved' outcomes
        $refusedCount  = 0;       // 'Served Refused' outcomes (served but refused to sign)

        foreach ($attempts as $att) {
            if ($att['is_served']) {
                $anyServed = true;
                if ($att['delivery_status'] === 'Served Refused') {
                    $refusedCount++;
                }
            } elseif ($att['is_pending']) {
                $hasPending = true;
            } elseif ($att['is_failed']) {
                $failedCount++;
            }
        }

        // Pending attempts must be resolved before any CFA determination
        if ($hasPending) {
            return $this->cfaLocked(
                'ATTEMPT_PENDING',
                'CFA Locked — An unresolved service attempt is still pending. Record the Officer\'s Return before evaluating CFA eligibility.',
                $totalAttempts, $failedCount, $attempts
            );
        }

        // ── SCENARIO 1: Three failed delivery attempts (statutory diligence & operational policy) ────────
        if ($failedCount >= 3) {
            return [
                'eligible'       => true,
                'scenario'       => 'THREE_FAILURES',
                'reason'         => 'CFA Unlocked — All 3 summons delivery attempts failed. RA 7160 §410 diligence requirement satisfied.',
                'code'           => 'THREE_FAILURES_MET',
                'attempts_count' => $totalAttempts,
                'failed_count'   => $failedCount,
                'can_issue'      => true,
                'attempts'       => $attempts,
            ];
        }

        // ── SCENARIO 2: Served but persistently refuses to appear ─────────────────
        // Respondent was legally served but failed to attend; CFA may be issued.
        // Check for 2+ no-show hearings after a confirmed service record.
        if ($anyServed) {
            $noShowCount = $this->countRespondentNoShows($caseId);
            if ($noShowCount >= 2) {
                return [
                    'eligible'       => true,
                    'scenario'       => 'REFUSAL_TO_APPEAR',
                    'reason'         => "CFA Unlocked — Respondent was served but failed to appear at {$noShowCount} hearing(s) without justification.",
                    'code'           => 'REFUSAL_TO_APPEAR',
                    'attempts_count' => $totalAttempts,
                    'failed_count'   => $failedCount,
                    'no_show_count'  => $noShowCount,
                    'can_issue'      => true,
                    'attempts'       => $attempts,
                ];
            }
            // Served but not yet enough no-shows; attendance tracking must continue
            return $this->cfaLocked(
                'SERVICE_SUCCESSFUL_AWAITING_HEARING',
                "CFA Locked — Respondent was served. {$noShowCount} unexcused absence(s) recorded; 2 required to unlock on refusal-to-appear grounds.",
                $totalAttempts, $failedCount, $attempts
            );
        }

        // Insufficient failures, not yet served
        return $this->cfaLocked(
            'INSUFFICIENT_FAILURES',
            sprintf(
                'CFA Locked — %d of 3 failed delivery attempts recorded. All 3 summons attempts must fail before CFA can be issued on summons failure grounds.',
                $failedCount
            ),
            $totalAttempts, $failedCount, $attempts
        );
    }

    /**
     * Counts the number of unexcused respondent no-shows across all hearings for a case.
     * Used to evaluate Scenario 3 CFA eligibility.
     */
    private function countRespondentNoShows(int $caseId): int
    {
        // Check hearings table respondent_attendance
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM hearings
            WHERE case_id = ? AND respondent_attendance = 'Absent'
        ");
        $stmt->execute([$caseId]);
        $count = (int) $stmt->fetchColumn();

        if ($count === 0) {
            // Check hearing_attendance table joined with complaint_parties & hearings
            $stmt2 = $this->conn->prepare("
                SELECT COUNT(*) FROM hearing_attendance ha
                INNER JOIN hearings h ON h.hearing_id = ha.hearing_id
                INNER JOIN cases c ON c.case_id = h.case_id
                INNER JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id AND cp.resident_id = ha.resident_id
                WHERE h.case_id = ? AND cp.party_type = 'Respondent'
                  AND ha.attendance_status IN ('Absent', 'No Show', 'Unexcused Absence')
                  AND (ha.is_justified = 0 OR ha.is_justified IS NULL)
            ");
            $stmt2->execute([$caseId]);
            $count = (int) $stmt2->fetchColumn();
        }

        return $count;
    }

    /**
     * Helper: builds a standard CFA-locked response array.
     */
    private function cfaLocked(
        string $code,
        string $reason,
        int $attemptsCount,
        int $failedCount,
        array $attempts = []
    ): array {
        return [
            'eligible'       => false,
            'scenario'       => null,
            'reason'         => $reason,
            'code'           => $code,
            'attempts_count' => $attemptsCount,
            'failed_count'   => $failedCount,
            'can_issue'      => false,
            'attempts'       => $attempts,
        ];
    }

    /**
     * Records delivery result for a party's summon/notice.
     * Accurately associates each Officer's Return with its specific attempt number.
     */
    public function recordDelivery(int $hearingId, array $data, int $actorUserId): array
    {
        $partyType = trim($data['party_type'] ?? '');
        if (!in_array($partyType, ['Complainant', 'Respondent', 'Witness'], true)) {
            return ['success' => false, 'message' => 'Invalid party type. Must be Complainant, Respondent, or Witness.'];
        }

        $deliveryStatus = trim($data['delivery_status'] ?? '');
        $allowedStatuses = ['Served Personal', 'Served Substituted', 'Served Refused', 'Unserved'];
        if (!in_array($deliveryStatus, $allowedStatuses, true)) {
            return ['success' => false, 'message' => 'Invalid delivery status.'];
        }

        // Retrieve hearing and case details
        $stmt = $this->conn->prepare("
            SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.rescheduled_from_id,
                   c.complaint_id, c.case_number, c.case_status, c.is_paused, c.pause_reason
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            WHERE h.hearing_id = ?
        ");
        $stmt->execute([$hearingId]);
        $hearing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }

        $caseId = (int) $hearing['case_id'];
        $recipientName = trim($data['recipient_name'] ?? '');
        $relationship = trim($data['relationship'] ?? '');
        $servedAt = !empty($data['served_at']) ? date('Y-m-d H:i:s', strtotime($data['served_at'])) : date('Y-m-d H:i:s');
        $servedBy = !empty($data['served_by']) ? (int) $data['served_by'] : $actorUserId;
        $unservedReason = trim($data['unserved_reason'] ?? '');
        $failureNotes = trim($data['failure_notes'] ?? '');
        $remarks = trim($data['remarks'] ?? '');
        $attemptNumber = !empty($data['attempt_number']) ? max(1, (int) $data['attempt_number']) : 1;
        $deliveryId = !empty($data['delivery_id']) ? (int) $data['delivery_id'] : null;

        // Official summons delivery window validation (bypassed if development/testing)
        if ($deliveryStatus !== 'Unserved' && $servedAt !== null && $partyType === 'Respondent') {
            require_once __DIR__ . '/OfficeLogisticsService.php';
            $logistics = new OfficeLogisticsService($this->conn);
            $prevStmt = $this->conn->prepare("
                SELECT served_at FROM summon_deliveries 
                WHERE hearing_id = ? AND party_type = ? AND served_at IS NOT NULL 
                ORDER BY served_at DESC LIMIT 1
            ");
            $prevStmt->execute([$hearingId, $partyType]);
            $prevAttemptDate = $prevStmt->fetchColumn() ?: null;

            $attemptVal = $logistics->validateSummonsServiceAttempt($servedAt, $attemptNumber, $prevAttemptDate);
            if (!$attemptVal['valid']) {
                return ['success' => false, 'message' => $attemptVal['message']];
            }
        }

        // Validations per delivery scenario
        if ($deliveryStatus === 'Unserved') {
            $allowedReasons = ['Moved Out', 'Wrong Address', 'No One Home', 'Refused', 'Other'];
            if (!in_array($unservedReason, $allowedReasons, true)) {
                return ['success' => false, 'message' => 'A valid failure reason (e.g., Moved Out, Wrong Address, No One Home, Refused) is required when marking unserved.'];
            }
            if ($failureNotes === '') {
                return ['success' => false, 'message' => 'Please provide failure notes detailing the service attempt.'];
            }
            $servedAt = null;
        } elseif ($deliveryStatus === 'Served Substituted') {
            if ($recipientName === '') {
                return ['success' => false, 'message' => 'Recipient name is required for substituted service.'];
            }
            if ($relationship === '') {
                return ['success' => false, 'message' => 'Relationship to recipient is required for substituted service.'];
            }
            if ($remarks === '' && $failureNotes === '') {
                $remarks = 'Substituted service left with person of sufficient age and discretion at residence after diligent attempt.';
            }
            $unservedReason = null;
            $failureNotes = null;
        } elseif ($deliveryStatus === 'Served Personal') {
            if ($recipientName === '') {
                return ['success' => false, 'message' => 'Recipient name is required for personal service.'];
            }
            $unservedReason = null;
            $failureNotes = null;
        } elseif ($deliveryStatus === 'Served Refused') {
            // Treated as served under KP rules - reason recorded as Refused
            $unservedReason = 'Refused';
            $failureNotes = null;
            if ($remarks === '') {
                $remarks = 'Party refused to sign receipt (treated as valid service under KP rules).';
            }
        }

        // Determine resident_id
        $residentId = !empty($data['resident_id']) ? (int) $data['resident_id'] : null;
        if (!$residentId) {
            $rStmt = $this->conn->prepare("
                SELECT cp.resident_id
                FROM complaint_parties cp
                WHERE cp.complaint_id = ? AND cp.party_type = ?
                LIMIT 1
            ");
            $rStmt->execute([$hearing['complaint_id'], $partyType]);
            $residentId = (int) $rStmt->fetchColumn() ?: null;
        }

        // Determine dynamic form type based on party type and hearing stage/sequence
        if (!empty($data['form_type'])) {
            $formType = trim($data['form_type']);
        } elseif ($partyType === 'Witness') {
            $formType = 'Subpoena';
        } else {
            $isRescheduled = !empty($hearing['rescheduled_from_id']);
            $isPangkat = str_contains($hearing['hearing_type'], 'Pangkat') || str_contains($hearing['hearing_type'], 'Conciliation');
            if ($partyType === 'Complainant') {
                if ($hearing['hearing_type'] === 'Show Cause (Complainant)') {
                    $formType = 'KP Form 18';
                } elseif ($isRescheduled) {
                    $formType = 'Notice of Rescheduled Hearing';
                } elseif ($isPangkat) {
                    $formType = 'Notice of Pangkat Hearing';
                } else {
                    $formType = 'Notice of Hearing';
                }
            } else {
                if ($hearing['hearing_type'] === 'Show Cause (Respondent)') {
                    $formType = 'KP Form 19';
                } elseif ($isRescheduled) {
                    $formType = 'Notice of Rescheduled Hearing';
                } elseif ($isPangkat) {
                    $formType = 'Notice of Pangkat Hearing';
                } else {
                    $formType = 'Summon';
                }
            }
        }

        // Locate or update the target attempt row in summon_deliveries
        if ($deliveryId) {
            $chk = $this->conn->prepare("
                SELECT delivery_id, attempt_number, party_type, delivery_status
                FROM summon_deliveries
                WHERE delivery_id = ?
            ");
            $chk->execute([$deliveryId]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing && !empty($existing['delivery_status']) && $existing['delivery_status'] !== 'Pending') {
                return [
                    'success' => false,
                    'message' => "Attempt #{$existing['attempt_number']} for {$existing['party_type']} has already been officially recorded as '{$existing['delivery_status']}' and cannot be modified."
                ];
            }

            $upd = $this->conn->prepare("
                UPDATE summon_deliveries
                SET delivery_status = ?,
                    served_at = ?,
                    served_by = ?,
                    served_by_user_id = ?,
                    attempted_at = COALESCE(attempted_at, NOW()),
                    recipient_name = ?,
                    relationship = ?,
                    unserved_reason = ?,
                    failure_notes = ?,
                    remarks = ?,
                    form_type = COALESCE(form_type, ?),
                    resident_id = COALESCE(resident_id, ?),
                    updated_at = CURRENT_TIMESTAMP
                WHERE delivery_id = ?
            ");
            $upd->execute([
                $deliveryStatus,
                $servedAt,
                $servedBy,
                $servedBy,
                $recipientName ?: null,
                $relationship ?: null,
                $unservedReason ?: null,
                $failureNotes ?: null,
                $remarks ?: null,
                $formType,
                $residentId,
                $deliveryId
            ]);
        } else {
            // Check if a row with this hearing, party_type, (resident_id if known), attempt_number exists
            if ($residentId) {
                $find = $this->conn->prepare("
                    SELECT delivery_id, attempt_number, party_type, delivery_status FROM summon_deliveries
                    WHERE hearing_id = ? AND party_type = ? AND resident_id = ? AND attempt_number = ?
                    LIMIT 1
                ");
                $find->execute([$hearingId, $partyType, $residentId, $attemptNumber]);
            } else {
                $find = $this->conn->prepare("
                    SELECT delivery_id, attempt_number, party_type, delivery_status FROM summon_deliveries
                    WHERE hearing_id = ? AND party_type = ? AND attempt_number = ?
                    LIMIT 1
                ");
                $find->execute([$hearingId, $partyType, $attemptNumber]);
            }
            $existing = $find->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (!empty($existing['delivery_status']) && $existing['delivery_status'] !== 'Pending') {
                    return [
                        'success' => false,
                        'message' => "Attempt #{$existing['attempt_number']} for {$existing['party_type']} has already been officially recorded as '{$existing['delivery_status']}' and cannot be modified."
                    ];
                }
                $deliveryId = (int) $existing['delivery_id'];
                $upd = $this->conn->prepare("
                    UPDATE summon_deliveries
                    SET delivery_status = ?,
                        served_at = ?,
                        served_by = ?,
                        served_by_user_id = ?,
                        attempted_at = COALESCE(attempted_at, NOW()),
                        recipient_name = ?,
                        relationship = ?,
                        unserved_reason = ?,
                        failure_notes = ?,
                        remarks = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE delivery_id = ?
                ");
                $upd->execute([
                    $deliveryStatus,
                    $servedAt,
                    $servedBy,
                    $servedBy,
                    $recipientName ?: null,
                    $relationship ?: null,
                    $unservedReason ?: null,
                    $failureNotes ?: null,
                    $remarks ?: null,
                    $deliveryId
                ]);
            } else {
                $ins = $this->conn->prepare("
                    INSERT INTO summon_deliveries (
                        hearing_id, case_id, party_type, resident_id, form_type, attempt_number,
                        delivery_status, service_method, attempted_at, served_at, served_by, served_by_user_id,
                        recipient_name, relationship, unserved_reason, failure_notes, remarks
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Personal', NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $hearingId,
                    $caseId,
                    $partyType,
                    $residentId,
                    $formType,
                    $attemptNumber,
                    $deliveryStatus,
                    $servedAt,
                    $servedBy,
                    $servedBy,
                    $recipientName ?: null,
                    $relationship ?: null,
                    $unservedReason ?: null,
                    $failureNotes ?: null,
                    $remarks ?: null
                ]);
                $deliveryId = (int) $this->conn->lastInsertId();
            }
        }

        // Associate with corresponding generated document
        $docId = !empty($data['document_id']) ? (int) $data['document_id'] : null;
        if (!$docId) {
            $targetTpl = ($partyType === 'Respondent') 
                ? (($hearing['hearing_type'] === 'Show Cause (Respondent)') ? 'KP Form 19' : 'KP Form 9')
                : (($partyType === 'Witness') ? 'KP Form 13' : (($hearing['hearing_type'] === 'Show Cause (Complainant)') ? 'KP Form 18' : 'KP Form 8'));
            $likeTpl = ($partyType === 'Respondent') 
                ? '%Summon%' 
                : (($partyType === 'Witness') ? '%Subpoena%' : '%Notice%');
            $docStmt = $this->conn->prepare("
                SELECT gd.document_id
                FROM generated_documents gd
                INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                WHERE gd.case_id = ? AND (dt.template_name = ? OR dt.template_name LIKE ?)
                ORDER BY gd.document_id DESC
                LIMIT 1
            ");
            $docStmt->execute([$caseId, $targetTpl, $likeTpl]);
            $docId = (int) $docStmt->fetchColumn() ?: null;
        }

        // Link document_id to summon_deliveries if available
        if ($docId && $deliveryId) {
            $this->conn->prepare("UPDATE summon_deliveries SET document_id = COALESCE(document_id, ?) WHERE delivery_id = ?")->execute([$docId, $deliveryId]);
        }

        $ordinals = ['', '1st', '2nd', '3rd'];
        $attemptOrd = $ordinals[$attemptNumber] ?? $attemptNumber . 'th';

        if ($deliveryStatus === 'Unserved') {
            // SCENARIO B: Delivery Failed (Unserved)
            if ($hearing['case_status'] === 'Mediation' && empty($hearing['is_paused'])) {
                $pauseReason = "Summon Unserved ({$partyType} Attempt #{$attemptNumber}) - {$unservedReason}";
                $this->deadlineService->pauseMediation($caseId, $pauseReason, $failureNotes, $actorUserId);
            }

            // Record in proof_of_service with attempt number
            $posRemarks = "Summon {$attemptOrd} Attempt unserved for {$partyType}: {$unservedReason}. Notes: {$failureNotes}";
            $posStmt = $this->conn->prepare("
                INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks)
                VALUES (?, ?, 'Service Failed', ?, NOW(), ?)
            ");
            $posStmt->execute([$caseId, $docId, $servedBy, $posRemarks]);

            if ($docId) {
                $updDoc = $this->conn->prepare("UPDATE generated_documents SET service_status = 'Service Failed' WHERE document_id = ?");
                $updDoc->execute([$docId]);
            }

            $this->audit->log(
                $actorUserId,
                "Summon {$attemptOrd} Attempt marked Unserved for {$partyType} in Case #{$hearing['case_number']}. Reason: {$unservedReason}",
                'Hearings',
                $caseId
            );
        } else {
            // SCENARIO A: Served (Personal, Substituted, or Refused)
            $posRemarks = "{$partyType} ({$formType}) {$attemptOrd} Attempt {$deliveryStatus}. Recipient: {$recipientName}" . ($relationship ? " ({$relationship})" : "");
            $posStmt = $this->conn->prepare("
                INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks)
                VALUES (?, ?, 'Served', ?, ?, ?)
            ");
            $posStmt->execute([$caseId, $docId, $servedBy, $servedAt, $posRemarks]);

            if ($docId) {
                $updDoc = $this->conn->prepare("UPDATE generated_documents SET service_status = 'Served' WHERE document_id = ?");
                $updDoc->execute([$docId]);
            }

            // Check if BOTH are now served
            $serviceCheck = $this->checkServiceStatus($hearingId);
            if ($serviceCheck['allowed']) {
                if (!empty($hearing['is_paused']) && str_starts_with((string) $hearing['pause_reason'], 'Summon Unserved')) {
                    $this->deadlineService->resumeMediation($caseId, $actorUserId);
                }
            }

            $this->audit->log(
                $actorUserId,
                "Summon {$attemptOrd} Attempt delivery recorded as '{$deliveryStatus}' for {$partyType} in Case #{$hearing['case_number']}",
                'Hearings',
                $caseId
            );
        }

        $cfaElig = $this->getCfaEligibility($caseId);

        return [
            'success' => true,
            'message' => "Summon {$attemptOrd} Attempt for {$partyType} updated to {$deliveryStatus}.",
            'delivery_id' => $deliveryId,
            'attempt_number' => $attemptNumber,
            'cfa_eligibility' => $cfaElig,
            'service_status' => $this->checkServiceStatus($hearingId),
        ];
    }
}
