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
$result = $controller->index($_GET);
http_response_code(200);
echo json_encode($result);
