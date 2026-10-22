<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();

echo "=== 1. CHECK CATEGORIES IN DB ===\n";
$cats = $db->query("SELECT category_id, category_name FROM complaint_categories LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cats as $c) {
    echo "ID {$c['category_id']}: {$c['category_name']}\n";
}

echo "\n=== 2. CHECK SETTINGS FOR TUESDAY MEDIATION & CONCILIATION DAYS ===\n";
$st = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%tuesday%' OR setting_key LIKE '%mediation_day%' OR setting_key LIKE '%conciliation%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($st);

echo "\n=== 3. CHECK CASES STATUS ENUM ===\n";
$cases_status = $db->query("SHOW COLUMNS FROM cases LIKE 'case_status'")->fetch(PDO::FETCH_ASSOC);
echo "cases.case_status: " . $cases_status['Type'] . "\n";

echo "\n=== 4. CHECK SETTLEMENTS & CASE TRANSMITTALS ===\n";
$trans_cols = $db->query("DESCRIBE case_transmittals")->fetchAll(PDO::FETCH_ASSOC);
foreach ($trans_cols as $t) {
    echo "transmittal col: {$t['Field']} ({$t['Type']})\n";
}

echo "\n=== 5. CHECK LUPON DUTY ROSTER TABLES ===\n";
$roster_tables = $db->query("SHOW TABLES LIKE '%roster%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($roster_tables);
$duty_tables = $db->query("SHOW TABLES LIKE '%duty%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($duty_tables);

echo "\n=== 6. CHECK TRANSMITTAL REMINDER / DEADLINES ===\n";
$cd_cols = $db->query("DESCRIBE case_deadlines")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cd_cols as $c) {
    echo "deadline col: {$c['Field']} ({$c['Type']})\n";
}

echo "\n=== 7. CHECK SETTLEMENT COMPLIANCE VALUES ===\n";
$set_comp = $db->query("SHOW COLUMNS FROM settlements LIKE 'compliance_status'")->fetch(PDO::FETCH_ASSOC);
echo "settlements.compliance_status: " . $set_comp['Type'] . "\n";
