<?php

header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/AuthController.php';
require_once __DIR__ . '/../../services/CsrfService.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!CsrfService::expectsJson()) {
        $tokenParam = isset($_GET['token']) && is_string($_GET['token']) ? ('?token=' . rawurlencode($_GET['token'])) : '';
        CsrfService::redirectBrowser('../../../frontend/pages/resident/verify-email.php' . $tokenParam);
    }
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = $_POST;
$result = (new AuthController())->verifyEmail($data);
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
