<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../../models/CaseProgress.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$complaintId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Valid complaint ID is required.']);
    exit;
}

$progress = (new CaseProgress())->getCaseProgress((int) $complaintId);
if (!$progress) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Complaint not found.']);
    exit;
}

echo json_encode(['success' => true, 'data' => $progress]);
