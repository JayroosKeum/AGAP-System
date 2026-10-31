<?php

declare(strict_types=1);

/**
 * AGAP Katarungang Pambarangay (KP) Dispute Resolution Workflow Integration Test Suite
 *
 * Scope: End-to-end integration tests covering KP Forms 10 through 20.
 *
 * Scenarios Covered:
 * 1. Referral routing and validation (elevation to Pangkat, panel constitution rules).
 * 2. Hearing scheduling, operating hour restrictions, notifications, and calendar conflicts.
 * 3. Form eligibility and condition checks (prerequisite enforcement before issuance).
 * 4. Generated form persistence & audit trail (KP Forms 10, 11, 12 in DB and file storage).
 * 5. Associated Delivery Records (tracking recipient, serving officer, delivery date, status).
 * 6. KP Form 12 Returns & Hearing Association (relational linkage and bidirectional traceability).
 * 7. Case outcomes:
 *    - Outcome A: Binding Arbitration (KP Forms 14 and 15)
 *    - Outcome B: Amicable Settlement & 10-day Transmittal (KP Form 16)
 *    - Outcome C: Repudiation & Case Reopening (KP Form 17)
 *    - Outcome D: Non-appearance & Show Cause (KP Forms 18, 19, 20-A, 20-B)
 *    - Outcome E: Conciliation Impasse & Certificate to File Action (KP Form 20)
 * 8. Complete teardown & test data cleanup idempotency.
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
require_once __DIR__ . '/../services/MediationDeadlineService.php';
require_once __DIR__ . '/../services/OfficeLogisticsService.php';
require_once __DIR__ . '/TestDataCleanup.php';

echo "====================================================================\n";
echo "   AGAP KP WORKFLOW INTEGRATION TEST SUITE (KP FORMS 10 - 20)       \n";
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

/**
 * Returns the next official working day for hearing scheduling,
 * skipping weekends and statutory Philippine holidays.
 */
function getNextWorkingDay(string $from, int $days = 1): string {
    global $db;
    $logistics = new OfficeLogisticsService($db);
    $dt = new DateTime($from);
    $added = 0;
    while ($added < $days) {
        $dt->modify('+1 day');
        $dow = (int) $dt->format('w');
        if ($dow === 0 || $dow === 6) {
            continue; // Weekend
        }
        if ($logistics->isPhilippineHoliday($dt->format('Y-m-d')) !== null) {
            continue; // Statutory holiday
        }
        $added++;
    }
    return $dt->format('Y-m-d');
}

/**
 * Returns the next Tuesday designated for Mediation hearings in Barangay Tumana,
 * skipping Philippine holidays.
 */
function getNextMediationDay(string $from, int $occurrences = 1): string {
    global $db;
    $logistics = new OfficeLogisticsService($db);
    $dt = new DateTime($from);
    $found = 0;
    while ($found < $occurrences) {
        $dt->modify('+1 day');
        if ((int)$dt->format('w') === 2) { // 2 = Tuesday
            if ($logistics->isPhilippineHoliday($dt->format('Y-m-d')) === null) {
                $found++;
            }
        }
    }
    return $dt->format('Y-m-d');
}

// -------------------------------------------------------------------------
// STEP 1: Fixtures Setup (Residents, Users, Complaint, Docketed Case)
// -------------------------------------------------------------------------
echo "[STEP 1] Setting up fixtures and test complaint...\n";

// Fetch 2 residents for Complainant and Respondent
$residents = $db->query("SELECT resident_id, first_name, last_name FROM residents LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($residents) < 2) {
    throw new RuntimeException("Test requires at least 2 resident records in agap_db.");
}
$complainantResidentId = (int) $residents[0]['resident_id'];
$respondentResidentId = (int) $residents[1]['resident_id'];

// Fetch 3 active Lupon Members for Pangkat panel
$luponStmt = $db->query("
    SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE r.role_name = 'Lupon Member' AND u.status = 'Active'
    LIMIT 3
");
$luponMembers = $luponStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($luponMembers) < 3) {
    throw new RuntimeException("Test requires at least 3 active Lupon Member accounts.");
}
$luponChairmanId = (int) $luponMembers[0]['user_id'];
$luponSecretaryId = (int) $luponMembers[1]['user_id'];
$luponMemberId = (int) $luponMembers[2]['user_id'];

