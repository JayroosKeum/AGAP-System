<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/CFA.php';
require_once __DIR__ . '/../models/CaseModel.php';
require_once __DIR__ . '/../models/CaseStage.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/HearingAttendance.php';
require_once __DIR__ . '/../models/HearingMinutes.php';
require_once __DIR__ . '/../models/HearingWorkflow.php';
require_once __DIR__ . '/../models/Paanyaya.php';
require_once __DIR__ . '/../models/Summons.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../controllers/HearingController.php';
require_once __DIR__ . '/../services/KpFormService.php';
require_once __DIR__ . '/../services/MediationCommencementService.php';
require_once __DIR__ . '/../services/MediationDeadlineService.php';
require_once __DIR__ . '/../services/PaanyayaPreparationService.php';
require_once __DIR__ . '/../services/PaanyayaServiceDelivery.php';
require_once __DIR__ . '/../services/ShowCauseService.php';
require_once __DIR__ . '/../services/SummonDeliveryService.php';
require_once __DIR__ . '/../services/SummonServiceGate.php';
require_once __DIR__ . '/../services/OfficeLogisticsService.php';
require_once __DIR__ . '/../services/DocketScheduleService.php';
require_once __DIR__ . '/../services/DeadlineAlertService.php';
require_once __DIR__ . '/../services/DeadlineService.php';
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
$db->prepare("INSERT INTO settlements (case_id, settlement_date, agreement_details, complainant_signed, respondent_signed, pb_attested, settlement_status, repudiation_status, compliance_status) VALUES (?, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Pay 5000', 1, 1, 1, 'Signed and Attested', 'Enforceable', 'Pending')")
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

// =========================================================================
// SCENARIO 9: Order 1.1 — Clerk Agenda Prepared Paanyaya Shortcuts
// =========================================================================
echo "\n--- SCENARIO 9: Order 1.1 Clerk Agenda Prepared Paanyaya Shortcuts ---\n";

$prepSvc = new PaanyayaPreparationService($db);
$paaDeliverySvc = new PaanyayaServiceDelivery($db);
$paaModel = new Paanyaya($db);
$activeServerRow = $db->query("SELECT user_id FROM users WHERE role_id = 4 AND status = 'Active' ORDER BY user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$paaServerId = $activeServerRow ? (int) $activeServerRow['user_id'] : $serverId;
$lisoReviewerId = (int) ($db->query("SELECT u.user_id FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Lupon Member' AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 1")->fetchColumn() ?: $clerkId);

$db->prepare("
    INSERT INTO complaints (
        complaint_number, complaint_title, status, screening_decision, liso_assigned_to, liso_reviewed_by, liso_reviewed_at,
        category_id, case_type, incident_date, narrative, encoded_by, created_at
    ) VALUES (?, 'Reg9 Paanyaya Shortcuts', 'Accepted', 'Eligible', ?, ?, NOW(), ?, 'Civil', CURDATE(), 'Prepared Paanyaya shortcut guards', ?, NOW())
")->execute(["CMP-REG9-{$token}", $lisoReviewerId, $lisoReviewerId, $catId, $clerkId]);
$comp9 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp9);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp9, $resComp, $comp9, $resResp1]);

$paaApptTs = time() + 7200;
$paaToday = date('Y-m-d', $paaApptTs);
$paaTime9 = date('H:i', $paaApptTs);
$prepSvc->save($comp9, $clerkId, 'generate', [
    'appointment_date' => $paaToday,
    'appointment_time' => $paaTime9,
    'venue' => 'Barangay Session Hall',
    'verify_parties' => '1',
    'party_version' => PaanyayaPreparationService::partyVersion($prepSvc->parties($comp9)),
], 1);
$prep9v1 = $prepSvc->intake($comp9, 1);
$ver9v1 = (string) ($prep9v1['document_version'] ?? '');
$prepSvc->save($comp9, $clerkId, 'ready', ['document_version' => $ver9v1], 1);
$paaDeliverySvc->release($comp9, $clerkId, $paaServerId, $ver9v1, 1);
$att9_1 = PaanyayaServiceDelivery::latestAttempt($db, $comp9, 1);

// Direct unserved attendance shortcut fails cleanly without mutating paanyayas/paanyaya_sessions/paanyaya_minutes/cases
$unservedQuickAtt = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'mark_paanyaya_attendance',
    'complaint_id' => $comp9,
    'session_id' => 0,
    'invitation_number' => 1,
    'document_version' => $ver9v1,
    'status' => 'Present',
]);
assertReg(
    $unservedQuickAtt['status'] >= 400
    && empty($unservedQuickAtt['json']['success'])
    && (int) $db->query("SELECT COUNT(*) FROM paanyaya_sessions WHERE complaint_id = {$comp9}")->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM paanyaya_minutes WHERE complaint_id = {$comp9}")->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM cases WHERE complaint_id = {$comp9}")->fetchColumn() === 0,
    'Clerk Agenda Paanyaya attendance rejects unserved invitation with zero DB mutation'
);

// Deliver v1 so session/agenda item is created, verify dashboard metadata, then reschedule to v2 so v1 becomes stale
$paaDeliverySvc->record((int) $att9_1['attempt_id'], $paaServerId, ['outcome' => 'Delivered', 'notes' => 'Delivered v1'], []);
$stats9 = (new Report())->dashboardStats('all');
$agendaItem9 = null;
foreach (array_merge($stats9['today_paanyayas'] ?? [], $stats9['calendar_hearings'] ?? []) as $item) {
    if (($item['record_type'] ?? '') === 'paanyaya' && (int) ($item['complaint_id'] ?? 0) === $comp9) {
        $agendaItem9 = $item;
        break;
    }
}
$sess9Id = (int) ($agendaItem9['session_id'] ?? 0);
$paa9Id = (int) ($agendaItem9['paanyaya_id'] ?? $db->query("SELECT paanyaya_id FROM paanyayas WHERE complaint_id = {$comp9} LIMIT 1")->fetchColumn());
assertReg(
    $agendaItem9 !== null
    && !empty($agendaItem9['is_prepared'])
    && (int) ($agendaItem9['invitation_number'] ?? 0) === 1
    && ($agendaItem9['document_version'] ?? '') === $ver9v1
    && !empty($agendaItem9['service_verified'])
    && $sess9Id > 0,
    'Report::dashboardStats attaches prepared invitation version, session_id, and verified service state'
);

$resched9 = regHttpRequest($baseUrl, $clerkSession, 'hearings/queue.php', [
    'action' => 'reschedule_item',
    'type' => 'paanyaya',
    'id' => $paa9Id,
    'complaint_id' => $comp9,
    'session_id' => $sess9Id,
    'target_date' => nextWeekdayDate($logistics, 2, 2),
    'target_time' => '11:00',
    'venue' => 'Barangay Session Hall',
    'presiding_officer_id' => $lisoReviewerId,
    'reschedule_reason' => 'Testing stale version rejection on quick action',
    'confirmed' => true,
]);
$prep9v2 = $prepSvc->intake($comp9, 1);
$ver9v2 = (string) ($prep9v2['document_version'] ?? '');
$sess9Before = $db->query("SELECT complainant_attended, respondent_attended FROM paanyaya_sessions WHERE session_id = {$sess9Id}")->fetch(PDO::FETCH_ASSOC);
$staleQuickAtt = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'mark_paanyaya_attendance',
    'complaint_id' => $comp9,
    'session_id' => $sess9Id,
    'invitation_number' => 1,
    'document_version' => $ver9v1,
    'status' => 'Present',
]);
$sess9After = $db->query("SELECT complainant_attended, respondent_attended FROM paanyaya_sessions WHERE session_id = {$sess9Id}")->fetch(PDO::FETCH_ASSOC);
assertReg(
    ($resched9['status'] === 200) && !empty($ver9v2) && $ver9v2 !== $ver9v1
    && $staleQuickAtt['status'] >= 400 && empty($staleQuickAtt['json']['success'])
    && $sess9After === $sess9Before
    && (int) $db->query("SELECT COUNT(*) FROM paanyaya_minutes WHERE complaint_id = {$comp9}")->fetchColumn() === 0,
    'Clerk Agenda Paanyaya attendance rejects stale document_version after reschedule with zero DB mutation'
);

// Deliver v2, then verify outcome shortcut without dialogue notes redirects to #paanyaya-dialogue-1 cleanly
$prepSvc->save($comp9, $clerkId, 'ready', ['document_version' => $ver9v2], 1);
$paaDeliverySvc->release($comp9, $clerkId, $paaServerId, $ver9v2, 1);
$att9_2 = PaanyayaServiceDelivery::latestAttempt($db, $comp9, 1);
$paaDeliverySvc->record((int) $att9_2['attempt_id'], $paaServerId, ['outcome' => 'Delivered', 'notes' => 'Delivered v2'], []);

$missingNotesOutcome = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'log_paanyaya_outcome',
    'complaint_id' => $comp9,
    'session_id' => $sess9Id,
    'invitation_number' => 1,
    'document_version' => $ver9v2,
    'outcome' => 'Settled',
    'dialogue_date' => date('Y-m-d\TH:i'),
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
]);
assertReg(
    $missingNotesOutcome['status'] === 422
    && !empty($missingNotesOutcome['json']['requires_dialogue_form'])
    && str_contains((string) ($missingNotesOutcome['json']['dialogue_url'] ?? ''), '#paanyaya-dialogue-1')
    && (int) $db->query("SELECT COUNT(*) FROM paanyaya_minutes WHERE complaint_id = {$comp9}")->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM cases WHERE complaint_id = {$comp9}")->fetchColumn() === 0,
    'Clerk Agenda Settled outcome without minutes/agreed terms returns requires_dialogue_form pointing to #paanyaya-dialogue-1 with zero DB changes (got ' . $missingNotesOutcome['status'] . ': ' . $missingNotesOutcome['body'] . ')'
);

$validQuickAtt = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'mark_paanyaya_attendance',
    'complaint_id' => $comp9,
    'session_id' => $sess9Id,
    'invitation_number' => 1,
    'document_version' => $ver9v2,
    'status' => 'Present',
]);
$validQuickOutcome = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'log_paanyaya_outcome',
    'complaint_id' => $comp9,
    'session_id' => $sess9Id,
    'invitation_number' => 1,
    'document_version' => $ver9v2,
    'outcome' => 'Escalate_Mediation',
    'dialogue_date' => date('Y-m-d\TH:i'),
    'discussion_summary' => 'Both parties appeared; complainant elected formal mediation docketing.',
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
]);
$validQuickDocket = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'log_paanyaya_outcome',
    'complaint_id' => $comp9,
    'session_id' => $sess9Id,
    'invitation_number' => 1,
    'document_version' => $ver9v2,
    'outcome' => 'Impasse_Escalate',
]);
assertReg(
    $validQuickAtt['status'] === 200 && $validQuickOutcome['status'] === 200
    && $validQuickDocket['status'] === 200
    && !empty($validQuickDocket['json']['result']['case_id'])
    && (int) $db->query("SELECT COUNT(*) FROM paanyaya_minutes WHERE complaint_id = {$comp9}")->fetchColumn() === 1,
    'Prepared Clerk Agenda attendance, full dialogue Escalate_Mediation outcome, and explicit docketing succeed cleanly (got ' . $validQuickOutcome['body'] . ' / ' . $validQuickDocket['body'] . ')'
);

// =========================================================================
// SCENARIO 10: Order 1.2 — Docketing vs 1st Formal Summons & Concurrency
// =========================================================================
echo "\n--- SCENARIO 10: Order 1.2 Docketing vs 1st Summons & Legacy Scope ---\n";

$db->prepare("
    INSERT INTO complaints (
        complaint_number, complaint_title, status, screening_decision, liso_assigned_to, liso_reviewed_by, liso_reviewed_at,
        category_id, case_type, incident_date, narrative, encoded_by, created_at
    ) VALUES (?, 'Reg10 Separate Docket & Summons', 'Accepted', 'Eligible', ?, ?, NOW(), ?, 'Civil', CURDATE(), 'Docketing before first summons test', ?, NOW())
")->execute(["CMP-REG10-{$token}", $lisoReviewerId, $lisoReviewerId, $catId, $clerkId]);
$comp10 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp10);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp10, $resComp, $comp10, $resResp1]);

$prepSvc->save($comp10, $clerkId, 'generate', [
    'appointment_date' => date('Y-m-d', time() + 10800),
    'appointment_time' => date('H:i', time() + 10800),
    'venue' => 'Barangay Session Hall',
    'verify_parties' => '1',
    'party_version' => PaanyayaPreparationService::partyVersion($prepSvc->parties($comp10)),
], 1);
$ver10 = (string) ($prepSvc->intake($comp10, 1)['document_version'] ?? '');
$prepSvc->save($comp10, $clerkId, 'ready', ['document_version' => $ver10], 1);
$paaDeliverySvc->release($comp10, $clerkId, $paaServerId, $ver10, 1);
$att10 = PaanyayaServiceDelivery::latestAttempt($db, $comp10, 1);
$paaDeliverySvc->record((int) $att10['attempt_id'], $paaServerId, ['outcome' => 'Delivered', 'notes' => 'Delivered'], []);
$paaModel->recordMinutes($comp10, 'Paanyaya 1', [
    'dialogue_date' => date('Y-m-d\TH:i'),
    'document_version' => $ver10,
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
    'outcome' => 'Escalate_Mediation',
    'discussion_summary' => 'Escalate for formal docketing check.',
], $clerkId);

