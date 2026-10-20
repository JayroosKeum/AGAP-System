<?php

$file = __DIR__ . '/../frontend/pages/complaints/complaint-create.php';
$content = file_get_contents($file);
$content = preg_replace('/[ \t]*\/\/ \(pre-filing jurisdiction screen removed per request\)[\r\n]*/', '', $content);
file_put_contents($file, $content);
echo "Done cleaning comment.\n";
