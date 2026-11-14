<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/Paanyaya.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Summons.php';
require_once __DIR__ . '/../controllers/HearingController.php';
require_once __DIR__ . '/../services/DocketScheduleService.php';
require_once __DIR__ . '/../services/OfficeLogisticsService.php';
require_once __DIR__ . '/../services/PaanyayaPreparationService.php';
require_once __DIR__ . '/../services/PaanyayaServiceDelivery.php';
require_once __DIR__ . '/../services/ResidentComplaintTimeline.php';
require_once __DIR__ . '/TestDataCleanup.php';

function runAssertion(bool $cond, string $message): void {
    if (!$cond) {
        echo "  [FAIL] {$message}\n";
        throw new RuntimeException("Assertion failed: {$message}");
    }
    echo "  [PASS] {$message}\n";
}

function nextWorkingTuesday(OfficeLogisticsService $logistics, string $afterDate): string {
    $ts = strtotime($afterDate . ' next Tuesday');
    for ($i = 0; $i < 20; $i++) {
        $candidate = date('Y-m-d', $ts);
        if ($logistics->isPhilippineHoliday($candidate) === null) {
            return $candidate;
        }
        $ts = strtotime($candidate . ' +7 days');
    }
    return date('Y-m-d', $ts);
}

function nextWorkingWeekday(OfficeLogisticsService $logistics, string $afterDate, int $targetDow = 3): string {
    $ts = strtotime($afterDate . ' +1 day');
    for ($i = 0; $i < 30; $i++) {
        $candidate = date('Y-m-d', $ts);
        if ((int) date('w', $ts) === $targetDow && $logistics->isPhilippineHoliday($candidate) === null) {
            return $candidate;
        }
        $ts = strtotime($candidate . ' +1 day');
    }
    return date('Y-m-d', $ts);
}

function httpJsonRequest(string $base, ?array $session, string $route, ?array $requestBody = null, bool $form = false): array {
    $headers = [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
    ];
    if ($session !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $session['id'];
        if (!empty($session['csrf_token'])) {
            $headers[] = 'X-CSRF-Token: ' . $session['csrf_token'];
        }
    }
    $options = [
        'header' => $headers,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ];
    if ($requestBody !== null) {
        $options['method'] = 'POST';
        $options['header'][] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
        $options['content'] = $form ? http_build_query($requestBody) : json_encode($requestBody, JSON_THROW_ON_ERROR);
    }

    $path = str_starts_with($route, '/') ? $route : '/backend/api/' . $route;
    $ctx = stream_context_create(['http' => $options]);
    $stream = @fopen($base . $path, 'rb', false, $ctx);
    if ($stream === false) {
        throw new RuntimeException("No HTTP response for {$route}");
    }
    $meta = stream_get_meta_data($stream);
    $body = stream_get_contents($stream);
    fclose($stream);
    $wrapperData = $meta['wrapper_data'] ?? [];
    if ($body === false || empty($wrapperData[0])) {
        throw new RuntimeException("No HTTP response for {$route}");
    }
    $status = (int) explode(' ', (string) $wrapperData[0])[1];
    return [
        'status' => $status,
        'body' => $body,
        'json' => json_decode($body, true),
    ];
}

$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);
$logistics = new OfficeLogisticsService($db);
$docketService = new DocketScheduleService($db);

$token = bin2hex(random_bytes(6));
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agap-sched-test-' . $token;
mkdir($sessionDir);
ini_set('session.save_path', $sessionDir);
ini_set('session.use_cookies', '0');

$process = null;
$logPath = $sessionDir . DIRECTORY_SEPARATOR . 'server.log';
$createdUserIds = [];
$createdHolidayDates = [];

register_shutdown_function(static function () use ($db, &$createdUserIds, &$createdHolidayDates, &$process, $sessionDir): void {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionDir);
    foreach ($createdHolidayDates as $hDate) {
        try {
            $db->prepare('DELETE FROM scheduling_holidays WHERE holiday_date = ?')->execute([$hDate]);
        } catch (Throwable) {}
    }
    foreach ($createdUserIds as $uId) {
        try {
            $db->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$uId]);
            $db->prepare('DELETE FROM resident_user_profiles WHERE user_id = ?')->execute([$uId]);
            $db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$uId]);
        } catch (Throwable) {}
    }
});

// Resolve Admin, Clerk, Lupon Member, Summons Server, and Resident test actors
$adminRow = $db->query("SELECT u.user_id, u.role_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Administrator' AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$clerkRow = $db->query("SELECT u.user_id, u.role_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Lupon Clerk' AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$luponRow = $db->query("SELECT u.user_id, u.role_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Lupon Member' AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$serverRow = $db->query("SELECT u.user_id, u.role_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Summons Server' AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$adminRow) {
    $adminRoleId = (int) ($db->query("SELECT role_id FROM roles WHERE role_name = 'Administrator'")->fetchColumn() ?: 1);
    $db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status) VALUES ('Test', 'Admin', ?, ?, ?, ?, 'Active')")
        ->execute(["admin_{$token}", "admin_{$token}@agap.test", password_hash($token, PASSWORD_DEFAULT), $adminRoleId]);
    $newAdminId = (int) $db->lastInsertId();
    $createdUserIds[] = $newAdminId;
    $adminRow = ['user_id' => $newAdminId, 'role_id' => $adminRoleId, 'role_name' => 'Administrator'];
}
if (!$clerkRow) {
    $clerkRoleId = (int) ($db->query("SELECT role_id FROM roles WHERE role_name = 'Lupon Clerk'")->fetchColumn() ?: 2);
    $db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status) VALUES ('Test', 'Clerk', ?, ?, ?, ?, 'Active')")
        ->execute(["clerk_{$token}", "clerk_{$token}@agap.test", password_hash($token, PASSWORD_DEFAULT), $clerkRoleId]);
    $newClerkId = (int) $db->lastInsertId();
    $createdUserIds[] = $newClerkId;
    $clerkRow = ['user_id' => $newClerkId, 'role_id' => $clerkRoleId, 'role_name' => 'Lupon Clerk'];
}
if (!$luponRow) {
    $luponRoleId = (int) ($db->query("SELECT role_id FROM roles WHERE role_name = 'Lupon Member'")->fetchColumn() ?: 3);
    $db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status) VALUES ('Test', 'Lupon', ?, ?, ?, ?, 'Active')")
        ->execute(["lupon_{$token}", "lupon_{$token}@agap.test", password_hash($token, PASSWORD_DEFAULT), $luponRoleId]);
    $newLuponId = (int) $db->lastInsertId();
    $createdUserIds[] = $newLuponId;
    $luponRow = ['user_id' => $newLuponId, 'role_id' => $luponRoleId, 'role_name' => 'Lupon Member'];
}

$residents = $db->query("SELECT resident_id FROM residents WHERE barangay = 'Tumana' ORDER BY resident_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
$residentRoleId = (int) ($db->query("SELECT role_id FROM roles WHERE role_name = 'Resident'")->fetchColumn() ?: 4);
$db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status) VALUES ('Test', 'Resident', ?, ?, ?, ?, 'Active')")
    ->execute(["resident_{$token}", "resident_{$token}@agap.test", password_hash($token, PASSWORD_DEFAULT), $residentRoleId]);
$residentUserId = (int) $db->lastInsertId();
$createdUserIds[] = $residentUserId;
if (!empty($residents[0])) {
    try {
        $db->prepare("INSERT IGNORE INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)")->execute([$residentUserId, (int) $residents[0]]);
    } catch (Throwable) {}
}
$residentRow = ['user_id' => $residentUserId, 'role_id' => $residentRoleId, 'role_name' => 'Resident'];

$actors = [
    'admin' => $adminRow,
    'clerk' => $clerkRow,
    'lupon' => $luponRow,
    'resident' => $residentRow,
];
if ($serverRow) {
    $actors['server'] = $serverRow;
}

foreach ($actors as $name => &$actor) {
    $actor['id'] = "{$token}-{$name}";
    $actor['csrf_token'] = bin2hex(random_bytes(32));
    session_id($actor['id']);
    session_start();
    $_SESSION = [
        'user_id' => (int) $actor['user_id'],
        'role_id' => (int) $actor['role_id'],
        'role_name' => $actor['role_name'],
        'csrf_token' => $actor['csrf_token'],
    ];
    session_write_close();
}
unset($actor);

$adminId = (int) $actors['admin']['user_id'];
$clerkId = (int) $actors['clerk']['user_id'];
$luponId = (int) $actors['lupon']['user_id'];

// Set default CLI session to Admin
$_SESSION['user_id'] = $adminId;
$_SESSION['role_id'] = (int) $actors['admin']['role_id'];
$_SESSION['role_name'] = 'Administrator';

