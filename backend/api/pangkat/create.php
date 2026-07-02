<?php

require_once '../../controllers/PangkatController.php';

$controller = new PangkatController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $id = $controller->create(
        $_POST['case_id']
    );

    echo json_encode([
        'pangkat_id' => $id
    ]);
}