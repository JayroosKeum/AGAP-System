<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../controllers/PangkatController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$pangkatId = filter_var($_POST['pangkat_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$memberId = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$position = trim((string) ($_POST['position'] ?? ''));
if (!$pangkatId || !$memberId || !in_array($position, ['Chairman', 'Secretary', 'Member'], true)) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'Provide a valid Pangkat member and position.']); exit; }
$success = (new PangkatController())->addMember((int) $pangkatId, (int) $memberId, $position);
http_response_code($success ? 201 : 422); echo json_encode($success ? ['success' => true, 'message' => 'Pangkat member added.'] : ['success' => false, 'message' => 'Unable to add this Pangkat member.']);
