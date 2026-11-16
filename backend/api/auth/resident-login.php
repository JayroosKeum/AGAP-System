<?php

require_once '../../controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../frontend/pages/resident/login.php', true, 303);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$auth = new AuthController();

if (($_POST['action'] ?? '') === 'resend_verification') {
    $notice = $auth->resendResidentVerification($username, $password);
    header('Location: ../../../frontend/pages/resident/login.php?notice=' . $notice, true, 303);
    exit;
}

if ($username === '' || $password === '' || !$auth->loginResident($username, $password)) {
    $notice = $auth->residentEmailPending($username, $password) ? 'verification_pending' : 'invalid_credentials';
    header('Location: ../../../frontend/pages/resident/login.php?notice=' . $notice, true, 303);
    exit;
}

header('Location: ../../../frontend/pages/resident/dashboard.php', true, 303);
exit;
