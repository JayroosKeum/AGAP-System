<?php

session_start();

require_once '../../controllers/ComplaintController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../frontend/pages/complaints/complaint-list.php');
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    header('Location: ../../../frontend/pages/auth/login.php');
    exit;
}

$controller = new ComplaintController();
$result = $controller->store($_POST);

$_SESSION['complaint_flash'] = [
    'type' => $result['success'] ? 'success' : 'error',
    'message' => $result['message'],
];

if (!$result['success']) {
    $_SESSION['complaint_flash']['open_modal'] = 'add';
    $_SESSION['complaint_flash']['old'] = $_POST;
}

header('Location: ../../../frontend/pages/complaints/complaint-list.php');
exit;
