<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../controllers/CaseController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member', 'Summons Server', 'Resident'], $db);

$controller = new CaseController();

if (isset($_GET['page'])) {
    $page = filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($page === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Page must be a positive whole number.']);
        exit;
    }

    echo json_encode($controller->page((int) $page));
    exit;
}

echo json_encode($controller->index());
