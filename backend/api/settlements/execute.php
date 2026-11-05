<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/SettlementController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only authorized barangay officers may manage execution proceedings.']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$action = $input['action'] ?? 'file_motion';
$controller = new SettlementController();

if (in_array($action, ['update_status', 'record_hearing', 'issue_notice'], true)) {
    $executionId = filter_var($input['execution_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$executionId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid execution_id is required.']);
        exit;
    }
    $result = match ($action) {
        'record_hearing' => $controller->recordExecutionHearing((int) $executionId, $input, (int) $_SESSION['user_id']),
        'issue_notice' => $controller->issueNoticeOfExecution((int) $executionId, $input, (int) $_SESSION['user_id']),
        default => $controller->updateExecution((int) $executionId, $input, (int) $_SESSION['user_id']),
    };
} else {
    $settlementId = filter_var($input['settlement_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$settlementId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid settlement_id is required.']);
        exit;
    }
    $result = $controller->fileExecution((int) $settlementId, $input, (int) $_SESSION['user_id']);
}

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
