<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/CFA.php';
require_once __DIR__ . '/../models/CaseModel.php';
require_once __DIR__ . '/../models/CaseStage.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/HearingAttendance.php';
require_once __DIR__ . '/../models/HearingWorkflow.php';
require_once __DIR__ . '/../controllers/HearingController.php';
require_once __DIR__ . '/../services/KpFormService.php';
require_once __DIR__ . '/../services/ShowCauseService.php';
require_once __DIR__ . '/../services/SummonDeliveryService.php';
require_once __DIR__ . '/../services/SummonServiceGate.php';
require_once __DIR__ . '/../services/OfficeLogisticsService.php';
require_once __DIR__ . '/../services/DocketScheduleService.php';
require_once __DIR__ . '/TestDataCleanup.php';

$token = bin2hex(random_bytes(6));
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agap-reg-' . $token;
mkdir($sessionDir);
ini_set('session.save_path', $sessionDir);
ini_set('session.use_cookies', '0');

// Spawn built-in HTTP server BEFORE calling Database::connect() in parent
// so putenv("DB_PASS=") in parent does not propagate to child php -S requests.
$process = null;
$logPath = $sessionDir . DIRECTORY_SEPARATOR . 'server.log';
$createdFiles = [];
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$root = dirname(__DIR__, 2);
$process = proc_open(
    [PHP_BINARY, '-d', 'error_reporting=E_ALL&~E_DEPRECATED', '-d', "session.save_path={$sessionDir}", '-S', $address, '-t', $root],
    [1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
    $pipes,
    $root
);
for ($i = 0; $i < 30; $i++) {
    $conn = @stream_socket_client("tcp://{$address}", $ec, $es, 0.1);
    if ($conn) {
        fclose($conn);
        break;
    }
    usleep(100000);
}
$baseUrl = "http://{$address}";

$db = (new Database())->connect();
$clerkRow = $db->query("SELECT u.user_id, u.role_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name IN ('Administrator', 'Lupon Clerk') AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$clerkId = $clerkRow ? (int) $clerkRow['user_id'] : 1;
$serverRow = $db->query("SELECT u.user_id, u.role_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Summons Server' AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$serverId = $serverRow ? (int) $serverRow['user_id'] : $clerkId;

$clerkSession = [
    'id' => "{$token}-clerk",
    'csrf_token' => bin2hex(random_bytes(32)),
    'user_id' => $clerkId,
    'role_id' => $clerkRow ? (int) $clerkRow['role_id'] : 1,
    'role_name' => $clerkRow ? $clerkRow['role_name'] : 'Administrator',
];
session_id($clerkSession['id']);
session_start();
$_SESSION = [
    'user_id' => $clerkSession['user_id'],
    'role_id' => $clerkSession['role_id'],
    'role_name' => $clerkSession['role_name'],
    'csrf_token' => $clerkSession['csrf_token'],
];
session_write_close();

// Set CLI session too for controller methods reading $_SESSION
$_SESSION = [
    'user_id' => $clerkId,
    'role_id' => $clerkSession['role_id'],
    'role_name' => $clerkSession['role_name'],
];

echo "====================================================================\n";
echo "   REGRESSIONS: CFA ELIGIBILITY, ATTENDANCE & SERVICE VERIFICATION  \n";
echo "====================================================================\n";

$passCount = 0;
$failCount = 0;

function assertReg(bool $cond, string $message): void
{
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$message}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failCount++;
    }
}

function nextWeekdayDate(OfficeLogisticsService $logistics, int $daysAhead = 1, ?int $targetDow = null): string
{
    $curr = new DateTime('now');
    $moved = 0;
    for ($i = 0; $i < 45; $i++) {
        $curr->modify('+1 day');
        $dow = (int) $curr->format('w');
        if ($dow === 0 || $dow === 6) {
            continue;
        }
        if ($logistics->isPhilippineHoliday($curr) !== null) {
            continue;
        }
        $moved++;
        if ($targetDow !== null) {
            if ($dow === $targetDow && $moved >= $daysAhead) {
                return $curr->format('Y-m-d');
            }
        } elseif ($moved >= $daysAhead) {
            return $curr->format('Y-m-d');
        }
    }
    return $curr->format('Y-m-d');
}

function regHttpRequest(string $base, ?array $session, string $route, ?array $data = null, bool $form = false): array
{
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
    if ($data !== null) {
        $options['method'] = 'POST';
        $options['header'][] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
        $options['content'] = $form ? http_build_query($data) : json_encode($data, JSON_THROW_ON_ERROR);
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
    $status = !empty($wrapperData[0]) ? (int) explode(' ', (string) $wrapperData[0])[1] : 0;
    return [
        'status' => $status,
        'body' => $body,
        'json' => json_decode((string) $body, true),
    ];
}

$cleanup = new TestDataCleanup($db);
$logistics = new OfficeLogisticsService($db);

register_shutdown_function(static function () use (&$process, $sessionDir, &$createdFiles): void {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach ($createdFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($sessionDir);
});

assertReg(!empty($clerkRow), 'Found active Administrator/Lupon Clerk test actor');

$residents = $db->query("SELECT resident_id FROM residents ORDER BY resident_id ASC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
while (count($residents) < 4) {
    $idx = count($residents) + 1;
    $db->prepare("INSERT INTO residents (first_name, last_name, barangay) VALUES (?, ?, 'Tumana')")
        ->execute(["RegRes{$idx}", $token]);
    $residents[] = (int) $db->lastInsertId();
}
$resComp = (int) $residents[0];
$resResp1 = (int) $residents[1];
$resResp2 = (int) $residents[2];

$catId = (int) ($db->query("SELECT category_id FROM complaint_categories ORDER BY category_id ASC LIMIT 1")->fetchColumn() ?: 1);

$summonService = new SummonDeliveryService($db);
$attModel = new HearingAttendance($db);
$showCauseService = new ShowCauseService($db);
$workflowModel = new HearingWorkflow($db);
$hearingCtrl = new HearingController($db);
$docketService = new DocketScheduleService($db);
$cfaModel = new CFA($db);
$kpService = new KpFormService($db);

$tue1 = nextWeekdayDate($logistics, 1, 2);
$tue2 = date('Y-m-d', strtotime($tue1 . ' +7 days'));
if ($logistics->isPhilippineHoliday($tue2) !== null) {
    $tue2 = date('Y-m-d', strtotime($tue2 . ' +7 days'));
}
$tue3 = date('Y-m-d', strtotime($tue2 . ' +7 days'));
if ($logistics->isPhilippineHoliday($tue3) !== null) {
    $tue3 = date('Y-m-d', strtotime($tue3 . ' +7 days'));
}

// =========================================================================
// SCENARIO 1 & 2: Two Respondents + Rescheduled Hearing + Replacement Retry
// =========================================================================
echo "\n--- SCENARIO 1 & 2: Rescheduled Hearing (2 Respondents), Service Verification & Retry ---\n";

$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test Multi-Resp Reschedule', 'Mediation', ?, 'Civil', CURDATE(), 'Two respondents test', ?, NOW())
")->execute(["CMP-REG1-{$token}", $catId, $clerkId]);
$comp1 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp1);

$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$comp1, $resComp, $comp1, $resResp1, $comp1, $resResp2]);

$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp1, "REG-001-{$token}"]);
$case1 = (int) $db->lastInsertId();

// Create Hearing 1 (Tuesday 14:00) + formal docket reschedule (Tuesday 15:00) -> issues replacement notices on same hearing_id
$h1Res = $hearingCtrl->create([
    'case_id' => $case1,
    'hearing_type' => 'Mediation',
    'hearing_date' => $tue1,
    'hearing_time' => '14:00',
    'venue' => 'Lupon Hall',
    'schedule_reviewed' => '1',
], $clerkId);
assertReg(!empty($h1Res['success']), 'Created 1st Mediation hearing');
$h1Id = (int) $h1Res['hearing_id'];

$reschedRes = $docketService->rescheduleItem([
    'item_type' => 'hearing',
    'id' => $h1Id,
    'target_date' => $tue2,
    'target_time' => '15:00',
    'reason' => 'Formal docket reschedule due to Barangay scheduling adjustment',
    'confirmed' => true,
], $clerkId, $clerkSession['role_id']);
assertReg(!empty($reschedRes['success']), 'Rescheduled hearing created replacement notices: ' . ($reschedRes['message'] ?? ''));
$h2Id = $h1Id;

