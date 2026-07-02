<?php

require_once '../../controllers/ComplaintController.php';

$id = $_GET['id'];

$controller = new ComplaintController();

header('Content-Type: application/json');

echo json_encode(
    $controller->show($id)
);