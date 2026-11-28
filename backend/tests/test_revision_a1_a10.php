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
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/CaseModel.php';
require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/ComplaintParty.php';
require_once __DIR__ . '/../services/ResidentNameLookup.php';
require_once __DIR__ . '/../models/Search.php';
require_once __DIR__ . '/../services/PaanyayaPreparationService.php';
require_once __DIR__ . '/../models/PaanyayaModel.php';
require_once __DIR__ . '/TestDataCleanup.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAILED: {$message}");
    }
    echo "PASS: {$message}\n";
}

class RejectClosureNotification extends NotificationService
{
    public function notifyUser(int $userId, string $title, string $message, ?int $excludeUserId = null, ?string $severity = null, ?string $actionUrl = null, ?int $caseId = null): bool
    { return false; }
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
    $beforeLegacySave = (int) $db->query('SELECT COUNT(*) FROM complaints')->fetchColumn();
    $legacySave = httpCall($base, null, 'complaints/save-complaint.php', ['complaint_id' => 1, 'narrative' => 'Synthetic retired endpoint test.'], true);
    assertTrue($legacySave['status'] === 410 && ($legacySave['json']['code'] ?? '') === 'LEGACY_COMPLAINT_SAVE_RETIRED'
        && (int) $db->query('SELECT COUNT(*) FROM complaints')->fetchColumn() === $beforeLegacySave, 'Legacy complaint-save endpoint rejects writes with HTTP 410');
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

    $rawRes = $db->prepare('/* pii:raw */ SELECT contact_no, contact_no_hash, contact_last4_hash, email, address FROM residents WHERE resident_id = ?');
    $rawRes->execute([$compResId]);
    $rawRow = $rawRes->fetch(PDO::FETCH_ASSOC);
    assertTrue(PiiCryptoService::isEncrypted($rawRow['contact_no']) && $rawRow['contact_no'] !== '09171119876', 'residents.contact_no stored as AES-256-GCM ciphertext');
    assertTrue(PiiCryptoService::isEncrypted($rawRow['email']) && PiiCryptoService::isEncrypted($rawRow['address']), 'residents.email and address stored as AES-256-GCM ciphertext');
    assertTrue(!empty($rawRow['contact_no_hash']) && !empty($rawRow['contact_last4_hash']), 'Blind index hashes populated for contact lookup');

    $decRes = $resModel->getById($compResId);
    $rawName = $db->query("/* pii:raw */ SELECT first_name FROM residents WHERE resident_id = {$compResId}")->fetchColumn();
    assertTrue(is_string($rawName) && str_starts_with($rawName, 'name:v1:'), 'A6: Resident names are ciphertext in actual database storage');
    $nameUpdate = $db->prepare("UPDATE residents\nSET\n first_name = ?\nWHERE resident_id = ?");
    $nameUpdate->bindValue(1, 'Juan');
    $nameUpdate->bindValue(2, $compResId, PDO::PARAM_INT);
    $nameUpdate->execute();
    $updatedName = $db->query("/* pii:raw */ SELECT first_name FROM residents WHERE resident_id = {$compResId}")->fetchColumn();
    assertTrue(str_starts_with($updatedName, 'name:v1:') && (new PiiCryptoService())->decrypt($updatedName) === 'Juan', 'A6: Multiline name updates and bound parameters remain encrypted');
    assertTrue($decRes['first_name'] === 'Juan', 'A6: Authorized resident reads decrypt names');
    assertTrue(ResidentNameLookup::find($db, 'Juan', 'DelaCruz' . substr($token, 0, 6)) === $compResId, 'A6: Name matching still resolves encrypted resident names');
    assertTrue($decRes['contact_no'] === '09171119876' && $decRes['address'] === '101 Mabini St., Barangay Tumana, Marikina City', 'Authorized Resident::getById decrypts PII fields cleanly');

