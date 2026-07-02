<?php

require_once '../../controllers/PangkatController.php';

header('Content-Type: application/json');

$controller = new PangkatController();

echo json_encode(
    $controller->members(
        $_GET['pangkat_id']
    )
);