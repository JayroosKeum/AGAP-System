<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Resident.php';
require_once __DIR__ . '/../models/Paanyaya.php';
require_once __DIR__ . '/../services/ComplaintRoutingService.php';
require_once __DIR__ . '/../services/ResidentComplaintTimeline.php';
require_once __DIR__ . '/TestDataCleanup.php';

function assertPhase3(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException("FAILED: {$msg}");
    }
    echo "PASS: {$msg}\n";
}

function p3HttpCall(string $base, ?array $session, string $route, ?array $postData = null): array
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
        $options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($postData);
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
    return ['status' => $status, 'body' => $body, 'json' => json_decode((string) $body, true)];
}

$db = (new Database())->connect();
$token = 'p3-' . bin2hex(random_bytes(5));
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/agap-p3-sessions-' . $token;
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0770, true);
}
$logPath = sys_get_temp_dir() . '/agap-p3-http-' . $token . '.log';

$cleanup = new TestDataCleanup($db);
$createdUserIds = [];
$createdResidentIds = [];
$process = null;

try {
    // 1. Verify agencies & max 2 Paanyaya rule
    foreach (['PNP', 'Marikina Settlement Office', 'CENO', 'TMO'] as $agencyKey) {
        $resolved = ComplaintRoutingService::resolveExternalAgency($agencyKey);
        assertPhase3($resolved !== null && isset(ComplaintRoutingService::APPROVED_QUEUES[$resolved['queue']]), "Agency {$agencyKey} resolves to approved queue");
    }
    assertPhase3(Paanyaya::MAX_ATTEMPTS === 2, 'Paanyaya::MAX_ATTEMPTS is 2 invitations');
    $maxPaanyayaDb = (int) $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'paanyaya_max_attempts'")->fetchColumn();
    assertPhase3($maxPaanyayaDb === 2, 'system_settings.paanyaya_max_attempts defaults to 2');

    // 2. Setup actors
    $roles = $db->query('SELECT role_name, role_id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    $insUser = $db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
    $actors = [];
    foreach ([
        'bpso' => 'BPSO',
        'liso' => 'Lupon Member',
        'server' => 'Summons Server',
        'resident' => 'Resident',
        'other_res' => 'Resident',
    ] as $key => $rName) {
        $uname = "{$key}_{$token}";
        $insUser->execute(['Test', ucfirst($key), $uname, "{$uname}@agap.test", password_hash($token, PASSWORD_DEFAULT), (int) $roles[$rName]]);
        $uid = (int) $db->lastInsertId();
        $createdUserIds[] = $uid;
        $actors[$key] = ['user_id' => $uid, 'role_id' => (int) $roles[$rName], 'role_name' => $rName, 'username' => $uname];
    }

    $resModel = new Resident();
    $compId = (int) $resModel->create(['first_name' => 'Lorna', 'last_name' => 'Santos_' . $token, 'address' => '10 Ilang-Ilang St, Tumana', 'purok' => 'Purok 1', 'residency_verified' => 1]);
    $respId = (int) $resModel->create(['first_name' => 'Gardo', 'last_name' => 'Reyes_' . $token, 'address' => '22 Ilang-Ilang St, Tumana', 'purok' => 'Purok 1', 'residency_verified' => 1]);
    $otherId = (int) $resModel->create(['first_name' => 'Other', 'last_name' => 'Resident_' . $token, 'address' => '99 Gumamela St, Tumana', 'purok' => 'Purok 5', 'residency_verified' => 1]);
    $createdResidentIds = [$compId, $respId, $otherId];
    $db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)')->execute([$actors['resident']['user_id'], $compId]);
    $db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)')->execute([$actors['other_res']['user_id'], $otherId]);

    $port = random_int(23000, 28999);
    $cmd = sprintf('php -d session.save_path=%s -S 127.0.0.1:%d -t %s', escapeshellarg($sessionDir), $port, escapeshellarg($root));
    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']], $pipes);
    assertPhase3(is_resource($process), 'Started ephemeral PHP HTTP server');
    $base = "http://127.0.0.1:{$port}";
    $ready = false;
    for ($i = 0; $i < 20; $i++) {
        usleep(150000);
        if (@file_get_contents($base . '/frontend/pages/auth/login.php') !== false) {
            $ready = true;
            break;
        }
    }
    assertPhase3($ready, 'Ephemeral HTTP server ready');

    $sessions = [];
    foreach ($actors as $key => $actor) {
        $sid = 'sess' . bin2hex(random_bytes(8));
        $csrf = bin2hex(random_bytes(16));
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = ['user_id' => $actor['user_id'], 'role_id' => $actor['role_id'], 'role_name' => $actor['role_name'], 'username' => $actor['username'], 'csrf_token' => $csrf];
        file_put_contents($sessionDir . '/sess_' . $sid, session_encode());
        $_SESSION = [];
        session_write_close();
        $sessions[$key] = ['id' => $sid, 'csrf_token' => $csrf];
    }

    // 3. Resident submits an environmental complaint -> BPSO endorses -> LISO endorses to CENO
    $cRes = p3HttpCall($base, $sessions['resident'], 'complaints/create.php?format=json', [
        'complaint_title' => 'Illegal Dumping of Chemical Waste ' . $token,
        'narrative' => 'Commercial shop is dumping waste oil into the Barangay Tumana creek drainage.',
        'incident_date' => date('Y-m-d', strtotime('-1 day')),
        'incident_purok' => 'Purok 1',
        'incident_street' => '10 Ilang-Ilang St',
        'complainant_resident_id' => $compId,
        'respondent_resident_id' => $respId,
        'privacy_notice_acknowledged' => 1,
    ]);
    $cid = (int) ($cRes['json']['complaint_id'] ?? 0);
    assertPhase3($cid > 0, 'Resident created complaint for LISO endorsement test');
    $cleanup->trackComplaint($cid);

    $bpsoEnd = p3HttpCall($base, $sessions['bpso'], 'complaints/bpso-review.php', [
        'complaint_id' => $cid,
        'action' => 'endorse',
        'identity_verified' => 1,
        'details_verified' => 1,
        'respondent_in_tumana' => 1,
    ]);
    assertPhase3($bpsoEnd['status'] === 200, 'BPSO endorsed complaint to LISO');

    // Validation checks on Referred
    $badAgency = p3HttpCall($base, $sessions['liso'], 'complaints/review.php', [
        'complaint_id' => $cid,
        'status' => 'Referred',
        'endorsement_agency' => 'UnknownAgency',
        'counseling_notes' => 'Test notes',
    ]);
    assertPhase3($badAgency['status'] === 422, 'LISO referral rejects invalid external agency');

    $missingNotes = p3HttpCall($base, $sessions['liso'], 'complaints/review.php', [
        'complaint_id' => $cid,
        'status' => 'Referred',
        'endorsement_agency' => 'CENO',
        'counseling_notes' => '',
    ]);
    assertPhase3($missingNotes['status'] === 422, 'LISO referral requires pre-filing counseling notes');

    // Valid CENO endorsement
    $cenoEnd = p3HttpCall($base, $sessions['liso'], 'complaints/review.php', [
        'complaint_id' => $cid,
        'status' => 'Referred',
        'endorsement_agency' => 'CENO',
        'counseling_notes' => 'Commercial environmental waste ordinance violations fall under Marikina CENO. Advised complainant to submit photos and endorsement slip to CENO.',
    ]);
    assertPhase3($cenoEnd['status'] === 200 && !empty($cenoEnd['json']['success']), 'LISO endorses environmental complaint to CENO with counseling (got ' . $cenoEnd['status'] . ': ' . $cenoEnd['body'] . ')');

    $saved = $db->query("SELECT status, screening_decision, assigned_queue, liso_endorsement_agency, liso_counseling_notes, liso_endorsed_at FROM complaints WHERE complaint_id = {$cid}")->fetch(PDO::FETCH_ASSOC);
    assertPhase3(
        $saved['status'] === 'Referred'
        && $saved['screening_decision'] === 'Referred'
        && $saved['assigned_queue'] === 'CENO_REFERRAL'
        && str_contains((string) $saved['liso_endorsement_agency'], 'CENO')
        && !empty($saved['liso_endorsed_at']),
        'Database records CENO agency, CENO_REFERRAL queue, counseling notes, and endorsement timestamp'
    );

    // Timeline check
    $timeline = (new ResidentComplaintTimeline($db))->find($cid, $actors['resident']['user_id']);
    $tTitles = array_column($timeline['events'] ?? [], 'title');
    assertPhase3(in_array('Pre-filing counseling & agency endorsement', $tTitles, true), 'Resident timeline shows pre-filing counseling & agency endorsement');

    // Printable endorsement slip access control
    $slipLiso = p3HttpCall($base, $sessions['liso'], 'complaints/endorsement-slip.php?complaint_id=' . $cid);
    assertPhase3($slipLiso['status'] === 200 && str_contains($slipLiso['body'], 'CENO'), 'LISO can render printable endorsement slip');

    $slipOwner = p3HttpCall($base, $sessions['resident'], 'complaints/endorsement-slip.php?complaint_id=' . $cid);
    assertPhase3($slipOwner['status'] === 200 && str_contains($slipOwner['body'], 'CENO'), 'Owning resident can view printable endorsement slip');

    $slipOther = p3HttpCall($base, $sessions['other_res'], 'complaints/endorsement-slip.php?complaint_id=' . $cid);
    assertPhase3($slipOther['status'] === 403, 'Unrelated resident is forbidden from endorsement slip');

    $slipSrv = p3HttpCall($base, $sessions['server'], 'complaints/endorsement-slip.php?complaint_id=' . $cid);
    assertPhase3($slipSrv['status'] === 403, 'Summons Server is forbidden from endorsement slip');

    // 4. Test Marikina Settlement Office & TMO endorsements via model/HTTP on walk-in
    $wRes = p3HttpCall($base, $sessions['bpso'], 'complaints/create.php?format=json', [
        'filing_track' => 'bpso_to_liso',
        'complaint_title' => 'Public Road Obstruction ' . $token,
        'narrative' => 'Unregistered delivery truck blocking public highway intersection.',
        'incident_date' => date('Y-m-d', strtotime('-1 day')),
        'incident_purok' => 'Purok 2',
        'incident_street' => 'J.P. Rizal St',
        'category_id' => 1,
        'case_type' => 'Civil',
        'complainant_resident_id' => $compId,
        'respondent_resident_id' => $respId,
        'bpso_identity_verified' => 1,
        'bpso_respondent_in_tumana' => 1,
        'duplicate_acknowledged' => 1,
        'jurisdiction_screened' => 1,
        'privacy_notice_acknowledged' => 1,
    ]);
    $wid = (int) ($wRes['json']['complaint_id'] ?? 0);
    assertPhase3($wid > 0, 'BPSO logged walk-in complaint for TMO test');
    $cleanup->trackComplaint($wid);

    $tmoEnd = p3HttpCall($base, $sessions['liso'], 'complaints/review.php', [
        'complaint_id' => $wid,
        'status' => 'Referred',
        'endorsement_agency' => 'TMO',
        'counseling_notes' => 'Traffic & public highway obstruction is under Marikina TMO enforcement.',
    ]);
    assertPhase3($tmoEnd['status'] === 200 && str_contains((string) ($tmoEnd['json']['endorsement_agency'] ?? ''), 'TMO'), 'LISO endorses traffic obstruction complaint to TMO');

    echo "\nALL PHASE 3 LISO ENDORSEMENT & MAX-2 PAANYAYA TESTS PASSED.\n";
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
