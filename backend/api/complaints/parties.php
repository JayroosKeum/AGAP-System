<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../controllers/ComplaintController.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    respond(['success' => false, 'message' => 'Authentication required.'], 401);
}
if (!in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    respond(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$controller = new ComplaintController();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $complaintId = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$complaintId) {
        respond(['success' => false, 'message' => 'A valid complaint ID is required.'], 422);
    }
    respond(['success' => true, 'data' => $controller->getParties((int) $complaintId)]);
}

if ($method === 'POST') {
    $complaintId = filter_var($_POST['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $residentId = filter_var($_POST['resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $partyType = trim((string) ($_POST['party_type'] ?? ''));
    if (!$complaintId || !$residentId || !in_array($partyType, ['Complainant', 'Respondent', 'Witness'], true)) {
        respond(['success' => false, 'message' => 'A valid complaint, resident, and party type are required.'], 422);
    }
    $result = $controller->addParty((int) $complaintId, (int) $residentId, $partyType);
    respond($result, $result['success'] ? 201 : 422);
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $input);
    $partyId = filter_var($input['party_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$partyId) {
        respond(['success' => false, 'message' => 'A valid party ID is required.'], 422);
    }
    $result = $controller->deleteParty((int) $partyId);
    respond($result, $result['success'] ? 200 : 404);
}

respond(['success' => false, 'message' => 'Method not allowed.'], 405);
