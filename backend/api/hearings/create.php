<?php

require_once '../../controllers/HearingController.php';

$controller = new HearingController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->create($_POST);

    header(
        'Location: ../../../frontend/pages/hearings/schedules.php'
    );

    exit;
}