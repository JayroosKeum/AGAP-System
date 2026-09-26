<?php

require_once '../../controllers/ComplaintController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$controller = new ComplaintController();

echo json_encode(
    $controller->show((int) ($_GET['id'] ?? 0))
);