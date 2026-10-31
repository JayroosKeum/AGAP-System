<?php

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../models/CaseModel.php';
require_once __DIR__ . '/../models/CaseStage.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/CFA.php';
require_once __DIR__ . '/../controllers/ArbitrationController.php';
require_once __DIR__ . '/../controllers/CFAController.php';
require_once __DIR__ . '/../services/KpFormService.php';
require_once __DIR__ . '/../services/OfficeLogisticsService.php';

$db = (new Database())->connect();

echo "====================================================================\n";
echo "   E2E TESTING: CERTIFICATE TO FILE ACTION (CFA) & ARBITRATION      \n";
echo "====================================================================\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $message): void {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$message}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failCount++;
    }
}

function getNonHolidayWeekday(string $startDate = 'now', int $daysAhead = 1): string {
    $logistics = new OfficeLogisticsService();
    $curr = new DateTime($startDate);
    $count = 0;
    while ($count < $daysAhead) {
        $curr->modify('+1 day');
        $w = (int) $curr->format('w');
        if ($w === 0 || $w === 6) continue;
        if ($logistics->isPhilippineHoliday($curr) !== null) continue;
        $count++;
    }
    return $curr->format('Y-m-d 10:00:00');
}

// Clean up old test data
$db->exec("DELETE FROM cfa_records WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM arbitration_records WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM show_cause_evaluations WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM hearing_minutes WHERE hearing_id IN (SELECT hearing_id FROM hearings WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%'))");
$db->exec("DELETE FROM hearing_attendance WHERE hearing_id IN (SELECT hearing_id FROM hearings WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%'))");
$db->exec("DELETE FROM hearings WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM case_stages WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM generated_documents WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%'");
$db->exec("DELETE FROM complaint_parties WHERE complaint_id IN (SELECT complaint_id FROM complaints WHERE complaint_title LIKE 'E2E Test%')");
$db->exec("DELETE FROM complaints WHERE complaint_title LIKE 'E2E Test%'");

// Ensure default residents exist
$resId1 = $db->query("SELECT resident_id FROM residents ORDER BY resident_id ASC LIMIT 1")->fetchColumn() ?: 1;
$resId2 = $db->query("SELECT resident_id FROM residents WHERE resident_id != {$resId1} ORDER BY resident_id ASC LIMIT 1")->fetchColumn() ?: 2;

$cfaModel = new CFA($db);
$cfaController = new CFAController();
$kpService = new KpFormService($db);

// ====================================================================
// TEST 1: COMPLAINANT DEFAULT -> KP FORM 20-A (CERTIFICATE TO BAR ACTION)
// ====================================================================
echo "\n--- TEST 1: Complainant Default -> KP Form 20-A (Bar Action) ---\n";

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
VALUES ('CMP-E2E-CFA-1', 'E2E Test Complainant Barred', 'Mediation', 1, 'Civil', CURDATE(), 'Dispute 1', 1, NOW())")->execute();
$comp1 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")->execute([$comp1, $resId1, $comp1, $resId2]);

$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, 'E2E-CFA-001', 'Mediation', CURDATE(), NOW())")->execute([$comp1]);
$case1 = (int) $db->lastInsertId();

// Create hearing
$hDate1 = getNonHolidayWeekday('now', 1);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Mediation', ?, 'Lupon Court Room', 'Scheduled')")->execute([$case1, $hDate1]);
$h1 = (int) $db->lastInsertId();

