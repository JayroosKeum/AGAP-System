<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Paanyaya.php';
require_once __DIR__ . '/../../services/CsrfService.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$paanyayaModel = new Paanyaya();
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $paanyayaId = filter_var($_GET['paanyaya_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($paanyayaId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid Paanyaya ID is required.']);
        exit;
    }

    $data = $paanyayaModel->getPaanyayaById($paanyayaId);
    if (!$data) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Paanyaya not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $paanyayaId = filter_var($input['paanyaya_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($paanyayaId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid Paanyaya ID is required.']);
        exit;
    }

    try {
        $result = $paanyayaModel->recordAttendanceAndAction($paanyayaId, $userId, $input);
        if (!$result['success']) {
            http_response_code(422);
        }
        echo json_encode($result);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal error: ' . $e->getMessage()]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit;
