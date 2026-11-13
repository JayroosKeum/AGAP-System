<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../controllers/PaanyayaController.php';

$db = (new Database())->connect();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$allowedRoles = ($method === 'GET')
    ? ['Administrator', 'Lupon Clerk', 'Lupon Member', 'LISO', 'Summons Server', 'Secretary', 'Barangay Secretary']
    : ['Administrator', 'Lupon Clerk', 'Secretary', 'Barangay Secretary'];
agapRequireApiRoles($allowedRoles, $db);

$controller = new PaanyayaController();
$userId = agapSessionUserId();

$input = $_GET;

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $jsonData = json_decode((string) $raw, true);
    if (is_array($jsonData)) {
        $input = array_merge($_POST, $jsonData);
    } else {
        $input = $_POST;
    }
}

$action = $input['action'] ?? ($input['type'] ?? 'get_data');
$complaintId = filter_var($input['complaint_id'] ?? ($input['id'] ?? null), FILTER_VALIDATE_INT) ?: 0;

try {
    switch ($action) {
        case 'get_data':
        case 'show':
            $result = $controller->show($complaintId);
            if (!($result['success'] ?? false)) {
                http_response_code(404);
            }
            echo json_encode($result);
            break;

        case 'save':
        case 'issue':
            $input['complaint_id'] = $complaintId;
            $result = $controller->save($input, $userId);
            if (!($result['success'] ?? false)) {
                http_response_code(400);
            }
            echo json_encode($result);
            break;

        case 'progress':
        case 'record_attendance':
            $input['complaint_id'] = $complaintId;
            $result = $controller->progress($input, $userId);
            if (!($result['success'] ?? false)) {
                http_response_code(400);
            }
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unrecognized action requested.']);
            break;
    }
} catch (Throwable $e) {
    error_log('paanyaya-actions error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process Paanyaya action right now.']);
}