// Record Complainant unjustified absence in show_cause_evaluations
$db->prepare("INSERT INTO show_cause_evaluations (hearing_id, case_id, party_type, form_type, is_justified, justification_notes, action_taken)
VALUES (?, ?, 'Complainant', 'KP Form 18', 0, 'Complainant deliberately failed to appear without justifiable cause.', 'Bar Action')")->execute([$h1, $case1]);

// 1.1 Test Backend Eligibility
$elig1 = $cfaModel->determineCfaEligibility($case1);
assertCondition($elig1['eligible'] === true, "Case 1 is eligible for certification");
assertCondition($elig1['recommended_form'] === 'KP Form 20-A', "Recommended form is KP Form 20-A");
assertCondition($elig1['available_forms'] === ['KP Form 20-A'], "Available forms strictly restricted to KP Form 20-A");
assertCondition($elig1['barred_complainant'] === true, "Complainant marked as barred");

// 1.2 Test Premature / Forbidden KP Form 20 Issuance Blocked
$resBlocked20 = $cfaController->create([
    'case_id' => $case1,
    'cfa_type' => 'KP Form 20',
    'reason' => 'Attempting to issue regular CFA to barred complainant'
]);
assertCondition($resBlocked20['success'] === false, "Server-side validation blocked KP Form 20 for barred complainant");
assertCondition(str_contains($resBlocked20['message'], 'barred'), "Rejection message explains Complainant is barred from KP Form 20");

// 1.3 Issue Authorized KP Form 20-A
$resIssue20A = $cfaController->create([
    'case_id' => $case1,
    'cfa_type' => 'KP Form 20-A',
    'reason' => 'Willful non-appearance of complainant without justifiable cause pursuant to RA 7160 Sec. 412(b)(1).'
]);
assertCondition(!empty($resIssue20A['success']), "KP Form 20-A successfully issued");
assertCondition($resIssue20A['cfa_type'] === 'KP Form 20-A', "Returned cfa_type is KP Form 20-A");

// 1.4 Validate Database Status Transition
$caseStatus1 = $db->query("SELECT case_status FROM cases WHERE case_id = {$case1}")->fetchColumn();
$compStatus1 = $db->query("SELECT status FROM complaints WHERE complaint_id = {$comp1}")->fetchColumn();
assertCondition($caseStatus1 === 'DISMISSED_BARRED', "Case status correctly transitioned to 'DISMISSED_BARRED'");
assertCondition($compStatus1 === 'Dismissed', "Complaint status transitioned to 'Dismissed'");

$cfaRow1 = $cfaModel->getByCase($case1);
assertCondition(!empty($cfaRow1) && $cfaRow1['cfa_type'] === 'KP Form 20-A', "cfa_records stored cfa_type as KP Form 20-A");

// 1.5 Validate Form Availability in KpFormService
$prereq20A = $kpService->checkPrerequisites('KP Form 20-A', $case1);
assertCondition($prereq20A['eligible'] === true, "KP Form 20-A is unlocked in KpFormService");
$prereq20Barred = $kpService->checkPrerequisites('KP Form 20', $case1);
assertCondition($prereq20Barred['eligible'] === false, "KP Form 20 is strictly locked and barred in KpFormService");

// ====================================================================
// TEST 2: RESPONDENT DEFAULT -> KP FORM 20-B & KP FORM 20 (CFA)
// ====================================================================
echo "\n--- TEST 2: Respondent Default -> KP Form 20-B (Bar Counterclaim) & KP Form 20 ---\n";

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
VALUES ('CMP-E2E-CFA-2', 'E2E Test Respondent Default', 'Mediation', 1, 'Civil', CURDATE(), 'Dispute 2', 1, NOW())")->execute();
$comp2 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")->execute([$comp2, $resId1, $comp2, $resId2]);

$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, 'E2E-CFA-002', 'Mediation', CURDATE(), NOW())")->execute([$comp2]);
$case2 = (int) $db->lastInsertId();

$hDate2 = getNonHolidayWeekday('now', 2);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Mediation', ?, 'Lupon Court Room', 'Scheduled')")->execute([$case2, $hDate2]);
$h2 = (int) $db->lastInsertId();

