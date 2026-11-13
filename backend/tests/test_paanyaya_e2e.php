<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PaanyayaPreparationService.php';
require_once __DIR__ . '/../services/PaanyayaServiceDelivery.php';
require_once __DIR__ . '/TestDataCleanup.php';
require_once __DIR__ . '/TestLisoDuty.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function request(string $base, array $session, string $route, ?array $data = null): array
{
    $headers = [
        'Cookie: PHPSESSID=' . $session['id'],
        'Accept: application/json',
        'X-CSRF-Token: ' . $session['csrf_token'],
    ];
    $options = ['header' => $headers, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20];
    if ($data !== null) {
        $options['method'] = 'POST';
        $options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
        $options['content'] = http_build_query($data);
    }

    $body = file_get_contents($base . '/backend/api/' . $route, false, stream_context_create(['http' => $options]));
    if ($body === false || !isset($http_response_header[0])) {
        throw new RuntimeException("No HTTP response for $route");
    }
    $status = (int) explode(' ', $http_response_header[0])[1];
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

function expectKp7(string $base, array $clerk, int $id, bool $available): void
{
    $view = request($base, $clerk, "complaints/view.php?id=$id");
    check($view['status'] === 200 && ($view['json']['kp7_available'] ?? null) === $available, 'Complaint workspace reports the expected KP Form 7 availability');
    $preview = request($base, $clerk, "complaints/kp7-preview.php?id=$id&format=json");
    $download = request($base, $clerk, "complaints/kp7-preview.php?id=$id&download=1");
    $generic = request($base, $clerk, "documents/kp-form.php?complaint_id=$id&form_code=KP%20Form%207&preview=1");
    $genericPdf = request($base, $clerk, "documents/kp-form.php?complaint_id=$id&form_code=KP%20Form%207&pdf=1");
    if ($available) {
        check($preview['status'] === 200 && !empty($preview['json']['html']), 'KP Form 7 preview opens after docketing');
        check($download['status'] === 200 && str_starts_with($download['body'], '%PDF-'), 'KP Form 7 download returns a PDF after docketing');
        check($generic['status'] === 200, 'General document preview opens after docketing');
        check($genericPdf['status'] === 200 && str_starts_with($genericPdf['body'], '%PDF-'), 'General document route returns a PDF after docketing');
    } else {
        check($preview['status'] === 403 && ($preview['json']['success'] ?? null) === false, 'Direct KP Form 7 preview is blocked');
        check($download['status'] === 403 && !str_starts_with($download['body'], '%PDF-'), 'Direct KP Form 7 download is blocked');
        check($generic['status'] === 403, 'General document preview is blocked');
        check($genericPdf['status'] === 403, 'General document download is blocked');
        $alias = request($base, $clerk, "documents/kp-form.php?complaint_id=$id&form_code=KP%20Blg.%207&preview=1");
        check($alias['status'] === 403, 'The Filipino form name also enforces the hold');
    }
}

function expectKp7Scan(string $base, array $clerk, int $scanId, int $expectedStatus): void
{
    foreach (['', '&download=1'] as $mode) {
        $scan = request($base, $clerk, "complaints/scanned-document.php?id=$scanId$mode");
        check(
            $scan['status'] === $expectedStatus && str_starts_with($scan['body'], '%PDF-') === ($expectedStatus === 200),
            'Scanned KP Form 7 ' . ($mode ? 'download' : 'preview') . " returns HTTP $expectedStatus"
        );
    }
}

function prepareInvitation(
    string $base, array $clerk, PaanyayaPreparationService $preparations, int $id, int $number
): string {
    $params = ['complaint_id' => $id, 'invitation_number' => $number];
    $result = request($base, $clerk, 'paanyaya/preparation.php', $params + [
        'action' => 'generate',
        'appointment_date' => date('Y-m-d', strtotime("+$number days")),
        'appointment_time' => '14:00',
        'venue' => 'Lupon Court Room',
        'verify_parties' => '1',
        'party_version' => PaanyayaPreparationService::partyVersion($preparations->parties($id)),
    ]);
    $record = $preparations->intake($id, $number);
    $version = $record['document_version'] ?? '';
    check($result['status'] === 303 && $version !== '', "Invitation $number is generated for the original complaint");
    $pdf = request($base, $clerk, 'paanyaya/preparation.php?' . http_build_query($params + ['version' => $version]));
    check($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-'), "Invitation $number has a PDF");
    $result = request($base, $clerk, 'paanyaya/preparation.php', $params + ['action' => 'ready', 'document_version' => $version]);
    check($result['status'] === 303 && !empty($preparations->intake($id, $number)['ready_at']), "Invitation $number is ready for release");
    return $version;
}

function releaseInvitation(string $base, array $clerk, array $server, PDO $db, int $id, int $number, string $version): int
{
    $result = request($base, $clerk, 'paanyaya/service.php', [
        'action' => 'release',
        'complaint_id' => $id,
        'invitation_number' => $number,
        'document_version' => $version,
        'assigned_to' => $server['user_id'],
    ]);
    $latest = PaanyayaServiceDelivery::latestAttempt($db, $id, $number);
    check($result['status'] === 303 && $latest && $latest['outcome'] === null, "Invitation $number is assigned for service");
    $attemptId = (int) $latest['attempt_id'];
    $pdf = request($base, $server, "paanyaya/service.php?attempt_id=$attemptId&view=invitation");
    check($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-'), 'Assigned server can open the invitation');
    return $attemptId;
}

$db = (new Database())->connect();
$cleanup = new TestDataCleanup($db);
$preparations = new PaanyayaPreparationService($db);
$token = bin2hex(random_bytes(8));
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agap-test-' . $token;
mkdir($sessionDir);
ini_set('session.save_path', $sessionDir);
ini_set('session.use_cookies', '0');
$process = null;
$logPath = $sessionDir . DIRECTORY_SEPARATOR . 'server.log';
$residentUserId = null;
register_shutdown_function(static function () use ($db, &$residentUserId, &$process, $sessionDir): void {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($sessionDir);
    if ($residentUserId) {
        $db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$residentUserId]);
    }
});

$actors = [];
$lookup = $db->prepare('SELECT u.user_id, u.role_id, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.username = ? AND u.status = \'Active\'');
foreach (['clerk', 'luponmember', 'luponhead', 'summonsserver'] as $username) {
    $lookup->execute([$username]);
    $actors[$username] = $lookup->fetch() ?: throw new RuntimeException("Seed the local $username test account.");
}
$dutyId = startTestLisoDuty($db, (int) $actors['luponmember']['user_id']);
$residents = $db->query("SELECT resident_id FROM residents WHERE barangay = 'Tumana' ORDER BY resident_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($residents) !== 2) {
    throw new RuntimeException('The Paanyaya suite requires two seeded resident profiles.');
}
$residentRole = (int) $db->query("SELECT role_id FROM roles WHERE role_name = 'Resident'")->fetchColumn();
$db->prepare("INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
    VALUES ('Test', 'Resident', ?, ?, ?, ?, 'Active')")
    ->execute(["test-$token", "$token@agap.test", password_hash($token, PASSWORD_DEFAULT), $residentRole]);
$residentUserId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)')->execute([$residentUserId, $residents[0]]);
$actors['resident'] = ['user_id' => $residentUserId, 'role_id' => $residentRole, 'role_name' => 'Resident'];

foreach ($actors as $name => &$actor) {
    $actor['id'] = "$token-$name";
    $actor['csrf_token'] = bin2hex(random_bytes(32));
    session_id($actor['id']);
    session_start();
    $_SESSION = array_diff_key($actor, ['id' => true]);
    session_write_close();
}
unset($actor);
$clerk = $actors['clerk'];
$liso = $actors['luponmember'];
$server = $actors['summonsserver'];

$socket = stream_socket_server('tcp://127.0.0.1:0');
if ($socket === false) {
    throw new RuntimeException('Could not reserve a localhost port for the PHP test server.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$root = dirname(__DIR__, 2);
$scanPath = "storage/paanyaya-test-$token.pdf";
register_shutdown_function(static function () use ($root, $scanPath): void {
    if (is_file("$root/$scanPath")) {
        unlink("$root/$scanPath");
    }
});
$process = proc_open(
    [PHP_BINARY, '-d', "session.save_path=$sessionDir", '-S', $address, '-t', $root],
    [1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
    $pipes,
    $root
);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the PHP test server.');
}
for ($attempt = 0; $attempt < 30; $attempt++) {
    $connection = @stream_socket_client("tcp://$address", $errorCode, $error, 0.1);
    if ($connection) {
        fclose($connection);
        break;
    }
    if (!proc_get_status($process)['running'] || $attempt === 29) {
        throw new RuntimeException('PHP test server failed to start: ' . file_get_contents($logPath));
    }
    usleep(100000);
}
$base = "http://$address";
$caseLookup = $db->prepare('SELECT case_id, case_status FROM cases WHERE complaint_id = ?');
$minutesLookup = $db->prepare('SELECT outcome, complainant_choice FROM paanyaya_minutes WHERE complaint_id = ? AND stage_type = ?');
$complaintLookup = $db->prepare('SELECT intake_source, submitted_by, liso_assigned_duty_session_id, status, resolved_without_docketing FROM complaints WHERE complaint_id = ?');

try {
    foreach (['Walk-in' => $clerk, 'Online' => $actors['resident']] as $source => $filer) {
        $created = request($base, $filer, 'complaints/create.php', [
            'complaint_title' => "Paanyaya E2E $source $token",
            'category_id' => 1,
            'case_type' => 'Civil',
            'incident_date' => date('Y-m-d', strtotime('-1 day')),
            'incident_time' => '09:30',
            'incident_street' => 'Synthetic test street',
            'narrative' => 'Synthetic boundary dispute used to verify intake and dialogue.',
            'complainant_resident_id' => $residents[0],
            'respondent_resident_id' => $residents[1],
            'latitude' => 14.6521,
            'longitude' => 121.0854,
            'jurisdiction_screened' => '1',
            'duplicate_acknowledged' => '1',
            'liso_assigned_to' => $liso['user_id'],
        ]);
        $id = (int) ($created['json']['complaint_id'] ?? 0);
        $cleanup->trackComplaint($id);
        check($created['status'] === 201 && $id > 0, "$source intake was created: " . ($created['json']['message'] ?? $created['body']));
        $complaintLookup->execute([$id]);
        $complaint = $complaintLookup->fetch();
        check($complaint['intake_source'] === $source, 'Intake source is recorded');
        if ($source === 'Walk-in') {
            check((int) $complaint['liso_assigned_duty_session_id'] === $dutyId, 'Walk-in is tied to the active LISO duty session');
            $review = request($base, $actors['luponhead'], 'complaints/review.php', ['complaint_id' => $id, 'status' => 'Accepted']);
            check($review['status'] === 422, 'Another Lupon member cannot screen the assigned walk-in');
        } else {
            check((int) $complaint['submitted_by'] === $residentUserId && $complaint['liso_assigned_duty_session_id'] === null, 'Online intake belongs to the resident account');
        }
        $reviewData = ['complaint_id' => $id, 'status' => 'Accepted'];
        $review = request($base, $liso, 'complaints/review.php', $reviewData);
        check($review['status'] === 200 && ($review['json']['success'] ?? false), "$source intake is marked eligible");
        check(request($base, $liso, 'complaints/review.php', $reviewData)['status'] === 422, 'Repeated eligibility is rejected');
        check(!empty($preparations->intake($id)['eligible']), "$source intake enters the preparation queue");
        expectKp7($base, $clerk, $id, false);
        $earlyDocket = request($base, $clerk, 'complaints/docket.php', ['complaint_id' => $id]);
        check(($earlyDocket['json']['success'] ?? null) === false, 'Direct docketing is rejected before dialogue');

        $version = prepareInvitation($base, $clerk, $preparations, $id, 1);
        $scanBytes = $preparations->document($id, $version);
        check(file_put_contents("$root/$scanPath", $scanBytes) !== false, 'Synthetic scan fixture is available');
        $db->prepare("INSERT INTO complaint_scanned_documents (complaint_id, file_name, file_path, file_type, file_size)
            VALUES (?, 'synthetic-test.pdf', ?, 'application/pdf', ?)")
            ->execute([$id, $scanPath, strlen($scanBytes)]);
        $scanId = (int) $db->lastInsertId();
        expectKp7Scan($base, $clerk, $scanId, 403);
        $firstAttempt = releaseInvitation($base, $clerk, $server, $db, $id, 1, $version);
        $outcome = $source === 'Walk-in' ? 'Settled' : 'Request_Paanyaya_2';
        $minutes = [
            'action' => 'minutes', 'complaint_id' => $id, 'invitation_number' => 1,
            'complainant_attended' => 'Present', 'respondent_attended' => 'Present',
            'discussion_summary' => 'Both parties discussed the dispute.',
            'agreed_terms' => 'The parties will confirm the boundary together.',
            'outcome' => $outcome,
            'dialogue_date' => date('Y-m-d\TH:i'),
        ];
        if ($source === 'Walk-in') {
            $recorded = request($base, $server, 'paanyaya/service.php', [
                'action' => 'record', 'attempt_id' => $firstAttempt, 'outcome' => 'Not Delivered', 'notes' => 'Recipient unavailable',
            ]);
            check($recorded['status'] === 303 && PaanyayaServiceDelivery::latestAttempt($db, $id)['outcome'] === 'Not Delivered', 'Unsuccessful delivery is recorded');
            request($base, $clerk, 'paanyaya/dialogue.php', $minutes);
            $minutesLookup->execute([$id, 'Paanyaya 1']);
            check($minutesLookup->fetch() === false, 'Failed service does not unlock dialogue');
            $attemptId = releaseInvitation($base, $clerk, $server, $db, $id, 1, $version);
            check($attemptId !== $firstAttempt, 'Retry creates a separate service attempt for the same invitation');
        } else {
            $attemptId = $firstAttempt;
        }
        $recorded = request($base, $server, 'paanyaya/service.php', [
            'action' => 'record', 'attempt_id' => $attemptId, 'outcome' => 'Delivered',
        ]);
        check($recorded['status'] === 303 && PaanyayaServiceDelivery::latestAttempt($db, $id)['outcome'] === 'Delivered', 'Successful delivery activates dialogue');
        $minutes['dialogue_date'] = date('Y-m-d\TH:i');
        $recorded = request($base, $clerk, 'paanyaya/dialogue.php', $minutes);
        $minutesLookup->execute([$id, 'Paanyaya 1']);
        $saved = $minutesLookup->fetch();
        check($recorded['status'] === 303 && (in_array($outcome, $saved ?: [], true)), "Dialogue records $outcome after confirmed service");
        $caseLookup->execute([$id]);
        check($caseLookup->fetchAll() === [], 'Dialogue does not create a formal case');

        if ($source === 'Walk-in') {
            $complaintLookup->execute([$id]);
            $settled = $complaintLookup->fetch();
            check($settled['status'] === 'Settled' && (int) $settled['resolved_without_docketing'] === 1, 'Settlement closes the complaint at Paanyaya');
            expectKp7($base, $clerk, $id, false);
            expectKp7Scan($base, $clerk, $scanId, 403);
            continue;
        }

        check(!empty($preparations->intake($id, 2)['eligible']), 'First dialogue unlocks a second invitation');
        $secondVersion = prepareInvitation($base, $clerk, $preparations, $id, 2);
        check($secondVersion !== $version && $preparations->intake($id, 1)['document_version'] === $version, 'The two invitations retain separate PDFs');
        $secondAttempt = releaseInvitation($base, $clerk, $server, $db, $id, 2, $secondVersion);
        $minutes['invitation_number'] = 2;
        $minutes['outcome'] = 'Escalate_Mediation';
        request($base, $clerk, 'paanyaya/dialogue.php', $minutes);
        $minutesLookup->execute([$id, 'Paanyaya 2']);
        check($minutesLookup->fetch() === false, 'First delivery does not unlock the second dialogue');
        request($base, $server, 'paanyaya/service.php', ['action' => 'record', 'attempt_id' => $secondAttempt, 'outcome' => 'Delivered']);
        check(PaanyayaServiceDelivery::latestAttempt($db, $id, 2)['outcome'] === 'Delivered', 'Second invitation records its own delivery');
        $minutes['dialogue_date'] = date('Y-m-d\TH:i');
        request($base, $clerk, 'paanyaya/dialogue.php', $minutes);
        $minutesLookup->execute([$id, 'Paanyaya 2']);
        check(($minutesLookup->fetch()['complainant_choice'] ?? '') === 'Escalate_Mediation', 'Second dialogue records escalation to mediation');
        expectKp7($base, $clerk, $id, false);

        $docket = ['action' => 'docket', 'complaint_id' => $id, 'invitation_number' => 2];
        request($base, $clerk, 'paanyaya/dialogue.php', $docket);
        $caseLookup->execute([$id]);
        $cases = $caseLookup->fetchAll();
        check(count($cases) === 1 && $cases[0]['case_status'] === 'Mediation', 'Escalation dockets one mediation case on the original complaint');
        request($base, $clerk, 'paanyaya/dialogue.php', $docket);
        $caseLookup->execute([$id]);
        check($caseLookup->fetchAll() === $cases, 'Retrying docketing keeps the same single formal case');
        expectKp7($base, $clerk, $id, true);
        expectKp7Scan($base, $clerk, $scanId, 200);

        $db->prepare("INSERT INTO generated_documents (case_id, template_id, file_path)
            SELECT ?, template_id, ? FROM document_templates WHERE template_name = 'KP Form 7'")
            ->execute([$cases[0]['case_id'], $scanPath]);
        $documentId = (int) $db->lastInsertId();
        $stored = request($base, $clerk, "documents/download.php?id=$documentId&download=1");
        check($stored['status'] === 200 && str_starts_with($stored['body'], '%PDF-'), 'Stored KP Form 7 downloads after docketing');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, file_get_contents($logPath));
    throw $exception;
}
