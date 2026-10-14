USE remastered_db;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS complaints (
    complaint_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_no VARCHAR(40) NOT NULL,
    complaint_type VARCHAR(150) NOT NULL,
    incident_at DATETIME NOT NULL,
    narrative LONGTEXT NOT NULL,
    relief_sought VARCHAR(150) NOT NULL,
    incident_purok VARCHAR(100) NOT NULL,
    incident_location VARCHAR(255) NOT NULL,
    incident_latitude DECIMAL(10,7) NOT NULL,
    incident_longitude DECIMAL(10,7) NOT NULL,
    process_stage ENUM('Intake','Summon','Mediation','Conciliation','CFA','Closed') NOT NULL DEFAULT 'Intake',
    status ENUM('Filed','Pending Review','Active','Resolved','Dismissed','Archived') NOT NULL DEFAULT 'Filed',
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaints_case_no (case_no),
    KEY idx_complaints_incident_at (incident_at),
    KEY idx_complaints_status_stage (status, process_stage),
    CONSTRAINT fk_complaints_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_complaints_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaint_parties (
    complaint_party_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id BIGINT UNSIGNED NOT NULL,
    party_type ENUM('Complainant','Respondent') NOT NULL,
    profile_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(255) NOT NULL,
    contact_no VARCHAR(30) NOT NULL,
    address_line VARCHAR(255) NOT NULL,
    purok_sitio VARCHAR(100) NOT NULL,
    is_manual TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_complaint_parties_complaint (complaint_id, party_type),
    KEY idx_complaint_parties_profile (profile_id),
    CONSTRAINT fk_complaint_parties_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE RESTRICT,
    CONSTRAINT fk_complaint_parties_profile FOREIGN KEY (profile_id) REFERENCES profiles(profile_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS complaint_history (
    complaint_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    details LONGTEXT NULL,
    acted_by INT UNSIGNED NOT NULL,
    acted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_complaint_history_complaint (complaint_id, acted_at),
    CONSTRAINT fk_complaint_history_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE RESTRICT,
    CONSTRAINT fk_complaint_history_actor FOREIGN KEY (acted_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