// Verify Hearing 1 initial delivery marked Superseded
$supCount = (int) $db->query("SELECT COUNT(*) FROM summon_deliveries WHERE hearing_id = {$h1Id} AND delivery_status = 'Superseded'")->fetchColumn();
assertReg($supCount >= 1, "Superseded prior Hearing 1 delivery marked as 'Superseded'");
$supDelRow = $db->query("SELECT delivery_id, party_type, resident_id, document_id FROM summon_deliveries WHERE hearing_id = {$h1Id} AND delivery_status = 'Superseded' ORDER BY delivery_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$supDelId = (int) ($supDelRow['delivery_id'] ?? 0);

// Check replacement_notices has separate entries for resComp, resResp1, resResp2
$replRowsAll = $summonService->replacementNotices($h2Id);
$replRows = array_values(array_filter($replRowsAll, static fn($r) => empty($r['is_superseded'])));
assertReg(count($replRows) === 3, 'Rescheduled Hearing issued 3 active separate recipient notices (1 Complainant, 2 Respondents)');

$delComp = null;
$delResp1 = null;
$delResp2 = null;
foreach ($replRows as $row) {
    $rid = (int) $row['resident_id'];
    if ($rid === $resComp && $row['party_type'] === 'Complainant') $delComp = $row;
    if ($rid === $resResp1 && $row['party_type'] === 'Respondent') $delResp1 = $row;
    if ($rid === $resResp2 && $row['party_type'] === 'Respondent') $delResp2 = $row;
}
assertReg($delComp !== null && $delResp1 !== null && $delResp2 !== null, 'All 3 recipients have dedicated operative notices on Hearing 2');
assertReg((int) $delResp1['document_id'] !== (int) $delResp2['document_id'], 'Each respondent has a distinct replacement document_id');

// Verify stale return against Superseded Hearing 1 delivery is rejected
$staleSupSave = $summonService->recordDelivery($h1Id, [
    'case_id' => $case1,
    'hearing_id' => $h1Id,
    'delivery_id' => $supDelId,
    'document_id' => $supDelRow['document_id'] ?: null,
    'party_type' => $supDelRow['party_type'],
    'resident_id' => $supDelRow['resident_id'] ?: null,
    'delivery_status' => 'Served Personal',
    'served_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
    'recipient_name' => 'Superseded Test',
], $serverId);
assertReg(empty($staleSupSave['success']) && stripos($staleSupSave['message'] ?? '', 'superseded') !== false, 'Stale submission against superseded Hearing 1 notice is rejected (' . ($staleSupSave['message'] ?? '') . ')');

// Serve Complainant and Respondent 1 ONLY on Hearing 2; leave Respondent 2 Pending
$saveComp = $summonService->recordDelivery($h2Id, [
    'case_id' => $case1,
    'hearing_id' => $h2Id,
    'delivery_id' => $delComp['delivery_id'],
    'document_id' => $delComp['document_id'],
    'party_type' => 'Complainant',
    'resident_id' => $resComp,
    'delivery_status' => 'Served Personal',
    'served_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
    'recipient_name' => 'Complainant Test',
], $serverId);
assertReg(!empty($saveComp['success']), 'Served Complainant operative replacement notice');

$saveR1 = $summonService->recordDelivery($h2Id, [
    'case_id' => $case1,
    'hearing_id' => $h2Id,
    'delivery_id' => $delResp1['delivery_id'],
    'document_id' => $delResp1['document_id'],
    'party_type' => 'Respondent',
    'resident_id' => $resResp1,
    'delivery_status' => 'Served Personal',
    'served_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
    'recipient_name' => 'Respondent 1 Test',
], $serverId);
assertReg(!empty($saveR1['success']), 'Served Respondent 1 operative replacement notice');

// Verify Respondent 1 is verified, Respondent 2 is NOT verified
$verR1 = $summonService->verifyRecipientServiceForHearing($h2Id, $resResp1, 'Respondent');
$verR2 = $summonService->verifyRecipientServiceForHearing($h2Id, $resResp2, 'Respondent');
assertReg(!empty($verR1['verified']), 'Respondent 1 service verification passes');
assertReg(empty($verR2['verified']) && $verR2['status'] === 'Pending Verification', 'Respondent 2 remains unverified (Pending Verification) even after Respondent 1 is served');

// Verify checkServiceStatus shows Respondent 2 waiting for service
$gateStatus = $summonService->checkServiceStatus($h2Id);
$waitingIds = array_map(static fn($w) => (int) $w['resident_id'], $gateStatus['waiting_parties'] ?? []);
assertReg(in_array($resResp2, $waitingIds, true) && !in_array($resResp1, $waitingIds, true), 'checkServiceStatus lists only Respondent 2 in waiting_parties');

// Attempt to mark Respondent 2 as Absent across ALL attendance & show-cause entry points
$attBlocked = $attModel->recordAttendance($h2Id, [
    ['resident_id' => $resComp, 'attendance_status' => 'Present', 'is_justified' => 0],
    ['resident_id' => $resResp1, 'attendance_status' => 'Present', 'is_justified' => 0],
    ['resident_id' => $resResp2, 'attendance_status' => 'Absent', 'is_justified' => 0],
], $clerkId);
assertReg(empty($attBlocked['success']), 'HearingAttendance rejects Absent for unserved Respondent 2');

$scBlocked = $showCauseService->recordAttendance($h2Id, [
    'parties' => [
        $resComp => ['status' => 'Present', 'is_justified' => 0],
        $resResp1 => ['status' => 'Present', 'is_justified' => 0],
        $resResp2 => ['status' => 'Absent', 'is_justified' => 0],
    ],
], $clerkId);
assertReg(empty($scBlocked['success']), 'ShowCauseService::recordAttendance rejects Absent for unserved Respondent 2');

$checkInBlocked = $hearingCtrl->checkInParty([
    'hearing_id' => $h2Id,
    'party_type' => 'Respondent',
    'resident_id' => $resResp2,
    'status' => 'Absent',
], $clerkId);
assertReg(empty($checkInBlocked['success']), 'HearingController::checkInParty rejects Absent for unserved Respondent 2');

$httpAttBlocked = regHttpRequest($baseUrl, $clerkSession, 'hearings/attendance.php', [
    'hearing_id' => $h2Id,
    'records' => [
        ['resident_id' => $resComp, 'attendance_status' => 'Present', 'is_justified' => 0],
        ['resident_id' => $resResp1, 'attendance_status' => 'Present', 'is_justified' => 0],
        ['resident_id' => $resResp2, 'attendance_status' => 'Absent', 'is_justified' => 0],
    ],
]);
assertReg(in_array($httpAttBlocked['status'], [409, 422], true) && empty($httpAttBlocked['json']['success']), 'HTTP POST attendance.php rejects marking unserved Respondent 2 Absent (status=' . $httpAttBlocked['status'] . ')');

$wfNoticeBlocked = $workflowModel->act('issue_notice', [
    'hearing_id' => $h2Id,
    'resident_id' => $resResp2,
    'explanation_date' => "{$tue3} 09:00:00",
    'assigned_server_id' => $serverId,
], $clerkId, $clerkSession['role_id']);
assertReg(empty($wfNoticeBlocked['success']), 'HearingWorkflow blocks issuing KP Form 19 show-cause notice for unserved Respondent 2');

$wfBarBlocked = $workflowModel->act('legal_action', [
    'hearing_id' => $h2Id,
    'resident_id' => $resResp2,
    'action_taken' => 'Request Contempt / Bar Execution',
], $clerkId, $clerkSession['role_id']);
assertReg(empty($wfBarBlocked['success']), 'HearingWorkflow blocks bar/default execution without documented show-cause determination');

// Mark Respondent 2 as Pending Verification (valid) and verify status preserved distinct from Absent
$attPendingVer = $attModel->recordAttendance($h2Id, [
    ['resident_id' => $resComp, 'attendance_status' => 'Present', 'is_justified' => 0],
    ['resident_id' => $resResp1, 'attendance_status' => 'Present', 'is_justified' => 0],
    ['resident_id' => $resResp2, 'attendance_status' => 'Pending Verification', 'is_justified' => 0],
], $clerkId);
assertReg(!empty($attPendingVer['success']), 'HearingAttendance allows distinct Pending Verification status for Respondent 2');
$r2AttStatus = $db->query("SELECT attendance_status FROM hearing_attendance WHERE hearing_id = {$h2Id} AND resident_id = {$resResp2}")->fetchColumn();
assertReg($r2AttStatus === 'Pending Verification', "Respondent 2 stored as 'Pending Verification' (not 'Absent')");

// Scenario 2: Record FAILED replacement delivery for Respondent 2 with proof path, then RETRY
$proofDirRel = "storage/summons-proofs/reg-test-{$token}";
$proofDirAbs = dirname(__DIR__, 2) . "/{$proofDirRel}";
@mkdir($proofDirAbs, 0775, true);
$proofRel = "{$proofDirRel}/failed_attempt_1.txt";
$proofAbs = dirname(__DIR__, 2) . "/{$proofRel}";
file_put_contents($proofAbs, 'proof-1');
$createdFiles[] = $proofAbs;

// Save Unserved on Respondent 2's operative notice
$saveFailedR2 = $summonService->recordDelivery($h2Id, [
    'case_id' => $case1,
    'hearing_id' => $h2Id,
    'delivery_id' => $delResp2['delivery_id'],
    'document_id' => $delResp2['document_id'],
    'party_type' => 'Respondent',
    'resident_id' => $resResp2,
    'delivery_status' => 'Unserved',
    'unserved_reason' => 'No One Home',
    'failure_notes' => 'No one home on attempt 1',
    'proof_file_path' => $proofRel,
], $serverId);
assertReg(!empty($saveFailedR2['success']), 'Recorded failed Unserved return on Respondent 2 replacement notice (' . ($saveFailedR2['message'] ?? '') . ')');
$db->prepare("UPDATE summon_deliveries SET proof_file_path = ? WHERE delivery_id = ?")
    ->execute([$proofRel, (int) $delResp2['delivery_id']]);

// Verify verification fails with Unserved after failed return
$verR2AfterUnserved = $summonService->verifyRecipientServiceForHearing($h2Id, $resResp2, 'Respondent');
assertReg(empty($verR2AfterUnserved['verified']) && in_array($verR2AfterUnserved['status'], ['Unserved', 'Not Served'], true), "Verification classifies Respondent 2 as unserved ('{$verR2AfterUnserved['status']}') after failed return");

// Verify replacementNotices exposes can_retry = true + failed proof preserved
$replAfterFail = $summonService->replacementNotices($h2Id);
$r2RowFail = null;
foreach ($replAfterFail as $row) {
    if ((int) $row['delivery_id'] === (int) $delResp2['delivery_id']) {
        $r2RowFail = $row;
    }
}
assertReg($r2RowFail && !empty($r2RowFail['can_retry']) && $r2RowFail['proof_file_path'] === $proofRel, 'Failed replacement notice offers Retry and preserves failed proof_file_path');

// Trigger Retry via HTTP proof-service.php (action=next_attempt)
$retryHttp = regHttpRequest($baseUrl, $clerkSession, 'gps/proof-service.php', [
    'action' => 'next_attempt',
    'case_id' => $case1,
    'hearing_id' => $h2Id,
    'party_type' => 'Respondent',
    'resident_id' => $resResp2,
    'delivery_id' => $delResp2['delivery_id'],
], true);
$retryDiag = $retryHttp['status'] . ': ' . ($retryHttp['json']['message'] ?? substr((string) $retryHttp['body'], 0, 120));
assertReg($retryHttp['status'] === 200 && !empty($retryHttp['json']['success']), "HTTP proof-service.php next_attempt retry succeeded for unserved replacement notice ({$retryDiag})");
$retryDelId = (int) ($retryHttp['json']['delivery_id'] ?? 0);
assertReg($retryDelId > 0 && $retryDelId !== (int) $delResp2['delivery_id'], 'Retry created new delivery row linked to Respondent 2');

// Check old failed return + proof preserved AND linked to same hearing, recipient, document_id
$oldRowDb = $db->query("SELECT delivery_status, proof_file_path, document_id, resident_id, hearing_id FROM summon_deliveries WHERE delivery_id = " . (int) $delResp2['delivery_id'])->fetch(PDO::FETCH_ASSOC);
$newRowDb = $db->query("SELECT delivery_status, attempt_number, document_id, resident_id, hearing_id FROM summon_deliveries WHERE delivery_id = {$retryDelId}")->fetch(PDO::FETCH_ASSOC);
assertReg($oldRowDb['delivery_status'] === 'Unserved' && $oldRowDb['proof_file_path'] === $proofRel, 'Original failed return and proof remain preserved in database');
assertReg(
    (int) $newRowDb['hearing_id'] === $h2Id
    && (int) $newRowDb['resident_id'] === $resResp2
    && (int) $newRowDb['document_id'] === (int) $delResp2['document_id']
    && (int) $newRowDb['attempt_number'] === 2,
    'Retry row stays linked to same hearing, recipient, and replacement document_id with attempt_number = 2'
);

// Reject stale edit on already-retried Unserved delivery row
$staleOldRetrySave = $summonService->recordDelivery($h2Id, [
    'case_id' => $case1,
    'hearing_id' => $h2Id,
    'delivery_id' => $delResp2['delivery_id'],
    'document_id' => $delResp2['document_id'],
    'party_type' => 'Respondent',
    'resident_id' => $resResp2,
    'delivery_status' => 'Served Personal',
    'served_at' => date('Y-m-d H:i:s'),
    'recipient_name' => 'Stale Save Attempt',
], $serverId);
assertReg(empty($staleOldRetrySave['success']), 'Rejected return submission against superseded/already-retried replacement attempt');

// Successful retry check: serve retry #2 for Respondent 2 on Hearing 2 -> service verified and Absent now allowed
$serveRetryOk = $summonService->recordDelivery($h2Id, [
    'case_id' => $case1,
    'hearing_id' => $h2Id,
    'delivery_id' => $retryDelId,
    'document_id' => $delResp2['document_id'],
    'party_type' => 'Respondent',
    'resident_id' => $resResp2,
    'delivery_status' => 'Served Personal',
    'served_at' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
    'recipient_name' => 'Respondent 2 Retry Served',
], $serverId);
assertReg(!empty($serveRetryOk['success']), 'Successful retry delivery saved on same hearing, recipient, and notice document');
$verR2AfterRetry = $summonService->verifyRecipientServiceForHearing($h2Id, $resResp2, 'Respondent');
assertReg(!empty($verR2AfterRetry['verified']), 'Respondent 2 service becomes verified after successful retry');
$gateAfterRetry = $summonService->checkServiceStatus($h2Id);
assertReg(empty($gateAfterRetry['waiting_parties']), 'No waiting parties remain after Respondent 2 retry succeeds');
$attAbsentAllowedAfterRetry = $attModel->recordAttendance($h2Id, [
    ['resident_id' => $resComp, 'attendance_status' => 'Present', 'is_justified' => 0],
    ['resident_id' => $resResp1, 'attendance_status' => 'Present', 'is_justified' => 0],
    ['resident_id' => $resResp2, 'attendance_status' => 'Absent', 'is_justified' => 0],
], $clerkId);
assertReg(!empty($attAbsentAllowedAfterRetry['success']), 'Absent is allowed once Respondent 2 retry succeeds');

// Attempt limit check on a fresh rescheduled hearing for Respondent 2 (summons_max_attempts = 6)
$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test Limit Check', 'Mediation', ?, 'Civil', CURDATE(), 'Attempt limit check', ?, NOW())
")->execute(["CMP-REGLIM-{$token}", $catId, $clerkId]);
$compLim = (int) $db->lastInsertId();
$cleanup->trackComplaint($compLim);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$compLim, $resComp, $compLim, $resResp2]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$compLim, "REG-LIM-{$token}"]);
$caseLim = (int) $db->lastInsertId();
$hLimCreate = $hearingCtrl->create([
    'case_id' => $caseLim,
    'hearing_type' => 'Mediation',
    'hearing_date' => $tue2,
    'hearing_time' => '16:00',
    'venue' => 'Lupon Hall',
    'schedule_reviewed' => '1',
], $clerkId);
$h3Id = (int) ($hLimCreate['hearing_id'] ?? 0);
$limResched = $docketService->rescheduleItem([
    'item_type' => 'hearing',
    'id' => $h3Id,
    'target_date' => $tue3,
    'target_time' => '14:00',
    'reason' => 'Limit Test Reschedule',
    'confirmed' => true,
], $clerkId, $clerkSession['role_id']);
assertReg(!empty($limResched['success']), 'Limit test hearing rescheduled cleanly');
$h3Repl = $summonService->replacementNotices($h3Id);
$h3R2 = null;
foreach ($h3Repl as $row) {
    if (empty($row['is_superseded']) && (int) $row['resident_id'] === $resResp2 && $row['party_type'] === 'Respondent') {
        $h3R2 = $row;
    }
}
$currDelId = (int) ($h3R2['delivery_id'] ?? 0);
for ($att = 1; $att <= 5; $att++) {
    $saveLim = $summonService->recordDelivery($h3Id, [
        'case_id' => $caseLim,
        'hearing_id' => $h3Id,
        'delivery_id' => $currDelId,
        'document_id' => $h3R2['document_id'],
        'party_type' => 'Respondent',
        'resident_id' => $resResp2,
        'delivery_status' => 'Unserved',
        'unserved_reason' => 'No One Home',
        'failure_notes' => "Attempt {$att} unserved",
    ], $serverId);
    assertReg(!empty($saveLim['success']), "Recorded Attempt #{$att} Unserved cleanly (" . ($saveLim['message'] ?? '') . ')');
    $nxt = $summonService->initiateNextAttempt($caseLim, $clerkId, $h3Id, 'Respondent', $currDelId, $resResp2);
    assertReg(!empty($nxt['success']), "Attempt #" . ($att + 1) . " created cleanly for Respondent 2");
    $currDelId = (int) $nxt['delivery_id'];
}
// Mark 6th attempt Unserved, then confirm 7th attempt is BLOCKED by summons_max_attempts (6)
$saveLim6 = $summonService->recordDelivery($h3Id, [
    'case_id' => $caseLim,
    'hearing_id' => $h3Id,
    'delivery_id' => $currDelId,
    'document_id' => $h3R2['document_id'],
    'party_type' => 'Respondent',
    'resident_id' => $resResp2,
    'delivery_status' => 'Unserved',
    'unserved_reason' => 'No One Home',
    'failure_notes' => 'Attempt 6 unserved',
], $serverId);
assertReg(!empty($saveLim6['success']), 'Recorded Attempt #6 Unserved cleanly');
$att7Blocked = $summonService->initiateNextAttempt($caseLim, $clerkId, $h3Id, 'Respondent', $currDelId, $resResp2);
assertReg(empty($att7Blocked['success']), 'Configured summons_max_attempts (6) stops 7th retry for Respondent 2');

