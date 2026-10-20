<?php
$f = __DIR__ . '/../frontend/pages/complaints/complaint-details.php';
$c = file_get_contents($f);
if (strpos($c, "\r\n") !== false) {
    file_put_contents($f, str_replace("\r\n", "\n", $c));
    echo "Normalized complaint-details.php to LF\n";
} else {
    echo "Already LF\n";
}
