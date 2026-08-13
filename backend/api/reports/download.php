<?php
session_start();
require_once __DIR__ . '/../../controllers/ReportController.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); exit('Method not allowed.'); }
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) { http_response_code(403); exit('Unauthorized.'); }
$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$record = $reportId ? (new ReportController())->getDownload((int) $reportId) : null;
$storage = realpath(dirname(__DIR__, 3) . '/storage/generated-reports');
$file = $record ? realpath(dirname(__DIR__, 3) . '/' . $record['file_path']) : false;
if ($storage === false || $file === false || !str_starts_with($file, $storage . DIRECTORY_SEPARATOR) || !is_file($file)) { http_response_code(404); exit('Generated report not found.'); }
header('X-Content-Type-Options: nosniff'); header('Content-Type: text/csv; charset=utf-8'); header('Content-Length: ' . filesize($file)); header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($file))); readfile($file);
