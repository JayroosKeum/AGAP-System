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

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing case ID.']);
    exit;
}

require_once __DIR__ . '/../../models/CaseTransmittal.php';

try {
    $transmittalModel = new CaseTransmittal();
    $details = $transmittalModel->getTransmittalFullDetails($caseId);

    echo json_encode([
        'success' => true,
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
