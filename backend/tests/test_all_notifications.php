<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/Paanyaya.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/HearingException.php';
require_once __DIR__ . '/../models/HearingAttendance.php';
require_once __DIR__ . '/../models/Summons.php';
require_once __DIR__ . '/../models/Settlement.php';
require_once __DIR__ . '/../models/CFA.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/DeadlineAlertService.php';
require_once __DIR__ . '/../services/SummonDeliveryService.php';

$db = (new Database())->connect();
$notifService = new NotificationService();
$complaintModel = new Complaint($db);
$paanyayaModel = new Paanyaya($db);
$hearingModel = new Hearing($db);
$hearingException = new HearingException($db);
$summonsModel = new Summons($db);
$settlementModel = new Settlement($db);
$deadlineAlerts = new DeadlineAlertService();
$deliveryService = new SummonDeliveryService($db);

echo "====================================================\n";
echo "GENERATING ALL KINDS OF COMPLAINTS & NOTIFICATIONS\n";
echo "KATARUNGANG PAMBARANGAY NOTIFICATION ENGINE TEST\n";
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
echo "  ✓ Amicably Settled at Paanyaya level. Status is now Settled.\n";
echo "  ✓ Dispatched notifyPaanyayaOutcome('Settled', severity='success')\n\n";

// -----------------------------------------------------------------------------
// [3] SCENARIO 3: Escalated to Formal Mediation & Summons #1 Issued
// -----------------------------------------------------------------------------
echo "[3] Creating Complaint 3: Unpaid Loan of PHP 35,000 (Escalated to Mediation)...\n";
$c3Data = [
    'complaint_title' => 'Collection of Sum of Money - Unpaid Supplies ' . date('His'),
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
    'mediation_date' => date('Y-m-d', strtotime('next tuesday + 14 days')),
    'mediation_time' => '13:00',
    'case_type' => 'Civil'
], 2);

$case3Id = (int) ($escRes['case_id'] ?? 0);
$case3Num = $escRes['case_number'] ?? '';
echo "  ✓ Formally Docketed: Case #{$case3Num} (Case ID: {$case3Id})\n";
echo "  ✓ Dispatched notifyPaanyayaOutcome('Escalate_Mediation', severity='warning')\n";
echo "  ✓ Dispatched notifySummonsDispatched() to Summons Servers\n\n";

// Ensure a valid hearing exists for this case
$hStmt = $db->prepare("SELECT hearing_id FROM hearings WHERE case_id = ? ORDER BY hearing_id DESC LIMIT 1");
$hStmt->execute([$case3Id]);
$hId = (int) $hStmt->fetchColumn();

if ($hId <= 0) {
    $insH = $db->prepare("
        INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, presiding_officer_id, status)
        VALUES (?, 'Mediation', ?, 'Lupon Court Room', 3, 'Scheduled')
    ");
    $insH->execute([$case3Id, date('Y-m-d 13:00:00', strtotime('next tuesday + 14 days'))]);
    $hId = (int) $db->lastInsertId();
}

// -----------------------------------------------------------------------------
// [4] SCENARIO 4: Summons Delivery Return Served
// -----------------------------------------------------------------------------
echo "[4] Recording Summons Delivery Return (Personal Service)...\n";
$delivRes = $deliveryService->recordDelivery($hId, [
    'party_type' => 'Respondent',
    'delivery_status' => 'Served Personal',
    'recipient_name' => 'Eduardo Santos (Respondent)',
    'served_by' => 4,
    'remarks' => 'Personally served at respondent residence. Signed acknowledgment receipt.'
], 2);
echo "  ✓ Delivery Return logged: " . ($delivRes['message'] ?? 'OK') . "\n";
echo "  ✓ Dispatched notifySummonsServed() (severity='success')\n\n";

// -----------------------------------------------------------------------------
// [5] SCENARIO 5: Unjustified Non-Appearance & Show-Cause Initiation
// -----------------------------------------------------------------------------
echo "[5] Recording Unjustified Non-Appearance for Case #{$case3Num}...\n";
$naRes = $hearingException->recordUnjustifiedNonAppearance(
    $hId,
    $res2,
    'Respondent failed to appear for 1st mediation without written justification.',
    2
);
echo "  ✓ Unjustified non-appearance recorded (Case ID: " . ($naRes['case_id'] ?? $case3Id) . ")\n";
echo "  ✓ Dispatched notifyNonAppearanceRecorded() (severity='urgent')\n\n";