// Start local built-in PHP HTTP server for API integration tests
$socket = stream_socket_server('tcp://127.0.0.1:0');
if ($socket === false) {
    throw new RuntimeException('Could not reserve a localhost port for the PHP test server.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$root = dirname(__DIR__, 2);
$process = proc_open(
    [PHP_BINARY, '-d', "session.save_path={$sessionDir}", '-S', $address, '-t', $root],
    [1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
    $pipes,
    $root
);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the PHP test server.');
}
for ($attempt = 0; $attempt < 30; $attempt++) {
    $connection = @stream_socket_client("tcp://{$address}", $errorCode, $error, 0.1);
    if ($connection) {
        fclose($connection);
        break;
    }
    if (!proc_get_status($process)['running'] || $attempt === 29) {
        throw new RuntimeException('PHP test server failed to start: ' . @file_get_contents($logPath));
    }
    usleep(100000);
}
$baseUrl = "http://{$address}";

echo "====================================================================\n";
echo "   SCHEDULING, DOCKET QUEUE & CROSS-DATE RESCHEDULE TEST SUITE\n";
echo "====================================================================\n\n";

// =========================================================================
// [TEST 1-4] Existing Mediation Working Hours & HearingController::create
// =========================================================================
echo "[TEST 1-4] Verifying Mediation Working Hours & HearingController::create...\n";

$catId = (int) ($db->query("SELECT category_id FROM complaint_categories LIMIT 1")->fetchColumn() ?: 1);
$insComplaint = $db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, narrative, category_id, status, case_type, submitted_by, created_at)
    VALUES (?, ?, 'Test narrative for scheduling', ?, 'Docketed', 'Civil', ?, NOW())
");
$insCase = $db->prepare("
    INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at)
    VALUES (?, ?, 'Mediation', CURDATE(), NOW())
");

$compNum = 'CMP-SCHED-' . time() . '-' . rand(100, 999);
$insComplaint->execute([$compNum, 'Sched Test Dispute', $catId, $residentUserId]);
$complaintId = (int) $db->lastInsertId();
$cleanup->trackComplaint($complaintId);

$caseNumber = 'CASE-SCHED-' . date('YmdHis') . '-1';
$insCase->execute([$complaintId, $caseNumber]);
$caseId = (int) $db->lastInsertId();

runAssertion(Setting::getSetting('mediation_day_of_week', 'Tue') === 'Tue', 'Test database uses the Tuesday-only mediation schedule');
$summons = new Summons($db);
$tuesdayDate = nextWorkingTuesday($logistics, date('Y-m-d', strtotime('+7 days')));
$wednesdayDate = date('Y-m-d', strtotime($tuesdayDate . ' +1 day'));

$conflict = $summons->findMediationConflict("{$wednesdayDate} 14:00:00", 'Lupon Court Room', $caseId, null, 'Mediation');
runAssertion(($conflict['hearing_type'] ?? '') === 'Mediation Day Restriction', 'Wednesday mediation is blocked');

$beforeHours = $summons->findMediationConflict("{$tuesdayDate} 07:00:00", 'Lupon Court Room', $caseId, null, 'Mediation');
runAssertion($beforeHours !== null, 'Tuesday before opening is outside the seeded mediation hours');
$afternoon = $summons->findMediationConflict("{$tuesdayDate} 14:00:00", 'Lupon Court Room', $caseId, null, 'Mediation');
runAssertion($afternoon === null, 'Tuesday afternoon is available');

$hearingCtrl = new HearingController();
$res1 = $hearingCtrl->create([
    'case_id' => $caseId,
    'hearing_type' => 'Mediation',
    'hearing_date' => "{$tuesdayDate} 14:00:00",
    'venue' => 'Lupon Court Room',
    'remarks' => '1st Mediation test session',
    'schedule_reviewed' => '1'
], $adminId);
runAssertion($res1['success'] === true, "1st Mediation scheduled successfully on Tuesday");
$h1Id = (int) $res1['hearing_id'];

$db->prepare("UPDATE hearings SET status = 'Completed', queue_status = 'Concluded', complainant_attendance = 'Absent', respondent_attendance = 'Absent' WHERE hearing_id = ?")->execute([$h1Id]);
$db->prepare("
    INSERT INTO hearing_minutes (hearing_id, case_id, session_type, session_outcome, status, recorded_by, created_at)
    VALUES (?, ?, '1st Mediation', 'Party Absent', 'Finalized', ?, NOW())
")->execute([$h1Id, $caseId, $adminId]);

$secondTuesdayDate = nextWorkingTuesday($logistics, $tuesdayDate);
$res2 = $hearingCtrl->create([
    'case_id' => $caseId,
    'hearing_type' => '2nd Mediation',
    'hearing_date' => "{$secondTuesdayDate} 14:00:00",
    'venue' => 'Lupon Court Room',
    'remarks' => '2nd Mediation test session after non-appearance',
    'schedule_reviewed' => '1'
], $adminId);
runAssertion($res2['success'] === true, "2nd Mediation scheduled successfully on the following working Tuesday");
$h2Id = (int) $res2['hearing_id'];

$compNum2 = 'CMP-SCHED2-' . time() . '-' . rand(100, 999);
$insComplaint->execute([$compNum2, 'Sched Test Dispute 2', $catId, $residentUserId]);
$complaintId2 = (int) $db->lastInsertId();
$cleanup->trackComplaint($complaintId2);

$insCase->execute([$complaintId2, 'CASE-SCHED2-' . time()]);
$caseId2 = (int) $db->lastInsertId();

$res3 = $hearingCtrl->create([
    'case_id' => $caseId2,
    'hearing_type' => 'Mediation',
    'hearing_date' => $tuesdayDate,
    'hearing_time' => '15:00',
    'venue' => '',
    'remarks' => 'Tuesday test session',
    'schedule_reviewed' => '1'
], $adminId);
runAssertion($res3['success'] === true, "Hearing scheduled with separate date and time and empty venue fallback");
$h3Id = (int) $res3['hearing_id'];

// =========================================================================
// [TEST 5] Role-Based Authorization (Admin & Clerk Allowed; Others Rejected)
// =========================================================================
echo "\n[TEST 5] Verifying server-side staff eligibility & unauthorized API rejection...\n";

$unauthCheck = $docketService->isAuthorizedStaff(0, null);
runAssertion($unauthCheck['authorized'] === false && $unauthCheck['http_code'] === 401, 'Unauthenticated user_id=0 is rejected with HTTP 401');

$luponAuthCheck = $docketService->isAuthorizedStaff($luponId, (int) $actors['lupon']['role_id']);
runAssertion($luponAuthCheck['authorized'] === false && $luponAuthCheck['http_code'] === 403, 'Lupon Member is rejected with HTTP 403');

$residentAuthCheck = $docketService->isAuthorizedStaff($residentUserId, (int) $actors['resident']['role_id']);
runAssertion($residentAuthCheck['authorized'] === false && $residentAuthCheck['http_code'] === 403, 'Resident is rejected with HTTP 403');

$adminAuthCheck = $docketService->isAuthorizedStaff($adminId, (int) $actors['admin']['role_id']);
$clerkAuthCheck = $docketService->isAuthorizedStaff($clerkId, (int) $actors['clerk']['role_id']);
runAssertion($adminAuthCheck['authorized'] === true && $clerkAuthCheck['authorized'] === true, 'Both Administrator and Lupon Clerk are authorized');

// Direct HTTP API checks against /backend/api/hearings/queue.php
$httpUnauthGet = httpJsonRequest($baseUrl, null, "hearings/queue.php?action=check_slots&type=hearing&id={$h3Id}&target_date={$tuesdayDate}");
runAssertion($httpUnauthGet['status'] === 401 && ($httpUnauthGet['json']['success'] ?? true) === false, 'Direct unauthenticated HTTP GET to queue.php returns 401');

$httpUnauthPost = httpJsonRequest($baseUrl, null, 'hearings/queue.php', ['action' => 'reorder_queue', 'queue_date' => $tuesdayDate, 'items' => []]);
runAssertion(in_array($httpUnauthPost['status'], [401, 419], true) && ($httpUnauthPost['json']['success'] ?? true) === false, 'Direct unauthenticated HTTP POST to queue.php is rejected (401/419)');

$httpLuponReorder = httpJsonRequest($baseUrl, $actors['lupon'], 'hearings/queue.php', ['action' => 'reorder_queue', 'queue_date' => $tuesdayDate, 'items' => []]);
runAssertion($httpLuponReorder['status'] === 403 && ($httpLuponReorder['json']['success'] ?? true) === false, 'Lupon Member HTTP POST reorder_queue to queue.php returns 403 (got ' . $httpLuponReorder['status'] . ': ' . $httpLuponReorder['body'] . ')');

$httpLuponResched = httpJsonRequest($baseUrl, $actors['lupon'], 'hearings/queue.php', ['action' => 'reschedule_item', 'id' => $h3Id, 'type' => 'hearing']);
runAssertion($httpLuponResched['status'] === 403 && ($httpLuponResched['json']['success'] ?? true) === false, 'Lupon Member HTTP POST reschedule_item to queue.php returns 403 (got ' . $httpLuponResched['status'] . ': ' . $httpLuponResched['body'] . ')');

$httpLuponSlots = httpJsonRequest($baseUrl, $actors['lupon'], "hearings/queue.php?action=check_slots&type=hearing&id={$h3Id}&target_date={$tuesdayDate}");
runAssertion($httpLuponSlots['status'] === 403 && ($httpLuponSlots['json']['success'] ?? true) === false, 'Lupon Member HTTP GET check_slots to queue.php returns 403 (got ' . $httpLuponSlots['status'] . ': ' . $httpLuponSlots['body'] . ')');

$httpResidentReorder = httpJsonRequest($baseUrl, $actors['resident'], 'hearings/queue.php', ['action' => 'reorder_queue', 'queue_date' => $tuesdayDate, 'items' => []]);
runAssertion($httpResidentReorder['status'] === 403 && ($httpResidentReorder['json']['success'] ?? true) === false, 'Resident HTTP POST to queue.php returns 403 (got ' . $httpResidentReorder['status'] . ': ' . $httpResidentReorder['body'] . ')');

// =========================================================================
// [TEST 6] Same-Day Queue Reordering (Preserves Official Times & Notices)
// =========================================================================
echo "\n[TEST 6] Verifying same-day queue reorder changes queue_order ONLY and preserves official appointment times...\n";

// Pick a dedicated future working Tuesday for docket reorder & cross-date reschedule tests
$docketTue = nextWorkingTuesday($logistics, date('Y-m-d', strtotime($secondTuesdayDate . ' +14 days')));
$nextDocketTue = nextWorkingTuesday($logistics, $docketTue);

// Ensure no leftover records exist on $docketTue or $nextDocketTue
$db->prepare("DELETE FROM hearings WHERE DATE(hearing_date) IN (?, ?)")->execute([$docketTue, $nextDocketTue]);
$db->prepare("DELETE FROM paanyayas WHERE scheduled_date IN (?, ?)")->execute([$docketTue, $nextDocketTue]);
$db->prepare("DELETE FROM paanyaya_sessions WHERE scheduled_date IN (?, ?)")->execute([$docketTue, $nextDocketTue]);

// Create Case A (Hearing A at 09:00 AM on $docketTue)
$insComplaint->execute(['CMP-DOCK-A-' . $token, 'Docket Case A', $catId, $residentUserId]);
$compAId = (int) $db->lastInsertId();
$cleanup->trackComplaint($compAId);
$insCase->execute([$compAId, 'CASE-DOCK-A-' . $token]);
$caseAId = (int) $db->lastInsertId();

$resHA = $hearingCtrl->create([
    'case_id' => $caseAId,
    'hearing_type' => 'Mediation',
    'hearing_date' => "{$docketTue} 09:00:00",
    'venue' => 'Lupon Court Room',
    'remarks' => 'Docket Hearing A',
    'schedule_reviewed' => '1'
], $adminId);
runAssertion($resHA['success'] === true, 'Created Docket Hearing A at 09:00 AM');
$hearingAId = (int) $resHA['hearing_id'];
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type)
    VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$compAId, (int) $residents[0], $compAId, (int) $residents[1], $compAId, (int) $residents[2]]);

