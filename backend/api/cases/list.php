<?php

require_once '../../controllers/CaseController.php';

header('Content-Type: application/json');

$controller = new CaseController();

echo json_encode(
    $controller->index()
);