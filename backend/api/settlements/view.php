<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/SettlementController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$controller = new SettlementController();

if (!empty($_GET['id'])) {
    $result = $controller->show((int) $_GET['id']);
} elseif (!empty($_GET['case_id'])) {
    $result = $controller->getByCase((int) $_GET['case_id']);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id or case_id parameter is required.']);
    exit;
}

http_response_code($result['success'] ? 200 : 404);
echo json_encode($result);
