<?php

session_start();

require_once __DIR__ . '/../../controllers/ComplaintController.php';
require_once __DIR__ . '/../../middleware/rbac.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'BPSO', 'LISO'], $db);

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$complaintId = filter_var($input['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$complaintId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a valid existing complaint to link.']);
    exit;
}

$controller = new ComplaintController();
$result = $controller->linkWalkin((int) $complaintId, $input, $_FILES);

http_response_code(!empty($result['success']) ? 200 : 422);
echo json_encode($result);
exit;
