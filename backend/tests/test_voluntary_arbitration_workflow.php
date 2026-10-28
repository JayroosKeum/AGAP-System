<?php

require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/models/CaseModel.php';
require_once __DIR__ . '/../../backend/models/CaseStage.php';
require_once __DIR__ . '/../../backend/models/Hearing.php';
require_once __DIR__ . '/../../backend/controllers/ArbitrationController.php';
require_once __DIR__ . '/../../backend/services/KpFormService.php';

$db = (new Database())->connect();

echo "====================================================================\n";
echo "   TESTING VOLUNTARY ARBITRATION WORKFLOW & STATUTORY VALIDATION    \n";
echo "====================================================================\n";

function testAssert($condition, $message) {
    if ($condition) {
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
}

// 1. Setup a test complaint and case
$db->prepare("DELETE FROM arbitration_records WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'ARB-TEST-%')")->execute();
$db->prepare("DELETE FROM hearings WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'ARB-TEST-%')")->execute();
$db->prepare("DELETE FROM case_stages WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'ARB-TEST-%')")->execute();
$db->prepare("DELETE FROM cases WHERE case_number LIKE 'ARB-TEST-%'")->execute();
$db->prepare("DELETE FROM complaints WHERE complaint_title = 'Voluntary Arbitration Test Case'")->execute();

$insComp = $db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES ('CMP-ARB-001', 'Voluntary Arbitration Test Case', 'Mediation', 1, 'Civil', CURDATE(), 'Test arbitration dispute', 1, NOW())
");
$insComp->execute();
$complaintId = (int) $db->lastInsertId();

// Apply migrations if needed
$colInfo = $db->query("SHOW COLUMNS FROM case_stages LIKE 'outcome'")->fetch(PDO::FETCH_ASSOC);
if (strpos($colInfo['Type'] ?? '', 'Arbitration') === false) {
    $db->exec("ALTER TABLE case_stages MODIFY COLUMN outcome ENUM('Settled', 'Unsuccessful', 'Repudiated', 'Dismissed', 'Referred to Pangkat', 'Arbitration', 'Arbitration Award', 'Pending') NOT NULL DEFAULT 'Pending'");
}
$colMin = $db->query("SHOW COLUMNS FROM hearing_minutes LIKE 'session_outcome'")->fetch(PDO::FETCH_ASSOC);
if (strpos($colMin['Type'] ?? '', 'Arbitration') === false) {
    $db->exec("ALTER TABLE hearing_minutes MODIFY COLUMN session_outcome ENUM('Settled', 'Continue Mediation', 'Continue Conciliation', 'Continue Arbitration', 'Failed', 'Party Absent', 'Rescheduled', 'Elevate to Pangkat', 'Pending CFA', 'Arbitration', 'Arbitration Award', 'Repudiated', 'Pending') NOT NULL DEFAULT 'Pending'");
}

$insCase = $db->prepare("
    INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at)
    VALUES (?, 'ARB-TEST-001', 'Mediation', CURDATE(), NOW())
");
$insCase->execute([$complaintId]);
$caseId = (int) $db->lastInsertId();

echo "\n[STEP 1] Recording Stage Outcome as Voluntary Arbitration (KP Form 14)...\n";
$stageModel = new CaseStage($db);
$resOutcome = $stageModel->recordOutcome($caseId, 'Mediation', 'Arbitration', 'Parties voluntarily submitted to binding arbitration', 1);
if (empty($resOutcome['success'])) {
    echo "ERROR DETAIL: " . ($resOutcome['message'] ?? 'Unknown error') . "\n";
}
testAssert(!empty($resOutcome['success']), "Stage outcome recorded as Arbitration");

// Check case status and arbitration record
$caseStatus = $db->query("SELECT case_status FROM cases WHERE case_id = {$caseId}")->fetchColumn();
testAssert($caseStatus === 'Arbitration', "Case status advanced to 'Arbitration'");

$arbRow = (new ArbitrationController())->getByCase($caseId);
testAssert(!empty($arbRow), "Arbitration record exists in arbitration_records");
testAssert(!empty($arbRow['agreement_date']), "agreement_date recorded (Day 0: {$arbRow['agreement_date']})");

echo "\n[STEP 2] Verifying KP Form 14 and KP Form 17 Eligibility...\n";
$kpService = new KpFormService($db);
$prereq14 = $kpService->checkPrerequisites('KP Form 14', $caseId);
testAssert($prereq14['eligible'], "KP Form 14 is unlocked and eligible upon arbitration agreement");

$prereq17 = $kpService->checkPrerequisites('KP Form 17', $caseId);
testAssert($prereq17['eligible'], "KP Form 17 is accessible during arbitration stage for repudiation");

$prereq15Before = $kpService->checkPrerequisites('KP Form 15', $caseId);
testAssert(!$prereq15Before['eligible'], "KP Form 15 is locked before award is rendered");

echo "\n[STEP 3] Scheduling Segregated Arbitration Hearings (Flexible Scheduling)...\n";
$hearingModel = new Hearing($db);

// Schedule 1st Arbitration Hearing on same day / tomorrow
$schedDate = date('Y-m-d 09:30:00', strtotime('+1 weekday'));
$vProg = $hearingModel->validateHearingDateProgression($caseId, $schedDate, null, 'Arbitration');
testAssert($vProg['valid'], "Arbitration date progression validated successfully");

$hData = [
    'case_id' => $caseId,
    'hearing_type' => 'Arbitration',
    'hearing_date' => $schedDate,
    'venue' => 'Lupon Court Room',
    'remarks' => '1st Arbitration Hearing to receive party claims and evidence'
];
$createdH = $hearingModel->createProgression($hData, null, 1);
testAssert(!empty($createdH['success']), "1st Arbitration Hearing scheduled successfully");
testAssert($createdH['hearing_type'] === 'Arbitration', "Hearing type strictly segregated as 'Arbitration'");

echo "\n[STEP 4] Testing Mandatory Statutory Date Validation for KP Form 15 (Days 6 to 15)...\n";
$arbController = new ArbitrationController();
$agreementDate = $arbRow['agreement_date'];

// Test A: Day 3 (Within 5-day repudiation window) -> MUST BE REJECTED
$day3 = date('Y-m-d', strtotime($agreementDate . ' +3 days'));
$resDay3 = $arbController->create([
    'case_id' => $caseId,
    'agreement_date' => $agreementDate,
    'award_date' => $day3,
    'award_details' => 'Award attempted too early during repudiation window'
]);
testAssert(!$resDay3['success'], "Award issuance on Day 3 correctly blocked by 5-day repudiation window rule");

// Test B: Day 5 (Last day of repudiation window) -> MUST BE REJECTED
$day5 = date('Y-m-d', strtotime($agreementDate . ' +5 days'));
$resDay5 = $arbController->create([
    'case_id' => $caseId,
    'agreement_date' => $agreementDate,
    'award_date' => $day5,
    'award_details' => 'Award attempted on Day 5'
]);
testAssert(!$resDay5['success'], "Award issuance on Day 5 correctly blocked (cannot be issued earlier than Day 6)");

// Test C: Day 16 (Exceeds 15-day limit) -> MUST BE REJECTED
$day16 = date('Y-m-d', strtotime($agreementDate . ' +16 days'));
$resDay16 = $arbController->create([
    'case_id' => $caseId,
    'agreement_date' => $agreementDate,
    'award_date' => $day16,
    'award_details' => 'Award attempted after 15 calendar days'
]);
testAssert(!$resDay16['success'], "Award issuance on Day 16 correctly blocked by 15-day statutory limit");

// Test D: Day 7 (Within Day 6 to 15 window) -> MUST SUCCEED
$day7 = date('Y-m-d', strtotime($agreementDate . ' +7 days'));
$resDay7 = $arbController->create([
    'case_id' => $caseId,
    'agreement_date' => $agreementDate,
    'award_date' => $day7,
    'award_details' => 'Matapos ang masusing pagdinig sa arbitrasyon, inuutusan ang inirereklamo na isauli ang halagang PHP 15,000 sa loob ng 10 araw.'
]);
testAssert(!empty($resDay7['success']), "Award issuance on Day 7 successfully rendered within statutory Day 6-15 window");

echo "\n[STEP 5] Verifying KP Form 15 Unlocking & Form Generation Data...\n";
$prereq15After = $kpService->checkPrerequisites('KP Form 15', $caseId);
testAssert($prereq15After['eligible'], "KP Form 15 is unlocked and eligible after award is rendered");

$formData15 = $kpService->getFormData('KP Form 15', $caseId);
testAssert(!empty($formData15['award_date']), "KP Form 15 contains award_date");
testAssert(!empty($formData15['award_details']), "KP Form 15 contains award_details");
testAssert(isset($formData15['arbitration_hearing_dates']), "KP Form 15 contains arbitration_hearing_dates array");

echo "\n====================================================================\n";
echo "   ALL VOLUNTARY ARBITRATION WORKFLOW INTEGRATION TESTS PASSED!     \n";
echo "====================================================================\n";
