<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'Method not allowed.'
        ]);
        exit;
    }

    if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4, 5, 6], true)) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'Unauthorized.'
        ]);
        exit;
    }

    require_once __DIR__ . '/../../controllers/NotificationController.php';

    $result = (new NotificationController())->markAllRead((int) $_SESSION['user_id']);
    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log("Notifications mark-all-read.php exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Unable to mark all notifications as read.',
        'error_detail' => $e->getMessage()
    ]);
}
