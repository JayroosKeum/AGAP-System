<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../models/CaseStage.php';
require_once __DIR__ . '/../../models/CFA.php';

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
$overview['mediation_show_cause_hearings'] = $stageModel->getShowCauseHearings($caseId, 'Mediation');
$overview['conciliation_show_cause_hearings'] = $stageModel->getShowCauseHearings($caseId, 'Conciliation');
$overview['cfa_records'] = (new CFA($db))->getAllByCase($caseId);

$overview['paanyaya_minutes'] = [];
$linkedComplaintId = (int) ($overview['case']['complaint_id'] ?? 0);
if ($linkedComplaintId > 0) {
    try {
        $pmStmt = $db->prepare("
            SELECT minutes_id, stage_type, dialogue_date, complainant_attended, respondent_attended,
                   discussion_summary, agreed_terms, session_remarks, outcome, complainant_choice
            FROM paanyaya_minutes
            WHERE complaint_id = ?
            ORDER BY FIELD(stage_type, 'Paanyaya 1', 'Paanyaya 2'), minutes_id ASC
        ");
        $pmStmt->execute([$linkedComplaintId]);
        $overview['paanyaya_minutes'] = $pmStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $pmErr) {
        error_log('stages/details paanyaya_minutes lookup warning: ' . $pmErr->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'data' => $overview
]);
