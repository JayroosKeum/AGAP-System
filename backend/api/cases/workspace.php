<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/CaseController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid case is required.']); exit; }
$result = (new CaseController())->workspace((int) $id);
http_response_code($result['success'] ? 200 : 404);
echo json_encode($result);
