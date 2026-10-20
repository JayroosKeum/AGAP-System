<?php
$path = __DIR__ . '/../backend/models/Paanyaya.php';
$content = file_get_contents($path);

$target = "ORDER BY at.created_at DESC";
$replacement = "ORDER BY at.audit_id DESC";

$content = str_replace($target, $replacement, $content);
file_put_contents($path, $content);
echo "Fixed audit order column in Paanyaya.php\n";
