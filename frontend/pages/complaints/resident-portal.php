<?php

session_start();

if (($_SESSION['role_name'] ?? '') === 'Resident') {
    header('Location: ../resident/dashboard.php', true, 303);
    exit;
}

$destination = ($_GET['tab'] ?? '') === 'register' ? 'register.php' : 'login.php';
header('Location: ../resident/' . $destination, true, 303);
exit;
