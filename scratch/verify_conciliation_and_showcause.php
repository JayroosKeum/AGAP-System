<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/models/Complaint.php';
require_once __DIR__ . '/../backend/models/CaseModel.php';
require_once __DIR__ . '/../backend/models/Hearing.php';
require_once __DIR__ . '/../backend/models/CaseStage.php';
require_once __DIR__ . '/../backend/services/SummonDeliveryService.php';
require_once __DIR__ . '/../backend/services/ShowCauseService.php';
require_once __DIR__ . '/../backend/tests/TestDataCleanup.php';

$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);

function testAssert(bool $condition, string $msg): void {
    if (!$condition) {
        throw new RuntimeException("Assertion Failed: " . $msg);
    }
    echo "  [PASS] " . $msg . "\n";
}

echo "====================================================================\n";
echo "   VERIFYING 2ND CONCILIATION & KP 18/19 SHOW CAUSE WORKFLOW       \n";
echo "====================================================================\n\n";

try {
    // 1. Setup Complaint
    echo "[STEP 1] Setting up Test Complaint & Case in Conciliation...\n";
    $residents = $db->query("SELECT resident_id FROM residents WHERE barangay = 'Tumana' ORDER BY resident_id ASC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    if (count($residents) < 2) {
        $residents = $db->query("SELECT resident_id FROM residents ORDER BY resident_id ASC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    }
    $cId = (int) $residents[0]['resident_id'];
    $rId = (int) $residents[1]['resident_id'];

    // Ensure both are Tumana for KP jurisdiction
    $db->prepare("UPDATE residents SET barangay = 'Tumana', city_municipality = 'Marikina City' WHERE resident_id IN (?, ?)")->execute([$cId, $rId]);

    $complaintModel = new Complaint();
    $createRes = $complaintModel->create([
        'complaint_title' => 'Conciliation Unblock & Show Cause E2E Test',
        'category_id' => 1,
        'case_type' => 'Civil',
        'incident_date' => date('Y-m-d', strtotime('-3 days')),
        'incident_time' => '10:00',
        'incident_street' => 'San Jose St, Tumana',
        'incident_barangay' => 'Tumana',
        'incident_city' => 'Marikina City',
        'narrative' => 'Dispute to test 2nd conciliation and show cause workflow parity.',
        'requested_resolution' => 'Resolution test.',
        'complainant_resident_id' => $cId,
        'respondent_resident_id' => $rId,
        'latitude' => 14.6525,
        'longitude' => 121.0850,
        'duplicate_acknowledged' => 1,
        'jurisdiction_screened' => 1,
        'created_by' => 1
    ]);
    if (empty($createRes['success'])) {
        var_dump($createRes);
    }
    $complaintId = (int) ($createRes['complaint_id'] ?? 0);
    $cleanup->trackComplaint($complaintId);
    testAssert($complaintId > 0, "Test complaint created ID: {$complaintId}");

    // Docket into active case
    $caseModel = new CaseModel($db);
    $caseRes = $caseModel->create([
        'complaint_id' => $complaintId,
        'case_type' => 'Civil',
        'case_status' => 'Conciliation',
        'created_by' => 1
    ]);
    $caseId = (int) $db->query("SELECT case_id FROM cases WHERE complaint_id = {$complaintId}")->fetchColumn();
    $db->prepare("UPDATE cases SET case_status = 'Conciliation' WHERE case_id = ?")->execute([$caseId]);
    testAssert($caseId > 0, "Case created ID: {$caseId}");

    // Assign Lupon Head/Chairman
    $luponUsers = $db->query("SELECT user_id FROM users WHERE role_id = 3 ORDER BY user_id ASC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    if (count($luponUsers) < 3) {
        $luponUsers = $db->query("SELECT user_id FROM users ORDER BY user_id ASC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    }
    $headId = (int) $luponUsers[0];
    $secId = (int) $luponUsers[1];
    $memId = (int) $luponUsers[2];

    $db->prepare("
        INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date)
        VALUES (?, ?, 'Head', CURDATE()), (?, ?, 'Secretary', CURDATE()), (?, ?, 'Member', CURDATE())
    ")->execute([$caseId, $headId, $caseId, $secId, $caseId, $memId]);

    $db->prepare("
        INSERT INTO case_stages (case_id, stage_type, stage_status, outcome)
        VALUES (?, 'Mediation', 'Referred to Pangkat', 'Referred to Pangkat')
    ")->execute([$caseId]);

    // Create 1st Conciliation hearing
    $hearingModel = new Hearing();
    $h1Date = date('Y-m-d 10:00:00', strtotime('next Wednesday'));
    $h1Res = $hearingModel->createProgression([
        'case_id' => $caseId,
        'hearing_type' => 'Conciliation',
        'hearing_date' => $h1Date,
        'venue' => 'Lupon Court Room',
        'presiding_officer_id' => $headId
    ], null, 1);
    if (empty($h1Res['success'])) {
        var_dump($h1Res);
    }
    testAssert(!empty($h1Res['success']), "1st Conciliation hearing created with ID: " . ($h1Res['hearing_id'] ?? ''));
    $h1Id = (int) $h1Res['hearing_id'];

    // Check that 1st Conciliation created Attempt 1 KP Form 12 deliveries
    $delivStmt = $db->prepare("SELECT * FROM summon_deliveries WHERE hearing_id = ? AND form_type = 'KP Form 12'");
    $delivStmt->execute([$h1Id]);
    $h1Delivs = $delivStmt->fetchAll(PDO::FETCH_ASSOC);
    testAssert(count($h1Delivs) >= 2, "1st Conciliation created KP Form 12 deliveries (Count: " . count($h1Delivs) . ")");
    testAssert((int)$h1Delivs[0]['attempt_number'] === 1, "1st Conciliation attempt_number is 1");

    echo "\n[STEP 2] Testing 2nd Conciliation Scheduling & Auto-Registration of KP Form 12 (Attempt 2)...\n";
    $h2Date = date('Y-m-d 10:00:00', strtotime('next Thursday'));
    $h2Res = $hearingModel->createProgression([
        'case_id' => $caseId,
        'hearing_type' => 'Conciliation',
        'hearing_date' => $h2Date,
        'venue' => 'Lupon Court Room',
        'presiding_officer_id' => $headId
    ], null, 1);
    testAssert(!empty($h2Res['success']), "2nd Conciliation hearing created with ID: {$h2Res['hearing_id']}");
    $h2Id = (int) $h2Res['hearing_id'];

    $deliv2Stmt = $db->prepare("SELECT * FROM summon_deliveries WHERE hearing_id = ? AND form_type = 'KP Form 12' ORDER BY party_type ASC");
    $deliv2Stmt->execute([$h2Id]);
    $h2Delivs = $deliv2Stmt->fetchAll(PDO::FETCH_ASSOC);
    testAssert(count($h2Delivs) >= 2, "2nd Conciliation automatically created KP Form 12 deliveries for both parties (Count: " . count($h2Delivs) . ")");
    testAssert((int)$h2Delivs[0]['attempt_number'] === 2, "2nd Conciliation attempt_number is 2 (Found: " . $h2Delivs[0]['attempt_number'] . ")");
    testAssert($h2Delivs[0]['delivery_status'] === 'Pending', "2nd Conciliation delivery status is Pending");
    testAssert(str_contains($h2Delivs[0]['remarks'], 'KP Form 12 - Notice of 2nd Conciliation Hearing'), "2nd Conciliation remarks correct: " . $h2Delivs[0]['remarks']);

    echo "\n[STEP 3] Testing Non-Appearance Parity: Complainant Absent in Conciliation...\n";
    // Mark Officer's Return as Served for 1st Conciliation so attendance is unlocked
    $db->prepare("UPDATE summon_deliveries SET delivery_status = 'Served', served_at = NOW() WHERE hearing_id = ?")->execute([$h1Id]);

    $scService = new ShowCauseService();
    $compAbsentRes = $scService->recordAttendance($h1Id, [
        'complainant_attendance' => 'Absent',
        'complainant_explanation' => 'Unexcused absence at 1st conciliation',
        'respondent_attendance' => 'Present',
    ], 1);
    if (empty($compAbsentRes['success'])) {
        var_dump($compAbsentRes);
    }
    testAssert(!empty($compAbsentRes['success']), "Complainant absence recorded successfully");
    testAssert($compAbsentRes['outcome'] === 'complainant_absent', "Outcome identified as complainant_absent");

    // Verify KP Form 18 was generated and show cause hearing created
    $actions = $compAbsentRes['results']['actions_triggered'] ?? [];
    testAssert(count($actions) > 0 && $actions[0]['type'] === 'KP Form 18', "KP Form 18 workflow triggered");
    $scHearingId = (int) $actions[0]['show_cause_hearing_id'];
    $doc18Id = (int) $actions[0]['document_id'];
    testAssert($scHearingId > 0, "Show Cause (Complainant) hearing scheduled with ID: {$scHearingId}");
    testAssert($doc18Id > 0, "KP Form 18 document generated with ID: {$doc18Id}");

    // Check Officer's Return in summon_deliveries for KP Form 18
    $scDelivStmt = $db->prepare("SELECT * FROM summon_deliveries WHERE hearing_id = ? AND party_type = 'Complainant' AND form_type = 'KP Form 18'");
    $scDelivStmt->execute([$scHearingId]);
    $scDeliv = $scDelivStmt->fetch(PDO::FETCH_ASSOC);
    testAssert(!empty($scDeliv), "Officer's Return registered for KP Form 18 in summon_deliveries");
    testAssert((int)$scDeliv['document_id'] === $doc18Id, "Officer's Return linked to KP Form 18 document ID {$doc18Id}");
    testAssert($scDeliv['delivery_status'] === 'Pending', "Officer's Return delivery_status is Pending");

    // Check Case History logged under stage 'Conciliation'
    $histStmt = $db->prepare("SELECT * FROM case_history WHERE case_id = ? AND status = 'Conciliation' AND remarks LIKE '%KP Form 18%' ORDER BY history_id DESC LIMIT 1");
    $histStmt->execute([$caseId]);
    $histRow = $histStmt->fetch(PDO::FETCH_ASSOC);
    testAssert(!empty($histRow), "Case history logged under stage 'Conciliation' for KP Form 18 non-appearance");

    echo "\n[STEP 4] Testing Non-Appearance Parity: Respondent Absent in Conciliation...\n";
    // Mark Officer's Return as Served for 2nd Conciliation so attendance is unlocked
    $db->prepare("UPDATE summon_deliveries SET delivery_status = 'Served', served_at = NOW() WHERE hearing_id = ?")->execute([$h2Id]);

    $respAbsentRes = $scService->recordAttendance($h2Id, [
        'complainant_attendance' => 'Present',
        'respondent_attendance' => 'Absent',
        'respondent_explanation' => 'Unexcused absence at 2nd conciliation',
    ], 1);
    testAssert(!empty($respAbsentRes['success']), "Respondent absence recorded successfully");
    testAssert($respAbsentRes['outcome'] === 'respondent_absent', "Outcome identified as respondent_absent");

    $respActions = $respAbsentRes['results']['actions_triggered'] ?? [];
    testAssert(count($respActions) > 0 && $respActions[0]['type'] === 'KP Form 19', "KP Form 19 workflow triggered");
    $scRespHearingId = (int) $respActions[0]['show_cause_hearing_id'];
    $doc19Id = (int) $respActions[0]['document_id'];
    testAssert($scRespHearingId > 0, "Show Cause (Respondent) hearing scheduled with ID: {$scRespHearingId}");
    testAssert($doc19Id > 0, "KP Form 19 document generated with ID: {$doc19Id}");

    // Check Officer's Return in summon_deliveries for KP Form 19
    $scRespDelivStmt = $db->prepare("SELECT * FROM summon_deliveries WHERE hearing_id = ? AND party_type = 'Respondent' AND form_type = 'KP Form 19'");
    $scRespDelivStmt->execute([$scRespHearingId]);
    $scRespDeliv = $scRespDelivStmt->fetch(PDO::FETCH_ASSOC);
    testAssert(!empty($scRespDeliv), "Officer's Return registered for KP Form 19 in summon_deliveries");
    testAssert((int)$scRespDeliv['document_id'] === $doc19Id, "Officer's Return linked to KP Form 19 document ID {$doc19Id}");
    testAssert($scRespDeliv['delivery_status'] === 'Pending', "Officer's Return delivery_status is Pending");

    // Check Case History logged under stage 'Conciliation'
    $hist2Stmt = $db->prepare("SELECT * FROM case_history WHERE case_id = ? AND status = 'Conciliation' AND remarks LIKE '%KP Form 19%' ORDER BY history_id DESC LIMIT 1");
    $hist2Stmt->execute([$caseId]);
    $hist2Row = $hist2Stmt->fetch(PDO::FETCH_ASSOC);
    testAssert(!empty($hist2Row), "Case history logged under stage 'Conciliation' for KP Form 19 non-appearance");

    echo "\n====================================================================\n";
    echo "   ALL TESTS PASSED! Conciliation 2nd scheduling, KP 12 Attempt 2, \n";
    echo "   and KP 18/19 Show Cause workflows verified successfully.        \n";
    echo "====================================================================\n";

} finally {
    $cleanup->cleanup();
}
