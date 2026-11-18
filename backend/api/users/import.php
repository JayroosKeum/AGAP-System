<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../controllers/UserController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$actorId = (int) ($_SESSION['user_id'] ?? 0);
if ((int) ($_SESSION['role_id'] ?? 0) !== 1 || $actorId < 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
    exit;
}

if (empty($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please upload a CSV file.']);
    exit;
}

$file = $_FILES['csv_file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'CSV upload failed.']);
    exit;
}

if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'CSV file must not exceed 2 MB.']);
    exit;
}

$ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Only .csv files are accepted.']);
    exit;
}

$handle = fopen($file['tmp_name'], 'rb');
if ($handle === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to read uploaded CSV file.']);
    exit;
}

$header = fgetcsv($handle);
if (!is_array($header) || $header === []) {
    fclose($handle);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The CSV file is empty.']);
    exit;
}

// Strip UTF-8 BOM from first header cell if present.
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
$normalizedHeaders = array_map(static fn ($col) => strtolower(trim((string) $col)), $header);

$required = ['first_name', 'last_name', 'username', 'email', 'role'];
$missing = array_diff($required, $normalizedHeaders);
if ($missing !== []) {
    fclose($handle);
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required CSV columns: ' . implode(', ', $missing),
    ]);
    exit;
}

$controller = new UserController();
$roleMap = [];
foreach ($controller->staffRoles() as $roleRow) {
    $roleMap[strtolower(trim((string) ($roleRow['role_name'] ?? '')))] = (int) $roleRow['role_id'];
}
$roleMap['resident'] = 5;

$imported = 0;
$skipped = 0;
$errors = [];
$rowNumber = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNumber++;
    if ($row === [null] || count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
        continue;
    }

    $assoc = [];
    foreach ($normalizedHeaders as $idx => $col) {
        $assoc[$col] = trim((string) ($row[$idx] ?? ''));
    }

    $roleInput = strtolower($assoc['role'] ?? '');
    $roleId = ctype_digit($roleInput) ? (int) $roleInput : ($roleMap[$roleInput] ?? 0);
    if ($roleId === 1) {
        $skipped++;
        $errors[] = "Row {$rowNumber}: Creating Administrator accounts via CSV import is not allowed.";
        continue;
    }
    if ($roleId < 2) {
        $skipped++;
        $errors[] = "Row {$rowNumber}: Unknown role '{$assoc['role']}'.";
        continue;
    }

    $payload = [
        'first_name' => $assoc['first_name'] ?? '',
        'middle_name' => $assoc['middle_name'] ?? '',
        'last_name' => $assoc['last_name'] ?? '',
        'username' => $assoc['username'] ?? '',
        'email' => $assoc['email'] ?? '',
        'contact_number' => $assoc['contact_number'] ?? '',
        'role_id' => $roleId,
        'password' => $assoc['password'] ?? '',
        'status' => ($assoc['password'] ?? '') !== '' ? 'Active' : 'Pending',
    ];

    $res = $controller->create($payload, $actorId);
    if (!empty($res['success'])) {
        $imported++;
    } else {
        $skipped++;
        $errors[] = "Row {$rowNumber} ({$payload['username']}): " . ($res['message'] ?? 'Validation failed.');
    }
}

fclose($handle);

echo json_encode([
    'success' => $imported > 0 || $errors === [],
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => array_slice($errors, 0, 25),
    'message' => "Imported {$imported} user(s); skipped {$skipped}.",
]);
