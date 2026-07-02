<?php

require_once '../../controllers/HearingController.php';

$controller = new HearingController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->attendance($_POST);

    echo json_encode([
        'success' => true
    ]);
}