$prereqModernUndocketed = $paaModel->checkSummonsPrerequisite($comp10);
$summonsDirectBeforeDocket = (new Summons($db))->issueFirstSummon($comp10, $clerkId, [
    'mediation_date' => $tue1,
    'mediation_time' => '14:00',
    'venue' => 'Lupon Court Room',
    'override_paanyaya' => true,
]);
$issueHttpBeforeDocket = regHttpRequest($baseUrl, $clerkSession, 'summons/issue.php', [
    'complaint_id' => $comp10,
    'mediation_date' => $tue3,
    'mediation_time' => '15:30',
    'venue' => 'Lupon Court Room',
], true);
$previewHttpBeforeDocket = regHttpRequest($baseUrl, $clerkSession, "summons/preview.php?complaint_id={$comp10}&case_id=0");

assertReg(
    empty($prereqModernUndocketed['allowed']) && ($prereqModernUndocketed['reason'] ?? '') === 'docketing_required'
    && empty($summonsDirectBeforeDocket['success'])
    && $issueHttpBeforeDocket['status'] === 422
    && $previewHttpBeforeDocket['status'] >= 400
    && (int) $db->query("SELECT COUNT(*) FROM cases WHERE complaint_id = {$comp10}")->fetchColumn() === 0,
    'First formal summons, API issue, and preview are blocked before formal docketing even after Escalate_Mediation dialogue'
);

// Legacy fallback remains strictly scoped to non-prepared legacy records
$db->prepare("
    INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Reg10 Legacy Complaint', 'Pre-Docketed (Paanyaya)', ?, 'Civil', CURDATE(), 'Legacy fallback scope test', ?, NOW())
")->execute(["CMP-REG10LEG-{$token}", $catId, $clerkId]);
$comp10Leg = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp10Leg);
$db->prepare("INSERT INTO paanyayas (complaint_id, invitee_name, scheduled_date, scheduled_time, status, dialogue_notes, created_at) VALUES (?, 'Legacy Respondent', CURDATE(), '10:00:00', 'Escalated', 'Escalate_Mediation', NOW())")
    ->execute([$comp10Leg]);
$db->prepare("INSERT INTO complaint_paanyaya (complaint_id, invitation_date, invitation_time, status) VALUES (?, CURDATE(), '10:00:00', 'Escalated')")
    ->execute([$comp10Leg]);
$prereqLegacy = $paaModel->checkSummonsPrerequisite($comp10Leg);
assertReg(!empty($prereqLegacy['allowed']), 'Paanyaya::checkSummonsPrerequisite preserves legacy fallback only for non-prepared legacy records');

// Concurrent/duplicate docketing idempotency
$complaintModelReg = new Complaint($db);
$docketA = $complaintModelReg->docket($comp10, $clerkId);
$docketB = $complaintModelReg->docket($comp10, $clerkId);
$docketC = $paaModel->escalateToFormalDocketing($comp10, $clerkId);
assertReg(
    !empty($docketA['success']) && !empty($docketB['success']) && !empty($docketC['success'])
    && (int) $docketA['case_id'] === (int) $docketB['case_id']
    && (int) $docketA['case_id'] === (int) $docketC['case_id']
    && (string) $docketA['case_number'] === (string) $docketB['case_number']
    && (int) $db->query("SELECT COUNT(*) FROM cases WHERE complaint_id = {$comp10}")->fetchColumn() === 1,
    'Retrying or duplicate formal docketing returns the exact same single case_id and case_number'
);

$comp10CaseId = (int) $docketA['case_id'];
$previewMismatchedCase = regHttpRequest($baseUrl, $clerkSession, "summons/preview.php?complaint_id={$comp10}&case_id=" . ($comp10CaseId + 99999));
$issueHttpAfterDocket = regHttpRequest($baseUrl, $clerkSession, 'summons/issue.php', [
    'complaint_id' => $comp10,
    'case_id' => $comp10CaseId,
    'mediation_date' => $tue3,
    'mediation_time' => '15:30',
    'venue' => 'Lupon Court Room',
], true);
$previewValidCase = regHttpRequest($baseUrl, $clerkSession, "summons/preview.php?complaint_id={$comp10}&case_id={$comp10CaseId}&form_type=kp9");
assertReg(
    $previewMismatchedCase['status'] >= 400
    && $issueHttpAfterDocket['status'] === 200
    && $previewValidCase['status'] === 200,
    'First summons issuance & preview reject mismatched case_id and succeed once formal case is docketed (issue=' . $issueHttpAfterDocket['status'] . ', preview=' . $previewValidCase['status'] . ')'
);

// =========================================================================
// SCENARIO 11: Order 2.1 & 2.2 — Qualifying Sessions, Exclusions & Extensions
// =========================================================================
echo "\n--- SCENARIO 11: Order 2.1 & 2.2 Referral Eligibility, Exclusions & Extensions ---\n";

$luponPanelIds = $db->query("SELECT u.user_id FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Lupon Member' AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
if (count($luponPanelIds) < 3) {
    $luponPanelIds = $db->query("SELECT user_id FROM users WHERE status = 'Active' ORDER BY user_id ASC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
}
$pChair = (int) $luponPanelIds[0];
$pSec = (int) $luponPanelIds[1];
$pMem = (int) $luponPanelIds[2];

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at) VALUES (?, 'Reg11 Mediation Referral Rules', 'Mediation', ?, 'Civil', CURDATE(), 'Mediation referral rules', ?, NOW())")
    ->execute(["CMP-REG11-{$token}", $catId, $clerkId]);
$comp11 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp11);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp11, $resComp, $comp11, $resResp1]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp11, "REG-011-{$token}"]);
$case11 = (int) $db->lastInsertId();

// Create 1st & 2nd Scheduled Mediation (HearingMinutes::save transitions to Completed), plus attendance-only 3rd, Cancelled 4th, and Rescheduled 5th
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, attendance_recorded_at, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Scheduled', NOW(), 1)")
    ->execute([$case11, $tue1 . ' 09:00:00']);
$h11_1 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, attendance_recorded_at, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Scheduled', NOW(), 2)")
    ->execute([$case11, $tue2 . ' 09:00:00']);
$h11_2 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, attendance_recorded_at, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Completed', NOW(), 2)")
    ->execute([$case11, $tue2 . ' 10:30:00']); // attendance only (no minutes)
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Cancelled', 2)")
    ->execute([$case11, $tue2 . ' 13:30:00']);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, attendance_recorded_at, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Rescheduled', NOW(), 2)")
    ->execute([$case11, $tue2 . ' 15:00:00']);
$h11_resched = (int) $db->lastInsertId();

$minutesModelReg = new HearingMinutes($db);
$save11_1 = $minutesModelReg->save([
    'hearing_id' => $h11_1,
    'session_type' => '1st Mediation',
    'session_outcome' => 'Continue Mediation',
    'status' => 'Finalized',
    'settlement_discussion_notes' => '1st Mediation held cleanly.',
], $clerkId);
$save11_2Draft = $minutesModelReg->save([
    'hearing_id' => $h11_2,
    'session_type' => '2nd Mediation',
    'session_outcome' => 'Failed',
    'status' => 'Draft',
    'settlement_discussion_notes' => 'Draft notes only.',
], $clerkId);
$db->prepare("INSERT INTO hearing_minutes (hearing_id, case_id, session_type, session_outcome, status, recorded_by) VALUES (?, ?, '2nd Mediation', 'Failed', 'Finalized', ?)")
    ->execute([$h11_resched, $case11, $clerkId]);

$eligBeforeFinalize = CaseStage::evaluateMediationReferralEligibility($db, $case11);
$eligAfterFinalizeCheck = null;
assertReg(
    empty($eligBeforeFinalize['eligible'])
    && (int) ($eligBeforeFinalize['completed_mediation_count'] ?? 0) === 1,
    'Draft minutes, attendance-only sessions, cancelled hearings, and rescheduled hearings do NOT unlock Pangkat referral'
);

// Finalize 2nd Mediation Failed -> Ready for Referral without auto-opening Conciliation
$finalize11_2 = $minutesModelReg->finalize($h11_2, $clerkId);
$case11AfterFin = $db->query("SELECT case_status FROM cases WHERE case_id = {$case11}")->fetchColumn();
$medStage11 = $db->query("SELECT stage_status FROM case_stages WHERE case_id = {$case11} AND stage_type = 'Mediation'")->fetchColumn();
$eligAfterFinalize = CaseStage::evaluateMediationReferralEligibility($db, $case11);
assertReg(
    !empty($finalize11_2['success'])
    && $case11AfterFin === 'Mediation'
    && $medStage11 === 'Ready for Referral'
    && !empty($eligAfterFinalize['eligible'])
    && (int) ($eligAfterFinalize['completed_mediation_count'] ?? 0) === 2,
    'Finalizing 2nd Mediation Failed keeps case in Mediation, marks stage Ready for Referral, and does not auto-open Conciliation'
);

// Deadline exception via mediation_commencements + mediation_pause_decisions
$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at) VALUES (?, 'Reg11 Pause Extension Policy', 'Mediation', ?, 'Civil', CURDATE(), 'Extension exception basis check', ?, NOW())")
    ->execute(["CMP-REG11EXT-{$token}", $catId, $clerkId]);
$comp11Ext = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp11Ext);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp11Ext, "REG-011EXT-{$token}"]);
$case11Ext = (int) $db->lastInsertId();
$start20Ago = date('Y-m-d', strtotime('-20 days'));
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Completed')")
    ->execute([$case11Ext, $start20Ago . ' 10:00:00']);
$h11Ext = (int) $db->lastInsertId();
$dl15 = MediationDeadlineService::calculateDeadline($start20Ago, 15);
$db->prepare("INSERT INTO mediation_commencements (case_id, hearing_id, first_actual_meeting_at, mediation_deadline_at, source, created_by) VALUES (?, ?, ?, ?, 'Attendance', ?)")
    ->execute([$case11Ext, $h11Ext, $start20Ago . ' 10:00:00', $dl15 . ' 00:00:00', $clerkId]);
$db->prepare("INSERT INTO mediation_pause_decisions (case_id, legal_basis, reason, decided_by, paused_at, resumed_at, extension_authorised, extension_days, extension_basis) VALUES (?, 'Force Majeure / Barangay Emergency', 'Typhoon suspension', ?, ?, ?, 1, 15, 'Barangay Emergency Proclamation No. 12')")
    ->execute([$case11Ext, $clerkId, date('Y-m-d H:i:s', strtotime('-10 days')), date('Y-m-d H:i:s', strtotime('-1 day'))]);

$eligWithExt = CaseStage::evaluateMediationReferralEligibility($db, $case11Ext);
$dlSvcReg = new MediationDeadlineService($db);
$case11ExtRow = $db->query("SELECT * FROM cases WHERE case_id = {$case11Ext}")->fetch(PDO::FETCH_ASSOC);
$dlStatusWithExt = $dlSvcReg->computeStatus($case11ExtRow);
assertReg(
    empty($dlStatusWithExt['is_lapsed']) && empty($eligWithExt['eligible']),
    'Authorized extension in mediation_pause_decisions shifts deadline cleanly and prevents premature lapse'
);

// =========================================================================
// SCENARIO 12: Order 2.3 — Atomic Referral, Pending Booking & Rollbacks
// =========================================================================
echo "\n--- SCENARIO 12: Order 2.3 Atomic Referral, Separate Booking & Rollback ---\n";

$stageModelReg = new CaseStage($db);

// 12a. Booking failure (Tuesday conflict) rolls back entire referral cleanly
$refTueFail = $stageModelReg->referToPangkat($case11, [
    'referral_date' => date('Y-m-d'),
    'referral_reason' => 'Should rollback on Tuesday conciliation booking',
    'chairman_id' => $pChair,
    'secretary_id' => $pSec,
    'member_id' => $pMem,
    'conciliation_date' => $tue3,
    'conciliation_time' => '10:00',
    'venue' => 'Lupon Court Room',
], $clerkId);
assertReg(
    empty($refTueFail['success'])
    && $db->query("SELECT case_status FROM cases WHERE case_id = {$case11}")->fetchColumn() === 'Mediation'
    && (int) $db->query("SELECT COUNT(*) FROM pangkat_groups WHERE case_id = {$case11}")->fetchColumn() === 0
    && (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case11}")->fetchColumn() === 0,
    'Booking failure on referToPangkat rolls back case status, Pangkat panel, and documents cleanly'
);

