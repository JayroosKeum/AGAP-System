<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/AnonymousConcernService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Lightweight session throttle (max 10 submissions per 10 minutes)
$now = time();
$bucket = array_values(array_filter(
    (array) ($_SESSION['anon_submit_hits'] ?? []),
    static fn ($ts) => is_int($ts) && ($now - $ts) < 600
));
if (count($bucket) >= 10) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many submissions. Please wait a few minutes before trying again.']);
    exit;
}
$bucket[] = $now;
$_SESSION['anon_submit_hits'] = $bucket;

$raw = file_get_contents('php://input');
$input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($input)) {
    $input = $_POST;
}

$attachment = $_FILES['attachment'] ?? null;
$service = new AnonymousConcernService((new Database())->connect());
$result = $service->submit($input, is_array($attachment) ? $attachment : null);

http_response_code(!empty($result['success']) ? 201 : 422);
echo json_encode($result);
