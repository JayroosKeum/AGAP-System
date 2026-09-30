<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../services/ShowCauseService.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Administrators and Clerks can evaluate show-cause hearings.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $showCauseService = new ShowCauseService();
    $result = $showCauseService->evaluateShowCause((int) $hearingId, $inputData, (int) $_SESSION['user_id']);
    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
