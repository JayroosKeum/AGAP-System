<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../controllers/DocumentController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4, 5, 6], true)) {
    http_response_code(403);
    exit('Unauthorized.');
}
$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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

$isAttachment = !empty($_GET['download']) || !empty($_GET['attachment']);
$disposition = $isAttachment ? 'attachment' : 'inline';

// Construct clean AGAP-compliant download filename
$caseNum = !empty($record['case_number']) ? preg_replace('/[^A-Za-z0-9_-]/', '-', $record['case_number']) : 'Case';
$tplName = $record['template_name'] ?? '';
if (str_contains($tplName, 'KP Form 9') || str_contains($tplName, 'Summon')) {
    $fileName = sprintf('AGAP_%s_KP-Form-9_Summons.pdf', $caseNum);
} elseif (str_contains($tplName, 'KP Form 8')) {
    $fileName = sprintf('AGAP_%s_KP-Form-8_Notice.pdf', $caseNum);
} else {
    $cleanTpl = preg_replace('/[^A-Za-z0-9_-]/', '-', $tplName) ?: 'Document';
    $fileName = sprintf('AGAP_%s_%s.pdf', $caseNum, $cleanTpl);
}

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($file));
header("Content-Disposition: {$disposition}; filename*=UTF-8''" . rawurlencode($fileName));
readfile($file);