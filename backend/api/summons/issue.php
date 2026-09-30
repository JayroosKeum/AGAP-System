<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../models/Summons.php';
require_once '../../services/AuditService.php';
require_once '../../services/ComplaintLifecycleService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Administrators and Lupon Clerks can issue summons.']);
    exit;
}

$complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId && !empty($_POST['case_id'])) {
    $caseId = (int) $_POST['case_id'];
    require_once '../../config/database.php';
    $db = (new Database())->connect();
    $stmt = $db->prepare('SELECT complaint_id FROM cases WHERE case_id = ?');
    $stmt->execute([$caseId]);
    $complaintId = (int) $stmt->fetchColumn();
}

if (!$complaintId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid complaint ID is required to issue a summons.']);
    exit;
}

$lifecycleStatus = (new ComplaintLifecycleService())->forComplaint((int) $complaintId);
if (in_array($lifecycleStatus, [
    ComplaintLifecycleService::CONCILIATION,
    ComplaintLifecycleService::CFA,
    ComplaintLifecycleService::CLOSED
], true)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'A summons cannot be issued after the complaint has entered Conciliation or a later status.'
    ]);
    exit;
}

$mediationData = [
    'mediation_date' => trim((string) ($_POST['mediation_date'] ?? '')),
    'mediation_time' => trim((string) ($_POST['mediation_time'] ?? '')),
    'venue' => trim((string) ($_POST['venue'] ?? 'Barangay Hall')),
    'remarks' => trim((string) ($_POST['remarks'] ?? ''))
];

$summons = new Summons();
$result = $summons->issueFirstSummon((int) $complaintId, (int) $_SESSION['user_id'], $mediationData);

if ($result['success']) {
    $audit = new AuditService();
    $auditAction = ($result['already_issued'] ?? false)
        ? sprintf('Re-opened Summons #%d for Case %s', $result['summons_number'] ?? 1, $result['case_number'] ?? '')
        : sprintf('Issued Summons #%d and scheduled 1st Mediation for Case %s', $result['summons_number'] ?? 1, $result['case_number'] ?? '');
    $audit->log((int) $_SESSION['user_id'], $auditAction, 'Summons', $result['document_id'] ?? null);
}

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
