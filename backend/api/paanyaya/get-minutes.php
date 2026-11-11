<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'LISO'], $db);

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

    $carryover = $paanyayaModel->getCarryoverContext((int) $complaintId, $stageType ?: Paanyaya::STAGE_1);

    echo json_encode([
        'success' => true,
        'data' => $data,
        'carryover' => $carryover
    ]);
    exit;
} catch (Throwable $e) {
    error_log('paanyaya/get-minutes error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching Paanyaya minutes.'
    ]);
    exit;
}
