<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Secretary', 'Barangay Secretary', 'Lupon Member', 'LISO', 'Summons Server'], $db);

$paanyayaId = filter_var($_GET['id'] ?? ($_GET['paanyaya_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$paanyayaId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid Paanyaya ID is required.']);
    exit;
}

try {
    $paanyayaModel = new Paanyaya();
    $record = $paanyayaModel->getPaanyayaById((int) $paanyayaId);

    if (!$record) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Paanyaya record not found.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $record
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
