<?php
require_once __DIR__ . '/../backend/config/database.php';
$db = (new Database())->connect();

echo "=== CHECK 1: SCREENING QUESTIONS IN COMPLAINT CREATE ===\n";
$create_content = file_get_contents('frontend/pages/complaints/complaint-create.php');
if (stripos($create_content, 'screening') !== false) {
    preg_match_all('/(screening|jurisdiction|question).*?(\n|\r\n)/i', $create_content, $matches);
    echo "Found " . count($matches[0]) . " screening matches\n";
    foreach (array_slice($matches[0], 0, 5) as $m) echo "  - " . trim($m) . "\n";
} else {
    echo "No screening questions in complaint-create.php\n";
}

echo "\n=== CHECK 2: RAW NARRATIVE IN COMPLAINT CREATE ===\n";
$hasRawNarrative = stripos($create_content, 'raw_narrative') !== false || stripos($create_content, 'rawNarrative') !== false || stripos($create_content, 'original_narrative') !== false;
echo "Raw narrative retention in complaint-create.php: " . ($hasRawNarrative ? 'YES' : 'NO') . "\n";

echo "\n=== CHECK 3: NOTICE ON FAILURE TO APPEAR & KP 9 ===\n";
$files = glob('backend/*/*.php');
$foundNoticeFailure = false;
foreach ($files as $f) {
    $c = file_get_contents($f);
    if (stripos($c, 'Notice of Failure to Appear') !== false || stripos($c, 'failure_to_appear') !== false) {
        echo "Found in $f\n";
        $foundNoticeFailure = true;
    }
}
if (!$foundNoticeFailure) echo "No 'Notice of Failure to Appear' found in backend.\n";

echo "\n=== CHECK 4: SUMMONS EXCEL ACTIVITY EXPORT ===\n";
$foundSummonsExport = false;
foreach ($files as $f) {
    $c = file_get_contents($f);
    if (stripos($c, 'exportSummons') !== false || (stripos($c, 'summon') !== false && stripos($c, 'excel') !== false)) {
        echo "Found summons excel export in $f\n";
        $foundSummonsExport = true;
    }
}
if (!$foundSummonsExport) echo "No summons excel activity export found in backend.\n";

echo "\n=== CHECK 5: MEDIATION MAX SESSIONS (2 OR 3?) ===\n";
$st = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%mediation_max%' OR setting_key LIKE '%session%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($st);

$hCtrl = file_get_contents('backend/controllers/HearingController.php');
if (preg_match_all('/mediation.*?(session|attempt|limit|max)/i', $hCtrl, $m)) {
    echo "HearingController mediation session limits:\n";
    foreach (array_slice($m[0], 0, 5) as $item) echo "  - " . trim($item) . "\n";
}

echo "\n=== CHECK 6: EMERGENCY POSTPONEMENT BUTTON ===\n";
$foundPostpone = false;
$allPHP = array_merge(glob('frontend/*/*.php'), glob('frontend/*/*/*.php'), glob('backend/*/*.php'));
foreach ($allPHP as $f) {
    $c = file_get_contents($f);
    if (stripos($c, 'Reset / Postpone Session') !== false || stripos($c, 'emergency_postpone') !== false || stripos($c, 'postpone_session') !== false) {
        echo "Found emergency postponement in $f\n";
        $foundPostpone = true;
    }
}
if (!$foundPostpone) echo "No emergency session postponement button found.\n";

echo "\n=== CHECK 7: LUPON DAILY DUTY ROSTER INTERFACE ===\n";
$foundRoster = false;
foreach ($allPHP as $f) {
    $c = file_get_contents($f);
    if (stripos($c, 'duty_roster') !== false || stripos($c, 'duty roster') !== false) {
        echo "Found duty roster in $f\n";
        $foundRoster = true;
    }
}
if (!$foundRoster) echo "No duty roster found.\n";

echo "\n=== CHECK 8: CASE REOPENING 'FOR CFA' & UNLOCK SETTLED CASES ===\n";
$foundReopenCFA = false;
foreach ($allPHP as $f) {
    $c = file_get_contents($f);
    if (stripos($c, 'Reopen Case: Non-Compliance') !== false || stripos($c, 'For CFA') !== false || stripos($c, 'reopen_settlement') !== false) {
        echo "Found Reopen For CFA in $f\n";
        $foundReopenCFA = true;
    }
}
if (!$foundReopenCFA) echo "No 'Reopen Case: Non-Compliance / Breach of Settlement' or 'For CFA' found.\n";

echo "\n=== CHECK 9: TOP NAVIGATION REPORTS HUB ===\n";
$nav = file_get_contents('frontend/layouts/navbar.php');
echo "Navbar has reports link: " . (stripos($nav, 'report') !== false ? 'YES' : 'NO') . "\n";
$sidebar = file_get_contents('frontend/layouts/sidebar.php');
echo "Sidebar has reports link: " . (stripos($sidebar, 'report') !== false ? 'YES' : 'NO') . "\n";

echo "\n=== CHECK 10: BLANK KP FORMS REPOSITORY ===\n";
$foundBlankRepo = false;
foreach ($allPHP as $f) {
    $c = file_get_contents($f);
    if (stripos($c, 'blank_forms') !== false || stripos($c, 'Blank KP Forms') !== false || stripos($c, 'kp_forms_repository') !== false) {
        echo "Found Blank Forms Repo in $f\n";
        $foundBlankRepo = true;
    }
}
if (!$foundBlankRepo) echo "No Blank KP Forms repository found.\n";

echo "\n=== CHECK 11: KP FORM 7 SIGNATURE LAYOUT ===\n";
$foundKp7 = false;
foreach ($allPHP as $f) {
    $c = file_get_contents($f);
    if (stripos($c, 'kp_form_7') !== false || stripos($c, 'kp_7') !== false || stripos($c, 'KP Form 7') !== false || stripos($c, 'KP-7') !== false || stripos($c, 'kp7') !== false) {
        echo "Found KP Form 7 reference in $f\n";
        $foundKp7 = true;
    }
}
if (!$foundKp7) echo "No KP Form 7 references found.\n";
