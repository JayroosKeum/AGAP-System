<?php
declare(strict_types=1);
require_once __DIR__ . '/../../services/auth.php';
startSecureSession();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['logout_csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400); exit('Invalid request.');
}
logoutAdmin();
header('Location: ../../../index.php'); exit;
