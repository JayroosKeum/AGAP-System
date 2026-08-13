<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../controllers/PangkatController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid case is required.']); exit; }
$id = (new PangkatController())->create((int) $caseId);
http_response_code($id ? 201 : 422); echo json_encode($id ? ['success' => true, 'pangkat_id' => $id] : ['success' => false, 'message' => 'This case already has a Pangkat group or could not be found.']);
