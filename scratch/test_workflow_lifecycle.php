<?php
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/models/Paanyaya.php';
require_once __DIR__ . '/../backend/models/Complaint.php';

$db = (new Database())->connect();
$paa = new Paanyaya($db);

echo "======================================================\n";
echo "TESTING 2-STAGE PAANYAYA WITH 6 ATTEMPTS EACH\n";
echo "======================================================\n";

// Create a mock complaint for testing
$db->exec("
    INSERT INTO complaints (complaint_number, complaint_title, narrative, status, incident_date, category_id)
    VALUES ('CMP-TEST-999', 'Boundary & Noise Dispute', 'Kapitbahay nag-aaway sa bakod at ingay.', 'Filed', CURDATE(), 1)
");
$cId = (int)$db->lastInsertId();
echo "[1] Created Test Complaint ID: {$cId}\n";

// Step 1: Initial state
$state = $paa->getTwoStagesData($cId);
assert($state['stage_1']['details']['status'] === 'Not Started');
assert($state['stage_2']['is_eligible'] === false);
assert($state['summons_prerequisite']['allowed'] === false);
echo "  ✓ Initial status: Paanyaya 1 is Not Started, Paanyaya 2 is Locked, Summons blocked.\n";

// Step 2: Issue Paanyaya 1
$issueRes = $paa->issueStage($cId, 'Paanyaya 1', [
    'invitation_date' => date('Y-m-d', strtotime('+3 days')),
    'invitation_time' => '14:00',
    'assigned_officer_id' => 6 // Summons Server
], 2);
assert($issueRes['success'] === true);
echo "  ✓ Paanyaya 1 issued successfully! PDF: {$issueRes['pdf_path']}\n";

$state = $paa->getTwoStagesData($cId);
assert($state['stage_1']['details']['status'] === 'In Progress');
assert($state['stage_1']['next_attempt_number'] === 1);
assert($state['summons_prerequisite']['allowed'] === false);
echo "  ✓ Paanyaya 1 is In Progress. Next attempt: 1 of 6. Summons still blocked.\n";

// Step 3: Record Attempt 1 (Unsuccessful)
$ret1 = $paa->recordOfficerReturn($cId, 'Paanyaya 1', [
    'service_datetime' => date('Y-m-d H:i:s'),
    'serving_officer_id' => 6,
    'recipient_name' => 'Juan Dela Cruz (Respondent)',
    'service_address' => '123 Riverbanks, Brgy Tumana',
    'delivery_outcome' => 'Recipient unavailable',
    'unsuccessful_reason' => 'Wala sa bahay nang magtungo ang server.',
    'remarks' => 'First attempt made at 10 AM.'
], 2);
assert($ret1['success'] === true);
assert($ret1['attempt_number'] === 1);
assert($ret1['stage_status'] === 'In Progress');
echo "  ✓ Attempt 1 recorded as Recipient unavailable. Status remains In Progress.\n";

$state = $paa->getTwoStagesData($cId);
assert($state['stage_1']['attempts_count'] === 1);
assert($state['stage_1']['next_attempt_number'] === 2);
assert($state['summons_prerequisite']['allowed'] === false);
echo "  ✓ Verified: 1 attempt used, next attempt is 2 of 6.\n";

// Step 4: Record Attempt 2 (Successfully Delivered)
$ret2 = $paa->recordOfficerReturn($cId, 'Paanyaya 1', [
    'service_datetime' => date('Y-m-d H:i:s'),
    'serving_officer_id' => 6,
    'recipient_name' => 'Juan Dela Cruz (Respondent)',
    'service_address' => '123 Riverbanks, Brgy Tumana',
    'delivery_outcome' => 'Successfully delivered',
    'remarks' => 'Personal service accepted and signed by respondent.'
], 2);
assert($ret2['success'] === true);
assert($ret2['attempt_number'] === 2);
assert($ret2['is_delivered'] === true);
assert($ret2['stage_status'] === 'Successfully Delivered');
echo "  ✓ Attempt 2 recorded as Successfully delivered. Stage 1 is Successfully Delivered!\n";

$state = $paa->getTwoStagesData($cId);
assert($state['stage_1']['is_delivered'] === true);
assert($state['stage_2']['is_eligible'] === true, "Paanyaya 2 must become eligible after Paanyaya 1 is delivered");
assert($state['summons_prerequisite']['allowed'] === true, "Summons prerequisite must unlock after successful delivery");
echo "  ✓ Verified: Stage 2 is now eligible! Formal Summons is unlocked!\n";

// Step 5: Issue Paanyaya 2
$issue2 = $paa->issueStage($cId, 'Paanyaya 2', [
    'invitation_date' => date('Y-m-d', strtotime('+7 days')),
    'invitation_time' => '15:00',
    'assigned_officer_id' => 6
], 2);
assert($issue2['success'] === true);
echo "  ✓ Paanyaya 2 issued successfully!\n";

// With Paanyaya 2 now In Progress, formal summons should be locked again pending Paanyaya 2 completion!
$state = $paa->getTwoStagesData($cId);
assert($state['stage_2']['details']['status'] === 'In Progress');
assert($state['stage_2']['attempts_count'] === 0);
assert($state['stage_2']['next_attempt_number'] === 1);
assert($state['summons_prerequisite']['allowed'] === false, "With Paanyaya 2 In Progress, summons should wait until Paanyaya 2 completes");
echo "  ✓ Verified: Paanyaya 2 has independent attempt counter (0 of 6 used). Summons correctly waiting on Paanyaya 2.\n";

// Step 6: Record 6 unserved attempts on Paanyaya 2 to test attempt exhaustion & max 6 limit
echo "[2] Testing 6 attempts exhaustion for Paanyaya 2...\n";
for ($att = 1; $att <= 6; $att++) {
    $res = $paa->recordOfficerReturn($cId, 'Paanyaya 2', [
        'service_datetime' => date('Y-m-d H:i:s'),
        'serving_officer_id' => 6,
        'recipient_name' => 'Juan Dela Cruz',
        'service_address' => '123 Riverbanks',
        'delivery_outcome' => 'Recipient refused to receive',
        'unsuccessful_reason' => "Tumanggi tanggapin (Subok {$att}).",
        'remarks' => "Attempt {$att} of 6"
    ], 2);
    assert($res['success'] === true);
    assert($res['attempt_number'] === $att);
    if ($att === 6) {
        assert($res['stage_status'] === 'Attempts Exhausted – For Review');
        assert($res['is_exhausted'] === true);
    }
}
echo "  ✓ Successfully recorded 6 attempts. Status became: Attempts Exhausted – For Review.\n";

// Test 7th attempt should fail
$att7 = $paa->recordOfficerReturn($cId, 'Paanyaya 2', [
    'service_datetime' => date('Y-m-d H:i:s'),
    'serving_officer_id' => 6,
    'recipient_name' => 'Juan Dela Cruz',
    'service_address' => '123 Riverbanks',
    'delivery_outcome' => 'Recipient refused to receive',
    'remarks' => 'Attempt 7'
], 2);
assert($att7['success'] === false);
echo "  ✓ Verified: 7th attempt was REJECTED: '{$att7['message']}'\n";

// Step 7: Summons is NOT auto-unlocked just because 6 attempts exhausted
$state = $paa->getTwoStagesData($cId);
assert($state['summons_prerequisite']['allowed'] === false);
echo "  ✓ Safeguard verified: Exhausted attempts do NOT automatically unlock summons without review.\n";

// Step 8: Authorized Review
echo "[3] Testing Authorized Lupon Review...\n";
$revRes = $paa->reviewStage($cId, 'Paanyaya 2', [
    'decision' => 'Approved for Summons',
    'review_notes' => 'Respondent willfully refused 6 invitations. Lupon authorizes immediate escalation to formal mediation summons.'
], 2);
assert($revRes['success'] === true);
echo "  ✓ Review decision recorded: Approved for Summons.\n";

$state = $paa->getTwoStagesData($cId);
assert($state['summons_prerequisite']['allowed'] === true);
echo "  ✓ Verified: Summons workflow unlocked via authorized review!\n";

// Clean up mock complaint
$db->exec("DELETE FROM complaints WHERE complaint_id = {$cId}");
echo "\n======================================================\n";
echo "ALL TESTS PASSED WITH 100% SUCCESS!\n";
echo "======================================================\n";
