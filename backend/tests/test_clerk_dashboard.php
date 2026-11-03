<?php
/**
 * Test Suite: Lupon Clerk Dashboard & Quick Actions
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/ReportController.php';

echo "Running Clerk Dashboard verification...\n";

// 1. Verify ReportController provides all Lupon Clerk specialized metrics
$reportController = new ReportController();
$data = $reportController->dashboard(['period' => 'all']);

$requiredKeys = [
    'walkins_today',
    'active_paanyaya',
    'pending_forms',
    'total_cases',
    'active_mediations',
    'pangkat_conciliations',
    'settled_cases',
    'archived_cases',
    'active_users',
    'audit_feed',
    'today_hearings',
    'today_paanyayas',
    'analytics'
];

foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $data)) {
        throw new RuntimeException("Missing expected dashboard key: {$key}");
    }
}
echo "✓ All required dashboard KPIs and widgets are present in ReportController::dashboard().\n";

// 2. Verify Analytics Summary structure
$analytics = $data['analytics'];
if (!isset($analytics['top_categories'], $analytics['purok_hotspots'], $analytics['gender_breakdown'], $analytics['age_distribution'])) {
    throw new RuntimeException("Missing expected analytics components in dashboard data.");
}
echo "✓ Macro Dispute Analytics (Categories, Hotspots, Demographics) verified.\n";

// 3. Verify Paanyaya payload includes session info
if (is_array($data['today_paanyayas'])) {
    foreach ($data['today_paanyayas'] as $paa) {
        if (!array_key_exists('session_id', $paa) || !array_key_exists('attempt_number', $paa)) {
            throw new RuntimeException("Paanyaya items missing session tracking fields.");
        }
    }
    echo "✓ Today's Paanyayas contain valid session linkages.\n";
}

// 4. Verify Period-filtered stats also return specialized metrics
$weeklyData = $reportController->dashboard(['period' => 'weekly', 'offset' => 0]);
foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $weeklyData)) {
        throw new RuntimeException("Missing expected dashboard key in weekly dataset: {$key}");
    }
}
echo "✓ Period-filtered dashboard data contains all specialized keys.\n";

echo "All Lupon Clerk dashboard backend tests passed successfully!\n";
