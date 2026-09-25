<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../controllers/AIController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$windowStarted = (int) ($_SESSION['ai_narrative_window_started'] ?? 0);
$requests = (int) ($_SESSION['ai_narrative_requests'] ?? 0);
if ($windowStarted < (time() - 300)) {
    $windowStarted = time();
    $requests = 0;
}
if ($requests >= 10) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Please wait a few minutes before requesting another AI enhancement.']);
    exit;
}

$_SESSION['ai_narrative_window_started'] = $windowStarted;
$_SESSION['ai_narrative_requests'] = $requests + 1;

$result = (new AIController())->refineComplaintNarrative((string) ($payload['narrative'] ?? ''));
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
