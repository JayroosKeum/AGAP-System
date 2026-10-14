<?php

require_once '../../controllers/AIController.php';

header('Content-Type: application/json');

$controller =
    new AIController();

$response =
    $controller->chatbot(
        $_POST['message']
    );

echo json_encode(
    $response
);