<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../services/PangkatService.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid case_id is required.']);
    exit;
}

$service = new PangkatService();
$data = $service->getPangkatOverview($caseId);

http_response_code(200);
echo json_encode(['success' => true, 'data' => $data]);
