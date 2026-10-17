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

if (!CsrfService::validateRequest()) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing request token.']);
    exit;
}

$caseId = filter_input(INPUT_POST, 'case_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid case ID is required.']);
    exit;
}

$created = (new ArbitrationController())->create($_POST);
http_response_code($created ? 201 : 422);
echo json_encode([
    'success' => (bool) $created,
    'message' => $created ? 'Arbitration award saved.' : 'Unable to save arbitration award.'
]);
