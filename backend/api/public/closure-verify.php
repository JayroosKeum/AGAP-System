<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/ClosureWorkflowService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($input)) {
    $input = $_POST;
}

$decision = (string) ($input['decision'] ?? $input['action'] ?? '');
$token = isset($input['verification_token']) ? (string) $input['verification_token'] : (isset($input['token']) ? (string) $input['token'] : null);
$otp = isset($input['otp']) ? (string) $input['otp'] : null;
$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$objectionReason = (string) ($input['objection_reason'] ?? $input['reason'] ?? '');

if (($token === null || trim($token) === '') && ($otp === null || trim($otp) === '')) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A one-time verification token or OTP is required.']);
    exit;
}

$service = new ClosureWorkflowService((new Database())->connect());
$result = $service->respond($decision, $complaintId ? (int) $complaintId : null, null, $token, $otp, $objectionReason);
$code = (int) ($result['http_code'] ?? (!empty($result['success']) ? 200 : 422));
unset($result['http_code']);

http_response_code($code);
echo json_encode($result);
