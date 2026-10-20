<?php

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/models/ProofOfService.php';
require_once __DIR__ . '/../backend/controllers/GPSController.php';
require_once __DIR__ . '/../backend/models/Paanyaya.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

$db = (new Database())->connect();
$proofModel = new ProofOfService($db);
$gpsCtrl = new GPSController();
$paaModel = new Paanyaya($db);

echo "========================================================\n";
echo " TEST: PROOF OF SERVICE PAANYAYA INTEGRATION \n";
echo "========================================================\n\n";

$pass = 0;
$fail = 0;
function testAssert($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $name\n";
        $pass++;
    } else {
        echo " [FAIL] $name" . ($detail ? ": $detail" : "") . "\n";
        $fail++;
    }
}

// 1. Create a test complaint in Paanyaya
$catId = (int)($db->query('SELECT category_id FROM complaint_categories LIMIT 1')->fetchColumn() ?: 1);
$cStmt = $db->prepare("INSERT INTO complaints (complaint_number, complaint_title, narrative, status, category_id, created_at) 
                       VALUES (?, 'Proof of Service Paanyaya Test', 'Testing proof of service integration', 'Pre-Docketed (Paanyaya)', ?, NOW())");
$compNum = 'CMP-PROOF-TEST-' . time();
$cStmt->execute([$compNum, $catId]);
$testCompId = (int)$db->lastInsertId();

// Add parties
$db->exec("INSERT INTO residents (first_name, last_name, address, purok, contact_no) VALUES ('Carlos', 'Santana', '101 Music Ave', 'Purok 3', '09112223334')");
$resCompId = (int)$db->lastInsertId();
$db->exec("INSERT INTO residents (first_name, last_name, address, purok, contact_no) VALUES ('Diego', 'Silang', '202 Heroes St', 'Purok 4', '09556667778')");
$resRespId = (int)$db->lastInsertId();

$db->exec("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES ($testCompId, $resCompId, 'Complainant')");
$db->exec("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES ($testCompId, $resRespId, 'Respondent')");

// Issue Paanyaya 1
$paaModel->issueStage($testCompId, 'Paanyaya 1', [
    'invitation_date' => date('Y-m-d', strtotime('+2 weekdays')),
    'invitation_time' => '14:00',
    'assigned_officer_id' => 1,
    'venue' => 'Barangay Tumana Multi-Purpose Hall',
    'salaysay' => 'Preliminary dialogue test'
], 1);

testAssert("Paanyaya test complaint created (ID: $testCompId)", $testCompId > 0);

// 2. Test getAvailableCases() includes this Paanyaya complaint
$availableCases = $proofModel->getAvailableCases();
$foundPaa = null;
foreach ($availableCases as $c) {
    if ((int)($c['complaint_id'] ?? 0) === $testCompId || (int)($c['case_id'] ?? 0) === $testCompId) {
        $foundPaa = $c;
        break;
    }
}
testAssert("ProofOfService::getAvailableCases() includes Paanyaya complaint", !empty($foundPaa));
testAssert("Paanyaya case identified with is_paanyaya = 1", !empty($foundPaa['is_paanyaya']));
testAssert("Respondent names present on Paanyaya record", strpos($foundPaa['respondent_names'], 'Diego Silang') !== false);
testAssert("Paanyaya record service status is 'Pending'", $foundPaa['respondent_service_status'] === 'Pending');

// 3. Test GPSController::caseDeliveries for Paanyaya
$delivResult = $gpsCtrl->caseDeliveries($testCompId);
testAssert("GPSController::caseDeliveries succeeded for Paanyaya", $delivResult['success'] === true);
testAssert("Case deliveries has_hearings is true", !empty($delivResult['data']['has_hearings']));
$hearing = $delivResult['data']['hearings'][0] ?? [];
testAssert("Hearing is marked is_paanyaya = true", !empty($hearing['is_paanyaya']));
testAssert("Hearing type is 'Paanyaya 1'", ($hearing['hearing_type'] ?? '') === 'Paanyaya 1');
testAssert("Respondent attempts has 1 pending attempt", count($hearing['respondent_attempts'] ?? []) === 1 && !empty($hearing['respondent_attempts'][0]['is_pending']));

// 4. Test GPSController::recordOfficerReturn for Paanyaya
$retInput = [
    'case_id' => $testCompId,
    'hearing_id' => $hearing['hearing_id'],
    'is_paanyaya' => 1,
    'stage_type' => 'Paanyaya 1',
    'delivery_status' => 'Unserved',
    'unserved_reason' => 'No One Home',
    'failure_notes' => 'Gate locked, neighbor verified at work',
    'served_at' => date('Y-m-d H:i:s'),
    'recipient_name' => 'Diego Silang',
    'remarks' => 'Left first invitation calling card'
];
$recordResult = $gpsCtrl->recordOfficerReturn($retInput, [], 1);
testAssert("GPSController::recordOfficerReturn succeeded for Paanyaya", $recordResult['success'] === true);
testAssert("Attempt #1 recorded", (int)($recordResult['attempt_number'] ?? 0) === 1);

// Re-fetch deliveries
$delivResult2 = $gpsCtrl->caseDeliveries($testCompId);
$attempts2 = $delivResult2['data']['hearings'][0]['respondent_attempts'] ?? [];
testAssert("Attempts list now has recorded Attempt 1 + pending Attempt 2", count($attempts2) === 2);
testAssert("Attempt 1 is recorded as unserved", $attempts2[0]['is_failed'] === true);
testAssert("Attempt 2 is pending", $attempts2[1]['is_pending'] === true && (int)$attempts2[1]['attempt_number'] === 2);

// 5. Test Successfully Delivered via Proof of Service
$retInputServed = [
    'case_id' => $testCompId,
    'hearing_id' => $hearing['hearing_id'],
    'is_paanyaya' => 1,
    'stage_type' => 'Paanyaya 1',
    'delivery_status' => 'Served Personal',
    'served_at' => date('Y-m-d H:i:s'),
    'recipient_name' => 'Diego Silang',
    'remarks' => 'Personal service received and signed'
];
$recordResultServed = $gpsCtrl->recordOfficerReturn($retInputServed, [], 1);
testAssert("Record Attempt 2 as Served succeeded", $recordResultServed['success'] === true);

// Verify Summons is unlocked
$prereq = $paaModel->checkSummonsPrerequisite($testCompId);
testAssert("Summons is now UNLOCKED after Paanyaya service recorded via Proof of Service", $prereq['allowed'] === true);

echo "\n========================================================\n";
echo " SUMMARY: $pass Passed, $fail Failed\n";
echo "========================================================\n";

if ($fail > 0) exit(1);
exit(0);
