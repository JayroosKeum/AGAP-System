<?php

require_once '../../config/session.php';
require_once '../../controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../frontend/pages/auth/login.php', true, 303);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$auth = new AuthController();

if ($username === '' || $password === '' || !$auth->login($username, $password)) {
    $errUrl = '../../../frontend/pages/auth/login.php?error=invalid_credentials';
    if ($returnTo !== '') {
        $errUrl .= '&return_to=' . urlencode($returnTo);
    }
    header('Location: ' . $errUrl, true, 303);
    exit;
}

// If return_to is provided and safe, redirect there
if ($returnTo !== '') {
    if (preg_match('#^/(frontend/pages/|backend/)#i', $returnTo) || preg_match('#^\.\./#', $returnTo)) {
        header('Location: ' . $returnTo, true, 303);
        exit;
    }
}

switch ((int) $_SESSION['role_id']) {
    case 1:
        header('Location: ../../../frontend/pages/dashboard/admin-dashboard.php', true, 303);
        break;
    case 2:
        header('Location: ../../../frontend/pages/dashboard/clerk-dashboard.php', true, 303);
        break;
    case 3:
        header('Location: ../../../frontend/pages/dashboard/lupon-dashboard.php', true, 303);
        break;
    case 4:
        header('Location: ../../../frontend/pages/dashboard/server-dashboard.php', true, 303);
        break;
    default:
        session_unset();
        session_destroy();
        header('Location: ../../../frontend/pages/auth/login.php?error=invalid_credentials', true, 303);
        break;
}

exit;