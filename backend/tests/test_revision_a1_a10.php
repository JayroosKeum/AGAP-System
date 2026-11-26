<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PiiCryptoService.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/ComplaintRoutingService.php';
require_once __DIR__ . '/../services/AnonymousConcernService.php';
require_once __DIR__ . '/../services/ClosureWorkflowService.php';
require_once __DIR__ . '/../services/PrivacyGovernanceService.php';
require_once __DIR__ . '/../models/Resident.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/ComplaintParty.php';
require_once __DIR__ . '/TestDataCleanup.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAILED: {$message}");
    }
    echo "PASS: {$message}\n";
}

function httpCall(string $base, ?array $session, string $route, ?array $postData = null, bool $jsonBody = false): array
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
$token = 'rev-' . bin2hex(random_bytes(5));
$root = dirname(__DIR__, 2);
$sessionDir = sys_get_temp_dir() . '/agap-rev-sessions-' . $token;
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0770, true);
}
$logPath = sys_get_temp_dir() . '/agap-rev-http-' . $token . '.log';

$createdUserIds = [];
$createdResidentIds = [];
$createdComplaintIds = [];
$createdAnonymousIds = [];
$createdDocFiles = [];
$process = null;

try {
    // =====================================================
    // SETUP TEST USERS & EPHEMERAL HTTP SERVER
    // =====================================================
    $roles = $db->query('SELECT role_name, role_id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    $hasSuperAdminRole = isset($roles['Super Administrator']);

    $insUser = $db->prepare(
        "INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
         VALUES (?, ?, ?, ?, ?, ?, 'Active')"
    );
    $actors = [];
    foreach ([
        'superadmin' => 'Super Administrator',
        'admin' => 'Administrator',
        'clerk' => 'Lupon Clerk',
        'lupon_assigned' => 'Lupon Member',
        'lupon_other' => 'Lupon Member',
        'server_other' => 'Summons Server',
        'resident_owner' => 'Resident',
        'resident_other' => 'Resident',
    ] as $key => $roleName) {
        $uname = "{$key}_{$token}";
        $email = "{$uname}@agap.test";
        $insUser->execute(['Test', ucfirst($key), $uname, $email, password_hash($token, PASSWORD_DEFAULT), (int) $roles[$roleName]]);
        $uid = (int) $db->lastInsertId();
        $createdUserIds[] = $uid;
        $actors[$key] = [
            'user_id' => $uid,
            'role_id' => (int) $roles[$roleName],
            'role_name' => $roleName,
            'id' => "{$token}-" . str_replace('_', '-', $key),
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
    }

    // Create encrypted resident fixtures via Resident model
    $resModel = new Resident($db);
    $compResId = (int) $resModel->create([
        'first_name' => 'Juan',
        'last_name' => 'DelaCruz' . substr($token, 0, 6),
        'contact_no' => '09171119876',
        'email' => "juan.{$token}@tumana.test",
        'address' => '101 Mabini St., Barangay Tumana, Marikina City',
        'purok' => 'Purok 1',
    ]);
    $respResId = (int) $resModel->create([
        'first_name' => 'Pedro',
        'last_name' => 'Penduko' . substr($token, 0, 6),
        'contact_no' => '09182224321',
        'email' => "pedro.{$token}@tumana.test",
        'address' => '202 Rizal St., Barangay Tumana, Marikina City',
        'purok' => 'Purok 2',
    ]);
    $createdResidentIds[] = $compResId;
    $createdResidentIds[] = $respResId;

    $db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)')
        ->execute([$actors['resident_owner']['user_id'], $compResId]);

    // Write session files for HTTP server
    ini_set('session.save_path', $sessionDir);
    foreach ($actors as $actor) {
        session_id($actor['id']);
        session_start();
        $_SESSION = array_diff_key($actor, ['id' => true]);
        session_write_close();
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open(
        [PHP_BINARY, '-d', 'error_reporting=E_ALL&~E_DEPRECATED', '-d', "session.save_path={$sessionDir}", '-S', $address, '-t', $root],
        [1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
        $pipes,
        $root
    );
    for ($i = 0; $i < 30; $i++) {
        $probe = @fsockopen('127.0.0.1', (int) explode(':', (string) $address)[1], $errno, $errstr, 0.2);
        if (is_resource($probe)) {
            fclose($probe);
            break;
        }
        usleep(100000);
    }
    $base = "http://{$address}";
    assertTrue($hasSuperAdminRole, 'Super Administrator role exists in roles table');

    // =====================================================
    // A6: FAIL-CLOSED CRYPTO, CIPHERTEXT IN DB, DECRYPT ON READ, KEY ROTATION
    // =====================================================
    $unconfigured = new PiiCryptoService('');
    assertTrue(!$unconfigured->isConfigured(), 'Unconfigured PiiCryptoService reports not configured');
    $failClosed = false;
    try {
        $unconfigured->encrypt('09171119876');
    } catch (RuntimeException $e) {
        $failClosed = true;
    }
    assertTrue($failClosed, 'PiiCryptoService::encrypt fails closed (throws) when APP_PII_KEY is missing');

    $rawRes = $db->prepare('SELECT contact_no, contact_no_hash, contact_last4_hash, email, address FROM residents WHERE resident_id = ?');
    $rawRes->execute([$compResId]);
    $rawRow = $rawRes->fetch(PDO::FETCH_ASSOC);
    assertTrue(PiiCryptoService::isEncrypted($rawRow['contact_no']) && $rawRow['contact_no'] !== '09171119876', 'residents.contact_no stored as AES-256-GCM ciphertext');
    assertTrue(PiiCryptoService::isEncrypted($rawRow['email']) && PiiCryptoService::isEncrypted($rawRow['address']), 'residents.email and address stored as AES-256-GCM ciphertext');
    assertTrue(!empty($rawRow['contact_no_hash']) && !empty($rawRow['contact_last4_hash']), 'Blind index hashes populated for contact lookup');

    $decRes = $resModel->getById($compResId);
    assertTrue($decRes['contact_no'] === '09171119876' && $decRes['address'] === '101 Mabini St., Barangay Tumana, Marikina City', 'Authorized Resident::getById decrypts PII fields cleanly');

    // Key rotation test
    $oldCrypto = new PiiCryptoService('old-key-alpha');
    $oldCipher = $oldCrypto->encrypt('Sensitive Value 123');
    $rotator = new PiiCryptoService('new-key-beta', ['old-key-alpha']);
    $rotatedCipher = $rotator->rotate($oldCipher);
    assertTrue($rotatedCipher !== $oldCipher && (new PiiCryptoService('new-key-beta'))->decrypt($rotatedCipher) === 'Sensitive Value 123', 'Key rotation decrypts legacy key and re-encrypts with new primary key');

    // =====================================================
    // A1, A2, A3: AUTOMATED ROUTING PERSISTENCE, EXCEPTION QUEUE, OVERRIDE & PRIVACY
    // =====================================================
    $complaintModel = new Complaint($db);
    $catId = (int) $db->query('SELECT category_id FROM complaint_categories LIMIT 1')->fetchColumn();

    $_SESSION = array_diff_key($actors['clerk'], ['id' => true]);
    $vawcCreate = $complaintModel->create([
        'category_id' => $catId,
        'case_type' => 'Criminal',
        'complaint_title' => 'Domestic violence concern ' . $token,
        'incident_date' => date('Y-m-d'),
        'incident_street' => 'Mabini St',
        'incident_purok' => 'Purok 1',
        'narrative' => 'Physical abuse by husband and economic abuse at home.',
        'complainant_resident_id' => $compResId,
        'respondent_resident_id' => $respResId,
        'jurisdiction_screened' => '1',
        'jurisdiction_complainant_resides' => '1',
        'jurisdiction_respondent_resides' => '1',
        'privacy_notice_acknowledged' => '1',
    ]);
    assertTrue(!empty($vawcCreate['success']), 'Complaint creation succeeds with automated routing');
    $vawcId = (int) $vawcCreate['complaint_id'];
    $createdComplaintIds[] = $vawcId;

    $rRow = $db->prepare('SELECT routing_route, assigned_queue, routing_rule_version, routed_at, privacy_notice_acknowledged, privacy_notice_version FROM complaints WHERE complaint_id = ?');
    $rRow->execute([$vawcId]);
    $rData = $rRow->fetch(PDO::FETCH_ASSOC);
    assertTrue($rData['routing_route'] === 'VAWC' && $rData['assigned_queue'] === 'VAWC_REFERRAL_DESK', 'Intake persists VAWC route and VAWC_REFERRAL_DESK queue automatically');
    assertTrue($rData['routing_rule_version'] === ComplaintRoutingService::RULE_VERSION && !empty($rData['routed_at']), 'Intake persists rule version and routing timestamp');
    assertTrue((int) $rData['privacy_notice_acknowledged'] === 1 && $rData['privacy_notice_version'] === PrivacyGovernanceService::NOTICE_VERSION, 'A3 privacy notice version and acknowledgement persisted');

    $logCount = (int) $db->query("SELECT COUNT(*) FROM complaint_routing_logs WHERE complaint_id = {$vawcId} AND event_type = 'Automated'")->fetchColumn();
    assertTrue($logCount === 1, 'Automated routing decision logged in complaint_routing_logs');

    // Ambiguous / no-match intake goes to REVIEW_EXCEPTION_QUEUE
    $ambigCreate = $complaintModel->create([
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complaint_title' => 'General inquiry ' . $token,
        'incident_date' => date('Y-m-d', strtotime('-2 days')),
        'incident_street' => 'Rizal St',
        'incident_purok' => 'Purok 2',
        'narrative' => 'Nais ko lang pong humingi ng payo sa barangay tungkol sa sitwasyon.',
        'complainant_resident_id' => $compResId,
        'respondent_resident_id' => $respResId,
        'jurisdiction_screened' => '1',
        'jurisdiction_complainant_resides' => '1',
        'jurisdiction_respondent_resides' => '1',
        'duplicate_acknowledged' => '1',
    ]);
    assertTrue(!empty($ambigCreate['success']), 'Ambiguous intake created');
    $ambigId = (int) $ambigCreate['complaint_id'];
    $createdComplaintIds[] = $ambigId;
    $ambigQueue = $db->query("SELECT assigned_queue FROM complaints WHERE complaint_id = {$ambigId}")->fetchColumn();
    assertTrue($ambigQueue === 'REVIEW_EXCEPTION_QUEUE', 'Ambiguous/no-match narrative is placed in REVIEW_EXCEPTION_QUEUE');

    // Human routing override via HTTP endpoint
    $overrideRes = httpCall($base, $actors['clerk'], 'complaints/override-routing.php', [
        'complaint_id' => $ambigId,
        'assigned_queue' => 'LUPON_PAANYAYA_PREP',
        'reason' => 'Confirmed KP civil dispute after clerk review',
    ]);
    assertTrue($overrideRes['status'] === 200 && !empty($overrideRes['json']['success']), 'Human routing override endpoint succeeds');
    $overrideLogCount = (int) $db->query("SELECT COUNT(*) FROM complaint_routing_logs WHERE complaint_id = {$ambigId} AND event_type = 'Override'")->fetchColumn();
    assertTrue($overrideLogCount === 1, 'Human routing override recorded in complaint_routing_logs');

    // A6 Tracker blind-index verification
    $vawcRef = (string) $vawcCreate['intake_reference'];
    $trackOk = httpCall($base, null, 'public/track-complaint.php', ['reference' => $vawcRef, 'verifier' => '9876'], true);
    assertTrue($trackOk['status'] === 200 && !empty($trackOk['json']['success']) && !isset($trackOk['json']['record']['contact_no']), 'Public tracker verifies encrypted contact last 4 digits via blind index with zero PII disclosed');

    // =====================================================
    // A4: CASE-LEVEL RBAC AUTHORIZATION (HTTP NEGATIVE & POSITIVE TESTS)
    // =====================================================
    // Create an online complaint owned by resident_owner, docket a formal case, assign lupon_assigned, and generate a document
    $_SESSION = array_diff_key($actors['resident_owner'], ['id' => true]);
    $ownedCreate = $complaintModel->create([
        'category_id' => $catId,
        'case_type' => 'Civil',
        'complaint_title' => 'Owned noise complaint ' . $token,
        'incident_date' => date('Y-m-d'),
        'incident_street' => 'Mabini St',
        'incident_purok' => 'Purok 1',
        'narrative' => 'Loud karaoke past midnight disturbing neighbors.',
        'respondent_first_name' => 'Pedro',
        'respondent_last_name' => 'Penduko' . substr($token, 0, 6),
        'respondent_resident_id' => $respResId,
        'privacy_notice_acknowledged' => '1',
    ]);
    assertTrue(!empty($ownedCreate['success']), 'Resident owner created online complaint');
    $ownedCid = (int) $ownedCreate['complaint_id'];
    $createdComplaintIds[] = $ownedCid;

    $db->prepare("INSERT INTO cases (complaint_id, case_number, case_type, case_status, docket_date) VALUES (?, ?, 'Civil', 'Mediation', CURDATE())")
        ->execute([$ownedCid, 'CASE-' . $token]);
    $caseId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, 'Mediator', CURDATE())")
        ->execute([$caseId, $actors['lupon_assigned']['user_id']]);

    $tmplId = (int) ($db->query("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 8' LIMIT 1")->fetchColumn() ?: 1);
    $relDocPath = "storage/generated-documents/test-{$token}.pdf";
    $absDocPath = "{$root}/{$relDocPath}";
    if (!is_dir(dirname($absDocPath))) {
        mkdir(dirname($absDocPath), 0750, true);
    }
    file_put_contents($absDocPath, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $createdDocFiles[] = $absDocPath;
    $db->prepare('INSERT INTO generated_documents (case_id, template_id, generated_by, file_path) VALUES (?, ?, ?, ?)')
        ->execute([$caseId, $tmplId, $actors['clerk']['user_id'], $relDocPath]);
    $docId = (int) $db->lastInsertId();

    // Negative ID tampering checks
    assertTrue(httpCall($base, $actors['lupon_other'], "complaints/view.php?id={$ownedCid}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from viewing complaint by ID');
    assertTrue(httpCall($base, $actors['lupon_other'], "cases/view.php?id={$caseId}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from viewing case by ID');
    assertTrue(httpCall($base, $actors['lupon_other'], "documents/download.php?id={$docId}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from downloading case document');
    assertTrue(httpCall($base, $actors['server_other'], "cases/view.php?id={$caseId}")['status'] === 403, 'A4: Unassigned Summons Server is blocked (403) from viewing case by ID');
    assertTrue(httpCall($base, $actors['server_other'], "documents/download.php?id={$docId}")['status'] === 403, 'A4: Unassigned Summons Server is blocked (403) from downloading document by ID');
    assertTrue(httpCall($base, $actors['resident_other'], "complaints/view.php?id={$ownedCid}")['status'] === 403, 'A4: Different Resident is blocked (403) from viewing another resident complaint');

    // Positive authorized checks
    assertTrue(httpCall($base, $actors['lupon_assigned'], "complaints/view.php?id={$ownedCid}")['status'] === 200, 'A4: Assigned Lupon Member can view assigned complaint (200)');
    assertTrue(httpCall($base, $actors['lupon_assigned'], "cases/view.php?id={$caseId}")['status'] === 200, 'A4: Assigned Lupon Member can view assigned case (200)');
    assertTrue(httpCall($base, $actors['lupon_assigned'], "documents/download.php?id={$docId}")['status'] === 200, 'A4: Assigned Lupon Member can download assigned case document (200)');
    assertTrue(httpCall($base, $actors['resident_owner'], "complaints/view.php?id={$ownedCid}")['status'] === 200, 'A4: Owning Resident can view their own complaint (200)');

    // =====================================================
    // A5: ANONYMOUS REPORTING & HASHED-TOKEN FOLLOW-UP
    // =====================================================
    $anonSub = httpCall($base, null, 'public/anonymous-submit.php', [
        'concern_title' => 'Anonymous street lighting & noise report ' . $token,
        'narrative' => 'Repeated loud disturbance near Purok 3 alley after midnight.',
        'barangay_area' => 'Purok 3',
    ], true);
    assertTrue($anonSub['status'] === 201 && !empty($anonSub['json']['follow_up_token']), 'A5: Anonymous submission succeeds and returns one-time follow-up token');
    $anonId = (int) $anonSub['json']['concern_id'];
    $createdAnonymousIds[] = $anonId;
    $anonRef = (string) $anonSub['json']['reference_code'];
    $anonToken = (string) $anonSub['json']['follow_up_token'];

    $storedHash = (string) $db->query("SELECT token_hash FROM anonymous_concerns WHERE concern_id = {$anonId}")->fetchColumn();
    assertTrue($storedHash !== $anonToken && hash_equals($storedHash, hash('sha256', $anonToken)), 'A5: Database stores only SHA-256 hash of anonymous token');

    $anonTrack = httpCall($base, null, 'public/anonymous-track.php', [
        'reference_code' => $anonRef,
        'follow_up_token' => $anonToken,
    ], true);
    assertTrue($anonTrack['status'] === 200 && ($anonTrack['json']['record']['status'] ?? '') === 'Submitted', 'A5: Anonymous token tracking returns safe milestone without PII');

    // Brute-force lockout check
    for ($attempt = 1; $attempt <= AnonymousConcernService::MAX_FAILED_ATTEMPTS; $attempt++) {
        $badTrack = httpCall($base, null, 'public/anonymous-track.php', [
            'reference_code' => $anonRef,
            'follow_up_token' => 'wrong-token-' . $attempt,
        ], true);
    }
    $lockedTrack = httpCall($base, null, 'public/anonymous-track.php', [
        'reference_code' => $anonRef,
        'follow_up_token' => $anonToken,
    ], true);
    assertTrue($lockedTrack['status'] === 429, 'A5: Anonymous token tracker enforces lockout (429) after repeated invalid attempts');

    // =====================================================
    // A7: IMMUTABLE / TAMPER-EVIDENT AUDIT TRAIL
    // =====================================================
    $auditSvc = new AuditService($db);
    $chainCheck = $auditSvc->verifyChain();
    assertTrue($chainCheck['valid'] === true && $chainCheck['checked'] > 0, 'A7: Cryptographic audit_trails hash chain verifies cleanly');

    $lastAuditId = (int) $db->query('SELECT MAX(audit_id) FROM audit_trails')->fetchColumn();
    $updateBlocked = false;
    try {
        $db->exec("UPDATE audit_trails SET action = 'Tampered Action' WHERE audit_id = {$lastAuditId}");
    } catch (PDOException $e) {
        $updateBlocked = true;
    }
    assertTrue($updateBlocked, 'A7: Database trigger rejects UPDATE on audit_trails');

    $deleteBlocked = false;
    try {
        $db->exec("DELETE FROM audit_trails WHERE audit_id = {$lastAuditId}");
    } catch (PDOException $e) {
        $deleteBlocked = true;
    }
    assertTrue($deleteBlocked, 'A7: Database trigger rejects DELETE on audit_trails');

    // =====================================================
    // A8: SOFT DELETION & SUPER-ADMIN BOUNDARY
    // =====================================================
    $clerkDel = httpCall($base, $actors['clerk'], 'complaints/delete.php', ['id' => $ambigId, 'reason' => 'Clerk attempt']);
    $adminDel = httpCall($base, $actors['admin'], 'complaints/delete.php', ['id' => $ambigId, 'reason' => 'Admin attempt']);
    $clerkDeletedList = httpCall($base, $actors['clerk'], 'complaints/deleted-list.php');
    assertTrue($clerkDel['status'] === 403 && $adminDel['status'] === 403, 'A8: Staff (Clerk & regular Admin) cannot delete complaints via delete.php (403)');
    assertTrue($clerkDeletedList['status'] === 403, 'A8: Staff cannot view deleted-records endpoint (403)');

    $saDel = httpCall($base, $actors['superadmin'], 'complaints/delete.php', ['id' => $ambigId, 'reason' => 'Duplicate test cleanup']);
    assertTrue($saDel['status'] === 200 && !empty($saDel['json']['success']), 'A8: Super Administrator soft-deletes complaint cleanly');

    $deletedCheck = $db->query("SELECT deleted_at, deleted_by, deletion_reason FROM complaints WHERE complaint_id = {$ambigId}")->fetch(PDO::FETCH_ASSOC);
    assertTrue($deletedCheck !== false && $deletedCheck['deleted_at'] !== null && (int) $deletedCheck['deleted_by'] === $actors['superadmin']['user_id'], 'A8: Record preserved in DB with deleted_at, deleted_by, deletion_reason');
    assertTrue(httpCall($base, $actors['clerk'], "complaints/view.php?id={$ambigId}")['status'] === 403, 'A8: Soft-deleted complaint is inaccessible to ordinary staff via view.php');

    $saList = httpCall($base, $actors['superadmin'], 'complaints/deleted-list.php');
    $foundInSaList = array_filter($saList['json']['deleted_complaints'] ?? [], fn ($r) => (int) $r['complaint_id'] === $ambigId);
    assertTrue($saList['status'] === 200 && count($foundInSaList) === 1, 'A8: Deleted record visible to Super Administrator in deleted-list.php');

    $saRestore = httpCall($base, $actors['superadmin'], 'complaints/restore.php', ['id' => $ambigId, 'reason' => 'Verified valid filing']);
    assertTrue($saRestore['status'] === 200 && !empty($saRestore['json']['success']), 'A8: Super Administrator restores soft-deleted complaint');

    // =====================================================
    // A9 & A10: RESOLUTION DEFINITIONS, PENDING CLOSE, CITIZEN OBJECTION/CONFIRM & TIMEOUT
    // =====================================================
    // A9 guard check: unfulfilled settlement cannot close as Obligation Compliance
    $db->prepare("INSERT INTO settlements (case_id, settlement_date, agreement_details, settlement_status, compliance_status) VALUES (?, CURDATE(), 'Pay 5,000', 'Signed and Attested', 'Pending')")
        ->execute([$caseId]);
    $closureSvc = new ClosureWorkflowService($db);
    $guard = $closureSvc->validateResolutionReadiness($ownedCid, 'Obligation Compliance');
    assertTrue(!$guard['ready'], 'A9: Signed settlement with Pending compliance cannot close as Obligation Compliance');

    // A10 step 1: Staff initiates Pending Close
    $req1 = httpCall($base, $actors['clerk'], 'complaints/closure-request.php', [
        'action' => 'request',
        'complaint_id' => $ownedCid,
        'resolution_type' => 'Administrative Closure',
        'request_reason' => 'Parties reported informal compliance; awaiting complainant verification.',
        'timeout_days' => 5,
    ], true);
    assertTrue($req1['status'] === 201 && !empty($req1['json']['verification_token']), 'A10: Clerk requests Pending Close and generates one-time verification token');
    $closeToken1 = (string) $req1['json']['verification_token'];

    // A10 step 2: Complainant objects via one-time verification token -> blocks closure
    $objRes = httpCall($base, null, 'public/closure-verify.php', [
        'decision' => 'object',
        'verification_token' => $closeToken1,
        'objection_reason' => 'Respondent has not yet paid the agreed amount.',
    ], true);
    assertTrue($objRes['status'] === 200 && ($objRes['json']['status'] ?? '') === 'Objected', 'A10: Complainant objection blocks closure');
    $reopenedStatus = (string) $db->query("SELECT case_status FROM cases WHERE case_id = {$caseId}")->fetchColumn();
    assertTrue($reopenedStatus !== 'Closed' && $reopenedStatus !== 'Pending Close', 'A10: Objection restores case from Pending Close back to active stage');

    // A10 step 3: Staff initiates Pending Close again; owning resident confirms via authenticated portal
    $req2 = httpCall($base, $actors['clerk'], 'complaints/closure-request.php', [
        'action' => 'request',
        'complaint_id' => $ownedCid,
        'resolution_type' => 'Administrative Closure',
        'request_reason' => 'Balance verified paid; requesting final closure.',
    ], true);
    assertTrue($req2['status'] === 201, 'A10: Second Pending Close request created');

    $confRes = httpCall($base, $actors['resident_owner'], 'complaints/closure-request.php', [
        'action' => 'confirm',
        'complaint_id' => $ownedCid,
    ], true);
    assertTrue($confRes['status'] === 200 && ($confRes['json']['complaint_status'] ?? '') === 'Resolution / Closed', 'A10: Owning complainant confirms closure -> Resolution / Closed');

    // A10 step 4: Expired uncontested Pending Close auto-closes idempotently
    $req3 = $closureSvc->requestPendingClose($ambigId, $actors['clerk']['user_id'], [
        'resolution_type' => 'Administrative Closure',
        'request_reason' => 'Uncontested timeout verification test.',
        'expires_at_override' => date('Y-m-d H:i:s', time() - 3600),
    ]);
    assertTrue(!empty($req3['success']), 'A10: Created expired Pending Close request for timeout test');
    $proc1 = $closureSvc->processExpiredRequests();
    $proc2 = $closureSvc->processExpiredRequests();
    assertTrue(in_array((int) $req3['closure_request_id'], $proc1['closed_request_ids'], true), 'A10: Timeout processor closes expired unobjected request');
    assertTrue(!in_array((int) $req3['closure_request_id'], $proc2['closed_request_ids'], true), 'A10: Timeout processor is idempotent on re-run');

    echo "\nALL REVISION A1-A10 END-TO-END CHECKS PASSED.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach ($createdDocFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    // Clean up disposable test rows (note: audit_trails rows remain append-only per A7)
    if ($createdComplaintIds !== []) {
        $in = implode(',', array_map('intval', $createdComplaintIds));
        $db->exec("DELETE FROM closure_requests WHERE complaint_id IN ({$in})");
        $db->exec("DELETE FROM complaint_routing_logs WHERE complaint_id IN ({$in})");
        $db->exec("DELETE FROM generated_documents WHERE case_id IN (SELECT case_id FROM cases WHERE complaint_id IN ({$in}))");
        $db->exec("DELETE FROM settlements WHERE case_id IN (SELECT case_id FROM cases WHERE complaint_id IN ({$in}))");
        $db->exec("DELETE FROM case_assignments WHERE case_id IN (SELECT case_id FROM cases WHERE complaint_id IN ({$in}))");
        $db->exec("DELETE FROM cases WHERE complaint_id IN ({$in})");
        $db->exec("DELETE FROM complaint_parties WHERE complaint_id IN ({$in})");
        $db->exec("DELETE FROM complaint_intake_screenings WHERE complaint_id IN ({$in})");
        $db->exec("DELETE FROM incident_locations WHERE complaint_id IN ({$in})");
        $db->exec("DELETE FROM complaints WHERE complaint_id IN ({$in})");
    }
    if ($createdAnonymousIds !== []) {
        $in = implode(',', array_map('intval', $createdAnonymousIds));
        $db->exec("DELETE FROM anonymous_concerns WHERE concern_id IN ({$in})");
    }
    if ($createdUserIds !== []) {
        $in = implode(',', array_map('intval', $createdUserIds));
        $db->exec("DELETE FROM resident_user_profiles WHERE user_id IN ({$in})");
        $db->exec("DELETE FROM notifications WHERE user_id IN ({$in})");
        $db->exec("DELETE FROM users WHERE user_id IN ({$in})");
    }
    if ($createdResidentIds !== []) {
        $in = implode(',', array_map('intval', $createdResidentIds));
        $db->exec("DELETE FROM residents WHERE resident_id IN ({$in})");
    }
    @unlink($logPath);
    if (is_dir($sessionDir)) {
        foreach (glob($sessionDir . '/*') ?: [] as $sf) {
            @unlink($sf);
        }
        @rmdir($sessionDir);
    }
}
