<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once '../../controllers/GPSController.php';
if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required.']); exit; }
if (!in_array((int) $_SESSION['role_id'], [1, 2, 4], true)) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit; }
$controller = new GPSController();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $caseId = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $mode = $_GET['mode'] ?? '';
    if ($mode === 'servers') {
        $result = $controller->summonsServers();
    } elseif ($caseId) {
        if ($mode === 'documents') {
            $result = $controller->documents((int) $caseId);
        } elseif ($mode === 'summary') {
            $result = $controller->caseSummary((int) $caseId);
        } else {
            $result = $controller->proofs((int) $caseId);
        }
    } else {
        $result = $controller->cases();
    }
    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
$result = $controller->saveProof($_POST, $_FILES['photo'] ?? [], (int) $_SESSION['user_id']);
http_response_code($result['success'] ? 201 : 422);
echo json_encode($result);
