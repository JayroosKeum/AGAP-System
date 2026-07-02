<?php

require_once '../../controllers/NotificationController.php';

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller =
        new NotificationController();

    $controller->read(
        $_POST['notification_id']
    );

    echo json_encode([
        'success' => true
    ]);
}