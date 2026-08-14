<?php
session_start();
require_once '../../controllers/GPSController.php';
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 4], true)) { http_response_code(403); exit('Unauthorized.'); }
$proofId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); $record = $proofId ? (new GPSController())->proofImage((int) $proofId) : false;
if (!$record || empty($record['image_path'])) { http_response_code(404); exit('Proof image not found.'); }
$base = realpath(dirname(__DIR__, 3) . '/storage/uploads/proof-of-service'); $path = realpath(dirname(__DIR__, 3) . '/' . $record['image_path']);
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit('Proof image not found.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path); header('X-Content-Type-Options: nosniff'); header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($path)); readfile($path);
