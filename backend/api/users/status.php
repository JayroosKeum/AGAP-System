<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../controllers/UserController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if ((int) ($_SESSION['role_id'] ?? 0) !== 1 || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

$userId = (int) ($input['user_id'] ?? 0);
$status = trim((string) ($input['status'] ?? ''));

$result = (new UserController())->setStatus($userId, $status, (int) $_SESSION['user_id']);
http_response_code(!empty($result['success']) ? 200 : 422);
echo json_encode($result);
