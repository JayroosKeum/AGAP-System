<?php
/** CLI-only synthetic fixtures for manual browser regression on an isolated DB. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !str_starts_with((string) getenv('DB_NAME'), 'agap_revision_browser_')) {
    throw new RuntimeException('Use an isolated agap_revision_browser_* database.');
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Resident.php';
require_once __DIR__ . '/../models/Complaint.php';

$db = (new Database())->connect();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== getenv('DB_NAME')) throw new RuntimeException('Database mismatch.');
$roles = $db->query('SELECT role_name, role_id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
$userModel = new User(); $residentModel = new Resident($db); $actors = [];
foreach (['browser_resident' => 'Resident', 'browser_other' => 'Other'] as $username => $lastName) {
    $user = $userModel->findByUsernameOrEmail($username);
    if (!$user) {
        $result = $userModel->create(['first_name' => 'Synthetic', 'last_name' => $lastName, 'username' => $username,
            'email' => $username . '@example.invalid', 'contact_no' => null, 'password' => 'PassTumana2026!', 'role_id' => $roles['Resident'], 'status' => 'Active']);
        if (empty($result['success'])) throw new RuntimeException('Cannot create synthetic account.');
        $user = $userModel->findByUsernameOrEmail($username);
    }
    $profile = $db->prepare('SELECT resident_id FROM resident_user_profiles WHERE user_id = ?');
    $profile->execute([$user['user_id']]); $residentId = (int) $profile->fetchColumn();
    if (!$residentId) {
        $residentId = (int) $residentModel->create(['first_name' => 'Synthetic', 'last_name' => $lastName,
            'address' => 'Synthetic test address, Barangay Tumana, Marikina City', 'purok' => 'Purok 1']);
        $db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)')->execute([$user['user_id'], $residentId]);
    }
    $actors[$username] = ['user_id' => (int) $user['user_id'], 'resident_id' => $residentId];
}
$clerk = $userModel->findByUsernameOrEmail('clerk');
$_SESSION = ['user_id' => (int) $clerk['user_id'], 'role_id' => (int) $clerk['role_id'], 'role_name' => 'Lupon Clerk'];
$respondentId = (int) $residentModel->create(['first_name' => 'Synthetic', 'last_name' => 'Respondent', 'address' => 'Another synthetic test address, Barangay Tumana, Marikina City', 'purok' => 'Purok 2']);
$categoryId = (int) $db->query("SELECT category_id FROM complaint_categories WHERE category_name LIKE '%Neighborhood Nuisance%' LIMIT 1")->fetchColumn();
if (!$categoryId) $categoryId = (int) $db->query('SELECT category_id FROM complaint_categories LIMIT 1')->fetchColumn();
$tag = date('His'); $fixtures = ['database' => getenv('DB_NAME'), 'actors' => $actors, 'complaints' => []];
foreach (['confirm', 'object', 'isolation'] as $scenario) {
    $actor = $actors[$scenario === 'isolation' ? 'browser_other' : 'browser_resident'];
    $result = (new Complaint($db))->create([
        'complaint_title' => "Synthetic browser {$scenario} {$tag}", 'category_id' => $categoryId, 'case_type' => 'Civil',
        'narrative' => 'Synthetic browser test only. Maingay na videoke at gabi; no real persons or dispute are involved.',
        'incident_date' => date('Y-m-d', strtotime('-1 day')), 'incident_street' => 'Synthetic test street', 'incident_purok' => 'Purok 1',
        'complainant_resident_id' => $actor['resident_id'], 'respondent_resident_id' => $respondentId,
        'jurisdiction_screened' => '1', 'jurisdiction_complainant_resides' => '1', 'jurisdiction_respondent_resides' => '1',
        'duplicate_acknowledged' => '1', 'privacy_notice_acknowledged' => '1',
    ]);
    if (empty($result['success'])) throw new RuntimeException('Fixture intake failed: ' . ($result['message'] ?? 'unknown'));
    $id = (int) $result['complaint_id'];
    $db->prepare("UPDATE complaints SET submitted_by = ?, intake_source = 'Online', status = 'Mediation' WHERE complaint_id = ?")->execute([$actor['user_id'], $id]);
    $db->prepare("INSERT INTO cases (complaint_id, case_number, case_type, case_status, docket_date) VALUES (?, ?, 'Civil', 'Mediation', CURDATE())")
        ->execute([$id, 'BROWSER-' . $scenario . '-' . $tag]);
    $caseId = (int) $db->lastInsertId();
    $relative = "storage/uploads/attachments/browser-evidence-{$id}.png";
    $absolute = dirname(__DIR__, 2) . '/' . $relative;
    if (!is_dir(dirname($absolute))) mkdir(dirname($absolute), 0750, true);
    file_put_contents($absolute, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+X2Z0AAAAASUVORK5CYII='));
    $db->prepare('INSERT INTO complaint_attachments (complaint_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)')->execute([$id, 'Synthetic supporting evidence.png', $relative, 'image/png']);
    $fixtures['complaints'][$scenario] = ['complaint_id' => $id, 'case_id' => $caseId, 'evidence_reference' => 'attachment:' . $db->lastInsertId()];
}
$dir = dirname(__DIR__, 2) . '/output/browser-tests';
if (!is_dir($dir)) mkdir($dir, 0750, true);
file_put_contents($dir . '/fixtures.json', json_encode($fixtures, JSON_PRETTY_PRINT));
echo json_encode($fixtures, JSON_PRETTY_PRINT), PHP_EOL;
