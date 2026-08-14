<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/AuthController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
$data = json_decode(file_get_contents('php://input'), true); if (!is_array($data)) $data = $_POST;
$result = (new AuthController())->requestPasswordReset($data);
http_response_code($result['success'] ? 200 : 422); echo json_encode($result);
