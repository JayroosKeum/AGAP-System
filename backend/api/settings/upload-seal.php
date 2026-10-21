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
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Administrators and Lupon Clerks can upload seals.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$sealType = trim((string)($_POST['type'] ?? 'barangay'));
if (!in_array($sealType, ['barangay', 'city'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid seal type. Allowed: barangay, city.']);
    exit;
}

if (empty($_FILES['seal_file'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$controller = new SettingsController();
echo json_encode($controller->uploadSeal($sealType, $_FILES['seal_file'], $userId, $roleId));
