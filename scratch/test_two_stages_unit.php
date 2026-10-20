<?php
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/models/Paanyaya.php';

$db = (new Database())->connect();
$paa = new Paanyaya($db);

// Find an existing complaint
$cId = (int)$db->query("SELECT complaint_id FROM complaints ORDER BY complaint_id DESC LIMIT 1")->fetchColumn();
echo "Testing on Complaint ID: $cId\n";

$data = $paa->getTwoStagesData($cId);
echo "Stage 1 status: " . $data['stage_1']['details']['status'] . "\n";
echo "Stage 2 status: " . $data['stage_2']['details']['status'] . "\n";
echo "Stage 1 attempts count: " . $data['stage_1']['attempts_count'] . "\n";
echo "Stage 2 is eligible: " . ($data['stage_2']['is_eligible'] ? 'Yes' : 'No') . "\n";
echo "Summons prerequisite allowed: " . ($data['summons_prerequisite']['allowed'] ? 'Yes' : 'No') . "\n";
echo "Summons prerequisite message: " . $data['summons_prerequisite']['message'] . "\n";
echo "Serving officers found: " . count($data['serving_officers']) . "\n";
print_r(array_map(fn($o) => $o['full_name'] . ' (' . $o['role_name'] . ')', $data['serving_officers']));
