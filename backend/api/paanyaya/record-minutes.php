<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'LISO'], $db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid Complaint ID is required.']);
    exit;
}

$stageType = trim((string) ($input['stage_type'] ?? 'Paanyaya 1'));
if (!in_array($stageType, [Paanyaya::STAGE_1, Paanyaya::STAGE_2], true)) {
    $stageType = Paanyaya::STAGE_1;
}

$proofFile = $_FILES['scanned_minutes'] ?? ($_FILES['proof_file'] ?? null);

try {
    $paanyayaModel = new Paanyaya();
    $userId = agapSessionUserId();

    $result = $paanyayaModel->recordMinutes((int) $complaintId, $stageType, $input, $userId, $proofFile);

    if (empty($result['success'])) {
        http_response_code(422);
    } else {
        $_SESSION['complaint_flash'] = [
            'message' => $result['message'] ?? 'Dialogue minutes recorded successfully!',
            'type' => 'success'
        ];
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('paanyaya/record-minutes error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to save Paanyaya dialogue minutes right now.'
    ]);
    exit;
}