// Now test a clean successful retry on another 2-respondent case OR serve attempt? Wait: verify on case1's Attempt 6 if updated before exhaustion? No, 6 is already Unserved -> verify Prior Notice exception & successful retry on fresh Hearing 3:
// Test prior-notice exception on non-rescheduled 2nd Mediation hearing for Respondent 1 (had verified Mediation 1? No, Mediation 1 was rescheduled; create a clean 2nd Mediation hearing case!):
$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test Prior Notice & Retry', 'Mediation', ?, 'Civil', CURDATE(), 'Prior notice & retry test', ?, NOW())
")->execute(["CMP-REG1B-{$token}", $catId, $clerkId]);
$comp1b = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp1b);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$comp1b, $resComp, $comp1b, $resResp1, $comp1b, $resResp2]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp1b, "REG-001B-{$token}"]);
$case1b = (int) $db->lastInsertId();

$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Completed'), (?, 'Mediation', ?, 'Lupon Hall', 'Scheduled')")
    ->execute([$case1b, "{$tue1} 09:00:00", $case1b, "{$tue2} 09:00:00"]);
$m1Id = (int) $db->query("SELECT hearing_id FROM hearings WHERE case_id = {$case1b} ORDER BY hearing_id ASC LIMIT 1")->fetchColumn();
$m2Id = (int) $db->query("SELECT hearing_id FROM hearings WHERE case_id = {$case1b} ORDER BY hearing_id DESC LIMIT 1")->fetchColumn();

