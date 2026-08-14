<?php
session_start(); header('Content-Type: application/json; charset=utf-8'); require_once '../../controllers/AuthController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required.']); exit; }
$data = json_decode(file_get_contents('php://input'), true); if (!is_array($data)) $data = $_POST;
$result = (new AuthController())->changePassword($data, (int) $_SESSION['user_id']); http_response_code($result['success'] ? 200 : 422); echo json_encode($result);
