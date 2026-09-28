<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../services/MediationDeadlineService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Administrators and Lupon Clerks can manage mediation suspensions.']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 1);
$caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid case ID is required.']);
    exit;
}

$deadlineService = new MediationDeadlineService();
$result = $deadlineService->resumeMediation($caseId, $userId);

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
