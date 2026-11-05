<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
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

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only authorized personnel may draft hearing minutes.']);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 30000);
if ($raw === false || strlen($raw) > 30000) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Request is too large.']);
    exit;
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
    exit;
}

$hearingId = filter_var($payload['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sourceText = trim((string) ($payload['source_text'] ?? ''));
if (!$hearingId || mb_strlen($sourceText) < 20 || mb_strlen($sourceText) > 20000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Provide a valid hearing and 20 to 20,000 characters of source notes.']);
    exit;
}
if (!in_array($payload['external_ai_consent'] ?? null, [true, 1, '1', 'true'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Confirm that hearing notes may be processed by Google Gemini.']);
    exit;
}
if (!AiRequestRateLimit::consume()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait a moment before requesting another AI draft.']);
    exit;
}

try {
    $db = (new Database())->connect();
    $stmt = $db->prepare('SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, c.case_id, c.case_number FROM hearings h INNER JOIN cases c ON c.case_id = h.case_id WHERE h.hearing_id = ?');
    $stmt->execute([$hearingId]);
    $hearing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hearing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Hearing session not found.']);
        exit;
    }

    $result = (new AIController())->draftHearingMinutes($hearing, $sourceText);
    if ($result['success']) {
        (new AuditService())->log((int) $_SESSION['user_id'], 'Generated AI hearing-minutes draft (review required)', 'Hearings', $hearingId);
        echo json_encode($result);
        exit;
    }

    http_response_code(422);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('AI hearing-minutes draft error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to draft hearing minutes right now.']);
}

