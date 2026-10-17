<?php

session_start();

require_once __DIR__ . '/../../controllers/ComplaintController.php';
require_once __DIR__ . '/../../services/CsrfService.php';

header('Content-Type: application/json; charset=utf-8');

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

if (!CsrfService::validateRequest()) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing request token.']);
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid complaint ID is required.']);
    exit;
}

$success = (new ComplaintController())->destroy((int) $id);
http_response_code($success ? 200 : 422);
echo json_encode([
    'success' => $success,
    'message' => $success ? 'Complaint deleted successfully.' : 'Unable to delete complaint.'
]);

exit;
