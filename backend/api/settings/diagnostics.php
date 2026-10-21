<?php

require_once __DIR__ . '/../../config/Database.php';
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

$model = new Setting();
echo json_encode([
    'success' => true,
    'storage' => $model->getStorageBreakdown(),
    'diagnostics' => $model->getDiagnostics()
]);
