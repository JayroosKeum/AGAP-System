<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../controllers/AiServiceController.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/AiRequestRateLimit.php';

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
$db = (new Database())->connect();
$roleName = agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'BPSO', 'LISO', 'Resident'], $db);
$userId = agapSessionUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Only POST is accepted.']);
    exit;
}

CsrfService::enforceForApiRequest();

$enforceConsentAndQuota = static function (mixed $consent) use ($roleName, $userId): void {
    if (!in_array($consent, [true, 1, '1', 'true'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Confirm that this recording may be sent to Google Gemini for processing.']);
        exit;
    }
    $allowed = ($roleName === 'Resident')
        ? !empty(AiRequestRateLimit::consumeScoped("resident_transcribe_{$userId}", 5, 600)['allowed'])
        : AiRequestRateLimit::consume();
    if (!$allowed) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Please wait a few minutes before making another AI request.']);
        exit;
    }
};

$maxRequestBytes = (int) (ceil((25 * 1024 * 1024) / 3) * 4) + 4096;
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxRequestBytes) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Audio request exceeds the 25MB limit.']);
    exit;
}

$file = $_FILES['audio'] ?? ($_FILES['file'] ?? null);

if (!$file || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
    $rawInput = file_get_contents('php://input', false, null, 0, $maxRequestBytes + 1);
    if ($rawInput === false || strlen($rawInput) > $maxRequestBytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Audio request exceeds the 25MB limit.']);
        exit;
    }
    $json = json_decode($rawInput, true);
    if (is_array($json) && !empty($json['audio_base64'])) {
        $mime = $json['mime_type'] ?? 'audio/webm';
        if (!is_string($mime)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid audio MIME type.']);
            exit;
        }
        $enforceConsentAndQuota($json['external_ai_consent'] ?? null);
        $languageHint = is_string($json['language'] ?? null) ? trim($json['language']) : '';
        $result = (new AiServiceController())->transcribeAudio($json['audio_base64'], $mime, $languageHint);
        http_response_code($result['success'] ? 200 : 422);
        echo json_encode($result);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No audio file or recording received.']);
    exit;
}

$enforceConsentAndQuota($_POST['external_ai_consent'] ?? null);
$languageHint = is_string($_POST['language'] ?? null) ? trim($_POST['language']) : '';

$controller = new AiServiceController();
$result = $controller->transcribeAudio($file, '', $languageHint);

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
exit;
