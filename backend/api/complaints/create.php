<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/auth.php';
require_once __DIR__ . '/../../services/complaints.php';

startSecureSession();
requireAdmin();

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
    header('Location: ../../../index.php?page=complaints');
    exit;
}

// CSRF check
$csrf = (string)($_POST['csrf_token'] ?? '');
if (!empty($_SESSION['complaint_csrf']) && !hash_equals($_SESSION['complaint_csrf'], $csrf)) {
    // Also check logout_csrf_token as fallback
    if (empty($_SESSION['logout_csrf_token']) || !hash_equals($_SESSION['logout_csrf_token'], $csrf)) {
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
            exit;
        }
        $_SESSION['complaint_flash'] = ['type' => 'error', 'message' => 'Security token expired. Please try again.', 'old' => $_POST];
        header('Location: ../../../index.php?page=complaints-create');
        exit;
    }
}

$actorId = (int)($_SESSION['admin_id'] ?? 1);
$service = new ComplaintService($pdo);
$result = $service->create($_POST, $actorId, $_FILES);

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode([
        'success' => $result['ok'],
        'complaint_id' => $result['id'] ?? null,
        'message' => $result['message'] ?? ($result['ok'] ? 'Complaint filed successfully.' : 'Unable to file complaint.'),
        'errors' => $result['errors'] ?? [],
        'redirect' => $result['ok'] ? 'index.php?page=complaints&action=view&id=' . $result['id'] : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$result['ok']) {
    $_SESSION['complaint_flash'] = [
        'type' => 'error',
        'message' => $result['message'] ?? 'Please correct the highlighted fields.',
        'errors' => $result['errors'] ?? [],
        'old' => $_POST
    ];
    header('Location: ../../../index.php?page=complaints-create');
    exit;
}

$_SESSION['complaint_flash'] = [
    'type' => 'success',
    'message' => 'Complaint filed successfully.'
];
header('Location: ../../../index.php?page=complaints&action=view&id=' . (int)$result['id']);
exit;