// Serve ONLY Respondent 1 in Mediation 1
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, resident_id, party_type, attempt_number, delivery_status, served_at, recipient_name) VALUES (?, ?, ?, 'Respondent', 1, 'Served Personal', NOW(), 'Resp 1')")
    ->execute([$case1b, $m1Id, $resResp1]);
$verM2R1 = $summonService->verifyRecipientServiceForHearing($m2Id, $resResp1, 'Respondent');
$verM2R2 = $summonService->verifyRecipientServiceForHearing($m2Id, $resResp2, 'Respondent');
assertReg(!empty($verM2R1['verified']) && ($verM2R1['basis'] ?? '') === 'prior_mediation_notice', 'Prior-notice exception applies on non-rescheduled 2nd Mediation for Respondent 1');
assertReg(empty($verM2R2['verified']), 'Prior-notice exception does NOT apply to Respondent 2 who was never served in Mediation 1');

// =========================================================================
// SCENARIO 3: CFA Bypass Rejection, Draft Minutes, Wrong-Stage Minutes
// =========================================================================
echo "\n--- SCENARIO 3: CFA Bypass (noncompliance_reopen), Draft & Wrong-Stage Minutes Rejection ---\n";

$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test CFA Gate', 'Mediation', ?, 'Civil', CURDATE(), 'CFA bypass check', ?, NOW())
")->execute(["CMP-REG3-{$token}", $catId, $clerkId]);
$comp3 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp3);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp3, $resComp, $comp3, $resResp1]);
// Deliberately set case_status = 'Repudiated' WITHOUT any saved repudiation record to prove bare status cannot bypass
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Repudiated', CURDATE(), NOW())")
    ->execute([$comp3, "REG-003-{$token}"]);
$case3 = (int) $db->lastInsertId();

// Create Finalized Mediation 'Failed' minutes + Draft Conciliation 'Pending CFA' minutes
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Completed'), (?, 'Conciliation', ?, 'Lupon Hall', 'Scheduled')")
    ->execute([$case3, "{$tue1} 10:00:00", $case3, "{$tue2} 10:00:00"]);
$c3MedH = (int) $db->query("SELECT hearing_id FROM hearings WHERE case_id = {$case3} AND hearing_type = 'Mediation' LIMIT 1")->fetchColumn();
$c3ConH = (int) $db->query("SELECT hearing_id FROM hearings WHERE case_id = {$case3} AND hearing_type = 'Conciliation' LIMIT 1")->fetchColumn();

$db->prepare("
    INSERT INTO hearing_minutes (hearing_id, case_id, session_type, settlement_discussion_notes, session_outcome, status, outcome_remarks, recorded_by)
    VALUES (?, ?, '1st Mediation', 'Mediation failed', 'Failed', 'Finalized', 'Mediation failed notes', ?),
           (?, ?, 'Conciliation', 'Draft notes', 'Pending CFA', 'Draft', 'Conciliation draft notes', ?)
")->execute([$c3MedH, $case3, $clerkId, $c3ConH, $case3, $clerkId]);

$elig3 = $cfaModel->determineCfaEligibility($case3);
assertReg(empty($elig3['eligible']), 'Mediation Failed minutes, Draft Conciliation minutes, and bare For CFA status do NOT establish CFA eligibility');

$kp20Pre = $kpService->checkPrerequisites('KP Form 20', $case3);
assertReg(empty($kp20Pre['eligible']), 'KpFormService locks KP Form 20 when only Draft or wrong-stage minutes exist');

$httpPreviewBlocked = regHttpRequest($baseUrl, $clerkSession, "documents/kp-form.php?case_id={$case3}&form_code=KP%20Form%2020&preview=1");
assertReg($httpPreviewBlocked['status'] === 403, 'kp-form.php preview=1 returns HTTP 403 for ineligible case with Draft/wrong-stage minutes');

$httpBypassReopen = regHttpRequest($baseUrl, $clerkSession, 'documents/cfa.php', [
    'case_id' => $case3,
    'cfa_type' => 'KP Form 20',
    'workflow' => 'noncompliance_reopen',
    'reason' => 'Forged reopen bypass',
]);
assertReg($httpBypassReopen['status'] === 422 && empty($httpBypassReopen['json']['success']), 'Direct CFA POST with workflow=noncompliance_reopen and no saved violated settlement is rejected (HTTP 422)');

$httpReopenEndpoint = regHttpRequest($baseUrl, $clerkSession, 'cases/reopen.php', [
    'case_id' => $case3,
    'reason' => 'No settlement exists',
], true);
assertReg($httpReopenEndpoint['status'] === 422 && empty($httpReopenEndpoint['json']['success']), 'cases/reopen.php rejects noncompliance reopen when no saved settlement exists');

// =========================================================================
// SCENARIO 4: Valid CFA Issuance, Duplicate Idempotency & Doc-Failure Rollback
// =========================================================================
echo "\n--- SCENARIO 4: Valid CFA Issuance, Duplicate Idempotency & Doc Failure Rollback ---\n";

// Finalize Conciliation minutes cleanly to make case3 validly eligible for KP Form 20
$db->prepare("UPDATE hearing_minutes SET status = 'Finalized', finalized_by = ?, finalized_at = NOW() WHERE hearing_id = ? AND session_type = 'Conciliation'")
    ->execute([$clerkId, $c3ConH]);
$db->prepare("UPDATE cases SET case_status = 'Conciliation' WHERE case_id = ?")->execute([$case3]);

$elig3Valid = $cfaModel->determineCfaEligibility($case3);
assertReg(!empty($elig3Valid['eligible']) && $elig3Valid['ground'] === 'CONCILIATION_IMPASSE' && $elig3Valid['recommended_form'] === 'KP Form 20', 'Finalized Conciliation Pending CFA unlocks KP Form 20');

// Wrong certificate type KP Form 20-A / 20-B must still be rejected for Conciliation Impasse
$wrongForm = regHttpRequest($baseUrl, $clerkSession, 'documents/cfa.php', [
    'case_id' => $case3,
    'cfa_type' => 'KP Form 20-A',
    'reason' => 'Wrong form type check',
]);
assertReg($wrongForm['status'] === 422 && empty($wrongForm['json']['success']), 'Issuing KP Form 20-A for CONCILIATION_IMPASSE is rejected');

// Simulate Document-Generation Failure -> Must roll back cfa_records, status, history and leave case eligible!
$failingCfaModel = new class($db) extends CFA {
    private PDO $mockDb;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->mockDb = $db;
    }

    protected function createKpFormService(): KpFormService
    {
        return new class($this->mockDb) extends KpFormService {
            public function generateAndPersistForm(string $formCode, int $caseId, int $userId, array $options = []): array
            {
                throw new RuntimeException('Simulated PDF generator failure');
            }
        };
    }
};