// Record Respondent unjustified absence in show_cause_evaluations
$db->prepare("INSERT INTO show_cause_evaluations (hearing_id, case_id, party_type, form_type, is_justified, justification_notes, action_taken)
VALUES (?, ?, 'Respondent', 'KP Form 19', 0, 'Respondent failed to appear after summons without justifiable cause.', 'Bar Counterclaim')")->execute([$h2, $case2]);

// 2.1 Test Backend Eligibility
$elig2 = $cfaModel->determineCfaEligibility($case2);
assertCondition($elig2['eligible'] === true, "Case 2 is eligible for CFA");
assertCondition($elig2['defaulted_respondent'] === true, "Respondent marked as defaulted");
assertCondition(in_array('KP Form 20-B', $elig2['available_forms'], true), "KP Form 20-B available for respondent default");
assertCondition(in_array('KP Form 20', $elig2['available_forms'], true), "KP Form 20 available for complainant");

// 2.2 Issue KP Form 20-B (Certificate to Bar Counterclaim)
$resIssue20B = $cfaController->create([
    'case_id' => $case2,
    'cfa_type' => 'KP Form 20-B',
    'reason' => 'Respondent willfully failed to appear after summons. Counterclaim is barred pursuant to Sec. 412(b)(2).'
]);
assertCondition(!empty($resIssue20B['success']), "KP Form 20-B successfully issued");
$caseStatus2 = $db->query("SELECT case_status FROM cases WHERE case_id = {$case2}")->fetchColumn();
assertCondition($caseStatus2 === 'RESPONDENT_DEFAULT', "Case status updated to RESPONDENT_DEFAULT");

// 2.3 Issue KP Form 20 for Complainant (Same Case)
$resIssue20Comp = $cfaController->create([
    'case_id' => $case2,
    'cfa_type' => 'KP Form 20',
    'reason' => 'Complainant is entitled to Certificate to File Action following respondent default.'
]);
assertCondition(!empty($resIssue20Comp['success']), "KP Form 20 successfully issued for Complainant in same case");
$caseStatus2After = $db->query("SELECT case_status FROM cases WHERE case_id = {$case2}")->fetchColumn();
assertCondition($caseStatus2After === 'CFA Issued', "Case status updated to CFA Issued");

// Verify both forms exist in cfa_records
$allCfas2 = $cfaModel->getAllByCase($case2);
$cfaTypes2 = array_column($allCfas2, 'cfa_type');
assertCondition(in_array('KP Form 20-B', $cfaTypes2, true) && in_array('KP Form 20', $cfaTypes2, true), "Both KP Form 20-B and KP Form 20 successfully recorded in cfa_records");

// ====================================================================
// TEST 3: CONCILIATION IMPASSE -> KP FORM 20
// ====================================================================
echo "\n--- TEST 3: Conciliation Impasse -> KP Form 20 ---\n";

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
VALUES ('CMP-E2E-CFA-3', 'E2E Test Conciliation Impasse', 'Conciliation', 1, 'Civil', CURDATE(), 'Dispute 3', 1, NOW())")->execute();
$comp3 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")->execute([$comp3, $resId1, $comp3, $resId2]);

$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, 'E2E-CFA-003', 'Conciliation', CURDATE(), NOW())")->execute([$comp3]);
$case3 = (int) $db->lastInsertId();

// Record Conciliation Stage as Unsuccessful
$db->prepare("INSERT INTO case_stages (case_id, stage_type, stage_status, outcome, outcome_remarks, completed_at)
VALUES (?, 'Conciliation', 'Completed', 'Unsuccessful', 'Pangkat proceedings failed to produce settlement.', NOW())")->execute([$case3]);

$elig3 = $cfaModel->determineCfaEligibility($case3);
assertCondition($elig3['eligible'] === true, "Case 3 is eligible for CFA");
assertCondition($elig3['ground'] === 'CONCILIATION_IMPASSE', "Ground correctly identified as CONCILIATION_IMPASSE");
assertCondition($elig3['recommended_form'] === 'KP Form 20', "Recommended form is KP Form 20");

$resIssue3 = $cfaController->create([
    'case_id' => $case3,
    'cfa_type' => 'KP Form 20',
    'reason' => 'Conciliation proceedings before the Pangkat Tagapagkasundo failed to reach an amicable settlement.'
]);
assertCondition(!empty($resIssue3['success']), "KP Form 20 successfully issued for conciliation impasse");
$caseStatus3 = $db->query("SELECT case_status FROM cases WHERE case_id = {$case3}")->fetchColumn();
assertCondition($caseStatus3 === 'CFA Issued', "Case status updated to CFA Issued");

// ====================================================================
// TEST 4: VOLUNTARY ARBITRATION DURING MEDIATION (PB PRESIDES)
// ====================================================================
echo "\n--- TEST 4: Voluntary Arbitration during Mediation (Punong Barangay Presides) ---\n";

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
VALUES ('CMP-E2E-ARB-1', 'E2E Test Arbitration Mediation', 'Mediation', 1, 'Civil', CURDATE(), 'Arbitration Dispute 1', 1, NOW())")->execute();
$comp4 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")->execute([$comp4, $resId1, $comp4, $resId2]);

$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, 'E2E-ARB-001', 'Mediation', CURDATE(), NOW())")->execute([$comp4]);
$case4 = (int) $db->lastInsertId();

