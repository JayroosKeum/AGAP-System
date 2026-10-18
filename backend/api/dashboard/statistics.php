<?php

session_start();
require_once '../../controllers/ReportController.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorized to view dashboard statistics.']);
    exit;
}

try {
    $controller = new ReportController();
    $data = $controller->dashboard();
    echo json_encode(array_merge(['success' => true, 'data' => $data], $data));
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load dashboard statistics.']);
}