// Attach a generated document and summon_delivery to Hearing A so we can verify queue reorder does not touch it
$kp9TemplateId = (int) ($db->query("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 9' LIMIT 1")->fetchColumn() ?: 3);
$db->prepare("INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status) VALUES (?, ?, ?, 'storage/test_kp9_a.pdf', 'Served')")
    ->execute([$caseAId, $kp9TemplateId, $adminId]);
$docAId = (int) $db->lastInsertId();
$db->prepare("
    INSERT INTO summon_deliveries (hearing_id, case_id, party_type, resident_id, form_type, attempt_number, delivery_status, recipient_name, document_id, created_by)
    VALUES (?, ?, 'Respondent', ?, 'KP Form 9', 1, 'Served', 'Test Respondent A', ?, ?)
")->execute([$hearingAId, $caseAId, $residents[1] ?? null, $docAId, $adminId]);
$deliveryAId = (int) $db->lastInsertId();

// Create Case B (Hearing B at 10:30 AM on $docketTue)
$insComplaint->execute(['CMP-DOCK-B-' . $token, 'Docket Case B', $catId, $residentUserId]);
$compBId = (int) $db->lastInsertId();
$cleanup->trackComplaint($compBId);
$insCase->execute([$compBId, 'CASE-DOCK-B-' . $token]);
$caseBId = (int) $db->lastInsertId();

$resHB = $hearingCtrl->create([
    'case_id' => $caseBId,
    'hearing_type' => 'Mediation',
    'hearing_date' => "{$docketTue} 10:30:00",
    'venue' => 'Lupon Court Room',
    'presiding_officer_id' => $luponId,
    'remarks' => 'Docket Hearing B',
    'schedule_reviewed' => '1'
], $adminId);
runAssertion($resHB['success'] === true, 'Created Docket Hearing B at 10:30 AM');
$hearingBId = (int) $resHB['hearing_id'];

// Create Complaint P (Paanyaya P at 02:00 PM on $docketTue with full preparation + delivered service attempt)
$db->prepare("
    INSERT INTO complaints (
        complaint_number, intake_reference, complaint_title, narrative, category_id,
        status, screening_decision, case_type, intake_source, submitted_by, liso_reviewed_at, created_at
    ) VALUES (?, ?, 'Paanyaya Docket Test', 'Test Paanyaya narrative', ?, 'Accepted', 'Eligible', 'Civil', 'Online', ?, NOW(), NOW())
")->execute(['CMP-PAA-' . $token, 'INT-PAA-' . $token, $catId, $residentUserId]);
$compPId = (int) $db->lastInsertId();
$cleanup->trackComplaint($compPId);

if (count($residents) >= 2) {
    $db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
        ->execute([$compPId, (int) $residents[0], $compPId, (int) $residents[1]]);
}

$prepService = new PaanyayaPreparationService($db);
$partiesP = $prepService->parties($compPId);
$prepService->save($compPId, $clerkId, 'generate', [
    'appointment_date' => $docketTue,
    'appointment_time' => '14:00',
    'venue' => 'Lupon Court Room',
    'verify_parties' => '1',
    'party_version' => PaanyayaPreparationService::partyVersion($partiesP),
], 1);
$intakeP = $prepService->intake($compPId, 1);
$origDocVersionP = (string) ($intakeP['document_version'] ?? '');
runAssertion($origDocVersionP !== '', 'Paanyaya invitation PDF generated with SHA-256 version');
$prepService->save($compPId, $clerkId, 'ready', ['document_version' => $origDocVersionP], 1);

$deliveryService = new PaanyayaServiceDelivery($db);
$servers = $deliveryService->servers();
$assignedServerId = (int) ($servers[0]['user_id'] ?? $actors['server']['user_id'] ?? 0);
if ($assignedServerId < 1) {
    $db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status) VALUES ('Test', 'Server', ?, ?, ?, 4, 'Active')")
        ->execute(["server_{$token}", "server_{$token}@agap.test", password_hash($token, PASSWORD_DEFAULT)]);
    $assignedServerId = (int) $db->lastInsertId();
    $createdUserIds[] = $assignedServerId;
}

$deliveryService->release($compPId, $clerkId, $assignedServerId, $origDocVersionP, 1);
$latestAttemptP = PaanyayaServiceDelivery::latestAttempt($db, $compPId, 1);
$attemptPId = (int) ($latestAttemptP['attempt_id'] ?? 0);
runAssertion($attemptPId > 0, 'Paanyaya service attempt created');
$deliveryService->record($attemptPId, $assignedServerId, [
    'outcome' => 'Delivered',
    'notes' => 'Served personally to respondent',
], []);

// Fetch the authoritative paanyayas and paanyaya_sessions rows created by activateDialogue
$paanyayaPId = (int) $db->query("SELECT paanyaya_id FROM paanyayas WHERE complaint_id = {$compPId} ORDER BY paanyaya_id DESC LIMIT 1")->fetchColumn();
$sessionPId = (int) $db->query("SELECT session_id FROM paanyaya_sessions WHERE complaint_id = {$compPId} ORDER BY session_id DESC LIMIT 1")->fetchColumn();
runAssertion($paanyayaPId > 0 && $sessionPId > 0, 'Delivered Paanyaya activated authoritative paanyayas and paanyaya_sessions rows');

$db->prepare("
    INSERT INTO complaint_paanyaya (
        complaint_id, invitation_date, invitation_time, venue, status, scheduled_by
    ) VALUES (?, ?, '14:00:00', 'Lupon Court Room', 'Scheduled', ?)
")->execute([$compPId, $docketTue, $clerkId]);

// Capture original official appointment timestamps before reordering
$origHA = $db->query("SELECT hearing_date, end_time, queue_order FROM hearings WHERE hearing_id = {$hearingAId}")->fetch(PDO::FETCH_ASSOC);
$origHB = $db->query("SELECT hearing_date, end_time, queue_order FROM hearings WHERE hearing_id = {$hearingBId}")->fetch(PDO::FETCH_ASSOC);
$origPP = $db->query("SELECT scheduled_date, scheduled_time, queue_order FROM paanyayas WHERE paanyaya_id = {$paanyayaPId}")->fetch(PDO::FETCH_ASSOC);
$origPS = $db->query("SELECT scheduled_date, scheduled_time, queue_order FROM paanyaya_sessions WHERE session_id = {$sessionPId}")->fetch(PDO::FETCH_ASSOC);
$origPrep = $db->query("SELECT appointment_at, SHA2(document_pdf, 256) AS doc_ver FROM paanyaya_preparations WHERE complaint_id = {$compPId} AND invitation_number = 1")->fetch(PDO::FETCH_ASSOC);
$origAtt = $db->query("SELECT * FROM paanyaya_service_attempts WHERE attempt_id = {$attemptPId}")->fetch(PDO::FETCH_ASSOC);
$origPdf = $prepService->document($compPId);

// Reorder via Administrator: [Paanyaya P, Hearing B, Hearing A]
$adminReorderRes = httpJsonRequest($baseUrl, $actors['admin'], 'hearings/queue.php', [
    'action' => 'reorder_queue',
    'queue_date' => $docketTue,
    'view_mode' => 'day',
    'is_filtered' => false,
    'is_paginated' => false,
    'full_day_loaded' => true,
    'items' => [
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId],
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ]
]);
runAssertion($adminReorderRes['status'] === 200 && ($adminReorderRes['json']['success'] ?? false) === true, 'Administrator same-day reorder succeeded via HTTP queue.php: ' . $adminReorderRes['body']);

// Reorder via Lupon Clerk: [Hearing B, Paanyaya P, Hearing A]
$clerkReorderRes = httpJsonRequest($baseUrl, $actors['clerk'], 'hearings/queue.php', [
    'action' => 'reorder_queue',
    'queue_date' => $docketTue,
    'view_mode' => 'day',
    'is_filtered' => false,
    'is_paginated' => false,
    'full_day_loaded' => true,
    'items' => [
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ]
]);
runAssertion($clerkReorderRes['status'] === 200 && ($clerkReorderRes['json']['success'] ?? false) === true, 'Lupon Clerk same-day reorder succeeded via HTTP queue.php');

// Verify queue_order changed while official appointment times and served notices remained 100% untouched
$afterHA = $db->query("SELECT hearing_date, end_time, queue_order FROM hearings WHERE hearing_id = {$hearingAId}")->fetch(PDO::FETCH_ASSOC);
$afterHB = $db->query("SELECT hearing_date, end_time, queue_order FROM hearings WHERE hearing_id = {$hearingBId}")->fetch(PDO::FETCH_ASSOC);
$afterPP = $db->query("SELECT scheduled_date, scheduled_time, queue_order FROM paanyayas WHERE paanyaya_id = {$paanyayaPId}")->fetch(PDO::FETCH_ASSOC);
$afterPS = $db->query("SELECT scheduled_date, scheduled_time, queue_order FROM paanyaya_sessions WHERE session_id = {$sessionPId}")->fetch(PDO::FETCH_ASSOC);
$afterPrep = $db->query("SELECT appointment_at, SHA2(document_pdf, 256) AS doc_ver FROM paanyaya_preparations WHERE complaint_id = {$compPId} AND invitation_number = 1")->fetch(PDO::FETCH_ASSOC);
$afterAtt = $db->query("SELECT appointment_at, document_version FROM paanyaya_service_attempts WHERE attempt_id = {$attemptPId}")->fetch(PDO::FETCH_ASSOC);
$afterDeliv = $db->query("SELECT delivery_status FROM summon_deliveries WHERE delivery_id = {$deliveryAId}")->fetchColumn();

runAssertion((int) $afterHB['queue_order'] === 1 && (int) $afterPP['queue_order'] === 2 && (int) $afterPS['queue_order'] === 2 && (int) $afterHA['queue_order'] === 3, 'Queue order updated to [Hearing B=1, Paanyaya P=2, Hearing A=3] across hearings, paanyayas, and paanyaya_sessions');
runAssertion($afterHA['hearing_date'] === $origHA['hearing_date'] && $afterHA['end_time'] === $origHA['end_time'], 'Hearing A official hearing_date (09:00) and end_time were NOT recalculated during queue reorder');
runAssertion($afterHB['hearing_date'] === $origHB['hearing_date'] && $afterHB['end_time'] === $origHB['end_time'], 'Hearing B official hearing_date (10:30) and end_time were NOT recalculated during queue reorder');
runAssertion($afterPP['scheduled_date'] === $origPP['scheduled_date'] && $afterPP['scheduled_time'] === $origPP['scheduled_time'], 'Paanyaya P scheduled_date and scheduled_time (14:00:00) were NOT recalculated during queue reorder');
runAssertion($afterPS['scheduled_date'] === $origPS['scheduled_date'] && $afterPS['scheduled_time'] === $origPS['scheduled_time'], 'paanyaya_sessions scheduled_date and scheduled_time were NOT recalculated during queue reorder');
runAssertion($afterPrep['appointment_at'] === $origPrep['appointment_at'] && $afterPrep['doc_ver'] === $origPrep['doc_ver'], 'paanyaya_preparations appointment_at and PDF hash were NOT altered by queue reorder');
runAssertion($afterAtt['appointment_at'] === $origAtt['appointment_at'] && $afterAtt['document_version'] === $origAtt['document_version'], 'paanyaya_service_attempts appointment_at and document_version were NOT altered by queue reorder');
runAssertion($afterDeliv === 'Served', 'Served summon_deliveries status remained Served after queue-only reorder');

// Verify Report::dashboardStats returns identical queue_order-sorted calendar_hearings for Admin and Clerk
$reportModel = new Report();
$adminDashData = $reportModel->dashboardStats('all');
$clerkDashData = $reportModel->dashboardStats('all');

$filterDayItems = static function (array $calHearings) use ($docketTue): array {
    return array_values(array_filter($calHearings, static fn(array $r): bool => substr((string) ($r['hearing_date'] ?? ''), 0, 10) === $docketTue));
};
$adminDayItems = $filterDayItems($adminDashData['calendar_hearings'] ?? []);
$clerkDayItems = $filterDayItems($clerkDashData['calendar_hearings'] ?? []);
runAssertion(count($adminDayItems) === 3 && count($clerkDayItems) === 3, 'Both Admin and Clerk dashboard payloads include all 3 docket items (Hearings + Paanyaya) for the target date');
runAssertion(
    (int) $adminDayItems[0]['hearing_id'] === $hearingBId
    && (int) ($adminDayItems[1]['paanyaya_id'] ?? 0) === $paanyayaPId && ($adminDayItems[1]['record_type'] ?? '') === 'paanyaya'
    && (int) $adminDayItems[2]['hearing_id'] === $hearingAId
    && array_column($adminDayItems, 'hearing_id') === array_column($clerkDayItems, 'hearing_id'),
    'Admin and Clerk dashboards sort mixed Hearings + Paanyayas identically by queue_order'
);

// =========================================================================
// [TEST 7] Unchanged / Cancelled Drags & Failed Request Rollback
// =========================================================================
echo "\n[TEST 7] Verifying unchanged drags make no DB changes and failed requests roll back cleanly...\n";

$auditCountBeforeNoOp = (int) $db->query("SELECT COUNT(*) FROM audit_trails")->fetchColumn();
$noOpRes = httpJsonRequest($baseUrl, $actors['admin'], 'hearings/queue.php', [
    'action' => 'reorder_queue',
    'queue_date' => $docketTue,
    'view_mode' => 'day',
    'full_day_loaded' => true,
    'items' => [
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ]
]);
$auditCountAfterNoOp = (int) $db->query("SELECT COUNT(*) FROM audit_trails")->fetchColumn();
runAssertion(
    $noOpRes['status'] === 200
    && ($noOpRes['json']['success'] ?? false) === true
    && ($noOpRes['json']['no_op'] ?? false) === true
    && $auditCountAfterNoOp === $auditCountBeforeNoOp,
    'Unchanged drag order returns no_op=true and makes zero database or audit_trails changes'
);

// Verify frontend shared module implements snapshot & restore on failed/cancelled drag
$sharedJs = (string) file_get_contents($root . '/frontend/assets/js/docket-drag-schedule.js');
runAssertion(
    str_contains($sharedJs, 'captureContainerSnapshot')
    && str_contains($sharedJs, 'restoreContainerSnapshot')
    && str_contains($sharedJs, 'currentOrderKey === initialOrderKey'),
    'Shared frontend module (docket-drag-schedule.js) snapshots DOM order, skips unchanged/cancelled drags, and restores DOM order on failure'
);

// =========================================================================
// [TEST 8] Filtered, Paginated, Multi-Day, Duplicate, Cross-Date & Incomplete Payloads
// =========================================================================
echo "\n[TEST 8] Verifying filtered, paginated, multi-day, duplicate, cross-date, and incomplete payloads are rejected...\n";

$filteredRes = $docketService->reorderQueue(
    [['id' => $hearingBId, 'type' => 'hearing']],
    $adminId,
    $docketTue,
    ['is_filtered' => true, 'role_id' => 1]
);
runAssertion($filteredRes['success'] === false && ($filteredRes['code'] ?? '') === 'FILTERED_VIEW_LOCKED', 'Filtered view reorder is rejected');

$paginatedRes = $docketService->reorderQueue(
    [['id' => $hearingBId, 'type' => 'hearing']],
    $adminId,
    $docketTue,
    ['is_paginated' => true, 'role_id' => 1]
);
runAssertion($paginatedRes['success'] === false && ($paginatedRes['code'] ?? '') === 'PAGINATED_VIEW_LOCKED', 'Paginated view reorder is rejected');

foreach (['weekly', 'monthly', 'yearly'] as $periodMode) {
    $periodRes = $docketService->reorderQueue(
        [['id' => $hearingBId, 'type' => 'hearing']],
        $adminId,
        $docketTue,
        ['view_mode' => $periodMode, 'full_day_loaded' => false, 'role_id' => 1]
    );
    runAssertion($periodRes['success'] === false && ($periodRes['code'] ?? '') === 'PERIOD_VIEW_LOCKED', "Multi-day view_mode '{$periodMode}' is rejected without full_day_loaded");
}

// Incomplete day payload (omitting Hearing A)
$incompleteRes = $docketService->reorderQueue(
    [
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId],
    ],
    $adminId,
    $docketTue,
    ['view_mode' => 'day', 'full_day_loaded' => true, 'role_id' => 1]
);
runAssertion($incompleteRes['success'] === false && ($incompleteRes['code'] ?? '') === 'INCOMPLETE_DOCKET_PAYLOAD', 'Incomplete day payload (missing unlocked item) is rejected to prevent partial queue corruption');

