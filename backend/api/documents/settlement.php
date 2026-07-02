<?php

require_once '../../controllers/SettlementController.php';

$controller = new SettlementController();

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->create($_POST);

    echo json_encode([
        'success' => true,
        'message' => 'Settlement saved successfully.'
    ]);
}