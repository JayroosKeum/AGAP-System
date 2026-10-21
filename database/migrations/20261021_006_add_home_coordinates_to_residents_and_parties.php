<?php
require_once __DIR__ . '/../../backend/config/Database.php';

try {
    $db = (new Database())->connect();

    // 1. Alter residents table
    $cols = $db->query("SHOW COLUMNS FROM residents LIKE 'home_latitude'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE residents ADD COLUMN home_latitude DECIMAL(10, 8) NULL AFTER address, ADD COLUMN home_longitude DECIMAL(11, 8) NULL AFTER home_latitude");
        echo "Added home_latitude and home_longitude to residents.\n";
    } else {
        echo "Columns already exist in residents.\n";
    }

    // 2. Alter complaint_parties table
    $colsParty = $db->query("SHOW COLUMNS FROM complaint_parties LIKE 'home_latitude'")->fetchAll();
    if (empty($colsParty)) {
        $db->exec("ALTER TABLE complaint_parties ADD COLUMN home_latitude DECIMAL(10, 8) NULL AFTER guardian_address, ADD COLUMN home_longitude DECIMAL(11, 8) NULL AFTER home_latitude");
        echo "Added home_latitude and home_longitude to complaint_parties.\n";
    } else {
        echo "Columns already exist in complaint_parties.\n";
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Error running migration: " . $e->getMessage() . "\n";
    exit(1);
}