// Duplicate item in payload
$dupRes = $docketService->reorderQueue(
    [
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ],
    $adminId,
    $docketTue,
    ['view_mode' => 'day', 'full_day_loaded' => true, 'role_id' => 1]
);
runAssertion($dupRes['success'] === false && ($dupRes['code'] ?? '') === 'DUPLICATE_ITEM', 'Duplicate item in queue payload is rejected');

// Cross-date item ($h3Id is on $tuesdayDate, not $docketTue)
$crossDateRes = $docketService->reorderQueue(
    [
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId],
        ['id' => $hearingAId, 'type' => 'hearing'],
        ['id' => $h3Id, 'type' => 'hearing'],
    ],
    $adminId,
    $docketTue,
    ['view_mode' => 'day', 'full_day_loaded' => true, 'role_id' => 1]
);
runAssertion($crossDateRes['success'] === false && ($crossDateRes['code'] ?? '') === 'CROSS_DATE_REJECTED', 'Cross-date item in reorder_queue is rejected');

// =========================================================================
// [TEST 9] Concurrent / Stale Payload Rejection & Paanyaya Session Ownership
// =========================================================================
echo "\n[TEST 9] Verifying concurrent/stale request protection and Paanyaya session ownership checks...\n";

$staleUpdatedAtRes = $docketService->reorderQueue(
    [
        ['id' => $hearingBId, 'type' => 'hearing', 'expected_updated_at' => '1999-01-01 00:00:00'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ],
    $adminId,
    $docketTue,
    ['view_mode' => 'day', 'full_day_loaded' => true, 'role_id' => 1]
);
runAssertion($staleUpdatedAtRes['success'] === false && $staleUpdatedAtRes['http_code'] === 409 && ($staleUpdatedAtRes['code'] ?? '') === 'STALE_PAYLOAD', 'Stale expected_updated_at is rejected with HTTP 409');

$staleOrderRes = $docketService->reorderQueue(
    [
        ['id' => $hearingBId, 'type' => 'hearing', 'expected_queue_order' => 99],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ],
    $adminId,
    $docketTue,
    ['view_mode' => 'day', 'full_day_loaded' => true, 'role_id' => 1]
);
runAssertion($staleOrderRes['success'] === false && $staleOrderRes['http_code'] === 409 && ($staleOrderRes['code'] ?? '') === 'STALE_PAYLOAD', 'Stale expected_queue_order is rejected with HTTP 409');

$staleTimeRes = $docketService->reorderQueue(
    [
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compPId, 'expected_scheduled_time' => '08:00'],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ],
    $adminId,
    $docketTue,
    ['view_mode' => 'day', 'full_day_loaded' => true, 'role_id' => 1]
);
runAssertion($staleTimeRes['success'] === false && $staleTimeRes['http_code'] === 409 && ($staleTimeRes['code'] ?? '') === 'STALE_PAYLOAD', 'Stale Paanyaya expected_scheduled_time is rejected with HTTP 409');

