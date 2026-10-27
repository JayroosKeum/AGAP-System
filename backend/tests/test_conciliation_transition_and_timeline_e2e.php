<?php

declare(strict_types=1);

/**
 * AGAP Streamlined 2nd Mediation-to-Conciliation Transition, Integrated Conciliation Booking,
 * & Stage-Isolated Officer's Return Lifecycle Integration Test
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/CaseModel.php';
require_once __DIR__ . '/../models/Summons.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/HearingMinutes.php';
require_once __DIR__ . '/../models/CaseStage.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../services/KpFormService.php';
require_once __DIR__ . '/../services/PDFService.php';
require_once __DIR__ . '/../services/PangkatService.php';
require_once __DIR__ . '/../services/SummonDeliveryService.php';
require_once __DIR__ . '/../controllers/GPSController.php';
require_once __DIR__ . '/TestDataCleanup.php';

echo "====================================================================\n";
echo "   CONCILIATION TRANSITION & STAGE-ISOLATED OFFICER'S RETURN TESTS \n";
echo "====================================================================\n\n";

$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);

$passedAssertions = 0;
function testAssert(bool $condition, string $description): void {
    global $passedAssertions;
    if (!$condition) {
        throw new RuntimeException("Assertion Failed: {$description}");
    }
    $passedAssertions++;
    echo "  [PASS] {$description}\n";
}

try {
    $summonsModel = new Summons($db);
    $stageModel = new CaseStage($db);
    $hearingModel = new Hearing($db);
    $deliveryService = new SummonDeliveryService($db);
    $gpsController = new GPSController($db);

    // -------------------------------------------------------------
    // TEST 1: Schedule Checking & Tuesday Restrictions
    // -------------------------------------------------------------
    echo "[TEST 1] Testing Conciliation Schedule Rules (Tuesdays vs Mon/Wed/Thu/Fri)...\n";

    // Find next Tuesday and next Wednesday
    $nextTuesday = new DateTime('next Tuesday');
    $nextWednesday = new DateTime('next Wednesday');
    $nextThursday = new DateTime('next Thursday');

    $tuesdayDateStr = $nextTuesday->format('Y-m-d');
    $wednesdayDateStr = $nextWednesday->format('Y-m-d');
    $thursdayDateStr = $nextThursday->format('Y-m-d');

    // Tuesday afternoon check for Conciliation
    $tuesdayConflict = $summonsModel->findMediationConflict("{$tuesdayDateStr} 14:00", 'Lupon Court Room', null, null, 'Conciliation');
    testAssert($tuesdayConflict !== null, "Tuesday afternoon Conciliation returns conflict flag");
    testAssert(strpos($tuesdayConflict['message'] ?? '', 'Tuesdays reserved exclusively for Punong Barangay Mediation') !== false,
        "Tuesday conflict provides descriptive reason: " . ($tuesdayConflict['message'] ?? ''));

    // Thursday check for Conciliation (should NOT conflict unless already booked)
    $thursdayConflict = $summonsModel->findMediationConflict("{$thursdayDateStr} 14:00", 'Lupon Court Room', null, null, 'Conciliation');
    testAssert($thursdayConflict === null, "Thursday afternoon Conciliation slot has no default Tuesday block");

    // -------------------------------------------------------------
    // TEST 2: Setup Test Complaint & 2 Mediation Sessions
    // -------------------------------------------------------------
    echo "\n[TEST 2] Setting up Test Complaint & Completing 2 Mediation Sessions...\n";

    // Fetch 2 residents for Complainant and Respondent
    $residents = $db->query("SELECT resident_id FROM residents LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    if (count($residents) < 2) {
        throw new RuntimeException("Test requires at least 2 resident records in agap_db.");
    }
    $complainantResidentId = (int) $residents[0]['resident_id'];
    $respondentResidentId = (int) $residents[1]['resident_id'];

    $complaintModel = new Complaint();
    $compCreate = $complaintModel->create([
        'complaint_title' => 'E2E Conciliation Transition Boundary Test',
        'category_id' => 1,
        'case_type' => 'Civil',
        'incident_date' => date('Y-m-d', strtotime('-5 days')),
        'incident_time' => '09:30',
        'incident_street' => 'Block 7 Lot 12, Barangay Tumana',
        'incident_barangay' => 'Tumana',
        'incident_city' => 'Marikina City',
        'narrative' => 'Dispute testing Pangkat appointment, booking, and stage-isolated summons',
        'requested_resolution' => 'Proper boundary inspection and amicable agreement.',
        'complainant_resident_id' => $complainantResidentId,
        'respondent_resident_id' => $respondentResidentId,
        'latitude' => 14.6525,
        'longitude' => 121.0850,
        'duplicate_acknowledged' => 1,
        'jurisdiction_screened' => 1,
        'created_by' => 1
    ]);
    $testComplaintId = (int)($compCreate['complaint_id'] ?? 0);
    testAssert($testComplaintId > 0, "Test complaint created with ID: {$testComplaintId}");
    $cleanup->trackComplaint($testComplaintId);

    // Find open Tuesday slot
    $targetTuesday = new DateTime('+3 weeks Tuesday');
    $medDateStr = $targetTuesday->format('Y-m-d');

    // Docket into active mediation
    $issueRes = $summonsModel->issueFirstSummon($testComplaintId, 1, [
        'mediation_date' => $medDateStr,
        'mediation_time' => '15:00',
        'venue' => 'Lupon Court Room',
        'override_paanyaya' => true
    ]);
    if (empty($issueRes['success'])) {
        var_dump($issueRes);
    }
    testAssert(!empty($issueRes['success']), "Case successfully docketed into Mediation (Case #" . ($issueRes['case_number'] ?? '') . ")");
    $testCaseId = (int)$issueRes['case_id'];

    // Docketing automatically created 1st Mediation summon attempt (Attempt 1).
    // Simulate subsequent failed/unserved delivery attempts for Mediation (KP Form 9, Attempt 2 & Attempt 3)
    $stmtDelivery = $db->prepare(
        "INSERT INTO summon_deliveries (
            hearing_id, case_id, recipient_name, party_type, 
            form_type, attempt_number, delivery_status, remarks, created_at
        ) VALUES (?, ?, ?, 'Respondent', 'KP Form 9', ?, 'Unserved', 'Mediation summon delivery attempt', NOW())"
    );
    $stmtDelivery->execute([null, $testCaseId, 'Mang Berto Gonzales', 2]);
    $delId2 = (int)$db->lastInsertId();

    $stmtDelivery->execute([null, $testCaseId, 'Mang Berto Gonzales', 3]);
    $delId3 = (int)$db->lastInsertId();

    testAssert($delId2 > 0 && $delId3 > 0, "Created unserved Mediation summon attempts (Attempts 2 and 3) for Respondent");

    // Verify Mediation attempts counter returns 3
    $mediationAttempts = $deliveryService->getSummonsAttempts($testCaseId, null, 'Mediation');
    testAssert(count($mediationAttempts) === 3, "Mediation attempts count is 3 (Found: " . count($mediationAttempts) . ")");

    // Complete 1st mediation
    $stmtH = $db->prepare("SELECT * FROM hearings WHERE case_id = ? ORDER BY hearing_id ASC");
    $stmtH->execute([$testCaseId]);
    $hearings = $stmtH->fetchAll(PDO::FETCH_ASSOC);
    $firstHearing = $hearings[0];
    $firstHearingId = (int)$firstHearing['hearing_id'];
    $db->prepare("UPDATE hearings SET status = 'Completed', attendance_recorded_at = NOW() WHERE hearing_id = ?")->execute([$firstHearingId]);

    // Schedule and conclude 2nd mediation
    $med2Date = (new DateTime($medDateStr . ' +7 days'))->format('Y-m-d');
    $med2Res = $hearingModel->createProgression([
        'case_id' => $testCaseId,
        'hearing_type' => 'Mediation',
        'hearing_date' => "{$med2Date} 15:30:00",
        'duration_minutes' => 45,
        'venue' => 'Lupon Court Room',
        'remarks' => '2nd Mediation Hearing before Punong Barangay'
    ], null, 1);
    testAssert(!empty($med2Res['success']), "2nd Mediation session scheduled");
    $secondHearingId = (int)$med2Res['hearing_id'];
    $db->prepare("UPDATE hearings SET status = 'Completed', attendance_recorded_at = NOW() WHERE hearing_id = ?")->execute([$secondHearingId]);
    testAssert(true, "Completed 2 Mediation sessions; eligible for Pangkat elevation");

    // -------------------------------------------------------------
    // TEST 3: Pangkat Referral with Integrated Conciliation Booking
    // -------------------------------------------------------------
    echo "\n[TEST 3] Testing Pangkat Referral with Conciliation Booking & Form Generation...\n";

    // Fetch 3 active lupon members
    $stmtUsers = $db->query("
        SELECT u.user_id 
        FROM users u 
        INNER JOIN roles r ON r.role_id = u.role_id 
        WHERE r.role_name = 'Lupon Member' AND u.status = 'Active' 
        ORDER BY u.user_id ASC LIMIT 3
    ");
    $luponMembers = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
    if (count($luponMembers) < 3) {
        $stmtUsers = $db->query("SELECT user_id FROM users WHERE status = 'Active' ORDER BY user_id ASC LIMIT 3");
        $luponMembers = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
    }
    testAssert(count($luponMembers) >= 3, "Found 3 users for Pangkat panel");

    // Attempt referral on a Tuesday — must fail
    $tuesdayRes = $stageModel->referToPangkat($testCaseId, [
        'referral_date' => date('Y-m-d'),
        'referral_reason' => 'Parties unable to settle in mediation.',
        'selection_method' => 'Party Agreement',
        'chairman_id' => (int)$luponMembers[0],
        'secretary_id' => (int)$luponMembers[1],
        'member_id' => (int)$luponMembers[2],
        'conciliation_date' => $tuesdayDateStr,
        'conciliation_time' => '14:00',
        'venue' => 'Lupon Court Room'
    ], 1);
    testAssert(empty($tuesdayRes['success']), "Referral with Tuesday conciliation booking was properly rejected: " . ($tuesdayRes['message'] ?? ''));
    testAssert(strpos($tuesdayRes['message'] ?? '', 'Tuesdays reserved exclusively for Punong Barangay Mediation') !== false,
        "Tuesday rejection gives correct statutory reason");

    // Refer with valid Thursday date
    $referralResult = $stageModel->referToPangkat($testCaseId, [
        'referral_date' => date('Y-m-d'),
        'referral_reason' => 'Parties unable to settle in mediation.',
        'selection_method' => 'Party Agreement',
        'chairman_id' => (int)$luponMembers[0],
        'secretary_id' => (int)$luponMembers[1],
        'member_id' => (int)$luponMembers[2],
        'conciliation_date' => $thursdayDateStr,
        'conciliation_time' => '14:00',
        'venue' => 'Lupon Court Room'
    ], 1);

    testAssert($referralResult['success'] === true, "Referral with conciliation booking succeeded: " . ($referralResult['message'] ?? ''));
    testAssert(!empty($referralResult['hearing_id']), "1st Conciliation hearing created with ID: " . $referralResult['hearing_id']);
    $conciliationHearingId = (int)$referralResult['hearing_id'];

    testAssert(!empty($referralResult['kp10_document_id']), "KP Form 10 generated (Doc ID: {$referralResult['kp10_document_id']})");
    testAssert(!empty($referralResult['kp11_document_id']), "KP Form 11 generated (Doc ID: {$referralResult['kp11_document_id']})");
    testAssert(!empty($referralResult['kp12_document_id']), "KP Form 12 generated (Doc ID: {$referralResult['kp12_document_id']})");

    // Verify Case & Complaint status are now Conciliation
    $caseModel = new CaseModel($db);
    $caseData = $caseModel->getById($testCaseId);
    testAssert($caseData['case_status'] === 'Conciliation', "Case status updated to 'Conciliation'");

    $complaintData = $complaintModel->getById($testComplaintId);
    testAssert($complaintData['status'] === 'Conciliation', "Complaint status updated to 'Conciliation'");

    // -------------------------------------------------------------
    // TEST 4: Auto-Registration of KP Form 12 Officer's Return Records
    // -------------------------------------------------------------
    echo "\n[TEST 4] Testing KP Form 12 Officer's Return Auto-Registration...\n";

    $stmtCheckReturns = $db->prepare(
        "SELECT * FROM summon_deliveries WHERE hearing_id = ? AND form_type = 'KP Form 12' ORDER BY delivery_id ASC"
    );
    $stmtCheckReturns->execute([$conciliationHearingId]);
    $kp12Deliveries = $stmtCheckReturns->fetchAll(PDO::FETCH_ASSOC);

    testAssert(count($kp12Deliveries) === 2, "Exactly 2 KP Form 12 Officer's Return records auto-registered (Found: " . count($kp12Deliveries) . ")");

    $complainantReturn = null;
    $respondentReturn = null;
    foreach ($kp12Deliveries as $del) {
        if ($del['party_type'] === 'Complainant') $complainantReturn = $del;
        if ($del['party_type'] === 'Respondent') $respondentReturn = $del;
    }

    testAssert($complainantReturn !== null, "Complainant KP Form 12 return record exists");
    testAssert($respondentReturn !== null, "Respondent KP Form 12 return record exists");

    testAssert((int)$complainantReturn['attempt_number'] === 1, "Complainant attempt number is 1");
    testAssert((int)$respondentReturn['attempt_number'] === 1, "Respondent attempt number is 1");
    testAssert($complainantReturn['delivery_status'] === 'Pending', "Complainant return status is 'Pending'");
    testAssert($respondentReturn['delivery_status'] === 'Pending', "Respondent return status is 'Pending'");
    testAssert(strpos($complainantReturn['remarks'], 'KP Form 12') !== false, "Remarks stamped with KP Form 12: " . $complainantReturn['remarks']);

    // -------------------------------------------------------------
    // TEST 5: Stage-Isolated Officer's Return Attempt Counters
    // -------------------------------------------------------------
    echo "\n[TEST 5] Testing Stage-Isolated Attempt Counter Logic...\n";

    // Mediation had 3 attempts. Conciliation has 1 attempt.
    // Querying Conciliation stage attempts MUST return 1, NOT 4!
    $concilAttempts = $deliveryService->getSummonsAttempts($testCaseId, $conciliationHearingId, 'Conciliation');
    testAssert(count($concilAttempts) === 1, "Conciliation attempt counter is stage-isolated at 1 (NOT polluted by mediation 3 attempts; count: " . count($concilAttempts) . ")");

    $medAttemptsAfter = $deliveryService->getSummonsAttempts($testCaseId, null, 'Mediation');
    testAssert(count($medAttemptsAfter) === 3, "Mediation attempts count remains unchanged at 3");

    // -------------------------------------------------------------
    // TEST 6: Chronological Case Service History & Timeline API
    // -------------------------------------------------------------
    echo "\n[TEST 6] Testing Case Service History & Delivered Forms Timeline...\n";

    $timeline = $gpsController->getCaseServiceTimeline($testCaseId);
    testAssert(!empty($timeline['success']), "Timeline API reports success");
    testAssert(isset($timeline['stages']), "Timeline returns stages array");
    testAssert(count($timeline['stages']) === 3, "Timeline contains 3 stages (Stage 0, Stage 1, Stage 2)");

    $stage0 = $timeline['stages'][0];
    $stage1 = $timeline['stages'][1];
    $stage2 = $timeline['stages'][2];

    testAssert($stage0['stage_name'] === 'Stage 0: Paanyaya', "Stage 0 is Paanyaya");
    testAssert($stage1['stage_name'] === 'Stage 1: Mediation', "Stage 1 is Mediation");
    testAssert($stage2['stage_name'] === 'Stage 2: Conciliation', "Stage 2 is Conciliation");

    // Check Stage 1 (Mediation)
    testAssert(!empty($stage1['items']), "Stage 1 (Mediation) has service records");
    testAssert(count($stage1['items']) >= 3, "Stage 1 contains the mediation summons records");

    // Check Stage 2 (Conciliation)
    testAssert(!empty($stage2['items']), "Stage 2 (Conciliation) has service records");
    $stage2Records = $stage2['items'];
    testAssert(count($stage2Records) >= 2, "Stage 2 contains conciliation notice records");

    // Check KP Form 12 items
    $kp12TimelineItems = array_filter($stage2Records, fn($item) => $item['document_code'] === 'KP Form 12');
    testAssert(!empty($kp12TimelineItems), "Stage 2 includes KP Form 12 items");

    $firstKp12 = array_values($kp12TimelineItems)[0];
    testAssert($firstKp12['stage'] === 'Conciliation', "Stage 2 record stage is Conciliation");
    testAssert(isset($firstKp12['status_pill']), "Stage 2 record includes formatted status_pill: " . $firstKp12['status_pill']);
    testAssert(isset($firstKp12['preview_url']), "Stage 2 record includes preview_url: " . $firstKp12['preview_url']);

    echo "\n====================================================================\n";
    echo "   ALL CONCILIATION TRANSITION & TIMELINE ASSERTIONS PASSED! ({$passedAssertions} passed)\n";
    echo "====================================================================\n\n";

} catch (Throwable $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    echo "Cleaning up test records...\n";
    $cleanup->cleanup();
    echo "Teardown complete.\n";
}
