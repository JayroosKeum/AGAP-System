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

$roleName = (string) ($_SESSION['role_name'] ?? '');
$isResidentPortal = $roleName === 'Resident';
$isStaffIntakeRole = in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true)
    || in_array($roleName, ['Administrator', 'Lupon Clerk', 'BPSO', 'Super Administrator'], true);
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || (!$isStaffIntakeRole && !$isResidentPortal)) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    header('Location: ../../../frontend/pages/auth/login.php');
    exit;
}

$controller = new ComplaintController();
$result = $controller->store($_POST, $_FILES);
$result['message'] = trim((string)($result['message'] ?? '')) ?: (
    !empty($result['success'])
        ? 'Complaint saved successfully.'
        : 'Unable to save the complaint.'
);

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    $errorStatus = in_array($result['code'] ?? '', ['PAANYAYA_HANDOFF_FORBIDDEN', 'FORMAL_MEDIATION_FORBIDDEN'], true) ? 403 : 422;
    http_response_code($result['success'] ? 201 : $errorStatus);
    echo json_encode($result);
    exit;
}

$_SESSION['complaint_flash'] = [
    'type' => $result['success'] ? 'success' : 'error',
    'message' => $result['message'],
];

if (!$result['success']) {
    $_SESSION['complaint_flash']['old'] = $_POST;
    $sourcePaanyayaId = $isResidentPortal ? 0 : (int)($_POST['escalated_from_paanyaya_id'] ?? 0);
    $retryUrl = '../../../frontend/pages/complaints/complaint-create.php';
    if ($sourcePaanyayaId > 0) {
        $retryUrl .= '?escalate_paanyaya_id=' . $sourcePaanyayaId;
    }
    header('Location: ' . $retryUrl);
    exit;
}

if (!empty($result['complaint_id'])) {
    if ($isResidentPortal) {
        header('Location: ../../../frontend/pages/resident/dashboard.php');
        exit;
    }
    if (!empty($result['escalated_from_paanyaya']) || !empty($result['direct_formal_mediation'])) {
        header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . (int)$result['complaint_id'] . '&tab=mediation');
        exit;
    }
    header('Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . (int)$result['complaint_id']);
    exit;
}

header('Location: ../../../frontend/pages/complaints/complaint-list.php');
exit;