// Mismatched complaint_id for Paanyaya
$mismatchCompRes = $docketService->reorderQueue(
    [
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $sessionPId, 'complaint_id' => $compAId],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ],
    $adminId,
    $docketTue,
    ['view_mode' => 'day', 'full_day_loaded' => true, 'role_id' => 1]
);
runAssertion($mismatchCompRes['success'] === false && ($mismatchCompRes['code'] ?? '') === 'PAANYAYA_COMPLAINT_MISMATCH', 'Mismatched complaint_id for Paanyaya is rejected');

// Create a foreign Paanyaya session belonging to compAId and verify PAANYAYA_SESSION_MISMATCH
$db->prepare("
    INSERT INTO paanyaya_sessions (complaint_id, invitee_name, attempt_number, scheduled_date, scheduled_time, venue, queue_status)
    VALUES (?, 'Foreign Session', 1, ?, '16:00:00', 'Lupon Court Room', 'Concluded')
")->execute([$compAId, $docketTue]);
$foreignSessionId = (int) $db->lastInsertId();

$mismatchSessRes = $docketService->reorderQueue(
    [
        ['id' => $hearingBId, 'type' => 'hearing'],
        ['id' => $paanyayaPId, 'type' => 'paanyaya', 'session_id' => $foreignSessionId, 'complaint_id' => $compPId],
        ['id' => $hearingAId, 'type' => 'hearing'],
    ],
    $adminId,
    $docketTue,
    ['view_mode' => 'day', 'full_day_loaded' => true, 'role_id' => 1]
);
runAssertion($mismatchSessRes['success'] === false && ($mismatchSessRes['code'] ?? '') === 'PAANYAYA_SESSION_MISMATCH', 'Foreign session_id not belonging to the submitted complaint and Paanyaya record is rejected');

// =========================================================================
// [TEST 10] Server-Side Eligibility & Locking Rules
// =========================================================================
echo "\n[TEST 10] Verifying started, attendance-recorded, concluded, cancelled, resolved, and superseded records are locked...\n";

// 10a. Hearing lock states
$db->prepare("UPDATE hearings SET queue_status = 'In_Session' WHERE hearing_id = ?")->execute([$hearingAId]);
$lockInSession = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockInSession['success'] === false && ($lockInSession['code'] ?? '') === 'IN_SESSION', 'In-session hearing is locked from rescheduling');

$db->prepare("UPDATE hearings SET queue_status = 'Waiting', complainant_attendance = 'Present' WHERE hearing_id = ?")->execute([$hearingAId]);
$lockAtt = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockAtt['success'] === false && ($lockAtt['code'] ?? '') === 'ATTENDANCE_RECORDED', 'Attendance-recorded hearing is locked');

$db->prepare("UPDATE hearings SET complainant_attendance = 'Pending', status = 'Cancelled' WHERE hearing_id = ?")->execute([$hearingAId]);
$lockCancelled = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockCancelled['success'] === false && ($lockCancelled['code'] ?? '') === 'CANCELLED', 'Cancelled hearing is locked');

$db->prepare("UPDATE hearings SET status = 'Completed', queue_status = 'Concluded' WHERE hearing_id = ?")->execute([$hearingAId]);
$lockConcluded = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockConcluded['success'] === false && ($lockConcluded['code'] ?? '') === 'CONCLUDED', 'Completed/concluded hearing is locked');

// Superseded hearing check: point Hearing B's rescheduled_from_id to Hearing A temporarily
$db->prepare("UPDATE hearings SET status = 'Scheduled', queue_status = 'Waiting' WHERE hearing_id = ?")->execute([$hearingAId]);
$db->prepare("UPDATE hearings SET rescheduled_from_id = ? WHERE hearing_id = ?")->execute([$hearingAId, $hearingBId]);
$lockSuperseded = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockSuperseded['success'] === false && ($lockSuperseded['code'] ?? '') === 'SUPERSEDED', 'Superseded hearing (referenced by rescheduled_from_id) is locked');
$db->prepare("UPDATE hearings SET rescheduled_from_id = NULL WHERE hearing_id = ?")->execute([$hearingBId]);

// Closed parent case check
$db->prepare("UPDATE cases SET case_status = 'Settled' WHERE case_id = ?")->execute([$caseAId]);
$lockClosedCase = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockClosedCase['success'] === false && ($lockClosedCase['code'] ?? '') === 'CASE_CLOSED', 'Hearing under a Settled/closed case is locked');
$db->prepare("UPDATE cases SET case_status = 'Mediation' WHERE case_id = ?")->execute([$caseAId]);

