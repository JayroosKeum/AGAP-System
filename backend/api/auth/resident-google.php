<?php

declare(strict_types=1);

require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/GoogleResidentIdentity.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$portal = '../../../frontend/pages/resident/';
$signedInHome = (($_SESSION['role_name'] ?? '') === 'Resident')
    ? ($portal . 'dashboard.php')
    : ($portal . 'login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    CsrfService::redirectBrowser($signedInHome);
}

if (!CsrfService::validateRequest()) {
    $csrfTarget = (($_SESSION['role_name'] ?? '') === 'Resident')
        ? ($portal . 'dashboard.php')
        : ($portal . 'login.php?notice=google_failed');
    CsrfService::redirectBrowser($csrfTarget);
}

$attempt = $_SESSION['resident_google_attempt'] ?? [];
unset($_SESSION['resident_google_attempt']);
$credential = $_POST['credential'] ?? null;
$destination = 'login.php?notice=google_failed';
if (is_string($credential) && ($attempt['expires_at'] ?? 0) > time()) {
    try {
        $identity = (new GoogleResidentIdentity())->confirm($credential, $attempt['nonce']);
        $destination = match ((new AuthController())->acceptResidentGoogleIdentity($identity)) {
            'signed_in' => 'dashboard.php',
            'register' => 'register.php',
            'email_in_use' => 'login.php?notice=google_existing',
            default => 'login.php?notice=google_failed',
        };
    } catch (Throwable $e) {
        error_log('Resident Google verification failed: ' . get_class($e));
    }
}
CsrfService::redirectBrowser($portal . $destination);
