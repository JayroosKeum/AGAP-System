<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/AIController.php';
require_once __DIR__ . '/../../services/ComplaintClassificationService.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/AiRequestRateLimit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

CsrfService::enforceForApiRequest();

$isStaff = isset($_SESSION['role_id']) && in_array((int) $_SESSION['role_id'], [1, 2], true);
$isResident = (($_SESSION['role_name'] ?? '') === 'Resident');
if (!isset($_SESSION['user_id']) || (!$isStaff && !$isResident)) {
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

$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$narrative = $payload['narrative'] ?? null;
if (!is_string($narrative) || mb_strlen(trim($narrative)) < 15 || mb_strlen(trim($narrative)) > 15000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Narrative must contain 15 to 15,000 characters.']);
    exit;
}
if (!in_array($payload['external_ai_consent'] ?? null, [true, 1, '1', 'true'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Confirm that this narrative may be sent to Google Gemini for processing.']);
    exit;
}
if (!AiRequestRateLimit::consume(20, 300)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait a moment before requesting another title suggestion.']);
    exit;
}

$language = (($payload['language'] ?? 'tl') === 'en') ? 'en' : 'tl';
$result = (new AIController())->suggestComplaintTitle($narrative, $language);
if (!empty($result['success'])) {
    try {
        $db = (new Database())->connect();
        $suggestion = ComplaintClassificationService::suggestFromNarrative($db, $narrative);
        $result['suggested_category_id'] = $suggestion['primary']['category_id'] ?? null;
        $result['suggested_category_name'] = $suggestion['primary']['category_name'] ?? null;
        $result['suggested_case_type'] = $suggestion['case_type'] ?? 'Civil';
    } catch (Throwable) {
        // Non-fatal: title suggestion still succeeds even if advisory classification fails.
    }
}
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
