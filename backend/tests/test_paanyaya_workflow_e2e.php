<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/Paanyaya.php';
require_once __DIR__ . '/../models/CaseModel.php';

echo "====================================================\n";
echo "TESTING MANDATORY PAANYAYA PRE-DOCKETING WORKFLOW\n";
echo "====================================================\n\n";

$db = (new Database())->connect();
$complaintModel = new Complaint($db);
$paanyayaModel = new Paanyaya($db);

// 1. Fetch sample resident IDs
$resStmt = $db->query("SELECT resident_id FROM residents ORDER BY resident_id ASC LIMIT 2");
$residents = $resStmt->fetchAll(PDO::FETCH_COLUMN);
$cResId = (int) ($residents[0] ?? 1);
$rResId = (int) ($residents[1] ?? 2);

// TEST 1: Intake Filing with Mandatory Pre-Docketing (No formal case docket assigned)
echo "[1] Testing New Complaint Intake Filing...\n";
$createData = [
    'category_id' => 5, // Threats / Pagbabanta
    'case_type' => 'Civil',
    'complaint_title' => 'Automated Test: Boundary Dispute ' . time(),
    'duplicate_acknowledged' => 1,
    'incident_date' => date('Y-m-d'),
    'incident_time' => '10:00',
    'incident_street' => 'Road 1 Extension',
    'incident_purok' => 'Purok 4',
    'incident_location' => 'Road 1 Extension, Purok 4, Barangay Tumana, Marikina City',
    'incident_barangay' => 'Tumana',
    'incident_city' => 'Marikina City',
    'narrative' => 'Nagkaroon ng pagtatalo ang mga panig ukol sa bakod ng lupa sa Road 1.',
    'requested_resolution' => 'Maayos na pag-uusap at pagtatakda ng tamang boundary.',
    'complainant_mode' => 'existing',
    'complainant_resident_id' => $cResId,
    'respondent_mode' => 'existing',
    'respondent_resident_id' => $rResId,
    'latitude' => 14.6521,
    'longitude' => 121.0854,
    'is_draft' => 0,
    'jurisdiction_screened' => 1,
    'party_is_government' => 0,
    'party_is_public_officer' => 0,
    'parties_in_diff_lgu' => 0,
    'property_in_diff_lgu' => 0,
    'accused_under_detention' => 0,
    'habeas_corpus_needed' => 0,
    'provisional_remedy_needed' => 0,
    'prescription_risk' => 0,
    'indigenous_community' => 0,
    'offense_imprisonment_max' => 0,
];

$createResult = $complaintModel->create($createData);
if (!$createResult['success']) {
    die("FAILED to create complaint: " . $createResult['message'] . "\n");
}
$complaintId = (int) $createResult['complaint_id'];
$trackingNumber = $createResult['complaint_number'];

echo "  ✓ Complaint created with ID: {$complaintId}\n";
echo "  ✓ Tracking Number: {$trackingNumber}\n";
assert(str_starts_with($trackingNumber, 'CMP-') || str_starts_with($trackingNumber, 'P-'), "Tracking number must start with CMP- or P- (not a court docket number)");

// Verify status in DB
$cCheck = $db->query("SELECT status, complaint_number, paanyaya_attempt_count, resolved_without_docketing FROM complaints WHERE complaint_id = {$complaintId}")->fetch(PDO::FETCH_ASSOC);
echo "  ✓ Initial DB Status: {$cCheck['status']}\n";
assert($cCheck['status'] === 'Pre-Docketed (Paanyaya)', "Initial status must be Pre-Docketed (Paanyaya)");

// TEST 2: Schedule 1st Paanyaya Session
echo "\n[2] Testing 1st Paanyaya Scheduling (Session 1)...\n";
$sess1Result = $paanyayaModel->schedule([
    'complaint_id' => $complaintId,
    'attempt_number' => 1,
    'invitation_date' => date('Y-m-d', strtotime('+3 weekdays')),
    'invitation_time' => '14:00',
    'venue' => 'Tanggapan ng Barangay Tumana',
    'has_e_signature' => 1
], 1);

