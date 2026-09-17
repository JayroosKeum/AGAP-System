<?php
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode(['success' => false, 'message' => 'Pangkat assignment has been replaced by the complete case-team assignment.']);
exit;
require_once '../../controllers/PangkatController.php';
header('Content-Type: application/json');
echo json_encode((new PangkatController())->index());
