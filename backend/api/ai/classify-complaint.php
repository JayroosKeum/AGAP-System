<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../controllers/AIController.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/AiRequestRateLimit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

CsrfService::enforceForApiRequest();

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'LISO', 'Secretary', 'Barangay Secretary'], $db);

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
if (!is_string($narrative) || mb_strlen(trim($narrative)) < 10 || mb_strlen(trim($narrative)) > 15000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Narrative must contain 10 to 15,000 characters.']);
    exit;
}

$requestedResolution = is_string($payload['requested_resolution'] ?? null) ? $payload['requested_resolution'] : '';
$mode = trim((string) ($payload['processing_mode'] ?? 'local_only'));
$hasConsent = in_array($payload['external_ai_consent'] ?? null, [true, 1, '1', 'true'], true);
$useGemini = ($mode === 'gemini' && $hasConsent);

if ($useGemini && !AiRequestRateLimit::consume(20, 300)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait a moment before requesting another AI classification check.']);
    exit;
}

$result = (new AIController())->classifyComplaint($db, $narrative, $requestedResolution, $useGemini);
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
