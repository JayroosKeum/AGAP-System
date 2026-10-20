<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Lupon Clerk or Administrator may record review determinations.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid Complaint ID is required.']);
    exit;
}

$stageType = trim((string) ($input['stage_type'] ?? 'Paanyaya 1'));
if (!in_array($stageType, [Paanyaya::STAGE_1, Paanyaya::STAGE_2], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid stage type specified.']);
    exit;
}

try {
    $paanyayaModel = new Paanyaya();
    $userId = (int) $_SESSION['user_id'];

    $result = $paanyayaModel->reviewStage((int) $complaintId, $stageType, $input, $userId);

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
