<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../controllers/AiServiceController.php';

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

$file = $_FILES['image'] ?? ($_FILES['file'] ?? null);

if (!$file || !isset($file['tmp_name']) || empty($file['tmp_name'])) {
    // Check if raw base64 data was sent via JSON
    $rawInput = file_get_contents('php://input');
    $json = json_decode($rawInput, true);
    if (is_array($json) && !empty($json['image_base64'])) {
        $controller = new AiServiceController('gemini-1.5-flash');
        $mime = $json['mime_type'] ?? 'image/jpeg';
        $result = $controller->ocrImage($json['image_base64'], $mime);
        http_response_code($result['success'] ? 200 : 422);
        echo json_encode($result);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No image file uploaded.']);
    exit;
}

$controller = new AiServiceController('gemini-1.5-flash');
$result = $controller->ocrImage($file);

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
exit;
