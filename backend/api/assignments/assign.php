<?php

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

require_once '../../controllers/AssignmentController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

http_response_code(410);
echo json_encode(['success' => false, 'message' => 'Individual role assignment has been replaced by the complete case-team assignment.']);
