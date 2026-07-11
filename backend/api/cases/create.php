<?php

require_once '../../controllers/CaseController.php';

$controller = new CaseController();

if($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $error = $controller->getDocketingError($_POST['complaint_id'] ?? null);

    if ($error !== null) {
        header('Location: ../../../frontend/pages/cases/case-list.php?error=' . $error);
        exit;
    }

    if (!$controller->store($_POST)) {
        header('Location: ../../../frontend/pages/cases/case-list.php?error=save_failed');
        exit;
    }

    header(
        'Location: ../../../frontend/pages/cases/case-list.php'
    );

    exit;
}
