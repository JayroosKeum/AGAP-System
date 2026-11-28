<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Resident.php';
require_once __DIR__ . '/../services/AnonymousConcernService.php';
require_once __DIR__ . '/../services/IntakeNoticeFormatter.php';
require_once __DIR__ . '/../services/PiiCryptoService.php';
require_once __DIR__ . '/TestDataCleanup.php';

function assertPhase2(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException("FAILED: {$msg}");
    }
    echo "PASS: {$msg}\n";
}

function p2HttpCall(string $base, ?array $session, string $route, ?array $postData = null, bool $jsonBody = false): array
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
        'body' => $body,
        'json' => json_decode((string) $body, true),
    ];
}

$db = (new Database())->connect();
$token = 'p2-' . bin2hex(random_bytes(5));
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/agap-p2-sessions-' . $token;
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0770, true);
}
$logPath = sys_get_temp_dir() . '/agap-p2-http-' . $token . '.log';

$cleanup = new TestDataCleanup($db);
$createdUserIds = [];
$createdResidentIds = [];
$createdAnonIds = [];
$process = null;

try {
    // 1. IntakeNoticeFormatter bilingual check
    $notice = IntakeNoticeFormatter::formatInsufficientInfoNotice(['buong pangalan ng inirereklamo', 'tirahan sa Barangay Tumana']);
    assertPhase2(
        str_contains($notice['tagalog'], 'BPSO') && str_contains($notice['tagalog'], 'Resident Account')
        && str_contains($notice['english'], 'BPSO') && str_contains($notice['english'], 'Resident Account'),
        'IntakeNoticeFormatter formats formal bilingual Tagalog & English notice with BPSO and Resident Account options'
    );

    // 2. AnonymousConcernService includes kp_insufficient_info_notice while preserving token flow
    $anonSvc = new AnonymousConcernService($db);
    $anonRes = $anonSvc->submit([
        'concern_title' => 'General street light tip ' . $token,
        'narrative' => 'Street lamp near alley is out for three nights.',
        'barangay_area' => 'Purok 3',
    ]);
    assertPhase2(!empty($anonRes['success']) && !empty($anonRes['kp_insufficient_info_notice']['tagalog']), 'AnonymousConcernService includes formal KP insufficient-info notice');
    if (!empty($anonRes['concern_id'])) {
        $createdAnonIds[] = (int) $anonRes['concern_id'];
    }

    // 3. Quick Unverified Resident Account (RES-TMN-XXXXX) even when Google client ID is configured
    putenv('GOOGLE_RESIDENT_CLIENT_ID=dummy-client.apps.googleusercontent.com');
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $auth = new AuthController();
    $quickReg = $auth->registerQuickResident([
        'password' => 'TumanaQuick2026!',
        'password_confirmation' => 'TumanaQuick2026!',
        'terms_agreed' => '1',
    ]);
    assertPhase2(!empty($quickReg['success']), 'Quick unverified resident registration succeeds');
    $handle = (string) ($quickReg['username'] ?? '');
    assertPhase2(preg_match('/^RES-TMN-\d{5}$/', $handle) === 1, 'Auto-generated handle matches RES-TMN-XXXXX format (' . $handle . ')');
    assertPhase2(($quickReg['verification_tier'] ?? '') === 'Unverified / Pending Verification', 'Quick registration returns Unverified / Pending Verification tier');

    $quickUid = (int) $quickReg['user_id'];
    $quickRid = (int) $quickReg['resident_id'];
    $createdUserIds[] = $quickUid;
    $createdResidentIds[] = $quickRid;

    $verifiedFlag = (int) $db->query("SELECT residency_verified FROM residents WHERE resident_id = {$quickRid}")->fetchColumn();
    assertPhase2($verifiedFlag === 0, 'Quick resident starts with residency_verified = 0');
    assertPhase2($auth->loginResident($handle, 'TumanaQuick2026!'), 'Quick resident can sign in using RES-TMN handle');
    session_write_close();

    // 4. Create BPSO & Respondent fixture and start ephemeral HTTP server
    $roles = $db->query('SELECT role_name, role_id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    $bpsoUname = 'bpso_' . $token;
    $db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status) VALUES ('BPSO', 'Tester', ?, ?, ?, ?, 'Active')")
        ->execute([$bpsoUname, "{$bpsoUname}@agap.test", password_hash($token, PASSWORD_DEFAULT), (int) $roles['BPSO']]);
    $bpsoUid = (int) $db->lastInsertId();
    $createdUserIds[] = $bpsoUid;

    $respId = (int) (new Resident())->create([
        'first_name' => 'Ramon',
        'last_name' => 'Delos_' . $token,
        'contact_no' => '09178889999',
        'address' => '78 Kamagong St, Barangay Tumana, Marikina City',
        'purok' => 'Purok 4',
        'residency_verified' => 1,
    ]);
    $createdResidentIds[] = $respId;

    $port = random_int(23000, 28999);
    $cmd = sprintf('php -d session.save_path=%s -S 127.0.0.1:%d -t %s', escapeshellarg($sessionDir), $port, escapeshellarg($root));
    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']], $pipes);
    assertPhase2(is_resource($process), 'Started ephemeral PHP HTTP server');
    $base = "http://127.0.0.1:{$port}";
    $ready = false;
    for ($i = 0; $i < 20; $i++) {
        usleep(150000);
        if (@file_get_contents($base . '/frontend/pages/resident/register.php?mode=quick') !== false) {
            $ready = true;
            break;
        }
    }
    assertPhase2($ready, 'Quick registration UI loads over HTTP');

    // Prepare sessions
    $makeSession = static function (array $sessData) use ($sessionDir): array {
        $sid = 'sess' . bin2hex(random_bytes(8));
        $csrf = bin2hex(random_bytes(16));
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = $sessData + ['csrf_token' => $csrf];
        file_put_contents($sessionDir . '/sess_' . $sid, session_encode());
        $_SESSION = [];
        session_write_close();
        return ['id' => $sid, 'csrf_token' => $csrf];
    };

    $resSession = $makeSession([
        'user_id' => $quickUid,
        'role_id' => (int) $roles['Resident'],
        'role_name' => 'Resident',
        'username' => $handle,
    ]);
    $bpsoSession = $makeSession([
        'user_id' => $bpsoUid,
        'role_id' => (int) $roles['BPSO'],
        'role_name' => 'BPSO',
        'username' => $bpsoUname,
    ]);

    // 5. Resident completes profile via update-profile.php
    $profRes = p2HttpCall($base, $resSession, 'residents/update-profile.php', [
        'first_name' => 'Elena',
        'last_name' => 'Navarro_' . $token,
        'contact_no' => '09171112233',
        'purok' => 'Purok 4',
        'address' => '15 Molave St, Barangay Tumana, Marikina City',
    ], true);
    assertPhase2($profRes['status'] === 200 && !empty($profRes['json']['success']), 'Unverified resident completes profile via update-profile.php');
    $stillUnverified = (int) $db->query("SELECT residency_verified FROM residents WHERE resident_id = {$quickRid}")->fetchColumn();
    assertPhase2($stillUnverified === 0, 'Self-service profile update keeps residency_verified = 0 until BPSO/staff verifies');

    // 6. RES-TMN user submits online complaint -> BPSO endorses -> resident promoted to Verified
    $compRes = p2HttpCall($base, $resSession, 'complaints/create.php?format=json', [
        'complaint_title' => 'Quick Account Grievance ' . $token,
        'narrative' => 'Neighbor blocked the shared drainage canal on Molave St, Purok 4.',
        'incident_date' => date('Y-m-d', strtotime('-1 day')),
        'incident_purok' => 'Purok 4',
        'incident_street' => '15 Molave St',
        'complainant_resident_id' => $quickRid,
        'respondent_resident_id' => $respId,
        'privacy_notice_acknowledged' => 1,
    ]);
    assertPhase2($compRes['status'] === 201 && !empty($compRes['json']['complaint_id']), 'RES-TMN resident submits online grievance into Pending BPSO Review');
    $cid = (int) $compRes['json']['complaint_id'];
    $cleanup->trackComplaint($cid);

    $endorseRes = p2HttpCall($base, $bpsoSession, 'complaints/bpso-review.php', [
        'complaint_id' => $cid,
        'action' => 'endorse',
        'identity_verified' => 1,
        'details_verified' => 1,
        'respondent_in_tumana' => 1,
        'private_notes' => 'Verified completed profile remotely.',
    ], true);
    assertPhase2($endorseRes['status'] === 200 && ($endorseRes['json']['bpso_status'] ?? '') === 'Endorsed to LISO', 'BPSO endorses completed RES-TMN grievance to LISO');

    $nowVerified = (int) $db->query("SELECT residency_verified FROM residents WHERE resident_id = {$quickRid}")->fetchColumn();
    assertPhase2($nowVerified === 1, 'BPSO endorsement promotes completed resident profile to residency_verified = 1');

    echo "\nALL PHASE 2 UNIFIED RESIDENT & NOTICE TESTS PASSED.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    $cleanup->cleanup();
    if ($createdAnonIds) {
        $in = implode(',', array_map('intval', $createdAnonIds));
        $db->exec("DELETE FROM anonymous_concerns WHERE concern_id IN ({$in})");
    }
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
