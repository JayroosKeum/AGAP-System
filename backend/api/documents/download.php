<?php
session_start();
require_once '../../controllers/DocumentController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(403);
    exit('Unauthorized.');
}
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$record = $documentId ? (new DocumentController())->getDownload((int) $documentId) : false;
if (!$record) {
    http_response_code(404);
    exit('Generated document not found.');
}
$root = realpath(dirname(__DIR__, 3));
$storage = realpath(dirname(__DIR__, 3) . '/storage/generated-documents');
$file = realpath(dirname(__DIR__, 3) . '/' . $record['file_path']);
if ($root === false || $storage === false || $file === false || !str_starts_with($file, $storage . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit('Generated PDF file not found.');
}
$fileName = basename($file);
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($file));
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($fileName));
readfile($file);