<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../controllers/CaseController.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (!in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorized to edit cases.']);
    exit;
}

$result = (new CaseController())->update($_POST['case_id'] ?? null, $_POST);
http_response_code(!empty($result['success']) ? 200 : 422);
echo json_encode($result);
