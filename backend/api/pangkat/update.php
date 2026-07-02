<?php

require_once '../../controllers/PangkatController.php';

$controller = new PangkatController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $controller->addMember(
        $_POST['pangkat_id'],
        $_POST['member_id'],
        $_POST['position']
    );

    echo json_encode([
        'success' => true
    ]);
}
