<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../../services/LisoDutyService.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if ($userId < 1 || !in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$service = new LisoDutyService();
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? trim((string) ($_GET['action'] ?? 'status'))
    : trim((string) ($_POST['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success' => true,
        'current_session' => $roleId === 3 ? $service->currentForUser($userId) : null,
        'active_members' => $service->activeMembers(),
    ]);
    exit;
}

if ($roleId !== 3 || !in_array($action, ['start', 'end'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Lupon Members may start or end LISO duty.']);
    exit;
}

$result = $action === 'start' ? $service->start($userId) : $service->end($userId);
http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
