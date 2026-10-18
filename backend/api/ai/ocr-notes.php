<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../controllers/AiServiceController.php';
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

$maxRequestBytes = (int) (ceil((15 * 1024 * 1024) / 3) * 4) + 4096;
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxRequestBytes) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Image request exceeds the 15MB limit.']);
    exit;
}

$file = $_FILES['image'] ?? ($_FILES['file'] ?? null);

if (!$file || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
    $rawInput = file_get_contents('php://input', false, null, 0, $maxRequestBytes + 1);
    if ($rawInput === false || strlen($rawInput) > $maxRequestBytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Image request exceeds the 15MB limit.']);
        exit;
    }
    $json = json_decode($rawInput, true);
    if (is_array($json) && !empty($json['image_base64'])) {
        $consent = $json['external_ai_consent'] ?? null;
        $mime = $json['mime_type'] ?? 'image/jpeg';
        if (!in_array($consent, [true, 1, '1', 'true'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Confirm that these notes may be sent to Google Gemini for processing.']);
            exit;
        }
        if (!is_string($mime) || !AiRequestRateLimit::consume()) {
            http_response_code(!is_string($mime) ? 422 : 429);
            echo json_encode(['success' => false, 'message' => !is_string($mime) ? 'Invalid image MIME type.' : 'Please wait a few minutes before making another AI request.']);
            exit;
        }
        $result = (new AiServiceController('gemini-1.5-flash'))->ocrImage($json['image_base64'], $mime);
        http_response_code($result['success'] ? 200 : 422);
        echo json_encode($result);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No image file uploaded.']);
    exit;
}

$consent = $_POST['external_ai_consent'] ?? null;
if (!in_array($consent, [true, 1, '1', 'true'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Confirm that these notes may be sent to Google Gemini for processing.']);
    exit;
}
if (!AiRequestRateLimit::consume()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait a few minutes before making another AI request.']);
    exit;
}

$controller = new AiServiceController('gemini-1.5-flash');
$result = $controller->ocrImage($file);

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
exit;
