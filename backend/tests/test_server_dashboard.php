<?php
/**
 * Test Suite: Summons Server Dashboard, Dispatch & 1-Tap Service Logger
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/ServerDashboardController.php';

echo "Running Summons Server Dashboard verification...\n";

$db = (new Database())->connect();
$controller = new ServerDashboardController();

// 1. Get Dashboard Payload
$data = $controller->getDashboardData(['purok' => 'all']);

if (!isset($data['kpis'], $data['puroks'], $data['tasks'])) {
    throw new RuntimeException("Missing expected keys in server dashboard payload.");
}
echo "✓ Server Dashboard data structure verified.\n";

// 2. Verify Dispatch Summary KPIs
$kpis = $data['kpis'];
if (!isset($kpis['pending_delivery'], $kpis['served_today'], $kpis['unserved_followup'])) {
    throw new RuntimeException("Missing required Dispatch Summary KPIs (Pending, Served Today, Unserved/Followup).");
}
echo "✓ Dispatch Summary KPIs (Pending, Served Today, Unserved/Followup) verified.\n";

// 3. Verify Purok Route Filter
$puroks = $data['puroks'];
if (!is_array($puroks) || count($puroks) === 0) {
    throw new RuntimeException("No purok routes returned for batch field filtering.");
}
echo "✓ Purok Route Filter areas loaded successfully.\n";

// 4. Verify 1-Tap Service Logging for a Delivery Record
// Find a valid delivery record that joins cases, hearings, residents
$delivery = $db->query("
    SELECT sd.delivery_id, sd.case_id, sd.hearing_id, sd.resident_id
    FROM summon_deliveries sd
    JOIN cases c ON c.case_id = sd.case_id
    JOIN hearings h ON h.hearing_id = sd.hearing_id
    JOIN residents r ON r.resident_id = sd.resident_id
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$delivery) {
    throw new RuntimeException("No valid delivery record found for testing.");
}

$deliveryId = (int) $delivery['delivery_id'];

// Test 1-Tap Action: Served
$resServed = $controller->logServiceAction([
    'delivery_id' => $deliveryId,
    'action' => 'served',
    'remarks' => 'Served personally at house gate',
    'user_id' => 6 // Kuya Summons Server
]);

if (!$resServed['success']) {
    throw new RuntimeException("1-Tap Served logging failed: " . ($resServed['message'] ?? ''));
}
echo "✓ 1-Tap [Served] action logged successfully.\n";

// Test 1-Tap Action: Refused to Sign
$resRefused = $controller->logServiceAction([
    'delivery_id' => $deliveryId,
    'action' => 'refused',
    'remarks' => 'Respondent refused to sign the summons copy',
    'user_id' => 6
]);

if (!$resRefused['success']) {
    throw new RuntimeException("1-Tap [Refused to Sign] logging failed: " . ($resRefused['message'] ?? ''));
}
echo "✓ 1-Tap [Refused to Sign] action logged successfully.\n";

// Test 1-Tap Action: Unserved (Not Found)
$resUnserved = $controller->logServiceAction([
    'delivery_id' => $deliveryId,
    'action' => 'unserved',
    'unserved_reason' => 'Address Not Found',
    'remarks' => 'Party moved to another barangay',
    'user_id' => 6
]);

if (!$resUnserved['success']) {
    throw new RuntimeException("1-Tap [Unserved (Not Found)] logging failed: " . ($resUnserved['message'] ?? ''));
}
echo "✓ 1-Tap [Unserved (Not Found)] action logged successfully.\n";

echo "All Summons Server dashboard backend tests passed successfully!\n";
