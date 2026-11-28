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

    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../middleware/rbac.php';
    $db = (new Database())->connect();
    if (!agapHasRole(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member', 'Summons Server', 'Resident', 'BPSO'], $db)) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'Unauthorized.'
        ]);
        exit;
    }

    $id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $parsed = json_decode($raw, true);
            if (!empty($parsed['notification_id'])) {
                $id = filter_var($parsed['notification_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            }
        }
    }

    if (!$id) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'success' => false,
            'message' => 'A valid notification is required.'
        ]);
        exit;
    }

    require_once __DIR__ . '/../../controllers/NotificationController.php';

    $result = (new NotificationController())->unread((int) $id, (int) $_SESSION['user_id']);
    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log("Notifications unread.php exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => 'Unable to mark notification as unread.',
        'error_detail' => $e->getMessage()
    ]);
}
