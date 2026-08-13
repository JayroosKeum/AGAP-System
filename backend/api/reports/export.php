<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../controllers/ReportController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$result = (new ReportController())->export($input, (int) $_SESSION['user_id']);
http_response_code($result['success'] ? 201 : 422); echo json_encode($result);
