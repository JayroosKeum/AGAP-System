<?php

/**
 * Pangkat Duty Groups API (standing duty roster).
 *
 * GET                       → list groups (?active=1 for the referral picker)
 * POST {action: "save", …}  → create / update a group   (Administrator / Clerk)
 * POST {action: "delete", duty_group_id} → delete a group (Administrator / Clerk)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../services/PangkatDutyGroupService.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$roleId = (int) ($_SESSION['role_id'] ?? 0);

if ($userId <= 0 || !in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

try {
    $service = new PangkatDutyGroupService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $activeOnly = !empty($_GET['active']);
        echo json_encode(['success' => true, 'data' => $service->listGroups($activeOnly)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    if (!in_array($roleId, [1, 2], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the Administrator or Lupon Clerk can manage Pangkat duty groups.']);
        exit;
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = $input['action'] ?? 'save';
    $result = $action === 'delete'
        ? $service->deleteGroup((int) ($input['duty_group_id'] ?? 0), $userId)
        : $service->saveGroup($input, $userId);

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('Pangkat duty groups API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process the Pangkat duty group request.']);
}
