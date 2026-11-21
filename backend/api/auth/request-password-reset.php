<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/AuthController.php';
require_once __DIR__ . '/../../services/CsrfService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!CsrfService::expectsJson()) {
        CsrfService::redirectBrowser('../../../frontend/pages/auth/forgot-password.php');
    }
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

$auth = new AuthController();

if (!empty($data['credential']) && is_string($data['credential'])) {
    $attempt = $_SESSION['forgot_password_google_attempt'] ?? [];
    unset($_SESSION['forgot_password_google_attempt']);

    $nonce = (($attempt['expires_at'] ?? 0) > time() && is_string($attempt['nonce'] ?? null))
        ? $attempt['nonce']
        : '';

    $result = $auth->requestPasswordResetWithGoogle($data['credential'], $nonce);

    if (empty($result['success'])) {
        $newAttempt = ['nonce' => bin2hex(random_bytes(32)), 'expires_at' => time() + 600];
        $_SESSION['forgot_password_google_attempt'] = $newAttempt;
        $result['next_nonce'] = $newAttempt['nonce'];
    }
} else {
    $result = $auth->requestPasswordReset($data);
}

http_response_code(!empty($result['success']) ? 200 : 422);
echo json_encode($result);
