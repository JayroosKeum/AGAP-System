<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/HearingController.php';
http_response_code(410);
echo json_encode(['success' => false, 'message' => 'This legacy endpoint is retired. Confirm service for the specific hearing, then record attendance through the hearing workflow.']);
