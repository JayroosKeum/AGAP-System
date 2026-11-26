<?php
session_start();
require_once '../../config/database.php';
require_once '../../middleware/rbac.php';
require_once '../../controllers/GPSController.php';

$db = (new Database())->connect();
$roleName = agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member', 'Summons Server'], $db);
$proofId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$proofId) { http_response_code(404); exit('Proof image not found.'); }
if (!agapCanAccessProofImage($db, (int) $proofId, agapSessionUserId(), $roleName)) {
    http_response_code(403);
    exit('You are not authorized to access this proof image.');
}
$record = (new GPSController())->proofImage((int) $proofId);
if (!$record || empty($record['image_path'])) { http_response_code(404); exit('Proof image not found.'); }
$base = realpath(dirname(__DIR__, 3) . '/storage/uploads/proof-of-service'); $path = realpath(dirname(__DIR__, 3) . '/' . $record['image_path']);
if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit('Proof image not found.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path); header('X-Content-Type-Options: nosniff'); header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($path)); readfile($path);
