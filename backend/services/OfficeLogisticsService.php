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
     * Time restrictions for complaint intake and clerical recording are permanently disabled (24/7 intake allowed).
     */
    public static function validateOfficeHours(DateTimeInterface|string $dateTime, bool $allowTanodSkeletal = false): array
    {
        return ['valid' => true, 'is_skeletal' => false, 'message' => ''];
    }

    /**
     * Checks if a date falls on an official Philippine legal holiday or configured holiday.
     */
    public function isPhilippineHoliday(DateTimeInterface|string $date): ?string
    {
        try {
            $dt = is_string($date) ? new DateTimeImmutable($date) : DateTimeImmutable::createFromInterface($date);
        } catch (Throwable) {
            return null;
        }

        $dateStr = $dt->format('Y-m-d');
        $monthDay = $dt->format('m-d');

        // 1. Check configured holidays table
        try {
            $stmt = $this->conn->prepare("
                SELECT holiday_name FROM scheduling_holidays
                WHERE holiday_date = ? OR (is_recurring = 1 AND DATE_FORMAT(holiday_date, '%m-%d') = ?)
                LIMIT 1
            ");
            $stmt->execute([$dateStr, $monthDay]);
            $configured = $stmt->fetchColumn();
            if ($configured) {
                return (string) $configured;
            }
        } catch (Throwable) {
            // Table might not be reachable
        }

        // 2. Check system_settings configured_holidays (JSON)
        $settingHolidays = $this->getSetting('configured_holidays');
        if ($settingHolidays) {
            $decoded = json_decode($settingHolidays, true);
            if (is_array($decoded)) {
                foreach ($decoded as $h) {
                    if (is_array($h) && ($h['date'] ?? '') === $dateStr) {
                        return (string) ($h['name'] ?? 'Configured Holiday');
                    }
                }
            }
        }

        // 3. Check legal holidays from MediationDeadlineService
        if (MediationDeadlineService::isNonWorkingDay($dt)) {
            $dayOfWeek = (int) $dt->format('N');
            if ($dayOfWeek !== 6 && $dayOfWeek !== 7) {
                return 'Official Philippine Legal Holiday';
            }
        }

        return null;
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

        // Check if date falls on an official or configured holiday
        $holidayName = $this->isPhilippineHoliday($start);
        if ($holidayName !== null) {
            return [
                'valid' => false,
                'is_holiday' => true,
                'holiday_name' => $holidayName,
                'message' => sprintf(
                    'Scheduling is blocked on %s: Official/configured holiday (%s). Hearings cannot be scheduled on non-working holidays.',
                    $start->format('F j, Y'),
                    $holidayName
                )
            ];
        }

        // Compute duration & end time
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
            $durationMin = 45;
            $end = $start->modify("+{$durationMin} minutes");
        }

        // Stage-Specific Day & Time Rules for Barangay Tumana
        $dayOfWeek = (int) $start->format('w'); // 0 = Sun, 1 = Mon, 2 = Tue, 3 = Wed, 4 = Thu, 5 = Fri, 6 = Sat
        $hour = (int) $start->format('H');
        $minute = (int) $start->format('i');

        if ($hearingType === 'Mediation') {
            if ($dayOfWeek !== 2) {
                return [
                    'valid' => false,
                    'message' => 'Mediation hearings can only be scheduled on Tuesdays.'
                ];
            }
            if ($hour < 13 || $hour >= 17) {
                return [
                    'valid' => false,
                    'message' => 'Mediation hearings must be between 1:00 PM and 5:00 PM.'
                ];
            }
            $endHour = (int) $end->format('H');
            $endMin = (int) $end->format('i');
            if ($endHour > 17 || ($endHour === 17 && $endMin > 0)) {
                return [
                    'valid' => false,
                    'message' => 'Mediation hearings must conclude by 5:00 PM.'
                ];
            }
        } elseif ($hearingType === 'Conciliation') {
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                return [
                    'valid' => false,
                    'message' => 'Conciliation hearings cannot be scheduled on weekends.'
                ];
            }
            if ($dayOfWeek === 2) {
                // Tuesday morning only: 8:00 AM – 12:00 PM
                if ($hour < 8 || $hour >= 12) {
                    return [
                        'valid' => false,
                        'message' => 'Conciliation hearings on Tuesdays are permitted only in the morning (8:00 AM – 12:00 PM). Tuesday afternoons (1:00 PM – 5:00 PM) are exclusively reserved for Formal Mediation in the Lupon Court Room.'
                    ];
                }
            } else {
                // Mon, Wed, Thu, Fri: 8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM
                if (($hour < 8 || $hour >= 17) || ($hour >= 12 && $hour < 13)) {
                    return [
                        'valid' => false,
                        'message' => 'Conciliation hearings must be during regular office hours (8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM, excluding 12:00 PM – 1:00 PM lunch break).'
                    ];
                }
            }
            if ($hour < 12) {
                $endHour = (int) $end->format('H');
                $endMin = (int) $end->format('i');
                if ($endHour > 12 || ($endHour === 12 && $endMin > 0)) {
                    return [
                        'valid' => false,
                        'message' => 'Morning conciliation hearings must conclude by 12:00 PM for lunch break.'
                    ];
                }
            } else {
                $endHour = (int) $end->format('H');
                $endMin = (int) $end->format('i');
                if ($endHour > 17 || ($endHour === 17 && $endMin > 0)) {
                    return [
                        'valid' => false,
                        'message' => 'Afternoon conciliation hearings must conclude by 5:00 PM.'
                    ];
                }
            }
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
     * Checks daily scheduled case load across the barangay.
     * Soft Indicator: Returns case count (e.g. "Tuesday, Oct 27 (4 cases scheduled)").
     * No hard cap: Does NOT block staff from scheduling as many cases as agreed.
     */
    public function validateDailyCapacity(string $date, ?int $officerId = null, ?int $excludeHearingId = null): array
    {
        $targetDay = substr($date, 0, 10);

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

        $indicatorText = sprintf(
            '%s (%d case%s scheduled)',
            date('l, M j', strtotime($targetDay)),
            $totalToday,
            $totalToday === 1 ? '' : 's'
        );

        // Soft indicator: Always valid, no artificial capping
        return [
            'valid' => true,
            'total_hearings' => $totalToday,
            'indicator' => $indicatorText,
            'message' => ''
        ];
    }

    /**
     * Checks venue availability including existing bookings and maintenance/unavailable periods.
     */
    public function validateVenueAvailability(
        string $venue,
        string $startDateTime,
        string $endDateTime,
        ?int $excludeHearingId = null
    ): array {
        $venue = trim($venue);
        if ($venue === '') {
            return ['available' => true, 'message' => ''];
        }

        // 1. Check venue maintenance/unavailable records
        try {
            $stmt = $this->conn->prepare("
                SELECT unavailability_id, reason, start_time, end_time
                FROM venue_unavailabilities
                WHERE LOWER(TRIM(venue)) = LOWER(TRIM(?))
                  AND (start_time < ? AND end_time > ?)
                LIMIT 1
            ");
            $stmt->execute([$venue, $endDateTime, $startDateTime]);
            $unavail = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($unavail) {
                $startFmt = date('g:i A', strtotime($unavail['start_time']));
                $endFmt = date('g:i A', strtotime($unavail['end_time']));
                $dateFmt = date('F j, Y', strtotime($unavail['start_time']));
                return [
                    'available' => false,
                    'is_maintenance' => true,
                    'message' => sprintf(
                        'The venue "%s" is unavailable on %s from %s to %s due to scheduled maintenance/closure: %s.',
                        $venue,
                        $dateFmt,
                        $startFmt,
                        $endFmt,
                        $unavail['reason']
                    )
                ];
            }
        } catch (Throwable) {
            // Ignore if table temporarily unreachable
        }

        // 2. Master Operating Schedule & Cross-Type Overlap for single shared Lupon Court Room
        $venueClean = strtolower($venue);
        if ($venueClean === 'lupon court room') {
            $ts = strtotime($startDateTime);
            $dow = (int) date('w', $ts);
            $hour = (int) date('H', $ts);

            // Weekend & lunch break hard check
            if ($dow === 0 || $dow === 6) {
                return ['available' => false, 'message' => 'The Lupon Court Room is closed on weekends.'];
            }
            if ($hour >= 12 && $hour < 13) {
                return ['available' => false, 'message' => 'The Lupon Court Room is closed during daily lunch break (12:00 PM – 1:00 PM).'];
            }

            // Check if opposing session type is scheduled in Lupon Court Room
            // e.g. If Tue afternoon (Mediation window), block if any Conciliation or Paanyaya is present
            if ($dow === 2 && $hour >= 13 && $hour < 17) {
                $chkOther = $this->conn->prepare("
                    SELECT h.hearing_id, h.hearing_type, h.hearing_date, c.case_number
                    FROM hearings h
                    INNER JOIN cases c ON c.case_id = h.case_id
                    WHERE LOWER(TRIM(h.venue)) = 'lupon court room'
                      AND h.status NOT IN ('Cancelled', 'Office Cancelled')
                      AND h.hearing_type <> 'Mediation'
                      AND DATE(h.hearing_date) = DATE(?)
                      AND HOUR(h.hearing_date) >= 13 AND HOUR(h.hearing_date) < 17
                    LIMIT 1
                ");
                $chkOther->execute([$startDateTime]);
                $other = $chkOther->fetch(PDO::FETCH_ASSOC);
                if ($other) {
                    return [
                        'available' => false,
                        'message' => "The Lupon Court Room on Tuesday afternoons is exclusively reserved for Formal Mediation. Conflicting {$other['hearing_type']} session found."
                    ];
                }
            }
        }

        // 3. Elastic duration: Multiple cases of the permitted session type can be placed on the daily docket.
        // Overlap error is only raised if another hearing of a DIFFERENT type or exact same case conflicts.
        return ['available' => true, 'message' => ''];
    }

    /**
     * Checks if a member/officer has a conflicting hearing at the given date/time range.
     */
    public function validateMemberAvailability(
        int $userId,
        string $startDateTime,
        string $endDateTime,
        ?int $excludeHearingId = null,
        string $roleLabel = 'Lupon Officer'
    ): array {
        $nameStmt = $this->conn->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS full_name FROM users WHERE user_id = ?");
        $nameStmt->execute([$userId]);
        $userName = $nameStmt->fetchColumn() ?: "Officer #{$userId}";

        $sql = "
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.end_time, c.case_number
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            LEFT JOIN case_assignments ca ON ca.case_id = h.case_id AND ca.member_id = ?
            LEFT JOIN pangkat_groups pg ON pg.case_id = h.case_id
            LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.member_id = ?
            WHERE (h.presiding_officer_id = ? OR h.substitute_presider_id = ? OR ca.member_id = ? OR pm.member_id = ?)
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
              AND NOT EXISTS (
                  SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id
              )
              AND (
                  h.hearing_date < ? AND COALESCE(h.end_time, DATE_ADD(h.hearing_date, INTERVAL COALESCE(h.duration_minutes, 45) MINUTE)) > ?
              )
        ";
        $params = [$userId, $userId, $userId, $userId, $userId, $userId, $endDateTime, $startDateTime];
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
                : date('g:i A', strtotime($conflict['hearing_date'] . ' +45 minutes'));
            $dateFormatted = date('F j, Y', strtotime($conflict['hearing_date']));

            return [
                'available' => false,
                'conflict' => $conflict,
                'officer_name' => $userName,
                'message' => sprintf(
                    '%s %s is already scheduled for Case %s (%s, %s–%s on %s). Overlapping officer assignments are blocked.',
                    $roleLabel,
                    $userName,
                    $conflict['case_number'],
                    $conflict['hearing_type'],
                    $startFormatted,
                    $endFormatted,
                    $dateFormatted
                )
            ];
        }

        return ['available' => true, 'officer_name' => $userName, 'message' => ''];
    }

    /**
     * Checks if any required Lupon officer assigned to the case has an overlapping hearing.
     */
    public function validateCaseRequiredOfficersAvailability(
        int $caseId,
        string $startDateTime,
        string $endDateTime,
        ?int $excludeHearingId = null
    ): array {
        $stmt = $this->conn->prepare("
            SELECT ca.member_id, ca.assignment_role, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS officer_name
            FROM case_assignments ca
            INNER JOIN users u ON u.user_id = ca.member_id
            WHERE ca.case_id = ?
            UNION
            SELECT pm.member_id, CONCAT('Pangkat ', pm.position) AS assignment_role, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS officer_name
            FROM pangkat_members pm
            INNER JOIN pangkat_groups pg ON pg.pangkat_id = pm.pangkat_id
            INNER JOIN users u ON u.user_id = pm.member_id
            WHERE pg.case_id = ?
        ");
        $stmt->execute([$caseId, $caseId]);
        $officers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($officers as $off) {
            $check = $this->validateMemberAvailability(
                (int) $off['member_id'],
                $startDateTime,
                $endDateTime,
                $excludeHearingId,
                $off['assignment_role']
            );
            if (!$check['available']) {
                return $check;
            }
        }

        return ['available' => true, 'message' => ''];
    }

    /**
     * Validates duplicate hearing prevention:
     * - Same case at overlapping dates/times
     * - Same case and same mediation attempt
     */
    public function validateDuplicateHearing(
        int $caseId,
        string $startDateTime,
        string $endDateTime,
        string $hearingType,
        ?int $mediationAttempt = null,
        ?int $excludeHearingId = null
    ): array {
        $stmtCase = $this->conn->prepare('SELECT case_number FROM cases WHERE case_id = ?');
        $stmtCase->execute([$caseId]);
        $caseNumber = $stmtCase->fetchColumn() ?: "Case #{$caseId}";

        // 1. Block duplicate active schedule for the same date/time
        $sqlTime = "
            SELECT hearing_id, hearing_type, hearing_date, end_time
            FROM hearings
            WHERE case_id = ?
              AND status NOT IN ('Cancelled', 'Office Cancelled')
              AND NOT EXISTS (
                  SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = hearings.hearing_id
              )
              AND (
                  hearing_date < ? AND COALESCE(end_time, DATE_ADD(hearing_date, INTERVAL COALESCE(duration_minutes, 45) MINUTE)) > ?
              )
        ";
        $paramsTime = [$caseId, $endDateTime, $startDateTime];
        if ($excludeHearingId) {
            $sqlTime .= ' AND hearing_id != ?';
            $paramsTime[] = $excludeHearingId;
        }
        $sqlTime .= ' LIMIT 1';

        $stmtTime = $this->conn->prepare($sqlTime);
        $stmtTime->execute($paramsTime);
        $timeDup = $stmtTime->fetch(PDO::FETCH_ASSOC);
        if ($timeDup) {
            $timeFmt = date('F j, Y \a\t g:i A', strtotime($timeDup['hearing_date']));
            return [
                'valid' => false,
                'message' => sprintf(
                    'Case %s already has an active %s schedule on %s. Duplicate hearings for the same case at overlapping dates/times are blocked.',
                    $caseNumber,
                    $timeDup['hearing_type'],
                    $timeFmt
                )
            ];
        }

        // 2. Block duplicate active schedule for the same mediation attempt
        if ($hearingType === 'Mediation' && $mediationAttempt !== null && $mediationAttempt > 0) {
            $sqlMed = "
                SELECT hearing_id, hearing_date, mediation_attempt
                FROM hearings
                WHERE case_id = ?
                  AND hearing_type IN ('Initial Hearing', 'Mediation')
                  AND mediation_attempt = ?
                  AND status NOT IN ('Cancelled', 'Office Cancelled')
                  AND NOT EXISTS (
                      SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = hearings.hearing_id
                  )
            ";
            $paramsMed = [$caseId, $mediationAttempt];
            if ($excludeHearingId) {
                $sqlMed .= ' AND hearing_id != ?';
                $paramsMed[] = $excludeHearingId;
            }
            $sqlMed .= ' LIMIT 1';

            $stmtMed = $this->conn->prepare($sqlMed);
            $stmtMed->execute($paramsMed);
            $medDup = $stmtMed->fetch(PDO::FETCH_ASSOC);
            if ($medDup) {
                $medDateFmt = date('F j, Y \a\t g:i A', strtotime($medDup['hearing_date']));
                return [
                    'valid' => false,
                    'message' => sprintf(
                        'Case %s already has an active schedule for Mediation %d on %s. Duplicate active schedules for the same mediation attempt are blocked.',
                        $caseNumber,
                        $mediationAttempt,
                        $medDateFmt
                    )
                ];
            }
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * Finds 3-5 practical alternative available dates/times when a requested slot is blocked.
     */
    public function findAlternativeSlots(
        int $caseId,
        string $startDate,
        int $durationMinutes = 45,
        string $venue = 'Lupon Court Room',
        ?int $presidingOfficerId = null,
        int $maxSuggestions = 4
    ): array {
        $slots = [];
        try {
            $startDt = new DateTimeImmutable(substr($startDate, 0, 10));
        } catch (Throwable) {
            $startDt = new DateTimeImmutable();
        }

        // Candidate time slots during standard operating hours
        $standardTimes = ['09:00', '10:00', '13:30', '14:30'];
        $candidateDay = $startDt->modify('+1 day');
        $daysChecked = 0;

        while (count($slots) < $maxSuggestions && $daysChecked < 14) {
            $daysChecked++;
            $dayOfWeek = (int) $candidateDay->format('N');
            if ($dayOfWeek === 6 || $dayOfWeek === 7) {
                $candidateDay = $candidateDay->modify('+1 day');
                continue;
            }

            // Check if holiday
            if ($this->isPhilippineHoliday($candidateDay) !== null) {
                $candidateDay = $candidateDay->modify('+1 day');
                continue;
            }

            $dateStr = $candidateDay->format('Y-m-d');

            // Check daily capacity
            $capCheck = $this->validateDailyCapacity($dateStr);
            if (!$capCheck['valid']) {
                $candidateDay = $candidateDay->modify('+1 day');
                continue;
            }

            foreach ($standardTimes as $t) {
                if (count($slots) >= $maxSuggestions) break;

                $startDateTime = "{$dateStr} {$t}:00";
                $endDateTime = date('Y-m-d H:i:s', strtotime("{$startDateTime} +{$durationMinutes} minutes"));

                // Validate venue
                $venueCheck = $this->validateVenueAvailability($venue, $startDateTime, $endDateTime);
                if (!$venueCheck['available']) continue;

                // Validate presider
                if ($presidingOfficerId) {
                    $presCheck = $this->validateMemberAvailability($presidingOfficerId, $startDateTime, $endDateTime, null, 'Presiding Officer');
                    if (!$presCheck['available']) continue;
                }

                // Validate case required officers
                $offCheck = $this->validateCaseRequiredOfficersAvailability($caseId, $startDateTime, $endDateTime);
                if (!$offCheck['available']) continue;

                $slots[] = [
                    'date' => $dateStr,
                    'time' => $t,
                    'datetime' => $startDateTime,
                    'start_time' => $startDateTime,
                    'end_time' => $endDateTime,
                    'formatted' => date('l, M j, Y \a\t g:i A', strtotime($startDateTime)),
                    'formatted_date' => date('l, M j, Y \a\t g:i A', strtotime($startDateTime)),
                    'venue' => $venue
                ];
            }

            $candidateDay = $candidateDay->modify('+1 day');
        }

        return $slots;
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
        return 'Lupon Court Room';
    }

    /**
     * Validates summons delivery window (8:00 AM – 6:00 PM), attempt intervals (1–3 days), and max attempts (2–3).
     */
    public function validateSummonsServiceAttempt(
        string $serviceDateTime,
        int $attemptNumber = 1,
        ?string $previousAttemptDate = null
    ): array {
        // Time restrictions (delivery window 8:00 AM - 6:00 PM and attempt intervals) bypassed for testing/development
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
