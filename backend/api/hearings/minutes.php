<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../models/HearingMinutes.php';
require_once __DIR__ . '/../../services/AuditService.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$userId = (int) $_SESSION['user_id'];
$model = new HearingMinutes();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hearingId = filter_var($_GET['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($hearingId) {
        $data = $model->getByHearing($hearingId);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    if ($caseId) {
        $data = $model->getByCase($caseId);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'hearing_id or case_id is required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only Admin (1), Clerk (2), Lupon Member (3) can record hearing minutes
    if (!in_array($roleId, [1, 2, 3], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Only authorized personnel can record hearing minutes.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input) || empty($input)) {
        $input = $_POST;
    }

    $result = $model->save($input, $userId);
    if ($result['success']) {
        (new AuditService())->log($userId, 'Recorded hearing session minutes', 'Hearings', (int) ($input['hearing_id'] ?? 0));
    }

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
