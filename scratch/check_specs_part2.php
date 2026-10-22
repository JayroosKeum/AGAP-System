<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();

echo "=== CHECK LTIA METRICS IN REPORT MODEL / CONTROLLER ===\n";
$reportFiles = [
    'backend/models/Report.php',
    'backend/controllers/ReportController.php',
    'backend/models/Paanyaya.php',
    'backend/controllers/ComplaintController.php'
];
foreach ($reportFiles as $rf) {
    if (file_exists($rf)) {
        $content = file_get_contents($rf);
        $hasLtia = stripos($content, 'ltia') !== false;
        $hasSettledPaanyaya = stripos($content, 'Settled via Paanyaya') !== false;
        echo "$rf -> LTIA: " . ($hasLtia ? 'YES' : 'NO') . " | Settled via Paanyaya: " . ($hasSettledPaanyaya ? 'YES' : 'NO') . "\n";
    }
}

echo "\n=== CHECK AUTHORIZATION LETTER ===\n";
$authMatches = glob('backend/*/*.php');
$authFound = false;
foreach ($authMatches as $am) {
    $c = file_get_contents($am);
    if (stripos($c, 'authorization_letter') !== false || stripos($c, 'authoriz') !== false && stripos($c, 'paanyaya') !== false) {
        echo "Found auth letter mention in: $am\n";
        $authFound = true;
    }
}
if (!$authFound) echo "No authorization letter references found in backend.\n";

echo "\n=== CHECK NEXT WORKING DAY IN SUMMONS ===\n";
$summonFiles = [
    'backend/models/Summons.php',
    'backend/services/SummonDeliveryService.php',
    'backend/models/SummonDelivery.php',
    'backend/controllers/HearingController.php'
];
foreach ($summonFiles as $sf) {
    if (file_exists($sf)) {
        $c = file_get_contents($sf);
        $hasNextWorkDay = stripos($c, 'next-working-day') !== false || stripos($c, 'working_day') !== false || stripos($c, 'addWorkingDays') !== false;
        echo "$sf -> Next Working Day logic: " . ($hasNextWorkDay ? 'YES' : 'NO') . "\n";
    } else {
        echo "$sf -> NOT FOUND\n";
    }
}
