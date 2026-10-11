<?php

session_start();

require_once '../../controllers/ComplaintController.php';

$id = isset($_POST['complaint_id'])
    ? (int) $_POST['complaint_id']
    : (isset($_POST['id'])
        ? (int) $_POST['id']
        : (isset($_GET['id']) ? (int) $_GET['id'] : 0));

$success = false;
if ($id > 0) {
    $controller = new ComplaintController();
    $success = $controller->archive($id);
}

// Support AJAX requests if needed
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Complaint archived successfully.' : 'Failed to archive complaint.'
    ]);
    exit;
}

header('Location: ../../../frontend/pages/complaints/complaint-list.php?status=archived');
exit;
