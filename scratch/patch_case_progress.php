<?php
$path = __DIR__ . '/../backend/models/CaseProgress.php';
$content = file_get_contents($path);
$target = "\$summonButton['tooltip'] = \$prereq['message'] ?? 'Complete the required Paanyaya process before issuing the first formal summons.';";
$replacement = "\$summonButton['tooltip'] = \$prereq['message'] ?? 'Complete the required Paanyaya process before issuing the first formal summons.';\n                \$summonButton['is_locked_by_paanyaya'] = true;";
if (strpos($content, "'is_locked_by_paanyaya'") === false) {
    $content = str_replace($target, $replacement, $content);
    file_put_contents($path, $content);
    echo "Patched CaseProgress.php\n";
} else {
    echo "CaseProgress.php already has is_locked_by_paanyaya\n";
}
