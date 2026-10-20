<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../controllers/ReportController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
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

if ($format === 'register_excel') {
    // Case Register Excel export (1-row-per-case with summons timeliness)
    $result = $controller->exportCaseRegister($input, $userId);
} else {
    // Default: standard summary CSV
    $result = $controller->export($input, $userId);
}

http_response_code($result['success'] ? 201 : 422);
echo json_encode($result);
