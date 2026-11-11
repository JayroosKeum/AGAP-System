<?php

session_start();

require_once __DIR__ . '/../../controllers/ComplaintController.php';
require_once __DIR__ . '/../../middleware/rbac.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = (new Database())->connect();
$roleName = agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'Lupon Member', 'BPSO', 'LISO', 'Resident'], $db);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid complaint ID is required.']);
    exit;
}

$complaint = (new ComplaintController())->show((int) $id);
if ($complaint === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Complaint not found.']);
    exit;
}

if ($roleName === 'Resident') {
    $userId = agapSessionUserId();
    if (!agapResidentOwnsComplaint($db, (int) $id, $userId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only view your own submitted complaints.']);
        exit;
    }
    if (empty($complaint['ai_suggestion']['resident_viewed'])) {
        $complaint['ai_suggestion'] = null;
    }
    if (!empty($complaint['intake_screening']) && is_array($complaint['intake_screening'])) {
        unset($complaint['intake_screening']['ai_suggestion_json']);
    }
}

echo json_encode($complaint);
