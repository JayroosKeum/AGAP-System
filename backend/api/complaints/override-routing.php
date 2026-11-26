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
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member'], $db);

if (!CsrfService::validateRequest()) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing request token.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($input)) {
    $input = $_POST;
}

$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$newQueue = trim((string) ($input['assigned_queue'] ?? ''));
$reason = trim((string) ($input['reason'] ?? ''));
$newRoute = isset($input['routing_route']) ? trim((string) $input['routing_route']) : null;

if (!$complaintId || $newQueue === '' || $reason === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'complaint_id, assigned_queue, and reason are required.']);
    exit;
}

agapRequireComplaintAccess($db, (int) $complaintId, 'write', true);

$result = (new ComplaintController())->overrideRouting((int) $complaintId, $newQueue, $reason, $newRoute);
http_response_code(!empty($result['success']) ? 200 : 422);
echo json_encode($result);
