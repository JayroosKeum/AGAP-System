<?php

require_once '../../controllers/AuthController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../frontend/pages/resident/login.php', true, 303);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$auth = new AuthController();

if ($username === '' || $password === '' || !$auth->loginResident($username, $password)) {
    header('Location: ../../../frontend/pages/resident/login.php?error=invalid_credentials', true, 303);
    exit;
}

header('Location: ../../../frontend/pages/resident/dashboard.php', true, 303);
exit;
