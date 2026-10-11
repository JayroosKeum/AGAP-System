<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../models/HearingMinutes.php';
require_once __DIR__ . '/../../services/AuditService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only authorized personnel can finalize hearing minutes.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

$hearingId = filter_var($input['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$hearingId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid hearing_id is required.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$model = new HearingMinutes();
$result = $model->finalize($hearingId, $userId);

if ($result['success']) {
    (new AuditService())->log($userId, 'Finalized and locked hearing minutes', 'Hearings', $hearingId);
}

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
