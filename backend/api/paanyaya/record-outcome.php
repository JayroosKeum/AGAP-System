<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$sessionId = filter_var($input['session_id'] ?? $input['paanyaya_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$paanyayaModel = new Paanyaya();
if ($sessionId) {
    $sessCheck = $paanyayaModel->getSessionById($sessionId);
    if (!$sessCheck) {
        $sessionId = null;
    }
}
if (!$sessionId && !empty($input['complaint_id'])) {
    $cid = (int) $input['complaint_id'];
    $latest = $paanyayaModel->getByComplaintId($cid);
    if ($latest && !empty($latest['session_id'])) {
        $sessionId = (int) $latest['session_id'];
    } else {
        $newPaa = $paanyayaModel->schedule([
            'complaint_id' => $cid,
            'invitation_date' => date('Y-m-d'),
            'invitation_time' => '14:00',
            'venue' => 'Barangay Tumana',
        ], (int) ($_SESSION['user_id'] ?? 1));
        if (!empty($newPaa['session_id'])) {
            $sessionId = (int) $newPaa['session_id'];
        }
    }
}

if (!$sessionId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid Session ID is required.']);
    exit;
}

$outcome = trim((string) ($input['outcome'] ?? ''));
$allowedOutcomes = ['Settled', 'Absent_Retry', 'Continued_Dialogue', 'Impasse_Escalate', 'Max_Failed_Escalate', 'Resolved', 'Escalate'];
if (!in_array($outcome, $allowedOutcomes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid outcome specified.']);
    exit;
}

$notes = trim((string) ($input['outcome_notes'] ?? ($input['resolution_notes'] ?? ($input['notes'] ?? ''))));
$userId = (int) $_SESSION['user_id'];

$outcomeData = [
    'outcome' => $outcome,
    'outcome_notes' => $notes,
    'complainant_attended' => $input['complainant_attended'] ?? 'Present',
    'respondent_attended' => $input['respondent_attended'] ?? 'Present',
    'mediation_date' => $input['mediation_date'] ?? null,
    'mediation_time' => $input['mediation_time'] ?? null,
    'venue' => $input['venue'] ?? 'Lupon Court Room',
];

try {
    $paanyayaModel = new Paanyaya();
    $result = $paanyayaModel->recordOutcome($sessionId, $outcomeData, $userId);

    if (!$result['success']) {
        http_response_code(422);
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
    exit;
}
