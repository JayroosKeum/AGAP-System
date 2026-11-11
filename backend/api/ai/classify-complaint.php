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
$roleName = agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'BPSO', 'LISO', 'Resident'], $db);
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

// Strict data minimization: accept ONLY narrative text, optional relief sought, processing mode, consent, and context.
$narrative = $payload['narrative'] ?? null;
if (!is_string($narrative) || mb_strlen(trim($narrative)) < 10 || mb_strlen(trim($narrative)) > 15000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Narrative must contain 10 to 15,000 characters.']);
    exit;
}

$requestedResolution = $payload['requested_resolution'] ?? '';
if (!is_string($requestedResolution) || mb_strlen(trim($requestedResolution)) > 5000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Requested resolution must be 5,000 characters or fewer.']);
    exit;
}

$processingMode = (($payload['processing_mode'] ?? 'gemini') === 'local_only') ? 'local_only' : 'gemini';
$allowExternalAi = ($processingMode === 'gemini');

if ($allowExternalAi && !in_array($payload['external_ai_consent'] ?? null, [true, 1, '1', 'true'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Data Privacy Act consent required: confirm before sending narrative text to Google Gemini, or choose Local Offline Suggestion.'
    ]);
    exit;
}

$contextTag = match ((string) ($payload['context'] ?? '')) {
    'liso_screening' => 'LISO Screening',
    'resident_portal' => 'Resident Portal',
    default => 'Intake',
};

$auditComplaintId = null;
if ($roleName !== 'Resident' && !empty($payload['complaint_id'])) {
    $cid = filter_var($payload['complaint_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($cid) {
        $chk = $db->prepare('SELECT complaint_id FROM complaints WHERE complaint_id = ? LIMIT 1');
        $chk->execute([$cid]);
        if ($chk->fetchColumn()) {
            $auditComplaintId = (int) $cid;
        }
    }
}

$audit = new AuditService($db);

// Strict rate limiting: Residents get a strict 3 Gemini requests per 10 minutes quota (and 8/5min local fallback)
if ($roleName === 'Resident') {
    $rl = $allowExternalAi
        ? AiRequestRateLimit::consumeScoped("resident_gemini_classify_{$userId}", 3, 600)
        : AiRequestRateLimit::consumeScoped("resident_local_classify_{$userId}", 8, 300);
} else {
    $rl = AiRequestRateLimit::consumeScoped("staff_classify_{$userId}", 15, 300);
}

if (empty($rl['allowed'])) {
    $retryMin = max(1, (int) ceil(($rl['retry_after'] ?? 60) / 60));
    $audit->log($userId, "Rate-limited AI classification request ({$roleName} | {$processingMode})", 'Complaints', $auditComplaintId);
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'rate_limited' => true,
        'retry_after' => (int) ($rl['retry_after'] ?? 60),
        'message' => $allowExternalAi
            ? "AI quota limit reached ({$rl['limit']} requests max per window). Wait about {$retryMin} minute(s) or use Local Rule Suggestion."
            : "Please wait about {$retryMin} minute(s) before requesting another suggestion."
    ]);
    exit;
}

try {
    $stmt = $db->query("
        SELECT category_id, category_name, tagalog_name, description
        FROM complaint_categories
        WHERE COALESCE(is_active, 1) = 1
        ORDER BY
            CASE
                WHEN category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%' THEN 1
                ELSE 0
            END ASC,
            category_name ASC
    ");
    $categories = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $result = (new AIController())->suggestComplaintClassification(
        $narrative,
        $categories,
        $requestedResolution,
        $allowExternalAi
    );

    if (!empty($result['success'])) {
        $src = (string) ($result['source'] ?? 'keyword_fallback');
        $audit->log(
            $userId,
            "Requested advisory classification ({$src} | {$roleName} | {$contextTag})",
            'Complaints',
            $auditComplaintId
        );
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
    error_log('classify-complaint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to classify narrative right now. Please try again.']);
}
