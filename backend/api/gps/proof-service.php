<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../../controllers/GPSController.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (!in_array((int) $_SESSION['role_id'], [1, 2, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$controller = new GPSController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'officer_return' || isset($_POST['party_type'])) {
        $result = $controller->recordOfficerReturn($_POST, $_FILES['proof_image'] ?? [], (int) $_SESSION['user_id']);
    } else {
        $result = $controller->saveProof($_POST, $_FILES['proof_image'] ?? [], (int) $_SESSION['user_id']);
    }

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $caseId = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $mode = trim((string) ($_GET['mode'] ?? ''));

    if ($caseId) {
        if ($mode === 'deliveries') {
            $result = $controller->caseDeliveries((int) $caseId);
        } elseif ($mode === 'notices') {
            $result = $controller->summonsNotices((int) $caseId);
        } elseif ($mode === 'summary') {
            $result = $controller->caseSummary((int) $caseId);
        } elseif ($mode === '') {
            $result = $controller->proofs((int) $caseId);
        } else {
            $result = ['success' => false, 'message' => 'Unsupported view mode.'];
        }
    } elseif ($mode === '') {
        $result = $controller->cases();
    } else {
        $result = ['success' => false, 'message' => 'A valid case is required.'];
    }

    http_response_code($result['success'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
header('Allow: GET, POST');
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
