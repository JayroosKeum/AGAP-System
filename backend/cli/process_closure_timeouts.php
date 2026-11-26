<?php

declare(strict_types=1);

/**
 * Scheduled idempotent Pending Close timeout processor (A10).
 * Intended to be run periodically via Windows Task Scheduler / cron:
 *   php backend/cli/process_closure_timeouts.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ClosureWorkflowService.php';

$db = (new Database())->connect();
$service = new ClosureWorkflowService($db);
$result = $service->processExpiredRequests();

echo json_encode([
    'success' => true,
    'processed_at' => date('Y-m-d H:i:s'),
    'processed_count' => $result['processed_count'],
    'closed_request_ids' => $result['closed_request_ids'],
], JSON_PRETTY_PRINT) . PHP_EOL;
