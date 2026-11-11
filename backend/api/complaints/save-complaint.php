<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../controllers/ComplaintController.php';
require_once __DIR__ . '/../../middleware/rbac.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Only POST is accepted.']);
    exit;
}

$db = (new Database())->connect();
$roleName = agapRequireApiRoles(['Administrator', 'Lupon Clerk', 'BPSO', 'Resident'], $db);

try {
    $inputData = $_POST;
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonDecoded = json_decode($rawInput, true);
        if (is_array($jsonDecoded)) {
            $inputData = array_merge($inputData, $jsonDecoded);
        }
    }

    $userId = agapSessionUserId();
    $inputData['complaint_source'] = ($roleName === 'Resident') ? 'Online' : 'Walk-in';
    $inputData['created_by'] = $userId;

    $controller = new ComplaintController();
    $complaintId = !empty($inputData['complaint_id']) ? (int) $inputData['complaint_id'] : 0;

    if ($complaintId > 0) {
        $existing = $controller->show($complaintId);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Complaint not found.']);
            exit;
        }
        if ($roleName === 'Resident') {
            if (!agapResidentOwnsComplaint($db, (int) $complaintId, $userId)
                || !in_array((string) ($existing['status'] ?? ''), ['Filed', 'Needs Information'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'You cannot edit this complaint in its current status.']);
                exit;
            }
        }
        $result = $controller->update($complaintId, $inputData, $_FILES);
        $isUpdate = true;
    } else {
        $result = $controller->store($inputData, $_FILES);
        $isUpdate = false;
    }

    if (empty($result['success'])) {
        http_response_code(422);
        echo json_encode($result);
        exit;
    }

    $savedId = (int) ($result['complaint_id'] ?? $complaintId);
    $result['is_update'] = $isUpdate;
    $result['redirect_url'] = ($roleName === 'Resident')
        ? '../../frontend/pages/complaints/resident-portal.php'
        : '../../frontend/pages/complaints/complaint-details.php?id=' . $savedId;

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('save-complaint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to save complaint right now. Please try again.'
    ]);
    exit;
}
