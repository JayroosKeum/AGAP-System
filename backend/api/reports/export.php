<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../controllers/ReportController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!agapHasRole(['Super Administrator', 'Administrator', 'Lupon Clerk'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$controller = new ReportController();
$userId = (int) $_SESSION['user_id'];

// Route by format parameter
$format = strtolower(trim((string) ($input['format'] ?? '')));

if ($format === 'kp_monitoring_excel') {
    // KP Monitoring Form Excel (.xlsx) export
    $result = $controller->exportKpMonitoring($input, $userId);
} else {
    // Default: 1-row-per-case Excel (.xlsx) register with the requested 21 columns
    $result = $controller->exportCaseRegister($input, $userId);
}

http_response_code($result['success'] ? 201 : 422);
echo json_encode($result);
