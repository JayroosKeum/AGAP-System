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

// The canonical test schema reserves Tuesdays for mediation.
runAssertion(Setting::getSetting('mediation_day_of_week', 'Tue') === 'Tue', 'Test database uses the Tuesday-only mediation schedule');
$summons = new Summons($db);
$tuesdayDate = date('Y-m-d', strtotime('next Tuesday + 7 days'));
$wednesdayDate = date('Y-m-d', strtotime($tuesdayDate . ' +1 day'));
$conflict = $summons->findMediationConflict("$wednesdayDate 14:00:00", 'Lupon Court Room', $caseId, null, 'Mediation');
runAssertion(($conflict['hearing_type'] ?? '') === 'Mediation Day Restriction', 'Wednesday mediation is blocked');

$beforeHours = $summons->findMediationConflict("$tuesdayDate 07:00:00", 'Lupon Court Room', $caseId, null, 'Mediation');
runAssertion($beforeHours !== null, 'Tuesday before opening is outside the seeded mediation hours');
$afternoon = $summons->findMediationConflict("$tuesdayDate 14:00:00", 'Lupon Court Room', $caseId, null, 'Mediation');
runAssertion($afternoon === null, 'Tuesday afternoon is available: ' . json_encode($afternoon));

echo "\nScheduling first mediation on Tuesday at 02:00 PM...\n";
$hearingCtrl = new HearingController();

$createData = [
    'case_id' => $caseId,
    'hearing_type' => 'Mediation',
    'hearing_date' => "{$tuesdayDate} 14:00:00",
    'venue' => 'Lupon Court Room',
    'remarks' => '1st Mediation test session',
    'schedule_reviewed' => '1'
];
$res1 = $hearingCtrl->create($createData, $userId);
runAssertion($res1['success'] === true, "1st Mediation scheduled successfully on Tuesday: " . ($res1['message'] ?? ''));
$h1Id = (int) $res1['hearing_id'];

// Mark 1st mediation as Completed with outcome 'Party Absent' and both absent
$db->prepare("UPDATE hearings SET status = 'Completed', queue_status = 'Concluded', complainant_attendance = 'Absent', respondent_attendance = 'Absent' WHERE hearing_id = ?")->execute([$h1Id]);
$db->prepare("
    INSERT INTO hearing_minutes (hearing_id, case_id, session_type, session_outcome, status, recorded_by, created_at)
    VALUES (?, ?, '1st Mediation', 'Party Absent', 'Finalized', ?, NOW())
")->execute([$h1Id, $caseId, $userId]);

// Schedule the second hearing using the ordinal hearing type.
echo "\nScheduling second mediation on the following Tuesday at 02:00 PM...\n";
$secondTuesdayTs = strtotime($tuesdayDate . ' +7 days');
$secondTuesdayDate = date('Y-m-d', $secondTuesdayTs);

$create2Data = [
    'case_id' => $caseId,
    'hearing_type' => '2nd Mediation', // Verify normalization
    'hearing_date' => "{$secondTuesdayDate} 14:00:00",
    'venue' => 'Lupon Court Room',
    'remarks' => '2nd Mediation test session after non-appearance',
    'schedule_reviewed' => '1'
];
$res2 = $hearingCtrl->create($create2Data, $userId);
runAssertion($res2['success'] === true, "2nd Mediation scheduled successfully on the following Tuesday: " . ($res2['message'] ?? ''));
$h2Id = (int) $res2['hearing_id'];

// TEST 4: Schedule with date and time in separate fields or without seconds
echo "\n[TEST 4] Test flexible date and time parsing in HearingController::validate...\n";
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
    'hearing_date' => $tuesdayDate,
    'hearing_time' => '15:30', // Separate date & time without seconds
    'venue' => '', // Should default to Lupon Court Room
    'remarks' => 'Tuesday test session',
    'schedule_reviewed' => '1'
];
$res3 = $hearingCtrl->create($create3Data, $userId);
runAssertion($res3['success'] === true, "Hearing scheduled with separate date and time and empty venue fallback: " . ($res3['message'] ?? ''));
$h3Id = (int) $res3['hearing_id'];

// Clean up
$cleanup->cleanup();

echo "\n====================================================================\n";
echo "   ALL SCHEDULING VERIFICATION TESTS PASSED SUCCESSFULLY! (100%)\n";
echo "====================================================================\n";