$failResult = $failingCfaModel->create([
    'case_id' => $case3,
    'cfa_type' => 'KP Form 20',
    'reason' => 'Initial issuance attempt that fails PDF generation',
], $clerkId);
assertReg(empty($failResult['success']), 'CFA creation fails cleanly when document generation fails');
$cfaCountAfterFail = (int) $db->query("SELECT COUNT(*) FROM cfa_records WHERE case_id = {$case3}")->fetchColumn();
$caseStatusAfterFail = (string) $db->query("SELECT case_status FROM cases WHERE case_id = {$case3}")->fetchColumn();
$historyCountAfterFail = (int) $db->query("SELECT COUNT(*) FROM case_history WHERE case_id = {$case3} AND status = 'CFA Issued'")->fetchColumn();
$eligAfterFail = $cfaModel->determineCfaEligibility($case3);
assertReg($cfaCountAfterFail === 0 && $caseStatusAfterFail === 'Conciliation' && $historyCountAfterFail === 0 && !empty($eligAfterFail['eligible']), 'Transaction rolled back cfa_records, case_status, and history leaving case eligible for retry');

// Successful Retry via real HTTP POST /backend/api/documents/cfa.php
$origReason = 'Conciliation proceedings before the Pangkat failed to produce a settlement.';
$issueHttp = regHttpRequest($baseUrl, $clerkSession, 'documents/cfa.php', [
    'case_id' => $case3,
    'cfa_type' => 'KP Form 20',
    'reason' => $origReason,
]);
assertReg($issueHttp['status'] === 200 && !empty($issueHttp['json']['success']), 'Successful retry via HTTP cfa.php issues KP Form 20 (HTTP 200)');
$issuedDocPath = $db->query("SELECT gd.file_path FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = {$case3} AND dt.template_name = 'KP Form 20' ORDER BY gd.document_id DESC LIMIT 1")->fetchColumn();
if ($issuedDocPath) {
    $createdFiles[] = dirname(__DIR__, 2) . '/' . ltrim((string) $issuedDocPath, '/');
}

// Duplicate submission with a different reason -> must return existing issuance unchanged
$dupHttp = regHttpRequest($baseUrl, $clerkSession, 'documents/cfa.php', [
    'case_id' => $case3,
    'cfa_type' => 'KP Form 20',
    'reason' => 'Overwritten reason should NOT be stored',
]);
assertReg($dupHttp['status'] === 200 && !empty($dupHttp['json']['success']) && !empty($dupHttp['json']['duplicate']), 'Duplicate CFA submission returns existing issuance idempotently (HTTP 200)');
$cfaRowsNow = $db->query("SELECT reason, issuance_date FROM cfa_records WHERE case_id = {$case3} AND cfa_type = 'KP Form 20'")->fetchAll(PDO::FETCH_ASSOC);
assertReg(count($cfaRowsNow) === 1 && $cfaRowsNow[0]['reason'] === $origReason, 'Duplicate submission preserves single cfa_records row with original reason and date');

// Verify settlement noncompliance works ONLY with saved settlement + documented violation (positive check)
$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test Settlement Breach', 'Settled', ?, 'Civil', CURDATE(), 'Settlement noncompliance check', ?, NOW())
")->execute(["CMP-REG4-{$token}", $catId, $clerkId]);
$comp4 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp4);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp4, $resComp, $comp4, $resResp1]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Settled', CURDATE(), NOW())")
    ->execute([$comp4, "REG-004-{$token}"]);
$case4 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO settlements (case_id, settlement_date, agreement_details, repudiation_status, compliance_status) VALUES (?, CURDATE(), 'Pay 5000', 'Enforceable', 'Pending')")
    ->execute([$case4]);
$reopenOk = regHttpRequest($baseUrl, $clerkSession, 'cases/reopen.php', [
    'case_id' => $case4,
    'reason' => 'Respondent refused to pay on due date per affidavit.',
], true);
$elig4 = $cfaModel->determineCfaEligibility($case4);
assertReg($reopenOk['status'] === 200 && !empty($reopenOk['json']['success']) && !empty($elig4['eligible']) && $elig4['ground'] === 'SETTLEMENT_NONCOMPLIANCE', 'Valid settlement noncompliance reopen succeeds and establishes SETTLEMENT_NONCOMPLIANCE eligibility');

// =========================================================================
// SCENARIO 5: Per-Respondent Delivery-Failure CFA Eligibility (3 + 3 vs 6)
// =========================================================================
echo "\n--- SCENARIO 5: Per-Respondent Delivery-Failure CFA Eligibility ---\n";

$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test Per-Resp Delivery CFA', 'Mediation', ?, 'Civil', CURDATE(), 'Two respondents delivery failure split', ?, NOW())
")->execute(["CMP-REG5-{$token}", $catId, $clerkId]);
$comp5 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp5);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$comp5, $resComp, $comp5, $resResp1, $comp5, $resResp2]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp5, "REG-005-{$token}"]);
$case5 = (int) $db->lastInsertId();

$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, mediation_attempt, created_at) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Scheduled', 1, NOW())")
    ->execute([$case5, $tue1 . ' 14:00:00']);
$h5Id = (int) $db->lastInsertId();

// 3 Unserved failures for Respondent 1 + 3 Unserved failures for Respondent 2 = 6 rows total across respondents, max 3 per respondent
$insFailStmt = $db->prepare("
    INSERT INTO summon_deliveries
    (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, unserved_reason, served_by, created_at)
    VALUES (?, ?, 'Respondent', ?, ?, 'KP Form 9', 'Personal', 'Unserved', 'No One at Home', ?, NOW())
");
for ($a = 1; $a <= 3; $a++) {
    $insFailStmt->execute([$case5, $h5Id, $resResp1, $a, $clerkId]);
    $insFailStmt->execute([$case5, $h5Id, $resResp2, $a, $clerkId]);
}

$elig5Split = $summonService->getCfaEligibility($case5);
$cfa5Split = $cfaModel->determineCfaEligibility($case5);
$kp5Split = $kpService->checkPrerequisites('KP Form 20', $case5);
assertReg(empty($elig5Split['eligible']) && (int) $elig5Split['failed_count'] === 3, 'Three failures each for 2 respondents leaves SummonDeliveryService CFA locked at 3/6');
assertReg(empty($cfa5Split['eligible']) && empty($kp5Split['eligible']), 'Three failures each for 2 respondents leaves CFA::determineCfaEligibility and KpFormService preview locked');
$proofCases = regHttpRequest($baseUrl, $clerkSession, 'gps/proof-service.php');
$proofCase = array_values(array_filter($proofCases['json']['data'] ?? [], static fn($row) => (int) ($row['case_id'] ?? 0) === $case5))[0] ?? null;
$proofEligibility = regHttpRequest($baseUrl, $clerkSession, "gps/proof-service.php?mode=cfa_eligibility&case_id={$case5}");
assertReg($proofCase && (int) $proofCase['respondent_failed_attempts'] === 6
    && $proofCase['cfa_eligibility'] === $cfa5Split
    && ($proofEligibility['json']['data'] ?? null) === $cfa5Split,
    'Proof of Service uses the same locked decision and reason as CFA for split failures');

// Add superseded + cancelled-hearing Unserved rows -> must NOT push Respondent 1 over threshold
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, unserved_reason, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 9, 'KP Form 9', 'Personal', 'Superseded', 'Superseded return', ?, NOW())")
    ->execute([$case5, $h5Id, $resResp1, $clerkId]);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, mediation_attempt, created_at) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Cancelled', 1, NOW())")
    ->execute([$case5, $tue2 . ' 14:00:00']);
$h5Cancel = (int) $db->lastInsertId();
$insFailStmt->execute([$case5, $h5Cancel, $resResp1, 4, $clerkId]);

$elig5WithExcluded = $summonService->getCfaEligibility($case5);
assertReg(empty($elig5WithExcluded['eligible']) && (int) $elig5WithExcluded['failed_count'] === 3, 'Superseded returns and cancelled-hearing Unserved rows are excluded from Respondent 1 failure count');

// Bring Respondent 1 alone up to 6 valid Unserved attempts -> unlocks SIX_FAILURES across all surfaces
for ($a = 4; $a <= 6; $a++) {
    $insFailStmt->execute([$case5, $h5Id, $resResp1, $a, $clerkId]);
}
$elig5Unlocked = $summonService->getCfaEligibility($case5);
$cfa5Unlocked = $cfaModel->determineCfaEligibility($case5);
$kp5Unlocked = $kpService->checkPrerequisites('KP Form 20', $case5);
$preview5Http = regHttpRequest($baseUrl, $clerkSession, "documents/kp-form.php?case_id={$case5}&form_code=KP%20Form%2020&preview=1");
assertReg(!empty($elig5Unlocked['eligible']) && ($elig5Unlocked['scenario'] ?? '') === 'SIX_FAILURES' && (int) ($elig5Unlocked['resident_id'] ?? 0) === $resResp1 && (int) $elig5Unlocked['failed_count'] === 6, 'Six Unserved attempts for a single respondent unlocks SIX_FAILURES CFA eligibility');
assertReg(!empty($cfa5Unlocked['eligible']) && ($cfa5Unlocked['ground'] ?? '') === 'SUMMONS_DELIVERY_FAILURE' && !empty($kp5Unlocked['eligible']) && $preview5Http['status'] === 200, 'CFA model, KpFormService, and HTTP kp-form.php preview agree once single respondent hits 6 failures');

