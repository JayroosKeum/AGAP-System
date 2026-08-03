<?php

session_start();

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');

try {
    $controllerPath =
        __DIR__
        . '/../../controllers/DocumentController.php';

    if (!is_file($controllerPath)) {
        throw new RuntimeException(
            'DocumentController.php was not found at: '
            . $controllerPath
        );
    }

    require_once $controllerPath;

    if (!class_exists('DocumentController')) {
        throw new RuntimeException(
            'DocumentController class could not be loaded.'
        );
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.',
        ]);

        exit;
    }

    if (!isset($_SESSION['role_id'])) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Authentication required.',
        ]);

        exit;
    }

    if (
        !in_array(
            (int) $_SESSION['role_id'],
            [1, 2, 3],
            true
        )
    ) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized.',
        ]);

        exit;
    }

    $caseId = filter_input(
        INPUT_GET,
        'case_id',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if (!$caseId) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' =>
                'A valid case_id query parameter is required. '
                . 'Example: kp12-data.php?case_id=1',
        ]);

        exit;
    }

    $controller = new DocumentController();

    $result = $controller->kp12Data(
        (int) $caseId
    );

    http_response_code(
        $result['success'] ? 200 : 404
    );

    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $exception) {
    error_log(
        'KP Form 12 endpoint error: '
        . $exception->getMessage()
        . PHP_EOL
        . $exception->getTraceAsString()
    );

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' =>
                'KP Form 12 endpoint failed: '
                . $exception->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
}