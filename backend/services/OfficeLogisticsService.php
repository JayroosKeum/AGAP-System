<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/MediationDeadlineService.php';

class OfficeLogisticsService
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    /**
     * Retrieves a configurable system setting with fallback.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        try {
            $stmt = $this->conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Validates clerical/administrative actions within official Lupon office hours.
     * Official hours: Monday–Friday, 8:00 AM – 5:00 PM.
     */
    public static function validateOfficeHours(DateTimeInterface|string $dateTime, bool $allowTanodSkeletal = false): array
    {
        $dt = is_string($dateTime) ? new DateTimeImmutable($dateTime) : DateTimeImmutable::createFromInterface($dateTime);
        $dayOfWeek = (int) $dt->format('N'); // 1 = Monday, 7 = Sunday
        $timeStr = $dt->format('H:i');

        // Weekend validation
        if ($dayOfWeek === 7) { // Sunday
            return [
                'valid' => false,
                'message' => 'Office operations are closed on Sundays. Official Lupon clerical hours are Monday to Friday, 8:00 AM to 5:00 PM.'
            ];
        }

        if ($dayOfWeek === 6) { // Saturday
            if ($allowTanodSkeletal) {
                if ($timeStr < '08:00' || $timeStr > '17:00') {
                    return [
                        'valid' => false,
                        'message' => 'Skeletal desk/tanod operations on Saturdays are only permitted between 8:00 AM and 5:00 PM.'
                    ];
                }
                return ['valid' => true, 'is_skeletal' => true];
            }
            return [
                'valid' => false,
                'message' => 'Normal Lupon clerical and administrative processing is only permitted Monday to Friday, 8:00 AM to 5:00 PM (Saturdays are reserved for skeletal desk/tanod operations only).'
            ];
        }

        // Philippine holiday check
        if (MediationDeadlineService::isNonWorkingDay($dt)) {
            return [
                'valid' => false,
                'message' => 'Official Lupon office operations are closed on legal holidays.'
            ];
        }

        // Monday 8:00 AM - 9:00 AM administrative block (Section A.8)
        if ($dayOfWeek === 1 && $timeStr >= '08:00' && $timeStr < '09:00') {
            return [
                'valid' => false,
                'message' => 'Monday 8:00 AM–9:00 AM is reserved for weekly barangay flag ceremony and general administrative briefing. No clerical appointments or case encoding may be scheduled during this block.'
            ];
        }

        // Office hours: 08:00 to 17:00
        if ($timeStr < '08:00' || $timeStr > '17:00') {
            return [
                'valid' => false,
                'message' => 'Lupon administrative operations and case encoding must take place within official office hours (8:00 AM to 5:00 PM).'
            ];
        }

        return ['valid' => true, 'is_skeletal' => false];
    }

    /**
     * Checks if a date falls on an official Philippine legal holiday.
     */
    public function isPhilippineHoliday(DateTimeInterface|string $date): ?string
    {
        try {
            $dt = is_string($date) ? new DateTimeImmutable($date) : DateTimeImmutable::createFromInterface($date);
        } catch (Throwable) {
            return null;
        }

        return MediationDeadlineService::isNonWorkingDay($dt) ? 'Legal Holiday' : null;
    }

    /**
     * Validates hearing schedule: approved morning/afternoon blocks, lunch block, Monday 8-9am block,
     * Philippine legal holidays, duration rules, and exceedance justifications.
     *
     * Approved blocks:
     * - Morning: 9:00 AM – 11:30 AM
     * - Afternoon: 1:30 PM – 4:00 PM
     *
     * Rejections:
     * - 8:00 AM – 8:59 AM (and Monday 8:00-9:00 AM administrative block)
     * - 11:31 AM – 1:29 PM (including 12:00-1:00 PM lunch block)
     * - After 4:00 PM
     */
    public function validateHearingSchedule(
        DateTimeInterface|string $startDateTime,
        DateTimeInterface|string|int|null $endOrDuration = null,
        string $hearingType = 'Mediation',
        ?string $durationExceedReason = null
    ): array {
        try {
            $start = is_string($startDateTime) ? new DateTimeImmutable($startDateTime) : DateTimeImmutable::createFromInterface($startDateTime);
        } catch (Throwable) {
            return ['valid' => false, 'message' => 'Invalid hearing start date/time format.'];
        }

        $now = new DateTimeImmutable();
        if ($start <= $now) {
            return ['valid' => false, 'message' => 'Hearing schedule must be in the future.'];
        }

        // 1. Weekday validation
        $dayOfWeek = (int) $start->format('N');
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            return [
                'valid' => false,
                'message' => 'Hearings cannot be scheduled on weekends (Saturday or Sunday). Please select a weekday (Monday to Friday).'
            ];
        }

        // 2. Legal Philippine Holiday validation
        if (MediationDeadlineService::isNonWorkingDay($start)) {
            return [
                'valid' => false,
                'message' => 'Hearings cannot be scheduled on an official Philippine legal holiday. Please select an official working day.'
            ];
        }

        // 3. Compute duration & end time
        $durationMin = 45;
        if (is_int($endOrDuration) || (is_numeric($endOrDuration) && (int) $endOrDuration > 0)) {
            $durationMin = (int) $endOrDuration;
            $end = $start->modify("+{$durationMin} minutes");
        } elseif ($endOrDuration instanceof DateTimeInterface || (is_string($endOrDuration) && trim($endOrDuration) !== '' && !is_numeric($endOrDuration))) {
            try {
                $end = is_string($endOrDuration) ? new DateTimeImmutable($endOrDuration) : DateTimeImmutable::createFromInterface($endOrDuration);
                $durationMin = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
            } catch (Throwable) {
                $durationMin = ($hearingType === 'Conciliation') ? 60 : 45;
                $end = $start->modify("+{$durationMin} minutes");
            }
        } else {
            // Default durations
            $durationMin = ($hearingType === 'Conciliation')
                ? (int) $this->getSetting('conciliation_default_duration_min', 60)
                : (int) $this->getSetting('mediation_default_duration_min', 45);
            $end = $start->modify("+{$durationMin} minutes");
        }

        if ($durationMin <= 0) {
            return ['valid' => false, 'message' => 'Hearing end time must be after the start time.'];
        }

        // 4. Duration limits and justification check
        $maxStandard = ($hearingType === 'Conciliation')
            ? (int) $this->getSetting('conciliation_max_duration_min', 90)
            : (int) $this->getSetting('mediation_max_duration_min', 60);

        if ($durationMin > $maxStandard) {
            $reason = trim((string) $durationExceedReason);
            if ($reason === '') {
                return [
                    'valid' => false,
                    'message' => sprintf(
                        'Hearing duration (%d minutes) exceeds the standard maximum of %d minutes for %s. A valid reason must be provided in the duration justification notes.',
                        $durationMin,
                        $maxStandard,
                        $hearingType
                    ),
                    'requires_reason' => true
                ];
            }
        }

        $startTimeStr = $start->format('H:i');
        $endTimeStr = $end->format('H:i');

        // 5. Monday 8:00 AM - 9:00 AM administrative block
        if ($dayOfWeek === 1 && $startTimeStr < '09:00') {
            return [
                'valid' => false,
                'message' => 'Monday 8:00 AM–9:00 AM is reserved for official administrative duties and assembly. Hearings start at 9:00 AM.'
            ];
        }

        // 6. Approved Hearing Blocks
        // Morning: 09:00 to 11:30
        // Afternoon: 13:30 to 16:00 (1:30 PM to 4:00 PM)
        $isMorningBlock = ($startTimeStr >= '09:00' && $startTimeStr <= '11:30' && $endTimeStr <= '12:00');
        $isAfternoonBlock = ($startTimeStr >= '13:30' && $startTimeStr <= '16:00' && $endTimeStr <= '17:00');

        if (!$isMorningBlock && !$isAfternoonBlock) {
            // Detailed rejection feedback
            if ($startTimeStr < '09:00') {
                return [
                    'valid' => false,
                    'message' => 'Hearings cannot be scheduled between 8:00 AM and 8:59 AM. Morning hearings begin at 9:00 AM.'
                ];
            }
            if ($startTimeStr > '11:30' && $startTimeStr < '13:30') {
                return [
                    'valid' => false,
                    'message' => 'Hearings cannot be scheduled between 11:31 AM and 1:29 PM (including lunch break 12:00 PM–1:00 PM). Afternoon hearings begin at 1:30 PM.'
                ];
            }
            if ($startTimeStr > '16:00') {
                return [
                    'valid' => false,
                    'message' => 'Hearings cannot be scheduled after 4:00 PM. Please select an approved hearing block (9:00 AM–11:30 AM or 1:30 PM–4:00 PM).'
                ];
            }
            if ($startTimeStr <= '11:30' && $endTimeStr > '12:00') {
                return [
                    'valid' => false,
                    'message' => 'Hearing duration extends past 12:00 PM into the lunch block. Morning hearings must conclude by 12:00 PM.'
                ];
            }
            if ($startTimeStr >= '13:30' && $endTimeStr > '17:00') {
                return [
                    'valid' => false,
                    'message' => 'Hearing duration extends past official office hours (5:00 PM). Afternoon hearings must conclude by 5:00 PM.'
                ];
            }
        }

        // 7. Lunch block hard check: 12:00 PM - 1:00 PM
        // Overlap occurs if start < 13:00 AND end > 12:00
        $lunchStart = $start->setTime(12, 0);
        $lunchEnd = $start->setTime(13, 0);
        if ($start < $lunchEnd && $end > $lunchStart) {
            return [
                'valid' => false,
                'message' => 'Hard block: Hearings cannot overlap the official lunch break (12:00 PM to 1:00 PM).'
            ];
        }

        return [
            'valid' => true,
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time' => $end->format('Y-m-d H:i:s'),
            'duration_minutes' => $durationMin,
            'message' => ''
        ];
    }

    /**
     * Checks daily overall and presiding officer capacity limits.
     * Overall: 10–18 hearings/day (configurable, default 14).
     * Presider: 5–8 hearings/day (configurable, default 6).
     */
    public function validateDailyCapacity(string $date, ?int $officerId = null, ?int $excludeHearingId = null): array
    {
        $targetDay = substr($date, 0, 10);
        $maxDaily = (int) $this->getSetting('daily_hearing_capacity', 14);
        $maxOfficerDaily = (int) $this->getSetting('officer_daily_hearing_capacity', 6);

        // Overall count for the day
        $sql = "SELECT COUNT(*) FROM hearings WHERE DATE(hearing_date) = ? AND status NOT IN ('Cancelled', 'Office Cancelled')";
        $params = [$targetDay];
        if ($excludeHearingId) {
            $sql .= ' AND hearing_id != ?';
            $params[] = $excludeHearingId;
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $totalToday = (int) $stmt->fetchColumn();

        if ($totalToday >= $maxDaily) {
            return [
                'valid' => false,
                'total_hearings' => $totalToday,
                'max_capacity' => $maxDaily,
                'message' => sprintf(
                    'Daily hearing capacity reached (%d/%d hearings scheduled on %s). Please select another date.',
                    $totalToday,
                    $maxDaily,
                    date('F j, Y', strtotime($targetDay))
                )
            ];
        }

        // Officer count if specified
        if ($officerId && $officerId > 0) {
            $sqlOff = "SELECT COUNT(*) FROM hearings WHERE DATE(hearing_date) = ? AND presiding_officer_id = ? AND status NOT IN ('Cancelled', 'Office Cancelled')";
            $paramsOff = [$targetDay, $officerId];
            if ($excludeHearingId) {
                $sqlOff .= ' AND hearing_id != ?';
                $paramsOff[] = $excludeHearingId;
            }
            $stmtOff = $this->conn->prepare($sqlOff);
            $stmtOff->execute($paramsOff);
            $officerToday = (int) $stmtOff->fetchColumn();

            if ($officerToday >= $maxOfficerDaily) {
                return [
                    'valid' => false,
                    'officer_hearings' => $officerToday,
                    'max_officer_capacity' => $maxOfficerDaily,
                    'message' => sprintf(
                        'The selected presiding officer has reached their maximum daily capacity (%d/%d hearings on %s). Please reassign or select another date.',
                        $officerToday,
                        $maxOfficerDaily,
                        date('F j, Y', strtotime($targetDay))
                    )
                ];
            }
        }

        return [
            'valid' => true,
            'total_hearings' => $totalToday,
            'max_capacity' => $maxDaily,
            'message' => ''
        ];
    }

    /**
     * Checks if a member/officer has a conflicting hearing at the given date/time range.
     */
    public function validateMemberAvailability(int $userId, string $startDateTime, string $endDateTime, ?int $excludeHearingId = null): array
    {
        $sql = "
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.end_time, c.case_number
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            LEFT JOIN case_assignments ca ON ca.case_id = h.case_id AND ca.member_id = ?
            WHERE (h.presiding_officer_id = ? OR ca.member_id = ?)
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
              AND (
                  (h.hearing_date < ? AND COALESCE(h.end_time, DATE_ADD(h.hearing_date, INTERVAL 60 MINUTE)) > ?)
              )
        ";
        $params = [$userId, $userId, $userId, $endDateTime, $startDateTime];
        if ($excludeHearingId) {
            $sql .= ' AND h.hearing_id != ?';
            $params[] = $excludeHearingId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($conflict) {
            $startFormatted = date('g:i A', strtotime($conflict['hearing_date']));
            $endFormatted = !empty($conflict['end_time'])
                ? date('g:i A', strtotime($conflict['end_time']))
                : date('g:i A', strtotime($conflict['hearing_date'] . ' +60 minutes'));

            return [
                'available' => false,
                'conflict' => $conflict,
                'message' => sprintf(
                    'Lupon member has a scheduling conflict with Case %s (%s, %s–%s).',
                    $conflict['case_number'],
                    $conflict['hearing_type'],
                    $startFormatted,
                    $endFormatted
                )
            ];
        }

        return ['available' => true, 'message' => ''];
    }

    /**
     * Validates recommended 3–5 calendar day interval between mediation sessions.
     */
    public function validateMediationInterval(int $caseId, string $targetDate, ?int $excludeHearingId = null): array
    {
        $targetDay = substr($targetDate, 0, 10);
        $sql = "
            SELECT hearing_id, hearing_date, DATE(hearing_date) AS hearing_day
            FROM hearings
            WHERE case_id = ? AND hearing_type = 'Mediation' AND status NOT IN ('Cancelled', 'Office Cancelled')
        ";
        $params = [$caseId];
        if ($excludeHearingId) {
            $sql .= ' AND hearing_id != ?';
            $params[] = $excludeHearingId;
        }
        $sql .= ' ORDER BY hearing_date DESC LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $lastMediation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lastMediation) {
            return ['valid' => true, 'days_interval' => null, 'message' => ''];
        }

        $lastDay = $lastMediation['hearing_day'];
        $diffDays = (int) (new DateTimeImmutable($targetDay))->diff(new DateTimeImmutable($lastDay))->days;

        $minRecommended = (int) $this->getSetting('mediation_session_interval_days', 3);

        if ($targetDay > $lastDay && $diffDays < $minRecommended) {
            return [
                'valid' => true, // Allowed, but with advisory recommendation
                'advisory' => true,
                'days_interval' => $diffDays,
                'message' => sprintf(
                    'Notice: Operational guidelines recommend a 3–5 calendar day interval between mediation sessions (selected date is %d days after previous session).',
                    $diffDays
                )
            ];
        }

        return ['valid' => true, 'advisory' => false, 'days_interval' => $diffDays, 'message' => ''];
    }

    /**
     * Returns default venue based on hearing type.
     */
    public static function getDefaultVenue(string $hearingType): string
    {
        return match ($hearingType) {
            'Mediation', '1st Mediation', '2nd Mediation', '3rd Mediation' => "Punong Barangay / Barangay Captain's Office",
            'Conciliation', '1st Conciliation', '2nd Conciliation', '3rd Conciliation' => 'Lupon Office',
            'Arbitration' => 'Arbitration Hearing Chamber',
            'Show Cause (Complainant)', 'Show Cause (Respondent)' => 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall',
            default => 'Barangay Tumana Hearing Room',
        };
    }

    /**
     * Validates summons delivery window (8:00 AM – 6:00 PM), attempt intervals (1–3 days), and max attempts (2–3).
     */
    public function validateSummonsServiceAttempt(
        string $serviceDateTime,
        int $attemptNumber = 1,
        ?string $previousAttemptDate = null
    ): array {
        $dt = new DateTimeImmutable($serviceDateTime);
        $timeStr = $dt->format('H:i');

        // Delivery window: 8:00 AM - 6:00 PM
        if ($timeStr < '08:00' || $timeStr > '18:00') {
            return [
                'valid' => false,
                'message' => 'Summons delivery must be conducted within the official delivery window (8:00 AM to 6:00 PM).'
            ];
        }

        $maxAttempts = (int) $this->getSetting('summons_max_attempts', 3);
        if ($attemptNumber > $maxAttempts) {
            return [
                'valid' => false,
                'message' => sprintf('Maximum service attempts (%d) reached. Case should be reviewed for next statutory action.', $maxAttempts)
            ];
        }

        if ($attemptNumber > 1 && $previousAttemptDate) {
            $prev = new DateTimeImmutable($previousAttemptDate);
            $diffDays = (int) $dt->diff($prev)->days;
            $minInterval = (int) $this->getSetting('summons_attempt_interval_days', 1);

            if ($diffDays < $minInterval) {
                return [
                    'valid' => false,
                    'message' => sprintf('Operational rules require at least %d day(s) between service attempts (last attempt was %s).', $minInterval, $prev->format('M j, Y'))
                ];
            }
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * Checks party reschedule limit (1–2 reschedules).
     */
    public function checkPartyRescheduleLimit(int $caseId, string $partyType): array
    {
        $limit = (int) $this->getSetting('party_reschedule_limit', 2);

        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM hearings
            WHERE case_id = ?
              AND rescheduled_from_id IS NOT NULL
              AND (rescheduled_by_party = ? OR rescheduled_by_party = 'Both')
        ");
        $stmt->execute([$caseId, $partyType]);
        $count = (int) $stmt->fetchColumn();

        $exceeded = ($count >= $limit);

        return [
            'count' => $count,
            'limit' => $limit,
            'exceeded' => $exceeded,
            'message' => $exceeded
                ? sprintf(
                    '%s has reached/exceeded the reschedule limit (%d/%d reschedules used). Review attendance history and authorized personnel must determine legal consequence.',
                    $partyType,
                    $count,
                    $limit
                )
                : sprintf('%s has used %d of %d allowed reschedules.', $partyType, $count, $limit)
        ];
    }
}
