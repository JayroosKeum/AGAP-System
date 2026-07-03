<?php

session_start();

require_once '../../controllers/ResidentController.php';

$controller = new ResidentController();

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->update(
        $_POST['resident_id'],
        $_POST
    );

    header(
        'Location: ../../../frontend/pages/residents/resident-list.php'
    );

    exit;
}