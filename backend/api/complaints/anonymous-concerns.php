<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/AnonymousConcernService.php';
require_once __DIR__ . '/../../services/CsrfService.php';

$db = (new Database())->connect();
agapRequireApiRoles(['Super Administrator', 'Administrator', 'Lupon Clerk', 'Lupon Member'], $db);
$service = new AnonymousConcernService($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'concerns' => $service->listForStaff()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfService::validateRequest()) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing request token.']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    if (!is_array($input)) {
        $input = $_POST;
    }

    $concernId = filter_var($input['concern_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $status = trim((string) ($input['status'] ?? ''));
    $note = trim((string) ($input['milestone_note'] ?? $input['public_milestone_note'] ?? ''));

    $result = $service->updateMilestone((int) $concernId, $status, $note, agapSessionUserId());
    http_response_code(!empty($result['success']) ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