assert($sess1Result['success'], "1st Paanyaya scheduling failed: " . ($sess1Result['message'] ?? ''));
$sess1Id = (int) $sess1Result['session_id'];
echo "  ✓ 1st Paanyaya Session ID: {$sess1Id}\n";
echo "  ✓ Generated PDF: {$sess1Result['pdf_path']}\n";
assert(!empty($sess1Result['pdf_path']) && file_exists(dirname(__DIR__, 2) . '/' . $sess1Result['pdf_path']), "PDF file must exist on disk");

// TEST 3: Outcome 2 at Session 1 (Party Absent -> Unlocks 2nd Paanyaya)
echo "\n[3] Testing Outcome 2 (No-Show / Party Absent) for 1st Paanyaya...\n";
$out1Result = $paanyayaModel->recordOutcome($sess1Id, [
    'outcome' => 'Absent_Retry',
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Absent',
    'outcome_notes' => 'Respondent was out of town. Complainant requested a second informal invitation.'
], 1);

assert($out1Result['success'], "Outcome 1 failed: " . ($out1Result['message'] ?? ''));
assert($out1Result['can_issue_second'] === true, "Must unlock 2nd Paanyaya");
$cCheck2 = $db->query("SELECT status FROM complaints WHERE complaint_id = {$complaintId}")->fetch(PDO::FETCH_ASSOC);
echo "  ✓ Updated Status after No-Show: {$cCheck2['status']}\n";
assert($cCheck2['status'] === '1st Paanyaya Failed (No-Show)');

// TEST 4: Schedule 2nd Paanyaya (Attempt #2 of 2)
echo "\n[4] Testing 2nd Paanyaya Scheduling (Session 2 - Final Informal Chance)...\n";
$sess2Result = $paanyayaModel->schedule([
    'complaint_id' => $complaintId,
    'attempt_number' => 2,
    'invitation_date' => date('Y-m-d', strtotime('+6 weekdays')),
    'invitation_time' => '15:00',
    'venue' => 'Tanggapan ng Barangay Tumana',
    'has_e_signature' => 1
], 1);

assert($sess2Result['success'], "2nd Paanyaya scheduling failed: " . ($sess2Result['message'] ?? ''));
$sess2Id = (int) $sess2Result['session_id'];
echo "  ✓ 2nd Paanyaya Session ID: {$sess2Id}\n";
echo "  ✓ 2nd Generated PDF: {$sess2Result['pdf_path']}\n";

$cCheck3 = $db->query("SELECT paanyaya_attempt_count, max_paanyaya_reached FROM complaints WHERE complaint_id = {$complaintId}")->fetch(PDO::FETCH_ASSOC);
echo "  ✓ Attempt Count in DB: {$cCheck3['paanyaya_attempt_count']}\n";
echo "  ✓ Max Paanyaya Reached: {$cCheck3['max_paanyaya_reached']}\n";
assert((int)$cCheck3['paanyaya_attempt_count'] === 2);
assert((int)$cCheck3['max_paanyaya_reached'] === 1);

// TEST 5: Enforce "Dalawang Beses Lang" (Blocking 3rd Attempt)
echo "\n[5] Testing Rule Enforcement: Blocking 3rd Paanyaya Attempt (Dalawang Beses Lang)...\n";
$sess3Attempt = $paanyayaModel->schedule([
    'complaint_id' => $complaintId,
    'attempt_number' => 3,
    'invitation_date' => date('Y-m-d', strtotime('+10 weekdays')),
    'invitation_time' => '14:00'
], 1);

assert($sess3Attempt['success'] === false, "3rd attempt must be rejected");
echo "  ✓ 3rd Paanyaya correctly blocked: {$sess3Attempt['message']}\n";