// 10b. Paanyaya lock states
$db->prepare("UPDATE paanyaya_sessions SET queue_status = 'In_Session' WHERE session_id = ?")->execute([$sessionPId]);
$lockPInSession = $docketService->rescheduleItem([
    'type' => 'paanyaya', 'id' => $paanyayaPId, 'session_id' => $sessionPId, 'complaint_id' => $compPId,
    'target_date' => $nextDocketTue, 'target_time' => '14:00', 'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockPInSession['success'] === false && ($lockPInSession['code'] ?? '') === 'IN_SESSION', 'In-session Paanyaya is locked');

$db->prepare("UPDATE paanyaya_sessions SET queue_status = 'Waiting' WHERE session_id = ?")->execute([$sessionPId]);
$db->prepare("UPDATE paanyayas SET attendance_recorded_at = NOW() WHERE paanyaya_id = ?")->execute([$paanyayaPId]);
$lockPAtt = $docketService->rescheduleItem([
    'type' => 'paanyaya', 'id' => $paanyayaPId, 'session_id' => $sessionPId, 'complaint_id' => $compPId,
    'target_date' => $nextDocketTue, 'target_time' => '14:00', 'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockPAtt['success'] === false && ($lockPAtt['code'] ?? '') === 'ATTENDANCE_RECORDED', 'Attendance-recorded Paanyaya is locked');
$db->prepare("UPDATE paanyayas SET attendance_recorded_at = NULL WHERE paanyaya_id = ?")->execute([$paanyayaPId]);

$db->prepare("UPDATE paanyayas SET status = 'Resolved' WHERE paanyaya_id = ?")->execute([$paanyayaPId]);
$lockPResolved = $docketService->rescheduleItem([
    'type' => 'paanyaya', 'id' => $paanyayaPId, 'session_id' => $sessionPId, 'complaint_id' => $compPId,
    'target_date' => $nextDocketTue, 'target_time' => '14:00', 'confirmed' => true, 'reschedule_reason' => 'Testing lock'
], $adminId);
runAssertion($lockPResolved['success'] === false && ($lockPResolved['code'] ?? '') === 'CONCLUDED', 'Resolved Paanyaya is locked');
$db->prepare("UPDATE paanyayas SET status = 'Scheduled' WHERE paanyaya_id = ?")->execute([$paanyayaPId]);

// =========================================================================
// [TEST 11] Dynamic Slot Discovery & Cross-Date Rescheduling Validation Rules
// =========================================================================
echo "\n[TEST 11] Verifying dynamic calendar slot discovery and cross-date scheduling validation rules...\n";

// 11a. Confirmation and reason required
$noConfirmRes = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '09:00',
    'confirmed' => false, 'reschedule_reason' => 'Valid reason text'
], $adminId);
runAssertion($noConfirmRes['success'] === false && ($noConfirmRes['code'] ?? '') === 'CONFIRMATION_REQUIRED', 'Reschedule without explicit confirmation is rejected');

$noReasonRes = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'no'
], $adminId);
runAssertion($noReasonRes['success'] === false && ($noReasonRes['code'] ?? '') === 'REASON_REQUIRED', 'Reschedule without a valid reason is rejected');

// 11b. Unchanged schedule rejected
$unchangedRes = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $docketTue, 'target_time' => '09:00',
    'venue' => 'Lupon Court Room', 'confirmed' => true, 'reschedule_reason' => 'Same date and time'
], $adminId);
runAssertion($unchangedRes['success'] === false && ($unchangedRes['code'] ?? '') === 'UNCHANGED_SCHEDULE', 'Reschedule to identical date, time, venue, and officer is rejected');

// 11c. Non-Tuesday Mediation day restriction in check_slots and reschedule_item
$nonTueDate = date('Y-m-d', strtotime($nextDocketTue . ' +1 day')); // Wednesday
$slotsWed = $docketService->getAvailableSlots(['type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nonTueDate], $adminId);
runAssertion($slotsWed['success'] === true && $slotsWed['date_valid'] === false, 'getAvailableSlots marks Wednesday invalid for Mediation with explanation: ' . ($slotsWed['date_reason'] ?? ''));

$reschedWed = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nonTueDate, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'Attempting Wednesday mediation'
], $adminId);
runAssertion($reschedWed['success'] === false && $reschedWed['http_code'] === 422, 'Cross-date reschedule of Mediation to Wednesday is rejected');

// 11d. Weekend restriction for Paanyaya
$saturdayDate = date('Y-m-d', strtotime($nextDocketTue . ' next Saturday'));
$slotsSat = $docketService->getAvailableSlots(['type' => 'paanyaya', 'id' => $paanyayaPId, 'target_date' => $saturdayDate], $adminId);
runAssertion($slotsSat['success'] === true && $slotsSat['date_valid'] === false, 'getAvailableSlots marks Saturday invalid for Paanyaya');

$reschedSat = $docketService->rescheduleItem([
    'type' => 'paanyaya', 'id' => $paanyayaPId, 'session_id' => $sessionPId, 'complaint_id' => $compPId,
    'target_date' => $saturdayDate, 'target_time' => '10:00', 'confirmed' => true, 'reschedule_reason' => 'Weekend test'
], $adminId);
runAssertion($reschedSat['success'] === false && ($reschedSat['code'] ?? '') === 'WEEKEND_BLOCKED', 'Cross-date reschedule of Paanyaya to Saturday is rejected');

// 11e. Holiday restriction
$holidayTue = nextWorkingTuesday($logistics, date('Y-m-d', strtotime($nextDocketTue . ' +14 days')));
$db->prepare("INSERT INTO scheduling_holidays (holiday_date, holiday_name, is_recurring) VALUES (?, 'Synthetic Barangay Charter Day', 0)")
    ->execute([$holidayTue]);
$createdHolidayDates[] = $holidayTue;

$slotsHoliday = $docketService->getAvailableSlots(['type' => 'hearing', 'id' => $hearingAId, 'target_date' => $holidayTue], $adminId);
runAssertion($slotsHoliday['success'] === true && $slotsHoliday['date_valid'] === false && str_contains(strtolower((string) ($slotsHoliday['date_reason'] ?? '')), 'holiday'), 'getAvailableSlots blocks configured holiday with explanation');

$reschedHoliday = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $holidayTue, 'target_time' => '09:00',
    'confirmed' => true, 'reschedule_reason' => 'Holiday test'
], $adminId);
runAssertion($reschedHoliday['success'] === false && $reschedHoliday['http_code'] === 422, 'Cross-date reschedule to configured holiday is rejected');

// 11f. Lunch break (12:00 PM) and outside working hours (07:00 AM) blocked
$reschedLunch = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $nextDocketTue, 'target_time' => '12:00',
    'confirmed' => true, 'reschedule_reason' => 'Lunch break test'
], $adminId);
runAssertion($reschedLunch['success'] === false && $reschedLunch['http_code'] === 422, 'Cross-date reschedule during lunch break (12:00 PM) is rejected');

// 11g. Valid slots on $docketTue show 10:30 AM (Hearing B) as unavailable due to presiding officer conflict
$slotsDocketTue = httpJsonRequest($baseUrl, $actors['clerk'], "hearings/queue.php?action=check_slots&type=hearing&id={$hearingAId}&target_date={$docketTue}&presiding_officer_id={$luponId}&venue=" . urlencode('Lupon Court Room'));
runAssertion($slotsDocketTue['status'] === 200 && ($slotsDocketTue['json']['date_valid'] ?? false) === true, 'HTTP check_slots returns valid slots for target Tuesday');
$slotMap = [];
foreach ($slotsDocketTue['json']['slots'] ?? [] as $sl) {
    $slotMap[$sl['time']] = $sl;
}
runAssertion(isset($slotMap['10:30']) && $slotMap['10:30']['available'] === false && !empty($slotMap['10:30']['reason']), 'Slot 10:30 AM on $docketTue is marked unavailable with conflict reason because Presiding Officer is already assigned to Hearing B');

// Attempting to reschedule Hearing A onto Hearing B's occupied 10:30 AM slot on $docketTue with the same officer fails with 409 conflict
$conflictResched = $docketService->rescheduleItem([
    'type' => 'hearing', 'id' => $hearingAId, 'target_date' => $docketTue, 'target_time' => '10:30',
    'venue' => 'Lupon Court Room', 'presiding_officer_id' => $luponId, 'confirmed' => true, 'reschedule_reason' => 'Testing officer overlap conflict'
], $adminId);
runAssertion($conflictResched['success'] === false && $conflictResched['http_code'] === 409, 'Rescheduling onto an occupied officer slot is rejected with HTTP 409 conflict');

// =========================================================================
// [TEST 12] Complete Cross-Date Rescheduling (Hearing & Served Paanyaya),
//           Notice Reconciliation, Resident Timeline, Audit & Notifications
// =========================================================================
echo "\n[TEST 12] Verifying complete cross-date rescheduling for Hearing and Served Paanyaya...\n";

