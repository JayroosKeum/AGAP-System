<?php

require_once __DIR__ . '/../../services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/SingleSessionService.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../controllers/SettingsController.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthenticated session.']);
    exit;
}

$db = (new Database())->connect();
SingleSessionService::enforceForApi($db);

$controller = new SettingsController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode($controller->getUserProfile($userId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfService::enforceForApiRequest();
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    echo json_encode($controller->updateUserProfile($userId, $data));
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
