<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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

$controller = new SettlementController();
$action = (string) ($input['action'] ?? '');
$targetCaseId = !empty($input['case_id'])
    ? (int) $input['case_id']
    : (!empty($input['settlement_id']) ? agapResolveSettlementCaseId($db, (int) $input['settlement_id']) : 0);
if ($targetCaseId > 0) {
    agapRequireCaseAccess($db, $targetCaseId, 'write', true);
}
if ($action === 'confirm_legacy_review') {
    $settlementId = filter_var($input['settlement_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$settlementId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid settlement_id is required.']);
        exit;
    }
    $result = $controller->confirmLegacyReview((int) $settlementId, $input, (int) $_SESSION['user_id']);
} elseif ($action === 'update_settlement_terms') {
    $result = $controller->updateTerms($input, (int) $_SESSION['user_id']);
} else {
    $result = $controller->save($input, (int) $_SESSION['user_id']);
}
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
