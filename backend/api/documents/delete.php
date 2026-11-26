<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../../config/database.php';
require_once '../../middleware/rbac.php';
require_once '../../controllers/DocumentController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk'], $db);
$documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid document ID is required.']);
    exit;
}
if (!agapCanAccessDocument($db, (int) $documentId, agapSessionUserId(), agapGetRoleName($db))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$result = (new DocumentController())->delete((int) $documentId, (int) $_SESSION['user_id']);
http_response_code($result['success'] ? 200 : 404);
echo json_encode($result);