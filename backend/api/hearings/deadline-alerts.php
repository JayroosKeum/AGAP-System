<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/HearingController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
echo json_encode((new HearingController())->dispatchDeadlineAlerts((int) $_SESSION['user_id']));
