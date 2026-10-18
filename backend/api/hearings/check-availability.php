<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../controllers/HearingController.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorized to view hearing availability.']);
    exit;
}

$input = $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST;
if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$result = (new HearingController())->checkAvailability($input);
http_response_code(200);
echo json_encode($result);
