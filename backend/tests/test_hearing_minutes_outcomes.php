<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/HearingMinutes.php';
require_once __DIR__ . '/../models/Hearing.php';
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
echo "   HEARING MINUTES OUTCOME WORKFLOW TEST\n";
echo "====================================================================\n\n";

$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);
$minutesModel = new HearingMinutes($db);

$catId = (int) ($db->query("SELECT category_id FROM complaint_categories LIMIT 1")->fetchColumn() ?: 1);

// Helper to get or create test resident
function getOrCreateResident(PDO $db, string $first, string $last): int {
    $stmt = $db->prepare("SELECT resident_id FROM residents WHERE first_name = ? AND last_name = ? LIMIT 1");
    $stmt->execute([$first, $last]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

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

function createTestCaseAndHearing(PDO $db, TestDataCleanup $cleanup, int $catId, int $compResId, int $respResId, string $prefix): array {
    $cStmt = $db->prepare("
        INSERT INTO complaints (complaint_number, category_id, complaint_title, narrative, status, case_type)
        VALUES (?, ?, 'AUTOTEST_MINUTES_" . $prefix . "', 'Narrative test', 'Docketed', 'Civil')
    ");
    $compNum = 'TEST-' . $prefix . '-' . time() . '-' . rand(100, 999);
    $cStmt->execute([$compNum, $catId]);
    $complaintId = (int) $db->lastInsertId();
    $cleanup->trackComplaint($complaintId);

    $pStmt = $db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, ?)");
    $pStmt->execute([$complaintId, $compResId, 'Complainant']);
    $pStmt->execute([$complaintId, $respResId, 'Respondent']);

    $caseStmt = $db->prepare("
        INSERT INTO cases (case_number, complaint_id, case_status, docket_date)
        VALUES (?, ?, 'Mediation', CURDATE())
    ");
    $caseNum = 'CASE-' . $prefix . '-' . time() . '-' . rand(100, 999);
    $caseStmt->execute([$caseNum, $complaintId]);
    $caseId = (int) $db->lastInsertId();

    $hStmt = $db->prepare("
        INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status)
        VALUES (?, 'Mediation', DATE_ADD(NOW(), INTERVAL 1 DAY), 'Lupon Court Room', 'Scheduled')
    ");
    $hStmt->execute([$caseId]);
    $hearingId = (int) $db->lastInsertId();

    return ['complaint_id' => $complaintId, 'case_id' => $caseId, 'hearing_id' => $hearingId];
}

// -------------------------------------------------------------
// TEST 1: Outcome = 'Party Absent' (Complainant Absent) -> KP Form 18
// -------------------------------------------------------------
echo "[TEST 1] Testing session_outcome = 'Party Absent' with Complainant Absent...\n";
$t1 = createTestCaseAndHearing($db, $cleanup, $catId, $compResidentId, $respResidentId, 'PA1');

$saveData1 = [
    'hearing_id' => $t1['hearing_id'],
    'session_type' => '1st Mediation',
    'session_outcome' => 'Party Absent',
    'status' => 'Draft',
    'complainant_attendance' => 'Absent',
    'respondent_attendance' => 'Present',
    'outcome_remarks' => 'Complainant absent during 1st mediation.'
];
$res1 = $minutesModel->save($saveData1, 1);
runAssertion($res1['success'], 'HearingMinutes save with Party Absent succeeded');

// Verify show cause hearing for Complainant was created
$sc1Stmt = $db->prepare("SELECT * FROM hearings WHERE rescheduled_from_id = ? AND hearing_type = 'Show Cause (Complainant)'");
$sc1Stmt->execute([$t1['hearing_id']]);
$sc1Hearing = $sc1Stmt->fetch(PDO::FETCH_ASSOC);
runAssertion(!empty($sc1Hearing), 'Show Cause (Complainant) hearing was automatically created');

// Verify KP Form 18 was created
$doc18Stmt = $db->prepare("
    SELECT gd.* FROM generated_documents gd
    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
    WHERE gd.case_id = ? AND dt.template_name = 'KP Form 18'
");
$doc18Stmt->execute([$t1['case_id']]);
$doc18 = $doc18Stmt->fetch(PDO::FETCH_ASSOC);
runAssertion(!empty($doc18), 'KP Form 18 generated and attached to case');

// -------------------------------------------------------------
// TEST 2: Outcome = 'Dismissed' (Finalized) -> KP Form 21
// -------------------------------------------------------------
echo "\n[TEST 2] Testing session_outcome = 'Dismissed' when Finalized -> KP Form 21...\n";
$t2 = createTestCaseAndHearing($db, $cleanup, $catId, $compResidentId, $respResidentId, 'DISM');

$saveData2 = [
    'hearing_id' => $t2['hearing_id'],
    'session_type' => '1st Mediation',
    'session_outcome' => 'Dismissed',
    'status' => 'Finalized',
    'outcome_remarks' => 'Dismissed following complainant failure/withdrawal.'
];
$res2 = $minutesModel->save($saveData2, 1);
runAssertion($res2['success'], 'HearingMinutes finalize with Dismissed succeeded');

// Verify Case status is Dismissed
$caseStatus = $db->query("SELECT case_status FROM cases WHERE case_id = {$t2['case_id']}")->fetchColumn();
runAssertion($caseStatus === 'Dismissed', "Case status updated to 'Dismissed' (Actual: {$caseStatus})");

// Verify KP Form 21 was generated and attached to case
$doc21Stmt = $db->prepare("
    SELECT gd.* FROM generated_documents gd
    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
    WHERE gd.case_id = ? AND dt.template_name = 'KP Form 21'
");
$doc21Stmt->execute([$t2['case_id']]);
$doc21 = $doc21Stmt->fetch(PDO::FETCH_ASSOC);
runAssertion(!empty($doc21), 'KP Form 21 generated and attached to case upon finalization');
runAssertion(file_exists($doc21['file_path']), "KP Form 21 PDF exists on disk: {$doc21['file_path']}");

// -------------------------------------------------------------
// TEST 3: Outcome = 'Rescheduled' -> Rescheduled Hearing + KP Form 8 & 9
// -------------------------------------------------------------
echo "\n[TEST 3] Testing session_outcome = 'Rescheduled' -> New hearing + KP Form 8 & 9...\n";
$t3 = createTestCaseAndHearing($db, $cleanup, $catId, $compResidentId, $respResidentId, 'RESCH');

$saveData3 = [
    'hearing_id' => $t3['hearing_id'],
    'session_type' => '1st Mediation',
    'session_outcome' => 'Rescheduled',
    'status' => 'Draft',
    'next_hearing_date' => date('Y-m-d', strtotime('+4 days')),
    'next_hearing_time' => '10:00',
    'outcome_remarks' => 'Session reset and rescheduled upon party request.'
];
$res3 = $minutesModel->save($saveData3, 1);
runAssertion($res3['success'], 'HearingMinutes save with Rescheduled succeeded');

// Verify original hearing was marked Rescheduled
$origStatus = $db->query("SELECT status FROM hearings WHERE hearing_id = {$t3['hearing_id']}")->fetchColumn();
runAssertion($origStatus === 'Rescheduled', "Original hearing status marked 'Rescheduled' (Actual: {$origStatus})");

// Verify new rescheduled hearing was created
$reschedHStmt = $db->prepare("SELECT * FROM hearings WHERE rescheduled_from_id = ? AND status = 'Scheduled'");
$reschedHStmt->execute([$t3['hearing_id']]);
$newHearing = $reschedHStmt->fetch(PDO::FETCH_ASSOC);
runAssertion(!empty($newHearing), 'Rescheduled hearing record created');
runAssertion(str_contains($newHearing['hearing_date'], '10:00'), 'Rescheduled hearing scheduled at specified time (10:00)');

// Verify KP Form 8 and KP Form 9 exist in generated_documents
$doc8Stmt = $db->prepare("
    SELECT gd.* FROM generated_documents gd
    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
    WHERE gd.case_id = ? AND dt.template_name = 'KP Form 8'
");
$doc8Stmt->execute([$t3['case_id']]);
$doc8 = $doc8Stmt->fetch(PDO::FETCH_ASSOC);
runAssertion(!empty($doc8), 'KP Form 8 generated and attached to case for rescheduled hearing');

$doc9Stmt = $db->prepare("
    SELECT gd.* FROM generated_documents gd
    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
    WHERE gd.case_id = ? AND dt.template_name = 'KP Form 9'
");
$doc9Stmt->execute([$t3['case_id']]);
$doc9 = $doc9Stmt->fetch(PDO::FETCH_ASSOC);
runAssertion(!empty($doc9), 'KP Form 9 generated and attached to case for rescheduled hearing');

// Verify summon deliveries for Complainant and Respondent
$delivStmt = $db->prepare("SELECT * FROM summon_deliveries WHERE hearing_id = ?");
$delivStmt->execute([(int) $newHearing['hearing_id']]);
$deliveries = $delivStmt->fetchAll(PDO::FETCH_ASSOC);
runAssertion(count($deliveries) >= 2, 'Summon deliveries created for Complainant (KP 8) and Respondent (KP 9)');

echo "\n====================================================================\n";
echo "   ALL HEARING MINUTES OUTCOME TESTS COMPLETED SUCCESSFULLY (100% PASS)\n";
echo "====================================================================\n";
