<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../services/SummonDeliveryService.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$canManage = in_array((int) $_SESSION['role_id'], [1, 2, 4], true); // Admin, Clerk, Summons Server
$deliveryService = new SummonDeliveryService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$hearingId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid hearing ID is required.']);
        exit;
    }

    $result = $deliveryService->getHearingDeliveries((int) $hearingId);
    http_response_code($result['success'] ? 200 : 404);
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Administrators, Clerks, and Summons Servers can record delivery.']);
        exit;
    }

    $inputData = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $inputData = json_decode($raw, true) ?: [];
    } else {
        $inputData = $_POST;
    }

    $hearingId = filter_var($inputData['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$hearingId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid hearing ID is required.']);
        exit;
    }

    $result = $deliveryService->recordDelivery((int) $hearingId, $inputData, (int) $_SESSION['user_id']);
    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
