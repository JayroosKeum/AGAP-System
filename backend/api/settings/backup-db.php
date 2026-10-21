<?php

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../controllers/SettingsController.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);

if ($userId <= 0 || !in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    die("Unauthorized access to database backups.");
}

$controller = new SettingsController();
$controller->exportBackup($userId, $roleId);