// 12b. Inject render failures through the same PDF path as a real referral.
$nextThu = nextWeekdayDate($logistics, 2, 4);
$firstBooking11 = [
    'referral_date' => date('Y-m-d'),
    'referral_reason' => 'Rollback when a required notice fails to render',
    'chairman_id' => $pChair,
    'secretary_id' => $pSec,
    'member_id' => $pMem,
    'conciliation_date' => $nextThu,
    'conciliation_time' => '10:00',
    'venue' => 'Lupon Court Room',
];
$referralPdfPattern = $root . "/storage/documents/kp-forms/{$case11}/*.pdf";
$pdfsBeforeReferral = glob($referralPdfPattern) ?: [];
foreach (['KP Form 10', 'KP Form 11', 'KP Form 12'] as $failedForm) {
    $failingStageModel = new class($db, $failedForm) extends CaseStage {
        public function __construct(private PDO $testConnection, private string $failedForm)
        {
            parent::__construct($testConnection);
        }

        protected function createKpFormService(): KpFormService
        {
            return new class($this->testConnection, $this->failedForm) extends KpFormService {
                public function __construct(PDO $connection, private string $failedForm)
                {
                    parent::__construct($connection);
                }

                public function generatePdf(string $formCode, int $caseId, ?int $hearingId = null, ?string $outputPath = null, ?int $residentId = null): string
                {
                    if ($formCode === $this->failedForm) {
                        file_put_contents($outputPath, '%PDF-1.4 partial output');
                        throw new RuntimeException("Simulated {$formCode} renderer failure");
                    }
                    return parent::generatePdf($formCode, $caseId, $hearingId, $outputPath, $residentId);
                }
            };
        }
    };

    $testOuterTransaction = $failedForm === 'KP Form 12';
    if ($testOuterTransaction) {
        $db->beginTransaction();
    }
    try {
        $refDocFail = $failingStageModel->referToPangkat($case11, $firstBooking11, $clerkId);
        assertReg(
            empty($refDocFail['success'])
            && str_contains($refDocFail['message'] ?? '', "Simulated {$failedForm} renderer failure")
            && $db->inTransaction() === $testOuterTransaction
            && $db->query("SELECT case_status FROM cases WHERE case_id = {$case11}")->fetchColumn() === 'Mediation'
            && (int) $db->query("SELECT COUNT(*) FROM pangkat_groups WHERE case_id = {$case11}")->fetchColumn() === 0
            && (int) $db->query("SELECT COUNT(*) FROM hearings WHERE case_id = {$case11} AND hearing_type = 'Conciliation'")->fetchColumn() === 0
            && (int) $db->query("SELECT COUNT(*) FROM generated_documents WHERE case_id = {$case11}")->fetchColumn() === 0
            && (glob($referralPdfPattern) ?: []) === $pdfsBeforeReferral,
            "{$failedForm} rendering failure rolls back the referral and removes partial and earlier PDFs"
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

// 12c. Separate booking keeps Conciliation visibly pending until saved, then duplicate referral is rejected
$commenced11 = date('Y-m-d', strtotime('-22 days'));
$due11 = MediationDeadlineService::calculateDeadline($commenced11, MediationDeadlineService::MEDIATION_PERIOD_DAYS);
$db->prepare("INSERT INTO mediation_commencements (case_id, hearing_id, first_actual_meeting_at, mediation_deadline_at, source, created_by)
    VALUES (?, ?, ?, ?, 'Attendance', ?)")->execute([$case11, $h11_1, $commenced11 . ' 09:00:00', $due11 . ' 00:00:00', $clerkId]);
$seedDeadline11 = $db->prepare("INSERT INTO case_deadlines (case_id, deadline_type, due_date, status) VALUES (?, ?, ?, ?)");
$seedDeadline11->execute([$case11, 'Mediation Period', $due11, 'Pending']);
$medDeadlineId11 = (int) $db->lastInsertId();
$seedDeadline11->execute([$case11, '15-Day Mediation Deadline', $due11, 'Overdue']);
$legacyDeadlineId11 = (int) $db->lastInsertId();
$seedDeadline11->execute([$case11, 'Initial Hearing', $due11, 'Pending']);
$unrelatedDeadlineId11 = (int) $db->lastInsertId();

$refSeparate = $stageModelReg->referToPangkat($case11, [
    'referral_date' => date('Y-m-d'),
    'referral_reason' => 'Explicit referral with separate Conciliation booking.',
    'chairman_id' => $pChair,
    'secretary_id' => $pSec,
    'member_id' => $pMem,
], $clerkId);
$conOverviewPending = $stageModelReg->getStageOverview($case11)['conciliation'] ?? [];
$dupReferral = $stageModelReg->referToPangkat($case11, [
    'referral_date' => date('Y-m-d'),
    'referral_reason' => 'Duplicate referral attempt',
    'chairman_id' => $pChair,
    'secretary_id' => $pSec,
    'member_id' => $pMem,
], $clerkId);
assertReg(
    !empty($refSeparate['success'])
    && !empty($refSeparate['is_booking_pending'])
    && ($conOverviewPending['stage_status'] ?? '') === 'Not Started'
    && !empty($conOverviewPending['is_booking_pending'])
    && ($conOverviewPending['booking_status'] ?? '') === 'Pending Scheduling'
    && empty($refSeparate['hearing_id'])
    && !empty($refSeparate['kp10_document_id'])
    && empty($dupReferral['success']) && !empty($dupReferral['already_referred'])
    && (int) $db->query("SELECT COUNT(*) FROM pangkat_groups WHERE case_id = {$case11}")->fetchColumn() === 1,
    'Separate booking keeps Conciliation visibly Pending Scheduling until booked and rejects duplicate referrals'
);

$deadlineState11 = $db->prepare("SELECT status, completed_at, due_date FROM case_deadlines WHERE deadline_id = ?");
$deadlineState11->execute([$medDeadlineId11]);
$closedDeadline11 = $deadlineState11->fetch(PDO::FETCH_ASSOC);
$deadlineState11->execute([$legacyDeadlineId11]);
$closedLegacyDeadline11 = $deadlineState11->fetch(PDO::FETCH_ASSOC);
$deadlineState11->execute([$unrelatedDeadlineId11]);
$unrelatedDeadline11 = $deadlineState11->fetch(PDO::FETCH_ASSOC);
assertReg(
    ($closedDeadline11['status'] ?? null) === 'Completed' && !empty($closedDeadline11['completed_at'])
    && ($closedLegacyDeadline11['status'] ?? null) === 'Completed' && !empty($closedLegacyDeadline11['completed_at'])
    && $closedDeadline11['due_date'] === $due11 && $closedLegacyDeadline11['due_date'] === $due11
    && ($unrelatedDeadline11['status'] ?? null) === 'Pending',
    'A successful referral completes both Mediation deadline types even when booking is deferred'
);

// Older referred cases can still carry open Mediation deadline rows.
$db->prepare("UPDATE case_deadlines SET status = 'Pending', completed_at = NULL WHERE deadline_id IN (?, ?)")->execute([$medDeadlineId11, $legacyDeadlineId11]);
(new DeadlineAlertService())->dispatch($clerkId);
$alertCount11 = $db->prepare("SELECT COUNT(*) FROM deadline_alert_log WHERE deadline_id = ?");
$alertCount11->execute([$medDeadlineId11]);
$medAlerts11 = (int) $alertCount11->fetchColumn();
$alertCount11->execute([$legacyDeadlineId11]);
$legacyAlerts11 = (int) $alertCount11->fetchColumn();
$alertCount11->execute([$unrelatedDeadlineId11]);
assertReg(
    $medAlerts11 === 0 && $legacyAlerts11 === 0 && (int) $alertCount11->fetchColumn() === 1,
    'Alert dispatch skips stale Mediation deadlines after referral and still processes unrelated deadlines'
);

// =========================================================================
// SCENARIO 13: Order 2.4 — Rescheduling Before & After Commencement Anchor
// =========================================================================
echo "\n--- SCENARIO 13: Order 2.4 Pre/Post-Commencement Deadline Anchor ---\n";

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at) VALUES (?, 'Reg13 Commencement Anchor', 'Mediation', ?, 'Civil', CURDATE(), 'Deadline anchor before and after commencement', ?, NOW())")
    ->execute(["CMP-REG13-{$token}", $catId, $clerkId]);
$comp13 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp13);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp13, $resComp, $comp13, $resResp1]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp13, "REG-013-{$token}"]);
$case13 = (int) $db->lastInsertId();

$tueList13 = [];
$probe13 = new DateTime('now');
for ($i = 0; $i < 90 && count($tueList13) < 3; $i++) {
    $probe13->modify('+1 day');
    if ((int) $probe13->format('w') === 2 && $logistics->isPhilippineHoliday($probe13) === null && $i >= 18) {
        $tueList13[] = $probe13->format('Y-m-d');
    }
}
[$tue4, $tue5, $tue6] = $tueList13;

$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Court Room', 'Scheduled', 1)")
    ->execute([$case13, $tue4 . ' 14:00:00']);
$h13_1 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, attempt_number, form_type, delivery_status, recipient_name, relationship, served_at, served_by, created_at) VALUES (?, ?, 'Respondent', ?, 1, 'KP Form 9', 'Served Personal', 'Respondent', 'Self', NOW(), ?, NOW())")
    ->execute([$case13, $h13_1, $resResp1, $clerkId]);

// Reschedule BEFORE commencement: deadline remains NULL, not lapsed, referral locked
$reschedPre = $docketService->rescheduleItem([
    'confirmed' => true,
    'item_type' => 'hearing',
    'item_id' => $h13_1,
    'target_date' => $tue5,
    'target_time' => '14:00',
    'venue' => 'Lupon Court Room',
    'reschedule_reason' => 'Pre-commencement move check',
    'force_reschedule' => true,
], $clerkId, (int) $clerkSession['role_id']);
$case13Pre = $db->query("SELECT * FROM cases WHERE case_id = {$case13}")->fetch(PDO::FETCH_ASSOC);
$status13Pre = $dlSvcReg->computeStatus($case13Pre);
$elig13Pre = CaseStage::evaluateMediationReferralEligibility($db, $case13);
assertReg(
    !empty($reschedPre['success'])
    && $case13Pre['mediation_deadline_date'] === null
    && empty($status13Pre['is_lapsed'])
    && empty($elig13Pre['eligible']),
    'Rescheduling before commencement leaves statutory deadline unset, not lapsed, and referral locked (got: ' . ($reschedPre['message'] ?? 'err') . ')'
);

// Record commencement (first actual meeting 20 days ago -> 15-day period elapsed) then reschedule 2nd Mediation hearing
$commDate13 = date('Y-m-d', strtotime('-20 days'));
$commDl13 = MediationDeadlineService::calculateDeadline($commDate13, 15);
$db->prepare("UPDATE hearings SET status = 'Completed', hearing_date = ? WHERE hearing_id = ?")
    ->execute([$commDate13 . ' 10:00:00', $h13_1]);
$db->prepare("INSERT INTO mediation_commencements (case_id, hearing_id, first_actual_meeting_at, mediation_deadline_at, source, created_by) VALUES (?, ?, ?, ?, 'Attendance', ?)")
    ->execute([$case13, $h13_1, $commDate13 . ' 10:00:00', $commDl13 . ' 00:00:00', $clerkId]);
$db->prepare("UPDATE cases SET mediation_start_date = ?, mediation_deadline_date = ? WHERE case_id = ?")
    ->execute([$commDate13, $commDl13, $case13]);

$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Court Room', 'Scheduled', 2)")
    ->execute([$case13, $tue5 . ' 15:00:00']);
$h13_2 = (int) $db->lastInsertId();
$reschedPost = $docketService->rescheduleItem([
    'confirmed' => true,
    'item_type' => 'hearing',
    'item_id' => $h13_2,
    'target_date' => $tue6,
    'target_time' => '15:00',
    'venue' => 'Lupon Court Room',
    'reschedule_reason' => 'Post-commencement move check',
    'force_reschedule' => true,
], $clerkId, (int) $clerkSession['role_id']);
$case13Post = $db->query("SELECT mediation_start_date, mediation_deadline_date FROM cases WHERE case_id = {$case13}")->fetch(PDO::FETCH_ASSOC);
$elig13Post = CaseStage::evaluateMediationReferralEligibility($db, $case13);
$replacementCount13 = (int) $db->query("SELECT COUNT(*) FROM summon_deliveries WHERE hearing_id = {$h13_2} AND delivery_status <> 'Superseded'")->fetchColumn();

assertReg(
    !empty($reschedPost['success'])
    && $case13Post['mediation_start_date'] === $commDate13
    && $case13Post['mediation_deadline_date'] === $commDl13
    && $replacementCount13 >= 2
    && !empty($elig13Post['eligible'])
    && ($elig13Post['basis'] ?? '') === 'statutory_deadline_lapsed',
    'Rescheduling after commencement preserves first_actual_meeting_at deadline anchor, issues replacement notices, and unlocks lapsed-period referral (post=' . ($reschedPost['message'] ?? 'err') . ', notices=' . $replacementCount13 . ', basis=' . ($elig13Post['basis'] ?? 'none') . ')'
);

// =========================================================================
// [14] Latest Qualifying Outcome, Show-Cause/Cancelled Follow-ups & Arbitration Exclusion
// =========================================================================
echo "\n[14] Testing latest qualifying outcome, Arbitration rejection, and show-cause/cancelled follow-ups...\n";
$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, narrative, category_id, status, case_type, incident_date, encoded_by, created_at) VALUES (?, 'Referral Rule Check', 'Test', ?, 'Mediation', 'Civil', CURDATE(), ?, NOW())")
    ->execute(["CMP-REG14-{$token}", $catId, $clerkId]);
