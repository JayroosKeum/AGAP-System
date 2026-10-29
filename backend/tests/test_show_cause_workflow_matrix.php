<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ShowCauseService.php';
require_once __DIR__ . '/../services/KpFormService.php';
require_once __DIR__ . '/TestDataCleanup.php';

function runAssertion(bool $cond, string $message): void {
    if (!$cond) {
        echo "  [FAIL] {$message}\n";
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  [PASS] {$message}\n";
}

echo "====================================================================\n";
echo "   SHOW-CAUSE EVALUATION WORKFLOW MATRIX ACCEPTANCE TEST\n";
echo "====================================================================\n\n";

$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);
$showCauseService = new ShowCauseService($db);
$kpFormService = new KpFormService($db);

// Helper to get or create test resident
function getOrCreateResident(PDO $db, string $first, string $last): int {
    $stmt = $db->prepare("SELECT resident_id FROM residents WHERE first_name = ? AND last_name = ? LIMIT 1");
    $stmt->execute([$first, $last]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    // Use existing resident if available
    $any = $db->query("SELECT resident_id FROM residents ORDER BY resident_id ASC LIMIT 1")->fetchColumn();
    if ($any) return (int) $any;

    $ins = $db->prepare("
        INSERT INTO residents (first_name, last_name, birth_date, gender, civil_status, address, barangay)
        VALUES (?, ?, '1990-01-01', 'Male', 'Single', 'Tumana Marikina', 'Tumana')
    ");
    $ins->execute([$first, $last]);
    return (int) $db->lastInsertId();
}

$residents = $db->query("SELECT resident_id FROM residents ORDER BY resident_id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$compResidentId = !empty($residents[0]) ? (int) $residents[0] : getOrCreateResident($db, 'TestComp', 'Tester');
$respResidentId = !empty($residents[1]) ? (int) $residents[1] : getOrCreateResident($db, 'TestResp', 'Tester');

// Clean up previous test cases if any
// (handled automatically by TestDataCleanup)

// =========================================================================
// SCENARIO 1: POST-KP 19 EVALUATION (Respondent Unjustified Absence)
// =========================================================================
echo "[SCENARIO 1] Testing Post-KP 19 Evaluation (Respondent Unjustified Absence)...\n";

$catId = (int) ($db->query("SELECT category_id FROM complaint_categories LIMIT 1")->fetchColumn() ?: 1);

// 1. Create complaint and case
$stmt = $db->prepare("
    INSERT INTO complaints (complaint_number, category_id, complaint_title, narrative, status, case_type)
    VALUES (?, ?, 'AUTOTEST_RESP_UNJUSTIFIED', 'Test narrative for respondent unjustified absence', 'Docketed', 'Civil')
");
$testCompNum1 = 'TEST-SC1-' . time();
$stmt->execute([$testCompNum1, $catId]);
$complaintId1 = (int) $db->lastInsertId();
$cleanup->trackComplaint($complaintId1);

// Add parties
$pStmt = $db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, ?)");
$pStmt->execute([$complaintId1, $compResidentId, 'Complainant']);
$pStmt->execute([$complaintId1, $respResidentId, 'Respondent']);

// Create case
$cStmt = $db->prepare("
    INSERT INTO cases (case_number, complaint_id, case_status, docket_date)
    VALUES (?, ?, 'Mediation', CURDATE())
");
$testCaseNum1 = 'CASE-SC1-' . time();
$cStmt->execute([$testCaseNum1, $complaintId1]);
$caseId1 = (int) $db->lastInsertId();

// Create hearing
$hStmt = $db->prepare("
    INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status)
    VALUES (?, 'Mediation', DATE_ADD(NOW(), INTERVAL 1 DAY), 'Lupon Court Room', 'Scheduled')
");
$hStmt->execute([$caseId1]);
$origHearingId1 = (int) $db->lastInsertId();

// Insert dummy summon deliveries served to allow recording attendance
$delivStmt = $db->prepare("
    INSERT INTO summon_deliveries (hearing_id, case_id, party_type, resident_id, form_type, delivery_status, recipient_name, attempt_number)
    VALUES (?, ?, ?, ?, 'KP Form 8', 'Served', 'Test Recipient', 1)
");
$delivStmt->execute([$origHearingId1, $caseId1, 'Complainant', $compResidentId]);
$delivStmt = $db->prepare("
    INSERT INTO summon_deliveries (hearing_id, case_id, party_type, resident_id, form_type, delivery_status, recipient_name, attempt_number)
    VALUES (?, ?, ?, ?, 'KP Form 9', 'Served', 'Test Recipient', 1)
");
$delivStmt->execute([$origHearingId1, $caseId1, 'Respondent', $respResidentId]);

// Record attendance: Complainant Present, Respondent Absent
$attResult1 = $showCauseService->recordAttendance($origHearingId1, [
    'complainant_attendance' => 'Present',
    'respondent_attendance' => 'Absent',
    'respondent_explanation' => 'No reason given'
], 1);

runAssertion(!empty($attResult1['success']), "Recorded attendance (Respondent Absent, Complainant Present)");

// Find the Show Cause hearing created for Respondent
$scStmt1 = $db->prepare("SELECT hearing_id FROM hearings WHERE case_id = ? AND hearing_type = 'Show Cause (Respondent)' ORDER BY hearing_id DESC LIMIT 1");
$scStmt1->execute([$caseId1]);
$scHearingId1 = (int) $scStmt1->fetchColumn();
runAssertion($scHearingId1 > 0, "Show Cause (Respondent) hearing created (ID: {$scHearingId1})");

// Evaluate Show Cause as UNJUSTIFIED
$evalResult1 = $showCauseService->evaluateShowCause($scHearingId1, [
    'party_type' => 'Respondent',
    'is_justified' => 0,
    'justification_category' => 'Willful Refusal',
    'justification_notes' => 'Refused to attend show-cause hearing without justifiable reason'
], 1);

runAssertion(!empty($evalResult1['success']), "evaluateShowCause succeeded for Respondent");
runAssertion($evalResult1['action_taken'] === 'CFA Issued', "evaluateShowCause returned action_taken = 'CFA Issued'");

// Verify case status and complaint status
$chkCase1 = $db->prepare("SELECT case_status FROM cases WHERE case_id = ?");
$chkCase1->execute([$caseId1]);
$caseStatus1 = $chkCase1->fetchColumn();
runAssertion($caseStatus1 === 'CFA Issued', "Case status updated to 'CFA Issued'");

$chkComp1 = $db->prepare("SELECT status FROM complaints WHERE complaint_id = ?");
$chkComp1->execute([$complaintId1]);
$compStatus1 = $chkComp1->fetchColumn();
runAssertion($compStatus1 === 'CFA Issued', "Complaint status updated to 'CFA Issued'");

// Verify generated documents: KP 20-B, KP 20, KP 22
$docsStmt1 = $db->prepare("
    SELECT dt.template_name, gd.document_id, gd.file_path
    FROM generated_documents gd
    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
    WHERE gd.case_id = ?
");
$docsStmt1->execute([$caseId1]);
$docs1 = $docsStmt1->fetchAll(PDO::FETCH_ASSOC);
$docNames1 = array_column($docs1, 'template_name');

runAssertion(in_array('KP Form 20-B', $docNames1, true), "KP Form 20-B created in generated_documents");
runAssertion(in_array('KP Form 20', $docNames1, true), "KP Form 20 (CFA) created in generated_documents");
runAssertion(in_array('KP Form 22', $docNames1, true), "KP Form 22 created in generated_documents");

// Verify that PDF files exist on disk and have content
$rootDir = dirname(__DIR__, 2);
foreach ($docs1 as $doc) {
    if (in_array($doc['template_name'], ['KP Form 20', 'KP Form 20-B', 'KP Form 22'], true)) {
        $fullPath = $rootDir . '/' . $doc['file_path'];
        runAssertion(file_exists($fullPath), "PDF file for {$doc['template_name']} exists on disk ({$doc['file_path']})");
        runAssertion(filesize($fullPath) > 1000, "PDF file for {$doc['template_name']} has valid size (" . filesize($fullPath) . " bytes)");
    }
}

// Verify cfa_records entry exists
$cfaCheck1 = $db->prepare("SELECT COUNT(*) FROM cfa_records WHERE case_id = ?");
$cfaCheck1->execute([$caseId1]);
runAssertion((int) $cfaCheck1->fetchColumn() > 0, "cfa_records row inserted");

// Verify case_history entry exists
$histCheck1 = $db->prepare("SELECT remarks FROM case_history WHERE case_id = ? AND status = 'CFA Issued' ORDER BY history_id DESC LIMIT 1");
$histCheck1->execute([$caseId1]);
$histRemarks1 = $histCheck1->fetchColumn();
runAssertion(!empty($histRemarks1) && str_contains($histRemarks1, 'KP Form 20'), "case_history logged CFA and barred counterclaim issuance");

// Verify notification dispatched
$notifCheck1 = $db->prepare("SELECT title, message FROM notifications WHERE case_id = ? ORDER BY notification_id DESC LIMIT 1");
$notifCheck1->execute([$caseId1]);
$notif1 = $notifCheck1->fetch(PDO::FETCH_ASSOC);
runAssertion(!empty($notif1) && str_contains($notif1['title'], 'CFA Issued'), "Notification dispatched for CFA issuance");

// Verify KpFormService checkPrerequisites
$prereq20 = $kpFormService->checkPrerequisites('KP Form 20', $caseId1);
runAssertion($prereq20['eligible'], "KpFormService: KP Form 20 is eligible");
$prereq20B = $kpFormService->checkPrerequisites('KP Form 20-B', $caseId1);
runAssertion($prereq20B['eligible'], "KpFormService: KP Form 20-B is eligible");
$prereq22 = $kpFormService->checkPrerequisites('KP Form 22', $caseId1);
runAssertion($prereq22['eligible'], "KpFormService: KP Form 22 is eligible");

echo "\n";

// =========================================================================
// SCENARIO 2: POST-KP 18 & 19 COMBINED EVALUATION (Both Parties Unjustified)
// =========================================================================
echo "[SCENARIO 2] Testing Post-KP 18 & 19 Combined Evaluation (Both Parties Failed / Unjustified)...\n";

// 1. Create second complaint and case
$stmt2 = $db->prepare("
    INSERT INTO complaints (complaint_number, category_id, complaint_title, narrative, status, case_type)
    VALUES (?, ?, 'AUTOTEST_BOTH_UNJUSTIFIED', 'Test narrative for both parties absent', 'Docketed', 'Civil')
");
$testCompNum2 = 'TEST-SC2-' . time();
$stmt2->execute([$testCompNum2, $catId]);
$complaintId2 = (int) $db->lastInsertId();
$cleanup->trackComplaint($complaintId2);

$pStmt->execute([$complaintId2, $compResidentId, 'Complainant']);
$pStmt->execute([$complaintId2, $respResidentId, 'Respondent']);

$cStmt->execute(['CASE-SC2-' . time(), $complaintId2]);
$caseId2 = (int) $db->lastInsertId();

$hStmt->execute([$caseId2]);
$origHearingId2 = (int) $db->lastInsertId();

// Add served notices to allow recording attendance
$delivStmt->execute([$origHearingId2, $caseId2, 'Complainant', $compResidentId]);
$delivStmt->execute([$origHearingId2, $caseId2, 'Respondent', $respResidentId]);

// Record attendance: BOTH Absent
$attResult2 = $showCauseService->recordAttendance($origHearingId2, [
    'complainant_attendance' => 'Absent',
    'respondent_attendance' => 'Absent',
    'complainant_explanation' => 'Did not appear',
    'respondent_explanation' => 'Did not appear'
], 1);

runAssertion(!empty($attResult2['success']), "Recorded attendance (Both parties Absent)");

// Get Show Cause hearings for Complainant and Respondent
$scCompStmt = $db->prepare("SELECT hearing_id FROM hearings WHERE case_id = ? AND hearing_type = 'Show Cause (Complainant)' ORDER BY hearing_id DESC LIMIT 1");
$scCompStmt->execute([$caseId2]);
$scCompHearingId = (int) $scCompStmt->fetchColumn();

$scRespStmt = $db->prepare("SELECT hearing_id FROM hearings WHERE case_id = ? AND hearing_type = 'Show Cause (Respondent)' ORDER BY hearing_id DESC LIMIT 1");
$scRespStmt->execute([$caseId2]);
$scRespHearingId = (int) $scRespStmt->fetchColumn();

runAssertion($scCompHearingId > 0, "Show Cause (Complainant) hearing created (ID: {$scCompHearingId})");
runAssertion($scRespHearingId > 0, "Show Cause (Respondent) hearing created (ID: {$scRespHearingId})");

// Step 2A: Evaluate Complainant Show Cause first as UNJUSTIFIED
echo "  Evaluating Complainant Show Cause (1st evaluation)...\n";
$evalComp = $showCauseService->evaluateShowCause($scCompHearingId, [
    'party_type' => 'Complainant',
    'is_justified' => 0,
    'justification_category' => 'Unjustified Absence',
    'justification_notes' => 'Complainant ignored show-cause notice'
], 1);
runAssertion(!empty($evalComp['success']), "Complainant show-cause evaluated as unjustified");
runAssertion($evalComp['action_taken'] === 'Barred Action', "Action taken is 'Barred Action'");

// Step 2B: Now evaluate Respondent Show Cause as UNJUSTIFIED (Second evaluation from same session)
echo "  Evaluating Respondent Show Cause (2nd evaluation - Combined Trigger)...\n";
$evalResp = $showCauseService->evaluateShowCause($scRespHearingId, [
    'party_type' => 'Respondent',
    'is_justified' => 0,
    'justification_category' => 'Unjustified Absence',
    'justification_notes' => 'Respondent also failed to appear or justify'
], 1);

runAssertion(!empty($evalResp['success']), "Respondent show-cause evaluated as unjustified");
runAssertion($evalResp['action_taken'] === 'Barred Action', "Action taken is 'Barred Action'");
runAssertion(str_contains($evalResp['message'], 'DISMISSED_BARRED'), "Client message indicates case permanently DISMISSED_BARRED");

// Verify Case & Complaint Status permanently set to DISMISSED_BARRED
$chkCase2 = $db->prepare("SELECT case_status FROM cases WHERE case_id = ?");
$chkCase2->execute([$caseId2]);
$caseStatus2 = $chkCase2->fetchColumn();
runAssertion($caseStatus2 === 'DISMISSED_BARRED', "Case status permanently set to 'DISMISSED_BARRED'");

$chkComp2 = $db->prepare("SELECT status FROM complaints WHERE complaint_id = ?");
$chkComp2->execute([$complaintId2]);
$compStatus2 = $chkComp2->fetchColumn();
runAssertion($compStatus2 === 'DISMISSED_BARRED', "Complaint status permanently set to 'DISMISSED_BARRED'");

// Verify both KP Form 21 and KP Form 22 exist in generated_documents
$docsStmt2 = $db->prepare("
    SELECT dt.template_name, gd.document_id, gd.file_path
    FROM generated_documents gd
    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
    WHERE gd.case_id = ?
");
$docsStmt2->execute([$caseId2]);
$docs2 = $docsStmt2->fetchAll(PDO::FETCH_ASSOC);
$docNames2 = array_column($docs2, 'template_name');

runAssertion(in_array('KP Form 21', $docNames2, true), "KP Form 21 created in generated_documents for Complainant");
runAssertion(in_array('KP Form 22', $docNames2, true), "KP Form 22 created in generated_documents for Respondent");

// Check PDF files exist and valid
foreach ($docs2 as $doc) {
    if (in_array($doc['template_name'], ['KP Form 21', 'KP Form 22'], true)) {
        $fullPath = $rootDir . '/' . $doc['file_path'];
        runAssertion(file_exists($fullPath), "PDF file for {$doc['template_name']} exists on disk ({$doc['file_path']})");
        runAssertion(filesize($fullPath) > 1000, "PDF file for {$doc['template_name']} has valid size (" . filesize($fullPath) . " bytes)");
    }
}

// Verify case_history entry stating permanent closure
$histCheck2 = $db->prepare("SELECT remarks FROM case_history WHERE case_id = ? AND (status = 'DISMISSED_BARRED' OR status = 'Dismissed') ORDER BY history_id DESC LIMIT 1");
$histCheck2->execute([$caseId2]);
$histRemarks2 = $histCheck2->fetchColumn();
runAssertion(!empty($histRemarks2) && str_contains($histRemarks2, 'permanenteng isinara'), "case_history notes permanent case closure and court bar for both parties");

// Verify KpFormService checkPrerequisites
$prereq20_case2 = $kpFormService->checkPrerequisites('KP Form 20', $caseId2);
runAssertion(!$prereq20_case2['eligible'], "KpFormService: KP Form 20 is strictly BARRED/LOCKED since Complainant is barred");

$prereq21_case2 = $kpFormService->checkPrerequisites('KP Form 21', $caseId2);
runAssertion($prereq21_case2['eligible'], "KpFormService: KP Form 21 is eligible");

$prereq22_case2 = $kpFormService->checkPrerequisites('KP Form 22', $caseId2);
runAssertion($prereq22_case2['eligible'], "KpFormService: KP Form 22 is eligible");

echo "\n====================================================================\n";
echo "   ALL ACCEPTANCE TESTS COMPLETED SUCCESSFULLY (100% PASS)\n";
echo "====================================================================\n";
