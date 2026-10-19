<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../models/Summons.php';
require_once '../../services/MediationDeadlineService.php';

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
$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$getWindow = !empty($input['get_window']) || !empty($input['window']);

$summons = new Summons();
$db = null;

if (!$caseId && $complaintId) {
    require_once '../../config/database.php';
    $db = (new Database())->connect();
    $stmt = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ?');
    $stmt->execute([$complaintId]);
    $caseId = (int) $stmt->fetchColumn() ?: null;
}

// 1. Build 15-working-day statutory window metadata if complaint_id or get_window is present
$windowMeta = null;
if ($complaintId || $getWindow) {
    if (!$db) {
        require_once '../../config/database.php';
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

    $dailyCounts = $summons->getDailyMediationCountsInRange($rangeStart, $rangeEnd, $caseId);

    $days = [];
    $cur = new DateTimeImmutable($rangeStart);
    $endDt = new DateTimeImmutable($rangeEnd);

    while ($cur <= $endDt) {
        $dStr = $cur->format('Y-m-d');
        $dayOfWeek = (int) $cur->format('N');
        $isWeekend = ($dayOfWeek === 6 || $dayOfWeek === 7);
        $isHoliday = MediationDeadlineService::isNonWorkingDay($cur);
        $booked = $dailyCounts[$dStr] ?? 0;
        $maxSlots = 8;
        $avail = max(0, $maxSlots - $booked);
        $isFull = ($booked >= $maxSlots);
        $isPast = ($dStr < $todayStr);
        $isOutsideWindow = ($dStr > $deadlineDateStr);

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
            'is_past' => $isPast,
            'is_outside_window' => $isOutsideWindow,
            'can_schedule' => (!$isPast && !$isOutsideWindow && !$isFull)
        ];

        $cur = $cur->modify('+1 day');
    }

    $windowMeta = [
        'filing_date' => $filingDateStr,
        'deadline_date' => $deadlineDateStr,
        'today' => $todayStr,
        'max_slots_per_day' => 8,
        'statutory_working_days' => 15,
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

$scheduledMediations = $summons->getScheduledMediationsForDate($date, $caseId);
$totalBooked = count($scheduledMediations);
$maxSlots = 8;
$availableSlots = max(0, $maxSlots - $totalBooked);
$isFullyBooked = ($totalBooked >= $maxSlots);

$isOutside15DayWindow = false;
$deadlineMessage = null;
if ($windowMeta) {
    if ($date > $windowMeta['deadline_date']) {
        $isOutside15DayWindow = true;
        $deadlineFormatted = date('F j, Y', strtotime($windowMeta['deadline_date']));
        $deadlineMessage = "The selected date exceeds the 15-working-day statutory mediation deadline ({$deadlineFormatted}).";
    }
}

$hasConflict = false;
$conflictData = null;

if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
    $hearingDateTime = substr($date, 0, 10) . ' ' . substr($time, 0, 5) . ':00';
    $conflict = $summons->findMediationConflict($hearingDateTime, $venue, $caseId);
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
            'venue' => $conflict['venue'],
            'message' => sprintf(
                'A %s is already scheduled%s from %s to %s on %s (%s). Mediation sessions cannot overlap on the same day.',
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
