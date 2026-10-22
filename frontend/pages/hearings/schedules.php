<?php
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$dashboard = match ($roleId) {
    1 => '../dashboard/admin-dashboard.php',
    2 => '../dashboard/clerk-dashboard.php',
    3 => '../dashboard/lupon-dashboard.php',
    4 => '../dashboard/server-dashboard.php',
    default => '../auth/login.php',
};

header('Location: ' . $dashboard);
exit;
