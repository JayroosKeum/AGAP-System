<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/ClosureWorkflowService.php';
require_once __DIR__ . '/../../services/CsrfService.php';

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member', 'Resident'], $db);
$service = new ClosureWorkflowService($db);
$roleName = agapGetRoleName($db);
$userId = agapSessionUserId();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $complaintId = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$complaintId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid complaint_id is required.']);
        exit;
    }
    agapRequireComplaintAccess($db, (int) $complaintId, 'read', true);
    echo json_encode([
        'success' => true,
        'history' => $service->getHistoryForComplaint((int) $complaintId),
        'evidence' => $roleName !== 'Resident' ? (new ClosureEvidenceService($db))->forComplaint((int) $complaintId) : [],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $action = strtolower(trim((string) ($input['action'] ?? 'request')));
    $complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

    if ($action === 'request') {
        if (!in_array($roleName, ['Super Administrator', 'Administrator', 'Lupon Clerk'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only authorized staff may request Pending Close.']);
            exit;
        }
        agapRequireComplaintAccess($db, (int) $complaintId, 'write', true);
        // Strip untrusted client-supplied expiry overrides and never expose complainant OTP/token to staff.
        unset($input['expires_at_override']);
        $result = $service->requestPendingClose((int) $complaintId, $userId, $input);
        unset($result['verification_token'], $result['verification_otp']);
        http_response_code(!empty($result['success']) ? 201 : 422);
        echo json_encode($result);
        exit;
    }

    if (in_array($action, ['confirm', 'object'], true)) {
        if ($complaintId > 0) {
            agapRequireComplaintAccess($db, (int) $complaintId, 'read', true);
        }
        $result = $service->respond(
            $action,
            $complaintId > 0 ? (int) $complaintId : null,
            $userId,
            isset($input['verification_token']) ? (string) $input['verification_token'] : null,
            isset($input['otp']) ? (string) $input['otp'] : null,
            (string) ($input['objection_reason'] ?? $input['reason'] ?? '')
        );
        $code = (int) ($result['http_code'] ?? (!empty($result['success']) ? 200 : 422));
        unset($result['http_code']);
        http_response_code($code);
        echo json_encode($result);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid closure workflow action.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
