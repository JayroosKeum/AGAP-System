<?php
require_once '../../controllers/PangkatController.php';
header('Content-Type: application/json');
echo json_encode((new PangkatController())->index());
