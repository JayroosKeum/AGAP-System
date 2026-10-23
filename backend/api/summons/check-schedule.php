<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../models/Summons.php';
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
$hearingType = trim((string) ($input['hearing_type'] ?? 'Mediation'));
if ($hearingType !== 'Conciliation') {
    $hearingType = 'Mediation';
}
$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$getWindow = !empty($input['get_window']) || !empty($input['window']);

$summons = new Summons();
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

    $filingDateStr = $compCreatedAt ? substr($compCreatedAt, 0, 10) : date('Y-m-d');
    $deadlineDateStr = MediationDeadlineService::calculateBusinessDays($filingDateStr, 15);
    $todayStr = date('Y-m-d');

    // Range to inspect: today (or filing date) up to 7 calendar days after statutory deadline
    $rangeStart = min($todayStr, $filingDateStr);
    $rangeEnd = date('Y-m-d', strtotime($deadlineDateStr . ' +7 days'));

    $dailyCounts = $summons->getDailyHearingCountsInRange($rangeStart, $rangeEnd, $caseId, $hearingType);

    $days = [];
    $cur = new DateTimeImmutable($rangeStart);
    $endDt = new DateTimeImmutable($rangeEnd);

    while ($cur <= $endDt) {
        $dStr = $cur->format('Y-m-d');
        $dayOfWeek = (int) $cur->format('N'); // 1 = Mon, 2 = Tue, ..., 7 = Sun
        $isWeekend = ($dayOfWeek === 6 || $dayOfWeek === 7);
        $isHoliday = MediationDeadlineService::isNonWorkingDay($cur);
        $isTuesday = ($dayOfWeek === 2);
        $booked = $dailyCounts[$dStr] ?? 0;

        if ($hearingType === 'Conciliation') {
            // Tumana Master Schedule: Tuesday morning only (8:00 AM - 12:00 PM) = 4 sessions
            // Mon, Wed, Thu, Fri = 8 sessions. Weekends & Holidays = 0.
            if ($isWeekend || $isHoliday) {
                $maxSlots = 0;
                $avail = 0;
                $isFull = false;
                $canSchedule = false;
            } else {
                $maxSlots = $isTuesday ? 4 : 8;
                $avail = max(0, $maxSlots - $booked);
                $isFull = ($booked >= $maxSlots);
                $canSchedule = (!$isPast = ($dStr < $todayStr)) && !$isFull;
            }
        } else {
            $maxSlots = 8;
            $avail = max(0, $maxSlots - $booked);
            $isFull = ($booked >= $maxSlots);
            $isPast = ($dStr < $todayStr);
            $canSchedule = (!$isPast && !$isFull);
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
            'is_tuesday_morning_only' => ($hearingType === 'Conciliation' && $isTuesday),
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

$curDayOfWeek = (int) $dateObj->format('N');
$isCurTuesday = ($curDayOfWeek === 2);
if ($hearingType === 'Conciliation') {
    $maxSlots = ($isWeekend || $isNonWorkingDay) ? 0 : ($isCurTuesday ? 4 : 8);
} else {
    $maxSlots = 8;
}

$scheduledMediations = $summons->getScheduledHearingsForDate($date, $hearingType, $caseId);
$totalBooked = count($scheduledMediations);
$availableSlots = max(0, $maxSlots - $totalBooked);
$isFullyBooked = ($maxSlots > 0 && $totalBooked >= $maxSlots);

$isOutside15DayWindow = false;
$deadlineMessage = null;

$hasConflict = false;
$conflictData = null;

if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
    $hearingDateTime = substr($date, 0, 10) . ' ' . substr($time, 0, 5) . ':00';
    $conflict = $summons->findMediationConflict($hearingDateTime, $venue, $caseId, null, $hearingType);
    if ($conflict) {
        $hasConflict = true;
        $cStart = date('g:i A', strtotime($conflict['hearing_date']));
        $cEnd = date('g:i A', strtotime($conflict['hearing_date'] . ' +60 minutes'));
        $cDate = date('M j, Y', strtotime($conflict['hearing_date']));
        $caseRef = !empty($conflict['case_number']) ? ' for Case ' . $conflict['case_number'] : '';
        $conflictData = [
            'case_number' => $conflict['case_number'] ?? '',
            'hearing_type' => $conflict['hearing_type'] ?? 'Mediation',
            'hearing_date' => $conflict['hearing_date'],
            'start_time' => $cStart,
            'end_time' => $cEnd,
            'venue' => $conflict['venue'] ?? $venue,
            'message' => !empty($conflict['message']) ? $conflict['message'] : sprintf(
                'A %s is already scheduled%s from %s to %s on %s (%s). Mediation sessions cannot overlap on the same time.',
                $conflict['hearing_type'] ?? 'Mediation',
                $caseRef,
                $cStart,
                $cEnd,
                $cDate,
                $conflict['venue'] ?? 'Barangay Hall'
            )
        ];
    }
}

echo json_encode([
    'success' => true,
    'date' => $date,
    'total_booked' => $totalBooked,
    'max_slots' => $maxSlots,
    'available_slots' => $availableSlots,
    'is_fully_booked' => $isFullyBooked,
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
