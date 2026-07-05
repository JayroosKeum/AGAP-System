<?php

session_start();

require_once '../../controllers/ResidentController.php';

if (isset($_GET['id']))
{
    $controller = new ResidentController();

    $controller->destroy($_GET['id']);
}

header(
    'Location: ../../../frontend/pages/residents/resident-list.php'
);

exit;