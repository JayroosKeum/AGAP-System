<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/auth.php';
startSecureSession();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Method not allowed.'); }
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['login_error'] = 'Invalid request. Please try again.';
    header('Location: ../../../index.php?page=login'); exit;
}
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
if (authenticateAdmin($pdo, $username, $password)) {
    unset($_SESSION['csrf_token'], $_SESSION['login_error']);
    header('Location: ../../../index.php?page=dashboard'); exit;
}
$_SESSION['login_error'] = 'Invalid administrator credentials.';
header('Location: ../../../index.php?page=login'); exit;