$comp14 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp14);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp14, $resComp, $comp14, $resResp1]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp14, "CASE-REG14-{$token}"]);
$case14 = (int) $db->lastInsertId();

$tue14a = nextWeekdayDate($logistics, 2, 2);
$tue14b = nextWeekdayDate($logistics, 9, 2);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Court Room', 'Completed', 1), (?, 'Mediation', ?, 'Lupon Court Room', 'Completed', 2)")
    ->execute([$case14, $tue14a . ' 13:00:00', $case14, $tue14b . ' 14:00:00']);
$h14Rows = $db->query("SELECT hearing_id FROM hearings WHERE case_id = {$case14} ORDER BY hearing_id ASC")->fetchAll(PDO::FETCH_COLUMN);
$h14_1 = (int) $h14Rows[0];
$h14_2 = (int) $h14Rows[1];

// 14a: Linked Show Cause hearing & Cancelled replacement on Session 1 must NOT strip Session 1 countability
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id) VALUES (?, 'Show Cause (Respondent)', ?, 'Lupon Court Room', 'Completed', ?), (?, 'Mediation', ?, 'Lupon Court Room', 'Office Cancelled', ?)")
    ->execute([$case14, $tue14a . ' 15:00:00', $h14_1, $case14, $tue14a . ' 16:00:00', $h14_1]);

$db->prepare("INSERT INTO hearing_minutes (hearing_id, case_id, session_type, session_outcome, status, recorded_by, created_at) VALUES (?, ?, '1st Mediation', 'Failed', 'Finalized', ?, NOW()), (?, ?, '2nd Mediation', 'Continue Mediation', 'Finalized', ?, NOW())")
    ->execute([$h14_1, $case14, $clerkId, $h14_2, $case14, $clerkId]);

$elig14Continue = CaseStage::evaluateMediationReferralEligibility($db, $case14);
$stageModel14 = new CaseStage();
$luponPanel = $db->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.role_name = 'Lupon Member' AND u.status = 'Active' ORDER BY u.user_id ASC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
$panelPayload14 = [
    'chairman_id' => (int) ($luponPanel[0] ?? 1),
    'secretary_id' => (int) ($luponPanel[1] ?? 2),
    'member_id' => (int) ($luponPanel[2] ?? 3),
    'referral_reason' => 'Attempted premature referral when latest session was Continue Mediation',
];
$ref14Continue = $stageModel14->referToPangkat($case14, $panelPayload14, $clerkId);
assertReg(
    (int) ($elig14Continue['completed_mediation_count'] ?? 0) === 2
    && empty($elig14Continue['eligible'])
    && empty($elig14Continue['has_finalized_unsuccessful'])
    && empty($ref14Continue['success']),
    '1st Failed + 2nd Continue Mediation leaves Pangkat referral locked while keeping Show Cause / Cancelled follow-ups non-superseding'
);

// 14b: Change 2nd minutes to Failed AND set Arbitration -> Arbitration must reject referToPangkat()
$db->prepare("UPDATE hearing_minutes SET session_outcome = 'Failed' WHERE hearing_id = ?")->execute([$h14_2]);
$elig14Ready = CaseStage::evaluateMediationReferralEligibility($db, $case14);
assertReg(
    !empty($elig14Ready['eligible']) && ($elig14Ready['latest_qualifying_outcome'] ?? '') === 'Failed',
    'Latest qualifying outcome Failed unlocks Pangkat referral'
);
$db->prepare("UPDATE cases SET case_status = 'Arbitration' WHERE case_id = ?")->execute([$case14]);
$elig14Arb = CaseStage::evaluateMediationReferralEligibility($db, $case14);
$ref14Arb = $stageModel14->referToPangkat($case14, $panelPayload14, $clerkId);
assertReg(
    empty($elig14Arb['eligible'])
    && !empty($elig14Arb['is_arbitration'])
    && empty($ref14Arb['success']),
    'Cases in Arbitration are rejected from Pangkat referral even under lock'
);

$db->prepare("UPDATE cases SET case_status = 'RESPONDENT_DEFAULT' WHERE case_id = ?")->execute([$case14]);
$db->prepare("INSERT INTO cfa_records (case_id, cfa_type, issuance_date, reason)
    VALUES (?, 'KP Form 20-B', CURDATE(), 'Counterclaim bar recorded before referral request')")->execute([$case14]);
$issuedBar14 = (int) $db->lastInsertId();
$elig14Default = CaseStage::evaluateMediationReferralEligibility($db, $case14);
$ref14Default = $stageModel14->referToPangkat($case14, $panelPayload14, $clerkId);
assertReg(
    empty($elig14Default['eligible']) && empty($ref14Default['success'])
    && $db->query("SELECT case_status FROM cases WHERE case_id = {$case14}")->fetchColumn() === 'RESPONDENT_DEFAULT'
    && $db->query("SELECT cfa_type FROM cfa_records WHERE cfa_id = {$issuedBar14}")->fetchColumn() === 'KP Form 20-B'
    && (int) $db->query("SELECT COUNT(*) FROM pangkat_groups WHERE case_id = {$case14}")->fetchColumn() === 0,
    'A recorded KP Form 20-B and respondent-default case remain intact when referral is attempted'
);
$db->prepare("DELETE FROM cfa_records WHERE cfa_id = ?")->execute([$issuedBar14]);

// =========================================================================
// [15] Pre-commencement & Post-commencement Deadlines + DeadlineAlertService + Pause Extensions
// =========================================================================
echo "\n[15] Testing pre-commencement pending deadlines, provisional row handling, alerts, and pause extensions...\n";
$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, narrative, category_id, status, case_type, incident_date, encoded_by, created_at) VALUES (?, 'Deadline Consistency Check', 'Test', ?, 'Mediation', 'Civil', CURDATE(), ?, NOW())")
    ->execute(["CMP-REG15-{$token}", $catId, $clerkId]);
$comp15 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp15);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp15, $resComp, $comp15, $resResp1]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp15, "CASE-REG15-{$token}"]);
$case15 = (int) $db->lastInsertId();

$hearingModel15 = new Hearing($db);
$tue15a = nextWeekdayDate($logistics, 3, 2);
$tue15b = nextWeekdayDate($logistics, 10, 2);
$create15 = $hearingModel15->createProgression([
    'case_id' => $case15,
    'hearing_type' => 'Mediation',
    'hearing_date' => $tue15a . ' 13:30:00',
    'venue' => 'Lupon Court Room',
    'duration_minutes' => 45,
], null, $clerkId);
$medDlRows15 = (int) $db->query("SELECT COUNT(*) FROM case_deadlines WHERE case_id = {$case15} AND deadline_type = 'Mediation Period'")->fetchColumn();
$case15Pre = $db->query("SELECT mediation_start_date, mediation_deadline_date FROM cases WHERE case_id = {$case15}")->fetch(PDO::FETCH_ASSOC);
assertReg(
    !empty($create15['success']) && $medDlRows15 === 0 && $case15Pre['mediation_deadline_date'] === null,
    'Booking pre-commencement Mediation creates zero Mediation Period rows in case_deadlines and leaves mediation_deadline_date NULL'
);

// Inject a stale provisional overdue 'Mediation Period' row + an unrelated future 'Pangkat Convening' row
$db->prepare("INSERT INTO case_deadlines (case_id, deadline_type, due_date, status) VALUES (?, 'Mediation Period', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Pending'), (?, 'Pangkat Convening', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Pending')")
    ->execute([$case15, $case15]);
$alertSvc15 = new DeadlineAlertService();
$alertSvc15->dispatch($clerkId);
$medAlertCnt15 = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE case_id = {$case15} AND title LIKE '%Mediation Deadline%'")->fetchColumn();
$medDlAfterDisp15 = (int) $db->query("SELECT COUNT(*) FROM case_deadlines WHERE case_id = {$case15} AND deadline_type = 'Mediation Period'")->fetchColumn();
$unrelatedAfterDisp15 = (int) $db->query("SELECT COUNT(*) FROM case_deadlines WHERE case_id = {$case15} AND deadline_type = 'Pangkat Convening'")->fetchColumn();
assertReg(
    $medAlertCnt15 === 0 && $medDlAfterDisp15 === 0 && $unrelatedAfterDisp15 === 1,
    'DeadlineAlertService sends zero procedural alerts for provisional uncommenced Mediation Period and preserves unrelated deadlines'
);

// Commence 16 days ago (would have lapsed) then pause + resume with +5 day authorized extension -> extended to +4 days from today
$commDate15 = date('Y-m-d', strtotime('-16 days'));
$baseDl15 = MediationDeadlineService::calculateDeadline($commDate15, MediationDeadlineService::MEDIATION_PERIOD_DAYS);
$db->prepare("INSERT INTO mediation_commencements (case_id, hearing_id, first_actual_meeting_at, mediation_deadline_at, source, created_by) VALUES (?, ?, ?, ?, 'Attendance', ?)")
    ->execute([$case15, (int) $create15['hearing_id'], $commDate15 . ' 13:30:00', $baseDl15 . ' 00:00:00', $clerkId]);
$db->prepare("INSERT INTO mediation_pause_decisions (case_id, legal_basis, reason, notes, decided_by, paused_at, resumed_at, extension_authorised, extension_days, extension_basis) VALUES (?, 'Force Majeure', 'Typhoon suspension', 'Extended 5 days', ?, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 1, 5, 'Typhoon clearance')")
    ->execute([$case15, $clerkId]);
$extDeadline15 = $dlSvcReg->syncCommencedDeadlines($case15);
$expectedExtDl15 = date('Y-m-d', strtotime($commDate15 . ' +20 days'));
$timer15Ext = $dlSvcReg->computeStatus($db->query("SELECT * FROM cases WHERE case_id = {$case15}")->fetch(PDO::FETCH_ASSOC));
$elig15Ext = CaseStage::evaluateMediationReferralEligibility($db, $case15);
$alertSvc15->dispatch($clerkId);
$medAlertExtCnt15 = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE case_id = {$case15} AND title LIKE '%Mediation Deadline%'")->fetchColumn();
assertReg(
    $extDeadline15 === $expectedExtDl15
    && empty($timer15Ext['is_lapsed'])
    && empty($elig15Ext['eligible'])
    && $medAlertExtCnt15 === 0,
    'Commenced Mediation uses saved first_actual_meeting_at + authorized extension for timer, case_deadlines, alerts, and referral lock (expected=' . $expectedExtDl15 . ', got=' . ($extDeadline15 ?? 'null') . ')'
);

