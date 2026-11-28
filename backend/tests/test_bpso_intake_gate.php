<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Resident.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../services/BpsoIntakeService.php';
require_once __DIR__ . '/../services/ComplaintInformationService.php';
require_once __DIR__ . '/../services/ResidentComplaintTimeline.php';
require_once __DIR__ . '/../controllers/LuponDashboardController.php';
require_once __DIR__ . '/../controllers/ComplaintController.php';
require_once __DIR__ . '/../middleware/rbac.php';
require_once __DIR__ . '/TestDataCleanup.php';

function assertBpso(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAILED: {$message}");
    }
    echo "PASS: {$message}\n";
}

function bpsoHttpCall(string $base, ?array $session, string $route, ?array $postData = null, bool $jsonBody = false): array
{
    $headers = ['Accept: application/json'];
    if ($session !== null) {
        $headers[] = 'Cookie: PHPSESSID=' . $session['id'];
        $headers[] = 'X-CSRF-Token: ' . $session['csrf_token'];
    }
    $options = [
        'header' => $headers,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 20,
    ];
    if ($postData !== null) {
        $options['method'] = 'POST';
        if ($jsonBody) {
            $options['header'][] = 'Content-Type: application/json';
            $options['content'] = json_encode($postData);
        } else {
            $options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
            $options['content'] = http_build_query($postData);
        }
    }
    $path = str_starts_with($route, '/') ? $route : '/backend/api/' . $route;
    $stream = @fopen($base . $path, 'rb', false, stream_context_create(['http' => $options]));
    if ($stream === false) {
        throw new RuntimeException("No HTTP response for {$route}");
    }
    $meta = stream_get_meta_data($stream);
    $body = stream_get_contents($stream);
    fclose($stream);
    $statusLine = $meta['wrapper_data'][0] ?? '';
    $status = (int) (explode(' ', (string) $statusLine)[1] ?? 0);
    return [
        'status' => $status,
        'headers' => $meta['wrapper_data'] ?? [],
        'body' => $body,
        'json' => json_decode((string) $body, true),
    ];
}

$db = (new Database())->connect();
$token = 'bpso-' . bin2hex(random_bytes(5));
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/agap-bpso-sessions-' . $token;
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0770, true);
}
$logPath = sys_get_temp_dir() . '/agap-bpso-http-' . $token . '.log';

$cleanup = new TestDataCleanup($db);
$createdUserIds = [];
$createdResidentIds = [];
$process = null;

