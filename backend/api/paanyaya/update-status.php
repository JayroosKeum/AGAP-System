<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Secretary', 'Barangay Secretary', 'Summons Server'], $db);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$paanyayaId = filter_var($input['paanyaya_id'] ?? ($input['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$paanyayaId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid Paanyaya ID is required.']);
    exit;
}

$status = trim((string) ($input['status'] ?? ''));
if ($status === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Status is required.']);
    exit;
}

try {
    $paanyayaModel = new Paanyaya();
    $userId = (int) $_SESSION['user_id'];

    $result = $paanyayaModel->updatePaanyayaStatus((int) $paanyayaId, $status, $userId);

    if (!$result['success']) {
        http_response_code(422);
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
    exit;
}
