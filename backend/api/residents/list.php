<?php
session_start();

require_once '../../controllers/ResidentController.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$controller = new ResidentController();

if (isset($_GET['page'])) {
    $page = filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($page === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Page must be a positive whole number.']);
        exit;
    }
    $perPage = filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 25;
    echo json_encode($controller->page((int) $page, (int) $perPage));
    exit;
}

echo json_encode(
    $controller->index()
);