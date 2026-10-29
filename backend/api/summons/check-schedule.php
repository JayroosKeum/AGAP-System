<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../models/Summons.php';
require_once __DIR__ . '/../../models/Setting.php';
require_once __DIR__ . '/../../services/MediationDeadlineService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

$date = trim((string) ($input['date'] ?? ''));
$time = trim((string) ($input['time'] ?? ''));
$venue = trim((string) ($input['venue'] ?? 'Lupon Court Room'));
$hearingType = trim((string) ($input['hearing_type'] ?? ($input['stage'] ?? 'Mediation')));
if ($hearingType !== 'Conciliation') {
    $hearingType = 'Mediation';
}
$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$getWindow = !empty($input['get_window']) || !empty($input['window']);

$summons = new Summons();

    $rawMedDays = Setting::getSetting('mediation_day_of_week', 'Tue');
    $allowedMedDays = [];
    if (is_string($rawMedDays)) {
        $dec = json_decode($rawMedDays, true);
        $allowedMedDays = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $rawMedDays)));
    } elseif (is_array($rawMedDays)) {
        $allowedMedDays = $rawMedDays;
    }
    if (empty($allowedMedDays)) {
        $allowedMedDays = ['Tue'];
    }
    $pluralMap = [
        'Mon' => 'Mondays', 'Tue' => 'Tuesdays', 'Wed' => 'Wednesdays',
        'Thu' => 'Thursdays', 'Fri' => 'Fridays', 'Sat' => 'Saturdays', 'Sun' => 'Sundays'
    ];
    $medDaysPlural = array_map(fn($d) => $pluralMap[$d] ?? $d, $allowedMedDays);
    $medDaysLabel = implode(', ', $medDaysPlural);
    $workStart = Setting::getSetting('working_hours_start', '08:00');
    $workEnd = Setting::getSetting('working_hours_end', '17:00');
    $medHoursStart = Setting::getSetting('mediation_hours_start', '13:00');
    $medHoursEnd = Setting::getSetting('mediation_hours_end', '16:30');
    $medVenue = Setting::getSetting('mediation_venue', 'Lupon Court Room');

$db = null;

if (!$caseId && $complaintId) {
    require_once __DIR__ . '/../../config/Database.php';
    $db = (new Database())->connect();
    $stmt = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ?');
    $stmt->execute([$complaintId]);
    $caseId = (int) $stmt->fetchColumn() ?: null;
} elseif ($caseId && !$complaintId) {
    require_once __DIR__ . '/../../config/Database.php';
    $db = (new Database())->connect();
    $stmt = $db->prepare('SELECT complaint_id FROM cases WHERE case_id = ?');
    $stmt->execute([$caseId]);
    $complaintId = (int) $stmt->fetchColumn() ?: null;
}