// 12a. Formal Hearing Cross-Date Reschedule (Hearing A -> $nextDocketTue at 10:00 AM, Session Hall B, Presiding Officer $luponId)
$reschedHearingRes = httpJsonRequest($baseUrl, $actors['admin'], 'hearings/queue.php', [
    'action' => 'reschedule_item',
    'type' => 'hearing',
    'id' => $hearingAId,
    'target_date' => $nextDocketTue,
    'target_time' => '10:00',
    'venue' => 'Session Hall B',
    'presiding_officer_id' => $luponId,
    'reschedule_reason' => 'Presiding officer unavailable on initial Tuesday morning',
    'confirmed' => true
]);
runAssertion(
    $reschedHearingRes['status'] === 200 && ($reschedHearingRes['json']['success'] ?? false) === true,
    'Formal Hearing cross-date reschedule succeeded: ' . ($reschedHearingRes['json']['message'] ?? $reschedHearingRes['body'])
);

// Verify old_schedule and new_schedule in API response
$hOldSched = $reschedHearingRes['json']['old_schedule'] ?? [];
$hNewSched = $reschedHearingRes['json']['new_schedule'] ?? [];
runAssertion(
    ($hOldSched['date'] ?? '') === $docketTue && ($hOldSched['time'] ?? '') === '09:00' && (int) ($hOldSched['queue_order'] ?? 0) === 3
    && ($hNewSched['date'] ?? '') === $nextDocketTue && ($hNewSched['time'] ?? '') === '10:00'
    && ($hNewSched['venue'] ?? '') === 'Session Hall B' && (int) ($hNewSched['presiding_officer_id'] ?? 0) === $luponId,
    'Hearing reschedule response returns old and new date, time, queue_order, venue, and presiding officer'
);

// Verify hearings table updated
$updatedHA = $db->query("SELECT * FROM hearings WHERE hearing_id = {$hearingAId}")->fetch(PDO::FETCH_ASSOC);
runAssertion(
    $updatedHA['hearing_date'] === "{$nextDocketTue} 10:00:00"
    && $updatedHA['venue'] === 'Session Hall B'
    && (int) $updatedHA['presiding_officer_id'] === $luponId
    && $updatedHA['reschedule_reason'] === 'Presiding officer unavailable on initial Tuesday morning',
    'Authoritative hearings row updated with new date, time, venue, presiding_officer_id, and reschedule_reason'
);

// Verify old summon_deliveries superseded and new Pending delivery created
$oldDelivStatus = $db->query("SELECT delivery_status FROM summon_deliveries WHERE delivery_id = {$deliveryAId}")->fetchColumn();
$newDeliveries = $db->query("SELECT * FROM summon_deliveries WHERE hearing_id = {$hearingAId} AND delivery_id <> {$deliveryAId}")->fetchAll(PDO::FETCH_ASSOC);
runAssertion($oldDelivStatus === 'Superseded' && count($newDeliveries) === 3
    && count(array_unique(array_column($newDeliveries, 'document_id'))) === 3
    && array_unique(array_column($newDeliveries, 'delivery_status')) === ['Pending'],
    'Each recipient has a distinct pending notice on the rescheduled hearing');
$respondentNotice = array_values(array_filter($newDeliveries, fn ($row) => $row['party_type'] === 'Respondent'))[0];
$secondRespondentNotice = array_values(array_filter($newDeliveries, fn ($row) => (int) $row['resident_id'] === (int) $residents[2]))[0];
$noticeUrl = 'documents/kp-form.php?document_id=' . $respondentNotice['document_id'];
$preview = httpJsonRequest($baseUrl, $actors['clerk'], $noticeUrl . '&hearing_id=' . $hearingBId);
runAssertion($preview['status'] === 200 && str_contains($preview['body'], $respondentNotice['recipient_name'])
    && str_contains($preview['body'], 'Session Hall B') && str_contains($preview['body'], '10:00 AM')
    && str_contains($preview['body'], 'Open printable PDF'), 'Notice preview uses the issued schedule and exact recipient');
