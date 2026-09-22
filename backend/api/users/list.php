<?php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../controllers/UserController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if ((int) ($_SESSION['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
    exit;
}

echo json_encode(['success' => true, 'users' => (new UserController())->index()]);
