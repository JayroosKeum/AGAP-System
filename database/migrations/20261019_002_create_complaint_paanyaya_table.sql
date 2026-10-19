-- Migration: 20261019_002_create_complaint_paanyaya_table.sql
-- Description: Create complaint_paanyaya table and support Pre-Docketed (Paanyaya) workflow

ALTER TABLE complaints MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Filed';

CREATE TABLE IF NOT EXISTS complaint_paanyaya (
    paanyaya_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    invitation_date DATE NOT NULL,
    invitation_time TIME NOT NULL,
    venue VARCHAR(255) NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
    hearing_day VARCHAR(50) NULL,
    salaysay TEXT NULL,
    status ENUM('Scheduled', 'Resolved', 'Failed', 'Escalated') NOT NULL DEFAULT 'Scheduled',
    resolution_notes TEXT NULL,
    pdf_path VARCHAR(255) NULL,
    has_e_signature TINYINT(1) NOT NULL DEFAULT 1,
    scheduled_by INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    escalated_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_paanyaya_complaint (complaint_id),
    KEY idx_paanyaya_status (status),
    CONSTRAINT fk_paanyaya_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings (setting_key, setting_value, description)
VALUES ('captain_name', 'HON. AKIKO LYNNE CENTENO', 'Official Name of Punong Barangay')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
