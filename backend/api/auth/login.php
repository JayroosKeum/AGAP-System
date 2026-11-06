<?php

require_once '../../controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../frontend/pages/auth/login.php', true, 303);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$auth = new AuthController();

if ($username === '' || $password === '' || !$auth->login($username, $password)) {
    header('Location: ../../../frontend/pages/auth/login.php?error=invalid_credentials', true, 303);
    exit;
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
