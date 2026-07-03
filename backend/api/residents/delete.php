<?php

session_start();

require_once '../../controllers/ResidentController.php';

$controller = new ResidentController();

if (isset($_GET['id']))
{
    $controller->destroy($_GET['id']);
}

header(
    'Location: ../../../frontend/pages/residents/resident-list.php'
);

exit;