$pdf = httpJsonRequest($baseUrl, $actors['clerk'], $noticeUrl . '&pdf=1');
runAssertion($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-'), 'Printable replacement PDF opens through the document endpoint');
$return = [
    'action' => 'officer_return', 'hearing_id' => $hearingAId, 'case_id' => $caseAId,
    'party_type' => 'Respondent', 'delivery_status' => 'Served Personal',
    'recipient_name' => $respondentNotice['recipient_name'],
];
$oldReturn = httpJsonRequest($baseUrl, $actors['clerk'], 'gps/proof-service.php', $return + ['delivery_id' => $deliveryAId], true);
runAssertion($oldReturn['status'] === 422 && str_contains($oldReturn['body'], 'read-only'), 'Superseded return cannot be edited');
$wrongReturn = httpJsonRequest($baseUrl, $actors['clerk'], 'gps/proof-service.php', $return + [
    'delivery_id' => $respondentNotice['delivery_id'], 'resident_id' => $secondRespondentNotice['resident_id'],
], true);
runAssertion($wrongReturn['status'] === 422, 'Replacement return rejects a different recipient');
$savedReturn = httpJsonRequest($baseUrl, $actors['clerk'], 'gps/proof-service.php', $return + [
    'delivery_id' => $respondentNotice['delivery_id'], 'resident_id' => $respondentNotice['resident_id'],
], true);
$proofNotice = $db->prepare('SELECT document_id FROM proof_of_service WHERE proof_id = ?');
$proofNotice->execute([$savedReturn['json']['proof_id'] ?? 0]);
runAssertion($savedReturn['status'] === 200 && (int) $proofNotice->fetchColumn() === (int) $respondentNotice['document_id'],
    'Officer return records proof against the exact replacement document');
runAssertion($db->query('SELECT delivery_status FROM summon_deliveries WHERE delivery_id = ' . (int) $secondRespondentNotice['delivery_id'])->fetchColumn() === 'Pending',
    'Serving one respondent leaves the co-respondent’s replacement pending');

// Verify case_history and audit_trails contain old and new values
$caseHistRow = $db->query("SELECT remarks FROM case_history WHERE case_id = {$caseAId} ORDER BY history_id DESC LIMIT 1")->fetchColumn();
runAssertion(
    is_string($caseHistRow)
    && str_contains($caseHistRow, 'Session Hall B')
    && str_contains($caseHistRow, 'Presiding officer unavailable on initial Tuesday morning'),
    'case_history records old and new schedule details and reason'
);

$hearingAuditRow = $db->query("SELECT action FROM audit_trails WHERE module_name = 'Hearings'
    AND affected_record = {$hearingAId} AND action LIKE 'Rescheduled Hearing%' ORDER BY audit_id DESC LIMIT 1")->fetchColumn();
runAssertion(
    is_string($hearingAuditRow)
    && str_contains($hearingAuditRow, $docketTue)
    && str_contains($hearingAuditRow, $nextDocketTue)
    && str_contains($hearingAuditRow, 'Session Hall B'),
    'audit_trails records old and new date, time, order, venue, and reason for Hearing reschedule'
);

// Verify notifications sent for Hearing reschedule
$hearingNotifCount = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE case_id = {$caseAId} AND title LIKE 'Hearing Rescheduled:%'")->fetchColumn();
runAssertion($hearingNotifCount > 0, 'Notifications created for affected parties and staff on Hearing reschedule');

// Verify ResidentComplaintTimeline for Case A shows the new hearing appointment and excludes superseded ones
$timelineService = new ResidentComplaintTimeline($db);
$timelineA = $timelineService->find($compAId, $residentUserId);
$hearingEventsA = array_values(array_filter($timelineA['events'] ?? [], static fn(array $e): bool => str_contains($e['title'], 'appointment scheduled')));
$expectedHearingDetailA = date('M j, Y, g:i A', strtotime("{$nextDocketTue} 10:00:00")) . ' · Session Hall B';
runAssertion(
    count($hearingEventsA) === 1 && $hearingEventsA[0]['detail'] === $expectedHearingDetailA,
    'ResidentComplaintTimeline shows the updated formal hearing appointment (' . $expectedHearingDetailA . ') with no conflicting old entry'
);

// 12b. Served Paanyaya Cross-Date Reschedule (Paanyaya P -> $nextDocketTue at 02:30 PM, Barangay Conference Room, Presiding Officer $luponId)
$timelinePBefore = $timelineService->find($compPId, $residentUserId);
$paanyayaEventsBefore = array_values(array_filter($timelinePBefore['events'] ?? [], static fn(array $e): bool => $e['title'] === 'Paanyaya appointment confirmed'));
$expectedBeforeDetail = date('M j, Y, g:i A', strtotime("{$docketTue} 14:00:00")) . ' · Lupon Court Room';
runAssertion(
    count($paanyayaEventsBefore) === 1 && $paanyayaEventsBefore[0]['detail'] === $expectedBeforeDetail,
    'ResidentComplaintTimeline shows the original delivered Paanyaya appointment before rescheduling'
);

$reschedPaanyayaRes = httpJsonRequest($baseUrl, $actors['clerk'], 'hearings/queue.php', [
    'action' => 'reschedule_item',
    'type' => 'paanyaya',
    'id' => $paanyayaPId,
    'session_id' => $sessionPId,
    'complaint_id' => $compPId,
    'target_date' => $nextDocketTue,
    'target_time' => '14:30',
    'venue' => 'Barangay Conference Room',
    'presiding_officer_id' => $luponId,
    'reschedule_reason' => 'Both parties requested afternoon dialogue on next Tuesday',
    'confirmed' => true
]);
runAssertion(
    $reschedPaanyayaRes['status'] === 200 && ($reschedPaanyayaRes['json']['success'] ?? false) === true,
    'Served Paanyaya cross-date reschedule succeeded via Clerk HTTP request: ' . ($reschedPaanyayaRes['json']['message'] ?? $reschedPaanyayaRes['body'])
);

// Verify all 5 Paanyaya tables + complaint_paanyaya are reconciled
$updatedP = $db->query("SELECT * FROM paanyayas WHERE paanyaya_id = {$paanyayaPId}")->fetch(PDO::FETCH_ASSOC);
$updatedPS = $db->query("SELECT * FROM paanyaya_sessions WHERE session_id = {$sessionPId}")->fetch(PDO::FETCH_ASSOC);
$updatedStage = $db->query("SELECT * FROM paanyaya_stages WHERE complaint_id = {$compPId} AND stage_type = 'Paanyaya 1'")->fetch(PDO::FETCH_ASSOC);
$updatedPrep = $db->query("SELECT appointment_at, venue, document_pdf, document_version, ready_at FROM paanyaya_preparations WHERE complaint_id = {$compPId} AND invitation_number = 1")->fetch(PDO::FETCH_ASSOC);
$updatedAtt = $db->query("SELECT * FROM paanyaya_service_attempts WHERE attempt_id = {$attemptPId}")->fetch(PDO::FETCH_ASSOC);
$updatedLegacy = $db->query("SELECT invitation_date, invitation_time, venue FROM complaint_paanyaya WHERE complaint_id = {$compPId}")->fetch(PDO::FETCH_ASSOC);

runAssertion(
    $updatedP['scheduled_date'] === $nextDocketTue
    && $updatedP['scheduled_time'] === '14:30:00'
    && $updatedP['venue'] === 'Barangay Conference Room'
    && (int) $updatedP['queue_order'] === 2,
    '1/6: paanyayas table updated with new date, time, venue, and queue_order'
);
runAssertion(
    $updatedPS['scheduled_date'] === $nextDocketTue
    && $updatedPS['scheduled_time'] === '14:30:00'
    && $updatedPS['venue'] === 'Barangay Conference Room'
    && (int) $updatedPS['queue_order'] === 2
    && str_contains((string) $updatedPS['presiding_officer_ids'], (string) $luponId),
    '2/6: paanyaya_sessions table reconciled with paanyayas'
);
runAssertion(
    $updatedStage['invitation_date'] === $nextDocketTue
    && $updatedStage['invitation_time'] === '14:30:00'
    && $updatedStage['venue'] === 'Barangay Conference Room',
    '3/6: paanyaya_stages table reconciled with new schedule'
);
runAssertion(
    $updatedPrep['appointment_at'] === "{$nextDocketTue} 14:30:00"
    && $updatedPrep['venue'] === 'Barangay Conference Room'
    && str_starts_with((string) $updatedPrep['document_pdf'], '%PDF-')
    && $updatedPrep['document_version'] !== $origDocVersionP && $updatedPrep['ready_at'] === null,
    '4/6: paanyaya_preparations appointment_at, venue, and regenerated PDF binary updated'
);
runAssertion(
    $updatedAtt === $origAtt && $deliveryService->invitationPdf($attemptPId) === $origPdf,
    '5/6: prior delivery result, evidence, and original PDF are preserved'
);
runAssertion(
    $updatedLegacy['invitation_date'] === $nextDocketTue
    && $updatedLegacy['invitation_time'] === '14:30:00'
    && $updatedLegacy['venue'] === 'Barangay Conference Room',
    '6/6: complaint_paanyaya table reconciled with new schedule'
);

// The new appointment stays unconfirmed until the revised invitation is delivered.
$timelinePAfter = $timelineService->find($compPId, $residentUserId);
$paanyayaEventsAfter = array_values(array_filter($timelinePAfter['events'] ?? [], static fn(array $e): bool => $e['title'] === 'Paanyaya appointment confirmed'));
runAssertion($paanyayaEventsAfter === [] && PaanyayaServiceDelivery::latestAttempt($db, $compPId) === null,
    'Old delivery cannot confirm a revised appointment');
$checkIn = $hearingCtrl->checkInParty(['session_source' => 'paanyaya', 'hearing_id' => $sessionPId, 'party_type' => 'Complainant'], $clerkId);
runAssertion(!$checkIn['success'] && str_contains($checkIn['message'], 'current invitation'), 'Dialogue check-in waits for re-service');
$minutesInput = [
    'dialogue_date' => date('Y-m-d\TH:i'), 'document_version' => $origDocVersionP,
    'complainant_attended' => 'Present', 'respondent_attended' => 'Present',
    'outcome' => 'Escalate_Mediation', 'discussion_summary' => 'Synthetic dialogue notes',
];
$paanyayaModel = new Paanyaya($db);
$blockedDialogue = $paanyayaModel->recordMinutes($compPId, 'Paanyaya 1', $minutesInput, $clerkId);
runAssertion(!$blockedDialogue['success'] && str_contains($blockedDialogue['message'], 'Confirm delivery'),
    'Dialogue minutes cannot be saved before the revised invitation is served');
$version = $updatedPrep['document_version'];
$prepService->save($compPId, $clerkId, 'ready', ['document_version' => $version], 1);
try {
    $deliveryService->record($attemptPId, $assignedServerId, ['outcome' => 'Not Delivered', 'notes' => 'Stale result'], []);
    throw new RuntimeException('Superseded attempt accepted a new result');
} catch (DomainException $exception) {
    runAssertion(str_contains($exception->getMessage(), 'superseded'), 'Superseded Paanyaya attempt is read-only');
}
$deliveryService->release($compPId, $clerkId, $assignedServerId, $version);
$revisedAttempt = PaanyayaServiceDelivery::latestAttempt($db, $compPId);
$deliveryService->record((int) $revisedAttempt['attempt_id'], $assignedServerId, ['outcome' => 'Not Delivered', 'notes' => 'Recipient unavailable'], []);
$deliveryService->release($compPId, $clerkId, $assignedServerId, $version);
$retry = PaanyayaServiceDelivery::latestAttempt($db, $compPId);
$deliveryService->record((int) $retry['attempt_id'], $assignedServerId, ['outcome' => 'Delivered'], []);
$staleMinutes = $paanyayaModel->recordMinutes($compPId, 'Paanyaya 1', $minutesInput, $clerkId);
runAssertion(!$staleMinutes['success'] && str_contains($staleMinutes['message'], 'invitation changed'),
    'An old dialogue form remains stale after successful re-service');
$timelinePAfter = $timelineService->find($compPId, $residentUserId);
$paanyayaEventsAfter = array_values(array_filter($timelinePAfter['events'] ?? [], static fn(array $e): bool => $e['title'] === 'Paanyaya appointment confirmed'));
runAssertion((int) $db->query("SELECT COUNT(*) FROM paanyaya_sessions WHERE complaint_id = {$compPId}")->fetchColumn() === 1,
    'Re-service reopens the same Paanyaya session');
$expectedAfterDetail = date('M j, Y, g:i A', strtotime("{$nextDocketTue} 14:30:00")) . ' · Barangay Conference Room';
runAssertion(
    count($paanyayaEventsAfter) === 1 && $paanyayaEventsAfter[0]['detail'] === $expectedAfterDetail,
    'ResidentComplaintTimeline reflects the rescheduled Paanyaya appointment (' . $expectedAfterDetail . ') without stale dates'
);

// Verify Paanyaya audit trail and notifications
$paanyayaAuditRow = $db->query("SELECT action FROM audit_trails WHERE module_name = 'Paanyaya'
    AND affected_record = {$compPId} AND action LIKE 'Rescheduled Paanyaya%' ORDER BY audit_id DESC LIMIT 1")->fetchColumn();
runAssertion(
    is_string($paanyayaAuditRow)
    && str_contains($paanyayaAuditRow, $docketTue)
    && str_contains($paanyayaAuditRow, $nextDocketTue)
    && str_contains($paanyayaAuditRow, 'Barangay Conference Room'),
    'audit_trails records old and new schedule details for Paanyaya reschedule'
);

$residentNotif = $db->query("SELECT message FROM notifications WHERE user_id = {$residentUserId} AND title LIKE 'Paanyaya Rescheduled:%' ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
runAssertion(
    is_string($residentNotif)
    && str_contains($residentNotif, 'Barangay Conference Room')
    && str_contains($residentNotif, 'Both parties requested afternoon dialogue on next Tuesday'),
    'Resident received notification with old and new Paanyaya schedule and reschedule reason'
);

// Clean up all test data
$cleanup->cleanup();

echo "\n====================================================================\n";
echo "   ALL SCHEDULING & DOCKET DRAG-AND-DROP TESTS PASSED! (100%)\n";
echo "====================================================================\n";
