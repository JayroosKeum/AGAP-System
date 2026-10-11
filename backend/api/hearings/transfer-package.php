<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/HearingController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid case_id parameter is required.']);
    exit;
}

$result = (new HearingController())->getTransferPackage((int) $caseId);
http_response_code($result['success'] ? 200 : 404);
echo json_encode($result);
