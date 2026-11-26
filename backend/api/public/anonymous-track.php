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

$now = time();
$bucket = array_values(array_filter(
    (array) ($_SESSION['anon_track_hits'] ?? []),
    static fn ($ts) => is_int($ts) && ($now - $ts) < 300
));
if (count($bucket) >= 20) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many lookup attempts. Please wait a few minutes and try again.']);
    exit;
}
$bucket[] = $now;
$_SESSION['anon_track_hits'] = $bucket;

$raw = file_get_contents('php://input');
$input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
if (!is_array($input)) {
    $input = $_POST;
}

$reference = (string) ($input['reference_code'] ?? $input['reference'] ?? '');
$token = (string) ($input['follow_up_token'] ?? $input['token'] ?? '');

$service = new AnonymousConcernService((new Database())->connect());
$result = $service->track($reference, $token);
$code = (int) ($result['http_code'] ?? (!empty($result['success']) ? 200 : 404));
unset($result['http_code']);

http_response_code($code);
echo json_encode($result);
