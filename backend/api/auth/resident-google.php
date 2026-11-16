<?php

declare(strict_types=1);

require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/GoogleResidentIdentity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!CsrfService::validateRequest()) {
    http_response_code(403);
    exit('Google sign-in could not be verified. Reload the sign-in page and try again.');
}

$portal = '../../../frontend/pages/resident/';
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
header('Location: ' . $portal . $destination, true, 303);