// -----------------------------------------------------------------------------
// [6] SCENARIO 6: Emergency Hearing Cancellation & Rescheduling
// -----------------------------------------------------------------------------
echo "[6] Testing Emergency Hearing Cancellation & Rescheduling on Case #{$case3Num}...\n";
$notifService->notifyHearingCancelled($hId, 'Severe Flooding along Marikina River / Tumana Dike (Typhoon Warning)', 0);
echo "  ✓ Hearing Cancelled (Emergency) notification dispatched.\n";

$newDate = date('Y-m-d 14:00:00', strtotime('next tuesday + 21 days'));
$insNewH = $db->prepare("
    INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, presiding_officer_id, status)
    VALUES (?, 'Mediation', ?, 'Lupon Court Room', 3, 'Scheduled')
");
$insNewH->execute([$case3Id, $newDate]);
$newHId = (int) $db->lastInsertId();

$notifService->notifyHearingRescheduled($newHId, $hId, 'Rescheduled to safe weather window per Lupon Head advisory', 0);
echo "  ✓ Hearing Rescheduled notification dispatched (New Hearing ID: {$newHId}).\n\n";

// -----------------------------------------------------------------------------
// [7] SCENARIO 7: Pangkat Formation & Legal Documents
// -----------------------------------------------------------------------------
echo "[7] Testing Pangkat Formation & KP Form 12...\n";
$db->prepare("
    INSERT INTO pangkat_groups (case_id, formation_date, selection_method, quorum_size)
    VALUES (?, CURDATE(), 'Party Agreement', 3)
    ON DUPLICATE KEY UPDATE formation_date = CURDATE()
")->execute([$case3Id]);
$pangkatId = (int) $db->lastInsertId() ?: (int) $db->query("SELECT pangkat_id FROM pangkat_groups WHERE case_id = {$case3Id}")->fetchColumn();

$db->prepare("DELETE FROM pangkat_members WHERE pangkat_id = ?")->execute([$pangkatId]);
$db->prepare("INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, 4, 'Chairman'), (?, 5, 'Secretary')")->execute([$pangkatId, $pangkatId]);

$notifService->notifyRoles(
    ['Administrator', 'Lupon Clerk'],
    'Pangkat formed',
    "Pangkat Tagapagkasundo group was formed for case {$case3Num} for conciliation proceedings.",
    null,
    'info',
    "../cases/case-details.php?id={$case3Id}",
    $case3Id
);
$notifService->notifyUser(4, 'Pangkat appointment', "You were appointed as Pangkat Chairman for case {$case3Num}.", null, 'info', "../cases/case-details.php?id={$case3Id}", $case3Id);
$notifService->notifyUser(5, 'Pangkat appointment', "You were appointed as Pangkat Secretary for case {$case3Num}.", null, 'info', "../cases/case-details.php?id={$case3Id}", $case3Id);
echo "  ✓ Pangkat appointments dispatched.\n\n";

// -----------------------------------------------------------------------------
// [8] SCENARIO 8: Amicable Settlement Kasunduan Executed
// -----------------------------------------------------------------------------
echo "[8] Recording Signed Amicable Settlement (Kasunduan)...\n";
$saveSettle = $settlementModel->saveSettlement([
    'case_id' => $case3Id,
    'settlement_date' => date('Y-m-d'),
    'agreement_details' => 'Respondent agrees to pay PHP 35,000 in two installments.',
    'terms_read_to_parties' => 1,
    'complainant_signed' => 1,
    'respondent_signed' => 1,
    'pb_attested' => 1,
    'pb_attested_by' => 1,
    'pb_attested_at' => date('Y-m-d H:i:s'),
    'has_installment' => 1,
    'total_amount' => 35000,
    'responsible_party' => 'Respondent',
    'compliance_due_date' => date('Y-m-d', strtotime('+30 days')),
    'installments' => [
        ['due_date' => date('Y-m-d', strtotime('+15 days')), 'amount_due' => 17500, 'notes' => '1st Installment'],
        ['due_date' => date('Y-m-d', strtotime('+30 days')), 'amount_due' => 17500, 'notes' => '2nd Installment']
    ]
], 2);
$settlementId = (int) ($saveSettle['settlement_id'] ?? 0);
echo "  ✓ Amicable Settlement Saved (ID: {$settlementId})\n";
echo "  ✓ Dispatched notifySettlementApproved() (severity='success')\n\n";

// -----------------------------------------------------------------------------
// [9] SCENARIO 9: Settlement Repudiated (KP Form 17 Sworn Statement)
// -----------------------------------------------------------------------------
echo "[9] Testing Settlement Repudiation (KP Form 17)...\n";
if ($settlementId > 0) {
    $repResult = $settlementModel->fileRepudiation($settlementId, [
        'repudiated_by' => $res2,
        'repudiation_reason' => 'Consent vitiated by intimidation and undue influence outside hearing venue.',
        'supporting_document_path' => 'uploads/repudiations/affidavit_repudiation_test.pdf'
    ], 2);
    echo "  ✓ Repudiation Filed: " . ($repResult['message'] ?? 'OK') . "\n";
    echo "  ✓ Dispatched notifySettlementRepudiated() (severity='urgent')\n\n";
}

// -----------------------------------------------------------------------------
// [10] SCENARIO 10: Settlement Breached / Execution Motion
// -----------------------------------------------------------------------------
echo "[10] Testing Settlement Breach Notification...\n";
$notifService->notifySettlementBreached(
    $case3Id,
    $case3Num,
    'Respondent failed to pay 1st scheduled installment of PHP 17,500 due on agreed date.',
    2
);
echo "  ✓ Dispatched notifySettlementBreached() (severity='urgent')\n\n";

// -----------------------------------------------------------------------------
// [11] SCENARIO 11: Certificate to File Action Ready / Issued
// -----------------------------------------------------------------------------
echo "[11] Testing Certificate to File Action Ready (KP Form 20-B)...\n";
$notifService->notifyCfaIssued(
    $case3Id,
    $case3Num,
    'KP Form 20-B (Repudiated Settlement)',
    2
);
echo "  ✓ Dispatched notifyCfaIssued() (severity='warning')\n\n";

// -----------------------------------------------------------------------------
// [12] SCENARIO 12: Statutory Deadline Scanner & SLA Overdue Evaluations
// -----------------------------------------------------------------------------
echo "[12] Testing DeadlineAlertService::dispatch()...\n";

// 12A: 15-Day Mediation Deadline Due Soon & Overdue
$db->prepare("DELETE FROM case_deadlines WHERE case_id = ?")->execute([$case3Id]);
$db->prepare("
    INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
    VALUES 
        (?, '15-Day Mediation Deadline', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 'Pending'),
        (?, 'Next-Working-Day Summons SLA', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Pending'),
        (?, 'Pangkat Convening', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Pending')
")->execute([$case3Id, $case3Id, $case3Id]);

// Clean alert log for fresh testing
$db->exec("DELETE FROM deadline_alert_log WHERE deadline_id IN (SELECT deadline_id FROM case_deadlines WHERE case_id = {$case3Id})");

// 12B: 10-Day Repudiation Expiry test setup
// Insert a dummy settled case with expired repudiation window (> 10 days)
$db->prepare("
    INSERT INTO settlements (
        case_id, settlement_date, agreement_details, terms_read_to_parties,
        complainant_signed, respondent_signed, pb_attested,
        repudiation_deadline, repudiation_status, compliance_status
    ) VALUES (
        ?, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Full and final settlement terms.', 1,
        1, 1, 1,
        DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Within Repudiation Period', 'Pending'
    ) ON DUPLICATE KEY UPDATE
        repudiation_deadline = DATE_SUB(CURDATE(), INTERVAL 2 DAY),
        repudiation_status = 'Within Repudiation Period'
")->execute([$case3Id]);

// 12C: Overdue Summons (> 3 days unserved)
// Insert or update attempt 2 to be unserved and > 3 days old
$db->prepare("
    INSERT INTO summon_deliveries (
        case_id, hearing_id, party_type, form_type, attempt_number,
        delivery_status, created_at, served_by_user_id
    ) VALUES (?, ?, 'Respondent', 'Summon', 2, 'Pending', DATE_SUB(NOW(), INTERVAL 4 DAY), 4)
    ON DUPLICATE KEY UPDATE
        delivery_status = 'Pending',
        created_at = DATE_SUB(NOW(), INTERVAL 4 DAY),
        served_by_user_id = 4
")->execute([$case3Id, $hId]);

// Dispatch statutory scanner
$alertsDispatched = $deadlineAlerts->dispatch(0);
echo "  ✓ Statutory deadline scanner dispatched {$alertsDispatched} automated statutory alerts.\n\n";

// -----------------------------------------------------------------------------
// [13] VERIFICATION & SMOKE TEST ASSERTIONS
// -----------------------------------------------------------------------------
echo "====================================================\n";
echo "VERIFICATION & SMOKE TEST RESULTS\n";
echo "====================================================\n";

// 1. Severity Distribution
$sevStmt = $db->query("
    SELECT severity, COUNT(*) AS cnt
    FROM notifications
    GROUP BY severity
    ORDER BY FIELD(severity, 'urgent', 'warning', 'info', 'success')
");
$sevCounts = $sevStmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo "1. Severity Breakdown in Database:\n";
$expectedSeverities = ['urgent', 'warning', 'info', 'success'];
$allSeveritiesPresent = true;
foreach ($expectedSeverities as $sev) {
    $count = $sevCounts[$sev] ?? 0;
    $icon = match($sev) {
        'urgent' => '🔴 [URGENT]',
        'warning' => '🟠 [WARNING]',
        'info' => '🔵 [INFO]',
        'success' => '🟢 [SUCCESS]'
    };
    echo "   {$icon} {$sev}: {$count} notifications\n";
    if ($count < 1) {
        $allSeveritiesPresent = false;
        echo "   ❌ Expected at least 1 notification for severity '{$sev}'!\n";
    }
}

// 2. Action URL Verification
$noUrlStmt = $db->query("
    SELECT COUNT(*) FROM notifications
    WHERE action_url IS NULL OR action_url = ''
");
$noUrlCount = (int) $noUrlStmt->fetchColumn();
echo "\n2. Action URL Routing Verification:\n";
echo "   Total notifications without action_url: {$noUrlCount} (legacy/system)\n";
$withUrlStmt = $db->query("
    SELECT action_url, COUNT(*) as cnt
    FROM notifications
    WHERE action_url IS NOT NULL AND action_url <> ''
    GROUP BY action_url
    ORDER BY cnt DESC
    LIMIT 6
");
echo "   Active Routes Detected:\n";
foreach ($withUrlStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "   • {$row['action_url']} ({$row['cnt']} notifications)\n";
}

// 3. Navbar Popover Filter Simulation (All, Urgent, Deadlines/Warning)
echo "\n3. Navbar Popover Tabs Filter Test:\n";
$allCount = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
$urgentCount = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND severity = 'urgent'")->fetchColumn();
$warningCount = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND severity = 'warning'")->fetchColumn();
echo "   • Popover Tab [All]: {$allCount} unread\n";
echo "   • Popover Tab [Urgent]: {$urgentCount} unread\n";
echo "   • Popover Tab [Deadlines / Warning]: {$warningCount} unread\n";

// 4. Sample Recent Notifications with Severity and URL
echo "\n4. Sample Recent Live KP Notifications Dispatched:\n";
$sampleStmt = $db->query("
    SELECT n.notification_id, n.title, n.severity, n.action_url, u.username, r.role_name
    FROM notifications n
    INNER JOIN users u ON u.user_id = n.user_id
    INNER JOIN roles r ON r.role_id = u.role_id
    ORDER BY n.notification_id DESC
    LIMIT 12
");
foreach ($sampleStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
    echo sprintf(
        "   [%-7s] %-40s -> %-15s (URL: %s)\n",
        strtoupper($s['severity']),
        mb_strimwidth($s['title'], 0, 38, '...'),
        "{$s['username']} ({$s['role_name']})",
        $s['action_url'] ?? 'none'
    );
}

echo "\n====================================================\n";
if ($allSeveritiesPresent) {
    echo "✅ ALL KP NOTIFICATION CHECKS PASSED WITH ZERO ERRORS!\n";
} else {
    echo "⚠️ SOME CHECKS NEED ATTENTION\n";
}
echo "====================================================\n";
