<?php
$content = file_get_contents(__DIR__ . '/../backend/models/Paanyaya.php');
if (strpos($content, "\r\n") !== false) {
    echo "Has CRLF\n";
    $content = str_replace("\r\n", "\n", $content);
    file_put_contents(__DIR__ . '/../backend/models/Paanyaya.php', $content);
    echo "Normalized to LF\n";
} else {
    echo "Has LF\n";
}