// 1. Build 15-working-day statutory window metadata if complaint_id or get_window is present
$windowMeta = null;
if ($complaintId || $getWindow) {
    if (!$db) {
        require_once __DIR__ . '/../../config/Database.php';
        $db = (new Database())->connect();
    }
    $compCreatedAt = null;
    if ($complaintId) {
        $stmtComp = $db->prepare('SELECT created_at FROM complaints WHERE complaint_id = ?');
        $stmtComp->execute([$complaintId]);
        $compCreatedAt = $stmtComp->fetchColumn();
    }

    $todayStr = date('Y-m-d');
    $targetDate = !empty($input['target_date']) ? trim((string) $input['target_date']) : null;

    if ($targetDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        // Target month mode: center window around target date's month
        $targetDt = new DateTimeImmutable($targetDate);
        $monthStart = $targetDt->format('Y-m-01');
        $monthEnd = $targetDt->format('Y-m-t');
        $rangeStart = $monthStart;
        $rangeEnd = date('Y-m-d', max(strtotime($monthEnd), strtotime($monthStart . ' +34 days')));
    } else {
        $filingDateStr = $compCreatedAt ? substr($compCreatedAt, 0, 10) : date('Y-m-d');
        $deadlineDateStr = MediationDeadlineService::calculateBusinessDays($filingDateStr, 15);

        // Range to inspect: today (or filing date) up to 7 calendar days after statutory deadline
        $rangeStart = min($todayStr, $filingDateStr);
        $rangeEnd = date('Y-m-d', strtotime($deadlineDateStr . ' +7 days'));
    }

    $dailyCounts = $summons->getDailyHearingCountsInRange($rangeStart, $rangeEnd, $caseId, $hearingType);

    $days = [];
    $cur = new DateTimeImmutable($rangeStart);
    $endDt = new DateTimeImmutable($rangeEnd);

    while ($cur <= $endDt) {
        $dStr = $cur->format('Y-m-d');
        $dayOfWeek = (int) $cur->format('N'); // 1 = Mon, 2 = Tue, ..., 7 = Sun
        $curDayCode = $cur->format('D');
        $isWeekend = ($dayOfWeek === 6 || $dayOfWeek === 7);
        $isHoliday = MediationDeadlineService::isNonWorkingDay($cur);
        $isMedDay = in_array($curDayCode, $allowedMedDays, true);
        $booked = $dailyCounts[$dStr] ?? 0;

        if ($hearingType === 'Conciliation') {
            // Conciliation blocked on designated mediation days (Lupon court room reserved for Punong Barangay).
            if ($isWeekend || $isHoliday || $isMedDay) {
                $maxSlots = 0;
                $avail = 0;
                $isFull = false;
                $canSchedule = false;
            } else {
                $maxSlots = 4;
                $avail = max(0, $maxSlots - $booked);
                $isFull = ($booked >= $maxSlots);
                $canSchedule = (!$isPast = ($dStr < $todayStr)) && !$isFull;
            }
        } else {
            // Mediation allowed only on configured mediation days
            $maxSlots = ($isMedDay && !$isWeekend && !$isHoliday) ? 8 : 0;
            $avail = max(0, $maxSlots - $booked);
            $isFull = ($booked >= 8);
            $isPast = ($dStr < $todayStr);
            $canSchedule = (!$isPast && !$isFull && $isMedDay && !$isHoliday && !$isWeekend);
        }

        $days[$dStr] = [
            'date' => $dStr,
            'day_name' => $cur->format('D'),
            'day_num' => (int) $cur->format('j'),
            'month_name' => $cur->format('M'),
            'year' => (int) $cur->format('Y'),
            'booked' => $booked,
            'max' => $maxSlots,
            'available' => $avail,
            'is_fully_booked' => $isFull,
            'is_weekend' => $isWeekend,
            'is_non_working_day' => $isHoliday,
            'is_tuesday_morning_only' => false,
            'is_tuesday_blocked' => ($hearingType === 'Conciliation' && $isTuesday),
            'tuesday_reason' => ($hearingType === 'Conciliation' && $isTuesday) ? 'Tuesdays reserved exclusively for Punong Barangay Mediation.' : null,
            'is_past' => ($dStr < $todayStr),
            'is_outside_window' => false,
            'can_schedule' => $canSchedule
        ];

        $cur = $cur->modify('+1 day');
    }

    $windowMeta = [
        'filing_date' => $filingDateStr,
        'deadline_date' => $deadlineDateStr,
        'today' => $todayStr,
        'hearing_type' => $hearingType,
        'max_slots_per_day' => ($hearingType === 'Conciliation') ? 8 : 8,
        'daily_slots' => $days
    ];
}

// If no date was supplied but window was requested, return window immediately
if ($date === '' && $windowMeta !== null) {
    echo json_encode([
        'success' => true,
        'window' => $windowMeta
    ]);
    exit;
}

if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid date (YYYY-MM-DD) is required.']);
    exit;
}

$dateObj = new DateTimeImmutable($date);
$dayOfWeek = (int) $dateObj->format('N');
$isWeekend = ($dayOfWeek === 6 || $dayOfWeek === 7);
$isNonWorkingDay = MediationDeadlineService::isNonWorkingDay($dateObj);

$curDayCode = $dateObj->format('D');
$isCurMedDay = in_array($curDayCode, $allowedMedDays, true);
if ($hearingType === 'Conciliation') {
    $maxSlots = ($isWeekend || $isNonWorkingDay || $isCurMedDay) ? 0 : 4;
} else {
    $maxSlots = $isCurMedDay ? 8 : 0;
}

$scheduledMediations = $summons->getScheduledHearingsForDate($date, $hearingType, $caseId);
$totalBooked = count($scheduledMediations);
$availableSlots = max(0, $maxSlots - $totalBooked);
$isFullyBooked = ($maxSlots > 0 && $totalBooked >= $maxSlots);

$isOutside15DayWindow = false;
$deadlineMessage = null;

$hasConflict = false;
$conflictData = null;

