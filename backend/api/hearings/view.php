<?php

require_once '../../controllers/HearingController.php';

header('Content-Type: application/json');

$controller = new HearingController();

echo json_encode(
    $controller->show($_GET['id'] ?? 0)
);
