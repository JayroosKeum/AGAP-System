<?php

require_once '../../controllers/ArbitrationController.php';

$controller = new ArbitrationController();

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->create($_POST);

    echo json_encode([
        'success' => true,
        'message' => 'Arbitration award saved.'
    ]);
}