// =========================================================================
// [16] Shared Policy Cap (mediation_max_sessions=1) & Dynamic Conciliation Settings
// =========================================================================
echo "\n[16] Testing mediation_max_sessions=1 policy alignment & non-default Conciliation settings...\n";
$savedSettingsStmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('mediation_max_sessions', 'conciliation_default_duration_min', 'default_hearing_venue', 'working_hours_start', 'working_hours_end')");
$origSettings = $savedSettingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$upsertSetting16 = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
try {
    $upsertSetting16->execute(['mediation_max_sessions', '1']);
    $policy1 = MediationDeadlineService::getMediationPolicy($db);
    assertReg(
        $policy1['configured_max_sessions'] === 1
        && $policy1['referral_required_sessions'] === 2
        && !empty($policy1['has_policy_conflict']),
        'Shared mediation policy reports conflict when mediation_max_sessions=1 vs approved 2-session referral rule'
    );

    // Check Hearing::createProgression stops at 1 session when mediation_max_sessions=1
    $tue16 = nextWeekdayDate($logistics, 16, 2);
    $db->prepare("INSERT INTO complaints (complaint_number, complaint_title, narrative, category_id, status, case_type, incident_date, encoded_by, created_at) VALUES (?, 'Cap Check', 'Test', ?, 'Mediation', 'Civil', CURDATE(), ?, NOW())")
        ->execute(["CMP-REG16-{$token}", $catId, $clerkId]);
    $comp16 = (int) $db->lastInsertId();
    $cleanup->trackComplaint($comp16);
    $db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
        ->execute([$comp16, "CASE-REG16-{$token}"]);
    $case16 = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, mediation_attempt) VALUES (?, 'Mediation', ?, 'Lupon Court Room', 'Completed', 1)")
        ->execute([$case16, $tue16 . ' 13:30:00']);
    $h16_1 = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO hearing_minutes (hearing_id, case_id, session_type, session_outcome, status, recorded_by, created_at) VALUES (?, ?, '1st Mediation', 'Continue Mediation', 'Finalized', ?, NOW())")
        ->execute([$h16_1, $case16, $clerkId]);
    $secondCapRes = $hearingModel15->createProgression([
        'case_id' => $case16,
        'hearing_type' => 'Mediation',
        'hearing_date' => nextWeekdayDate($logistics, 23, 2) . ' 14:00:00',
        'venue' => 'Lupon Court Room',
    ], null, $clerkId);
    $elig16 = CaseStage::evaluateMediationReferralEligibility($db, $case16);
    assertReg(
        empty($secondCapRes['success']) && !empty($elig16['policy_conflict']) && empty($elig16['eligible']),
        'Hearing::createProgression honors mediation_max_sessions=1 while evaluateMediationReferralEligibility surfaces policy conflict and preserves 2-session threshold'
    );

    // Non-default Conciliation duration (90 min) & venue + hours alignment between check-schedule.php and referToPangkat()
    $upsertSetting16->execute(['conciliation_default_duration_min', '90']);
    $upsertSetting16->execute(['default_hearing_venue', 'Annex Mediation Hall']);
    $wed16 = nextWeekdayDate($logistics, 4, 3);
    $schedResp = regHttpRequest($baseUrl, $clerkSession, "summons/check-schedule.php?date={$wed16}&time=11:00&hearing_type=Conciliation&get_window=1");
    $schedSlots = $schedResp['json']['conciliation_slots'] ?? [];
    $has1100Slot = false;
    foreach ($schedSlots as $sl) {
        if (($sl['time'] ?? '') === '11:00') {
            $has1100Slot = true;
        }
    }
    assertReg(
        $schedResp['status'] === 200
        && ($schedResp['json']['default_venue'] ?? '') === 'Annex Mediation Hall'
        && !empty($schedResp['json']['has_conflict'])
        && !$has1100Slot,
        'check-schedule.php honors 90-min Conciliation duration (rejects 11:00 crossing 12:00 lunch) and default_hearing_venue (venue=' . ($schedResp['json']['default_venue'] ?? 'null') . ', conflict=' . json_encode($schedResp['json']['has_conflict'] ?? null) . ')'
    );

    // Prepare case ready for referral (use $case14 after restoring case_status='Mediation')
    $db->prepare("UPDATE cases SET case_status = 'Mediation' WHERE case_id = ?")->execute([$case14]);
    $refBadLunch = $stageModel14->referToPangkat($case14, array_merge($panelPayload14, [
        'conciliation_date' => $wed16,
        'conciliation_time' => '11:00',
    ]), $clerkId);
    $refOkCustom = $stageModel14->referToPangkat($case14, array_merge($panelPayload14, [
        'conciliation_date' => $wed16,
        'conciliation_time' => '09:30',
    ]), $clerkId);
    $conHearing14 = $db->query("SELECT venue, duration_minutes FROM hearings WHERE case_id = {$case14} AND hearing_type = 'Conciliation' ORDER BY hearing_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertReg(
        empty($refBadLunch['success'])
        && !empty($refOkCustom['success'])
        && ($conHearing14['venue'] ?? '') === 'Annex Mediation Hall'
        && (int) ($conHearing14['duration_minutes'] ?? 0) === 90,
        'CaseStage::referToPangkat enforces 90-min duration & lunch boundary consistently with check-schedule.php and saves configured venue + duration (okMsg=' . ($refOkCustom['message'] ?? '') . ')'
    );
} finally {
    foreach (['mediation_max_sessions', 'conciliation_default_duration_min', 'default_hearing_venue', 'working_hours_start', 'working_hours_end'] as $k) {
        if (array_key_exists($k, $origSettings)) {
            $upsertSetting16->execute([$k, $origSettings[$k]]);
        } else {
            $db->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        }
    }
}

// =========================================================================
// [17] Prepared Paanyaya quick-action log_paanyaya_outcome Requires Explicit Dialogue Date & Attendance
// =========================================================================
echo "\n[17] Testing Clerk Agenda prepared Paanyaya outcome rejects missing/invalid dialogue_date and attendance...\n";
$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, narrative, category_id, status, case_type, screening_decision, liso_assigned_to, liso_reviewed_by, liso_reviewed_at, incident_date, encoded_by, created_at) VALUES (?, 'Prepared QA Check', 'Test', ?, 'Accepted', 'Civil', 'Eligible', ?, ?, NOW(), CURDATE(), ?, NOW())")
    ->execute(["CMP-REG17-{$token}", $catId, $lisoReviewerId, $lisoReviewerId, $clerkId]);
$comp17 = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp17);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp17, $resComp, $comp17, $resResp1]);
$prepSvc17 = new PaanyayaPreparationService($db);
$prepSvc17->save($comp17, $clerkId, 'generate', [
    'appointment_date' => nextWeekdayDate($logistics, 2, 1),
    'appointment_time' => '09:00',
    'venue' => 'Lupon Court Room',
    'verify_parties' => '1',
    'party_version' => PaanyayaPreparationService::partyVersion($prepSvc17->parties($comp17)),
], 1);
$ver17 = (string) ($prepSvc17->intake($comp17, 1)['document_version'] ?? '');
$prepSvc17->save($comp17, $clerkId, 'ready', ['document_version' => $ver17], 1);
$paaDeliverySvc->release($comp17, $clerkId, $paaServerId, $ver17, 1);
$att17 = PaanyayaServiceDelivery::latestAttempt($db, $comp17, 1);
$paaDeliverySvc->record((int) $att17['attempt_id'], $paaServerId, ['outcome' => 'Delivered', 'notes' => 'Delivered for QA check'], []);
$sess17Id = (int) $db->query("SELECT session_id FROM paanyaya_sessions WHERE complaint_id = {$comp17} ORDER BY session_id DESC LIMIT 1")->fetchColumn();

$qaMissingDate = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'log_paanyaya_outcome',
    'complaint_id' => $comp17,
    'session_id' => $sess17Id,
    'invitation_number' => 1,
    'document_version' => $ver17,
    'outcome' => 'Escalated to Mediation',
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
    'discussion_summary' => 'Attempted without dialogue_date',
]);
$qaMissingAtt = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'log_paanyaya_outcome',
    'complaint_id' => $comp17,
    'session_id' => $sess17Id,
    'invitation_number' => 1,
    'document_version' => $ver17,
    'outcome' => 'Settled',
    'dialogue_date' => date('Y-m-d\TH:i'),
    'discussion_summary' => 'Attempted without attendance',
    'agreed_terms' => 'Terms agreed',
]);
$qaImpossibleDate = regHttpRequest($baseUrl, $clerkSession, 'dashboard/quick-action.php', [
    'action' => 'log_paanyaya_outcome',
    'complaint_id' => $comp17,
    'session_id' => $sess17Id,
    'invitation_number' => 1,
    'document_version' => $ver17,
    'outcome' => 'Escalated to Mediation',
    'dialogue_date' => (date('Y') - 1) . '-02-30T10:00',
    'complainant_attended' => 'Present',
    'respondent_attended' => 'Present',
    'discussion_summary' => 'Attempted with a nonexistent calendar date',
]);
assertReg(
    $qaImpossibleDate['status'] === 422
    && !empty($qaImpossibleDate['json']['requires_dialogue_form'])
    && str_contains($qaImpossibleDate['json']['message'] ?? '', 'A valid actual dialogue date and time')
    && str_contains($qaImpossibleDate['json']['dialogue_url'] ?? '', '#paanyaya-dialogue-1'),
    'The Paanyaya shortcut sends staff back to the dialogue form when the calendar date is impossible'
);
$pmCnt17 = (int) $db->query("SELECT COUNT(*) FROM paanyaya_minutes WHERE complaint_id = {$comp17}")->fetchColumn();
assertReg(
    $qaMissingDate['status'] === 422
    && !empty($qaMissingDate['json']['requires_dialogue_form'])
    && str_contains((string) ($qaMissingDate['json']['dialogue_url'] ?? ''), '#paanyaya-dialogue-1')
    && $qaMissingAtt['status'] === 422
    && !empty($qaMissingAtt['json']['requires_dialogue_form'])
    && $pmCnt17 === 0,
    'log_paanyaya_outcome rejects missing dialogue_date or missing party attendance with HTTP 422 + requires_dialogue_form and leaves zero DB writes (d=' . $qaMissingDate['status'] . ':' . $qaMissingDate['body'] . ', a=' . $qaMissingAtt['status'] . ':' . $qaMissingAtt['body'] . ')'
);

// =========================================================================
// [18] Orders 3A & 3B: Differential Co-Respondent Show-Cause, Stale Notice Rejection,
//      Conciliation Impasse Evidence Gate, Repudiation -> CFA, and PDF Recovery
// =========================================================================
echo "\n[18] Testing Orders 3A & 3B (co-respondents, stale notice, repudiation-to-CFA, PDF recovery)...\n";

$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Order 3A Co-Respondent Show-Cause', 'Mediation', ?, 'Civil', CURDATE(), 'Multi-respondent show cause test', ?, NOW())")
    ->execute(["CMP-REG18A-{$token}", $catId, $clerkId]);
$comp18a = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp18a);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$comp18a, $resComp, $comp18a, $resResp1, $comp18a, $resResp2]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Mediation', CURDATE(), NOW())")
    ->execute([$comp18a, "REG-18A-{$token}"]);
$case18a = (int) $db->lastInsertId();

$tue18a = nextWeekdayDate($logistics, 1, 2);
$tue18b = nextWeekdayDate($logistics, 8, 2);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Mediation', ?, 'Lupon Hall', 'Completed')")
    ->execute([$case18a, "{$tue18a} 09:00:00"]);
$h18aOrig = (int) $db->lastInsertId();
// Verified original service for both respondents
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, form_type, delivery_status, served_at)
    VALUES (?, ?, 'Respondent', ?, 'KP Form 9', 'Served Personal', NOW()), (?, ?, 'Respondent', ?, 'KP Form 9', 'Served Personal', NOW())")
    ->execute([$case18a, $h18aOrig, $resResp1, $case18a, $h18aOrig, $resResp2]);
$origDel18R1 = (int) $db->query("SELECT delivery_id FROM summon_deliveries WHERE hearing_id = {$h18aOrig} AND resident_id = {$resResp1}")->fetchColumn();
$origDel18R2 = (int) $db->query("SELECT delivery_id FROM summon_deliveries WHERE hearing_id = {$h18aOrig} AND resident_id = {$resResp2}")->fetchColumn();
// Unexcused absence recorded for both respondents at original hearing
$db->prepare("INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified)
    VALUES (?, ?, 'Absent', 0), (?, ?, 'Absent', 0)")
    ->execute([$h18aOrig, $resResp1, $h18aOrig, $resResp2]);

$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id)
    VALUES (?, 'Show Cause (Respondent)', ?, 'Lupon Hall', 'Scheduled', ?)")
    ->execute([$case18a, "{$tue18b} 10:00:00", $h18aOrig]);
$h18aSc = (int) $db->lastInsertId();
// Co-respondent 1 has verified KP Form 19; Co-respondent 2 is still Pending Service
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, form_type, attempt_number, delivery_status, served_at)
    VALUES (?, ?, 'Respondent', ?, 'KP Form 19', 1, 'Served Personal', NOW())")
    ->execute([$case18a, $h18aSc, $resResp1]);
$scDel18R1 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, form_type, attempt_number, delivery_status)
    VALUES (?, ?, 'Respondent', ?, 'KP Form 19', 1, 'Pending')")
    ->execute([$case18a, $h18aSc, $resResp2]);
$scDel18R2Old = (int) $db->lastInsertId();

$db->prepare("INSERT INTO show_cause_evaluations (hearing_id, case_id, party_type, form_type, is_justified, justification_category, action_taken) VALUES (?, ?, 'Respondent', 'KP Form 19', 1, 'Medical Emergency', 'Rescheduled')")
    ->execute([$h18aSc, $case18a]);

$scSvc18 = new ShowCauseService($db);
$wsItems18 = $scSvc18->getShowCauseWorkspaceItems($case18a, 'Mediation');
$recMap18 = [];
foreach (($wsItems18[0]['recipient_items'] ?? []) as $ri) {
    $recMap18[(int) $ri['resident_id']] = $ri;
}
assertReg(
    count($wsItems18) === 1
    && count($wsItems18[0]['legacy_evaluations'] ?? []) === 1
    && ($wsItems18[0]['decided_count'] ?? -1) === 0
    && !empty($recMap18[$resResp1]['ready_for_decision'])
    && ($recMap18[$resResp2]['readiness_status'] ?? '') === 'awaiting_service'
    && str_contains((string) ($recMap18[$resResp2]['blocking_reason'] ?? ''), 'Proof of Service'),
    'Workspace keeps a role-only finding in history and shows ready and held co-respondents separately'
);

// Justified decision for Co-respondent 1 must excuse them and keep show-cause open for Co-respondent 2
$replacementDay18 = nextWeekdayDate($logistics, 15, 2);
$replacementDate18 = "{$replacementDay18} 14:00:00";
$justData18 = [
    'hearing_id' => $h18aSc,
    'resident_id' => $resResp1,
    'party_type' => 'Respondent',
    'is_justified' => 1,
    'justification_category' => 'Official Duty',
    'justification_notes' => 'Submitted official assignment',
    'reschedule_date' => $replacementDate18,
    'expected_notice_delivery_id' => $scDel18R1,
    'expected_original_delivery_id' => $origDel18R1,
];
foreach ([
    ['reschedule_date' => '2028-02-30 03:00:00'],
    ['reschedule_date' => "{$replacementDay18} 03:00:00"],
    ['justification_category' => 'Unrecognized category'],
] as $invalidFields) {
    $invalid = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', array_replace($justData18, $invalidFields));
    assertReg($invalid['status'] === 422, 'Justified determination rejects invalid appointment or category: ' . implode(', ', $invalidFields));
}
$invalidDecisionCount = (int) $db->query("SELECT COUNT(*) FROM show_cause_evaluations WHERE hearing_id = {$h18aSc} AND resident_id IS NOT NULL")->fetchColumn();
$replacementCount = (int) $db->query("SELECT COUNT(*) FROM hearings WHERE rescheduled_from_id = {$h18aSc}")->fetchColumn();
assertReg($invalidDecisionCount === 0 && $replacementCount === 0, 'Rejected justified decisions save neither a determination nor a replacement hearing');

