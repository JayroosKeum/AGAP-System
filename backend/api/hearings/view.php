<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once '../../controllers/HearingController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member'], $db);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Hearing not found.']);
    exit;
}
agapRequireHearingAccess($db, (int) $id, 'read', true);

$result = (new HearingController())->show((int) $id);
http_response_code($result['success'] ? 200 : 404);
echo json_encode($result);