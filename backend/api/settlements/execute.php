<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../config/database.php';
require_once '../../middleware/rbac.php';
require_once '../../controllers/SettlementController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk'], $db);

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
    agapRequireCaseAccess($db, agapResolveExecutionCaseId($db, (int) $executionId), 'write', true);
    $result = match ($action) {
        'record_hearing' => $controller->recordExecutionHearing((int) $executionId, $input, (int) $_SESSION['user_id']),
        'issue_notice' => $controller->issueNoticeOfExecution((int) $executionId, $input, (int) $_SESSION['user_id']),
        default => $controller->updateExecution((int) $executionId, $input, (int) $_SESSION['user_id']),
    };
} else {
    $settlementId = filter_var($input['settlement_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $arbitrationId = filter_var($input['arbitration_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($arbitrationId && !$settlementId) {
        agapRequireCaseAccess($db, agapResolveArbitrationCaseId($db, (int) $arbitrationId), 'write', true);
        $result = $controller->fileArbitrationExecution((int) $arbitrationId, $input, (int) $_SESSION['user_id']);
    } elseif ($settlementId) {
        agapRequireCaseAccess($db, agapResolveSettlementCaseId($db, (int) $settlementId), 'write', true);
        $result = $controller->fileExecution((int) $settlementId, $input, (int) $_SESSION['user_id']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid settlement_id or arbitration_id is required.']);
        exit;
    }
}

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