// =========================================================================
// SCENARIO 6: Per-Respondent Served No-Show Count & Prior-Notice Scoping
// =========================================================================
echo "\n--- SCENARIO 6: Per-Respondent Verified No-Show Counting ---\n";

$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test No-Show Scoping', 'Mediation', ?, 'Civil', CURDATE(), 'Per-respondent no-show count check', ?, NOW())
")->execute(["CMP-REG6-{$token}", $catId, $clerkId]);
$comp6 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp6);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$comp6, $resComp, $comp6, $resResp1, $comp6, $resResp2]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp6, "REG-006-{$token}"]);
$case6 = (int) $db->lastInsertId();

// Med 1..5 served personally to Resp1 AND Resp1 absent (unexcused). On Med 2 keep a failed retry attempt before served? No, Med 2 has attempt #1 Unserved + attempt #2 Served Personal!
$h6Ids = [];
for ($m = 1; $m <= 5; $m++) {
    $hDate = date('Y-m-d', strtotime($tue1 . ' +' . ($m - 1) . ' weeks')) . ' 14:00:00';
    $db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, respondent_attendance, mediation_attempt, created_at) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Completed', 'Absent', ?, NOW())")
        ->execute([$case6, $hDate, $m]);
    $hid = (int) $db->lastInsertId();
    $h6Ids[$m] = $hid;
    if ($m === 2) {
        // Failed attempt #1 followed by retry attempt #2 Served Personal -> verify retry preserves attempt #1 & counts hearing once
        $db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, unserved_reason, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 1, 'KP Form 9', 'Personal', 'Unserved', 'Temporary Absence', ?, NOW())")
            ->execute([$case6, $hid, $resResp1, $clerkId]);
        $db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, recipient_name, relationship, served_at, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 2, 'KP Form 9', 'Personal', 'Served Personal', 'Resp1', 'Self', NOW(), ?, NOW())")
            ->execute([$case6, $hid, $resResp1, $clerkId]);
    } else {
        $db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, recipient_name, relationship, served_at, served_by, created_at) VALUES (?, ?, 'Respondent', ?, ?, 'KP Form 9', 'Personal', 'Served Personal', 'Resp1', 'Self', NOW(), ?, NOW())")
            ->execute([$case6, $hid, $resResp1, $m, $clerkId]);
    }
    $db->prepare("INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified) VALUES (?, ?, 'Absent', 0)")
        ->execute([$hid, $resResp1]);
}

// Add noise that MUST NOT count toward Resp1's 6th no-show:
// (a) Resp2 served + unexcused absent on Med 1
$db->prepare("INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified) VALUES (?, ?, 'Absent', 0)")
    ->execute([$h6Ids[1], $resResp2]);
// (b) Excused absence for Resp1 on an extra completed hearing
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, respondent_attendance, mediation_attempt, created_at) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Completed', 'Excused', 5, NOW())")
    ->execute([$case6, date('Y-m-d', strtotime($tue1 . ' +5 weeks')) . ' 14:00:00']);
$h6Excused = (int) $db->lastInsertId();
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, recipient_name, relationship, served_at, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 5, 'KP Form 9', 'Personal', 'Served Personal', 'Resp1', 'Self', NOW(), ?, NOW())")
    ->execute([$case6, $h6Excused, $resResp1, $clerkId]);
$db->prepare("INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified) VALUES (?, ?, 'Excused', 1)")
    ->execute([$h6Excused, $resResp1]);
// (c) Cancelled hearing + superseded delivery on Resp1
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, respondent_attendance, mediation_attempt, created_at) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Cancelled', 'Absent', 5, NOW())")
    ->execute([$case6, date('Y-m-d', strtotime($tue1 . ' +6 weeks')) . ' 14:00:00']);
$h6Cancel = (int) $db->lastInsertId();
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, recipient_name, relationship, served_at, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 5, 'KP Form 9', 'Personal', 'Served Personal', 'Resp1', 'Self', NOW(), ?, NOW())")
    ->execute([$case6, $h6Cancel, $resResp1, $clerkId]);
$db->prepare("INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified) VALUES (?, ?, 'Absent', 0)")
    ->execute([$h6Cancel, $resResp1]);

$elig6Five = $summonService->getCfaEligibility($case6);
assertReg(empty($elig6Five['eligible']) && (int) $elig6Five['no_show_count'] === 5, '5 verified no-shows for Resp1 remain locked (co-resp, excused absence, and cancelled hearing excluded)');

// Verify attempt history on Med 2 preserved failed #1 alongside served #2
$attempts6 = $summonService->getSummonsAttempts($case6, null, 'Mediation', 'Respondent');
$med2Attempts = array_values(array_filter($attempts6, fn($a) => (int) ($a['hearing_id'] ?? 0) === $h6Ids[2]));
assertReg(count($med2Attempts) === 2, 'Failed retry attempt #1 on current notice is preserved alongside served retry #2');

// Now add Med 6 using prior_mediation_notice (no direct delivery row for Med 6, covered by Resp1 prior Med 5 service + Med 6 unexcused Absent) -> unlocks SIX_SERVED_NO_SHOWS
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, respondent_attendance, mediation_attempt, created_at) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Completed', 'Absent', 6, NOW())")
    ->execute([$case6, date('Y-m-d', strtotime($tue1 . ' +7 weeks')) . ' 14:00:00']);
$h6Med6 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified) VALUES (?, ?, 'Absent', 0)")
    ->execute([$h6Med6, $resResp1]);

$elig6Six = $summonService->getCfaEligibility($case6);
$cfa6Six = $cfaModel->determineCfaEligibility($case6);
assertReg(!empty($elig6Six['eligible']) && ($elig6Six['scenario'] ?? '') === 'SIX_SERVED_NO_SHOWS' && (int) ($elig6Six['no_show_count'] ?? 0) === 6 && !empty($cfa6Six['eligible']), '6th qualifying verified no-show (via Resp1 prior-notice exception) unlocks SIX_SERVED_NO_SHOWS across SummonDeliveryService and CFA');

// =========================================================================
// SCENARIO 7: Atomic Bar Approval & Certificate Rollback (HearingWorkflow::act legal_action)
// =========================================================================
echo "\n--- SCENARIO 7: Atomic Bar Approval & Certificate Rollback ---\n";

$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test Bar Approval Atomicity', 'Mediation', ?, 'Civil', CURDATE(), 'Bar action atomicity check', ?, NOW())
")->execute(["CMP-REG7-{$token}", $catId, $clerkId]);
$comp7 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp7);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$comp7, $resComp, $comp7, $resResp1, $comp7, $resResp2]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp7, "REG-007-{$token}"]);
$case7 = (int) $db->lastInsertId();

$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, mediation_attempt, created_at) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Scheduled', 1, NOW())")
    ->execute([$case7, $tue1 . ' 14:00:00']);
$h7Orig = (int) $db->lastInsertId();

// Resp1 is Served + Absent + Unjustified Show Cause; Resp2 has ZERO service/absence/show-cause
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, recipient_name, relationship, served_at, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 1, 'KP Form 9', 'Personal', 'Served Personal', 'Resp1', 'Self', NOW(), ?, NOW())")
    ->execute([$case7, $h7Orig, $resResp1, $clerkId]);
$db->prepare("INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified) VALUES (?, ?, 'Absent', 0)")
    ->execute([$h7Orig, $resResp1]);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id, created_at) VALUES (?, 'Show Cause (Respondent)', ?, 'Lupon Hall', 'Completed', ?, NOW())")
    ->execute([$case7, $tue2 . ' 14:00:00', $h7Orig]);
$h7Sc = (int) $db->lastInsertId();
$db->prepare("INSERT INTO show_cause_evaluations (hearing_id, case_id, party_type, form_type, is_justified, justification_category, justification_notes, action_taken, evaluated_by) VALUES (?, ?, 'Respondent', 'KP Form 19', 0, 'Unjustified Absence', 'Refused to attend without valid excuse', 'Respondent Default', ?)")
    ->execute([$h7Sc, $case7, $clerkId]);
$db->prepare("INSERT INTO hearing_explanations (hearing_id, resident_id, explanation, outcome, decided_by, decided_at, created_by) VALUES (?, ?, 'Unjustified absence confirmed for Resp1', 'Unjustified', ?, NOW(), ?)")
    ->execute([$h7Orig, $resResp1, $clerkId, $clerkId]);

