<?php
$f = __DIR__ . '/../backend/services/ComplaintLifecycleService.php';
$c = file_get_contents($f);
if (strpos($c, "\r\n") !== false) {
    file_put_contents($f, str_replace("\r\n", "\n", $c));
    echo "Normalized ComplaintLifecycleService.php\n";
}