    // Prefix-looking user content is ordinary text, including authentic ciphertext
    // pasted into a narrative. Only selected name columns/captions may decode it.
    $literalText = 'Support reference name:v1:AAAA; literal ' . $rawName;
    $textQuery = $db->prepare('SELECT ? AS narrative, ? AS before_state, ? AS after_state');
    $textQuery->execute([$literalText, $literalText, $literalText]);
    assertTrue($textQuery->fetch(PDO::FETCH_ASSOC) === ['narrative' => $literalText, 'before_state' => $literalText, 'after_state' => $literalText], 'A6: Narratives and audit JSON preserve prefix-looking text and pasted ciphertext');
    $textQuery->execute([$literalText, $literalText, $literalText]);
    assertTrue($textQuery->fetch(PDO::FETCH_NUM) === [$literalText, $literalText, $literalText], 'A6: Numeric reads do not decode arbitrary content or audit JSON');
    $textQuery->execute([$literalText, $literalText, $literalText]);
    assertTrue($textQuery->fetchColumn(1) === $literalText, 'A6: Scalar audit reads preserve exact stored bytes');
    $textQuery->execute([$literalText, $literalText, $literalText]);
    assertTrue($textQuery->fetchAll(PDO::FETCH_COLUMN, 2) === [$literalText], 'A6: Column-list reads preserve arbitrary content');

