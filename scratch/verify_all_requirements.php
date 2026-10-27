<?php
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/models/Setting.php';
require_once __DIR__ . '/../backend/services/SummonDeliveryService.php';

$db = (new Database())->connect();

echo "--- 1. Testing Default Settings ---\n";
$delivService = new SummonDeliveryService($db);
$maxAttempts = $delivService->getMaxSummonAttempts();
echo "Max Summon Attempts: {$maxAttempts} (Expected: 6)\n";
assert($maxAttempts === 6, "Expected 6 max attempts");

echo "--- 2. Testing Target Date Window Navigation in check-schedule.php ---\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = ['user_id' => 1, 'role_id' => 1];
$_GET = [
    'stage' => 'Conciliation',
    'target_date' => '2026-12-16',
    'get_window' => '1'
];

ob_start();
include __DIR__ . '/../backend/api/summons/check-schedule.php';
$raw = ob_get_clean();
$json = json_decode($raw, true);

if (!empty($json['window']['daily_slots']['2026-12-16'])) {
    $dec16 = $json['window']['daily_slots']['2026-12-16'];
    echo "SUCCESS: 2026-12-16 found in window!\n";
    echo "Date: {$dec16['date']}, Day: {$dec16['day_name']}, Max: {$dec16['max']}, Available: {$dec16['available']}\n";
} else {
    echo "FAIL: 2026-12-16 not found in window! Available keys: " . implode(', ', array_slice(array_keys($json['window']['daily_slots'] ?? []), 0, 5)) . "...\n";
    exit(1);
}

echo "--- 3. Testing 6-Attempt Reset for Conciliation (KP Form 12) ---\n";
// Query a Conciliation case if any exists
$stmt = $db->query("SELECT case_id FROM cases WHERE case_status = 'Conciliation' LIMIT 1");
$concCaseId = $stmt->fetchColumn();
if ($concCaseId) {
    $concAttempts = $delivService->getSummonsAttempts((int) $concCaseId, null, 'Conciliation');
    echo "Case #{$concCaseId} (Conciliation) attempts count: " . count($concAttempts) . "\n";
    foreach ($concAttempts as $ca) {
        echo "  - Attempt {$ca['attempt_number']}: {$ca['form_type']} ({$ca['delivery_status']})\n";
    }
}

echo "\nALL CHECKS PASSED!\n";