try {
    $userModel = new User();
    $staffRoles = $userModel->getStaffRoles();
    $staffRoleNames = array_column($staffRoles, 'role_name');
    assertBpso(in_array('BPSO', $staffRoleNames, true), 'User::getStaffRoles() includes BPSO role');

    $roles = $db->query('SELECT role_name, role_id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    assertBpso(isset($roles['BPSO']), 'roles table contains BPSO');

    $insUser = $db->prepare(
        "INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
         VALUES (?, ?, ?, ?, ?, ?, 'Active')"
    );
    $actors = [];
    foreach ([
        'admin' => 'Administrator',
        'bpso' => 'BPSO',
        'liso' => 'Lupon Member',
        'server' => 'Summons Server',
        'resident' => 'Resident',
    ] as $key => $roleName) {
        $uname = "{$key}_{$token}";
        $insUser->execute(['Test', ucfirst($key), $uname, "{$uname}@agap.test", password_hash($token, PASSWORD_DEFAULT), (int) $roles[$roleName]]);
        $uid = (int) $db->lastInsertId();
        $createdUserIds[] = $uid;
        $actors[$key] = [
            'user_id' => $uid,
            'role_id' => (int) $roles[$roleName],
            'role_name' => $roleName,
            'username' => $uname,
        ];
    }

    $resModel = new Resident();
    $compResId = (int) $resModel->create([
        'first_name' => 'Maria',
        'last_name' => 'Clara_' . $token,
        'contact_no' => '09175550111',
        'address' => '12 Balete St, Barangay Tumana, Marikina City',
        'purok' => 'Purok 1',
        'residency_verified' => 1,
    ]);
    $respResId = (int) $resModel->create([
        'first_name' => 'Pedro',
        'last_name' => 'Penduko_' . $token,
        'contact_no' => '09175550222',
        'address' => '45 Narra St, Barangay Tumana, Marikina City',
        'purok' => 'Purok 2',
        'residency_verified' => 1,
    ]);
    $createdResidentIds = [$compResId, $respResId];
    $db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)')->execute([$actors['resident']['user_id'], $compResId]);

    $catId = (int) $db->query("SELECT category_id FROM complaint_categories WHERE is_active = 1 ORDER BY category_id ASC LIMIT 1")->fetchColumn();

    $port = random_int(23000, 28999);
    $cmd = sprintf(
        'php -d session.save_path=%s -S 127.0.0.1:%d -t %s',
        escapeshellarg($sessionDir),
        $port,
        escapeshellarg($root)
    );
    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']], $pipes);
    assertBpso(is_resource($process), 'Started ephemeral PHP test HTTP server');
    $base = "http://127.0.0.1:{$port}";
    $ready = false;
    for ($i = 0; $i < 20; $i++) {
        usleep(150000);
        if (@file_get_contents($base . '/frontend/pages/auth/login.php') !== false) {
            $ready = true;
            break;
        }
    }
    assertBpso($ready, 'Ephemeral HTTP server ready');

    // Create deterministic session files for each actor
    $sessions = [];
    foreach ($actors as $key => $actor) {
        $sid = 'sess' . bin2hex(random_bytes(8));
        $csrf = bin2hex(random_bytes(16));
        $_SESSION = [
            'user_id' => $actor['user_id'],
            'role_id' => $actor['role_id'],
            'role_name' => $actor['role_name'],
            'username' => $actor['username'],
            'csrf_token' => $csrf,
        ];
        file_put_contents($sessionDir . '/sess_' . $sid, session_encode());
        $_SESSION = [];
        $sessions[$key] = ['id' => $sid, 'csrf_token' => $csrf];
    }

    // 1. Test BPSO login redirect
    $loginRes = bpsoHttpCall($base, null, 'auth/login.php', [
        'username' => $actors['bpso']['username'],
        'password' => $token,
    ]);
    $locationHeader = implode("\n", $loginRes['headers']);
    assertBpso($loginRes['status'] === 303 && str_contains($locationHeader, 'bpso-dashboard.php'), 'BPSO login redirects to bpso-dashboard.php');

    // 2. Resident submits an online complaint -> enters Pending BPSO Review
    $onlineRes = bpsoHttpCall($base, $sessions['resident'], 'complaints/create.php?format=json', [
        'complaint_title' => 'Online Noise Concern ' . $token,
        'narrative' => 'Late night karaoke disturbance near our house in Purok 2 Barangay Tumana.',
        'incident_date' => date('Y-m-d', strtotime('-1 day')),
        'incident_purok' => 'Purok 2',
        'incident_street' => '45 Narra St',
        'complainant_resident_id' => $compResId,
        'respondent_resident_id' => $respResId,
        'privacy_notice_acknowledged' => 1,
    ]);
    assertBpso($onlineRes['status'] === 201 && !empty($onlineRes['json']['complaint_id']), 'Resident submits online complaint successfully');
    $onlineId = (int) $onlineRes['json']['complaint_id'];
    $cleanup->trackComplaint($onlineId);
    assertBpso(($onlineRes['json']['bpso_status'] ?? '') === 'Pending BPSO Review', 'Online complaint starts with bpso_status = Pending BPSO Review');

    $onlineRow = $db->query("SELECT bpso_status, assigned_queue, status FROM complaints WHERE complaint_id = {$onlineId}")->fetch(PDO::FETCH_ASSOC);
    assertBpso($onlineRow['assigned_queue'] === 'BPSO_VALIDATION', 'KP-eligible online complaint queues in BPSO_VALIDATION first');

    // 3. LISO cannot view/screen before BPSO endorsement
    assertBpso(
        !agapCanAccessComplaint($db, $onlineId, $actors['liso']['user_id'], 'Lupon Member', 'read'),
        'Unassigned LISO cannot access complaint still pending BPSO validation'
    );
    $lisoEarlyReview = bpsoHttpCall($base, $sessions['liso'], 'complaints/review.php', [
        'complaint_id' => $onlineId,
        'status' => 'Accepted',
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complaint_title' => 'Attempt Early LISO Screen',
        'review_notes' => 'Should be blocked',
    ]);
    assertBpso($lisoEarlyReview['status'] === 422 && str_contains((string) ($lisoEarlyReview['json']['message'] ?? ''), 'BPSO'), 'LISO screening blocked prior to BPSO endorsement');

    // 4. Non-BPSO/unauthorized roles blocked from BPSO review & dashboard
    $srvBpsoTry = bpsoHttpCall($base, $sessions['server'], 'complaints/bpso-review.php', [
        'complaint_id' => $onlineId,
        'action' => 'endorse',
    ], true);
    assertBpso($srvBpsoTry['status'] === 403, 'Summons Server is forbidden from BPSO review endpoint');

    $resDashTry = bpsoHttpCall($base, $sessions['resident'], 'dashboard/bpso.php');
    assertBpso($resDashTry['status'] === 403, 'Resident is forbidden from BPSO dashboard endpoint');

    // 5. BPSO Dashboard shows pending online complaint
    $bpsoDash = bpsoHttpCall($base, $sessions['bpso'], 'dashboard/bpso.php');
    assertBpso($bpsoDash['status'] === 200 && !empty($bpsoDash['json']['success']), 'BPSO dashboard API loads');
    $pendingIds = array_map(static fn ($r) => (int) $r['complaint_id'], $bpsoDash['json']['data']['pending_online'] ?? []);
    assertBpso(in_array($onlineId, $pendingIds, true), 'Online complaint appears in BPSO pending queue');

    // 6. BPSO cannot endorse without Tumana residency or identity confirmation
    $badEndorse = bpsoHttpCall($base, $sessions['bpso'], 'complaints/bpso-review.php', [
        'complaint_id' => $onlineId,
        'action' => 'endorse',
        'identity_verified' => 1,
        'details_verified' => 1,
        'respondent_in_tumana' => 0,
    ], true);
    assertBpso($badEndorse['status'] === 422 && str_contains((string) ($badEndorse['json']['message'] ?? ''), 'Tumana'), 'BPSO cannot endorse to LISO when respondent Tumana residency is not confirmed');

    // 7. BPSO requests clarification -> resident replies -> returns to Pending BPSO Review
    $clarifyRes = bpsoHttpCall($base, $sessions['bpso'], 'complaints/bpso-review.php', [
        'complaint_id' => $onlineId,
        'action' => 'request_info',
        'request_message' => 'Pakilagay ang eksaktong oras ng insidente.',
        'private_notes' => 'Internal BPSO note: check exact hour.',
    ], true);
    assertBpso($clarifyRes['status'] === 200 && ($clarifyRes['json']['bpso_status'] ?? '') === 'Needs Clarification', 'BPSO requests clarification from resident (got ' . $clarifyRes['status'] . ': ' . $clarifyRes['body'] . ')');

    $reqId = (int) $db->query("SELECT request_id FROM complaint_information_requests WHERE complaint_id = {$onlineId} ORDER BY request_id DESC LIMIT 1")->fetchColumn();
    (new ComplaintInformationService($db))->reply($onlineId, $actors['resident']['user_id'], $reqId, 'Nangyari ito bandang 11:30 PM kagabi.');
    $afterReply = $db->query("SELECT bpso_status, status FROM complaints WHERE complaint_id = {$onlineId}")->fetch(PDO::FETCH_ASSOC);
    assertBpso($afterReply['bpso_status'] === 'Pending BPSO Review' && $afterReply['status'] === 'Submitted', 'Resident reply returns complaint to Pending BPSO Review');

    // 8. BPSO validates & endorses online complaint to LISO
    $endorseRes = bpsoHttpCall($base, $sessions['bpso'], 'complaints/bpso-review.php', [
        'complaint_id' => $onlineId,
        'action' => 'endorse',
        'identity_verified' => 1,
        'details_verified' => 1,
        'respondent_in_tumana' => 1,
        'private_notes' => 'BPSO desk verified resident and Tumana address.',
    ], true);
    assertBpso($endorseRes['status'] === 200 && ($endorseRes['json']['bpso_status'] ?? '') === 'Endorsed to LISO', 'BPSO validates and endorses online complaint to LISO');
    assertBpso(!empty($endorseRes['json']['bpso_blotter_no']), 'BPSO endorsement assigns a Barangay Blotter Reference Number');

    // Resident view hides bpso_private_notes
    $resView = bpsoHttpCall($base, $sessions['resident'], 'complaints/view.php?id=' . $onlineId);
    assertBpso($resView['status'] === 200 && !array_key_exists('bpso_private_notes', $resView['json']), 'Resident view strips private BPSO staff notes');

    // LISO can now access and screen the endorsed complaint
    assertBpso(
        agapCanAccessComplaint($db, $onlineId, $actors['liso']['user_id'], 'Lupon Member', 'read'),
        'LISO can access complaint after BPSO endorsement'
    );
    $lisoScreenOk = bpsoHttpCall($base, $sessions['liso'], 'complaints/review.php', [
        'complaint_id' => $onlineId,
        'status' => 'Accepted',
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complaint_title' => 'Approved Noise Complaint ' . $token,
        'review_notes' => 'Jurisdiction verified under RA 7160.',
    ]);
    assertBpso($lisoScreenOk['status'] === 200 && !empty($lisoScreenOk['json']['success']), 'LISO screens BPSO-endorsed complaint successfully');

    // BPSO cannot formally docket or archive
    $bpsoDocketTry = bpsoHttpCall($base, $sessions['bpso'], 'complaints/docket.php', ['complaint_id' => $onlineId]);
    assertBpso($bpsoDocketTry['status'] === 403, 'BPSO is forbidden from formal docketing');
    $bpsoArchiveTry = bpsoHttpCall($base, $sessions['bpso'], 'complaints/archive.php', ['complaint_id' => $onlineId]);
    assertBpso($bpsoArchiveTry['status'] === 403, 'BPSO is forbidden from archiving endorsed complaints');

    // 9. Online complaint rejection with walk-in guidance
    $onlineBadRes = bpsoHttpCall($base, $sessions['resident'], 'complaints/create.php?format=json', [
        'complaint_title' => 'Incomplete Online Complaint ' . $token,
        'narrative' => 'Short unclear narrative without proper respondent details.',
        'incident_date' => date('Y-m-d', strtotime('-1 day')),
        'incident_purok' => 'Purok 1',
        'incident_street' => '12 Balete St',
        'complainant_resident_id' => $compResId,
        'respondent_resident_id' => $respResId,
        'privacy_notice_acknowledged' => 1,
    ]);
    $onlineBadId = (int) ($onlineBadRes['json']['complaint_id'] ?? 0);
    $cleanup->trackComplaint($onlineBadId);
    assertBpso($onlineBadId > 0, 'Created second online complaint for rejection test');

    $rejectRes = bpsoHttpCall($base, $sessions['bpso'], 'complaints/bpso-review.php', [
        'complaint_id' => $onlineBadId,
        'action' => 'reject',
        'respondent_in_tumana' => 0,
        'rejection_reason' => 'Kulangan ng eksaktong pagkakakilanlan at address ng inirereklamo.',
    ], true);
    assertBpso($rejectRes['status'] === 200 && ($rejectRes['json']['bpso_status'] ?? '') === 'Rejected', 'BPSO rejects incomplete online complaint with reason');

    $timeline = (new ResidentComplaintTimeline($db))->find($onlineBadId, $actors['resident']['user_id']);
    $timelineTitles = array_column($timeline['events'] ?? [], 'title');
    $timelineDetails = implode(' ', array_column($timeline['events'] ?? [], 'detail'));
    assertBpso(in_array('Returned by BPSO (Incomplete / Walk-in Recommended)', $timelineTitles, true) && str_contains($timelineDetails, 'walk-in'), 'Resident timeline shows BPSO rejection reason and walk-in guidance');

    // 10. BPSO Walk-in Blotter & Endorsement to LISO
    $bpsoWalkInForbiddenFormal = bpsoHttpCall($base, $sessions['bpso'], 'complaints/create.php?format=json', [
        'filing_track' => 'formal_mediation',
        'complaint_title' => 'BPSO Direct Formal Attempt ' . $token,
        'narrative' => 'Testing that BPSO cannot skip LISO and Paanyaya.',
        'incident_date' => date('Y-m-d', strtotime('-1 day')),
        'incident_purok' => 'Purok 1',
        'incident_street' => '12 Balete St',
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complainant_resident_id' => $compResId,
        'respondent_resident_id' => $respResId,
        'jurisdiction_screened' => 1,
        'privacy_notice_acknowledged' => 1,
    ]);
    assertBpso($bpsoWalkInForbiddenFormal['status'] === 403, 'BPSO is blocked from direct formal_mediation track');

    $bpsoWalkInRes = bpsoHttpCall($base, $sessions['bpso'], 'complaints/create.php?format=json', [
        'filing_track' => 'bpso_to_liso',
        'complaint_title' => 'Walk-in Boundary Dispute ' . $token,
        'narrative' => 'Walk-in complainant reported fence encroachment in Purok 2 Barangay Tumana.',
        'incident_date' => date('Y-m-d', strtotime('-1 day')),
        'incident_purok' => 'Purok 2',
        'incident_street' => '45 Narra St',
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complainant_resident_id' => $compResId,
        'respondent_resident_id' => $respResId,
        'bpso_identity_verified' => 1,
        'bpso_respondent_in_tumana' => 1,
        'bpso_private_notes' => 'Walk-in logged at BPSO desk.',
        'duplicate_acknowledged' => 1,
        'jurisdiction_screened' => 1,
        'privacy_notice_acknowledged' => 1,
    ]);
    assertBpso($bpsoWalkInRes['status'] === 201 && !empty($bpsoWalkInRes['json']['complaint_id']), 'BPSO logs walk-in blotter successfully (got ' . $bpsoWalkInRes['status'] . ': ' . $bpsoWalkInRes['body'] . ')');
    $walkInId = (int) $bpsoWalkInRes['json']['complaint_id'];
    $cleanup->trackComplaint($walkInId);
    assertBpso(
        ($bpsoWalkInRes['json']['bpso_status'] ?? '') === 'Endorsed to LISO'
        && ($bpsoWalkInRes['json']['status'] ?? '') === 'Under Review'
        && !empty($bpsoWalkInRes['json']['bpso_blotter_no']),
        'BPSO walk-in is logged in blotter, endorsed to LISO, and queued for LISO jurisdiction screening'
    );

    $lisoWalkInScreen = bpsoHttpCall($base, $sessions['liso'], 'complaints/review.php', [
        'complaint_id' => $walkInId,
        'status' => 'Accepted',
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complaint_title' => 'Approved Walk-in Boundary Dispute ' . $token,
        'review_notes' => 'Jurisdiction verified for walk-in blotter.',
    ]);
    assertBpso($lisoWalkInScreen['status'] === 200 && !empty($lisoWalkInScreen['json']['success']), 'LISO screens BPSO-endorsed walk-in complaint successfully');

    echo "\nALL PHASE 1 BPSO INTAKE GATE TESTS PASSED.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $cleanup->cleanup();
    if ($createdUserIds) {
        $in = implode(',', array_map('intval', $createdUserIds));
        $db->exec("DELETE FROM resident_user_profiles WHERE user_id IN ({$in})");
        $db->exec("DELETE FROM users WHERE user_id IN ({$in})");
    }
    if ($createdResidentIds) {
        $in = implode(',', array_map('intval', $createdResidentIds));
        $db->exec("DELETE FROM residents WHERE resident_id IN ({$in})");
    }
    @unlink($logPath);
    if (is_dir($sessionDir)) {
        foreach (glob($sessionDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($sessionDir);
    }
}
