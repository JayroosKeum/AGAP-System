<?php
// Stable entry point for dashboard links and older bookmarks.
session_start();
if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}
$roleId = (int) $_SESSION['role_id'];
if ($roleId === 4) {
    header('Location: ../gps/proof-service.php');
    exit;
}
if (!in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    exit('Access denied for this role.');
}
require_once __DIR__ . '/../../../backend/config/database.php';
$db = (new Database())->connect();
$caseId = filter_var($_GET['case_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$complaintId = filter_var($_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$stmt = $db->prepare($caseId ? 'SELECT case_id, complaint_id, case_status FROM cases WHERE case_id = ?' : 'SELECT case_id, complaint_id, case_status FROM cases WHERE complaint_id = ?');
$stmt->execute([$caseId ?: $complaintId]);
$case = $stmt->fetch();
if (!$case) {
    header('Location: ' . ($roleId === 3 ? '../dashboard/lupon-dashboard.php' : 'case-list.php'));
    exit;
}
$page = match ($case['case_status']) {
    'Conciliation', 'Pangkat' => 'pangkat-workspace.php',
    'Arbitration', 'Arbitration Award' => 'arbitration-workspace.php',
    default => 'mediation-workspace.php',
};
$query = ['case_id' => (int) $case['case_id'], 'complaint_id' => (int) $case['complaint_id']];
$hearingId = filter_var($_GET['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($hearingId) $query['hearing_id'] = $hearingId;
header('Location: ' . $page . '?' . http_build_query($query), true, 302);
exit;