// 7a: Invalid Approved KP Form 22 for Resp2 (evidence only exists for Resp1) -> MUST fail cleanly with 0 rows created
$beforeLegalCount = (int) $db->query("SELECT COUNT(*) FROM hearing_legal_actions WHERE hearing_id = {$h7Orig}")->fetchColumn();
$beforeDocCount = (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case7}")->fetchColumn();
$roleId7 = (int) ($clerkSession['role_id'] ?? 1);
$badBarResp2 = $workflowModel->act('legal_action', [
    'hearing_id' => $h7Orig,
    'resident_id' => $resResp2,
    'action_type' => 'KP Form 22',
    'status' => 'Approved',
    'details' => 'Attempted bar approval for unserved co-respondent',
], $clerkId, $roleId7);
$afterLegalCount = (int) $db->query("SELECT COUNT(*) FROM hearing_legal_actions WHERE hearing_id = {$h7Orig}")->fetchColumn();
$afterDocCount = (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case7}")->fetchColumn();
assertReg(empty($badBarResp2['success']) && $afterLegalCount === $beforeLegalCount && $afterDocCount === $beforeDocCount, 'Approved KP Form 22 for unserved co-respondent fails cleanly with 0 approvals and 0 certificates');

$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, form_type, delivery_status)
    VALUES (?, ?, 'Respondent', ?, 'KP Form 9', 'Served Personal')")->execute([$case7, $h7Orig, $resResp2]);
$db->prepare("INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified)
    VALUES (?, ?, 'Absent', 0)")->execute([$h7Orig, $resResp2]);
$badBorrowedFinding = $workflowModel->act('legal_action', [
    'hearing_id' => $h7Orig,
    'resident_id' => $resResp2,
    'action_type' => 'KP Form 22',
    'status' => 'Approved',
    'details' => 'Served co-respondent with no recipient-specific finding',
], $clerkId, $roleId7);
assertReg(empty($badBorrowedFinding['success'])
    && (int) $db->query("SELECT COUNT(*) FROM hearing_legal_actions WHERE hearing_id = {$h7Orig}")->fetchColumn() === $beforeLegalCount
    && (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case7}")->fetchColumn() === $beforeDocCount,
    'Verified absence cannot borrow a co-respondent or role-only show-cause determination');

// 7b: Invalid Approved KP Form 21 on Complainant (missing service/absence/show-cause) -> 0 rows
$badBarComp = $workflowModel->act('legal_action', [
    'hearing_id' => $h7Orig,
    'resident_id' => $resComp,
    'action_type' => 'KP Form 21',
    'status' => 'Approved',
    'details' => 'Attempted bar approval with missing evidence',
], $clerkId, $roleId7);
$afterCompLegal = (int) $db->query("SELECT COUNT(*) FROM hearing_legal_actions WHERE hearing_id = {$h7Orig}")->fetchColumn();
$afterCompDocs = (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case7}")->fetchColumn();
assertReg(empty($badBarComp['success']) && $afterCompLegal === 0 && $afterCompDocs === 0, 'Approved KP Form 21 with missing evidence creates 0 approvals and 0 certificates');

// 7c: Pending Review and Rejected workflows still succeed cleanly
$pendRes = $workflowModel->act('legal_action', [
    'hearing_id' => $h7Orig,
    'resident_id' => $resResp2,
    'action_type' => 'KP Form 22',
    'status' => 'Pending Review',
    'details' => 'Queued for PB review',
], $clerkId, $roleId7);
$rejRes = $workflowModel->act('legal_action', [
    'hearing_id' => $h7Orig,
    'resident_id' => $resResp2,
    'action_type' => 'KP Form 22',
    'status' => 'Rejected',
    'details' => 'Insufficient ground upon review',
], $clerkId, $roleId7);
assertReg(!empty($pendRes['success']) && !empty($rejRes['success']), 'Pending Review and Rejected legal_action workflows are preserved');

// 7d: Simulated certificate-write failure on valid Resp1 Approved KP Form 22 MUST roll back approval + history + docs
$failingWorkflow = new class($db) extends HearingWorkflow {
    protected function createBarCertificateDocument(int $caseId, string $formCode, int $userId, int $residentId): array
    {
        throw new RuntimeException('Simulated certificate storage failure');
    }
};
$preRollbackApproved = (int) $db->query("SELECT COUNT(*) FROM hearing_legal_actions WHERE hearing_id = {$h7Orig} AND status = 'Approved'")->fetchColumn();
$preRollbackDocs = (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case7}")->fetchColumn();
$preRollbackHistory = (int) $db->query("SELECT COUNT(*) FROM case_history WHERE case_id = {$case7}")->fetchColumn();

$rollbackRes = $failingWorkflow->act('legal_action', [
    'hearing_id' => $h7Orig,
    'resident_id' => $resResp1,
    'action_type' => 'KP Form 22',
    'status' => 'Approved',
    'details' => 'Should roll back on certificate error',
], $clerkId, $roleId7);
$postRollbackApproved = (int) $db->query("SELECT COUNT(*) FROM hearing_legal_actions WHERE hearing_id = {$h7Orig} AND status = 'Approved'")->fetchColumn();
$postRollbackDocs = (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case7}")->fetchColumn();
$postRollbackHistory = (int) $db->query("SELECT COUNT(*) FROM case_history WHERE case_id = {$case7}")->fetchColumn();
assertReg(empty($rollbackRes['success']) && $postRollbackApproved === $preRollbackApproved && $postRollbackDocs === $preRollbackDocs && $postRollbackHistory === $preRollbackHistory, 'Simulated certificate-write failure rolls back approval, document, and case_history cleanly');

// 7e: Valid Approved KP Form 22 for Resp1 succeeds atomically with approval + generated_documents + history + audit
$okBarRes = $workflowModel->act('legal_action', [
    'hearing_id' => $h7Orig,
    'resident_id' => $resResp1,
    'action_type' => 'KP Form 22',
    'status' => 'Approved',
    'details' => 'Approved KP Form 22 bar after unjustified show cause',
], $clerkId, $roleId7);
$validApprovedCount = (int) $db->query("SELECT COUNT(*) FROM hearing_legal_actions WHERE hearing_id = {$h7Orig} AND status = 'Approved' AND action_type = 'KP Form 22'")->fetchColumn();
$validDocRow = $db->query("SELECT gd.document_id, gd.file_path FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = {$case7} AND dt.template_name = 'KP Form 22' ORDER BY gd.document_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$validHistCount = (int) $db->query("SELECT COUNT(*) FROM case_history WHERE case_id = {$case7} AND remarks LIKE 'Legal action review (Approved)%KP Form 22%'")->fetchColumn();
if (!empty($validDocRow['file_path'])) {
    $createdFiles[] = dirname(__DIR__, 2) . '/' . ltrim((string) $validDocRow['file_path'], '/');
}
assertReg(!empty($okBarRes['success']) && $validApprovedCount === 1 && !empty($validDocRow['document_id']) && $validHistCount === 1, 'Valid Approved KP Form 22 saves approval, certificate document, and case_history atomically');

$evalData = ['hearing_id' => $h7Orig, 'resident_id' => $resResp2, 'party_type' => 'Respondent', 'is_justified' => false];
$regularHearingEval = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', $evalData);
assertReg($regularHearingEval['status'] === 422 && (int) $db->query("SELECT COUNT(*) FROM cfa_records WHERE case_id = {$case7}")->fetchColumn() === 0,
    'Show-cause evaluation rejects an ordinary Mediation hearing and issues no CFA');

$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id)
    VALUES (?, 'Show Cause (Respondent)', ?, 'Lupon Hall', 'Scheduled', ?)")->execute([$case7, $tue2 . ' 15:00:00', $h7Orig]);
$h7Resp2ShowCause = (int) $db->lastInsertId();
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, form_type, delivery_status)
    VALUES (?, ?, 'Respondent', ?, 'KP Form 19', 'Pending')")->execute([$case7, $h7Resp2ShowCause, $resResp2]);
$notice7 = (int) $db->lastInsertId();
$evalData['hearing_id'] = $h7Resp2ShowCause;
$unservedEval = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', $evalData);
assertReg($unservedEval['status'] === 422 && (int) $db->query("SELECT COUNT(*) FROM cfa_records WHERE case_id = {$case7}")->fetchColumn() === 0,
    'Show-cause evaluation rejects a pending show-cause return despite verified original service');
$db->prepare("UPDATE summon_deliveries SET delivery_status = 'Served Personal' WHERE delivery_id = ?")->execute([$notice7]);
$wrongRecipientEval = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', array_replace($evalData, ['resident_id' => $resResp1]));
assertReg($wrongRecipientEval['status'] === 422 && (int) $db->query("SELECT COUNT(*) FROM cfa_records WHERE case_id = {$case7}")->fetchColumn() === 0,
    'A served show-cause notice cannot be used to evaluate a different respondent');