if ($hearingType === 'Conciliation' && $isCurMedDay) {
    $hasConflict = true;
    $conflictData = [
        'case_number' => '',
        'hearing_type' => 'Mediation Day Blocked',
        'hearing_date' => $date,
        'start_time' => '',
        'end_time' => '',
        'venue' => $venue,
        'message' => "{$medDaysLabel} reserved exclusively for Punong Barangay Mediation. Conciliation hearings must be held on non-mediation weekdays."
    ];
} elseif ($hearingType === 'Mediation' && !$isCurMedDay) {
    $hasConflict = true;
    $conflictData = [
        'case_number' => '',
        'hearing_type' => 'Restricted Mediation Day',
        'hearing_date' => $date,
        'start_time' => '',
        'end_time' => '',
        'venue' => $venue,
        'message' => "Mediation hearings can only be held on designated mediation days ({$medDaysLabel}) according to Office Hours Settings."
    ];
} elseif ($isNonWorkingDay) {
    $hasConflict = true;
    $conflictData = [
        'case_number' => '',
        'hearing_type' => 'Holiday Restriction',
        'hearing_date' => $date,
        'start_time' => '',
        'end_time' => '',
        'venue' => $venue,
        'message' => 'The selected date is a declared holiday or non-working day. Mediation hearings cannot be scheduled.'
    ];
} elseif ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
    $timeMin = (int) date('H', strtotime($time)) * 60 + (int) date('i', strtotime($time));
    $workStartMin = (int)date('H', strtotime($workStart)) * 60 + (int)date('i', strtotime($workStart));
    $workEndMin = (int)date('H', strtotime($workEnd)) * 60 + (int)date('i', strtotime($workEnd));
    if ($hearingType === 'Mediation' && ($timeMin < $workStartMin || $timeMin > ($workEndMin - 30))) {
        $hasConflict = true;
        $conflictData = [
            'case_number' => '',
            'hearing_type' => 'Operating Hours Restriction',
            'hearing_date' => $date,
            'start_time' => $time,
            'end_time' => '',
            'venue' => $venue,
            'message' => "Mediation sessions must have target call times within configured office hours (" . date('g:i A', strtotime($workStart)) . " – " . date('g:i A', strtotime($workEnd)) . ")."
        ];
    } else {
        $hearingDateTime = substr($date, 0, 10) . ' ' . substr($time, 0, 5) . ':00';
        $conflict = $summons->findMediationConflict($hearingDateTime, $venue, $caseId, null, $hearingType);
        if ($conflict) {
            $hasConflict = true;
            $conflictData = [
                'case_number' => $conflict['case_number'] ?? '',
                'hearing_type' => $conflict['hearing_type'] ?? 'Mediation',
                'hearing_date' => $conflict['hearing_date'],
                'start_time' => $conflict['start_time'] ?? date('g:i A', strtotime($conflict['hearing_date'])),
                'end_time' => $conflict['end_time'] ?? date('g:i A', strtotime($conflict['hearing_date'] . ' +45 minutes')),
                'venue' => $conflict['venue'] ?? $venue,
                'queue_status' => $conflict['queue_status'] ?? '',
                'message' => $conflict['message'] ?? sprintf(
                    '⚠️ Schedule Conflict: A %s is already scheduled on %s (%s).',
                    $conflict['hearing_type'] ?? 'Mediation',
                    date('M j, Y g:i A', strtotime($conflict['hearing_date'])),
                    $conflict['venue'] ?? 'Barangay Hall'
                )
            ];
        }
    }
}

$capacityColor = ($totalBooked <= 4) ? 'green' : (($totalBooked <= 7) ? 'amber' : 'red');
$capacityBadgeText = ($isFullyBooked)
    ? sprintf('%d of %d daily mediation slots booked — Date Fully Booked', $totalBooked, $maxSlots)
    : sprintf('%d of %d daily mediation slots booked', $totalBooked, $maxSlots);

echo json_encode([
    'success' => true,
    'date' => $date,
    'is_tuesday' => in_array('Tue', $allowedMedDays, true),
    'is_mediation_day' => $isCurMedDay,
    'mediation_days' => $allowedMedDays,
    'mediation_days_label' => $medDaysLabel,
    'working_hours_start' => $workStart,
    'working_hours_end' => $workEnd,
    'mediation_hours_start' => $medHoursStart,
    'mediation_hours_end' => $medHoursEnd,
    'mediation_venue' => $medVenue,
    'total_booked' => $totalBooked,
    'max_slots' => $maxSlots,
    'available_slots' => $availableSlots,
    'is_fully_booked' => $isFullyBooked,
    'capacity_color' => $capacityColor,
    'capacity_badge_text' => $capacityBadgeText,
    'is_weekend' => $isWeekend,
    'is_non_working_day' => $isNonWorkingDay,
    'is_outside_window' => $isOutside15DayWindow,
    'deadline_message' => $deadlineMessage,
    'capacity_feedback' => sprintf('%d of %d slots available for this date', $availableSlots, $maxSlots),
    'scheduled_mediations' => $scheduledMediations,
    'has_conflict' => $hasConflict,
    'conflict' => $conflictData,
    'window' => $windowMeta
]);
