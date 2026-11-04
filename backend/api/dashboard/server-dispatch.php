<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../controllers/ServerDashboardController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Enforce access control: Admin (1), Lupon Clerk (2), Summons Server (4)
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRoleId = (int) ($_SESSION['role_id'] ?? 0);

if ($currentUserId < 1 || !in_array($currentRoleId, [1, 2, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hindi awtorisado para sa Summons Server dispatch.']);
    exit;
}

try {
    $controller = new ServerDashboardController();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 1-Tap Service Action
        $payload = $_POST;
        if (empty($payload)) {
            $input = file_get_contents('php://input');
            $payload = json_decode($input, true) ?: [];
        }

        $payload['user_id'] = $currentUserId;
        $file = $_FILES['proof_file'] ?? null;

        $result = $controller->logServiceAction($payload, $file);
        http_response_code($result['success'] ? 200 : 422);
        echo json_encode($result);
        exit;
    }

    // GET Request: Retrieve Dashboard Dispatch Data
    $data = $controller->getDashboardData($_GET);
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    error_log('Summons Server API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
