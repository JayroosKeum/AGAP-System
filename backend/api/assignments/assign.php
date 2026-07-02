<?php

require_once '../../controllers/AssignmentController.php';

$controller = new AssignmentController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->assign($_POST);

    echo json_encode([
        'success' => true
    ]);
}