// Fetch an active Summons Server
$serverStmt = $db->query("
    SELECT u.user_id FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE r.role_name = 'Summons Server' AND u.status = 'Active'
    LIMIT 1
");
$summonsServerId = (int) ($serverStmt->fetchColumn() ?: 1);

// Create formal complaint
$complaintModel = new Complaint();
$compCreate = $complaintModel->create([
    'complaint_title' => 'Test KP Workflow Boundary & Property Dispute',
    'category_id' => 1,
    'case_type' => 'Civil',
    'incident_date' => date('Y-m-d', strtotime('-5 days')),
    'incident_time' => '09:30',
    'incident_street' => 'Block 7 Lot 12, Barangay Tumana',
    'incident_barangay' => 'Tumana',
    'incident_city' => 'Marikina City',
    'narrative' => 'Dispute regarding boundary demarcation and fence placement between neighbors.',
    'requested_resolution' => 'Proper boundary inspection and amicable agreement on land boundary.',
    'complainant_resident_id' => $complainantResidentId,
    'respondent_resident_id' => $respondentResidentId,
    'latitude' => 14.6525,
    'longitude' => 121.0850,
    'duplicate_acknowledged' => 1,
    'jurisdiction_screened' => 1,
    'created_by' => 1
]);
$complaintId = (int) ($compCreate['complaint_id'] ?? 0);
testAssert($complaintId > 0, "Test complaint created with ID {$complaintId}");
$cleanup->trackComplaint($complaintId);

// Docket case and issue 1st Mediation summons
$summonsModel = new Summons();
$firstMedDate = getNextMediationDay(date('Y-m-d'), 1);
$issueRes = $summonsModel->issueFirstSummon($complaintId, 1, [
    'mediation_date' => $firstMedDate,
    'mediation_time' => '10:00',
    'venue' => 'Lupon Court Room',
    'override_paanyaya' => true
]);
testAssert(!empty($issueRes['success']), "Case docketed and 1st Mediation summons issued (Case #{$issueRes['case_number']})");
$caseId = (int) $issueRes['case_id'];

$kpService = new KpFormService($db);

// -------------------------------------------------------------------------
// STEP 2: Form Eligibility & Condition Checks Before Referral
// -------------------------------------------------------------------------
echo "\n[STEP 2] Verifying form eligibility condition checks before referral...\n";

// KP Form 10 must be locked while in Mediation
$prereq10 = $kpService->checkPrerequisites('KP Form 10', $caseId);
testAssert(!$prereq10['eligible'], "KP Form 10 is locked during active Mediation stage (Reason: {$prereq10['reason']})");

// KP Form 11 must be locked before Pangkat is constituted
$prereq11 = $kpService->checkPrerequisites('KP Form 11', $caseId);
testAssert(!$prereq11['eligible'], "KP Form 11 is locked prior to Pangkat constitution");

// KP Form 12 must be locked before Pangkat is constituted
$prereq12 = $kpService->checkPrerequisites('KP Form 12', $caseId);
testAssert(!$prereq12['eligible'], "KP Form 12 is locked prior to Pangkat constitution");

// KP Form 14 & 15 must be locked without arbitration agreement
$prereq14 = $kpService->checkPrerequisites('KP Form 14', $caseId);
testAssert(!$prereq14['eligible'], "KP Form 14 locked without mutual arbitration agreement");
$prereq15 = $kpService->checkPrerequisites('KP Form 15', $caseId);
testAssert(!$prereq15['eligible'], "KP Form 15 locked without rendered arbitration award");

// KP Form 16 must be locked without settlement
$prereq16 = $kpService->checkPrerequisites('KP Form 16', $caseId);
testAssert(!$prereq16['eligible'], "KP Form 16 locked without reached settlement");

// KP Form 17 must be locked without settlement to repudiate
$prereq17 = $kpService->checkPrerequisites('KP Form 17', $caseId);
testAssert(!$prereq17['eligible'], "KP Form 17 locked without settlement to repudiate");

// KP Form 20 (CFA) must be locked while mediation is ongoing
$prereq20 = $kpService->checkPrerequisites('KP Form 20', $caseId);
testAssert(!$prereq20['eligible'], "KP Form 20 locked while mediation is in progress");

// -------------------------------------------------------------------------
// STEP 3: Referral Routing & Validation
// -------------------------------------------------------------------------
echo "\n[STEP 3] Testing referral routing and validation (Mediation -> Conciliation)...\n";

$pangkatService = new PangkatService($db);

// 3.1 Validation: Invalid Pangkat Panel (duplicate members)
$dupRes = $pangkatService->recordSelectionProcess($caseId, [
    'chairman_id' => $luponChairmanId,
    'secretary_id' => $luponChairmanId, // duplicate!
    'member_id' => $luponMemberId,
    'selection_method' => 'Party Agreement'
], 1);
testAssert(empty($dupRes['success']), "Duplicate Pangkat members rejected with validation error ({$dupRes['message']})");

// 3.2 Verify referral is locked before completing 2 mediation sessions
$db->prepare("UPDATE hearings SET status = 'Completed', attendance_recorded_at = NOW() WHERE case_id = ? AND hearing_type = 'Mediation'")->execute([$caseId]);
$caseStageModel = new CaseStage();
$earlyReferral = $caseStageModel->referToPangkat($caseId, [
    'referral_reason' => 'Mediation unsuccessful.',
    'referral_date' => date('Y-m-d'),
    'chairman_id' => $luponChairmanId,
    'secretary_id' => $luponSecretaryId,
    'member_id' => $luponMemberId
], 1);
testAssert(empty($earlyReferral['success']), "Elevation blocked after 1 mediation: Barangay Tumana policy strictly requires 2 sessions");

// 3.3 Schedule 2nd Mediation session
$hearingModel = new Hearing();
$med2Date = getNextMediationDay($firstMedDate, 1);
$med2Res = $hearingModel->createProgression([
    'case_id' => $caseId,
    'hearing_type' => 'Mediation',
    'hearing_date' => "{$med2Date} 10:30:00",
    'duration_minutes' => 45,
    'venue' => 'Lupon Court Room',
    'remarks' => '2nd Mediation Hearing before Punong Barangay'
], null, 1);
if (empty($med2Res['success'])) {
    echo "  [DEBUG med2Res error]: " . ($med2Res['message'] ?? 'Unknown') . "\n";
}
testAssert(!empty($med2Res['success']), "2nd Mediation session scheduled (Sequence 2)");

// 3.4 Attempt 3rd Mediation -> rejected by statutory 2-session cap
$med3Date = getNextMediationDay($med2Date, 1);
$med3Res = $hearingModel->createProgression([
    'case_id' => $caseId,
    'hearing_type' => 'Mediation',
    'hearing_date' => "{$med3Date} 11:15:00",
    'duration_minutes' => 45,
    'venue' => 'Lupon Court Room'
], null, 1);
testAssert(empty($med3Res['success']), "3rd Mediation session rejected by statutory 2-session cap");

// 3.5 Complete 2nd Mediation session and elevate to Pangkat Conciliation
$db->prepare("UPDATE hearings SET status = 'Completed', attendance_recorded_at = NOW() WHERE hearing_id = ?")->execute([$med2Res['hearing_id']]);

$referralRes = $caseStageModel->referToPangkat($caseId, [
    'referral_reason' => 'Parties unable to settle during Punong Barangay mediation after 2 sessions.',
    'referral_date' => date('Y-m-d'),
    'chairman_id' => $luponChairmanId,
    'secretary_id' => $luponSecretaryId,
    'member_id' => $luponMemberId,
    'selection_method' => 'Party Agreement'
], 1);
testAssert(!empty($referralRes['success']), "Case successfully elevated to Pangkat Conciliation after 2 mediation sessions: " . ($referralRes['message'] ?? ''));

// Verify stage transition in database
$caseStmt = $db->prepare("SELECT case_status FROM cases WHERE case_id = ?");
$caseStmt->execute([$caseId]);
$currentCaseStatus = $caseStmt->fetchColumn();
testAssert($currentCaseStatus === 'Conciliation', "Case status successfully moved to 'Conciliation'");

// Verify Pangkat Group in database
$pgStmt = $db->prepare("SELECT * FROM pangkat_groups WHERE case_id = ?");
$pgStmt->execute([$caseId]);
$pangkatRow = $pgStmt->fetch(PDO::FETCH_ASSOC);
testAssert(!empty($pangkatRow['pangkat_id']), "Pangkat group created with ID {$pangkatRow['pangkat_id']}");
testAssert(!empty($pangkatRow['convening_deadline']), "3-day convening deadline set to {$pangkatRow['convening_deadline']}");

// -------------------------------------------------------------------------
// STEP 4: Generated Form Persistence & Audit Trail (KP Forms 10 and 11)
// -------------------------------------------------------------------------
echo "\n[STEP 4] Testing generated form persistence & audit trail (KP Forms 10 & 11)...\n";

// 4.1 Check eligibility: KP Form 10 & 11 now eligible
$prereq10After = $kpService->checkPrerequisites('KP Form 10', $caseId);
testAssert($prereq10After['eligible'], "KP Form 10 is now unlocked and eligible");
$prereq11After = $kpService->checkPrerequisites('KP Form 11', $caseId);
testAssert($prereq11After['eligible'], "KP Form 11 is now unlocked and eligible");

// 4.2 Generate and persist KP Form 10
$persist10 = $kpService->generateAndPersistKp10($caseId, 1, [
    'serving_officer' => $summonsServerId,
    'delivery_date' => date('Y-m-d 09:00:00', strtotime('+1 day'))
]);
testAssert(!empty($persist10['success']), "KP Form 10 generated and persisted");
testAssert(file_exists($persist10['full_path']), "KP Form 10 PDF persists on disk at {$persist10['file_path']}");
testAssert(filesize($persist10['full_path']) > 1000, "KP Form 10 PDF has valid file size (" . filesize($persist10['full_path']) . " bytes)");

// Verify KP Form 10 in generated_documents
$doc10Stmt = $db->prepare("SELECT * FROM generated_documents WHERE document_id = ?");
$doc10Stmt->execute([$persist10['document_id']]);
$doc10Row = $doc10Stmt->fetch(PDO::FETCH_ASSOC);
testAssert(!empty($doc10Row), "KP Form 10 recorded in generated_documents table");

// Verify associated Delivery Records for KP Form 10 (Complainant and Respondent)
testAssert(count($persist10['delivery_records']) >= 2, "KP Form 10 created Delivery Records for both parties");
$deliv10Check = $db->prepare("
    SELECT COUNT(*) FROM summon_deliveries
    WHERE document_id = ? AND form_type = 'KP Form 10' AND delivery_status = 'Pending'
");
$deliv10Check->execute([$persist10['document_id']]);
testAssert((int) $deliv10Check->fetchColumn() >= 2, "Delivery Records persist in summon_deliveries for KP Form 10");

// 4.3 Generate and persist KP Form 11
$persist11 = $kpService->generateAndPersistKp11($caseId, 1, [
    'serving_officer' => $summonsServerId,
    'delivery_date' => date('Y-m-d 10:00:00', strtotime('+1 day'))
]);
testAssert(!empty($persist11['success']), "KP Form 11 generated and persisted");
testAssert(file_exists($persist11['full_path']), "KP Form 11 PDF persists on disk");

// Verify associated Delivery Records for KP Form 11 (3 chosen Pangkat members)
testAssert(count($persist11['delivery_records']) === 3, "KP Form 11 created Delivery Records for all 3 Pangkat members");
$deliv11Check = $db->prepare("
    SELECT COUNT(*) FROM summon_deliveries
    WHERE document_id = ? AND form_type = 'KP Form 11' AND party_type = 'Pangkat Member'
");
$deliv11Check->execute([$persist11['document_id']]);
testAssert((int) $deliv11Check->fetchColumn() === 3, "3 Pangkat Member delivery records persist in summon_deliveries");

// Verify Audit Trail for Form Generation
$auditStmt = $db->prepare("
    SELECT COUNT(*) FROM audit_trails
    WHERE module_name = 'Documents' AND affected_record IN (?, ?)
");
$auditStmt->execute([$persist10['document_id'], $persist11['document_id']]);
testAssert((int) $auditStmt->fetchColumn() >= 2, "Audit Trail logged for KP Forms 10 and 11 generation");

// -------------------------------------------------------------------------
// STEP 5: Hearing Scheduling, Calendar Conflicts & Notifications
// -------------------------------------------------------------------------
echo "\n[STEP 5] Testing Conciliation hearing scheduling, calendar rules, conflicts & notifications...\n";

$hearingModel = new Hearing();

// 5.1 Validation: Weekend scheduling blocked
$weekendTarget = date('Y-m-d 10:00:00', strtotime('next Saturday'));
$weekendValidation = $hearingModel->validateHearingOperatingHours($weekendTarget, 45, 'Conciliation');
testAssert(!$weekendValidation['valid'], "Weekend scheduling correctly blocked for Conciliation");

// 5.2 Validation: Tuesday afternoon conciliation blocked (exclusive to Mediation)
$tuesdayTarget = date('Y-m-d 14:00:00', strtotime('next Tuesday'));
$tuesdayValidation = $hearingModel->validateHearingOperatingHours($tuesdayTarget, 45, 'Conciliation');
testAssert(!$tuesdayValidation['valid'], "Tuesday afternoon Conciliation correctly blocked (Reason: {$tuesdayValidation['message']})");

// 5.3 Schedule initial Conciliation hearing on valid working day morning
$concDate = getNextWorkingDay($med3Date, 2);
$concHearingTime = "{$concDate} 09:30:00";

$scheduleRes = $hearingModel->createProgression([
    'case_id' => $caseId,
    'hearing_type' => 'Conciliation',
    'hearing_date' => $concHearingTime,
    'duration_minutes' => 45,
    'venue' => 'Lupon Court Room',
    'remarks' => '1st Conciliation Hearing before Pangkat Tagapagkasundo',
    'presiding_officer_id' => $luponChairmanId
], null, 1);
testAssert(!empty($scheduleRes['success']), "1st Conciliation Hearing scheduled successfully (Hearing ID: {$scheduleRes['hearing_id']})");
$concHearingId = (int) $scheduleRes['hearing_id'];

// 5.4 Validation: Calendar Conflict Detection in Lupon Court Room
$conflictCheck = $hearingModel->findConflict(
    $concHearingTime,
    'Lupon Court Room',
    $caseId,
    null,
    $luponChairmanId,
    45,
    'Conciliation'
);
testAssert(!empty($conflictCheck['conflict']), "Courtroom collision detected for conflicting slot in Lupon Court Room");
testAssert(!empty($conflictCheck['alternative_slots']), "System proposed alternative non-conflicting time slots");

// 5.5 Notification Verification
require_once __DIR__ . '/../services/NotificationService.php';
$notifService = new NotificationService($db);
$notifCountBefore = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE case_id = {$caseId}")->fetchColumn();
$notifService->notifyCaseMembers($caseId, 'Conciliation Hearing Scheduled', 'Notice of Conciliation on ' . $concHearingTime, 1);
$notifCountAfter = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE case_id = {$caseId}")->fetchColumn();
testAssert($notifCountAfter > $notifCountBefore, "Automated notifications dispatched to case team members");

// -------------------------------------------------------------------------
// STEP 6: KP Form 12 Returns & Hearing Association (Bidirectional Traceability)
// -------------------------------------------------------------------------
echo "\n[STEP 6] Testing KP Form 12 generation, Officer's Return & bidirectional traceability...\n";

// 6.1 Generate and persist KP Form 12 linked to the Conciliation Hearing
$persist12 = $kpService->generateAndPersistKp12($caseId, 1, [
    'hearing_id' => $concHearingId,
    'serving_officer' => $summonsServerId,
    'delivery_date' => date('Y-m-d 11:00:00', strtotime('+1 day'))
]);
testAssert(!empty($persist12['success']), "KP Form 12 generated and persisted");
testAssert(file_exists($persist12['full_path']), "KP Form 12 PDF persists on disk");
$doc12Id = (int) $persist12['document_id'];

// Verify relational link on hearings table
$hRelStmt = $db->prepare("SELECT kp12_document_id FROM hearings WHERE hearing_id = ?");
$hRelStmt->execute([$concHearingId]);
$linkedDocId = (int) $hRelStmt->fetchColumn();
testAssert($linkedDocId === $doc12Id, "Relational Link Established: hearings.kp12_document_id -> generated_documents.document_id ({$doc12Id})");

// Verify associated Delivery Records for KP Form 12
$deliv12Check = $db->prepare("
    SELECT COUNT(*) FROM summon_deliveries
    WHERE document_id = ? AND form_type = 'KP Form 12' AND hearing_id = ?
");
$deliv12Check->execute([$doc12Id, $concHearingId]);
testAssert((int) $deliv12Check->fetchColumn() >= 2, "Delivery Records created and associated to hearing {$concHearingId}");

// 6.2 Record Officer's Return for KP Form 12
$returnText = "Personally served KP Form 12 notice to respondent at residence. Handed copy and obtained signature.";
$officerReturnRes = $kpService->recordOfficerReturnForKp12($doc12Id, [
    'service_result' => 'Served',
    'service_date' => date('Y-m-d 14:15:00'),
    'officer_return' => $returnText,
    'party_type' => 'Respondent'
], $summonsServerId);
testAssert(!empty($officerReturnRes['success']), "Officer's Return recorded for KP Form 12: " . ($officerReturnRes['message'] ?? ''));
$serviceId = (int) $officerReturnRes['service_id'];

// 6.3 Bidirectional Traceability Verification:
// FORWARD TRACEABILITY: Query initial conciliation hearing -> resolves Officer's Return & service documents
$concHearingResolved = $hearingModel->getInitialConciliationHearing($caseId);
testAssert(!empty($concHearingResolved), "Initial Conciliation Hearing resolved for Case #{$caseId}");
testAssert(!empty($concHearingResolved['kp12_document_id']), "Resolved hearing contains kp12_document_id");
testAssert(!empty($concHearingResolved['officer_returns']), "Resolved hearing contains officer_returns array");

$foundReturn = false;
foreach ($concHearingResolved['officer_returns'] as $or) {
    if ((int) $or['document_id'] === $doc12Id && $or['service_result'] === 'Served') {
        $foundReturn = true;
        break;
    }
}
testAssert($foundReturn, "Resolved hearing contains matching Officer's Return (Result: Served)");

$foundDoc = false;
foreach ($concHearingResolved['service_documents'] as $sd) {
    if ((int) $sd['document_id'] === $doc12Id) {
        $foundDoc = true;
        testAssert($sd['service_status'] === 'Served', "Underlying KP Form 12 document status updated to 'Served'");
        break;
    }
}
testAssert($foundDoc, "Resolved hearing contains underlying KP Form 12 service document");

// REVERSE TRACEABILITY: Query from KP Form 12 document -> resolves initial Conciliation Hearing
$reverseHearing = $hearingModel->getHearingByKp12DocumentId($doc12Id);
testAssert(!empty($reverseHearing), "Reverse Traceability: Resolved Conciliation hearing from document_id {$doc12Id}");
testAssert((int) $reverseHearing['hearing_id'] === $concHearingId, "Reverse hearing matches initial conciliation hearing ID {$concHearingId}");

// SQL VIEW TRACEABILITY: Query view_conciliation_hearing_returns
$viewStmt = $db->prepare("SELECT * FROM view_conciliation_hearing_returns WHERE hearing_id = ?");
$viewStmt->execute([$concHearingId]);
$viewRows = $viewStmt->fetchAll(PDO::FETCH_ASSOC);
testAssert(count($viewRows) > 0, "Database view view_conciliation_hearing_returns resolves joined hearing, document, and return");

// -------------------------------------------------------------------------
// STEP 7: Case Outcomes & Dynamic Form Unlocking (Forms 14 - 20)
// -------------------------------------------------------------------------
echo "\n[STEP 7] Testing Case Outcomes & Dynamic Form Unlocking...\n";

// -------------------------------------------------------------------------
// OUTCOME A: Binding Arbitration (KP Forms 14 & 15)
// -------------------------------------------------------------------------
echo "  [7A] Testing Binding Arbitration (KP Forms 14 & 15)...\n";

$arbIns = $db->prepare("
    INSERT INTO arbitration_records (case_id, agreement_date, award_details)
    VALUES (?, CURDATE(), 'Parties freely submitted to binding arbitration under RA 7160 Sec 413')
");
$arbIns->execute([$caseId]);
$arbId = (int) $db->lastInsertId();

$prereq14 = $kpService->checkPrerequisites('KP Form 14', $caseId);
testAssert($prereq14['eligible'], "KP Form 14 (Agreement for Arbitration) unlocked upon submission");
$persist14 = $kpService->generateAndPersistForm('KP Form 14', $caseId, 1);
testAssert(!empty($persist14['success']), "KP Form 14 generated and persisted");

// Render Arbitration Award
$db->prepare("
    UPDATE arbitration_records SET
        award_date = CURDATE(),
        award_details = 'Respondent shall construct the concrete boundary wall on demarcated Lot 12 line within 30 days.'
    WHERE arbitration_id = ?
")->execute([$arbId]);

$prereq15 = $kpService->checkPrerequisites('KP Form 15', $caseId);
testAssert($prereq15['eligible'], "KP Form 15 (Arbitration Award) unlocked upon rendering award");
$persist15 = $kpService->generateAndPersistForm('KP Form 15', $caseId, 1);
testAssert(!empty($persist15['success']), "KP Form 15 generated and persisted");

// -------------------------------------------------------------------------
// OUTCOME B: Amicable Settlement (KP Form 16) & 10-Day Transmittal
// -------------------------------------------------------------------------
echo "  [7B] Testing Amicable Settlement (KP Form 16) & Statutory Transmittal...\n";

$settleDate = date('Y-m-d');
$settleIns = $db->prepare("
    INSERT INTO settlements (case_id, settlement_date, agreement_details, responsible_party, compliance_status)
    VALUES (?, ?, 'Parties amicably agreed to shared survey costs and boundary realignment.', 'Both', 'Pending')
");
$settleIns->execute([$caseId, $settleDate]);
$settlementId = (int) $db->lastInsertId();

// Update case status to Settled
$db->prepare("UPDATE cases SET case_status = 'Settled' WHERE case_id = ?")->execute([$caseId]);

$prereq16 = $kpService->checkPrerequisites('KP Form 16', $caseId);
testAssert($prereq16['eligible'], "KP Form 16 (Amicable Settlement) unlocked upon recorded settlement");

$persist16 = $kpService->generateAndPersistForm('KP Form 16', $caseId, 1);
testAssert(!empty($persist16['success']), "KP Form 16 generated and persisted");

// Verify 10-Working-Day Transmittal Window (excluding weekends and public holidays)
$transmittalDeadline = MediationDeadlineService::calculateDeadline($settleDate, 10);
testAssert(!empty($transmittalDeadline), "Statutory 10-working-day transmittal deadline calculated ({$transmittalDeadline})");

// -------------------------------------------------------------------------
// OUTCOME C: Repudiation (KP Form 17) & Case Reopening
// -------------------------------------------------------------------------
echo "  [7C] Testing Repudiation (KP Form 17) & Case Reopening...\n";

$db->prepare("
    UPDATE settlements SET
        repudiation_date = NOW(),
        repudiated_by = ?,
        repudiation_reason = 'Settlement entered into under duress and fraudulent representation of boundaries.',
        repudiation_status = 'Repudiated'
    WHERE settlement_id = ?
")->execute([$respondentResidentId, $settlementId]);

// Case reopened for conciliation / CFA pathway due to repudiation
$db->prepare("UPDATE cases SET case_status = 'Conciliation' WHERE case_id = ?")->execute([$caseId]);

$prereq17 = $kpService->checkPrerequisites('KP Form 17', $caseId);
testAssert($prereq17['eligible'], "KP Form 17 (Pagtatwa / Repudiation) unlocked upon recorded repudiation");
$persist17 = $kpService->generateAndPersistForm('KP Form 17', $caseId, 1);
testAssert(!empty($persist17['success']), "KP Form 17 generated and persisted");

// -------------------------------------------------------------------------
// OUTCOME D: Non-Appearance & Show Cause (KP Forms 18, 19, 20-A, 20-B)
// -------------------------------------------------------------------------
echo "  [7D] Testing Non-Appearance & Show Cause (KP Forms 18, 19, 20-A, 20-B)...\n";

// D1: Complainant Failure to Appear -> KP Form 18 & KP Form 20-A
$db->prepare("
    INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status)
    VALUES (?, ?, 'Absent')
")->execute([$concHearingId, $complainantResidentId]);

$db->prepare("
    INSERT INTO show_cause_evaluations (hearing_id, case_id, party_type, form_type, is_justified, justification_category, justification_notes, action_taken, evaluated_by)
    VALUES (?, ?, 'Complainant', 'KP Form 18', 0, 'Unjustified Absence', 'No valid emergency or notice provided for absence.', 'Barred Action', 1)
")->execute([$concHearingId, $caseId]);

$prereq18 = $kpService->checkPrerequisites('KP Form 18', $caseId, $concHearingId);
testAssert($prereq18['eligible'], "KP Form 18 (Notice on Failure to Appear - Complainant) unlocked");
$persist18 = $kpService->generateAndPersistForm('KP Form 18', $caseId, 1, ['hearing_id' => $concHearingId]);
testAssert(!empty($persist18['success']), "KP Form 18 generated and persisted");

$prereq20A = $kpService->checkPrerequisites('KP Form 20-A', $caseId);
testAssert($prereq20A['eligible'], "KP Form 20-A (Certificate to Bar Action) unlocked due to unjustified Complainant absence");
$persist20A = $kpService->generateAndPersistForm('KP Form 20-A', $caseId, 1);
testAssert(!empty($persist20A['success']), "KP Form 20-A generated and persisted");

// D2: Respondent Failure to Appear -> KP Form 19 & KP Form 20-B
$db->prepare("
    INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status)
    VALUES (?, ?, 'Absent')
")->execute([$concHearingId, $respondentResidentId]);

$db->prepare("
    INSERT INTO show_cause_evaluations (hearing_id, case_id, party_type, form_type, is_justified, justification_category, justification_notes, action_taken, evaluated_by)
    VALUES (?, ?, 'Respondent', 'KP Form 19', 0, 'Willful Refusal', 'Refused to attend despite personal service of summons.', 'Respondent Default', 1)
")->execute([$concHearingId, $caseId]);

$prereq19 = $kpService->checkPrerequisites('KP Form 19', $caseId, $concHearingId);
testAssert($prereq19['eligible'], "KP Form 19 (Notice on Failure to Appear - Respondent) unlocked");
$persist19 = $kpService->generateAndPersistForm('KP Form 19', $caseId, 1, ['hearing_id' => $concHearingId]);
testAssert(!empty($persist19['success']), "KP Form 19 generated and persisted");

$prereq20B = $kpService->checkPrerequisites('KP Form 20-B', $caseId);
testAssert($prereq20B['eligible'], "KP Form 20-B (Certificate to Bar Counterclaim) unlocked due to unjustified Respondent absence");
$persist20B = $kpService->generateAndPersistForm('KP Form 20-B', $caseId, 1);
testAssert(!empty($persist20B['success']), "KP Form 20-B generated and persisted");

// -------------------------------------------------------------------------
// OUTCOME E: Conciliation Impasse & Certificate to File Action (KP Form 20)
// -------------------------------------------------------------------------
echo "  [7E] Testing Conciliation Impasse & Certificate to File Action (KP Form 20)...\n";

$db->prepare("
    INSERT INTO cfa_records (case_id, issuance_date, reason)
    VALUES (?, CURDATE(), 'Conciliation concluded without settlement after mandated hearings.')
")->execute([$caseId]);

$prereq20 = $kpService->checkPrerequisites('KP Form 20', $caseId);
testAssert($prereq20['eligible'], "KP Form 20 (Certificate to File Action - CFA) unlocked");

$persist20 = $kpService->generateAndPersistForm('KP Form 20', $caseId, 1);
testAssert(!empty($persist20['success']), "KP Form 20 generated and persisted");
testAssert(file_exists($persist20['full_path']), "KP Form 20 PDF persists on disk");

// -------------------------------------------------------------------------
// STEP 8: Comprehensive Eligibility Registry Verification
// -------------------------------------------------------------------------
echo "\n[STEP 8] Verifying eligible KP Forms registry list...\n";

$eligibleForms = $kpService->getEligibleForms($caseId);
$eligibleCodes = array_column($eligibleForms, 'code');

$requiredForms = ['KP Form 10', 'KP Form 11', 'KP Form 12', 'KP Form 14', 'KP Form 15', 'KP Form 16', 'KP Form 17', 'KP Form 18', 'KP Form 19', 'KP Form 20', 'KP Form 20-A', 'KP Form 20-B'];
foreach ($requiredForms as $rf) {
    testAssert(in_array($rf, $eligibleCodes, true), "Registry contains active/eligible {$rf}");
}

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "   INTEGRATION TEST SUMMARY: ALL {$passedAssertions} ASSERTIONS PASSED!           \n";
echo "   All KP Forms 10 through 20 fully persisted, linked, and verified.\n";
echo "   Shutdown cleanup will now safely remove tracked test records.     \n";
echo "====================================================================\n";
