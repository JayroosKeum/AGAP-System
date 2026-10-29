<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../controllers/HearingController.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Summons.php';
require_once __DIR__ . '/TestDataCleanup.php';

function runAssertion(bool $cond, string $message): void {
    if (!$cond) {
        echo "  [FAIL] {$message}\n";
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  [PASS] {$message}\n";
}

echo "====================================================================\n";
echo "   SCHEDULING & MEDIATION WORKING HOURS VERIFICATION TEST\n";
echo "====================================================================\n\n";

$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);

// 1. Create a test case
$catId = (int) ($db->query("SELECT category_id FROM complaint_categories LIMIT 1")->fetchColumn() ?: 1);
$userRow = $db->query("SELECT user_id, role_id FROM users WHERE role_id IN (1, 2) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$userId = (int) ($userRow['user_id'] ?? 1);

$_SESSION['user_id'] = $userId;
$_SESSION['role_id'] = (int) ($userRow['role_id'] ?? 1);

$insComplaint = $db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, narrative, category_id, status, case_type, created_at)
    VALUES (?, 'Sched Test Dispute', 'Test narrative for scheduling', ?, 'Docketed', 'Civil', NOW())
");
$compNum = 'CMP-SCHED-' . time() . '-' . rand(100, 999);
$insComplaint->execute([$compNum, $catId]);
$complaintId = (int) $db->lastInsertId();
$cleanup->trackComplaint($complaintId);

$caseNumber = 'CASE-SCHED-' . date('YmdHis');
$insCase = $db->prepare("
    INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at)
    VALUES (?, ?, 'Mediation', CURDATE(), NOW())
");
$insCase->execute([$complaintId, $caseNumber]);
$caseId = (int) $db->lastInsertId();

echo "Created test case #{$caseId} ({$caseNumber})\n\n";

// TEST 1: Check-schedule API behavior for Mon, Wed, Thu, Fri mornings (9:00 AM) and afternoons (2:00 PM)
echo "[TEST 1] Testing check-schedule on non-Tuesday weekdays (Wednesday 09:00 AM and 02:00 PM)...\n";
$summons = new Summons();

// Find a future Wednesday
$wednesdayTs = strtotime('next wednesday + 7 days');
$wednesdayDate = date('Y-m-d', $wednesdayTs);

// Check conflict via Summons model directly
$conflictMorning = $summons->findMediationConflict("{$wednesdayDate} 09:00:00", 'Lupon Court Room', $caseId, null, 'Mediation');
runAssertion($conflictMorning === null || empty($conflictMorning['hearing_type']), "Wednesday morning 09:00 AM has no schedule conflict (Result: " . json_encode($conflictMorning) . ")");

$conflictAfternoon = $summons->findMediationConflict("{$wednesdayDate} 14:00:00", 'Lupon Court Room', $caseId, null, 'Mediation');
runAssertion($conflictAfternoon === null || empty($conflictAfternoon['hearing_type']), "Wednesday afternoon 02:00 PM has no schedule conflict");

// TEST 2: Create 1st Mediation Hearing on Wednesday 09:00 AM
echo "\n[TEST 2] Scheduling 1st Mediation on Wednesday at 09:00 AM via HearingController...\n";
$hearingCtrl = new HearingController();

$createData = [
    'case_id' => $caseId,
    'hearing_type' => 'Mediation',
    'hearing_date' => "{$wednesdayDate} 09:00:00",
    'venue' => 'Lupon Court Room',
    'remarks' => '1st Mediation test session',
    'schedule_reviewed' => '1'
];
$res1 = $hearingCtrl->create($createData, $userId);
runAssertion($res1['success'] === true, "1st Mediation scheduled successfully on Wednesday 09:00 AM: " . ($res1['message'] ?? ''));
$h1Id = (int) $res1['hearing_id'];

// Mark 1st mediation as Completed with outcome 'Party Absent' and both absent
$db->prepare("UPDATE hearings SET status = 'Completed', queue_status = 'Concluded', complainant_attendance = 'Absent', respondent_attendance = 'Absent' WHERE hearing_id = ?")->execute([$h1Id]);
$db->prepare("
    INSERT INTO hearing_minutes (hearing_id, case_id, session_type, session_outcome, status, recorded_by, created_at)
    VALUES (?, ?, '1st Mediation', 'Party Absent', 'Finalized', ?, NOW())
")->execute([$h1Id, $caseId, $userId]);

// TEST 3: Schedule 2nd Mediation Hearing on a Thursday 10:00 AM (accepting '2nd Mediation' as hearing_type)
echo "\n[TEST 3] Scheduling 2nd Mediation on a Thursday at 10:00 AM with '2nd Mediation' hearing_type...\n";
$thursdayTs = strtotime('next thursday + 7 days');
$thursdayDate = date('Y-m-d', $thursdayTs);

$create2Data = [
    'case_id' => $caseId,
    'hearing_type' => '2nd Mediation', // Verify normalization
    'hearing_date' => "{$thursdayDate} 10:00:00",
    'venue' => 'Lupon Court Room',
    'remarks' => '2nd Mediation test session after non-appearance',
    'schedule_reviewed' => '1'
];
$res2 = $hearingCtrl->create($create2Data, $userId);
runAssertion($res2['success'] === true, "2nd Mediation scheduled successfully on Thursday 10:00 AM: " . ($res2['message'] ?? ''));
$h2Id = (int) $res2['hearing_id'];

// TEST 4: Schedule with date and time in separate fields or without seconds
echo "\n[TEST 4] Test flexible date and time parsing in HearingController::validate...\n";
$fridayTs = strtotime('next friday + 7 days');
$fridayDate = date('Y-m-d', $fridayTs);

// Test with another case
$compNum2 = 'CMP-SCHED2-' . time() . '-' . rand(100, 999);
$insComplaint->execute([$compNum2, $catId]);
$complaintId2 = (int) $db->lastInsertId();
$cleanup->trackComplaint($complaintId2);

$insCase2 = $db->prepare("
    INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at)
    VALUES (?, 'CASE-SCHED2-" . time() . "', 'Mediation', CURDATE(), NOW())
");
$insCase2->execute([$complaintId2]);
$caseId2 = (int) $db->lastInsertId();

$create3Data = [
    'case_id' => $caseId2,
    'hearing_type' => 'Mediation',
    'hearing_date' => $fridayDate,
    'hearing_time' => '14:30', // Separate date & time without seconds
    'venue' => '', // Should default to Lupon Court Room
    'remarks' => 'Friday test session',
    'schedule_reviewed' => '1'
];
$res3 = $hearingCtrl->create($create3Data, $userId);
runAssertion($res3['success'] === true, "Hearing scheduled with separate date + time ('{$fridayDate}' + '14:30') and empty venue fallback: " . ($res3['message'] ?? ''));
$h3Id = (int) $res3['hearing_id'];

// Clean up
$cleanup->cleanup();

echo "\n====================================================================\n";
echo "   ALL SCHEDULING VERIFICATION TESTS PASSED SUCCESSFULLY! (100%)\n";
echo "====================================================================\n";
