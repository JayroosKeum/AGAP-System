<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();

echo "=== CHECKING REPORT CONTROLLER ENDPOINTS ===\n";
$reportCtrl = file_get_contents('backend/controllers/ReportController.php');
preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\(/', $reportCtrl, $funcs);
print_r($funcs[1]);

echo "\n=== CHECKING TRANSMITTAL MODAL & PDF ===\n";
if (file_exists('frontend/layouts/transmittal-modal.php')) {
    $tm = file_get_contents('frontend/layouts/transmittal-modal.php');
    echo "transmittal-modal.php length: " . strlen($tm) . "\n";
    echo "Contains Secretary signature: " . (stripos($tm, 'Secretary') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains MTC / DILG: " . (stripos($tm, 'MTC') !== false ? 'YES' : 'NO') . "\n";
}

echo "\n=== CHECKING NAVBAR.PHP ===\n";
echo file_get_contents('frontend/layouts/navbar.php');

echo "\n=== CHECKING KP FORM 7 SIGNATURE LAYOUT IN COMPLAINT-CREATE.PHP ===\n";
$cc = file_get_contents('frontend/pages/complaints/complaint-create.php');
$sigPos = strpos($cc, 'Punong Barangay');
if ($sigPos !== false) {
    echo substr($cc, max(0, $sigPos - 200), 500) . "\n";
}

echo "\n=== CHECKING DUAL-MODE HEARING MINUTES ===\n";
$hmCols = $db->query("DESCRIBE hearing_minutes")->fetchAll(PDO::FETCH_ASSOC);
foreach ($hmCols as $c) {
    echo "hearing_minutes: {$c['Field']} ({$c['Type']})\n";
}
