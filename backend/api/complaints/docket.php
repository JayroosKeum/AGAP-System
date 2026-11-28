<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/ComplaintController.php';
require_once '../../services/CsrfService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
CsrfService::enforceForApiRequest();
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Only the Lupon Clerk or Administrator may formally docket a complaint.']); exit; }
$raw = file_get_contents('php://input');
$payload = (is_string($raw) && trim($raw) !== '' && str_starts_with(trim($raw), '{'))
    ? (json_decode($raw, true) ?: [])
    : $_POST;
$complaintId = filter_var($payload['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid complaint is required.']); exit; }
$result = (new ComplaintController())->docket((int) $complaintId, (int) $_SESSION['user_id'], is_array($payload) ? $payload : []);
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
