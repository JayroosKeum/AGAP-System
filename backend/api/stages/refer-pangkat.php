<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../models/CaseStage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only authorized personnel can execute referral to Pangkat.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid case_id is required.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$stageModel = new CaseStage();
$result = $stageModel->referToPangkat($caseId, $input, $userId);

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
