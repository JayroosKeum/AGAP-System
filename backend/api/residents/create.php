<?php

session_start();

require_once '../../controllers/ResidentController.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller = new ResidentController();

    $result = $controller->store($_POST);

    if ($result['success'])
    {
        header(
            'Location: ../../../frontend/pages/residents/resident-list.php'
        );
        exit;
    }

    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    exit($result['message'] ?? 'Please check the resident information and try again.');
}
