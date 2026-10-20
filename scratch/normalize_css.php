<?php
$f = __DIR__ . '/../frontend/assets/css/case-details-modern.css';
$c = file_get_contents($f);
if (strpos($c, "\r\n") !== false) {
    file_put_contents($f, str_replace("\r\n", "\n", $c));
    echo "Normalized case-details-modern.css to LF\n";
} else {
    echo "Already LF\n";
}
