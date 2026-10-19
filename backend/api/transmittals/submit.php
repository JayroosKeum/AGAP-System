<?php

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing case ID.']);
    exit;
}

require_once __DIR__ . '/../../models/CaseTransmittal.php';

try {
    $transmittalModel = new CaseTransmittal();
    $result = $transmittalModel->recordSubmission(
        $caseId,
        $_POST,
        $_FILES['receiving_copy'] ?? null,
        (int) $_SESSION['user_id']
    );

    if (!$result['success']) {
        http_response_code(400);
    }

    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to record transmittal submission: ' . $e->getMessage()
    ]);
}
