<?php

require_once __DIR__ . '/../../services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/SingleSessionService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

CsrfService::enforceForApiRequest();

SingleSessionService::releaseCurrentSession((new Database())->connect());
SingleSessionService::destroyPhpSession();

$redirectPortal = (string) ($_POST['redirect_portal'] ?? '');
$logoutUrl = ($redirectPortal === 'resident')
    ? '../../../frontend/pages/complaints/resident-portal.php?logged_out=1'
    : '../../../frontend/pages/auth/login.php?logged_out=1';
header('Location: ' . $logoutUrl, true, 303);
exit;
