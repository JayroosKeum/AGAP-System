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
$venue = trim((string) ($input['venue'] ?? 'Barangay Hall'));
$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

if (!$caseId && $complaintId) {
    require_once '../../config/database.php';
    $db = (new Database())->connect();
    $stmt = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ?');
    $stmt->execute([$complaintId]);
    $caseId = (int) $stmt->fetchColumn() ?: null;
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

$summons = new Summons();
$scheduledMediations = $summons->getScheduledMediationsForDate($date, $caseId);
$totalBooked = count($scheduledMediations);
$maxSlots = 8;
$availableSlots = max(0, $maxSlots - $totalBooked);
$isFullyBooked = ($totalBooked >= $maxSlots);

$hasConflict = false;
$conflictData = null;

if ($isWeekend) {
    echo json_encode([
        'success' => false,
        'message' => 'Weekend selected. Mediation hearings can only be scheduled on weekdays (Monday to Friday).',
        'date' => $date,
        'is_weekend' => true,
        'is_non_working_day' => true,
        'total_booked' => $totalBooked,
        'max_slots' => $maxSlots,
        'available_slots' => 0,
        'is_fully_booked' => true,
        'scheduled_mediations' => $scheduledMediations
    ]);
    exit;
}

if ($isNonWorkingDay) {
    echo json_encode([
        'success' => false,
        'message' => 'The selected date is an official regular holiday / non-working day. Please select a regular working day.',
        'date' => $date,
        'is_weekend' => false,
        'is_non_working_day' => true,
        'total_booked' => $totalBooked,
        'max_slots' => $maxSlots,
        'available_slots' => 0,
        'is_fully_booked' => true,
        'scheduled_mediations' => $scheduledMediations
    ]);
    exit;
}

if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
    $timeParts = explode(':', $time);
    $hours = (int) $timeParts[0];
    $minutes = (int) ($timeParts[1] ?? 0);
    $totalMinutes = $hours * 60 + $minutes;

    // Office hours: 8:00 (480 min) to 12:00 (720 min) and 13:00 (780 min) to 17:00 (1020 min)
    // 1-hour session duration:
    $isMorning = ($totalMinutes >= 480 && ($totalMinutes + 60) <= 720);
    $isAfternoon = ($totalMinutes >= 780 && ($totalMinutes + 60) <= 1020);

    if ($totalMinutes >= 720 && $totalMinutes < 780) {
        $hasConflict = true;
        $conflictData = [
            'message' => 'Mediation sessions cannot be scheduled during lunch break (12:00 PM – 1:00 PM).'
        ];
    } elseif (!$isMorning && !$isAfternoon) {
        $hasConflict = true;
        $conflictData = [
            'message' => '1-hour mediation sessions must be scheduled within office hours (8:00 AM – 12:00 PM or 1:00 PM – 5:00 PM).'
        ];
    } else {
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
    'capacity_feedback' => sprintf('%d of %d slots available for this date', $availableSlots, $maxSlots),
    'scheduled_mediations' => $scheduledMediations,
    'has_conflict' => $hasConflict,
    'conflict' => $conflictData
]);
