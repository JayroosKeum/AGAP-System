<?php

require_once '../../controllers/CaseController.php';

header('Content-Type: application/json');

$controller = new CaseController();

if (isset($_GET['page'])) {
    $page = filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($page === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Page must be a positive whole number.']);
        exit;
    }

    echo json_encode($controller->page((int) $page));
    exit;
}

echo json_encode(
    $controller->index()
);
