<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/GPSController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$result = (new GPSController())->reopenSummonsNotice((int) $caseId, (int) $documentId, (int) $_SESSION['user_id']);
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
