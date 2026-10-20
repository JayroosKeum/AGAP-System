<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$returnId = filter_var($_GET['return_id'] ?? ($_GET['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$returnId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid Return ID is required.']);
    exit;
}

try {
    $paanyayaModel = new Paanyaya();
    $details = $paanyayaModel->getOfficerReturnDetails((int) $returnId);

    if (!$details) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Officer's Return record not found."]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $details
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
    exit;
}
