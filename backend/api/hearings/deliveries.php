<?php
/**
 * MODIFIED EXISTING FILE
 * Path: backend/api/hearings/deliveries.php
 *
 * Retains the existing SummonDeliveryService workflow while applying the
 * canonical service statuses and request validation from Step 1.
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../services/SummonDeliveryService.php';
require_once __DIR__ . '/../../services/SummonDeliveryStatus.php';
require_once __DIR__ . '/../../services/SummonDeliveryValidator.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['role_id']) ||
    !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$roleId = (int) $_SESSION['role_id'];
$userId = (int) $_SESSION['user_id'];
$canManage = in_array($roleId, [1, 2, 4], true); // Administrator, Clerk, Summons Server
$deliveryService = new SummonDeliveryService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hearingId = filter_input(
        INPUT_GET,
        'hearing_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$hearingId) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'A valid hearing ID is required.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = $deliveryService->getHearingDeliveries((int) $hearingId);
    http_response_code(!empty($result['success']) ? 200 : 404);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized. Only Administrators, Clerks, and Summons Servers can record delivery.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $inputData = [];
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

    if (str_contains(strtolower($contentType), 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'The request body must contain valid JSON.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $inputData = $decoded;
    } else {
        $inputData = $_POST;
    }

    $hearingId = filter_var(
        $inputData['hearing_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$hearingId) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'A valid hearing ID is required.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /*
     * Preserve compatibility with the existing delivery form and service.
     * The following aliases are normalized only when the canonical field is
     * absent. Existing client field names therefore continue to work.
     */
    if (!isset($inputData['delivery_status']) && isset($inputData['status'])) {
        $inputData['delivery_status'] = $inputData['status'];
    }
    if (!isset($inputData['attempted_at']) && isset($inputData['delivery_date'])) {
        $inputData['attempted_at'] = $inputData['delivery_date'];
    }
    if (!isset($inputData['service_address']) && isset($inputData['address'])) {
        $inputData['service_address'] = $inputData['address'];
    }
    if (!isset($inputData['service_method']) && isset($inputData['method'])) {
        $inputData['service_method'] = $inputData['method'];
    }
    if (!isset($inputData['recipient_name']) && isset($inputData['received_by'])) {
        $inputData['recipient_name'] = $inputData['received_by'];
    }

    $status = trim((string) ($inputData['delivery_status'] ?? ''));
    if (!SummonDeliveryStatus::isValid($status)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Select a valid summon delivery status.',
            'errors' => [
                'delivery_status' => 'Allowed values: ' . implode(', ', SummonDeliveryStatus::all()) . '.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $validation = (new SummonDeliveryValidator())->validate($inputData);
    if (!$validation['valid']) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Please correct the summon delivery information.',
            'errors' => $validation['errors'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Return the normalized canonical fields to the existing service while
    // retaining all other fields that it already accepts.
    $inputData = array_merge($inputData, $validation['normalized']);

    $result = $deliveryService->recordDelivery(
        (int) $hearingId,
        $inputData,
        $userId
    );

    if (!empty($result['success'])) {
        $result['service_effect'] = [
            'mediation_unlocked' => SummonDeliveryStatus::canUnlockMediation($status),
            'procedural_review_required' => SummonDeliveryStatus::requiresProceduralReview($status),
        ];
    }

    http_response_code(!empty($result['success']) ? 200 : 422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
