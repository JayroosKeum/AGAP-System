<?php

require_once '../../controllers/ComplaintController.php';

$id = $_POST['complaint_id'];

$controller = new ComplaintController();

$controller->destroy($id);

header(
    'Location: ../../../frontend/pages/complaints/complaint-list.php'
);

exit;