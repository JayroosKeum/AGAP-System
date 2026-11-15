<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

$db = (new Database())->connect();
$database = (string) getenv('DB_NAME');
if (!str_starts_with($database, 'agap_upgrade_') || $db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Use a disposable agap_upgrade_ database for the migration fixtures.');
}

if (($argv[1] ?? '') === 'seed') {
    $db->exec("INSERT INTO complaints (category_id, complaint_title, narrative, incident_date)
        VALUES (1, 'Paanyaya legacy fixture', 'Linked legacy invitations', CURDATE()),
               (1, 'Paanyaya ambiguous fixture', 'Two first invitations', CURDATE())");
    $complaints = $db->query("SELECT complaint_id FROM complaints WHERE complaint_title IN ('Paanyaya legacy fixture', 'Paanyaya ambiguous fixture') ORDER BY complaint_id")->fetchAll(PDO::FETCH_COLUMN);
    $insert = $db->prepare("INSERT INTO paanyayas (complaint_id, complainant_name, invitee_name, complainant_resident_id,
        scheduled_date, scheduled_time, attempt_number, salaysay) VALUES (?, 'Fixture Complainant', 'Fixture Invitee', ?, CURDATE(), '14:00:00', ?, ?)");
    $insert->execute([null, 1, 1, 'migration: standalone first']);
    $insert->execute([null, 2, 2, 'migration: unrelated with matching names']);
    $insert->execute([null, 1, 2, 'migration: same parties without case evidence']);
    $insert->execute([$complaints[0], 1, 1, 'migration: linked first']);
    $insert->execute([$complaints[0], 1, 2, 'migration: linked second']);
    $insert->execute([$complaints[1], 1, 1, 'migration: ambiguous first A']);
    $insert->execute([$complaints[1], 1, 1, 'migration: ambiguous first B']);
    $insert->execute([$complaints[1], 1, 2, 'migration: ambiguous second']);
    exit;
}

if (($argv[1] ?? '') !== 'verify') {
    throw new InvalidArgumentException('Pass seed or verify.');
}

$fixtures = $db->query("SELECT salaysay, paanyaya_id, parent_paanyaya_id FROM paanyayas WHERE salaysay LIKE 'migration: %'")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
if (count($fixtures) !== 8) {
    throw new RuntimeException('Paanyaya upgrade fixtures are missing.');
}
foreach ($fixtures as $label => $row) {
    $expected = $label === 'migration: linked second' ? $fixtures['migration: linked first']['paanyaya_id'] : null;
    if ($row['parent_paanyaya_id'] !== $expected) {
        throw new RuntimeException("Incorrect parent for {$label}.");
    }
}
if (!$db->query("SHOW INDEX FROM paanyayas WHERE Key_name = 'idx_paanyayas_parent'")->fetch()) {
    throw new RuntimeException('The Paanyaya parent index is missing.');
}
echo "Paanyaya upgrade preserves unrelated and ambiguous invitations and links the verified complaint thread.\n";
