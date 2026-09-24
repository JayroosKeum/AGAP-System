<?php

require_once '../../controllers/ComplaintController.php';

header('Content-Type: application/json');

$controller = new ComplaintController();

echo json_encode(
    $controller->show((int) ($_GET['id'] ?? 0))
);