$failingShowCause = new class($db) extends ShowCauseService {
    public function generateKpForm(
        int $caseId, int $hearingId, string $formCode, string $targetPartyType, array $parties,
        array $hearing, ?string $explanationDate, ?string $venue, int $actorUserId, bool $strictPdf = false
    ): array {
        throw new RuntimeException('Simulated show-cause certificate storage failure');
    }
};
$failedEval = $failingShowCause->evaluateShowCause($h7Resp2ShowCause, $evalData, $clerkId);
assertReg(empty($failedEval['success'])
    && (int) $db->query("SELECT COUNT(*) FROM cfa_records WHERE case_id = {$case7}")->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM show_cause_evaluations WHERE hearing_id = {$h7Resp2ShowCause}")->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case7}")->fetchColumn() === 1
    && $db->query("SELECT status FROM hearings WHERE hearing_id = {$h7Resp2ShowCause}")->fetchColumn() === 'Scheduled',
    'Show-cause certificate failure rolls back the evaluation, both CFA forms, and hearing status');
$validEval = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', $evalData);
$recipientFinding = $db->query("SELECT resident_id, notice_delivery_id FROM show_cause_evaluations WHERE hearing_id = {$h7Resp2ShowCause}")->fetch(PDO::FETCH_ASSOC);
$types = $db->query("SELECT cfa_type FROM cfa_records WHERE case_id = {$case7} ORDER BY cfa_type")->fetchAll(PDO::FETCH_COLUMN);
assertReg($validEval['status'] === 200 && (int) ($recipientFinding['resident_id'] ?? 0) === $resResp2
    && (int) ($recipientFinding['notice_delivery_id'] ?? 0) === $notice7 && $types === ['KP Form 20', 'KP Form 20-B'],
    'Valid show-cause evaluation saves the recipient and exact notice and issues both CFA forms through CFA issuance');
$counterclaimForm = $kpService->getFormData('KP Form 20-B', $case7);
assertReg(array_map('intval', array_column($counterclaimForm['respondents'], 'resident_id')) === [$resResp2],
    'KP Form 20-B preview identifies only the respondent whose determination authorized issuance');
foreach ($db->query("SELECT file_path FROM generated_documents WHERE case_id = {$case7}")->fetchAll(PDO::FETCH_COLUMN) as $filePath) {
    $createdFiles[] = dirname(__DIR__, 2) . '/' . ltrim((string) $filePath, '/');
}

// =========================================================================
// SCENARIO 8: Today Docket Per-Recipient Check-In & Multi-Respondent Readiness
// =========================================================================
echo "\n--- SCENARIO 8: Docket Check-In (Retry Served vs Unserved Co-Respondent) ---\n";

require_once __DIR__ . '/../models/Report.php';

$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg Test Docket Check-In Readiness', 'Mediation', ?, 'Civil', CURDATE(), 'Docket check-in readiness test', ?, NOW())
")->execute(["CMP-REG8-{$token}", $catId, $clerkId]);
$comp8 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp8);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$comp8, $resComp, $comp8, $resResp1, $comp8, $resResp2]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp8, "REG-008-{$token}"]);
$case8 = (int) $db->lastInsertId();

$todayDt = date('Y-m-d') . ' 14:00:00';
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, queue_status, mediation_attempt, created_at) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Scheduled', 'Waiting', 1, NOW())")
    ->execute([$case8, $todayDt]);
$h8Id = (int) $db->lastInsertId();

// Replacement notices where Complainant & Resp1 are served (Resp1 via Retry #2), while Resp2 remains Unserved
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, recipient_name, relationship, served_at, served_by, created_at) VALUES (?, ?, 'Complainant', ?, 1, 'KP Form 8', 'Personal', 'Served Personal', 'Complainant', 'Self', NOW(), ?, NOW())")
    ->execute([$case8, $h8Id, $resComp, $clerkId]);
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, unserved_reason, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 1, 'KP Form 9', 'Personal', 'Unserved', 'Not Home', ?, NOW())")
    ->execute([$case8, $h8Id, $resResp1, $clerkId]);
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, recipient_name, relationship, served_at, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 2, 'KP Form 9', 'Personal', 'Served Personal', 'Resp1', 'Self', NOW(), ?, NOW())")
    ->execute([$case8, $h8Id, $resResp1, $clerkId]);
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, service_method, delivery_status, unserved_reason, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 1, 'KP Form 9', 'Personal', 'Unserved', 'Not Home', ?, NOW())")
    ->execute([$case8, $h8Id, $resResp2, $clerkId]);

$chkResp1 = $hearingCtrl->checkInParty([
    'hearing_id' => $h8Id,
    'party_type' => 'Respondent',
    'status' => 'Checked In',
    'resident_id' => $resResp1,
], $clerkId);
$chkComp = $hearingCtrl->checkInParty([
    'hearing_id' => $h8Id,
    'party_type' => 'Complainant',
    'status' => 'Checked In',
    'resident_id' => $resComp,
], $clerkId);
$chkResp2Blocked = $hearingCtrl->checkInParty([
    'hearing_id' => $h8Id,
    'party_type' => 'Respondent',
    'status' => 'Checked In',
    'resident_id' => $resResp2,
], $clerkId);
$chkWrongRole = $hearingCtrl->checkInParty([
    'hearing_id' => $h8Id,
    'party_type' => 'Complainant',
    'status' => 'Checked In',
    'resident_id' => $resResp1,
], $clerkId);
assertReg(!empty($chkResp1['success']) && !empty($chkComp['success']), 'Check-in succeeds for complainant and retry-served Respondent 1');
assertReg(empty($chkResp2Blocked['success']) && ($chkResp2Blocked['code'] ?? '') === 'RECIPIENT_SERVICE_NOT_CONFIRMED', 'Check-in remains blocked (RECIPIENT_SERVICE_NOT_CONFIRMED) for unserved co-respondent');
assertReg(empty($chkWrongRole['success']), 'Check-in rejects resident_id mismatched against party_type');

$reportModel = new Report();
$dashStats = $reportModel->dashboardStats('all');
$todayItem8 = null;
foreach (($dashStats['today_hearings'] ?? []) as $th) {
    if ((int) ($th['hearing_id'] ?? 0) === $h8Id) {
        $todayItem8 = $th;
        break;
    }
}
$resp1PartyState = null;
$resp2PartyState = null;
foreach (($todayItem8['parties'] ?? []) as $p) {
    if ((int) $p['resident_id'] === $resResp1) $resp1PartyState = $p;
    if ((int) $p['resident_id'] === $resResp2) $resp2PartyState = $p;
}
assertReg(
    $todayItem8 !== null
    && ($todayItem8['queue_status'] ?? '') === 'Waiting'
    && empty($todayItem8['is_complete_and_ready'])
    && ($resp1PartyState['attendance_status'] ?? '') === 'Present'
    && !empty($resp1PartyState['service_verified'])
    && ($resp2PartyState['attendance_status'] ?? 'Pending') !== 'Present'
    && empty($resp2PartyState['service_verified']),
    'Report::dashboardStats today_hearings preserves Resp1 Present, Resp2 blocked/unverified, and keeps readiness Waiting'
);

$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status)
    VALUES (?, 'Conciliation', ?, 'Lupon Hall', 'Scheduled')")->execute([$case8, $todayDt]);
$conciliation8 = (int) $db->lastInsertId();
$serve12 = $db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, form_type, delivery_status)
    VALUES (?, ?, ?, ?, 'KP Form 12', 'Served Personal')");
$serve12->execute([$case8, $conciliation8, 'Complainant', $resComp]);
$serve12->execute([$case8, $conciliation8, 'Respondent', $resResp1]);
$quickAbsent = ['action' => 'mark_hearing_attendance', 'hearing_id' => $conciliation8, 'status' => 'Absent'];
$quickUnserved = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', $quickAbsent);
assertReg($quickUnserved['status'] === 422 && !empty($quickUnserved['json']['code'])
    && (int) $db->query("SELECT COUNT(*) FROM hearing_attendance WHERE hearing_id = {$conciliation8}")->fetchColumn() === 0,
    'Conciliation Quick Attendance blocks an absence while one recipient lacks verified service');
$serve12->execute([$case8, $conciliation8, 'Respondent', $resResp2]);
$quickAmbiguous = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', $quickAbsent);
assertReg($quickAmbiguous['status'] === 422
    && (int) $db->query("SELECT COUNT(*) FROM hearing_attendance WHERE hearing_id = {$conciliation8}")->fetchColumn() === 0,
    'One-click absence on a hearing with multiple served respondents requires individual attendance');
$quickPresent = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', array_replace($quickAbsent, ['status' => 'Present']));
assertReg($quickPresent['status'] === 200
    && (int) $db->query("SELECT COUNT(*) FROM hearing_attendance WHERE hearing_id = {$conciliation8} AND attendance_status = 'Present'")->fetchColumn() === 3,
    'Conciliation Quick Attendance still records Present once every recipient is served');

echo "\n====================================================================\n";
echo "   TOTAL REGRESSIONS: " . ($passCount + $failCount) . " | PASSED: {$passCount} | FAILED: {$failCount}\n";
echo "====================================================================\n";

$cleanup->cleanup();
exit($failCount > 0 ? 1 : 0);
