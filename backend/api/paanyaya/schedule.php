<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../models/Paanyaya.php';

header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Secretary', 'Barangay Secretary', 'LISO'], $db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$inviteeName = trim((string) ($input['invitee_name'] ?? ($input['name'] ?? '')));
$inviteeFirst = trim((string) ($input['invitee_first_name'] ?? ''));
$inviteeLast = trim((string) ($input['invitee_last_name'] ?? ''));

if (!$complaintId && $inviteeName === '' && ($inviteeFirst === '' && $inviteeLast === '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Either a valid Complaint ID or Invitee Name is required.']);
    exit;
}

try {
    $paanyayaModel = new Paanyaya();
    $userId = agapSessionUserId();

    $result = $paanyayaModel->schedule($input, $userId);

    if (empty($result['success'])) {
        http_response_code(422);
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('paanyaya/schedule error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to schedule Paanyaya right now.'
    ]);
    exit;
}
