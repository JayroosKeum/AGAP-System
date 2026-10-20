<?php

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/models/Paanyaya.php';
require_once __DIR__ . '/../backend/models/Summons.php';
require_once __DIR__ . '/../backend/models/CaseProgress.php';
require_once __DIR__ . '/../backend/controllers/ComplaintController.php';
require_once __DIR__ . '/../backend/services/PDFService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;
$_SESSION['username'] = 'admin_tester';

$db = new Database();
$conn = $db->connect();

echo "====================================================\n";
echo " AGAP E2E VERIFICATION: PAANYAYA 1 & 2 WORKFLOW \n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition($name, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $name\n";
        $passCount++;
    } else {
        echo " [FAIL] $name" . ($details ? ": $details" : "") . "\n";
        $failCount++;
    }
}

// Find a valid category
$catId = (int)($conn->query('SELECT category_id FROM complaint_categories LIMIT 1')->fetchColumn() ?: 1);

// Step 1: Create a dedicated test complaint
$cStmt = $conn->prepare("INSERT INTO complaints (complaint_number, complaint_title, narrative, status, category_id, created_at) 
                         VALUES (?, 'E2E Paanyaya Test Dispute', 'Boundary fence dispute narrative for E2E testing', 'Pending Verification', ?, NOW())");
$compNum = 'TEST-CMP-PAA-' . time();
$cStmt->execute([$compNum, $catId]);
$testComplaintId = (int)$conn->lastInsertId();

// Add parties
$conn->exec("INSERT INTO residents (first_name, last_name, address, purok, contact_no) VALUES ('Juan', 'Dela Cruz', '123 Sampaguita St', 'Purok 1', '09123456789')");
$resId1 = (int)$conn->lastInsertId();
$conn->exec("INSERT INTO residents (first_name, last_name, address, purok, contact_no) VALUES ('Pedro', 'Penduko', '456 Rosal St', 'Purok 2', '09987654321')");
$resId2 = (int)$conn->lastInsertId();

$conn->exec("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES ($testComplaintId, $resId1, 'Complainant')");
$conn->exec("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES ($testComplaintId, $resId2, 'Respondent')");

assertCondition("Test complaint created (ID: $testComplaintId, Num: $compNum)", $testComplaintId > 0);

$paaModel = new Paanyaya($conn);
$progressModel = new CaseProgress($conn);
$summonsModel = new Summons($conn);

// Step 2: Initial State Check
$initData = $paaModel->getTwoStagesData($testComplaintId);
assertCondition("Stage 1 starts as 'Not Started'", $initData['stage_1']['details']['status'] === 'Not Started');
assertCondition("Stage 1 can_issue is true", $initData['stage_1']['can_issue'] === true);
assertCondition("Stage 2 is locked (!is_eligible)", $initData['stage_2']['is_eligible'] === false);
assertCondition("Summons prerequisite is blocked (allowed = false)", $initData['summons_prerequisite']['allowed'] === false);

// Check Summons button gating via CaseProgress
$progressData = $progressModel->getCaseProgress($testComplaintId);
$sumBtn = $progressData['actions']['summon_button'];
assertCondition("CaseProgress gates summon button", !empty($sumBtn['disabled']) && $sumBtn['disabled'] === true);
assertCondition("CaseProgress identifies paanyaya lock", !empty($sumBtn['is_locked_by_paanyaya']) && $sumBtn['is_locked_by_paanyaya'] === true);

// Step 3: Issue Paanyaya 1
$issueRes = $paaModel->issueStage($testComplaintId, 'Paanyaya 1', [
    'invitation_date' => date('Y-m-d', strtotime('+3 days')),
    'invitation_time' => '14:00',
    'assigned_officer_id' => 1,
    'venue' => 'Barangay Tumana Multi-Purpose Hall',
    'salaysay' => 'Preliminary informal conciliation dialogue',
    'has_e_signature' => 1
], 1);
assertCondition("Issue Paanyaya 1 succeeded", $issueRes['success'] === true);

$dataAfterIssue1 = $paaModel->getTwoStagesData($testComplaintId);
assertCondition("Stage 1 status is 'In Progress'", $dataAfterIssue1['stage_1']['details']['status'] === 'In Progress');
assertCondition("Stage 1 attempt count is 0", $dataAfterIssue1['stage_1']['attempts_count'] === 0);
assertCondition("Stage 1 can_record_attempt is true", $dataAfterIssue1['stage_1']['can_record_attempt'] === true);
assertCondition("Summons STILL blocked after issuing Paanyaya 1", $dataAfterIssue1['summons_prerequisite']['allowed'] === false);

// Step 4: Record Unsuccessful Returns 1 to 5
for ($i = 1; $i <= 5; $i++) {
    $retRes = $paaModel->recordOfficerReturn($testComplaintId, 'Paanyaya 1', [
        'service_datetime' => date('Y-m-d H:i:s', strtotime("+$i hours")),
        'serving_officer_id' => 1,
        'recipient_name' => 'Pedro Penduko',
        'service_address' => '456 Rosal St, Purok 2, Tumana',
        'delivery_outcome' => 'Recipient unavailable',
        'unsuccessful_reason' => "Attempt #$i: Gate padlocked, respondent at work",
        'remarks' => "Left contact card at mailbox for attempt #$i",
        'latitude' => '14.652100',
        'longitude' => '121.085400'
    ], 1);
    assertCondition("Record Return Attempt #$i succeeded (attempt_number: {$retRes['attempt_number']})", 
        $retRes['success'] === true && (int)$retRes['attempt_number'] === $i);
}

$dataAfter5 = $paaModel->getTwoStagesData($testComplaintId);
assertCondition("Stage 1 has exactly 5 attempts recorded", $dataAfter5['stage_1']['attempts_count'] === 5);
assertCondition("Stage 1 status remains 'In Progress'", $dataAfter5['stage_1']['details']['status'] === 'In Progress');
assertCondition("Summons prerequisite STILL blocked after 5 attempts", $dataAfter5['summons_prerequisite']['allowed'] === false);

// Step 5: Record Attempt 6 (Exhaustion of attempts)
$ret6 = $paaModel->recordOfficerReturn($testComplaintId, 'Paanyaya 1', [
    'service_datetime' => date('Y-m-d H:i:s', strtotime('+6 hours')),
    'serving_officer_id' => 1,
    'recipient_name' => 'Pedro Penduko',
    'service_address' => '456 Rosal St, Purok 2, Tumana',
    'delivery_outcome' => 'Recipient refused to receive',
    'unsuccessful_reason' => 'Respondent explicitly refused receipt',
    'remarks' => 'Respondent stated he will not accept preliminary invitation',
    'latitude' => '14.652100',
    'longitude' => '121.085400'
], 1);
assertCondition("Record Return Attempt #6 succeeded", $ret6['success'] === true && (int)$ret6['attempt_number'] === 6);

$dataAfter6 = $paaModel->getTwoStagesData($testComplaintId);
assertCondition("Stage 1 status transitioned to 'Attempts Exhausted – For Review'", 
    $dataAfter6['stage_1']['details']['status'] === 'Attempts Exhausted – For Review');
assertCondition("Stage 1 needs_review is true", $dataAfter6['stage_1']['needs_review'] === true);
assertCondition("Summons UNLOCKED after 6 failed attempts on Paanyaya 1", $dataAfter6['summons_prerequisite']['allowed'] === true);

// Step 6: Test Attempt 7 Rejection (Max 6 limit server-side safeguard)
$ret7 = $paaModel->recordOfficerReturn($testComplaintId, 'Paanyaya 1', [
    'service_datetime' => date('Y-m-d H:i:s', strtotime('+7 hours')),
    'serving_officer_id' => 1,
    'recipient_name' => 'Pedro Penduko',
    'service_address' => '456 Rosal St, Purok 2, Tumana',
    'delivery_outcome' => 'Recipient unavailable',
    'unsuccessful_reason' => 'Attempt 7 should fail',
    'remarks' => 'Should be rejected'
], 1);
assertCondition("Attempt 7 correctly rejected by server-side limit", 
    $ret7['success'] === false && strpos($ret7['message'], 'Maximum') !== false);

// Step 7: Test Permitted Return Correction & Audit Log
$return1Id = (int)($dataAfter6['stage_1']['attempts'][0]['return_id'] ?? $dataAfter6['stage_1']['attempts'][0]['id']);
$updateRes = $paaModel->updateOfficerReturn($return1Id, [
    'service_address' => '456 Rosal St, Purok 2, Tumana (Near Barangay Hall)',
    'remarks' => 'Left contact card with neighbor Aling Nena',
    'correction_notes' => 'Corrected specific landmark and identified neighbor'
], 1);
assertCondition("Update Officer's Return succeeded", $updateRes['success'] === true);

$detailRes = $paaModel->getOfficerReturnDetails($return1Id);
assertCondition("Return details show updated address", strpos($detailRes['service_address'], 'Near Barangay Hall') !== false);
assertCondition("Return audit trail records correction", !empty($detailRes['corrections']) && count($detailRes['corrections']) >= 1);

// Step 8: Authorized Lupon Review Decision -> Authorize Paanyaya 2
$reviewRes = $paaModel->reviewStage($testComplaintId, 'Paanyaya 1', [
    'decision' => 'Approved for Paanyaya 2',
    'review_notes' => 'Lupon decided to extend 2nd preliminary invitation before formal court-annexed docketing.'
], 1);
assertCondition("Review decision 'Approved for Paanyaya 2' recorded", $reviewRes['success'] === true);

$dataAfterReview = $paaModel->getTwoStagesData($testComplaintId);
assertCondition("Stage 1 marked Completed/Approved", $dataAfterReview['stage_1']['is_completed'] === true);
assertCondition("Stage 2 is now unlocked (is_eligible = true)", $dataAfterReview['stage_2']['is_eligible'] === true);
assertCondition("Stage 2 can_issue is true", $dataAfterReview['stage_2']['can_issue'] === true);
assertCondition("Summons STILL blocked because Paanyaya 2 is required", $dataAfterReview['summons_prerequisite']['allowed'] === false);

// Step 9: Issue Paanyaya 2
$issue2Res = $paaModel->issueStage($testComplaintId, 'Paanyaya 2', [
    'invitation_date' => date('Y-m-d', strtotime('+5 days')),
    'invitation_time' => '10:00',
    'assigned_officer_id' => 1,
    'venue' => 'Barangay Tumana Multi-Purpose Hall',
    'salaysay' => 'Second preliminary informal conciliation dialogue',
    'has_e_signature' => 1
], 1);
assertCondition("Issue Paanyaya 2 succeeded", $issue2Res['success'] === true);

$dataAfterIssue2 = $paaModel->getTwoStagesData($testComplaintId);
assertCondition("Stage 2 status is 'In Progress'", $dataAfterIssue2['stage_2']['details']['status'] === 'In Progress');
assertCondition("Stage 2 attempt counter starts at 0 (INDEPENDENT from Stage 1)", $dataAfterIssue2['stage_2']['attempts_count'] === 0);
assertCondition("Stage 1 attempt count remains 6 (not overwritten)", $dataAfterIssue2['stage_1']['attempts_count'] === 6);

// Step 10: Record Paanyaya 2 Attempt 1 (Unsuccessful)
$p2Ret1 = $paaModel->recordOfficerReturn($testComplaintId, 'Paanyaya 2', [
    'service_datetime' => date('Y-m-d H:i:s'),
    'serving_officer_id' => 1,
    'recipient_name' => 'Pedro Penduko',
    'service_address' => '456 Rosal St, Purok 2, Tumana',
    'delivery_outcome' => 'Recipient unavailable',
    'unsuccessful_reason' => 'P2 Attempt 1: Respondent not home',
    'remarks' => 'Left second invitation notice'
], 1);
assertCondition("Paanyaya 2 Attempt 1 recorded as Attempt #1", 
    $p2Ret1['success'] === true && (int)$p2Ret1['attempt_number'] === 1);

// Step 11: Record Paanyaya 2 Attempt 2 (Successfully Delivered!)
$p2Ret2 = $paaModel->recordOfficerReturn($testComplaintId, 'Paanyaya 2', [
    'service_datetime' => date('Y-m-d H:i:s'),
    'serving_officer_id' => 1,
    'recipient_name' => 'Pedro Penduko',
    'service_address' => '456 Rosal St, Purok 2, Tumana',
    'delivery_outcome' => 'Successfully delivered',
    'remarks' => 'Respondent personally received Paanyaya 2 letter and signed receiving copy'
], 1);
assertCondition("Paanyaya 2 Attempt 2 recorded as Attempt #2 (Successfully delivered)", 
    $p2Ret2['success'] === true && (int)$p2Ret2['attempt_number'] === 2);

$dataAfterP2Delivered = $paaModel->getTwoStagesData($testComplaintId);
assertCondition("Stage 2 status transitioned to 'Successfully Delivered'", 
    $dataAfterP2Delivered['stage_2']['details']['status'] === 'Successfully Delivered');
assertCondition("Stage 2 attempts count is 2", $dataAfterP2Delivered['stage_2']['attempts_count'] === 2);
assertCondition("Stage 1 attempts count remains 6", $dataAfterP2Delivered['stage_1']['attempts_count'] === 6);

// Step 12: Check Summons Prerequisite Unlock
$finalPrereq = $paaModel->checkSummonsPrerequisite($testComplaintId);
assertCondition("Summons Prerequisite is now ALLOWED (allowed = true)", $finalPrereq['allowed'] === true);

// Verify Summons.php model enforcement
$attemptSummonRes = $summonsModel->issueFirstSummon($testComplaintId, 1, [
    'hearing_date' => date('Y-m-d', strtotime('+7 days')),
    'hearing_time' => '14:00',
    'hearing_venue' => 'Barangay Hall'
]);
// Even if case is not yet docketed or docketed, check that it passed the Paanyaya prerequisite check
assertCondition("Summons model does NOT fail on Paanyaya prerequisite", 
    !isset($attemptSummonRes['message']) || strpos($attemptSummonRes['message'], 'Paanyaya') === false);

// Step 13: Test PDF Preview & Download for Paanyaya 1 & Paanyaya 2
$pdfService = new PDFService();
$p1Html = $pdfService->renderPaanyayaHtml($testComplaintId, ['stage_type' => 'Paanyaya 1']);
assertCondition("Paanyaya 1 HTML renders properly", !empty($p1Html) && stripos($p1Html, 'paanyaya') !== false);

$p2Html = $pdfService->renderPaanyayaHtml($testComplaintId, ['stage_type' => 'Paanyaya 2']);
assertCondition("Paanyaya 2 HTML renders properly", !empty($p2Html) && stripos($p2Html, 'paanyaya') !== false);

// Step 14: Direct Unlock Test (Paanyaya 1 served unlocks summons immediately)
$cStmt->execute(['TEST-CMP-PAA-SERVE-' . time(), $catId]);
$servedComplaintId = (int)$conn->lastInsertId();
$conn->exec("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES ($servedComplaintId, $resId1, 'Complainant')");
$conn->exec("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES ($servedComplaintId, $resId2, 'Respondent')");

$paaModel->issueStage($servedComplaintId, 'Paanyaya 1', [
    'invitation_date' => date('Y-m-d', strtotime('+3 days')),
    'invitation_time' => '10:00',
    'assigned_officer_id' => 1,
    'venue' => 'Barangay Tumana Multi-Purpose Hall',
    'salaysay' => 'Direct served verification'
], 1);

// Attempt 1 unserved: Summons locked
$paaModel->recordOfficerReturn($servedComplaintId, 'Paanyaya 1', [
    'service_datetime' => date('Y-m-d H:i:s'),
    'serving_officer_id' => 1,
    'recipient_name' => 'Pedro Penduko',
    'service_address' => '456 Rosal St, Purok 2, Tumana',
    'delivery_outcome' => 'Recipient unavailable',
    'unsuccessful_reason' => 'Not home'
], 1);
$prereq1 = $paaModel->checkSummonsPrerequisite($servedComplaintId);
assertCondition("Summons LOCKED when Paanyaya 1 Attempt 1 is unserved", $prereq1['allowed'] === false);

// Attempt 2 served: Summons unlocked
$paaModel->recordOfficerReturn($servedComplaintId, 'Paanyaya 1', [
    'service_datetime' => date('Y-m-d H:i:s'),
    'serving_officer_id' => 1,
    'recipient_name' => 'Pedro Penduko',
    'service_address' => '456 Rosal St, Purok 2, Tumana',
    'delivery_outcome' => 'Successfully delivered',
    'remarks' => 'Respondent personally received Paanyaya 1 invitation'
], 1);
$prereq2 = $paaModel->checkSummonsPrerequisite($servedComplaintId);
assertCondition("Summons UNLOCKED when Paanyaya 1 is Successfully Delivered", $prereq2['allowed'] === true);

echo "\n====================================================\n";
echo " TEST SUMMARY: $passCount Passed, $failCount Failed\n";
echo "====================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
