<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../config/database.php';
require_once '../../middleware/rbac.php';
require_once '../../controllers/SettlementController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member', 'Resident'], $db);

$controller = new SettlementController();

if (!empty($_GET['id'])) {
    $settlementId = (int) $_GET['id'];
    $caseId = agapResolveSettlementCaseId($db, $settlementId);
    agapRequireCaseAccess($db, $caseId, 'read', true);
    $result = $controller->show($settlementId);
} elseif (!empty($_GET['case_id'])) {
    $caseId = (int) $_GET['case_id'];
    agapRequireCaseAccess($db, $caseId, 'read', true);
    $result = $controller->getByCase($caseId);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id or case_id parameter is required.']);
    exit;
}

http_response_code($result['success'] ? 200 : 404);
echo json_encode($result);
