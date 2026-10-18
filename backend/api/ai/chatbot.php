<?php

session_start();

require_once __DIR__ . '/../../controllers/AIController.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/AiRequestRateLimit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

CsrfService::enforceForApiRequest();

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$maxRequestBytes = 65536;
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxRequestBytes) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Request is too large.']);
    exit;
}

$rawInput = file_get_contents('php://input', false, null, 0, $maxRequestBytes + 1);
if ($rawInput === false || strlen($rawInput) > $maxRequestBytes) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Request is too large.']);
    exit;
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
$payload = str_starts_with($contentType, 'application/json')
    ? json_decode($rawInput, true)
    : $_POST;
if ($rawInput === '' && $payload === []) {
    $payload = $_POST;
}
if (!is_array($payload)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid request body is required.']);
    exit;
}

$message = $payload['message'] ?? null;
if (!is_string($message) || trim($message) === '' || mb_strlen(trim($message)) > 4000 || !mb_check_encoding($message, 'UTF-8')) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Message must contain 1 to 4,000 characters.']);
    exit;
}
if (!in_array($payload['external_ai_consent'] ?? null, [true, 1, '1', 'true'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Confirm that this message may be sent to Google Gemini for processing.']);
    exit;
}
if (!AiRequestRateLimit::consume()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait a few minutes before making another AI request.']);
    exit;
}

$response = (new AIController())->chatbot($message);
http_response_code($response['success'] ? 200 : 502);
echo json_encode($response);