    $nameReadSql = 'SELECT r.resident_id, r.first_name AS substitute_first_name, TRIM(CONCAT_WS(\' \', r.first_name, r.last_name)) AS full_name, ? AS narrative FROM residents r WHERE r.resident_id = ?';
    $expectedFullName = 'Juan ' . $decRes['last_name'];
    foreach ([PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH, PDO::FETCH_OBJ] as $fetchMode) {
        $nameQuery = $db->prepare($nameReadSql);
        $nameQuery->execute([$literalText, $compResId]);
        $row = $nameQuery->fetch($fetchMode);
        $row = is_object($row) ? (array) $row : $row;
        assertTrue(($row['substitute_first_name'] ?? $row[1]) === 'Juan' && ($row['full_name'] ?? $row[2]) === $expectedFullName && ($row['narrative'] ?? $row[3]) === $literalText, 'A6: Name fields decode while narrative stays unchanged in fetch mode ' . $fetchMode);
    }
    $nameQuery = $db->prepare($nameReadSql);
    $nameQuery->execute([$literalText, $compResId]);
    assertTrue($nameQuery->fetchAll(PDO::FETCH_COLUMN, 2) === [$expectedFullName], 'A6: Column-list name captions still decrypt');
    $nameQuery->execute([$literalText, $compResId]);
    $uniqueNames = $nameQuery->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    assertTrue($uniqueNames[$compResId]['full_name'] === $expectedFullName && $uniqueNames[$compResId]['narrative'] === $literalText, 'A6: Unique keyed rows retain name decryption and untouched narratives');
    $nameQuery->execute([$literalText, $compResId]);
    $groupedNames = $nameQuery->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_NUM);
    assertTrue($groupedNames[$compResId][0][0] === 'Juan' && $groupedNames[$compResId][0][2] === $literalText, 'A6: Grouped numeric rows map back to the correct source columns');
    $pairQuery = $db->prepare('SELECT resident_id, first_name FROM residents WHERE resident_id = ?');
    $pairQuery->execute([$compResId]);
    assertTrue($pairQuery->fetchAll(PDO::FETCH_KEY_PAIR) === [$compResId => 'Juan'], 'A6: Key-pair names still decrypt');
    $scalarName = $db->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM residents WHERE resident_id = ?");
    $scalarName->execute([$compResId]);
    assertTrue($scalarName->fetchColumn() === $expectedFullName, 'A6: Existing scalar name queries without aliases still decrypt');
    $defaultColumn = $db->prepare($nameReadSql);
    $defaultColumn->setFetchMode(PDO::FETCH_COLUMN, 2);
    $defaultColumn->execute([$literalText, $compResId]);
    assertTrue($defaultColumn->fetch() === $expectedFullName, 'A6: Configured default column fetch mode retains name decryption');
    $duplicateLabel = $db->prepare('SELECT r.first_name, ? AS first_name FROM residents r WHERE r.resident_id = ?');
    $duplicateLabel->execute([$literalText, $compResId]);
    assertTrue($duplicateLabel->fetch(PDO::FETCH_ASSOC)['first_name'] === $literalText, 'A6: Duplicate column labels respect the metadata of the returned text value');

    $db->beginTransaction();
    try {
        $db->prepare('/* pii:raw */ UPDATE residents SET first_name = ? WHERE resident_id = ?')->execute(['name:v1:AAAA', $compResId]);
        $corruptNameRejected = false;
        try { $db->query("SELECT first_name FROM residents WHERE resident_id = {$compResId}")->fetchColumn(); }
        catch (RuntimeException $e) { $corruptNameRejected = true; }
        assertTrue($corruptNameRejected, 'A6: Damaged ciphertext in a protected name column still fails closed');
    } finally { $db->rollBack(); }

    // A6: User::create() encrypts users.email and User::findByUsernameOrEmail() resolves via blind index
    $userModel = new User();
    $encUserRes = $userModel->create([
        'first_name' => 'EncTest',
        'last_name' => 'Resident',
        'username' => 'enc_user_' . $token,
        'email' => "enc.{$token}@tumana.test",
        'contact_no' => '09179998888',
        'password' => 'Pass@12345',
        'role_id' => $roles['Resident'],
    ]);
    assertTrue(!empty($encUserRes['success']), 'A6: User::create succeeds with encrypted email and contact');
    $encUserId = (int) $encUserRes['id'];
    $createdUserIds[] = $encUserId;
    $rawUser = $db->query("/* pii:raw */ SELECT email, email_hash, contact_no FROM users WHERE user_id = {$encUserId}")->fetch(PDO::FETCH_ASSOC);
    assertTrue(PiiCryptoService::isEncrypted($rawUser['email']) && !empty($rawUser['email_hash']), 'A6: users.email stored as AES-256-GCM ciphertext with email_hash');
    $decUserPdo = $db->query("SELECT email, contact_no FROM users WHERE user_id = {$encUserId}")->fetch(PDO::FETCH_ASSOC);
    assertTrue($decUserPdo['email'] === "enc.{$token}@tumana.test" && $decUserPdo['contact_no'] === '09179998888', 'A6: Standard PDO reads dynamically decrypt enc:v1: PII fields without per-query decryption');
    $lookedUpUser = $userModel->findByUsernameOrEmail("enc.{$token}@tumana.test");
    assertTrue($lookedUpUser !== false && $lookedUpUser['email'] === "enc.{$token}@tumana.test", 'A6: findByUsernameOrEmail locates and decrypts encrypted user email cleanly');

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
    $ambigRow = $db->query("SELECT assigned_queue, privacy_notice_acknowledged FROM complaints WHERE complaint_id = {$ambigId}")->fetch(PDO::FETCH_ASSOC);
    assertTrue(($ambigRow['assigned_queue'] ?? '') === 'REVIEW_EXCEPTION_QUEUE', 'Ambiguous/no-match narrative is placed in REVIEW_EXCEPTION_QUEUE');
    assertTrue((int) ($ambigRow['privacy_notice_acknowledged'] ?? 1) === 0, 'A3: Walk-in intake without explicit confirmation does not fabricate privacy_notice_acknowledged=1');

    // A legacy invitation snapshot must be resolved before SQL copies it to a
    // non-encrypted name column; those columns are no longer decoded globally.
    $legacyCreate = $complaintModel->create([
        'category_id' => $catId, 'case_type' => 'Civil', 'complaint_title' => 'Synthetic legacy name snapshot ' . $token,
        'incident_date' => date('Y-m-d', strtotime('-2 days')), 'incident_street' => 'Synthetic test street',
        'narrative' => 'Synthetic legacy invitation test only.', 'complainant_resident_id' => $compResId,
        'respondent_resident_id' => $respResId, 'jurisdiction_screened' => '1', 'duplicate_acknowledged' => '1',
    ]);
    assertTrue(!empty($legacyCreate['success']), 'A6: Legacy invitation snapshot fixture created');
    $legacyCid = (int) $legacyCreate['complaint_id'];
    $createdComplaintIds[] = $legacyCid;
    $db->prepare('INSERT INTO paanyaya_stages (complaint_id, stage_type, invitee_name, status) VALUES (?, \'Paanyaya 1\', \'Synthetic invitee\', \'In Progress\')')->execute([$legacyCid]);
    $legacyProgress = (new PaanyayaModel($db))->recordAttendanceAndProgression($legacyCid, [
        'complainant_attended' => 'Present', 'respondent_attended' => 'Present', 'agreement_status' => 'no_agreement',
    ], $actors['clerk']['user_id']);
    $legacyName = $db->query("/* pii:raw */ SELECT invitee_name FROM paanyaya_stages WHERE complaint_id = {$legacyCid} AND stage_type = 'Paanyaya 2'")->fetchColumn();
    assertTrue(!empty($legacyProgress['success']) && $legacyName === 'Pedro ' . $resModel->getById($respResId)['last_name'], 'A6: Second invitation stores a readable snapshot rather than encrypted name fragments');

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
        'narrative' => 'Loud karaoke past midnight. Literal reference name:v1:AAAA must remain ordinary narrative text.',
        'respondent_first_name' => 'Pedro',
        'respondent_last_name' => 'Penduko' . substr($token, 0, 6),
        'respondent_resident_id' => $respResId,
        'privacy_notice_acknowledged' => '1',
    ]);
    assertTrue(!empty($ownedCreate['success']), 'Resident owner created online complaint');
    $ownedCid = (int) $ownedCreate['complaint_id'];
    $preparedParties = (new PaanyayaPreparationService($db))->parties($ownedCid);
    assertTrue(in_array('101 Mabini St., Barangay Tumana, Marikina City', array_column($preparedParties, 'address'), true), 'A6: Invitation party snapshots decrypt the encrypted address before verification and printing');
    $createdComplaintIds[] = $ownedCid;
    $prefixNarrative = httpCall($base, $actors['clerk'], "complaints/view.php?id={$ownedCid}");
    assertTrue($prefixNarrative['status'] === 200 && str_contains($prefixNarrative['json']['narrative'] ?? '', 'name:v1:AAAA'), 'A6: Complaint API loads stored prefix-looking narrative without changing it');
    $misleadingAlias = $db->query("SELECT narrative AS full_name FROM complaints WHERE complaint_id = {$ownedCid}")->fetchColumn();
    assertTrue($misleadingAlias === $prefixNarrative['json']['narrative'], 'A6: A name-like alias cannot turn a stored narrative into an encrypted name');

    $preBpsoRead = httpCall($base, $actors['lupon_other'], "complaints/view.php?id={$ownedCid}");
    assertTrue($preBpsoRead['status'] === 403, 'A4: Unassigned Lupon Member cannot bypass BPSO intake gate before endorsement (403)');
    $db->prepare("UPDATE complaints SET bpso_status = 'Endorsed to LISO', assigned_queue = 'LISO_SCREENING' WHERE complaint_id = ?")
        ->execute([$ownedCid]);

    $unassignedScreenRead = httpCall($base, $actors['lupon_other'], "complaints/view.php?id={$ownedCid}");
    assertTrue($unassignedScreenRead['status'] === 200, 'A4: Active Lupon Member can open unassigned pending intake awaiting LISO screening');
    $dynamicLocCheck = PiiCryptoService::decryptPayload([
        'incident_street' => (new PiiCryptoService())->encrypt('101 Mabini St.'),
        'location' => ['address' => (new PiiCryptoService())->encrypt('101 Mabini St.') . ', Tumana, Marikina City'],
        'narrative' => 'Literal reference name:v1:AAAA',
    ]);
    assertTrue(
        ($dynamicLocCheck['incident_street'] ?? '') === '101 Mabini St.'
        && ($dynamicLocCheck['location']['address'] ?? '') === '101 Mabini St., Tumana, Marikina City'
        && ($dynamicLocCheck['narrative'] ?? '') === 'Literal reference name:v1:AAAA',
        'A6: Dynamic payload decryption resolves nested location ciphertext while preserving narrative text'
    );

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
    $ambigEvidencePath = "storage/generated-documents/ambig-{$token}.pdf";
    copy($absDocPath, "{$root}/{$ambigEvidencePath}");
    $createdDocFiles[] = "{$root}/{$ambigEvidencePath}";
    $db->prepare('INSERT INTO complaint_attachments (complaint_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)')
        ->execute([$ambigId, 'Supporting record.pdf', $ambigEvidencePath, 'application/pdf']);

    // Negative ID tampering checks (complaints, cases, documents, hearings, hearing minutes, settlements, proof images)
    $db->prepare("INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, presiding_officer_id) VALUES (?, 'Mediation', NOW(), 'Lupon Court Room', 'Scheduled', ?)")
        ->execute([$caseId, $actors['lupon_assigned']['user_id']]);
    $hearingId = (int) $db->lastInsertId();

    $relProofPath = "storage/uploads/proof-of-service/test-{$token}.png";
    $absProofPath = "{$root}/{$relProofPath}";
    if (!is_dir(dirname($absProofPath))) {
        mkdir(dirname($absProofPath), 0750, true);
    }
    file_put_contents($absProofPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+X2Z0AAAAASUVORK5CYII='));
    $createdDocFiles[] = $absProofPath;
    $db->prepare("INSERT INTO proof_of_service (case_id, service_result, served_by, served_date, image_path) VALUES (?, 'Served', ?, NOW(), ?)")
        ->execute([$caseId, $actors['clerk']['user_id'], $relProofPath]);
    $proofId = (int) $db->lastInsertId();

    assertTrue(httpCall($base, $actors['lupon_other'], "complaints/view.php?id={$ownedCid}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from viewing complaint by ID');
    assertTrue(httpCall($base, $actors['lupon_other'], "cases/view.php?id={$caseId}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from viewing case by ID');
    assertTrue(httpCall($base, $actors['lupon_other'], "documents/download.php?id={$docId}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from downloading case document');
    assertTrue(httpCall($base, $actors['lupon_other'], "hearings/view.php?id={$hearingId}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from viewing hearing by ID');
    assertTrue(httpCall($base, $actors['lupon_other'], "hearings/minutes.php?hearing_id={$hearingId}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from reading hearing minutes');
    assertTrue(httpCall($base, $actors['lupon_other'], "settlements/view.php?case_id={$caseId}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from viewing settlement by case ID');
    assertTrue(httpCall($base, $actors['lupon_other'], "gps/proof-image.php?id={$proofId}")['status'] === 403, 'A4: Unassigned Lupon Member is blocked (403) from viewing proof image');
    assertTrue(httpCall($base, $actors['server_other'], "cases/view.php?id={$caseId}")['status'] === 403, 'A4: Unassigned Summons Server is blocked (403) from viewing case by ID');
    assertTrue(httpCall($base, $actors['server_other'], "documents/download.php?id={$docId}")['status'] === 403, 'A4: Unassigned Summons Server is blocked (403) from downloading document by ID');
    assertTrue(httpCall($base, $actors['server_other'], "settlements/view.php?case_id={$caseId}")['status'] === 403, 'A4: Unassigned Summons Server is blocked (403) from viewing settlement (403)');
    assertTrue(httpCall($base, $actors['server_other'], "gps/proof-image.php?id={$proofId}")['status'] === 403, 'A4: Unassigned Summons Server is blocked (403) from viewing unassigned proof image');
    assertTrue(httpCall($base, $actors['resident_other'], "complaints/view.php?id={$ownedCid}")['status'] === 403, 'A4: Different Resident is blocked (403) from viewing another resident complaint');
    assertTrue(httpCall($base, $actors['resident_other'], "settlements/view.php?case_id={$caseId}")['status'] === 403, 'A4: Different Resident is blocked (403) from viewing settlement');
    assertTrue(httpCall($base, $actors['resident_owner'], "hearings/minutes.php?hearing_id={$hearingId}")['status'] === 403, 'A4: Resident cannot access internal hearing minutes API (403)');

    // Positive authorized checks
    assertTrue(httpCall($base, $actors['lupon_assigned'], "complaints/view.php?id={$ownedCid}")['status'] === 200, 'A4: Assigned Lupon Member can view assigned complaint (200)');
    assertTrue(httpCall($base, $actors['lupon_assigned'], "cases/view.php?id={$caseId}")['status'] === 200, 'A4: Assigned Lupon Member can view assigned case (200)');
    assertTrue(httpCall($base, $actors['lupon_assigned'], "documents/download.php?id={$docId}")['status'] === 200, 'A4: Assigned Lupon Member can download assigned case document (200)');
    assertTrue(httpCall($base, $actors['lupon_assigned'], "hearings/view.php?id={$hearingId}")['status'] === 200, 'A4: Assigned Lupon Member can view assigned hearing (200)');
    assertTrue(httpCall($base, $actors['lupon_assigned'], "settlements/view.php?case_id={$caseId}")['status'] === 200, 'A4: Assigned Lupon Member can view assigned case settlement (200)');
    assertTrue(httpCall($base, $actors['lupon_assigned'], "gps/proof-image.php?id={$proofId}")['status'] === 200, 'A4: Assigned Lupon Member can view assigned case proof image (200)');
    assertTrue(httpCall($base, $actors['resident_owner'], "complaints/view.php?id={$ownedCid}")['status'] === 200, 'A4: Owning Resident can view their own complaint (200)');
    assertTrue(httpCall($base, $actors['resident_owner'], "settlements/view.php?case_id={$caseId}")['status'] === 200, 'A4: Owning Resident can view their own settlement (200)');

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
    $anonAuditIp = $db->query("SELECT ip_address FROM audit_trails WHERE module_name = 'AnonymousConcerns' AND affected_record = {$anonId} ORDER BY audit_id DESC LIMIT 1")->fetchColumn();
    assertTrue($anonAuditIp === null || $anonAuditIp === false, 'A5: audit_trails records NULL ip_address for anonymous submissions');

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
    // A9 guard checks: unfulfilled or Draft settlements and missing evidence cannot close prematurely
    $closureSvc = new ClosureWorkflowService($db);
    assertTrue(!$closureSvc->validateResolutionReadiness($ambigId, 'Administrative Closure', $relDocPath)['ready'], 'A9: Evidence belonging to another complaint is rejected');
    assertTrue(!$closureSvc->validateResolutionReadiness($ownedCid, 'Dismissal', 'made-up-record')['ready'], 'A9: Arbitrary evidence text is rejected');
    assertTrue($closureSvc->validateResolutionReadiness($ambigId, 'Administrative Closure', $ambigEvidencePath)['ready'], 'A9: Stored supporting evidence belonging to the complaint is accepted');
    assertTrue(!$closureSvc->validateResolutionReadiness($ambigId, 'Obligation Compliance')['ready'], 'A9: Obligation Compliance rejected when no settlement exists');
    assertTrue(!$closureSvc->validateResolutionReadiness($ambigId, 'Administrative Closure')['ready'], 'A9: Administrative Closure requires selection of a saved supporting record');

    $db->prepare("INSERT INTO settlements (case_id, settlement_date, agreement_details, settlement_status, compliance_status) VALUES (?, CURDATE(), 'Pay 5,000', 'Draft', 'Pending')")
        ->execute([$caseId]);
    assertTrue(!$closureSvc->validateResolutionReadiness($ownedCid, 'Signed Settlement')['ready'], 'A9: Draft settlement cannot satisfy Signed Settlement closure');
    $draftUpdateRes = (new CaseModel($db))->update($caseId, ['case_type' => 'Civil', 'case_status' => 'Settled']);
    assertTrue(empty($draftUpdateRes['success']), 'A9: General CaseModel::update rejects Settled transition on Draft settlement');

    $db->prepare("UPDATE settlements SET settlement_status = 'Signed and Attested' WHERE case_id = ?")->execute([$caseId]);
    $guard = $closureSvc->validateResolutionReadiness($ownedCid, 'Obligation Compliance');
    assertTrue(!$guard['ready'], 'A9: Signed settlement with Pending compliance cannot close as Obligation Compliance');

    // A10 step 1: Staff initiates Pending Close via HTTP (must NOT receive complainant token/OTP or override expiry)
    $req1 = httpCall($base, $actors['clerk'], 'complaints/closure-request.php', [
        'action' => 'request',
        'complaint_id' => $ownedCid,
        'resolution_type' => 'Administrative Closure',
        'request_reason' => 'Parties reported informal compliance; awaiting complainant verification.',
        'evidence_path' => $relDocPath,
        'expires_at_override' => date('Y-m-d H:i:s', time() - 3600),
        'timeout_days' => 5,
    ], true);
    assertTrue($req1['status'] === 201 && empty($req1['json']['verification_token']) && empty($req1['json']['verification_otp']), 'A10: HTTP closure-request.php hides secret verification token/OTP from requesting staff');
    $req1Expiry = (string) $db->query("SELECT expires_at FROM closure_requests WHERE closure_request_id = " . (int) $req1['json']['closure_request_id'])->fetchColumn();
    assertTrue(strtotime($req1Expiry) > time() + 3600, 'A10: HTTP closure-request.php ignores untrusted expires_at_override');

    // A10 OTP throttling: 5 wrong OTP attempts trigger 429 lockout
    for ($otpTry = 1; $otpTry <= ClosureWorkflowService::MAX_FAILED_VERIFICATION_ATTEMPTS; $otpTry++) {
        $badOtpRes = httpCall($base, null, 'public/closure-verify.php', [
            'decision' => 'confirm',
            'complaint_id' => $ownedCid,
            'otp' => '00000' . $otpTry,
        ], true);
    }
    $lockedOtpRes = httpCall($base, null, 'public/closure-verify.php', [
        'decision' => 'confirm',
        'complaint_id' => $ownedCid,
        'otp' => '999999',
    ], true);
    assertTrue($lockedOtpRes['status'] === 429, 'A10: Repeated invalid OTP attempts trigger 429 lockout');

    // Reset lockout & test complainant objection via issued out-of-band token on a clean request
    $db->prepare("UPDATE closure_requests SET status = 'Cancelled', locked_until = NULL, failed_verification_attempts = 0 WHERE closure_request_id = ?")
        ->execute([(int) $req1['json']['closure_request_id']]);
    $db->prepare("UPDATE complaints SET status = 'Mediation' WHERE complaint_id = ?")->execute([$ownedCid]);
    $db->prepare("UPDATE cases SET case_status = 'Mediation' WHERE case_id = ?")->execute([$caseId]);

    $svcReq1 = $closureSvc->requestPendingClose($ownedCid, $actors['clerk']['user_id'], [
        'resolution_type' => 'Administrative Closure',
        'request_reason' => 'Out-of-band token objection test.',
        'evidence_path' => $relDocPath,
    ]);
    assertTrue(!empty($svcReq1['success']) && !empty($svcReq1['verification_token']), 'A10: Internal service issues verification token for out-of-band delivery');
    $closeToken1 = (string) $svcReq1['verification_token'];

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
        'evidence_path' => $relDocPath,
    ], true);
    assertTrue($req2['status'] === 201, 'A10: Second Pending Close request created');

    $confRes = httpCall($base, $actors['resident_owner'], 'complaints/closure-request.php', [
        'action' => 'confirm',
        'complaint_id' => $ownedCid,
    ], true);
    assertTrue($confRes['status'] === 200 && ($confRes['json']['complaint_status'] ?? '') === 'Resolution / Closed', 'A10: Owning complainant confirms closure -> Resolution / Closed');
    $repeatClosed = $closureSvc->requestPendingClose($ownedCid, $actors['clerk']['user_id'], [
        'resolution_type' => 'Administrative Closure', 'request_reason' => 'Synthetic repeat closure request.', 'evidence_path' => $relDocPath,
    ]);
    assertTrue(empty($repeatClosed['success']), 'A10: Final closure cannot be reopened by requesting Pending Close again');
    $search = new Search();
    $closedLabels = $search->resolveLifecycle(['case_id' => 1, 'case_status' => 'Closed', 'complaint_status' => 'Resolution / Closed', 'resolution_type' => 'Administrative Closure']);
    assertTrue($closedLabels['status_labels'] === ['Closed', 'Administrative Closure'] && $closedLabels['is_stage_exhausted'], 'A10: Confirmed closure is labeled Closed with its resolution type in complaint lists');
    $pendingLabels = $search->resolveLifecycle(['case_id' => 1, 'case_status' => 'Pending Close', 'complaint_status' => 'Pending Close']);
    assertTrue($pendingLabels['status_labels'] === ['Pending Close'], 'A10: Pending verification is labeled Pending Close rather than Mediation');
    $closedRows = $search->records(['status' => 'Resolution / Closed']);
    assertTrue(in_array($ownedCid, array_map('intval', array_column($closedRows, 'complaint_id')), true), 'A10: Closed filter includes complainant-confirmed closures');

    // A10 step 4: Pre-docketed complaints cannot request Pending Close; once formally docketed, notice-delivery gating and expired timeout work idempotently
    $preDocketReq = $closureSvc->requestPendingClose($ambigId, $actors['clerk']['user_id'], [
        'resolution_type' => 'Administrative Closure',
        'request_reason' => 'Attempted Pending Close prior to formal docketing.',
        'evidence_path' => $ambigEvidencePath,
    ]);
    assertTrue(empty($preDocketReq['success']), 'A10: Pre-docketed intake/Paanyaya complaint is rejected from Pending Close');

    $db->prepare("INSERT INTO cases (complaint_id, case_number, case_type, case_status, docket_date) VALUES (?, ?, 'Civil', 'Mediation', CURDATE())")
        ->execute([$ambigId, 'CASE-AMBIG-' . $token]);
    $db->prepare("UPDATE complaints SET status = 'Mediation' WHERE complaint_id = ?")->execute([$ambigId]);

    $db->prepare('UPDATE residents SET email = NULL, email_hash = NULL WHERE resident_id = ?')->execute([$compResId]);
    $db->prepare('UPDATE complaints SET submitted_by = ? WHERE complaint_id = ?')->execute([$actors['resident_owner']['user_id'], $ambigId]);
    $beforeFailedNotice = (int) $db->query('SELECT COUNT(*) FROM closure_requests')->fetchColumn();
    $failedNoticeSvc = new ClosureWorkflowService($db, new RejectClosureNotification($db));
    $failedNotice = $failedNoticeSvc->requestPendingClose($ambigId, $actors['clerk']['user_id'], [
        'resolution_type' => 'Administrative Closure', 'request_reason' => 'Synthetic notice failure.', 'evidence_path' => $ambigEvidencePath,
    ]);
    assertTrue(empty($failedNotice['success']), 'A10: Portal notification insertion failure cannot start a closure timer');
    assertTrue((int) $db->query('SELECT COUNT(*) FROM closure_requests')->fetchColumn() === $beforeFailedNotice
        && $db->query("SELECT status FROM complaints WHERE complaint_id = {$ambigId}")->fetchColumn() !== 'Pending Close', 'A10: Failed notice rolls back both request and complaint state');
    $db->prepare('UPDATE complaints SET submitted_by = NULL WHERE complaint_id = ?')->execute([$ambigId]);
    $unnotifiableReq = $closureSvc->requestPendingClose($ambigId, $actors['clerk']['user_id'], [
        'resolution_type' => 'Administrative Closure',
        'request_reason' => 'Should fail without complainant delivery channel.',
        'evidence_path' => $ambigEvidencePath,
    ]);
    assertTrue(empty($unnotifiableReq['success']), 'A10: Pending Close rejects requests with zero verified complainant notification channels');

    $db->prepare('UPDATE complaints SET submitted_by = ? WHERE complaint_id = ?')->execute([$actors['resident_owner']['user_id'], $ambigId]);
    $req3 = $closureSvc->requestPendingClose($ambigId, $actors['clerk']['user_id'], [
        'resolution_type' => 'Administrative Closure',
        'request_reason' => 'Uncontested timeout verification test.',
        'evidence_path' => $ambigEvidencePath,
    ], date('Y-m-d H:i:s', time() - 3600));
    assertTrue(!empty($req3['success']), 'A10: Created expired Pending Close request for timeout test');
    $db->prepare("UPDATE closure_requests SET notice_delivery_status = 'Unverified', notice_sent_at = NULL WHERE closure_request_id = ?")->execute([(int) $req3['closure_request_id']]);
    $unverifiedTimeout = $closureSvc->processExpiredRequests();
    assertTrue(!in_array((int) $req3['closure_request_id'], $unverifiedTimeout['closed_request_ids'], true), 'A10: Timeout processor ignores notices without confirmed delivery');
    $oldRequestId = (int) $req3['closure_request_id'];
    $req3 = $closureSvc->requestPendingClose($ambigId, $actors['clerk']['user_id'], [
        'resolution_type' => 'Administrative Closure', 'request_reason' => 'Replace unverified legacy notice.', 'evidence_path' => $ambigEvidencePath,
    ], date('Y-m-d H:i:s', time() - 3600));
    assertTrue(!empty($req3['success']) && $db->query("SELECT status FROM closure_requests WHERE closure_request_id = {$oldRequestId}")->fetchColumn() === 'Cancelled', 'A10: Unverified notice can be replaced with a fresh delivered request');
    $expiredManual = httpCall($base, null, 'public/closure-verify.php', [
        'decision' => 'confirm',
        'verification_token' => $req3['verification_token'],
    ], true);
    assertTrue($expiredManual['status'] === 410, 'A10: Expired Pending Close verification token is rejected with 410');
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
        $db->exec("DELETE FROM proof_of_service WHERE case_id IN (SELECT case_id FROM cases WHERE complaint_id IN ({$in}))");
        $db->exec("DELETE FROM generated_documents WHERE case_id IN (SELECT case_id FROM cases WHERE complaint_id IN ({$in}))");
        $db->exec("DELETE FROM settlements WHERE case_id IN (SELECT case_id FROM cases WHERE complaint_id IN ({$in}))");
        $db->exec("DELETE FROM hearings WHERE case_id IN (SELECT case_id FROM cases WHERE complaint_id IN ({$in}))");
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
