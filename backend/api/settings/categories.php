<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/SettingsController.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated session.']);
    exit;
}

$controller = new SettingsController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $model = new Setting();
    echo json_encode(['success' => true, 'categories' => $model->getCategories()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    $action = $data['action'] ?? 'save';

    if ($action === 'delete') {
        $catId = (int)($data['category_id'] ?? 0);
        echo json_encode($controller->deleteCategory($catId, $userId, $roleId));
        exit;
    }

    echo json_encode($controller->saveCategory($data, $userId, $roleId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_GET;
    $catId = (int)($data['category_id'] ?? 0);
    echo json_encode($controller->deleteCategory($catId, $userId, $roleId));
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
