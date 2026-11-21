<?php

require_once '../../controllers/AuthController.php';
require_once __DIR__ . '/../../services/CsrfService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../frontend/pages/resident/register.php', true, 303);
    exit;
}

if (!CsrfService::validateRequest()) {
    CsrfService::redirectBrowser('../../../frontend/pages/resident/register.php?error=session_expired');
}

$result = (new AuthController())->registerResident($_POST);
if (!$result['success']) {
    $code = in_array(($result['code'] ?? ''), ['validation', 'duplicate', 'google_required'], true)
        ? $result['code']
        : 'unavailable';
    header('Location: ../../../frontend/pages/resident/register.php?error=' . rawurlencode($code), true, 303);
    exit;
}

$notice = !empty($result['google_verified']) ? 'email_verified' : 'registered';
header('Location: ../../../frontend/pages/resident/login.php?notice=' . $notice, true, 303);
exit;
