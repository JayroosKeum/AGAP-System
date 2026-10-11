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
     *
     * @param string|DateTimeInterface $startDate
     * @param int $daysToAdd
     * @param array|null $holidayList Optional list of custom holiday MM-DD strings
     * @return string Y-m-d format
     */
    public static function calculateBusinessDays(string|DateTimeInterface $startDate, int $daysToAdd = 15, ?array $holidayList = null): string
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
     * Calculates the statutory 15-day mediation deadline.
     * Duration: 15 working days from start date (Day 0), excluding weekends and holidays.
     */
    public static function calculateDeadline(string $startDate, int $calendarDays = 15): string
    {
        return self::calculateBusinessDays($startDate, $calendarDays);
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
     * Initializes the statutory mediation clock when the 1st Mediation hearing is scheduled.
     */
    public function initializeClock(int $caseId, string $hearingDate, ?int $actorUserId = null): array
    {
        $startDate = substr($hearingDate, 0, 10);
        $deadlineDate = self::calculateDeadline($startDate, 15);

        $stmt = $this->conn->prepare(
            "UPDATE cases
             SET mediation_start_date = ?,
                 mediation_deadline_date = ?,
                 is_paused = 0,
                 paused_at = NULL,
                 resumed_at = NULL,
                 pause_reason = NULL,
                 pause_notes = NULL
             WHERE case_id = ?"
        );
        $stmt->execute([$startDate, $deadlineDate, $caseId]);

        // Upsert case_deadlines for Mediation Period
        $deadlineStmt = $this->conn->prepare(
            "INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
             VALUES (?, 'Mediation Period', ?, 'Pending')
             ON DUPLICATE KEY UPDATE
                due_date = VALUES(due_date),
                status = IF(status = 'Completed', 'Completed', 'Pending'),
                updated_at = CURRENT_TIMESTAMP"
        );
        $deadlineStmt->execute([$caseId, $deadlineDate]);

        return [
            'success' => true,
            'mediation_start_date' => $startDate,
            'mediation_deadline_date' => $deadlineDate,
        ];
    }

    /**
     * Manually pauses the mediation clock for a verified/excused reason (e.g., medical emergency, disaster).
     */
    public function pauseMediation(int $caseId, string $reason, ?string $notes, int $actorUserId): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM cases WHERE case_id = ? FOR UPDATE");
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        if ($case['case_status'] !== 'Mediation') {
            return ['success' => false, 'message' => 'Only active Mediation cases can be paused.'];
        }

        if (!empty($case['is_paused'])) {
            return ['success' => false, 'message' => 'Mediation clock is already paused for this case.'];
        }

        $now = date('Y-m-d H:i:s');
        $cleanReason = trim($reason);
        $cleanNotes = trim((string) $notes);

        if ($cleanReason === '') {
            return ['success' => false, 'message' => 'A valid justification reason is required to pause the mediation clock.'];
        }

        $update = $this->conn->prepare(
            "UPDATE cases
             SET is_paused = 1,
                 paused_at = ?,
                 pause_reason = ?,
                 pause_notes = ?
             WHERE case_id = ?"
        );
        $update->execute([$now, $cleanReason, $cleanNotes ?: null, $caseId]);

        $historyRemark = "Mediation clock paused: {$cleanReason}." . ($cleanNotes !== '' ? " Notes: {$cleanNotes}" : '');
        $history = $this->conn->prepare(
            "INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Mediation', ?, ?)"
        );
        $history->execute([$caseId, $historyRemark, $actorUserId]);

        $this->audit->log(
            $actorUserId,
            "Mediation clock paused: {$cleanReason}",
            'Mediation',
            $caseId
        );

        return [
            'success' => true,
            'message' => 'Mediation clock has been paused successfully.',
            'paused_at' => $now,
            'pause_reason' => $cleanReason,
        ];
    }

    /**
     * Resumes the mediation clock, calculates calendar days paused, and extends the statutory deadline.
     */
    public function resumeMediation(int $caseId, int $actorUserId): array
    {
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
            return ['success' => false, 'message' => 'Mediation clock is not currently paused.'];
        }

        $pausedAtStr = $case['paused_at'] ?: $case['updated_at'];
        $pausedAt = new DateTimeImmutable($pausedAtStr);
        $now = new DateTimeImmutable('now');

        // Calendar days elapsed between paused_at and now
        $pauseDay = new DateTimeImmutable($pausedAt->format('Y-m-d'));
        $today = new DateTimeImmutable($now->format('Y-m-d'));
        $daysPaused = (int) $pauseDay->diff($today)->days;

        $oldDeadlineStr = $case['mediation_deadline_date'] ?: self::calculateDeadline($case['mediation_start_date'] ?: date('Y-m-d'), 15);
        $oldDeadline = new DateTimeImmutable($oldDeadlineStr);

        // Extend deadline by the paused days
        $newDeadline = $oldDeadline->modify("+{$daysPaused} days");
        while (self::isNonWorkingDay($newDeadline)) {
            $newDeadline = $newDeadline->modify('+1 day');
        }
        $newDeadlineStr = $newDeadline->format('Y-m-d');
        $nowStr = $now->format('Y-m-d H:i:s');

        $update = $this->conn->prepare(
            "UPDATE cases
             SET is_paused = 0,
                 resumed_at = ?,
                 mediation_deadline_date = ?,
                 pause_reason = NULL,
                 pause_notes = NULL
             WHERE case_id = ?"
        );
        $update->execute([$nowStr, $newDeadlineStr, $caseId]);

        // Update case_deadlines due_date
        $updateDeadlines = $this->conn->prepare(
            "UPDATE case_deadlines
             SET due_date = ?,
                 status = IF(due_date < CURDATE(), 'Overdue', 'Pending'),
                 updated_at = CURRENT_TIMESTAMP
             WHERE case_id = ? AND deadline_type = 'Mediation Period'"
        );
        $updateDeadlines->execute([$newDeadlineStr, $caseId]);

        $formattedNewDeadline = date('F j, Y', strtotime($newDeadlineStr));
        $historyRemark = "Mediation clock resumed. Clock extended by {$daysPaused} calendar day(s). New statutory deadline: {$formattedNewDeadline}.";
        $history = $this->conn->prepare(
            "INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Mediation', ?, ?)"
        );
        $history->execute([$caseId, $historyRemark, $actorUserId]);

        $this->audit->log(
            $actorUserId,
            "Mediation clock resumed. Deadline extended to {$newDeadlineStr}",
            'Mediation',
            $caseId
        );

        return [
            'success' => true,
            'message' => "Mediation clock resumed successfully. Deadline extended to {$formattedNewDeadline}.",
            'days_paused' => $daysPaused,
            'new_deadline' => $newDeadlineStr,
        ];
    }
}
