<?php

require_once '../../controllers/GPSController.php';

$controller = new GPSController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->saveLocation($_POST);

    echo json_encode([
        'success' => true
    ]);
}