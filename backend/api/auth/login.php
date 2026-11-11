<?php

require_once '../../services/SecurityBootstrap.php';
SecurityBootstrap::applyHttpHeaders();
require_once '../../controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../frontend/pages/auth/login.php', true, 303);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$loginPortal = (string) ($_POST['login_portal'] ?? 'staff');
$auth = new AuthController();

if ($username === '' || $password === '' || !$auth->login($username, $password)) {
    $errCode = ($username !== '' && $password !== '') ? $auth->getLastLoginError() : 'invalid_credentials';
    $errQuery = 'error=' . $errCode;
    if ($errCode === 'account_locked') {
        $errQuery .= '&minutes=' . max(1, $auth->getLockoutMinutes());
    }
    $failRedirect = match ($loginPortal) {
        'resident_portal' => '../../../frontend/pages/complaints/resident-portal.php?' . $errQuery,
        'resident' => '../../../frontend/pages/auth/login.php?portal=resident&' . $errQuery,
        default => '../../../frontend/pages/auth/login.php?' . $errQuery,
    };
    header('Location: ' . $failRedirect, true, 303);
    exit;
}

$roleName = (string) ($_SESSION['role_name'] ?? '');
if ($roleName === 'BPSO' || $roleName === 'LISO') {
    header('Location: ../../../frontend/pages/complaints/complaint-list.php', true, 303);
    exit;
}
if ($roleName === 'Resident') {
    header('Location: ../../../frontend/pages/complaints/resident-portal.php', true, 303);
    exit;
}

switch ((int) $_SESSION['role_id']) {
    case 1:
        header('Location: ../../../frontend/pages/dashboard/admin-dashboard.php', true, 303);
        break;
    case 2:
    case 5:
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
