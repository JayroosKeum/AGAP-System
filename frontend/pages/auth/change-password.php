<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php', true, 303);
    exit;
}

header('Location: ../settings/index.php?tab=password', true, 303);
exit;
