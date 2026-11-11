<?php

session_start();

require_once __DIR__ . '/../../controllers/ComplaintController.php';
require_once __DIR__ . '/../../middleware/rbac.php';

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

$db = (new Database())->connect();
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !agapHasRole(['Administrator', 'Lupon Clerk', 'BPSO', 'Resident'], $db)) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(isset($_SESSION['user_id']) ? 403 : 401);
        echo json_encode(['success' => false, 'message' => isset($_SESSION['user_id']) ? 'Unauthorized for complaint intake.' : 'Authentication required.']);
        exit;
    }
    header('Location: ../../../frontend/pages/auth/login.php');
    exit;
}

$roleName = agapGetRoleName($db);
$payload = $_POST;
// Derive Source of Complaint strictly from the authenticated intake channel.
$payload['complaint_source'] = ($roleName === 'Resident') ? 'Online' : 'Walk-in';
$payload['created_by'] = agapSessionUserId();

$controller = new ComplaintController();
$result = $controller->store($payload, $_FILES);
$result['message'] = trim((string) ($result['message'] ?? '')) ?: (
    !empty($result['success'])
        ? 'Complaint saved successfully.'
        : 'Unable to save the complaint.'
);

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($result['success'] ? 201 : 422);
    echo json_encode($result);
    exit;
}

$_SESSION['complaint_flash'] = [
    'type' => $result['success'] ? 'success' : 'error',
    'message' => $result['message'],
];

if ($roleName === 'Resident') {
    if (!$result['success']) {
        $_SESSION['complaint_flash']['old'] = $_POST;
    }
    header('Location: ../../../frontend/pages/complaints/resident-portal.php');
    exit;
}

if (!$result['success']) {
    $_SESSION['complaint_flash']['old'] = $_POST;
    $sourcePaanyayaId = (int) ($_POST['escalated_from_paanyaya_id'] ?? 0);
    $retryUrl = '../../../frontend/pages/complaints/complaint-create.php';
    if ($sourcePaanyayaId > 0) {
        $retryUrl .= '?escalate_paanyaya_id=' . $sourcePaanyayaId;
    }
    header('Location: ' . $retryUrl);
    exit;
}

if (!empty($result['complaint_id'])) {
    header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . (int) $result['complaint_id']);
    exit;
}

header('Location: ../../../frontend/pages/complaints/complaint-list.php');
exit;
