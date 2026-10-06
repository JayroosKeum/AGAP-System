<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/SettlementController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$installmentId = filter_var($input['installment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$installmentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid installment_id is required.']);
    exit;
}

$controller = new SettlementController();
$result = $controller->recordPayment((int) $installmentId, $input, (int) $_SESSION['user_id']);
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
