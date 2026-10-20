<?php
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$needle = "CREATE TABLE complaint_scanned_documents";
$migration = file_get_contents(__DIR__ . '/../database/migrations/20261020_001_create_paanyaya_stages_and_returns.sql');
if (strpos($schema, "paanyaya_stages") === false) {
    $schema = str_replace($needle, $migration . "\n\n" . $needle, $schema);
    file_put_contents(__DIR__ . '/../database/schema.sql', $schema);
    echo "schema.sql updated successfully!\n";
} else {
    echo "already present\n";
}
