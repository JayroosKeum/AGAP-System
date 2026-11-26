<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../controllers/ComplaintController.php';
require_once __DIR__ . '/../../services/CsrfService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator'], $db);

if (!CsrfService::validateRequest()) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing request token.']);
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    $id = filter_input(INPUT_POST, 'complaint_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
}
if (!$id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid complaint ID is required.']);
    exit;
}

$reason = trim((string) ($_POST['reason'] ?? 'Restored by Super Administrator'));
$success = (new ComplaintController())->restore((int) $id, $reason);
http_response_code($success ? 200 : 422);
echo json_encode([
    'success' => $success,
    'message' => $success ? 'Complaint restored successfully.' : 'Unable to restore complaint.',
]);
