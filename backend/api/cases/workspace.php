<?php

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../../config/database.php';
require_once '../../middleware/rbac.php';
require_once '../../controllers/CaseController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member'], $db);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$id) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'A valid case is required.'
    ]);
    exit;
}

agapRequireCaseAccess($db, (int) $id, 'read', true);

$result = (new CaseController())->workspace((int) $id);

http_response_code($result['success'] ? 200 : 404);
echo json_encode($result);
