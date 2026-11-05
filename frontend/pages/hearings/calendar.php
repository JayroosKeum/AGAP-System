<?php
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$dashboard = match ($roleId) {
    1 => '../dashboard/admin-dashboard.php#dashboardCalendarCard',
    2 => '../dashboard/clerk-dashboard.php#miniCalendarCard',
    3 => '../dashboard/lupon-dashboard.php#luponDutyHearingsContainer',
    4 => '../dashboard/server-dashboard.php#dispatchTasksContainer',
    default => '../auth/login.php',
};

header('Location: ' . $dashboard);
exit;
