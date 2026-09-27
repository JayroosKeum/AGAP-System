<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../models/Summons.php';
require_once '../../services/AuditService.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$hearingId = filter_var($_POST['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$hearingId) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid hearing is required.']); exit; }
$result = (new Summons())->issueFollowUpForNonAppearance((int) $hearingId, (int) $_SESSION['user_id']);
if ($result['success']) (new AuditService())->log((int) $_SESSION['user_id'], 'Issued follow-up summons after non-appearance', 'Summons', (int) $result['document_id']);
http_response_code($result['success'] ? 201 : 422);
echo json_encode($result);
