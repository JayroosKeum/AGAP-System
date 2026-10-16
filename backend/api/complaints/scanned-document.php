<?php

session_start();

require_once __DIR__ . '/../../models/ComplaintScannedDocument.php';
require_once __DIR__ . '/../../services/AuditService.php';

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_GET['format']) && $_GET['format'] === 'json');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    header('Location: ../../../frontend/pages/auth/login.php');
    exit;
}

$model = new ComplaintScannedDocument();
$audit = new AuditService();

// ==========================================
// GET REQUESTS: PREVIEW, DOWNLOAD, HISTORY, CURRENT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $docId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $complaintId = filter_var($_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = trim((string) ($_GET['action'] ?? ''));

    // Download or Preview a specific scan document by doc_id
    if ($docId) {
        $record = $model->getById($docId);
        if (!$record) {
            http_response_code(404);
            echo 'Scanned complaint document not found.';
            exit;
        }

        $root = dirname(__DIR__, 2);
        $absolutePath = $root . '/' . $record['file_path'];

        if (!is_file($absolutePath)) {
            http_response_code(404);
            echo 'Document file is missing from storage.';
            exit;
        }

        $isDownload = ($action === 'download' || isset($_GET['download']));
        $disposition = $isDownload ? 'attachment' : 'inline';
        $fileName = $record['file_name'];
        $mime = $record['file_type'] ?: 'application/octet-stream';

        if ($isDownload) {
            $audit->log((int) $_SESSION['user_id'], "Downloaded Scanned KP Form 7 (v{$record['version_number']})", 'Complaints', (int)$record['complaint_id']);
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($fileName) . '"');
        header('Content-Length: ' . filesize($absolutePath));
        header('Cache-Control: private, max-age=3600');
        readfile($absolutePath);
        exit;
    }

    // Version history for a complaint
    if ($complaintId && $action === 'history') {
        header('Content-Type: application/json; charset=utf-8');
        $history = $model->getHistoryByComplaint($complaintId);
        echo json_encode([
            'success' => true,
            'complaint_id' => $complaintId,
            'history' => $history
        ]);
        exit;
    }

    // Current active scan for a complaint
    if ($complaintId) {
        header('Content-Type: application/json; charset=utf-8');
        $current = $model->getCurrentByComplaint($complaintId);
        echo json_encode([
            'success' => true,
            'complaint_id' => $complaintId,
            'has_scan' => ($current !== null),
            'scan' => $current
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

// ==========================================
// POST REQUESTS: UPLOAD OR REPLACE SCAN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Only Admin (1) and Lupon Clerk (2) can upload/replace formal complaint scans
    if (!in_array((int) $_SESSION['role_id'], [1, 2], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied. Only Lupon Clerks and Administrators can manage formal complaint documents.']);
        exit;
    }

    $complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$complaintId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Select a valid complaint.']);
        exit;
    }

    $file = null;
    if (!empty($_FILES['scan_file']) && (int)($_FILES['scan_file']['error'] ?? 0) === UPLOAD_ERR_OK) {
        $file = $_FILES['scan_file'];
    } elseif (!empty($_FILES['scanned_kp7']) && (int)($_FILES['scanned_kp7']['error'] ?? 0) === UPLOAD_ERR_OK) {
        $file = $_FILES['scanned_kp7'];
    }

    if (!$file) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Please select a valid scanned document file (PDF, JPG, or PNG).']);
        exit;
    }

    $reason = !empty($_POST['replacement_reason']) ? trim((string)$_POST['replacement_reason']) : null;
    $caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

    $result = $model->upload($complaintId, $file, (int)$_SESSION['user_id'], $reason, $caseId);

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit;