// 4.1 Record Stage Outcome as Arbitration (Agreement to Arbitrate - KP Form 14)
$stageModel = new CaseStage($db);
$resStageArb = $stageModel->recordOutcome($case4, 'Mediation', 'Arbitration', 'Parties agreed to binding arbitration before Punong Barangay', 1);
assertCondition(!empty($resStageArb['success']), "Mediation outcome recorded as Arbitration");

$caseStatus4 = $db->query("SELECT case_status FROM cases WHERE case_id = {$case4}")->fetchColumn();
assertCondition($caseStatus4 === 'Arbitration', "Case status advanced to 'Arbitration'");

$arbRow4 = (new ArbitrationController())->getByCase($case4);
assertCondition(!empty($arbRow4['agreement_date']), "Arbitration agreement recorded with agreement_date (Day 0: {$arbRow4['agreement_date']})");

// 4.2 KP Form 14 unlocked; KP Form 15 locked
$prereq14 = $kpService->checkPrerequisites('KP Form 14', $case4);
assertCondition($prereq14['eligible'] === true, "KP Form 14 is unlocked");
$prereq15Before = $kpService->checkPrerequisites('KP Form 15', $case4);
assertCondition($prereq15Before['eligible'] === false, "KP Form 15 is locked before award is rendered");

// 4.3 Schedule Arbitration Hearing
$hearingModel = new Hearing($db);
$hDateArb = getNonHolidayWeekday('now', 3);
$arbHData = [
    'case_id' => $case4,
    'hearing_type' => 'Arbitration',
    'hearing_date' => $hDateArb,
    'venue' => 'Lupon Court Room',
    'remarks' => 'Arbitration hearing to hear claims and evidence'
];
$resSchedArb = $hearingModel->createProgression($arbHData, null, 1);
assertCondition(!empty($resSchedArb['success']), "Arbitration hearing scheduled successfully on non-holiday working day");

// Verify presiding officer defaulted to Punong Barangay
$presider4 = $db->query("SELECT presiding_officer_id FROM hearings WHERE hearing_id = {$resSchedArb['hearing_id']}")->fetchColumn();
assertCondition(!empty($presider4), "Presiding arbitrator automatically assigned");

// 4.4 Test Statutory Date Validation for Arbitration Award (RA 7160 Sec. 413)
$arbController = new ArbitrationController();
$agreementDate4 = $arbRow4['agreement_date'];

// Day 3 (within 5-day repudiation window) -> Blocked
$day3 = date('Y-m-d', strtotime($agreementDate4 . ' +3 days'));
$resDay3 = $arbController->create([
    'case_id' => $case4,
    'agreement_date' => $agreementDate4,
    'award_date' => $day3,
    'award_details' => 'Premature award'
]);
assertCondition($resDay3['success'] === false, "Award on Day 3 blocked by 5-day repudiation window rule");

// Day 16 (exceeds 15-day limit) -> Blocked
$day16 = date('Y-m-d', strtotime($agreementDate4 . ' +16 days'));
$resDay16 = $arbController->create([
    'case_id' => $case4,
    'agreement_date' => $agreementDate4,
    'award_date' => $day16,
    'award_details' => 'Late award'
]);
assertCondition($resDay16['success'] === false, "Award on Day 16 blocked by 15-day statutory limit");

// Day 8 (within Day 6-15 window) -> Succeeded
$day8 = date('Y-m-d', strtotime($agreementDate4 . ' +8 days'));
$resDay8 = $arbController->create([
    'case_id' => $case4,
    'agreement_date' => $agreementDate4,
    'award_date' => $day8,
    'award_details' => 'Inuutusan ang ipinagsusumbong na magbayad ng halagang PHP 10,000 sa loob ng 10 araw bilang pinal na gawad.'
]);
assertCondition(!empty($resDay8['success']), "Award on Day 8 successfully rendered within statutory window");

// 4.5 Post-Award Transitions & KP Form 15 Unlocking
$caseStatus4After = $db->query("SELECT case_status FROM cases WHERE case_id = {$case4}")->fetchColumn();
assertCondition($caseStatus4After === 'Settled', "Case status correctly transitioned to 'Settled' following Arbitration Award");

