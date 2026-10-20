<?php
$path = __DIR__ . '/../backend/models/Paanyaya.php';
$content = file_get_contents($path);
$target = "\$upd->execute([\$decision, \$notes, \$userId, \$decision, \$stageId]);";
$replacement = "\$upd->execute([\$decision, \$notes, \$userId, \$stageId]);";
if (strpos($content, $target) !== false) {
    $content = str_replace($target, $replacement, $content);
    file_put_contents($path, $content);
    echo "Fixed reviewStage execute params successfully.\n";
} else {
    echo "Target not found or already fixed.\n";
}
