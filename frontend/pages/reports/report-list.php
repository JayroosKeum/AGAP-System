<?php
session_start();
if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}
$roleId = (int) $_SESSION['role_id'];
if (!in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    exit('Access denied for this role.');
}
$dashboardLink = [1 => '../dashboard/admin-dashboard.php', 2 => '../dashboard/clerk-dashboard.php'][$roleId] ?? '../auth/login.php';
header('Location: ' . $dashboardLink);
exit;
