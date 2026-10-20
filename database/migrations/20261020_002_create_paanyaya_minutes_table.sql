-- Migration: Create Paanyaya Minutes table and track complainant choice

CREATE TABLE IF NOT EXISTS paanyaya_minutes (
    minutes_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    stage_type ENUM('Paanyaya 1', 'Paanyaya 2') NOT NULL,
    dialogue_date DATETIME NOT NULL,
    complainant_attended ENUM('Present', 'Absent') DEFAULT 'Present',
    respondent_attended ENUM('Present', 'Absent') DEFAULT 'Present',
    discussion_summary TEXT NOT NULL,
    agreed_terms TEXT NULL,
    outcome ENUM('Settled', 'No_Agreement', 'Party_Absent') NOT NULL,
    complainant_choice ENUM('Request_Paanyaya_2', 'Escalate_Mediation', 'None') DEFAULT 'None',
    scanned_minutes_path VARCHAR(255) NULL,
    recorded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
