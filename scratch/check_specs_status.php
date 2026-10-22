<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();

echo "=== COMPLAINTS COLUMNS ===\n";
$cols = $db->query("DESCRIBE complaints")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ")\n";
}

echo "\n=== SETTINGS TABLE ===\n";
try {
    $settings = $db->query("SELECT * FROM system_settings")->fetchAll(PDO::FETCH_ASSOC);
    print_r($settings);
} catch (Exception $e) {
    echo "system_settings err: " . $e->getMessage() . "\n";
}

echo "\n=== HEARINGS COLUMNS ===\n";
$hcols = $db->query("DESCRIBE hearings")->fetchAll(PDO::FETCH_ASSOC);
foreach ($hcols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}

echo "\n=== CASES COLUMNS ===\n";
$ccols = $db->query("DESCRIBE cases")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ccols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}

echo "\n=== SETTLEMENTS COLUMNS ===\n";
try {
    $scols = $db->query("DESCRIBE settlements")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($scols as $c) {
        echo $c['Field'] . " (" . $c['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "settlements err: " . $e->getMessage() . "\n";
}

echo "\n=== ALL TABLES ===\n";
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
