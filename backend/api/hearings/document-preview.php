<?php

session_start();

require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    exit('Authentication required.');
}

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) {
    http_response_code(422);
    exit('A valid document ID is required.');
}

$db = (new Database())->connect();
$stmt = $db->prepare('SELECT file_name, file_path, mime_type FROM hearing_minutes_documents WHERE id = ? LIMIT 1');
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$relativePath = ltrim((string) $document['file_path'], '/\\');
if (!str_starts_with(str_replace('\\', '/', $relativePath), 'storage/hearing-documents/')) {
    http_response_code(404);
    exit('Invalid document path.');
}

$projectRoot = dirname(__DIR__, 3);
$path = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
// Compatibility for files written by the old, incorrect backend-relative path.
if (!is_file($path)) {
    $legacyPath = $projectRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR
        . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($legacyPath)) {
        $path = $legacyPath;
    }
}
if (!is_file($path)) {
    http_response_code(404);
    exit('Uploaded document file is unavailable.');
}

$mime = trim((string) $document['mime_type']);
if ($mime === '') {
    $mime = mime_content_type($path) ?: 'application/octet-stream';
}
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $document['file_name']) ?: 'minutes-document';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
