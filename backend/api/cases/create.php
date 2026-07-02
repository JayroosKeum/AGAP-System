<?php

require_once '../../controllers/CaseController.php';

$controller = new CaseController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->store($_POST);

    header(
        'Location: ../../../frontend/pages/cases/case-list.php'
    );

    exit;
}