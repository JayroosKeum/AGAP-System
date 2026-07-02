<?php

require_once '../../controllers/NotificationController.php';

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller =
        new NotificationController();

    $controller->send(
        $_POST['user_id'],
        $_POST['title'],
        $_POST['message']
    );

    echo json_encode([
        'success' => true
    ]);
}