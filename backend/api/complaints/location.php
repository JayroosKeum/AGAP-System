<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/ComplaintController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = $_POST;
$complaintId = filter_var($data['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid complaint is required.']); exit; }
$result = (new ComplaintController())->saveIncidentLocation((int) $complaintId, $data);
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
