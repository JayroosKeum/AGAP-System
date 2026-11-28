<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/BpsoIntakeService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = (new Database())->connect();
agapRequireApiRoles(['BPSO', 'Administrator', 'Super Administrator'], $db);

$raw = file_get_contents('php://input');
$payload = (is_string($raw) && trim($raw) !== '' && str_starts_with(trim($raw), '{'))
    ? json_decode($raw, true)
    : $_POST;
if (!is_array($payload)) {
    $payload = $_POST;
}

$complaintId = filter_var($payload['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid complaint ID is required.']);
    exit;
}

$action = trim((string) ($payload['action'] ?? ''));
$result = (new BpsoIntakeService($db))->reviewOnlineIntake((int) $complaintId, $action, $payload, agapSessionUserId());
http_response_code(!empty($result['success']) ? 200 : 422);
echo json_encode($result);
