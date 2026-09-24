<?php

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once '../../controllers/AssignmentController.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required.'
    ]);
    exit;
}

if (!in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

$caseId = filter_input(
    INPUT_GET,
    'case_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if (!$caseId) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'A valid case is required.'
    ]);
    exit;
}

$controller = new AssignmentController();
echo json_encode($controller->list((int) $caseId));