// TEST 6: Session 2 Outcome Failure -> Automatic Escalation to Formal Docketing
echo "\n[6] Testing Session 2 Outcome Failure -> Automatic Escalation to Formal Docketing...\n";
$out2Result = $paanyayaModel->recordOutcome($sess2Id, [
    'outcome' => 'Max_Failed_Escalate',
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
    'outcome_notes' => 'Both parties attended but reached total impasse. No amicable agreement possible.'
], 1);

assert($out2Result['success'], "Escalation failed: " . ($out2Result['message'] ?? ''));
assert($out2Result['escalated'] === true, "Must be flagged as escalated");
$caseNumber = $out2Result['case_number'];
$caseId = $out2Result['case_id'];

echo "  ✓ Successfully Formally Docketed!\n";
echo "  ✓ Official Formal Case Number Assigned: {$caseNumber}\n";
echo "  ✓ Case ID: {$caseId}\n";
echo "  ✓ 1st Mediation Hearing Date: {$out2Result['hearing_date']} (Day: " . date('l', strtotime($out2Result['hearing_date'])) . ")\n";
echo "  ✓ 1st Mediation Hearing Time: {$out2Result['hearing_time']}\n";
echo "  ✓ Venue: {$out2Result['venue']}\n";
assert(date('w', strtotime($out2Result['hearing_date'])) == 2, "Mediation hearing must be on Tuesday only");
assert($out2Result['venue'] === 'Lupon Court Room', "Venue must be Lupon Court Room");

// Verify generated formal documents (KP 7, KP 8, KP 9)
echo "\n[7] Verifying Generated Formal Legal Documents (KP Form 7, 8, 9)...\n";
$docsStmt = $db->prepare("
    SELECT dt.template_name, gd.file_path, gd.service_status
    FROM generated_documents gd
    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
    WHERE gd.case_id = ?
");
$docsStmt->execute([$caseId]);
$docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($docs as $d) {
    echo "  ✓ Document: {$d['template_name']} -> {$d['file_path']}\n";
}
$templateNames = array_column($docs, 'template_name');
assert(in_array('KP Form 7', $templateNames, true), "KP Form 7 (Complaint) must be generated");
assert(in_array('KP Form 8', $templateNames, true), "KP Form 8 (Notice) must be generated");
assert(in_array('KP Form 9', $templateNames, true), "KP Form 9 (Summons) must be generated");

// TEST 8: Test Pre-Docket Settlement (LTIA Pre-Docket Resolution)
echo "\n[8] Testing Separate Complaint for Immediate Informal Settlement (LTIA Win)...\n";
$createData2 = $createData;
$createData2['complaint_title'] = 'Automated Test: Minor Noise Complaint Settled';
$res2 = $complaintModel->create($createData2);
$cid2 = (int) $res2['complaint_id'];
$pRes = $paanyayaModel->schedule(['complaint_id' => $cid2, 'invitation_date' => date('Y-m-d', strtotime('+2 weekdays')), 'invitation_time' => '14:00'], 1);
$sid2 = (int) $pRes['session_id'];

$settleRes = $paanyayaModel->recordOutcome($sid2, [
    'outcome' => 'Settled',
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
    'outcome_notes' => 'Parties shook hands and agreed on quiet hours.'
], 1);

assert($settleRes['success'] && $settleRes['outcome'] === 'Settled');
$cCheckSettle = $db->query("SELECT status, resolved_without_docketing, ltia_category FROM complaints WHERE complaint_id = {$cid2}")->fetch(PDO::FETCH_ASSOC);
echo "  ✓ Settled Complaint Status: {$cCheckSettle['status']}\n";
echo "  ✓ Resolved Without Docketing: {$cCheckSettle['resolved_without_docketing']}\n";
echo "  ✓ LTIA Category: {$cCheckSettle['ltia_category']}\n";
assert($cCheckSettle['status'] === 'Settled');
assert((int)$cCheckSettle['resolved_without_docketing'] === 1);
assert($cCheckSettle['ltia_category'] === 'Pre-Docket Resolution');

echo "\n====================================================\n";
echo "ALL TESTS PASSED SUCCESSFULLY! WORKFLOW VERIFIED.\n";
echo "====================================================\n";
