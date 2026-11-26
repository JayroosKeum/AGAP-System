<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../models/HearingMinutes.php';
require_once __DIR__ . '/../../services/AuditService.php';

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member'], $db);
$userId = agapSessionUserId();
$model = new HearingMinutes();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hearingId = filter_var($_GET['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($hearingId) {
        agapRequireHearingAccess($db, (int) $hearingId, 'read', true);
        $data = $model->getByHearing($hearingId);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    if ($caseId) {
        agapRequireCaseAccess($db, (int) $caseId, 'read', true);
        $data = $model->getByCase($caseId);
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'hearing_id or case_id is required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input) || empty($input)) {
        $input = $_POST;
    }

    $targetHearingId = filter_var($input['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($targetHearingId) {
        agapRequireHearingAccess($db, (int) $targetHearingId, 'write', true);
    }

    $result = (($input['action'] ?? '') === 'book_next_hearing')
        ? $model->bookNextHearing($input, $userId)
        : $model->save($input, $userId);
    if ($result['success']) {
        $action = (($input['action'] ?? '') === 'book_next_hearing')
            ? 'Booked next hearing from concluded session'
            : 'Recorded hearing session minutes';
        (new AuditService())->log($userId, $action, 'Hearings', (int) ($input['hearing_id'] ?? 0));
    }

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
