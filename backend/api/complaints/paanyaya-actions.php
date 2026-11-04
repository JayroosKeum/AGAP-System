<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int)$_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

require_once __DIR__ . '/../../controllers/PaanyayaController.php';

$controller = new PaanyayaController();
$userId = (int)$_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = $_GET;

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $jsonData = json_decode($raw, true);
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
            echo json_encode(['success' => false, 'message' => "Unrecognized action: $action"]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
