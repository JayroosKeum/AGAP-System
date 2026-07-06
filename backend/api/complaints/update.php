<?php

session_start();

require_once '../../controllers/ComplaintController.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller = new ComplaintController();

    $controller->update(
        $_POST['complaint_id'],
        $_POST
    );

    header(
        'Location: ../../../frontend/pages/complaints/complaint-list.php'
    );

    exit;
}