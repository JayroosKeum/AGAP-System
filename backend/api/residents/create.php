<?php

session_start();

require_once '../../controllers/ResidentController.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller = new ResidentController();

    $result = $controller->store($_POST);

    if ($result)
    {
        header(
            'Location: ../../../frontend/pages/residents/resident-list.php'
        );
        exit;
    }

    die('Failed to save resident.');
}