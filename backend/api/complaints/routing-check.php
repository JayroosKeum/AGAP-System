<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

require_once __DIR__ . '/../../services/ComplaintRoutingService.php';

$raw = file_get_contents('php://input');
$payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($payload)) {
    $payload = $_POST;
}

$recommendation = ComplaintRoutingService::evaluate($payload);
echo json_encode([
    'success' => true,
    'routing' => $recommendation,
]);
