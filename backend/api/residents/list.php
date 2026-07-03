<?php

require_once '../../controllers/ResidentController.php';

header('Content-Type: application/json');

$controller = new ResidentController();

echo json_encode(
    $controller->index()
);