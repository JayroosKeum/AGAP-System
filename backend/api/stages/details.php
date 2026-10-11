<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../models/CaseStage.php';

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

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$complaintId = filter_var($_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$db = (new Database())->connect();
if (!$caseId && $complaintId) {
    $cStmt = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
    $cStmt->execute([$complaintId]);
    $caseId = (int) $cStmt->fetchColumn();
}

if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid case_id or complaint_id is required.']);
    exit;
}

$stageModel = new CaseStage($db);
$overview = $stageModel->getStageOverview($caseId);

if (!$overview) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Case record not found.']);
    exit;
}

$overview['mediation_sessions'] = $stageModel->getSessions($caseId, 'Mediation');
$overview['conciliation_sessions'] = $stageModel->getSessions($caseId, 'Conciliation');
$overview['arbitration_sessions'] = $stageModel->getSessions($caseId, 'Arbitration');

echo json_encode([
    'success' => true,
    'data' => $overview
]);
