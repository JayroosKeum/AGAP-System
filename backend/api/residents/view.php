<?php
session_start();

require_once '../../controllers/ResidentController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid resident profile ID.']);
    exit;
}

$controller = new ResidentController();
$resident = $controller->show((int) $id);

if (!$resident) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Resident profile not found.']);
    exit;
}

echo json_encode($resident);