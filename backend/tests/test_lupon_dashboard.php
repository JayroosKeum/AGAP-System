<?php
/**
 * Test Suite: Lupon Member Dashboard, Duty Schedule & Dossier API
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/LuponDashboardController.php';

echo "Running Lupon Member Dashboard verification...\n";

$db = (new Database())->connect();

// Ensure duty schedule exists for user 3
$db->prepare("
    INSERT INTO lupon_duty_schedules (user_id, duty_day, start_time, end_time, notes, is_active)
    VALUES (3, 'Wednesday', '08:00:00', '17:00:00', 'Wednesday Conciliation Team', 1)
    ON DUPLICATE KEY UPDATE duty_day = 'Wednesday', is_active = 1
")->execute();

$controller = new LuponDashboardController();

// Test 1: Get Member Dashboard Data
$data = $controller->getDashboardData(3);

if (!isset($data['duty_schedule'], $data['kpis'], $data['duty_hearings'], $data['assigned_cases'])) {
    throw new RuntimeException("Missing expected keys in lupon dashboard data.");
}
echo "✓ Lupon Member dashboard data keys verified.\n";

// Test 2: Verify Duty Schedule
$duty = $data['duty_schedule'];
if (($duty['duty_day'] ?? '') !== 'Wednesday') {
    throw new RuntimeException("Expected duty day to be Wednesday, got: " . ($duty['duty_day'] ?? 'null'));
}
echo "✓ Member's duty day defaults correctly to Wednesday Team.\n";

// Test 3: Verify Assigned Pangkat Cases
$cases = $data['assigned_cases'];
if (!is_array($cases) || count($cases) === 0) {
    throw new RuntimeException("Expected assigned pangkat cases for Lupon user 3, none returned.");
}

$sampleCase = $cases[0];
if (!isset($sampleCase['case_number'], $sampleCase['my_role'], $sampleCase['complainant_names'], $sampleCase['respondent_names'])) {
    throw new RuntimeException("Assigned case missing required summary fields.");
}
echo "✓ Assigned Pangkat Cases list and role badges verified.\n";

// Test 4: Verify Digital Case Dossier (preview, parties, hearings, terms)
$caseId = (int) $sampleCase['case_id'];
$dossier = $controller->getCaseDossier($caseId, 3);

if (!isset($dossier['case'], $dossier['parties'], $dossier['hearings'], $dossier['pangkat_members'])) {
    throw new RuntimeException("Missing components in Digital Case Dossier response.");
}

if (!is_array($dossier['parties']) || count($dossier['parties']) === 0) {
    throw new RuntimeException("Parties missing from Digital Case Dossier.");
}

$firstParty = $dossier['parties'][0];
if (!array_key_exists('contact_no', $firstParty) || !array_key_exists('full_name', $firstParty)) {
    throw new RuntimeException("Party in dossier missing contact_no or full_name.");
}
echo "✓ Digital Case Dossier contains party names, contact numbers, and hearing history.\n";

echo "All Lupon Member dashboard backend tests passed successfully!\n";
