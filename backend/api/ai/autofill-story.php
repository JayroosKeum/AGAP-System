<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../controllers/AIController.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/AiRequestRateLimit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

CsrfService::enforceForApiRequest();

$db = (new Database())->connect();
$roleName = agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'BPSO', 'LISO', 'Resident'], $db);
$userId = agapSessionUserId();

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

$spokenText = $payload['spoken_text'] ?? ($payload['narrative'] ?? null);
if (!is_string($spokenText) || mb_strlen(trim($spokenText)) < 10 || mb_strlen(trim($spokenText)) > 15000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please speak or enter at least 10 characters (up to 15,000) before autofilling.']);
    exit;
}

$langRaw = strtolower(trim((string) ($payload['language'] ?? 'tl')));
$language = in_array($langRaw, ['en', 'en-ph', 'english'], true) ? 'en' : 'tl';

$processingMode = (($payload['processing_mode'] ?? 'gemini') === 'local_only') ? 'local_only' : 'gemini';
$allowExternalAi = ($processingMode === 'gemini');

if ($allowExternalAi && !in_array($payload['external_ai_consent'] ?? null, [true, 1, '1', 'true'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Check the Gemini consent box before using external AI, or uncheck it to use the built-in local organizer.'
    ]);
    exit;
}

$audit = new AuditService($db);

if ($roleName === 'Resident') {
    $rl = $allowExternalAi
        ? AiRequestRateLimit::consumeScoped("resident_gemini_story_{$userId}", 6, 600)
        : AiRequestRateLimit::consumeScoped("resident_local_story_{$userId}", 20, 300);
} else {
    $rl = AiRequestRateLimit::consumeScoped("staff_story_{$userId}", 20, 300);
}

if (empty($rl['allowed'])) {
    $retryMin = max(1, (int) ceil(($rl['retry_after'] ?? 60) / 60));
    $audit->log($userId, "Rate-limited AI story autofill ({$roleName} | {$processingMode})", 'Complaints', null);
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'rate_limited' => true,
        'retry_after' => (int) ($rl['retry_after'] ?? 60),
        'message' => $allowExternalAi
            ? "AI quota limit reached ({$rl['limit']} requests max per window). Wait about {$retryMin} minute(s) or uncheck Gemini to use the local story organizer."
            : "Please wait about {$retryMin} minute(s) before requesting another autofill."
    ]);
    exit;
}

try {
    $result = (new AIController())->autofillComplaintStory($spokenText, $language, $allowExternalAi);
    if (!empty($result['success'])) {
        $src = (string) ($result['source'] ?? 'local_fallback');
        $audit->log($userId, "Autofilled complaint story ({$src} | {$language} | {$roleName})", 'Complaints', null);
        $result['rate_limit'] = [
            'remaining' => (int) ($rl['remaining'] ?? 0),
            'limit' => (int) ($rl['limit'] ?? 0),
        ];
        http_response_code(200);
        echo json_encode($result);
        exit;
    }

    http_response_code(422);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('autofill-story error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to organize your complaint story right now.']);
}