$justRes18 = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', $justData18);
$scStatusAfterJust = (string) $db->query("SELECT status FROM hearings WHERE hearing_id = {$h18aSc}")->fetchColumn();
$r1JustifiedCount = (int) $db->query("SELECT COUNT(*) FROM show_cause_evaluations e JOIN hearings h ON h.hearing_id = e.rescheduled_hearing_id WHERE e.hearing_id = {$h18aSc} AND e.resident_id = {$resResp1} AND e.is_justified = 1 AND e.justification_category = 'Official Duty' AND h.hearing_date = '{$replacementDate18}' AND h.venue = 'Lupon Hall' AND h.end_time > h.hearing_date")->fetchColumn();
assertReg(
    $justRes18['status'] === 200 && !empty($justRes18['json']['success']) && $r1JustifiedCount === 1 && $scStatusAfterJust !== 'Completed',
    'Justified decision preserves the selected category, books the validated appointment, and leaves the co-respondent pending'
);

// Supersede R2 KP Form 19 with Attempt 2 and submit stale Attempt 1 delivery ID -> must be rejected cleanly
$db->prepare("UPDATE summon_deliveries SET delivery_status = 'Failed Attempt' WHERE delivery_id = ?")->execute([$scDel18R2Old]);
$db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, form_type, attempt_number, delivery_status, served_at)
    VALUES (?, ?, 'Respondent', ?, 'KP Form 19', 2, 'Served Personal', NOW())")
    ->execute([$case18a, $h18aSc, $resResp2]);
$scDel18R2New = (int) $db->lastInsertId();

$staleRes18 = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', [
    'hearing_id' => $h18aSc,
    'resident_id' => $resResp2,
    'party_type' => 'Respondent',
    'is_justified' => 0,
    'justification_notes' => 'Stale attempt test',
    'expected_notice_delivery_id' => $scDel18R2Old,
    'expected_original_delivery_id' => $origDel18R2,
]);
$r2EvalAfterStale = (int) $db->query("SELECT COUNT(*) FROM show_cause_evaluations WHERE hearing_id = {$h18aSc} AND resident_id = {$resResp2}")->fetchColumn();
$cfaAfterStale = (int) $db->query("SELECT COUNT(*) FROM cfa_records WHERE case_id = {$case18a}")->fetchColumn();
assertReg(
    $staleRes18['status'] === 422 && empty($staleRes18['json']['success']) && $r2EvalAfterStale === 0 && $cfaAfterStale === 0,
    'Stale expected_notice_delivery_id submission is rejected (HTTP 422) with zero determinations or certificates written'
);

// Submit Co-respondent 2 Unjustified with current operative notice -> issues KP Form 20 & KP Form 20-B atomically
$unjustRes18 = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', [
    'hearing_id' => $h18aSc,
    'resident_id' => $resResp2,
    'party_type' => 'Respondent',
    'is_justified' => 0,
    'justification_notes' => 'Willful unexcused absence after personal service',
    'expected_notice_delivery_id' => $scDel18R2New,
    'expected_original_delivery_id' => $origDel18R2,
]);
$cfaTypes18 = $db->query("SELECT cfa_type FROM cfa_records WHERE case_id = {$case18a} ORDER BY cfa_type")->fetchAll(PDO::FETCH_COLUMN);
foreach ($db->query("SELECT file_path FROM generated_documents WHERE case_id = {$case18a}")->fetchAll(PDO::FETCH_COLUMN) as $fp18a) {
    $createdFiles[] = dirname(__DIR__, 2) . '/' . ltrim((string) $fp18a, '/');
}
assertReg(
    $unjustRes18['status'] === 200 && !empty($unjustRes18['json']['success']) && $cfaTypes18 === ['KP Form 20', 'KP Form 20-B'],
    'Valid unjustified decision for Co-respondent 2 commits KP Form 20 and KP Form 20-B atomically'
);

// Order 3B: Cancelled/Superseded Conciliation minutes exclusion, Repudiation -> CFA, and PDF Recovery
$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Order 3B Repudiation and PDF Recovery', 'Settled', ?, 'Civil', CURDATE(), '3B full test', ?, NOW())")
    ->execute(["CMP-REG18B-{$token}", $catId, $clerkId]);
$comp18b = (int) $db->lastInsertId();
$cleanup->trackComplaint($comp18b);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
    ->execute([$comp18b, $resComp, $comp18b, $resResp1]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, 'Settled', CURDATE(), NOW())")
    ->execute([$comp18b, "REG-18B-{$token}"]);
$case18b = (int) $db->lastInsertId();

// Finalized minutes on Cancelled and Superseded Conciliation hearings MUST NOT unlock impasse eligibility
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Conciliation', ?, 'Lupon Hall', 'Rescheduled')")
    ->execute([$case18b, "{$tue18a} 11:00:00"]);
$h18bOrig = (int) $db->lastInsertId();
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id) VALUES (?, 'Conciliation', ?, 'Lupon Hall', 'Cancelled', ?)")
    ->execute([$case18b, "{$tue18b} 11:00:00", $h18bOrig]);
