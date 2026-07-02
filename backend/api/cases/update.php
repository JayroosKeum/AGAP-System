<?php

require_once '../../controllers/CaseController.php';

$id = $_POST['case_id'];

$controller = new CaseController();

$controller->update($id,$_POST);

header(
    'Location: ../../../frontend/pages/cases/case-details.php?id=' . $id
);

exit;