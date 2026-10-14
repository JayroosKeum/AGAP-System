<?php

require_once '../../controllers/AIController.php';

header('Content-Type: application/json');

$controller =
    new AIController();

$response =
    $controller->narrative(
        $_POST
    );

echo json_encode(
    $response
);