<?php
session_start();
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    die('Access Denied');
}
$dashboardLink = [1 => '../dashboard/admin-dashboard.php', 2 => '../dashboard/clerk-dashboard.php'][$roleId] ?? '../auth/login.php';
header('Location: ' . $dashboardLink);
exit;
