<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/SettingsController.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);

if ($userId <= 0 || !in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$provider = trim((string)($data['provider'] ?? 'Semaphore'));
$apiKey = trim((string)($data['sms_api_key'] ?? ''));
$recipient = trim((string)($data['recipient'] ?? ''));
$message = trim((string)($data['message'] ?? ''));

$controller = new SettingsController();
echo json_encode($controller->testSmsGateway($provider, $apiKey, $recipient, $message));
