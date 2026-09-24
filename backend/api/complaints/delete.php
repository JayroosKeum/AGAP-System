<?php

session_start();

require_once '../../controllers/ComplaintController.php';

if (isset($_GET['id']))
{
    $controller = new ComplaintController();

    $controller->destroy((int) $_GET['id']);
}

header(
    'Location: ../../../frontend/pages/complaints/complaint-list.php'
);

exit;