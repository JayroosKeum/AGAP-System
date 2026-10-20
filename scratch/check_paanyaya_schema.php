<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();
$colsStages = $db->query('DESCRIBE paanyaya_stages')->fetchAll(PDO::FETCH_ASSOC);
echo "paanyaya_stages columns:\n";
foreach ($colsStages as $c) echo ' - ' . $c['Field'] . ' (' . $c['Type'] . ")\n";
$colsReturns = $db->query('DESCRIBE paanyaya_officer_returns')->fetchAll(PDO::FETCH_ASSOC);
echo "paanyaya_officer_returns columns:\n";
foreach ($colsReturns as $c) echo ' - ' . $c['Field'] . ' (' . $c['Type'] . ")\n";
