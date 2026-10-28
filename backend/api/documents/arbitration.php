<?php

session_start();

require_once __DIR__ . '/../../controllers/ArbitrationController.php';
require_once __DIR__ . '/../../services/CsrfService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

// CSRF check if token is present or HTTP header is used
if (!empty($_SERVER['HTTP_X_CSRF_TOKEN']) || !empty($input['csrf_token'])) {
    if (!CsrfService::validateRequest()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing request token.']);
        exit;
    }
}

$caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid case ID is required.']);
    exit;
}

$result = (new ArbitrationController())->create($input);
http_response_code(!empty($result['success']) ? 200 : 422);
echo json_encode($result);
