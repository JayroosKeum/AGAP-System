<?php

session_start();

require_once '../../controllers/ComplaintController.php';

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_GET['format']) && $_GET['format'] === 'json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    header('Location: ../../../frontend/pages/complaints/complaint-list.php');
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    header('Location: ../../../frontend/pages/auth/login.php');
    exit;
}

$complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$controller = new ComplaintController();
$result = $controller->update($complaintId, $_POST);

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

$_SESSION['complaint_flash'] = [
    'type' => $result['success'] ? 'success' : 'error',
    'message' => $result['message'],
];

if (!$result['success']) {
    $_SESSION['complaint_flash']['old'] = $_POST;
    header('Location: ../../../frontend/pages/complaints/complaint-edit.php?id=' . $complaintId);
    exit;
}

header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . $complaintId);
exit;
