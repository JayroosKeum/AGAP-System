<?php

require_once '../../controllers/AssignmentController.php';

header('Content-Type: application/json');

$caseId = $_GET['case_id'];

$controller = new AssignmentController();

echo json_encode(
    $controller->list($caseId)
);