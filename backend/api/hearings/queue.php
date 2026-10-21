<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../controllers/HearingController.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$userId = (int) $_SESSION['user_id'];
$controller = new HearingController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Retrieve daily docket
    $params = [
        'date' => trim((string) ($_GET['date'] ?? '')),
        'hearing_type' => trim((string) ($_GET['hearing_type'] ?? ''))
    ];
    $result = $controller->getDailyDocket($params);
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Actions: check_in, standby, start_session, conclude_session
    if (!in_array($roleId, [1, 2, 3], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Authorized personnel only.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input) || empty($input)) {
        $input = $_POST;
    }

    $action = trim((string) ($input['action'] ?? $_GET['action'] ?? ''));

    switch ($action) {
        case 'check_in':
            $res = $controller->checkInParty($input, $userId);
            http_response_code($res['success'] ? 200 : 422);
            echo json_encode($res);
            exit;

        case 'standby':
            $res = $controller->putOnStandby($input, $userId);
            http_response_code($res['success'] ? 200 : 422);
            echo json_encode($res);
            exit;

        case 'start_session':
            $res = $controller->startSession($input, $userId);
            http_response_code($res['success'] ? 200 : 422);
            echo json_encode($res);
            exit;

        case 'conclude_session':
            $res = $controller->concludeSession($input, $userId);
            http_response_code($res['success'] ? 200 : 422);
            echo json_encode($res);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid or missing action.']);
            exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
