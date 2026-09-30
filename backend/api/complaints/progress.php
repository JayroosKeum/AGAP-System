<?php

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../models/CaseProgress.php';
require_once __DIR__ . '/../../services/ComplaintLifecycleService.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication required.'
    ]);

    exit;
}

$complaintId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if (!$complaintId) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Valid complaint ID is required.'
    ]);

    exit;
}

$progress = (new CaseProgress())->getCaseProgress(
    (int) $complaintId
);

if (!$progress) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'Complaint not found.'
    ]);

    exit;
}

$lifecycle = new ComplaintLifecycleService();

$progress['lifecycle_status'] =
    $lifecycle->forComplaint(
        (int) $complaintId
    );

$progress['lifecycle_description'] =
    ComplaintLifecycleService::description(
        $progress['lifecycle_status']
    );

$progress['lifecycle_statuses'] =
    ComplaintLifecycleService::allowedStatuses();

$progress['hide_issue_summon'] =
    in_array(
        $progress['lifecycle_status'],
        [
            ComplaintLifecycleService::CONCILIATION,
            ComplaintLifecycleService::CFA,
            ComplaintLifecycleService::CLOSED
        ],
        true
    );

echo json_encode([
    'success' => true,
    'data' => $progress
]);