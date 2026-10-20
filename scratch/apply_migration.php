<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();
$sql = file_get_contents(__DIR__ . '/../database/migrations/20261020_001_create_paanyaya_stages_and_returns.sql');
$db->exec($sql);
echo "Migration applied successfully!\n";
$tables = $db->query("SHOW TABLES LIKE '%paanyaya%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
