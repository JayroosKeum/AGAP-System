<?php

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['message' => 'Unauthorized.']);
    exit;
}

require_once '../../controllers/AssignmentController.php';
echo json_encode((new AssignmentController())->luponMembers());
