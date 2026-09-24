<?php

session_start();
require_once '../../controllers/ComplaintController.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    exit('Authentication required.');
}
if (!in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    exit('Unauthorized.');
}

$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$attachmentId) {
    http_response_code(422);
    exit('A valid attachment ID is required.');
}

$attachment = (new ComplaintController())->getAttachment((int) $attachmentId);
if (!$attachment) {
    http_response_code(404);
    exit('Attachment not found.');
}

$base1 = realpath(dirname(__DIR__, 3) . '/storage/uploads/complaint-evidence');
$base2 = realpath(dirname(__DIR__, 3) . '/storage/uploads/evidence');
$path = realpath(dirname(__DIR__, 3) . '/' . $attachment['file_path']);
$isValidPath = false;
if ($path !== false && is_file($path)) {
    if (($base1 !== false && str_starts_with($path, $base1 . DIRECTORY_SEPARATOR)) ||
        ($base2 !== false && str_starts_with($path, $base2 . DIRECTORY_SEPARATOR))) {
        $isValidPath = true;
    }
}
if (!$isValidPath) {
    http_response_code(404);
    exit('Attachment file not found.');
}

$downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $attachment['file_name']) ?: 'attachment';
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $attachment['file_type']);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
readfile($path);
