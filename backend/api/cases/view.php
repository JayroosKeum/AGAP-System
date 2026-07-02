<?php

require_once '../../controllers/CaseController.php';

$id = $_GET['id'];

$controller = new CaseController();

header('Content-Type: application/json');

echo json_encode(
    $controller->show($id)
);