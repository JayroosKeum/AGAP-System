<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../controllers/NotificationController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4, 5, 6], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$json = json_decode($rawInput, true);

$ids = [];
if (!empty($json['notification_ids']) && is_array($json['notification_ids'])) {
    $ids = array_filter(array_map('intval', $json['notification_ids']), fn($i) => $i > 0);
} elseif (!empty($_POST['notification_ids'])) {
    if (is_array($_POST['notification_ids'])) {
        $ids = array_filter(array_map('intval', $_POST['notification_ids']), fn($i) => $i > 0);
    } else {
        $ids = array_filter(array_map('intval', explode(',', (string) $_POST['notification_ids'])), fn($i) => $i > 0);
    }
} elseif (!empty($_POST['notification_id'])) {
    $singleId = (int) $_POST['notification_id'];
    if ($singleId > 0) $ids = [$singleId];
} elseif (!empty($json['notification_id'])) {
    $singleId = (int) $json['notification_id'];
    if ($singleId > 0) $ids = [$singleId];
}

if (empty($ids)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid notification ID or list of IDs is required.']);
    exit;
}

$controller = new NotificationController();
if (count($ids) === 1) {
    $result = $controller->delete(reset($ids), (int) $_SESSION['user_id']);
} else {
    $result = $controller->deleteMultiple($ids, (int) $_SESSION['user_id']);
}

http_response_code($result['success'] ? 200 : 422);
echo json_encode($result);
