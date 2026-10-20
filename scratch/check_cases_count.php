<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();
$cases = $db->query("SELECT case_id, complaint_id, case_number FROM cases")->fetchAll(PDO::FETCH_ASSOC);
echo "Total cases: " . count($cases) . "\n";
print_r($cases);
