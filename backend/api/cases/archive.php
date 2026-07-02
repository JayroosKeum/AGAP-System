<?php

require_once '../../controllers/CaseController.php';

$id = $_POST['case_id'];

$controller = new CaseController();

$controller->archive($id);

header(
    'Location: ../../../frontend/pages/cases/case-list.php'
);

exit;