<?php

session_start();

require_once __DIR__ . '/../../controllers/AIController.php';
require_once __DIR__ . '/../../services/CsrfService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

CsrfService::enforceForApiRequest();

$controller =
    new AIController();

$response =
    $controller->narrative(
        $_POST
    );

echo json_encode(
    $response
);
