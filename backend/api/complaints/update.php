<?php

require_once '../../controllers/ComplaintController.php';

$id = $_POST['complaint_id'];

$controller = new ComplaintController();

$controller->update($id,$_POST);

header(
    'Location: ../../../frontend/pages/complaints/complaint-details.php?id=' . $id
);

exit;