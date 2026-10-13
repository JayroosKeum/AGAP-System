<?php
/**
 * Returns the authoritative summon-service gate for one case.
 * Copy to: backend/api/summons/service-gate.php
 */

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../services/SummonServiceGate.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$roleId = filter_var($_SESSION['role_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (!$userId || !$roleId || !in_array((int) $roleId, [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (!$caseId) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid case ID is required.']);
    exit;
}

try {
    $result = (new SummonServiceGate())->forCase((int) $caseId);
    $result['case_id'] = (int) $caseId;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Summon service gate failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'The summon service status could not be evaluated.',
    ]);
}
