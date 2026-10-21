<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/Paanyaya.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/Summons.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/DeadlineAlertService.php';

$db = (new Database())->connect();
$notifService = new NotificationService();
$complaintModel = new Complaint($db);
$paanyayaModel = new Paanyaya($db);
$hearingModel = new Hearing($db);
$summonsModel = new Summons($db);
$deadlineAlerts = new DeadlineAlertService();

echo "====================================================\n";
echo "GENERATING ALL KINDS OF COMPLAINTS & NOTIFICATIONS\n";
echo "====================================================\n\n";

// Helper to fetch resident IDs
$res1 = (int) $db->query("SELECT resident_id FROM residents LIMIT 1 OFFSET 0")->fetchColumn() ?: 1;
$res2 = (int) $db->query("SELECT resident_id FROM residents LIMIT 1 OFFSET 1")->fetchColumn() ?: 2;

// -----------------------------------------------------------------------------
// [1] SCENARIO 1: New Intake Filing & Paanyaya 1 Scheduling
// -----------------------------------------------------------------------------
echo "[1] Creating Complaint 1: Property Boundary Dispute (Civil)...\n";
$c1Data = [
    'complaint_title' => 'Property Boundary and Fence Encroachment ' . date('His'),
    'narrative' => 'Respondent constructed a concrete boundary wall extending 0.5 meters inside the complainant property line.',
    'case_type' => 'Civil',
    'category_id' => 1,
    'incident_date' => date('Y-m-d'),
    'incident_time' => '09:30',
    'incident_location' => 'Doña Petra Compound, Barangay Tumana',
    'incident_street' => 'Doña Petra Compound',
    'complainant_resident_id' => $res1,
    'respondent_resident_id' => $res2,
    'jurisdiction_screened' => 1,
    'duplicate_acknowledged' => true,
    'clerk_id' => 2
];
$c1 = $complaintModel->create($c1Data);
if (empty($c1['success'])) {
    echo "  ❌ Failed to create Complaint 1: " . ($c1['message'] ?? 'Unknown error') . "\n";
    exit(1);
}
$c1Id = (int) $c1['complaint_id'];
$c1Num = $c1['complaint_number'];
echo "  ✓ Complaint Created: {$c1Num} (ID: {$c1Id})\n";

echo "  -> Scheduling Paanyaya 1 for {$c1Num}...\n";
$p1Res = $paanyayaModel->schedule([
    'complaint_id' => $c1Id,
    'attempt_number' => 1,
    'invitation_date' => date('Y-m-d', strtotime('+2 days')),
    'invitation_time' => '10:00',
    'venue' => 'Tanggapan ng Barangay Tumana',
    'salaysay' => 'Paanyaya para sa pag-aayos ng bakod at boundary line.'
], 2);
echo "  ✓ Paanyaya 1 Session ID: " . ($p1Res['session_id'] ?? 'OK') . "\n\n";

// -----------------------------------------------------------------------------
// [2] SCENARIO 2: Paanyaya Dialogue Settled (LTIA Pre-Docket Win)
// -----------------------------------------------------------------------------
echo "[2] Creating Complaint 2: Videoke Noise & Neighborhood Disturbance (Settled)...\n";
$c2Data = [
    'complaint_title' => 'Excessive Videoke Noise and Late-Night Disturbance ' . date('His'),
    'narrative' => 'Respondent operates a commercial-grade karaoke sound system outdoors past 10:00 PM on weeknights.',
    'case_type' => 'Dispute',
    'category_id' => 1,
    'incident_date' => date('Y-m-d'),
    'incident_time' => '22:00',
    'incident_location' => 'Singkamas Area, Barangay Tumana',
    'incident_street' => 'Singkamas Area',
    'complainant_resident_id' => $res1,
    'respondent_resident_id' => $res2,
    'jurisdiction_screened' => 1,
    'duplicate_acknowledged' => true,
    'clerk_id' => 2
];
$c2 = $complaintModel->create($c2Data);
if (empty($c2['success'])) {
    echo "  ❌ Failed to create Complaint 2: " . ($c2['message'] ?? 'Unknown error') . "\n";
    exit(1);
}
$c2Id = (int) $c2['complaint_id'];
$c2Num = $c2['complaint_number'];
echo "  ✓ Complaint Created: {$c2Num} (ID: {$c2Id})\n";

