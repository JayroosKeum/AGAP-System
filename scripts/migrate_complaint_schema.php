<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS complaint_categories (
            category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_complaint_categories_name (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $cats = [
        'Non-Payment of Debt', 'Breach of Agreement', 'Physical Injuries', 'Defamation',
        'Threats', 'Property and Rental Disputes', 'Disturbance and Public Disorder',
        'Malicious Mischief', 'Trespassing', 'Family and Domestic Disputes'
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO complaint_categories (category_name) VALUES (?)');
    foreach ($cats as $c) {
        $stmt->execute([$c]);
    }

    $existing = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'remastered_db' AND TABLE_NAME = 'complaints'")->fetchAll(PDO::FETCH_COLUMN);

    $colsToAdd = [
        'category_id' => 'INT UNSIGNED NULL AFTER case_no',
        'case_type' => "ENUM('Civil', 'Criminal') NOT NULL DEFAULT 'Civil' AFTER category_id",
        'complaint_title' => 'VARCHAR(255) NULL AFTER case_type',
        'incident_date' => 'DATE NULL AFTER incident_at',
        'incident_time' => 'TIME NULL AFTER incident_date',
        'incident_city' => "VARCHAR(100) NOT NULL DEFAULT 'Marikina City' AFTER incident_location",
        'incident_barangay' => "VARCHAR(100) NOT NULL DEFAULT 'Tumana' AFTER incident_city",
        'incident_street' => 'VARCHAR(255) NULL AFTER incident_barangay',
        'incident_landmark' => 'VARCHAR(255) NULL AFTER incident_street',
        'additional_details' => 'LONGTEXT NULL AFTER narrative',
        'proof_ocr_notes' => 'LONGTEXT NULL AFTER additional_details'
    ];

    foreach ($colsToAdd as $col => $def) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec("ALTER TABLE complaints ADD COLUMN `$col` $def");
            echo "Added column $col\n";
        }
    }

    $pdo->exec("
        ALTER TABLE complaints 
            MODIFY COLUMN complaint_type VARCHAR(150) NULL,
            MODIFY COLUMN relief_sought VARCHAR(150) NULL,
            MODIFY COLUMN incident_at DATETIME NULL,
            MODIFY COLUMN incident_latitude DECIMAL(10,7) NULL,
            MODIFY COLUMN incident_longitude DECIMAL(10,7) NULL
    ");

    $pdo->exec("ALTER TABLE complaint_parties MODIFY COLUMN party_type ENUM('Complainant', 'Respondent', 'Witness') NOT NULL");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS complaint_attachments (
            attachment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            complaint_id BIGINT UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(1024) NOT NULL,
            file_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_complaint_attachments_complaint (complaint_id),
            CONSTRAINT fk_complaint_attachments_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    echo "Migration completed successfully.\n";
} catch (Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
