<?php
session_start();
if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}
if (!in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    exit('Access denied for this role.');
}

header('Location: ../cases/case-list.php', true, 303);
exit;
