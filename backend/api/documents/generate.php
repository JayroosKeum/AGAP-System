<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/DocumentController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
if (($data = json_decode(file_get_contents('php://input'), true)) === null) {
    $data = $_POST;
}
if (($data['form_code'] ?? '') !== 'KP12') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported document form.']);
    exit;
}

$result = (new DocumentController())->generateKp12($data, (int) $_SESSION['user_id']);
http_response_code($result['success'] ? 201 : 422);
echo json_encode($result);