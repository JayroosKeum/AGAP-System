<?php

require_once '../../controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../frontend/pages/resident/register.php', true, 303);
    exit;
}

$result = (new AuthController())->registerResident($_POST);
if (!$result['success']) {
    $code = in_array(($result['code'] ?? ''), ['validation', 'duplicate'], true)
        ? $result['code']
        : 'unavailable';
    header('Location: ../../../frontend/pages/resident/register.php?error=' . rawurlencode($code), true, 303);
    exit;
}

header('Location: ../../../frontend/pages/resident/login.php?registered=1', true, 303);
exit;
