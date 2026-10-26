<?php

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/CaseTransmittal.php';

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$complaintId = filter_var($_GET['complaint_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$db = (new Database())->connect();

if (!$caseId && $complaintId) {
    $cStmt = $db->prepare("SELECT case_id FROM cases WHERE complaint_id = ? ORDER BY case_id DESC LIMIT 1");
    $cStmt->execute([$complaintId]);
    $caseId = (int) $cStmt->fetchColumn() ?: null;
}

if (!$caseId) {
    // If no linked case yet, case is still in pre-docketing or unassigned
    echo json_encode([
        'success' => true,
        'is_resolved' => false,
        'is_locked' => true,
        'data' => null,
        'message' => 'No active case record found for this complaint.'
    ]);
    exit;
}

try {
    $transmittalModel = new CaseTransmittal($db);
    $transmittal = $transmittalModel->createOrGetTransmittal($caseId);

    if (!$transmittal) {
        // Case is still active in hearings (Mediation / Conciliation) and not yet formally resolved
        echo json_encode([
            'success' => true,
            'is_resolved' => false,
            'is_locked' => true,
            'data' => null,
            'case_id' => $caseId,
            'message' => 'Case is pending formal resolution (KP Form 16, 15, or 20).'
        ]);
        exit;
    }

    $details = $transmittalModel->getTransmittalFullDetails($caseId);

    echo json_encode([
        'success' => true,
        'is_resolved' => true,
        'is_locked' => (bool)($details['transmittal']['is_locked'] ?? true),
        'data' => $details['transmittal'],
        'details' => $details
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve transmittal status: ' . $e->getMessage()
    ]);
}
