<?php

require_once '../../controllers/ReportController.php';

header('Content-Type: application/json');

$controller =
    new ReportController();

echo json_encode(
    $controller->dashboard()
);