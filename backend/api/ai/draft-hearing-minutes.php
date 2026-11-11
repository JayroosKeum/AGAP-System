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
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'LISO'], $db);

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
$complaintId = filter_var($payload['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$stageType = trim((string) ($payload['stage_type'] ?? 'Paanyaya 1'));
$sourceText = trim((string) ($payload['source_text'] ?? ''));

if ((!$hearingId && !$complaintId) || mb_strlen($sourceText) < 20 || mb_strlen($sourceText) > 20000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Provide a valid session/complaint and 20 to 20,000 characters of source notes.']);
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
    if ($hearingId) {
        $stmt = $db->prepare('SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, c.case_id, c.case_number FROM hearings h INNER JOIN cases c ON c.case_id = h.case_id WHERE h.hearing_id = ?');
        $stmt->execute([$hearingId]);
        $sessionContext = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sessionContext) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Hearing session not found.']);
            exit;
        }
        $auditModule = 'Hearings';
        $auditId = $hearingId;
    } else {
        $cStmt = $db->prepare('SELECT complaint_id, complaint_number, complaint_title FROM complaints WHERE complaint_id = ? LIMIT 1');
        $cStmt->execute([$complaintId]);
        $comp = $cStmt->fetch(PDO::FETCH_ASSOC);
        if (!$comp) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Complaint not found.']);
            exit;
        }
        $sessionContext = [
            'hearing_type' => in_array($stageType, ['Paanyaya 1', 'Paanyaya 2'], true) ? $stageType : 'Paanyaya 1',
            'hearing_date' => date('Y-m-d'),
            'venue' => 'Tanggapan ng Barangay Tumana',
            'case_number' => $comp['complaint_number'] ?: ('CMP-' . $complaintId),
        ];
        $auditModule = 'Paanyaya';
        $auditId = $complaintId;
    }

    $result = (new AIController())->draftHearingMinutes($sessionContext, $sourceText);
    if (!empty($result['success'])) {
        (new AuditService())->log(agapSessionUserId(), 'Generated AI session-minutes draft (review required)', $auditModule, $auditId);
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
