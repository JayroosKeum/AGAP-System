<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/GPSController.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required.']); exit; }
if (!in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$controller = new GPSController();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $complaintId = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    echo json_encode($complaintId ? $controller->location((int) $complaintId) : $controller->locations()); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!in_array((int) $_SESSION['role_id'], [1, 2, 4], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Only field-service staff may save incident locations.']); exit; }
$data = json_decode(file_get_contents('php://input'), true); if (!is_array($data)) $data = $_POST;
$result = $controller->saveLocation($data, (int) $_SESSION['user_id']); http_response_code($result['success'] ? 200 : 422); echo json_encode($result);
