<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../controllers/ReportController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
if (!agapHasRole(['Super Administrator', 'Administrator', 'Lupon Clerk'])) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$result = (new ReportController())->generate(['type' => 'Annual', 'year' => $_GET['year'] ?? null]);
http_response_code($result['success'] ? 200 : 422); echo json_encode($result);
