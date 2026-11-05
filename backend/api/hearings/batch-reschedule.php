<?php
/**
 * Punong Barangay Unavailable Batch Reschedule API
 * Reschedules all active hearings for a given date to the next available Tuesday.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../controllers/HearingController.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$userId = (int) $_SESSION['user_id'];

// Enforce access control: Admin (1) and Lupon Clerk (2)
if (!in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Only Administrators and Lupon Clerks can batch-reschedule hearings.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

$controller = new HearingController();
$result = $controller->batchReschedulePbUnavailable($input, $userId);

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
exit;
