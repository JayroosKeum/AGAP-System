<?php

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../controllers/SettingsController.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated session.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$cur = trim((string)($data['current_password'] ?? ''));
$new = trim((string)($data['new_password'] ?? ''));
$conf = trim((string)($data['confirm_password'] ?? ''));

$controller = new SettingsController();
echo json_encode($controller->changeUserPassword($userId, $cur, $new, $conf));