$prereq15After = $kpService->checkPrerequisites('KP Form 15', $case4);
assertCondition($prereq15After['eligible'] === true, "KP Form 15 is unlocked and eligible after award is rendered");

$prereq23After = $kpService->checkPrerequisites('KP Form 23', $case4);
assertCondition($prereq23After['eligible'] === true, "KP Form 23 (Execution) unlocked for Arbitration Award");

// ====================================================================
// TEST 5: VOLUNTARY ARBITRATION DURING CONCILIATION (PANGKAT CHAIRMAN PRESIDES)
// ====================================================================
echo "\n--- TEST 5: Voluntary Arbitration during Conciliation (Pangkat Chairman Presides) ---\n";

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
VALUES ('CMP-E2E-ARB-2', 'E2E Test Arbitration Conciliation', 'Conciliation', 1, 'Civil', CURDATE(), 'Arbitration Dispute 2', 1, NOW())")->execute();
$comp5 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")->execute([$comp5, $resId1, $comp5, $resId2]);

$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, 'E2E-ARB-002', 'Conciliation', CURDATE(), NOW())")->execute([$comp5]);
$case5 = (int) $db->lastInsertId();

// Setup Pangkat Group and Chairman
$pangkatOfficerId = $db->query("SELECT user_id FROM users WHERE role_id = 3 AND status = 'Active' LIMIT 1")->fetchColumn() ?: 2;
$db->prepare("INSERT INTO pangkat_groups (case_id, formation_date, selection_stage) VALUES (?, CURDATE(), 'Constituted')")->execute([$case5]);
$pangkatId5 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, ?, 'Chairman')")->execute([$pangkatId5, $pangkatOfficerId]);

// Record Conciliation Outcome as Arbitration
$resStageArb5 = $stageModel->recordOutcome($case5, 'Conciliation', 'Arbitration', 'Parties submitted to binding arbitration before Pangkat Tagapagkasundo', 1);
assertCondition(!empty($resStageArb5['success']), "Conciliation outcome recorded as Arbitration");

// Check Overview presiding officer name shows Pangkat Chairman
$overview5 = $stageModel->getStageOverview($case5);
assertCondition(str_contains($overview5['arbitration']['presiding_officer'] ?? '', 'Pangkat Chairman'), "Presiding officer identifies Pangkat Chairman for arbitration during Conciliation");

// Schedule Arbitration Hearing
$hDateArb5 = getNonHolidayWeekday('now', 4);
$arbHData5 = [
    'case_id' => $case5,
    'hearing_type' => 'Arbitration',
    'hearing_date' => $hDateArb5,
    'venue' => 'Lupon Court Room',
    'remarks' => 'Arbitration hearing before Pangkat Chairman'
];
$resSchedArb5 = $hearingModel->createProgression($arbHData5, null, 1);
assertCondition(!empty($resSchedArb5['success']), "Arbitration hearing before Pangkat Chairman scheduled successfully");

$presider5 = (int) $db->query("SELECT presiding_officer_id FROM hearings WHERE hearing_id = {$resSchedArb5['hearing_id']}")->fetchColumn();
assertCondition($presider5 === (int) $pangkatOfficerId, "Presiding officer correctly auto-assigned to Pangkat Chairman");

// Cleanup after test run
$db->exec("DELETE FROM cfa_records WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM arbitration_records WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM show_cause_evaluations WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM hearing_minutes WHERE hearing_id IN (SELECT hearing_id FROM hearings WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%'))");
$db->exec("DELETE FROM hearing_attendance WHERE hearing_id IN (SELECT hearing_id FROM hearings WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%'))");
$db->exec("DELETE FROM hearings WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM case_stages WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM generated_documents WHERE case_id IN (SELECT case_id FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%')");
$db->exec("DELETE FROM cases WHERE case_number LIKE 'E2E-CFA-%' OR case_number LIKE 'E2E-ARB-%'");
$db->exec("DELETE FROM complaint_parties WHERE complaint_id IN (SELECT complaint_id FROM complaints WHERE complaint_title LIKE 'E2E Test%')");
$db->exec("DELETE FROM complaints WHERE complaint_title LIKE 'E2E Test%'");

echo "\n====================================================================\n";
echo "   TOTAL TESTS: " . ($passCount + $failCount) . " | PASSED: {$passCount} | FAILED: {$failCount}\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
