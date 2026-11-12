<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/AiRequestRateLimit.php';

// Verify authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Only POST is accepted.']);
    exit;
}

CsrfService::enforceForApiRequest();

$maxRequestBytes = (int) (ceil((25 * 1024 * 1024) / 3) * 4) + 4096;
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxRequestBytes) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Audio request exceeds the 25MB limit.']);
    exit;
}

$audio = $_FILES['audio'] ?? ($_FILES['file'] ?? null);
$mime = '';
$consent = $_POST['external_ai_consent'] ?? null;

if ($audio === null) {
    $rawInput = file_get_contents('php://input', false, null, 0, $maxRequestBytes + 1);
    if ($rawInput === false || strlen($rawInput) > $maxRequestBytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Audio request exceeds the 25MB limit.']);
        exit;
    }
    $json = json_decode($rawInput, true);
    $audio = is_string($json['audio_base64'] ?? null) ? $json['audio_base64'] : null;
    $mime = $json['mime_type'] ?? 'audio/webm';
    $consent = $json['external_ai_consent'] ?? null;
}

if ((!is_array($audio) && (!is_string($audio) || trim($audio) === '')) || !is_string($mime)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No valid audio recording was received.']);
    exit;
}

if (!in_array($consent, [true, 1, '1', 'true'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Confirm that this recording may be sent to Google Gemini for processing.']);
    exit;
}
if (is_array($audio) && in_array($audio['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'This recording exceeds the server’s upload limit. Try a shorter clip.']);
    exit;
}
if (!AiRequestRateLimit::consume()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait a few minutes before making another AI request.']);
    exit;
}

require_once __DIR__ . '/../../controllers/AiServiceController.php';
$controller = new AiServiceController();
session_write_close();
$result = $controller->transcribeAudio($audio, $mime);

if (!$result['success'] && array_key_exists('http_status', $result)) {
    $rateLimited = $result['http_status'] === 429;
    http_response_code($rateLimited ? 429 : 502);
    echo json_encode([
        'success' => false,
        'message' => $rateLimited
            ? 'Gemini’s transcription limit was reached. Try again later.'
            : 'Gemini couldn’t process this recording. Try again or type the narrative.',
    ]);
    exit;
}
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
exit;
