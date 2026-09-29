<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../models/Summons.php';

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

$summons = new Summons();
$scheduledMediations = $summons->getScheduledMediationsForDate($date, $caseId);

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
    'scheduled_mediations' => $scheduledMediations,
    'has_conflict' => $hasConflict,
    'conflict' => $conflictData
]);
