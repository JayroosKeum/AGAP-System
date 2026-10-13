<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../config/database.php';
require_once '../../services/SummonDeliveryService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $input = $json;
    }
}

$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hearingId = filter_var($input['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid case ID is required.']);
    exit;
}

try {
    $db = (new Database())->connect();
    $service = new SummonDeliveryService($db);
    $result = $service->initiateNextAttempt((int) $caseId, (int) $_SESSION['user_id'], $hearingId);

    http_response_code(!empty($result['success']) ? 200 : 422);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('Error in next-attempt.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred while initiating the summons attempt.']);
}