$h18bCanc = (int) $db->lastInsertId();
$db->prepare("INSERT INTO hearing_minutes (hearing_id, case_id, session_type, session_outcome, status, recorded_by, finalized_by, finalized_at)
    VALUES (?, ?, 'Conciliation', 'Failed', 'Finalized', ?, ?, NOW()), (?, ?, 'Conciliation', 'Failed', 'Finalized', ?, ?, NOW())")
    ->execute([$h18bOrig, $case18b, $clerkId, $clerkId, $h18bCanc, $case18b, $clerkId, $clerkId]);
$eligCancelledOnly = $cfaModel->determineCfaEligibility($case18b);
assertReg(
    empty($eligCancelledOnly['eligible']),
    'Finalized Failed minutes on Cancelled or superseded Conciliation hearings do NOT unlock CFA eligibility'
);

// File repudiation -> must NOT insert into cfa_records, and MUST unlock KP Form 20 via REPUDIATED_SETTLEMENT
$db->prepare("INSERT INTO settlements (case_id, settlement_date, agreement_details, complainant_signed, respondent_signed, pb_attested, settlement_status, repudiation_deadline, repudiation_status, compliance_status)
    VALUES (?, CURDATE(), 'Pay amount within 5 days', 1, 1, 1, 'Signed and Attested', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Within Repudiation Period', 'Pending')")
    ->execute([$case18b]);
$sett18bId = (int) $db->lastInsertId();
$repRes18b = regHttpRequest($baseUrl, $clerkSession, 'settlements/repudiate.php', [
    'settlement_id' => $sett18bId,
    'repudiated_by' => $resResp1,
    'repudiation_reason' => 'Fraud and intimidation',
    'supporting_document_path' => 'uploads/repudiations/test_reg18b.pdf',
], true);
$cfaCountAfterRep = (int) $db->query("SELECT COUNT(*) FROM cfa_records WHERE case_id = {$case18b}")->fetchColumn();
$eligAfterRep = $cfaModel->determineCfaEligibility($case18b);
assertReg(
    $repRes18b['status'] === 200
    && !empty($repRes18b['json']['success'])
    && $cfaCountAfterRep === 0
    && !empty($eligAfterRep['eligible'])
    && ($eligAfterRep['ground'] ?? '') === 'REPUDIATED_SETTLEMENT',
    'Valid settlement repudiation inserts zero cfa_records rows and establishes REPUDIATED_SETTLEMENT eligibility'
);

// Simulated render failure leaves repudiation available for retry, then standard CFA issuance succeeds atomically
$failRepIssue = $failingCfaModel->create([
    'case_id' => $case18b,
    'cfa_type' => 'KP Form 20',
    'reason' => 'Repudiation render failure retry check',
], $clerkId);
$eligStillRep = $cfaModel->determineCfaEligibility($case18b);
assertReg(
    empty($failRepIssue['success'])
    && (int) $db->query("SELECT COUNT(*) FROM cfa_records WHERE case_id = {$case18b}")->fetchColumn() === 0
    && !empty($eligStillRep['eligible'])
    && ($eligStillRep['ground'] ?? '') === 'REPUDIATED_SETTLEMENT',
    'Render failure on repudiated settlement rolls back issuance and keeps recorded repudiation eligible for retry'
);

$repIssueHttp = regHttpRequest($baseUrl, $clerkSession, 'documents/cfa.php', [
    'case_id' => $case18b,
    'cfa_type' => 'KP Form 20',
    'reason' => 'Settlement formally repudiated within statutory period.',
]);
$cfa18bRow = $db->query("SELECT cfa_id, cfa_type, issuance_date, reason FROM cfa_records WHERE case_id = {$case18b} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$doc18bRow = $db->query("SELECT gd.document_id, gd.file_path FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = {$case18b} AND dt.template_name = 'KP Form 20' ORDER BY gd.document_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$hist18bBeforeRec = (int) $db->query("SELECT COUNT(*) FROM case_history WHERE case_id = {$case18b}")->fetchColumn();
assertReg(
    $repIssueHttp['status'] === 200 && !empty($repIssueHttp['json']['success']) && !empty($cfa18bRow['cfa_id']) && !empty($doc18bRow['document_id']),
    'Standard CFA issuance commits KP Form 20, generated PDF, and history for repudiated settlement'
);

// PDF Recovery: set distinct historical date, delete PDF on disk AND remove generated_documents row, test recovery failure rollback, then recover twice
$cfa18bId = (int) $cfa18bRow['cfa_id'];
$origDate18b = '2026-01-15';
$origReason18b = 'Settlement formally repudiated within statutory period.';
$db->prepare("UPDATE cfa_records SET issuance_date = ? WHERE cfa_id = ?")->execute([$origDate18b, $cfa18bId]);
$origPdfDiskPath = dirname(__DIR__, 2) . '/' . ltrim((string) $doc18bRow['file_path'], '/');
if (is_file($origPdfDiskPath)) {
    @unlink($origPdfDiskPath);
}
$db->prepare("DELETE FROM generated_documents WHERE document_id = ?")->execute([(int) $doc18bRow['document_id']]);

// Failure on recovery must return actionable error and keep original cfa_records row untouched
$failRecRes = $failingCfaModel->recoverPdf(['cfa_id' => $cfa18bId], $clerkId);
$cfaCheckFailRec = $db->query("SELECT cfa_type, issuance_date, reason FROM cfa_records WHERE cfa_id = {$cfa18bId}")->fetch(PDO::FETCH_ASSOC);
assertReg(
    empty($failRecRes['success'])
    && ($cfaCheckFailRec['issuance_date'] ?? '') === $origDate18b
    && ($cfaCheckFailRec['reason'] ?? '') === $origReason18b,
    'Failed PDF recovery returns actionable error and preserves original CFA issuance_date and reason'
);

// Actual recovery via HTTP POST action=recover_pdf + immediate second recovery (concurrency/idempotency reuse)
$recHttp1 = regHttpRequest($baseUrl, $clerkSession, 'documents/cfa.php', [
    'action' => 'recover_pdf',
    'cfa_id' => $cfa18bId,
]);
$recHttp2 = regHttpRequest($baseUrl, $clerkSession, 'documents/cfa.php', [
    'action' => 'recover_pdf',
    'cfa_id' => $cfa18bId,
]);
$cfaAfterRec = $db->query("SELECT cfa_type, issuance_date, reason FROM cfa_records WHERE cfa_id = {$cfa18bId}")->fetch(PDO::FETCH_ASSOC);
$docsAfterRec = $db->query("SELECT gd.document_id, gd.file_path FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = {$case18b} AND dt.template_name = 'KP Form 20'")->fetchAll(PDO::FETCH_ASSOC);
$hist18bAfterRec = (int) $db->query("SELECT COUNT(*) FROM case_history WHERE case_id = {$case18b}")->fetchColumn();
$recoveredPath = !empty($docsAfterRec[0]['file_path']) ? dirname(__DIR__, 2) . '/' . ltrim((string) $docsAfterRec[0]['file_path'], '/') : '';
if ($recoveredPath) {
    $createdFiles[] = $recoveredPath;
}
assertReg(
    $recHttp1['status'] === 200 && !empty($recHttp1['json']['success'])
    && $recHttp2['status'] === 200 && !empty($recHttp2['json']['reused'])
    && count($docsAfterRec) === 1
    && $recoveredPath !== '' && is_file($recoveredPath)
    && ($cfaAfterRec['cfa_type'] ?? '') === 'KP Form 20'
    && ($cfaAfterRec['issuance_date'] ?? '') === $origDate18b
    && ($cfaAfterRec['reason'] ?? '') === $origReason18b
    && $hist18bAfterRec === $hist18bBeforeRec,
    'PDF recovery and repeat recovery produce one valid PDF while preserving original form, date, reason, and case_history'
);

$historicalCfa = $db->query("SELECT * FROM cfa_records WHERE cfa_id = {$cfa18bId}")->fetch(PDO::FETCH_ASSOC);
$priorRequestParams = $_GET;
$_GET = ['notice_date' => '2028-06-21', 'primary_body_text' => 'An editor override'];
try {
    $renderData = (new KpFormService($db))->getFormData('KP Form 20', $case18b, null, null, $historicalCfa);
} finally {
    $_GET = $priorRequestParams;
}
assertReg($renderData['issuance_date'] === $origDate18b && $renderData['cfa_reason'] === $origReason18b,
    'CFA issuance and recovery render the original date and reason even with live editor fields in the request');
$downloadPath = $recHttp1['json']['pdf_url'] ?? '';
$download = str_starts_with($downloadPath, '../../../')
    ? regHttpRequest($baseUrl, $clerkSession, '/' . substr($downloadPath, strlen('../../../')))
    : [];
assertReg(($download['status'] ?? 0) === 200 && str_starts_with($download['body'] ?? '', '%PDF'),
    'Recovered CFA download URL resolves from the staff pages and returns the saved PDF');

// A first unjustified decision can issue the case-level certificates while another recipient still awaits service.
$db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
    VALUES (?, 'Order 3A Pending Co-Respondent', 'Conciliation', ?, 'Civil', CURDATE(), 'Recipient-specific notices', ?, NOW())")
    ->execute(["REG-C19-{$token}", $catId, $clerkId]);
$complaint19 = (int) $db->lastInsertId();
$cleanup->trackComplaint($complaint19);
$db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent'), (?, ?, 'Respondent')")
    ->execute([$complaint19, $resComp, $complaint19, $resResp1, $complaint19, $resResp2]);
$db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date) VALUES (?, ?, 'Conciliation', CURDATE())")
    ->execute([$complaint19, "REG-19-{$token}"]);
$case19 = (int) $db->lastInsertId();
$db->prepare("INSERT INTO case_stages (case_id, stage_type, stage_status, records_transmitted) VALUES (?, 'Conciliation', 'In Progress', 1)")->execute([$case19]);
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Conciliation', DATE_SUB(NOW(), INTERVAL 1 DAY), 'Lupon Hall', 'Scheduled')")
    ->execute([$case19]);
$origin19 = (int) $db->lastInsertId();
$originalDelivery = $db->prepare("INSERT INTO summon_deliveries (case_id, hearing_id, party_type, resident_id, form_type, delivery_status, served_at) VALUES (?, ?, ?, ?, ?, 'Served Personal', NOW())");
foreach ([$resComp => 'Complainant', $resResp1 => 'Respondent', $resResp2 => 'Respondent'] as $partyId => $role) {
    $originalDelivery->execute([$case19, $origin19, $role, $partyId, 'KP Form 12']);
}
$attendance19 = regHttpRequest($baseUrl, $clerkSession, 'hearings/attendance.php', [
    'hearing_id' => $origin19,
    'parties' => [
        ['resident_id' => $resComp, 'party_type' => 'Complainant', 'attendance_status' => 'Present'],
        ['resident_id' => $resResp1, 'party_type' => 'Respondent', 'attendance_status' => 'Absent'],
        ['resident_id' => $resResp2, 'party_type' => 'Respondent', 'attendance_status' => 'Absent'],
    ],
]);
$show19 = (int) $db->query("SELECT hearing_id FROM hearings WHERE rescheduled_from_id = {$origin19} AND hearing_type = 'Show Cause (Respondent)'")->fetchColumn();
$newOriginalAttempts = (int) $db->query("SELECT COUNT(*) FROM summon_deliveries WHERE hearing_id = {$origin19} AND delivery_status = 'Pending'")->fetchColumn();
assertReg($newOriginalAttempts === 0, 'Show-cause issuance preserves the served original Conciliation notices and queues only the show-cause notices');
$notices19 = $db->query("SELECT resident_id, delivery_id, document_id FROM summon_deliveries WHERE hearing_id = {$show19} AND form_type = 'KP Form 19' ORDER BY delivery_id")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
$preview19 = [];
foreach ($notices19 as $recipientId => $notice) {
    $preview19[$recipientId] = regHttpRequest($baseUrl, $clerkSession, 'documents/download.php?stored=1&id=' . $notice['document_id']);
}
assertReg(!empty($attendance19['json']['success']) && count($notices19) === 2
    && (int) ($notices19[$resResp1]['document_id'] ?? 0) > 0
    && ($notices19[$resResp1]['document_id'] ?? null) !== ($notices19[$resResp2]['document_id'] ?? null)
    && str_starts_with($preview19[$resResp1]['body'] ?? '', '%PDF') && str_starts_with($preview19[$resResp2]['body'] ?? '', '%PDF'),
    'Attendance issues distinct printable KP Form 19 documents to both absent recipients (' . ($attendance19['json']['message'] ?? $attendance19['status']) . ')');

if (isset($notices19[$resResp1], $notices19[$resResp2])) {
    $serve19 = $db->prepare("UPDATE summon_deliveries SET delivery_status = 'Served Personal', served_at = NOW() WHERE delivery_id = ?");
    $serve19->execute([$notices19[$resResp1]['delivery_id']]);
    $first19 = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', [
        'hearing_id' => $show19, 'resident_id' => $resResp1, 'party_type' => 'Respondent', 'is_justified' => 0,
    ]);
    $bar19 = $db->query("SELECT cfa_id, resident_id FROM cfa_records WHERE case_id = {$case19} AND cfa_type = 'KP Form 20-B'")->fetch(PDO::FETCH_ASSOC);
    $held19 = $scSvc18->getShowCauseWorkspaceItems($case19, 'Conciliation')[0];
    assertReg($first19['status'] === 200 && $held19['awaiting_service_count'] === 1 && $held19['status'] !== 'Completed'
        && (int) ($bar19['resident_id'] ?? 0) === $resResp1,
        'First unjustified decision preserves the CFA recipient and keeps the co-respondent awaiting service');
    $serve19->execute([$notices19[$resResp2]['delivery_id']]);
    $second19 = regHttpRequest($baseUrl, $clerkSession, 'hearings/show-cause.php', [
        'hearing_id' => $show19, 'resident_id' => $resResp2, 'party_type' => 'Respondent', 'is_justified' => 1,
        'justification_category' => 'Force Majeure', 'expected_notice_document_id' => $notices19[$resResp2]['document_id'],
    ]);
    $finished19 = $scSvc18->getShowCauseWorkspaceItems($case19, 'Conciliation')[0];
    assertReg($second19['status'] === 200 && ($second19['json']['action_taken'] ?? null) === 'Excused'
        && empty($second19['json']['rescheduled_hearing_id']) && ($second19['json']['case_status'] ?? null) === 'CFA Issued'
        && $finished19['decided_count'] === 2 && $finished19['status'] === 'Completed',
        'Co-respondent can be excused after service and the saved CFA disposition is retained');
}

foreach ($db->query("SELECT file_path FROM generated_documents WHERE case_id = {$case19}")->fetchAll(PDO::FETCH_COLUMN) as $file19) {
    $createdFiles[] = dirname(__DIR__, 2) . '/' . ltrim((string) $file19, '/');
}

echo "\n[19] Testing Orders 4A & 4B (minutes vs signed settlement, arbitration award & rollback, deadlines, execution, transmittal)...\n";

require_once __DIR__ . '/../models/HearingMinutes.php';
require_once __DIR__ . '/../models/Arbitration.php';
require_once __DIR__ . '/../models/Settlement.php';
require_once __DIR__ . '/../models/CaseTransmittal.php';

$minutesModel19 = new HearingMinutes($db);
$stageModel19 = new CaseStage($db);
$arbModel19 = new Arbitration($db);
$settModel19 = new Settlement($db);
$transModel19 = new CaseTransmittal($db);

$createOrder4Case = function (string $title, string $caseStatus, string $compStatus, string $suffix) use ($db, $cleanup, $catId, $clerkId, $resComp, $resResp1, $token): array {
    $db->prepare("INSERT INTO complaints (complaint_number, complaint_title, status, category_id, case_type, incident_date, narrative, encoded_by, created_at)
        VALUES (?, ?, ?, ?, 'Civil', CURDATE(), 'Order 4 regression test', ?, NOW())")
        ->execute(["CMP-{$suffix}-{$token}", $title, $compStatus, $catId, $clerkId]);
    $compId = (int) $db->lastInsertId();
    $cleanup->trackComplaint($compId);
    $db->prepare("INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, 'Complainant'), (?, ?, 'Respondent')")
        ->execute([$compId, $resComp, $compId, $resResp1]);
    $db->prepare("INSERT INTO cases (complaint_id, case_number, case_status, docket_date, created_at) VALUES (?, ?, ?, CURDATE(), NOW())")
        ->execute([$compId, "REG-{$suffix}-{$token}", $caseStatus]);
    $caseId = (int) $db->lastInsertId();
    return [$compId, $caseId];
};

// 19A: Hearing minutes 'Settled' stays active until signed & attested KP Form 16
[$comp20Id, $case20Id] = $createOrder4Case('4A Minutes vs Settlement', 'Mediation', 'In Mediation', '4A01');
$db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status) VALUES (?, 'Mediation', DATE_SUB(NOW(), INTERVAL 1 HOUR), 'Lupon Hall', 'Scheduled')")->execute([$case20Id]);
$h20Id = (int) $db->lastInsertId();
$min20Res = $minutesModel19->save([
    'hearing_id' => $h20Id,
    'case_id' => $case20Id,
    'session_type' => 'Mediation',
    'session_notes' => 'Parties reached provisional agreement pending formal KP Form 16 signing.',
    'session_outcome' => 'Settled',
    'status' => 'Finalized',
], $clerkId);
$case20Row = $db->query("SELECT case_status FROM cases WHERE case_id = {$case20Id}")->fetch(PDO::FETCH_ASSOC);
$stage20Row = $db->query("SELECT stage_status, outcome FROM case_stages WHERE case_id = {$case20Id} AND stage_type = 'Mediation'")->fetch(PDO::FETCH_ASSOC);
$dl20Count = (int) $db->query("SELECT COUNT(*) FROM case_deadlines WHERE case_id = {$case20Id} AND deadline_type = 'Settlement Repudiation Period'")->fetchColumn();
assertReg(
    !empty($min20Res['success'])
    && ($case20Row['case_status'] ?? '') === 'Mediation'
    && ($stage20Row['stage_status'] ?? '') === 'Awaiting Outcome'
    && $dl20Count === 0,
    'Finalizing Mediation minutes as Settled keeps case active and leaves settlement deadlines pending'
);

$blockedOutcome20 = $stageModel19->recordOutcome($case20Id, 'Mediation', 'Settled', 'No KP Form 16 yet', $clerkId);
assertReg(
    empty($blockedOutcome20['success']),
    'CaseStage::recordOutcome rejects Settled until a signed and attested KP Form 16 settlement is recorded'
);

// Draft settlement (missing pb_attested) keeps case active & blocks repudiation/execution/transmittal
$draftSett20 = regHttpRequest($baseUrl, $clerkSession, 'settlements/save.php', [
    'case_id' => $case20Id,
    'settlement_date' => date('Y-m-d'),
    'agreement_details' => 'Draft agreement pending attestation.',
    'complainant_signed' => 1,
    'respondent_signed' => 1,
    'pb_attested' => 0,
]);
$draftSettId = (int) ($draftSett20['json']['settlement_id'] ?? 0);
$case20AfterDraft = (string) $db->query("SELECT case_status FROM cases WHERE case_id = {$case20Id}")->fetchColumn();
$repDraftBlock = regHttpRequest($baseUrl, $clerkSession, 'settlements/repudiate.php', [
    'settlement_id' => $draftSettId,
    'repudiated_by' => $resResp1,
    'repudiation_reason' => 'Fraud during draft',
]);
$execDraftBlock = regHttpRequest($baseUrl, $clerkSession, 'settlements/execute.php', [
    'settlement_id' => $draftSettId,
    'motion_filed_by' => $resComp,
    'obligation_violated' => 'Payment',
    'amount_or_requirement' => 'PHP 5000',
    'hearing_date' => date('Y-m-d H:i:s', strtotime('+3 days')),
]);
$transDraftBlock = $transModel19->createOrGetTransmittal($case20Id, $clerkId);
assertReg(
    $draftSett20['status'] === 200
    && ($draftSett20['json']['settlement_status'] ?? '') === 'Draft'
    && $case20AfterDraft === 'Mediation'
    && $repDraftBlock['status'] === 422
    && $execDraftBlock['status'] === 422
    && $transDraftBlock === null,
    'Draft settlement keeps case active and blocks repudiation, execution, and court packet creation'
);

// Signed and attested settlement transitions case to Settled, creates KP Form 16 PDF, and links court packet
$signedSett20 = regHttpRequest($baseUrl, $clerkSession, 'settlements/save.php', [
    'case_id' => $case20Id,
    'settlement_id' => $draftSettId,
    'settlement_date' => date('Y-m-d'),
    'agreement_details' => 'Respondent will pay PHP 5,000 in full.',
    'complainant_signed' => 1,
    'respondent_signed' => 1,
    'pb_attested' => 1,
]);
$case20AfterSigned = (string) $db->query("SELECT case_status FROM cases WHERE case_id = {$case20Id}")->fetchColumn();
$sett20DocId = (int) ($signedSett20['json']['document_id'] ?? 0);
$trans20 = $transModel19->createOrGetTransmittal($case20Id, $clerkId);
$trans20SubmitLocked = $transModel19->recordSubmission($case20Id, ['target_agency' => 'Both'], null, $clerkId);
$trans20PdfLocked = regHttpRequest($baseUrl, $clerkSession, "transmittals/preview.php?case_id={$case20Id}&pdf=1", [], false, 'GET');
assertReg(
    $signedSett20['status'] === 200
    && ($signedSett20['json']['settlement_status'] ?? '') === 'Signed and Attested'
    && $sett20DocId > 0
    && $case20AfterSigned === 'Settled'
    && !empty($trans20['transmittal_id'])
    && (int) ($trans20['source_document_id'] ?? 0) === $sett20DocId
    && !empty($trans20['is_locked'])
    && empty($trans20SubmitLocked['success'])
    && $trans20PdfLocked['status'] === 422,
    'Signed settlement links KP Form 16 PDF to court packet and locks transmittal PDF/submit during 10-working-day window'
);

// 19B: Arbitration agreement prerequisites, invalid dates, rollback, & idempotency
[$comp21Id, $case21Id] = $createOrder4Case('4A Arbitration Validation', 'Mediation', 'In Mediation', '4A02');

$noAgrAward = regHttpRequest($baseUrl, $clerkSession, 'documents/arbitration.php', [
    'case_id' => $case21Id,
    'agreement_date' => date('Y-m-d', strtotime('-8 days')),
    'award_date' => date('Y-m-d'),
    'award_details' => 'Attempted award without saved agreement.',
]);
assertReg(
    $noAgrAward['status'] === 422
    && (int) $db->query("SELECT COUNT(*) FROM arbitration_records WHERE case_id = {$case21Id}")->fetchColumn() === 0,
    'Award issuance rejects missing KP Form 14 agreement and ignores client-supplied agreement_date'
);

// Record signed KP Form 14 agreement 8 days ago (within Day 6-15 window)
$agrDate21 = date('Y-m-d', strtotime('-8 days'));
$futureAgr = $arbModel19->recordAgreement(['case_id' => $case21Id, 'agreement_date' => date('Y-m-d', strtotime('+2 days')), 'agreement_signed' => 1], $clerkId);
$okAgr21 = $arbModel19->recordAgreement(['case_id' => $case21Id, 'agreement_date' => $agrDate21, 'agreement_signed' => 1], $clerkId);
$earlyAward = regHttpRequest($baseUrl, $clerkSession, 'documents/arbitration.php', [
    'case_id' => $case21Id,
    'award_date' => date('Y-m-d', strtotime($agrDate21 . ' +3 days')),
    'award_details' => 'Too early Day 3 award.',
]);
$lateAward = regHttpRequest($baseUrl, $clerkSession, 'documents/arbitration.php', [
    'case_id' => $case21Id,
    'award_date' => date('Y-m-d', strtotime($agrDate21 . ' +16 days')),
    'award_details' => 'Too late Day 16 award.',
]);
assertReg(
    empty($futureAgr['success'])
    && !empty($okAgr21['success'])
    && !empty($okAgr21['agreement_document_id'])
    && $earlyAward['status'] === 422
    && $lateAward['status'] === 422,
    'Arbitration rejects future signing dates and Day 3 / Day 16 out-of-window award dates'
);

// Simulated PDF failure rolls back award & leaves case in Arbitration
$failingKp21 = new class($db) extends KpFormService {
    public function generateAndPersistForm(string $formCode, int $caseId, int $userId, array $options = []): array
    {
        return ['success' => false, 'message' => 'Simulated KP Form 15 PDF failure'];
    }
};
$failAwardModel21 = new class($db, $failingKp21) extends Arbitration {
    private KpFormService $kpMock;
    public function __construct(PDO $db, KpFormService $kpMock)
    {
        parent::__construct($db);
        $this->kpMock = $kpMock;
    }
    protected function createKpFormService(): KpFormService
    {
        return $this->kpMock;
    }
};
$failAwardRes21 = $failAwardModel21->issueAward([
    'case_id' => $case21Id,
    'award_date' => date('Y-m-d'),
    'award_details' => 'Rollback test award.',
], $clerkId);
$arb21Check = $db->query("SELECT award_status, award_document_id FROM arbitration_records WHERE case_id = {$case21Id}")->fetch(PDO::FETCH_ASSOC);
$case21AfterFail = (string) $db->query("SELECT case_status FROM cases WHERE case_id = {$case21Id}")->fetchColumn();
assertReg(
    empty($failAwardRes21['success'])
    && ($arb21Check['award_status'] ?? '') === 'Pending'
    && empty($arb21Check['award_document_id'])
    && $case21AfterFail === 'Arbitration',
    'Arbitration award PDF failure rolls back award_status, document link, and case status'
);

// Valid award issuance + repeat submission idempotency
$okAward21 = regHttpRequest($baseUrl, $clerkSession, 'documents/arbitration.php', [
    'case_id' => $case21Id,
    'award_date' => date('Y-m-d'),
    'award_details' => 'Respondent shall return the borrowed equipment within 5 days.',
    'arbitrator_presider' => 'Punong Barangay Test',
]);
$docCountBeforeDup = (int) $db->query("SELECT COUNT(*) FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = {$case21Id} AND dt.template_name = 'KP Form 15'")->fetchColumn();
$dupAward21 = regHttpRequest($baseUrl, $clerkSession, 'documents/arbitration.php', [
    'case_id' => $case21Id,
    'award_date' => date('Y-m-d', strtotime('-1 day')),
    'award_details' => 'Different terms should not overwrite finalized award.',
]);
$docCountAfterDup = (int) $db->query("SELECT COUNT(*) FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = {$case21Id} AND dt.template_name = 'KP Form 15'")->fetchColumn();
$arb21Final = $db->query("SELECT award_status, award_date, award_document_id FROM arbitration_records WHERE case_id = {$case21Id}")->fetch(PDO::FETCH_ASSOC);
assertReg(
    $okAward21['status'] === 200
    && !empty($okAward21['json']['document_id'])
    && $dupAward21['status'] === 200
    && !empty($dupAward21['json']['idempotent'])
    && $docCountBeforeDup === 1
    && $docCountAfterDup === 1
    && ($arb21Final['award_date'] ?? '') === date('Y-m-d'),
    'Valid arbitration award commits KP Form 15 atomically and repeat submission returns existing award without duplicating PDF'
);

// 19C: Arbitration agreement 5-day repudiation boundary & CFA eligibility
[$comp22Id, $case22Id] = $createOrder4Case('4B Arb Agreement Repudiation', 'Mediation', 'In Mediation', '4B01');
$agr22Ok = $arbModel19->recordAgreement(['case_id' => $case22Id, 'agreement_date' => date('Y-m-d', strtotime('-5 days')), 'agreement_signed' => 1], $clerkId);
$repArb22 = $arbModel19->repudiateAgreement([
    'case_id' => $case22Id,
    'repudiated_by' => $resResp1,
    'repudiation_ground' => 'Intimidation',
    'repudiation_reason' => 'Signed under threat.',
], $clerkId);
$awardAfterRep22 = regHttpRequest($baseUrl, $clerkSession, 'documents/arbitration.php', [
    'case_id' => $case22Id,
    'award_date' => date('Y-m-d'),
    'award_details' => 'Should be blocked after repudiation.',
]);
$eligArbRep22 = $cfaModel->determineCfaEligibility($case22Id);
assertReg(
    !empty($agr22Ok['success'])
    && !empty($repArb22['success'])
    && $awardAfterRep22['status'] === 422
    && !empty($eligArbRep22['eligible'])
    && ($eligArbRep22['ground'] ?? '') === 'REPUDIATED_SETTLEMENT',
    'Arbitration agreement repudiation on Day 5 blocks award issuance and unlocks standard CFA'
);

// 19D: Expired settlement -> noncompliance reopen vs execution & 6-month MTC lapse + superseded court packet
[$comp23Id, $case23Id] = $createOrder4Case('4B Noncompliance & Transmittal', 'Mediation', 'In Mediation', '4B02');
$pastSettDate23 = date('Y-m-d', strtotime('-25 days'));
$savePast23 = regHttpRequest($baseUrl, $clerkSession, 'settlements/save.php', [
    'case_id' => $case23Id,
    'settlement_date' => $pastSettDate23,
    'agreement_details' => 'Pay PHP 10,000 within 15 days.',
    'complainant_signed' => 1,
    'respondent_signed' => 1,
    'pb_attested' => 1,
]);
$sett23Id = (int) ($savePast23['json']['settlement_id'] ?? 0);
$transSett23 = $transModel19->createOrGetTransmittal($case23Id, $clerkId);
$subSett23 = $transModel19->recordSubmission($case23Id, ['target_agency' => 'Both'], null, $clerkId);
$dupSub23 = $transModel19->recordSubmission($case23Id, ['target_agency' => 'Both'], null, $clerkId);

// Reopen for noncompliance -> cancels settlement packet, unlocks SETTLEMENT_NONCOMPLIANCE CFA, and CFA creates new packet
$reopen23 = regHttpRequest($baseUrl, $clerkSession, 'cases/reopen.php', [
    'case_id' => $case23Id,
    'reason' => 'Respondent failed to pay after finality.',
]);
$eligNoncomp23 = $cfaModel->determineCfaEligibility($case23Id);
$cfa23Http = regHttpRequest($baseUrl, $clerkSession, 'documents/cfa.php', [
    'case_id' => $case23Id,
    'cfa_type' => 'KP Form 20',
    'reason' => 'Non-compliance with Amicable Settlement.',
]);
$transCfa23 = $transModel19->createOrGetTransmittal($case23Id, $clerkId);
$histPackets23 = (int) $db->query("SELECT COUNT(*) FROM case_transmittals WHERE case_id = {$case23Id}")->fetchColumn();
assertReg(
    $savePast23['status'] === 200
    && !empty($transSett23['is_ready_for_transmittal'])
    && !empty($subSett23['success'])
    && !empty($dupSub23['already_submitted'])
    && $reopen23['status'] === 200
    && ($eligNoncomp23['ground'] ?? '') === 'SETTLEMENT_NONCOMPLIANCE'
    && $cfa23Http['status'] === 200
    && ($transCfa23['source_type'] ?? '') === 'CFA'
    && empty($transCfa23['is_locked'])
    && $histPackets23 === 2,
    'Finalized settlement transmits once idempotently, reopen unlocks noncompliance CFA, and CFA packet supersedes settlement packet while preserving history'
);

// 6-month MTC lapse check on execution
[$comp24Id, $case24Id] = $createOrder4Case('4B 6-Month MTC Lapse', 'Mediation', 'In Mediation', '4B03');
$lapsedDate24 = date('Y-m-d', strtotime('-7 months'));
$saveLapsed24 = regHttpRequest($baseUrl, $clerkSession, 'settlements/save.php', [
    'case_id' => $case24Id,
    'settlement_date' => $lapsedDate24,
    'agreement_details' => 'Older than 6 months settlement.',
    'complainant_signed' => 1,
    'respondent_signed' => 1,
    'pb_attested' => 1,
]);
$lapsedSett24Id = (int) ($saveLapsed24['json']['settlement_id'] ?? 0);
$execLapsed24 = $settModel19->fileExecutionMotion($lapsedSett24Id, [
    'motion_filed_by' => $resComp,
    'obligation_violated' => 'Payment',
    'amount_or_requirement' => 'PHP 2000',
    'hearing_date' => date('Y-m-d H:i:s', strtotime('+3 days')),
], $clerkId);
assertReg(
    empty($execLapsed24['success']) && !empty($execLapsed24['lapsed_to_mtc']),
    'Execution motion past 6 months returns MTC referral lapse'
);

foreach ([$case20Id, $case21Id, $case22Id, $case23Id, $case24Id] as $c4CleanupId) {
    foreach ($db->query("SELECT file_path FROM generated_documents WHERE case_id = {$c4CleanupId}")->fetchAll(PDO::FETCH_COLUMN) as $f4) {
        $createdFiles[] = dirname(__DIR__, 2) . '/' . ltrim((string) $f4, '/');
    }
}

echo "\n====================================================================\n";
echo "   TOTAL REGRESSIONS: " . ($passCount + $failCount) . " | PASSED: {$passCount} | FAILED: {$failCount}\n";
echo "====================================================================\n";

$cleanup->cleanup();
exit($failCount > 0 ? 1 : 0);
