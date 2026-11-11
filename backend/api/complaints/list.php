<?php

session_start();

require_once __DIR__ . '/../../controllers/ComplaintController.php';
require_once __DIR__ . '/../../middleware/rbac.php';

header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->connect();
$roleName = agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'BPSO', 'LISO', 'Resident'], $db);

$controller = new ComplaintController();

echo json_encode(
    $controller->index([
        'user_id' => agapSessionUserId(),
        'role_name' => $roleName,
    ])
);
