<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../controllers/SearchController.php';

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'BPSO', 'LISO'], $db);

echo json_encode((new SearchController())->records($_GET));
