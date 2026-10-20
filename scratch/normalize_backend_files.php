<?php
$files = [
    __DIR__ . '/../backend/controllers/ComplaintController.php',
    __DIR__ . '/../backend/models/Summons.php',
    __DIR__ . '/../backend/models/CaseProgress.php',
];
foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        if (strpos($c, "\r\n") !== false) {
            file_put_contents($f, str_replace("\r\n", "\n", $c));
            echo "Normalized " . basename($f) . "\n";
        }
    }
}