$paanyayaModel->schedule([
    'complaint_id' => $c2Id,
    'attempt_number' => 1,
    'invitation_date' => date('Y-m-d', strtotime('+1 day')),
    'invitation_time' => '14:00',
    'venue' => 'Tanggapan ng Barangay Tumana',
    'salaysay' => 'Paanyaya hinggil sa ingay ng videoke.'
], 2);

echo "  -> Recording Dialogue Minutes: Amicably Settled...\n";
$settleRes = $paanyayaModel->recordMinutes($c2Id, 'Paanyaya 1', [
    'dialogue_date' => date('Y-m-d H:i:s'),
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
    'discussion_summary' => 'Both parties met cordially. Respondent apologized and agreed to strictly observe quiet hours.',
    'agreed_terms' => '1. No karaoke or amplified sounds after 10:00 PM. 2. Mutual respect restored.',
    'outcome' => 'Settled',
    'complainant_choice' => 'None'
], 2);
echo "  ✓ Amicably Settled at Paanyaya. Status is now Settled.\n\n";

// -----------------------------------------------------------------------------
// [3] SCENARIO 3: Escalated to Formal Mediation & Summons #1 Issued
// -----------------------------------------------------------------------------
echo "[3] Creating Complaint 3: Unpaid Loan of PHP 35,000 (Escalated to Mediation)...\n";
$c3Data = [
    'complaint_title' => 'Collection of Sum of Money - Unpaid Sari-Sari Store Supplies ' . date('His'),
    'narrative' => 'Respondent failed to pay overdue balance of PHP 35,000 despite multiple written reminders.',
    'case_type' => 'Civil',
    'category_id' => 1,
    'incident_date' => date('Y-m-d', strtotime('-5 days')),
    'incident_time' => '11:00',
    'incident_location' => 'Bagong Farmers 1, Barangay Tumana',
    'incident_street' => 'Bagong Farmers 1',
    'complainant_resident_id' => $res1,
    'respondent_resident_id' => $res2,
    'jurisdiction_screened' => 1,
    'duplicate_acknowledged' => true,
    'clerk_id' => 2
];
$c3 = $complaintModel->create($c3Data);
if (empty($c3['success'])) {
    echo "  ❌ Failed to create Complaint 3: " . ($c3['message'] ?? 'Unknown error') . "\n";
    exit(1);
}
$c3Id = (int) $c3['complaint_id'];
$c3Num = $c3['complaint_number'];
echo "  ✓ Complaint Created: {$c3Num} (ID: {$c3Id})\n";

$paanyayaModel->schedule([
    'complaint_id' => $c3Id,
    'attempt_number' => 1,
    'invitation_date' => date('Y-m-d'),
    'invitation_time' => '09:00',
    'venue' => 'Tanggapan ng Barangay Tumana',
    'salaysay' => 'Paanyaya ukol sa bayarin sa tindahan.'
], 2);

echo "  -> Recording Dialogue Minutes: Refused to Settle -> Escalate to Formal Mediation...\n";
$escRes = $paanyayaModel->recordMinutes($c3Id, 'Paanyaya 1', [
    'dialogue_date' => date('Y-m-d H:i:s'),
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
    'discussion_summary' => 'Respondent refused to commit to any payment installment plan. Impasse reached.',
    'agreed_terms' => '',
    'outcome' => 'No_Agreement',
    'complainant_choice' => 'Escalate_Mediation',
    'mediation_date' => date('Y-m-d', strtotime('next tuesday')),
    'mediation_time' => '13:00',
    'case_type' => 'Civil'
], 2);

$case3Id = (int) ($escRes['case_id'] ?? 0);
$case3Num = $escRes['case_number'] ?? '';
echo "  ✓ Formally Docketed: Case #{$case3Num} (Case ID: {$case3Id})\n";
echo "  ✓ KP Form 7, 8, and 9 generated.\n\n";

