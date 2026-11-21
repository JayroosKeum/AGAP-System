<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../services/CsrfService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!CsrfService::expectsJson()) {
        CsrfService::redirectBrowser(CsrfService::portalHomePath());
    }
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!CsrfService::validateRequest()) {
    if (!CsrfService::expectsJson()) {
        $fallback = isset($_SESSION['user_id'])
            ? CsrfService::portalHomePath()
            : '../../../frontend/pages/auth/login.php?logged_out=1';
        CsrfService::redirectBrowser($fallback);
    }
    http_response_code(419);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Invalid or missing request token.']);
    exit;
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool) ($params['secure'] ?? false),
        (bool) ($params['httponly'] ?? true)
    );
}

session_destroy();
header('Location: ../../../frontend/pages/auth/login.php?logged_out=1', true, 303);
exit;
