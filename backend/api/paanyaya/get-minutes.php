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

$complaintId = filter_var($_GET['id'] ?? ($_GET['complaint_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid Complaint ID is required.']);
    exit;
}

$stageType = isset($_GET['stage_type']) ? trim((string) $_GET['stage_type']) : null;

try {
    $paanyayaModel = new Paanyaya();
    if ($stageType && in_array($stageType, [Paanyaya::STAGE_1, Paanyaya::STAGE_2], true)) {
        $data = $paanyayaModel->getStageMinutes((int) $complaintId, $stageType);
    } else {
        $data = $paanyayaModel->getAllMinutes((int) $complaintId);
    }

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching Paanyaya minutes: ' . $e->getMessage()
    ]);
    exit;
}
