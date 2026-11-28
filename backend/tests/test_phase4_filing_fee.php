<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Resident.php';
require_once __DIR__ . '/../services/FilingFeeService.php';
require_once __DIR__ . '/../services/ResidentComplaintTimeline.php';
require_once __DIR__ . '/TestDataCleanup.php';

function assertPhase4(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException("FAILED: {$msg}");
    }
    echo "PASS: {$msg}\n";
}

function p4HttpCall(string $base, ?array $session, string $route, ?array $postData = null, bool $jsonBody = false): array
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
    return ['status' => $status, 'body' => $body, 'json' => json_decode((string) $body, true)];
}

$db = (new Database())->connect();
$token = 'p4-' . bin2hex(random_bytes(5));
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/agap-p4-sessions-' . $token;
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0770, true);
}
$logPath = sys_get_temp_dir() . '/agap-p4-http-' . $token . '.log';

$cleanup = new TestDataCleanup($db);
$createdUserIds = [];
$createdResidentIds = [];
$process = null;
$origRequireSetting = (string) $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'require_filing_fee_before_docket'")->fetchColumn();

try {
    $feeSvc = new FilingFeeService($db);
    assertPhase4($feeSvc->getDefaultFeeAmount() >= 0.0, 'FilingFeeService reads default KP Form 7 fee amount');

    $roles = $db->query('SELECT role_name, role_id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    $insUser = $db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
    $actors = [];
    foreach ([
        'clerk' => 'Lupon Clerk',
        'bpso' => 'BPSO',
        'liso' => 'Lupon Member',
        'resident' => 'Resident',
    ] as $key => $rName) {
        $uname = "{$key}_{$token}";
        $insUser->execute(['Test', ucfirst($key), $uname, "{$uname}@agap.test", password_hash($token, PASSWORD_DEFAULT), (int) $roles[$rName]]);
        $uid = (int) $db->lastInsertId();
        $createdUserIds[] = $uid;
        $actors[$key] = ['user_id' => $uid, 'role_id' => (int) $roles[$rName], 'role_name' => $rName, 'username' => $uname];
    }

    $resModel = new Resident();
    $compId = (int) $resModel->create(['first_name' => 'Celia', 'last_name' => 'Cruz_' . $token, 'address' => '15 Mabini St, Tumana', 'purok' => 'Purok 1', 'residency_verified' => 1]);
    $respId = (int) $resModel->create(['first_name' => 'Marco', 'last_name' => 'Bautista_' . $token, 'address' => '19 Mabini St, Tumana', 'purok' => 'Purok 1', 'residency_verified' => 1]);
    $createdResidentIds = [$compId, $respId];
    $db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)')->execute([$actors['resident']['user_id'], $compId]);
    $catId = (int) $db->query('SELECT category_id FROM complaint_categories WHERE is_active = 1 ORDER BY category_id ASC LIMIT 1')->fetchColumn();

    $port = random_int(23000, 28999);
    $cmd = sprintf('php -d session.save_path=%s -S 127.0.0.1:%d -t %s', escapeshellarg($sessionDir), $port, escapeshellarg($root));
    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']], $pipes);
    assertPhase4(is_resource($process), 'Started ephemeral PHP HTTP server');
    $base = "http://127.0.0.1:{$port}";
    $ready = false;
    for ($i = 0; $i < 20; $i++) {
        usleep(150000);
        if (@file_get_contents($base . '/frontend/pages/auth/login.php') !== false) {
            $ready = true;
            break;
        }
    }
    assertPhase4($ready, 'Ephemeral HTTP server ready');

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

    // 1. Create eligible complaint via Resident -> BPSO -> LISO
    $cRes = p4HttpCall($base, $sessions['resident'], 'complaints/create.php?format=json', [
        'complaint_title' => 'Unpaid Loan ' . $token,
        'narrative' => 'Neighbor failed to repay PHP 3,500 loan after due date in Barangay Tumana.',
        'incident_date' => date('Y-m-d', strtotime('-2 days')),
        'incident_purok' => 'Purok 1',
        'incident_street' => '15 Mabini St',
        'complainant_resident_id' => $compId,
        'respondent_resident_id' => $respId,
        'privacy_notice_acknowledged' => 1,
    ]);
    $cid = (int) ($cRes['json']['complaint_id'] ?? 0);
    assertPhase4($cid > 0, 'Created online complaint');
    $cleanup->trackComplaint($cid);

    p4HttpCall($base, $sessions['bpso'], 'complaints/bpso-review.php', [
        'complaint_id' => $cid,
        'action' => 'endorse',
        'identity_verified' => 1,
        'details_verified' => 1,
        'respondent_in_tumana' => 1,
    ]);
    p4HttpCall($base, $sessions['liso'], 'complaints/review.php', [
        'complaint_id' => $cid,
        'status' => 'Accepted',
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complaint_title' => 'Unpaid Loan ' . $token,
        'review_notes' => 'Eligible for KP.',
    ]);

    // Advance complaint through prepared Paanyaya 1 dialogue with Escalate_Mediation minutes
    $clerkId = $actors['clerk']['user_id'];
    $db->prepare("UPDATE complaints SET status = 'Pre-Docketed (Paanyaya)' WHERE complaint_id = ?")
        ->execute([$cid]);
    $db->prepare("INSERT INTO paanyaya_preparations (complaint_id, invitation_number, appointment_at, venue, verified_parties, document_pdf, prepared_by, ready_by, ready_at)
                  VALUES (?, 1, DATE_ADD(NOW(), INTERVAL 1 DAY), 'Barangay Tumana Session Hall', '{}', '%PDF-1.4', ?, ?, NOW())")
        ->execute([$cid, $clerkId, $clerkId]);
    $db->prepare("INSERT INTO paanyaya_minutes (complaint_id, stage_type, dialogue_date, discussion_summary, outcome, complainant_choice, recorded_by)
                  VALUES (?, 'Paanyaya 1', NOW(), 'Parties failed to settle during Paanyaya 1 dialogue.', 'No_Agreement', 'Escalate_Mediation', ?)")
        ->execute([$cid, $clerkId]);
    $db->prepare("INSERT INTO paanyaya_stages (complaint_id, stage_type, status, issued_by, issued_at) VALUES (?, 'Paanyaya 1', 'Completed', ?, NOW())")
        ->execute([$cid, $clerkId]);

    // 2. RBAC on record-filing-fee.php
    $bpsoFeeTry = p4HttpCall($base, $sessions['bpso'], 'complaints/record-filing-fee.php', [
        'complaint_id' => $cid,
        'filing_fee_status' => 'Paid',
        'filing_fee_or_number' => 'OR-999',
    ], true);
    assertPhase4($bpsoFeeTry['status'] === 403, 'BPSO is forbidden from recording KP Form 7 filing fee');

    $resFeeTry = p4HttpCall($base, $sessions['resident'], 'complaints/record-filing-fee.php', [
        'complaint_id' => $cid,
        'filing_fee_status' => 'Paid',
        'filing_fee_or_number' => 'OR-999',
    ], true);
    assertPhase4($resFeeTry['status'] === 403, 'Resident is forbidden from recording KP Form 7 filing fee');

    // 3. Docketing blocked when enforce_filing_fee=1 and Unpaid
    $blockedDocket = p4HttpCall($base, $sessions['clerk'], 'complaints/docket.php', [
        'complaint_id' => $cid,
        'enforce_filing_fee' => 1,
    ]);
    assertPhase4($blockedDocket['status'] === 422 && str_contains((string) ($blockedDocket['json']['message'] ?? ''), 'filing fee'), 'Docketing blocked when enforce_filing_fee=1 and fee is Unpaid');

    // 4. Enable strict setting and verify docketing still blocked without fee
    $db->exec("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'require_filing_fee_before_docket'");
    $strictBlocked = p4HttpCall($base, $sessions['clerk'], 'complaints/docket.php', [
        'complaint_id' => $cid,
    ]);
    assertPhase4($strictBlocked['status'] === 422 && str_contains((string) ($strictBlocked['json']['message'] ?? ''), 'filing fee'), 'Strict setting blocks docketing even without enforce_filing_fee param');

    // 5. Validation on Paid without OR number
    $badPaid = p4HttpCall($base, $sessions['clerk'], 'complaints/record-filing-fee.php', [
        'complaint_id' => $cid,
        'filing_fee_status' => 'Paid',
        'filing_fee_or_number' => '',
    ], true);
    assertPhase4($badPaid['status'] === 422, 'Paid filing fee requires Official Receipt (OR) Number');

    // 6. Clerk records OR payment -> docketing succeeds -> timeline shows fee
    $goodPaid = p4HttpCall($base, $sessions['clerk'], 'complaints/record-filing-fee.php', [
        'complaint_id' => $cid,
        'filing_fee_status' => 'Paid',
        'filing_fee_or_number' => 'OR-2026-00777',
        'filing_fee_amount' => '100.00',
    ], true);
    assertPhase4($goodPaid['status'] === 200 && ($goodPaid['json']['filing_fee']['filing_fee_status'] ?? '') === 'Paid', 'Clerk records official filing fee with OR Number');

    $docketOk = p4HttpCall($base, $sessions['clerk'], 'complaints/docket.php', [
        'complaint_id' => $cid,
    ]);
    assertPhase4($docketOk['status'] === 200 && !empty($docketOk['json']['case_id']) && !empty($docketOk['json']['case_number']), 'Formal KP Form No. 7 docketing succeeds after filing fee payment');

    $timeline = (new ResidentComplaintTimeline($db))->find($cid, $actors['resident']['user_id']);
    $tTitles = array_column($timeline['events'] ?? [], 'title');
    $tDetails = implode(' ', array_column($timeline['events'] ?? [], 'detail'));
    assertPhase4(in_array('KP Form No. 7 filing fee recorded', $tTitles, true) && str_contains($tDetails, 'OR-2026-00777'), 'Resident timeline displays recorded filing fee OR number');

    // 7. Test Indigency Exemption inline on direct formal mediation track
    $exemptDirect = p4HttpCall($base, $sessions['clerk'], 'complaints/create.php?format=json', [
        'filing_track' => 'formal_mediation',
        'complaint_title' => 'Indigent Direct Formal ' . $token,
        'narrative' => 'Direct formal filing with barangay indigency certificate exemption.',
        'incident_date' => date('Y-m-d', strtotime('-2 days')),
        'incident_purok' => 'Purok 1',
        'incident_street' => '15 Mabini St',
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complainant_resident_id' => $compId,
        'respondent_resident_id' => $respId,
        'duplicate_acknowledged' => 1,
        'jurisdiction_screened' => 1,
        'privacy_notice_acknowledged' => 1,
        'filing_fee_status' => 'Exempt / Indigent',
        'filing_fee_waiver_reason' => 'Barangay Indigency Cert #2026-01',
    ]);
    $eid = (int) ($exemptDirect['json']['complaint_id'] ?? 0);
    assertPhase4($exemptDirect['status'] === 201 && $eid > 0 && !empty($exemptDirect['json']['case_id']), 'Direct formal mediation filing succeeds with Exempt / Indigent status');
    $cleanup->trackComplaint($eid);
    $feeRow = $db->query("SELECT filing_fee_status, filing_fee_waiver_reason FROM complaints WHERE complaint_id = {$eid}")->fetch(PDO::FETCH_ASSOC);
    assertPhase4($feeRow['filing_fee_status'] === 'Exempt / Indigent' && str_contains((string) $feeRow['filing_fee_waiver_reason'], 'Indigency'), 'Exempt / Indigent filing fee metadata saved cleanly');

    echo "\nALL PHASE 4 FILING FEE GATE TESTS PASSED.\n";
} finally {
    $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'require_filing_fee_before_docket'")
        ->execute([$origRequireSetting !== '' ? $origRequireSetting : '0']);
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
