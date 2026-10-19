<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only authorized personnel can escalate disputes to formal docketing.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

$complaintId = filter_var($input['complaint_id'] ?? $input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$db = (new Database())->connect();

if (!$complaintId && $caseId) {
    $cStmt = $db->prepare("SELECT complaint_id FROM cases WHERE case_id = ? LIMIT 1");
    $cStmt->execute([$caseId]);
    $complaintId = (int) $cStmt->fetchColumn();
}

if (!$complaintId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid complaint_id or case_id is required.']);
    exit;
}

$notes = trim((string) ($input['notes'] ?? $input['resolution_notes'] ?? $input['outcome_notes'] ?? 'Escalated to formal Katarungang Pambarangay proceedings.'));
$userId = (int) $_SESSION['user_id'];

$escalationData = [
    'mediation_date' => !empty($input['mediation_date']) ? trim((string)$input['mediation_date']) : null,
    'mediation_time' => !empty($input['mediation_time']) ? trim((string)$input['mediation_time']) : null,
    'venue' => !empty($input['venue']) ? trim((string)$input['venue']) : 'Lupon Court Room',
];

$paanyayaModel = new Paanyaya();
$result = $paanyayaModel->escalateToFormalDocketing($complaintId, $userId, $notes, $escalationData);

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
