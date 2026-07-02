<?php

require_once '../../controllers/HearingController.php';

$id = $_POST['hearing_id'];

$controller = new HearingController();

$controller->update($id,$_POST);

header(
    'Location: ../../../frontend/pages/hearings/schedules.php'
);

exit;