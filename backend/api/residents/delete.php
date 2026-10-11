<?php
session_start();

require_once '../../controllers/ResidentController.php';

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_GET['format']) && $_GET['format'] === 'json');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    header('Location: ../../../frontend/pages/auth/login.php');
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid resident profile ID.']);
        exit;
    }
    header('Location: ../../../frontend/pages/residents/resident-list.php');
    exit;
}

$controller = new ResidentController();
$deleted = $controller->destroy($id);

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => (bool) $deleted,
        'message' => $deleted ? 'Resident profile deleted successfully.' : 'Unable to delete resident profile.'
    ]);
    exit;
}

$_SESSION['resident_flash'] = [
    'type' => $deleted ? 'success' : 'error',
    'message' => $deleted ? 'Resident profile deleted successfully.' : 'Unable to delete resident profile.',
];

header('Location: ../../../frontend/pages/residents/resident-list.php');
exit;