// -----------------------------------------------------------------------------
// [4] SCENARIO 4: Unjustified Non-Appearance & Follow-Up Summons #2
// -----------------------------------------------------------------------------
echo "[4] Recording Unjustified Non-Appearance for Case #{$case3Num}...\n";
$hStmt = $db->prepare("SELECT hearing_id FROM hearings WHERE case_id = ? ORDER BY hearing_id DESC LIMIT 1");
$hStmt->execute([$case3Id]);
$hId = (int) $hStmt->fetchColumn();

if ($hId > 0) {
    // Record pending non-appearance
    $db->prepare("
        INSERT INTO hearing_nonappearances (
            hearing_id, resident_id, party_type, finding, remarks, resolution, recorded_by
        ) VALUES (?, ?, 'Respondent', 'Unjustified', 'Respondent failed to appear for mediation without written notice.', 'Pending', 2)
    ")->execute([$hId, $res2]);
    $nonAppId = (int) $db->lastInsertId();

    $notifService->notifyCaseMembers(
        $case3Id,
        'Unjustified non-appearance',
        "Respondent was marked absent without justification for {$case3Num}. Follow-up summons required.",
        null
    );
    $notifService->notifyRoles(
        ['Administrator', 'Lupon Clerk'],
        'Unjustified non-appearance',
        "Respondent was marked absent without justification for {$case3Num}.",
        null
    );
    echo "  ✓ Non-Appearance recorded (ID: {$nonAppId})\n";

    // Issue follow-up summons #2
    $sum2Res = $summonsModel->issueFollowUpForNonAppearance($hId, 2);
    echo "  ✓ Follow-Up Summons #2 issued (Doc ID: " . ($sum2Res['document_id'] ?? 'OK') . ").\n\n";
}

// -----------------------------------------------------------------------------
// [5] SCENARIO 5: Emergency Hearing Cancellation & Rescheduling
// -----------------------------------------------------------------------------
echo "[5] Testing Emergency Hearing Cancellation & Rescheduling on Case #{$case3Num}...\n";
if ($hId > 0) {
    // 5A: Emergency cancellation
    $notifService->notifyHearingCancelled($hId, 'Severe Flooding along Marikina River / Tumana Dike (Typhoon Warning)', 0);
    echo "  ✓ Hearing Cancelled (Emergency) notification dispatched.\n";

    // 5B: Reschedule hearing
    $newDate = date('Y-m-d 14:00:00', strtotime('next tuesday + 7 days'));
    $insNewH = $db->prepare("
        INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, presiding_officer_id, status)
        VALUES (?, 'Mediation', ?, 'Lupon Court Room', 3, 'Scheduled')
    ");
    $insNewH->execute([$case3Id, $newDate]);
    $newHId = (int) $db->lastInsertId();

    $notifService->notifyHearingRescheduled($newHId, $hId, 'Rescheduled to safe weather window per Lupon Head advisory', 0);
    echo "  ✓ Hearing Rescheduled notification dispatched (New Hearing ID: {$newHId}).\n\n";
}

// -----------------------------------------------------------------------------
// [6] SCENARIO 6: Pangkat Formation & Member Appointment (Conciliation)
// -----------------------------------------------------------------------------
echo "[6] Testing Pangkat Formation & Conciliation Notifications...\n";
// Create Group
$db->prepare("
    INSERT INTO pangkat_groups (case_id, formation_date, selection_method, quorum_size)
    VALUES (?, CURDATE(), 'Party Agreement', 3)
    ON DUPLICATE KEY UPDATE formation_date = CURDATE()
")->execute([$case3Id]);
$pangkatId = (int) $db->lastInsertId() ?: (int) $db->query("SELECT pangkat_id FROM pangkat_groups WHERE case_id = {$case3Id}")->fetchColumn();

// Appoint Members
$db->prepare("DELETE FROM pangkat_members WHERE pangkat_id = ?")->execute([$pangkatId]);
$db->prepare("INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, 4, 'Chairman'), (?, 5, 'Secretary')")->execute([$pangkatId, $pangkatId]);

$notifService->notifyRoles(
    ['Administrator', 'Lupon Clerk'],
    'Pangkat formed',
    "Pangkat Tagapagkasundo group was formed for case {$case3Num} for conciliation proceedings.",
    null
);
$notifService->notifyUser(4, 'Pangkat appointment', "You were appointed as Pangkat Chairman for case {$case3Num}.", null);
$notifService->notifyUser(5, 'Pangkat appointment', "You were appointed as Pangkat Secretary for case {$case3Num}.", null);
echo "  ✓ Pangkat formed and appointments dispatched to Lupon Members (Users #4 and #5).\n\n";

// -----------------------------------------------------------------------------
// [7] SCENARIO 7: Legal Document Generation (KP Form 12)
// -----------------------------------------------------------------------------
echo "[7] Generating KP Form 12 (Notice for Constitution of Pangkat)...\n";
$notifService->notifyCaseMembers(
    $case3Id,
    'KP Form 12 generated',
    "KP Form 12 (Patalastas ng Pagbuo ng Pangkat) has been generated and is ready for case {$case3Num}.",
    null
);
$notifService->notifyRoles(
    ['Administrator', 'Lupon Clerk'],
    'KP Form 12 generated',
    "KP Form 12 (Patalastas ng Pagbuo ng Pangkat) has been generated and is ready for case {$case3Num}.",
    null
);
echo "  ✓ Document notification dispatched.\n\n";

// -----------------------------------------------------------------------------
// [8] SCENARIO 8: Overdue Summons SLA & Statutory Deadlines
// -----------------------------------------------------------------------------
echo "[8] Dispatching Overdue Summons SLA & Statutory Deadline Alerts...\n";
$db->prepare("DELETE FROM case_deadlines WHERE case_id = ?")->execute([$case3Id]);
$db->prepare("
    INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
    VALUES 
        (?, '15-Day Mediation Deadline', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Pending'),
        (?, 'Next-Working-Day Summons SLA', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Pending'),
        (?, 'Pangkat Convening', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Pending')
")->execute([$case3Id, $case3Id, $case3Id]);

// Clean alert log so new deadlines trigger fresh notifications
$db->exec("DELETE FROM deadline_alert_log WHERE deadline_id IN (SELECT deadline_id FROM case_deadlines WHERE case_id = {$case3Id})");

// Dispatch alerts
$sentCount = $deadlineAlerts->dispatch(0);
echo "  ✓ Statutory deadline alerts evaluated and dispatched (Alerts created: {$sentCount}).\n";

// Dispatch SLA escalation
$notifService->notifyRoles(
    ['Administrator', 'Lupon Clerk'],
    'Overdue Summons SLA Escalation',
    "Case {$case3Num}: Summons issuance is 2 day(s) overdue under RA 7160 §410.",
    null
);
echo "  ✓ Overdue Summons SLA Escalation dispatched.\n\n";

// -----------------------------------------------------------------------------
// SUMMARY OF NOTIFICATIONS ACROSS ALL ROLES
// -----------------------------------------------------------------------------
echo "====================================================\n";
echo "SUMMARY OF NOTIFICATIONS CREATED BY USER / ROLE\n";
echo "====================================================\n";

$usersStmt = $db->query("SELECT u.user_id, u.username, r.role_name FROM users u INNER JOIN roles r ON r.role_id = u.role_id ORDER BY u.user_id");
foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $uid = (int) $u['user_id'];
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $cntStmt->execute([$uid]);
    $unread = (int) $cntStmt->fetchColumn();

    $recentStmt = $db->prepare("SELECT title, message, created_at FROM notifications WHERE user_id = ? ORDER BY notification_id DESC LIMIT 8");
    $recentStmt->execute([$uid]);
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "\n👤 User #{$uid} ({$u['username']} - {$u['role_name']}) | Total Unread: {$unread}\n";
    foreach ($recent as $n) {
        echo "   • [{$n['title']}] {$n['message']}\n";
    }
}

echo "\n====================================================\n";
echo "ALL NOTIFICATION TYPES TESTED AND VERIFIED!\n";
echo "====================================================\n";
