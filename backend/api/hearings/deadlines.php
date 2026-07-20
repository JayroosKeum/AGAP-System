<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/HearingController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$caseId = null;
if (isset($_GET['case_id']) && $_GET['case_id'] !== '') {
    $caseId = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$caseId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid case ID is required.']);
        exit;
    }
}

echo json_encode((new HearingController())->deadlines($caseId ? (int) $caseId : null));