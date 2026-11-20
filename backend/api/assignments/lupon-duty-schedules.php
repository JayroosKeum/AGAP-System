<?php

/**
 * Monday–Friday Daily Lupon Duty Assignment API.
 *
 * GET                                  -> list active Lupon Members + Mon–Fri assignments (Admin, Clerk, Lupon Member)
 * POST {action: "assign", ...}         -> assign/update a Lupon Member on a weekday (Admin, Clerk)
 * POST {action: "remove", id|user_id}  -> remove a Lupon Member from a weekday (Admin, Clerk)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../services/LuponDutyScheduleService.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$roleId = (int) ($_SESSION['role_id'] ?? 0);

if ($userId <= 0 || !in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

try {
    $service = new LuponDutyScheduleService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['success' => true, 'data' => $service->getRoster()]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    if (!in_array($roleId, [1, 2], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the Administrator or Lupon Clerk can manage Lupon duty schedules.']);
        exit;
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = trim((string) ($input['action'] ?? 'assign'));
    $result = match ($action) {
        'remove', 'delete' => $service->removeAssignment($input, $userId),
        default => $service->assignMember($input, $userId),
    };

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('Lupon duty schedules API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process the Lupon duty schedule request.']);
}
