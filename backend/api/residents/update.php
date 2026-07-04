<?php

session_start();

require_once '../../controllers/ResidentController.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller = new ResidentController();

    $controller->update(
        $_POST['resident_id'],
        $_POST
    );

    header(
        'Location: ../../../frontend/pages/residents/resident-list.php'
    );

    exit;
}