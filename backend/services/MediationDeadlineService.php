<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditService.php';

class MediationDeadlineService
{
    private PDO $conn;
    private AuditService $audit;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService();
    }

    /**
     * Determines whether a given date is a non-working day (Saturday, Sunday, or Philippine regular holiday).
     */
    public static function isNonWorkingDay(DateTimeInterface $date): bool
    {
        // 1. Weekend check: Saturday (6) or Sunday (7)
        $dayOfWeek = (int) $date->format('N');
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            return true;
        }

        // 2. Fixed regular Philippine holidays (format: MM-DD)
        $fixedHolidays = [
            '01-01', // New Year's Day
            '04-09', // Araw ng Kagitingan / Day of Valor
            '05-01', // Labor Day
            '06-12', // Independence Day
            '08-21', // Ninoy Aquino Day
            '11-01', // All Saints' Day
            '11-02', // All Souls' Day
            '11-30', // Bonifacio Day
            '12-08', // Feast of the Immaculate Conception
            '12-25', // Christmas Day
            '12-30', // Rizal Day
            '12-31', // New Year's Eve
        ];

        $md = $date->format('m-d');
        if (in_array($md, $fixedHolidays, true)) {
            return true;
        }

        $year = (int) $date->format('Y');

        // 3. Movable holiday: National Heroes Day (Last Monday of August)
        $lastDayOfAugust = new DateTimeImmutable("{$year}-08-31");
        $dayOfWeekLast = (int) $lastDayOfAugust->format('N');
        // Subtract days to reach the last Monday
        $daysToLastMonday = ($dayOfWeekLast >= 1) ? ($dayOfWeekLast - 1) : 6;
        $nationalHeroesDay = $lastDayOfAugust->modify("-{$daysToLastMonday} days")->format('Y-m-d');
        if ($date->format('Y-m-d') === $nationalHeroesDay) {
            return true;
        }

        // 4. Movable holidays: Maundy Thursday & Good Friday (Easter-based)
        $easterTimestamp = function_exists('easter_date') ? easter_date($year) : false;
        if ($easterTimestamp !== false) {
            $easterDate = new DateTimeImmutable(date('Y-m-d', $easterTimestamp));
            $maundyThursday = $easterDate->modify('-3 days')->format('Y-m-d');
            $goodFriday = $easterDate->modify('-2 days')->format('Y-m-d');
            $formattedDate = $date->format('Y-m-d');
            if ($formattedDate === $maundyThursday || $formattedDate === $goodFriday) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculates deadline excluding weekends (Saturdays & Sundays) and official holidays (strictly working days).
     * NOTE: RA 7160 §410 specifies 15 CALENDAR days. This method computes BUSINESS days and should
     * only be used when a local/legal rule authorising business-day counting is on record.
     *
     * @param string|DateTimeInterface $startDate
     * @param int $daysToAdd
     * @return string Y-m-d format
     */
    public static function calculateBusinessDays(string|DateTimeInterface $startDate, int $daysToAdd = 15): string
    {
        if (is_string($startDate)) {
            $cur = new DateTimeImmutable(substr($startDate, 0, 10));
        } else {
            $cur = DateTimeImmutable::createFromInterface($startDate);
        }

        $added = 0;
        while ($added < $daysToAdd) {
            $cur = $cur->modify('+1 day');
            if (self::isNonWorkingDay($cur)) {
                continue;
            }
            $added++;
        }

        return $cur->format('Y-m-d');
    }

    /**
     * Calculates the statutory 15-day mediation deadline from a given start date.
     *
     * LEGAL BASIS
     * -----------
     * RA 7160, Section 410(b): "The Lupon Chairman shall, within fifteen (15) days
     * from the date of filing of the complaint, bring the parties together for amicable
     * settlement…" — the 15-day period is expressed in CALENDAR days.
     *
     * By default this method counts true calendar days (including weekends and holidays)
     * as the statute requires. If an authorised local ordinance or DILG memorandum circular
     * permits business-day counting for a specific barangay, pass $useBusinessDays = true
     * and document the legal basis in the audit trail before calling this method.
     *
     * @param string $startDate        ISO date (Y-m-d) — MUST be the date of the first actual
     *                                 meeting before the Lupon Chairman, not the scheduling date.
     * @param int    $calendarDays     Number of days (default 15 per statute).
     * @param bool   $useBusinessDays  Override to business-day counting ONLY when legally authorised.
     * @return string Y-m-d
     */
    public static function calculateDeadline(
        string $startDate,
        int $calendarDays = 15,
        bool $useBusinessDays = false
    ): string {
        if ($useBusinessDays) {
            // Business-day counting — requires a documented local/legal authorisation.
            return self::calculateBusinessDays($startDate, $calendarDays);
        }

        // Default: true calendar days as prescribed by RA 7160 §410.
        $dt = new DateTimeImmutable(substr($startDate, 0, 10));
        return $dt->modify("+{$calendarDays} days")->format('Y-m-d');
    }

    /**
     * Calculates target date adding days to start date.
     * Provided for compatibility with callers expecting calculateTargetDate().
     *
     * @param string|DateTimeInterface $startDate
     * @param int $days
     * @param bool $useBusinessDays
     * @return string Y-m-d
     */
    public static function calculateTargetDate(
        string|DateTimeInterface $startDate,
        int $days = 15,
        bool $useBusinessDays = false
    ): string {
        $str = ($startDate instanceof DateTimeInterface)
            ? $startDate->format('Y-m-d')
            : substr((string) $startDate, 0, 10);
        return self::calculateDeadline($str, $days, $useBusinessDays);
    }

    public static function calculateTargetDateStatic(
        string|DateTimeInterface $startDate,
        int $days = 15,
        bool $useBusinessDays = false
    ): string {
        $str = ($startDate instanceof DateTimeInterface)
            ? $startDate->format('Y-m-d')
            : substr((string) $startDate, 0, 10);
        return self::calculateDeadline($str, $days, $useBusinessDays);
    }

    /**
     * Computes Pangkat Conciliation status, convening deadline (3 days from constitution),
     * and statutory 15-day conciliation period (commencing when convened, plus extensions).
     *
     * LEGAL BASIS: RA 7160 Section 410(c)
     */
    public function computeConciliationStatus(int $caseId): array
    {
        $stmt = $this->conn->prepare("
            SELECT pg.*, c.case_status, c.case_number
            FROM pangkat_groups pg
            INNER JOIN cases c ON c.case_id = pg.case_id
            WHERE pg.case_id = ?
            LIMIT 1
        ");
        $stmt->execute([$caseId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            return [
                'is_constituted' => false,
                'status' => 'Not Constituted',
                'message' => 'Pangkat has not been constituted for this case.'
            ];
        }

        $formationDate = $group['formation_date'];
        $conveningDeadline = $group['convening_deadline'] ?: self::calculateDeadline($formationDate, 3);
        $convenedDate = $group['convened_date'];
        $isConvened = !empty($convenedDate);
        $today = date('Y-m-d');

        // Check 3-day convening status
        $conveningOverdue = false;
        $conveningDaysLeft = 0;
        if (!$isConvened) {
            $dtToday = new DateTimeImmutable($today);
            $dtDeadline = new DateTimeImmutable($conveningDeadline);
            $conveningOverdue = ($today > $conveningDeadline);
            $conveningDaysLeft = (int) $dtToday->diff($dtDeadline)->format('%r%a');
        }

        // Conciliation 15-day period (commences on convened_date)
        $conciliationDeadline = $group['conciliation_deadline'];
        if ($isConvened && empty($conciliationDeadline)) {
            $totalDays = 15 + (int) ($group['extended_days'] ?? 0);
            $conciliationDeadline = self::calculateDeadline($convenedDate, $totalDays);
        }

        $isLapsed = false;
        $daysLeft = null;
        if ($isConvened && $conciliationDeadline) {
            $dtToday = new DateTimeImmutable($today);
            $dtConDeadline = new DateTimeImmutable($conciliationDeadline);
            $isLapsed = ($today > $conciliationDeadline);
            $daysLeft = (int) $dtToday->diff($dtConDeadline)->format('%r%a');
        }

        // Active challenges
        $chalStmt = $this->conn->prepare("
            SELECT COUNT(*) FROM pangkat_challenges 
            WHERE case_id = ? AND status = 'Pending'
        ");
        $chalStmt->execute([$caseId]);
        $pendingChallengesCount = (int) $chalStmt->fetchColumn();

        return [
            'is_constituted' => true,
            'pangkat_id' => (int) $group['pangkat_id'],
            'case_id' => $caseId,
            'case_number' => $group['case_number'] ?? null,
            'case_status' => $group['case_status'] ?? null,
            'formation_date' => $formationDate,
            'convening_deadline' => $conveningDeadline,
            'is_convened' => $isConvened,
            'convened_date' => $convenedDate,
            'convening_overdue' => $conveningOverdue,
            'convening_days_left' => $conveningDaysLeft,
            'conciliation_deadline' => $conciliationDeadline,
            'is_lapsed' => $isLapsed,
            'days_remaining' => $daysLeft,
            'is_extended' => !empty($group['is_extended']),
            'extended_days' => (int) ($group['extended_days'] ?? 0),
            'extension_type' => $group['extension_type'] ?? 'None',
            'selection_method' => $group['selection_method'],
            'selection_stage' => $group['selection_stage'] ?? 'Constituted',
            'party_agreement_failed' => !empty($group['party_agreement_failed']),
            'party_agreement_failure_reason' => $group['party_agreement_failure_reason'] ?? null,
            'pending_challenges_count' => $pendingChallengesCount
        ];
    }

    /**
     * Computes the 15-day statutory mediation status, days remaining, and urgency badge for a case.
     */
    public function computeStatus(array $case): array
    {
        $caseStatus = $case['case_status'] ?? '';
        $startDate = $case['mediation_start_date'] ?? null;
        $deadlineDate = $case['mediation_deadline_date'] ?? null;
        $isPaused = !empty($case['is_paused']);
        $pausedAt = $case['paused_at'] ?? null;
        $pauseReason = $case['pause_reason'] ?? null;
        $pauseNotes = $case['pause_notes'] ?? null;

        if ($caseStatus !== 'Mediation') {
            $isClosedOrAdvanced = in_array($caseStatus, ['Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived'], true);
            return [
                'days_remaining' => null,
                'timer_status' => $isClosedOrAdvanced ? 'Concluded' : 'Not Started',
                'badge_class' => 'badge-status-default',
                'badge_label' => $isClosedOrAdvanced ? 'Concluded' : 'Not Started',
                'is_lapsed' => false,
                'is_paused' => false,
                'mediation_start_date' => $startDate,
                'mediation_deadline_date' => $deadlineDate,
                'pause_reason' => null,
                'pause_notes' => null,
                'paused_at' => null,
                'can_pause' => false,
                'can_resume' => false,
            ];
        }

        // If in Mediation but start date is somehow missing, fall back to docket date or first hearing date
        if (!$startDate) {
            return [
                'days_remaining' => null,
                'timer_status' => 'Pending Start',
                'badge_class' => 'badge-status-pending',
                'badge_label' => 'Pending Start',
                'is_lapsed' => false,
                'is_paused' => false,
                'mediation_start_date' => null,
                'mediation_deadline_date' => null,
                'pause_reason' => null,
                'pause_notes' => null,
                'paused_at' => null,
                'can_pause' => false,
                'can_resume' => false,
            ];
        }

        if (!$deadlineDate) {
            $deadlineDate = self::calculateDeadline($startDate, 15);
        }

        // If paused, clock is frozen
        if ($isPaused) {
            $pauseRefDate = new DateTimeImmutable(substr($pausedAt ?: 'now', 0, 10));
            $deadlineObj = new DateTimeImmutable($deadlineDate);
            $daysRemaining = (int) $pauseRefDate->diff($deadlineObj)->format('%r%a');

            $reasonDisplay = $pauseReason ?: 'Clock Paused';
            return [
                'days_remaining' => $daysRemaining,
                'timer_status' => 'Paused',
                'badge_class' => 'badge-paused',
                'badge_label' => "Paused: {$reasonDisplay}",
                'is_lapsed' => false,
                'is_paused' => true,
                'mediation_start_date' => $startDate,
                'mediation_deadline_date' => $deadlineDate,
                'pause_reason' => $pauseReason,
                'pause_notes' => $pauseNotes,
                'paused_at' => $pausedAt,
                'can_pause' => false,
                'can_resume' => true,
            ];
        }

        // Active timer calculation
        $today = new DateTimeImmutable(date('Y-m-d'));
        $deadlineObj = new DateTimeImmutable($deadlineDate);
        $daysRemaining = (int) $today->diff($deadlineObj)->format('%r%a');

        if ($daysRemaining > 5) {
            return [
                'days_remaining' => $daysRemaining,
                'timer_status' => 'Within Period',
                'badge_class' => 'badge-within-period',
                'badge_label' => "{$daysRemaining} days remaining",
                'is_lapsed' => false,
                'is_paused' => false,
                'mediation_start_date' => $startDate,
                'mediation_deadline_date' => $deadlineDate,
                'pause_reason' => null,
                'pause_notes' => null,
                'paused_at' => null,
                'can_pause' => true,
                'can_resume' => false,
            ];
        }

        if ($daysRemaining > 0) {
            return [
                'days_remaining' => $daysRemaining,
                'timer_status' => 'Expiring Soon',
                'badge_class' => 'badge-expiring-soon',
                'badge_label' => "{$daysRemaining} days remaining - Action Needed",
                'is_lapsed' => false,
                'is_paused' => false,
                'mediation_start_date' => $startDate,
                'mediation_deadline_date' => $deadlineDate,
                'pause_reason' => null,
                'pause_notes' => null,
                'paused_at' => null,
                'can_pause' => true,
                'can_resume' => false,
            ];
        }

        // Lapsed (<= 0 days remaining)
        $this->checkAndLogLapse((int) $case['case_id'], $deadlineDate);

        return [
            'days_remaining' => $daysRemaining,
            'timer_status' => 'Mediation Lapsed',
            'badge_class' => 'badge-mediation-lapsed',
            'badge_label' => '15-day limit reached',
            'is_lapsed' => true,
            'is_paused' => false,
            'mediation_start_date' => $startDate,
            'mediation_deadline_date' => $deadlineDate,
            'pause_reason' => null,
            'pause_notes' => null,
            'paused_at' => null,
            'can_pause' => false,
            'can_resume' => false,
        ];
    }

    /**
     * Checks if the 15-day mediation period has lapsed and logs an automated audit and case history entry once.
     */
    public function checkAndLogLapse(int $caseId, string $deadlineDate, ?int $actorUserId = null): void
    {
        try {
            $check = $this->conn->prepare(
                "SELECT 1 FROM case_history WHERE case_id = ? AND remarks LIKE '%15-day mediation period concluded%' LIMIT 1"
            );
            $check->execute([$caseId]);
            if ($check->fetchColumn()) {
                return;
            }

            $dateFormatted = date('F j, Y', strtotime($deadlineDate));
            $remarks = "15-day mediation period concluded on {$dateFormatted}. Awaiting elevation to Pangkat or issuance of CFA.";

            $insertHistory = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Mediation', ?, ?)"
            );
            $insertHistory->execute([$caseId, $remarks, $actorUserId]);

            // Mark deadline in case_deadlines as Overdue if still Pending
            $updateDeadline = $this->conn->prepare(
                "UPDATE case_deadlines SET status = 'Overdue' WHERE case_id = ? AND deadline_type = 'Mediation Period' AND status = 'Pending'"
            );
            $updateDeadline->execute([$caseId]);

            // Log to system audit trail
            $this->audit->log(
                $actorUserId ?? 1,
                $remarks,
                'Mediation',
                $caseId
            );
        } catch (Throwable $e) {
            error_log("Failed to log mediation lapse: " . $e->getMessage());
        }
    }

    /**
     * Initializes the SCHEDULING record when the 1st Mediation hearing is entered into the calendar.
     *
     * IMPORTANT — STATUTORY TIMER NOT STARTED HERE
     * RA 7160 §410 measures the 15-day mediation period from the date the parties FIRST ACTUALLY
     * MEET before the Lupon Chairman — not from the date the hearing is scheduled.
     * The statutory clock is started by MediationCommencementService::recordFromAttendance()
     * when attendance is recorded and both parties are confirmed present.
     *
     * This method stores the anticipated start date as a scheduling placeholder only.
     * mediation_deadline_date is left NULL until commencement is confirmed.
     *
     * @param int    $caseId      Case ID
     * @param string $hearingDate ISO datetime of the first scheduled hearing
     * @param int|null $actorUserId Actor performing the action
     */
    public function initializeClock(int $caseId, string $hearingDate, ?int $actorUserId = null): array
    {
        $scheduledDate = substr($hearingDate, 0, 10);

        // Store only the scheduled date; deadline will be set by MediationCommencementService
        // once actual attendance confirms both parties appeared.
        $stmt = $this->conn->prepare(
            "UPDATE cases
             SET mediation_start_date = ?,
                 mediation_deadline_date = NULL,
                 is_paused = 0,
                 paused_at = NULL,
                 resumed_at = NULL,
                 pause_reason = NULL,
                 pause_notes = NULL
             WHERE case_id = ?"
        );
        $stmt->execute([$scheduledDate, $caseId]);

        // Insert a Pending placeholder — due_date will be updated by MediationCommencementService.
        $tentativeDueDate = date('Y-m-d', strtotime($scheduledDate . ' + 15 days'));
        $deadlineStmt = $this->conn->prepare(
            "INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
             VALUES (?, 'Mediation Period', ?, 'Pending')
             ON DUPLICATE KEY UPDATE
                due_date = IF(status = 'Pending', VALUES(due_date), due_date),
                updated_at = CURRENT_TIMESTAMP"
        );
        $deadlineStmt->execute([$caseId, $tentativeDueDate]);

        $this->audit->log(
            $actorUserId ?? 0,
            "Mediation scheduled for {$scheduledDate}. Statutory 15-day clock will start upon first actual meeting (RA 7160 §410).",
            'Mediation',
            $caseId
        );

        return [
            'success'               => true,
            'scheduled_date'        => $scheduledDate,
            'mediation_start_date'  => $scheduledDate,
            'mediation_deadline_date' => null,
            'notice'                => 'The 15-day statutory period (RA 7160 §410) starts from the first actual meeting, not the scheduling date. Record attendance to commence the clock.',
        ];
    }

    /**
     * Called by MediationCommencementService after confirming both parties appeared.
     * Sets the true statutory start date and computes the 15-calendar-day deadline.
     *
     * @param int    $caseId         Case ID
     * @param string $meetingDate    ISO date of the first actual meeting (Y-m-d)
     * @param int|null $actorUserId  Actor
     * @param bool   $useBusinessDays  Pass true ONLY when a local rule authorises business-day counting
     */
    public function commenceStatutoryClock(
        int $caseId,
        string $meetingDate,
        ?int $actorUserId = null,
        bool $useBusinessDays = false
    ): array {
        $startDate    = substr($meetingDate, 0, 10);
        $deadlineDate = self::calculateDeadline($startDate, 15, $useBusinessDays);
        $countingMode = $useBusinessDays ? 'business days (local rule applied)' : 'calendar days (RA 7160 §410 default)';

        $stmt = $this->conn->prepare(
            "UPDATE cases
             SET mediation_start_date    = ?,
                 mediation_deadline_date = ?,
                 is_paused = 0,
                 paused_at = NULL,
                 resumed_at = NULL,
                 pause_reason = NULL,
                 pause_notes = NULL
             WHERE case_id = ?"
        );
        $stmt->execute([$startDate, $deadlineDate, $caseId]);

        $deadlineStmt = $this->conn->prepare(
            "INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
             VALUES (?, 'Mediation Period', ?, 'Pending')
             ON DUPLICATE KEY UPDATE
                due_date   = VALUES(due_date),
                status     = IF(status = 'Completed', 'Completed', 'Pending'),
                updated_at = CURRENT_TIMESTAMP"
        );
        $deadlineStmt->execute([$caseId, $deadlineDate]);

        $this->audit->log(
            $actorUserId ?? 0,
            "Statutory 15-day mediation clock commenced from first actual meeting {$startDate}. Deadline: {$deadlineDate} ({$countingMode}).",
            'Mediation',
            $caseId
        );

        return [
            'success'                 => true,
            'mediation_start_date'   => $startDate,
            'mediation_deadline_date' => $deadlineDate,
            'counting_mode'           => $countingMode,
        ];
    }

    /**
     * Administrative hold on the mediation clock.
     *
     * LEGAL CONTEXT
     * -------------
     * RA 7160 §410 does not explicitly authorise pausing the statutory 15-day period.
     * A hold is permissible as an ADMINISTRATIVE SCHEDULING measure (e.g., party illness,
     * force majeure, safety concern), but it does NOT automatically extend the statutory
     * period unless a formal extension is authorised by the Punong Barangay or Lupon and
     * recorded as such. The clock hold and any deadline extension are distinct acts.
     *
     * This method requires:
     *   $legalBasis  — one of the recognised categories below. Unknown basis is rejected.
     *   $reason      — free-text description of the specific circumstance.
     *   $notes       — additional supporting detail.
     *   $actorUserId — must be a logged-in authorised officer.
     *
     * Recognised legal_basis values:
     *   'Force Majeure'            — natural disaster, declared state of calamity
     *   'Party Medical Emergency'  — documented illness preventing attendance
     *   'Safety Concern'           — credible threat to a party or officer
     *   'Summon Unserved'          — delivery failure pending re-service
     *   'Awaiting Documentation'   — required legal documents not yet available
     *   'PB Directive'             — explicit written directive of the Punong Barangay
     *   'Administrative Hold'      — other administrative scheduling hold
     *
     * @param int    $caseId
     * @param string $reason      Specific circumstance description
     * @param string|null $notes  Supporting detail
     * @param int    $actorUserId
     * @param string $legalBasis  Category from the list above
     */
    public function pauseMediation(
        int $caseId,
        string $reason,
        ?string $notes,
        int $actorUserId,
        string $legalBasis = 'Administrative Hold'
    ): array {
        $allowedBases = [
            'Force Majeure',
            'Party Medical Emergency',
            'Safety Concern',
            'Summon Unserved',
            'Awaiting Documentation',
            'PB Directive',
            'Administrative Hold',
        ];

        if (!in_array($legalBasis, $allowedBases, true)) {
            return [
                'success' => false,
                'message' => 'Invalid legal basis. Permitted values: ' . implode(', ', $allowedBases) . '.',
            ];
        }

        $stmt = $this->conn->prepare("SELECT * FROM cases WHERE case_id = ? FOR UPDATE");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }
        if ($case['case_status'] !== 'Mediation') {
            return ['success' => false, 'message' => 'Only active Mediation cases can be placed on administrative hold.'];
        }
        if (!empty($case['is_paused'])) {
            return ['success' => false, 'message' => 'The mediation clock is already on administrative hold.'];
        }

        $cleanReason = trim($reason);
        $cleanNotes  = trim((string) $notes);

        if ($cleanReason === '') {
            return ['success' => false, 'message' => 'A specific justification is required to place the clock on hold.'];
        }

        $now = date('Y-m-d H:i:s');

        $update = $this->conn->prepare(
            "UPDATE cases
             SET is_paused    = 1,
                 paused_at    = ?,
                 pause_reason = ?,
                 pause_notes  = ?
             WHERE case_id = ?"
        );
        $update->execute([$now, $cleanReason, $cleanNotes ?: null, $caseId]);

        // Record a formal pause decision entry for accountability
        $this->recordPauseDecision($caseId, $legalBasis, $cleanReason, $cleanNotes, $actorUserId, $now);

        $historyRemark = "Administrative hold placed on mediation clock [{$legalBasis}]: {$cleanReason}"
            . ($cleanNotes !== '' ? ". Notes: {$cleanNotes}" : '')
            . '. NOTE: This is an administrative scheduling hold; any extension of the statutory period requires a separate authorised decision.';

        $history = $this->conn->prepare(
            "INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Mediation', ?, ?)"
        );
        $history->execute([$caseId, $historyRemark, $actorUserId]);

        $this->audit->log(
            $actorUserId,
            "Administrative hold on mediation clock [{$legalBasis}]: {$cleanReason}",
            'Mediation',
            $caseId
        );

        return [
            'success'      => true,
            'message'      => 'Administrative hold placed on mediation clock. Record resumption when the circumstance is resolved.',
            'paused_at'    => $now,
            'pause_reason' => $cleanReason,
            'legal_basis'  => $legalBasis,
            'warning'      => 'This hold does NOT automatically extend the statutory 15-day period. A separate authorised decision is required to extend the deadline.',
        ];
    }

    /**
     * Records a formal pause-decision entry for audit and accountability purposes.
     * Inserts into mediation_pause_decisions if the table exists; silently skips if not.
     */
    private function recordPauseDecision(
        int $caseId,
        string $legalBasis,
        string $reason,
        string $notes,
        int $actorUserId,
        string $pausedAt
    ): void {
        try {
            $ins = $this->conn->prepare(
                "INSERT INTO mediation_pause_decisions
                    (case_id, legal_basis, reason, notes, decided_by, paused_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([$caseId, $legalBasis, $reason, $notes ?: null, $actorUserId, $pausedAt]);
        } catch (Throwable) {
            // Table may not yet exist — fail silently to avoid blocking the hold action.
            // Create the table: case_id, legal_basis, reason, notes, decided_by, paused_at, resumed_at, extension_authorised, extension_days.
        }
    }

    /**
     * Resumes the mediation clock after an administrative hold.
     *
     * DEADLINE EXTENSION POLICY
     * -------------------------
     * Resuming the hold does NOT automatically extend the statutory 15-day period.
     * RA 7160 §410 does not provide for automatic extension. The deadline is only
     * modified if $authoriseExtension = true, which requires an explicit decision by
     * the authorised officer (Punong Barangay or Lupon Secretary). The decision is
     * recorded in the audit trail and in mediation_pause_decisions.
     *
     * @param int  $caseId
     * @param int  $actorUserId
     * @param bool $authoriseExtension  Set true ONLY when an authorised extension has been decided.
     * @param string|null $extensionBasis  Free-text legal/administrative basis for the extension.
     */
    public function resumeMediation(
        int $caseId,
        int $actorUserId,
        bool $authoriseExtension = false,
        ?string $extensionBasis = null
    ): array {
        $stmt = $this->conn->prepare("SELECT * FROM cases WHERE case_id = ? FOR UPDATE");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }
        if ($case['case_status'] !== 'Mediation') {
            return ['success' => false, 'message' => 'Only active Mediation cases can be resumed.'];
        }
        if (empty($case['is_paused'])) {
            return ['success' => false, 'message' => 'Mediation clock is not currently on hold.'];
        }
        if ($authoriseExtension && empty(trim((string) $extensionBasis))) {
            return ['success' => false, 'message' => 'A documented legal or administrative basis is required to authorise a deadline extension.'];
        }

        $pausedAtStr = $case['paused_at'] ?: $case['updated_at'];
        $pausedAt    = new DateTimeImmutable($pausedAtStr);
        $now         = new DateTimeImmutable('now');
        $pauseDay    = new DateTimeImmutable($pausedAt->format('Y-m-d'));
        $today       = new DateTimeImmutable($now->format('Y-m-d'));
        $daysPaused  = (int) $pauseDay->diff($today)->days;
        $nowStr      = $now->format('Y-m-d H:i:s');

        // Existing statutory deadline (may be NULL if commencement not yet recorded)
        $oldDeadlineStr = $case['mediation_deadline_date'] ?? null;

        if ($authoriseExtension && $oldDeadlineStr) {
            // Extend by the calendar days the clock was on hold, as authorised.
            $newDeadline    = (new DateTimeImmutable($oldDeadlineStr))->modify("+{$daysPaused} days");
            $newDeadlineStr = $newDeadline->format('Y-m-d');
        } else {
            // No extension: keep existing deadline or NULL.
            $newDeadlineStr = $oldDeadlineStr;
        }

        $update = $this->conn->prepare(
            "UPDATE cases
             SET is_paused               = 0,
                 resumed_at              = ?,
                 mediation_deadline_date = ?,
                 pause_reason            = NULL,
                 pause_notes             = NULL
             WHERE case_id = ?"
        );
        $update->execute([$nowStr, $newDeadlineStr, $caseId]);

        if ($newDeadlineStr) {
            $this->conn->prepare(
                "UPDATE case_deadlines
                 SET due_date = ?,
                     status   = IF(? < CURDATE(), 'Overdue', 'Pending'),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE case_id = ? AND deadline_type = 'Mediation Period'"
            )->execute([$newDeadlineStr, $newDeadlineStr, $caseId]);
        }

        // Update pause decision record
        try {
            $this->conn->prepare(
                "UPDATE mediation_pause_decisions
                 SET resumed_at              = ?,
                     extension_authorised    = ?,
                     extension_days          = ?,
                     extension_basis         = ?
                 WHERE case_id = ? AND resumed_at IS NULL
                 ORDER BY paused_at DESC LIMIT 1"
            )->execute([$nowStr, $authoriseExtension ? 1 : 0, $daysPaused, $extensionBasis, $caseId]);
        } catch (Throwable) {
            // Table may not exist yet — non-fatal
        }

        $extensionNote = $authoriseExtension && $newDeadlineStr
            ? " Deadline extended by {$daysPaused} day(s) to " . date('F j, Y', strtotime($newDeadlineStr)) . " (basis: {$extensionBasis})."
            : ' Statutory deadline was NOT extended (no authorised extension).'
            . ($oldDeadlineStr ? ' Existing deadline ' . date('F j, Y', strtotime($oldDeadlineStr)) . ' remains in force.' : ' No statutory deadline recorded yet.');

        $historyRemark = "Administrative hold lifted. Clock resumed.{$extensionNote}";
        $this->conn->prepare(
            "INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Mediation', ?, ?)"
        )->execute([$caseId, $historyRemark, $actorUserId]);

        $this->audit->log(
            $actorUserId,
            "Mediation clock hold lifted.{$extensionNote}",
            'Mediation',
            $caseId
        );

        return [
            'success'              => true,
            'message'             => "Mediation clock hold lifted.{$extensionNote}",
            'days_paused'         => $daysPaused,
            'extension_authorised' => $authoriseExtension,
            'new_deadline'        => $newDeadlineStr,
        ];
    }

    // =========================================================================
    // SESSION CEILING / REFERRAL EVALUATION  (Issue 4)
    // =========================================================================

    /**
     * Evaluates whether mediation should be referred onward (to Pangkat or CFA).
     *
     * LEGAL CONTEXT
     * -------------
     * RA 7160 §410 does NOT impose a fixed session count. The statute establishes a
     * 15-day period, not a three-session ceiling. Three sessions is an OPERATIONAL
     * POLICY that may serve as a practical guide, but it MUST NOT be treated as the
     * sole or exclusive test for referral. Additional triggers include:
     *
     *   - Parties expressly declare mediation has failed
     *   - Respondent persistently fails to appear (→ CFA track)
     *   - Justified interruption (illness, safety, force majeure) exhausting remaining time
     *   - Statutory period has lapsed regardless of session count
     *   - Case falls under a direct-court exception (Sec. 412)
     *
     * This method returns an advisory with all applicable triggers so the Punong
     * Barangay can make an informed referral decision.
     *
     * @param int   $caseId
     * @param array $caseRow  Associative array from the cases table
     * @return array {
     *   should_refer: bool,
     *   triggers: string[],    — list of reasons recommending referral
     *   advisories: string[],  — informational notes
     *   session_count: int,
     *   days_remaining: int|null,
     *   is_lapsed: bool
     * }
     */
    public function evaluateReferral(int $caseId, array $caseRow): array
    {
        $triggers   = [];
        $advisories = [];

        // 1. Session count (operational guide only)
        $sessStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM hearings
             WHERE case_id = ? AND hearing_type = 'Mediation'
               AND status NOT IN ('Cancelled', 'Office Cancelled')"
        );
        $sessStmt->execute([$caseId]);
        $sessionCount = (int) $sessStmt->fetchColumn();

        if ($sessionCount >= 2) {
            $advisories[] = "Two mediation sessions have been held (Barangay Tumana policy limit reached). "
                . 'Review whether mediation has genuinely failed before referring to Pangkat — the session count is a guide, not the sole statutory test.';
        }

        // 2. Statutory period lapse
        $status = $this->computeStatus($caseRow);
        $daysRemaining = $status['days_remaining'];
        $isLapsed      = $status['is_lapsed'] ?? false;

        if ($isLapsed) {
            $triggers[] = 'Statutory 15-day mediation period has lapsed (RA 7160 §410). Referral to Pangkat or CFA issuance is required.';
        } elseif ($daysRemaining !== null && $daysRemaining <= 3) {
            $advisories[] = "Only {$daysRemaining} day(s) remain in the statutory period. Consider expediting resolution or preparing referral documents.";
        }

        // 3. Parties declared failure
        $failStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM case_history
             WHERE case_id = ? AND remarks LIKE '%mediation failed%'"
        );
        $failStmt->execute([$caseId]);
        if ((int) $failStmt->fetchColumn() > 0) {
            $triggers[] = 'A party or the Lupon Chairman has declared mediation failed. Referral is appropriate.';
        }

        // 4. Respondent persistent non-appearance
        $noShowStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM hearing_attendance
             WHERE case_id = ? AND party_type = 'Respondent'
               AND attendance_status IN ('Absent', 'No Show', 'Unexcused Absence')"
        );
        $noShowStmt->execute([$caseId]);
        $respondentNoShows = (int) $noShowStmt->fetchColumn();
        if ($respondentNoShows >= 2) {
            $triggers[] = "Respondent has {$respondentNoShows} unexcused absence(s). CFA may be issued on refusal-to-appear grounds.";
        }

        $shouldRefer = !empty($triggers);

        return [
            'should_refer'  => $shouldRefer,
            'triggers'      => $triggers,
            'advisories'    => $advisories,
            'session_count' => $sessionCount,
            'days_remaining' => $daysRemaining,
            'is_lapsed'     => $isLapsed,
        ];
    }

    // =========================================================================
    // KP JURISDICTION EXCLUSION CHECK  (Issue 5 — RA 7160 §§408, 409, 412)
    // =========================================================================

    /**
     * Evaluates whether a dispute is excluded from Katarungang Pambarangay jurisdiction.
     *
     * RA 7160 §408 — Scope:
     *   All disputes between actual residents of the same city/municipality may be
     *   brought under KP, SUBJECT to the exclusions in §408 and §412.
     *
     * RA 7160 §408 — Excluded matters (system must route these out):
     *   (a) Where one party is the government, or any subdivision/instrumentality thereof.
     *   (b) Where one party is a public officer or employee acting in an official capacity
     *       or in the performance of official duties.
     *   (c) Offenses punishable by imprisonment exceeding one (1) year or a fine over
     *       Five thousand pesos (₱5,000).
     *   (d) Offenses where there is no private offended party.
     *   (e) Where real property or any interest therein located in different cities/
     *       municipalities is involved.
     *   (f) Disputes involving parties who reside in barangays of different cities/
     *       municipalities, EXCEPT where both are actual residents of the same barangay.
     *   (g) Such other classes of disputes as may be determined by the Minister of Justice
     *       (now Secretary).
     *
     * RA 7160 §412 — When parties may go directly to court (KP not required):
     *   (a) Where the accused is under detention.
     *   (b) Where a person has otherwise been deprived of personal liberty calling for
     *       habeas corpus proceedings.
     *   (c) Where actions are coupled with provisional remedies (injunction, attachment,
     *       replevin, support pendente lite).
     *   (d) Where the action may otherwise be barred by the statute of limitations.
     *
     * ADDITIONAL DILG GUIDANCE:
     *   Indigenous Cultural Communities whose customs and traditions provide their own
     *   dispute resolution mechanism are also excluded.
     *
     * @param array $disputeContext  Associative array describing the dispute. Keys:
     *   - party_is_government       (bool)  A party is a government entity/instrumentality
     *   - party_is_public_officer   (bool)  A party is a public officer in official capacity
     *   - offense_imprisonment_max  (float) Maximum imprisonment in years for the offense
     *   - offense_fine_max          (float) Maximum fine in PHP
     *   - has_private_offended_party (bool) Offense has a private offended party
     *   - property_in_diff_lgu      (bool)  Real property in different city/municipality
     *   - parties_in_diff_lgu       (bool)  Parties reside in different cities/municipalities
     *   - parties_same_barangay     (bool)  Both parties are residents of the same barangay
     *   - accused_under_detention   (bool)  Accused is currently detained
     *   - habeas_corpus_needed      (bool)  Personal liberty is at stake
     *   - provisional_remedy_needed (bool)  Action requires injunction/attachment/replevin etc.
     *   - prescription_risk         (bool)  Delay via KP would bar the action by prescription
     *   - indigenous_community      (bool)  Parties belong to an ICC with customary law
     *
     * @return array {
     *   excluded: bool,
     *   exclusion_grounds: string[],  — statutory citations and descriptions
     *   requires_review: bool,        — flagged for officer review even if not definitively excluded
     *   review_notes: string[]
     * }
     */
    public static function evaluateKpJurisdiction(array $disputeContext): array
    {
        $grounds = [];
        $reviewNotes = [];

        // §408(a) — Government party
        if (!empty($disputeContext['party_is_government'])) {
            $grounds[] = 'RA 7160 §408(a): A party is a government entity or instrumentality. KP jurisdiction does not apply.';
        }

        // §408(b) — Public officer in official capacity
        if (!empty($disputeContext['party_is_public_officer'])) {
            $grounds[] = 'RA 7160 §408(b): A party is a public officer acting in official capacity or in the performance of official duties. KP jurisdiction does not apply.';
        }

        // §408(c) — Offense beyond threshold
        $imprisonmentMax = (float) ($disputeContext['offense_imprisonment_max'] ?? 0);
        $fineMax         = (float) ($disputeContext['offense_fine_max'] ?? 0);
        if ($imprisonmentMax > 1.0 || $fineMax > 5000.0) {
            $grounds[] = sprintf(
                'RA 7160 §408(c): Offense carries imprisonment exceeding 1 year (%.1f yr) or fine exceeding ₱5,000 (₱%.2f). KP jurisdiction does not apply.',
                $imprisonmentMax,
                $fineMax
            );
        }

        // §408(d) — No private offended party
        if (array_key_exists('has_private_offended_party', $disputeContext)
            && $disputeContext['has_private_offended_party'] === false) {
            $grounds[] = 'RA 7160 §408(d): The offense has no private offended party. KP jurisdiction does not apply.';
        }

        // §408(e) — Property in different LGUs
        if (!empty($disputeContext['property_in_diff_lgu'])) {
            $grounds[] = 'RA 7160 §408(e): Real property at issue is located in different cities/municipalities. KP jurisdiction does not apply.';
        }

        // §408(f) — Parties in different LGUs (unless same barangay)
        if (!empty($disputeContext['parties_in_diff_lgu'])
            && empty($disputeContext['parties_same_barangay'])) {
            $grounds[] = 'RA 7160 §408(f): Parties reside in different cities/municipalities and are not both residents of the same barangay. KP jurisdiction does not apply.';
        }

        // §412(a) — Accused under detention
        if (!empty($disputeContext['accused_under_detention'])) {
            $grounds[] = 'RA 7160 §412(a): Accused is under detention. Parties may proceed directly to court.';
        }

        // §412(b) — Habeas corpus
        if (!empty($disputeContext['habeas_corpus_needed'])) {
            $grounds[] = 'RA 7160 §412(b): Personal liberty deprivation requiring habeas corpus proceedings. Parties may proceed directly to court.';
        }

        // §412(c) — Provisional remedies
        if (!empty($disputeContext['provisional_remedy_needed'])) {
            $grounds[] = 'RA 7160 §412(c): Action is coupled with a provisional remedy (injunction, attachment, replevin, support pendente lite). Parties may proceed directly to court.';
        }

        // §412(d) — Prescription risk
        if (!empty($disputeContext['prescription_risk'])) {
            $grounds[] = 'RA 7160 §412(d): KP process would bar the action by statute of limitations. Parties may proceed directly to court.';
        }

        // DILG guidance — Indigenous community with customary law
        if (!empty($disputeContext['indigenous_community'])) {
            $reviewNotes[] = 'DILG guidance: Parties may belong to an Indigenous Cultural Community with applicable customary dispute resolution. Verify whether ICC mechanisms apply before proceeding with KP.';
        }

        return [
            'excluded'          => !empty($grounds),
            'exclusion_grounds' => $grounds,
            'requires_review'   => !empty($reviewNotes),
            'review_notes'      => $reviewNotes,
        ];
    }
}
