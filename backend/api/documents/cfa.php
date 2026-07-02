<?php

require_once '../../controllers/CFAController.php';

$controller = new CFAController();

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->create($_POST);

    echo json_encode([
        'success' => true,
        'message' => 'CFA issued successfully.'
    ]);
}