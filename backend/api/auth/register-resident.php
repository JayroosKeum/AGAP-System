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

$isQuickMode = (($_POST['account_mode'] ?? '') === 'quick');
$auth = new AuthController();
$result = $isQuickMode ? $auth->registerQuickResident($_POST) : $auth->registerResident($_POST);
if (!$result['success']) {
    $code = in_array(($result['code'] ?? ''), ['validation', 'duplicate', 'google_required'], true)
        ? $result['code']
        : 'unavailable';
    $modeParam = $isQuickMode ? '&mode=quick' : '';
    header('Location: ../../../frontend/pages/resident/register.php?error=' . rawurlencode($code) . $modeParam, true, 303);
    exit;
}

if ($isQuickMode && !empty($_SESSION['user_id']) && ($_SESSION['role_name'] ?? '') === 'Resident') {
    $_SESSION['resident_flash'] = [
        'type' => 'success',
        'title' => 'Unverified Resident Account Created',
        'message' => 'Your auto-generated handle is ' . ($result['username'] ?? 'RES-TMN') . ' (Unverified / Pending Verification). Save this handle for signing in. Complete your profile or submit a grievance below.',
    ];
    header('Location: ../../../frontend/pages/resident/dashboard.php', true, 303);
    exit;
}

$notice = !empty($result['google_verified']) ? 'email_verified' : 'registered';
header('Location: ../../../frontend/pages/resident/login.php?notice=' . $notice, true, 303);
exit;
