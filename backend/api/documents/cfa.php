<?php

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../../controllers/CFAController.php';

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$controller = new CFAController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($caseId) {
        $record = $controller->getByCase($caseId);
        echo json_encode(['success' => true, 'data' => $record ?: null]);
        exit;
    }

    $records = $controller->index();
    echo json_encode(['success' => true, 'data' => $records]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($roleId, [1, 2], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Administrators and Lupon Clerks can issue a CFA.']);
        exit;
    }

    $input = $_POST;
    if (empty($input)) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $input = $json;
        }
    }

    $result = $controller->create($input);
    http_response_code(!empty($result['success']) ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);