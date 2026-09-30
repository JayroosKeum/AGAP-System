-- Migration: Hearing service verification, officer's return, attendance, explanations, legal actions, and rescheduling
-- Implements hearing attendance, service verification, nonappearance, explanation, rescheduling workflow.
-- Fresh installations receive these tables and columns from database/schema.sql.
USE agap_db;

CREATE TABLE IF NOT EXISTS hearing_party_services (
    service_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    party_type ENUM('Complainant','Respondent') NOT NULL,
    document_id INT UNSIGNED NOT NULL,
    service_date DATETIME NOT NULL,
    service_result ENUM('Served','Not Found','Wrong Address','Refused','Unsuccessful Attempt') NOT NULL,
    reason TEXT NULL,
    serving_officer INT UNSIGNED NOT NULL,
    officer_return TEXT NULL,
    supporting_file VARCHAR(1024) NULL,
    assigned_to INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hearing_party_services_party (hearing_id,resident_id,service_date),
    CONSTRAINT fk_hps_hearing FOREIGN KEY (hearing_id) REFERENCES hearings(hearing_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hps_party FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hps_document FOREIGN KEY (document_id) REFERENCES generated_documents(document_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hps_officer FOREIGN KEY (serving_officer) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hps_assigned FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hearing_explanations (
    explanation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    explanation TEXT NOT NULL,
    outcome ENUM('Pending','Justified','Unjustified') NOT NULL DEFAULT 'Pending',
    supporting_file VARCHAR(1024) NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hearing_explanations_party (hearing_id,resident_id),
    CONSTRAINT fk_hex_hearing FOREIGN KEY (hearing_id) REFERENCES hearings(hearing_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hex_party FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hex_decider FOREIGN KEY (decided_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hex_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hearing_legal_actions (
    legal_action_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(120) NOT NULL,
    status ENUM('Pending Review','Approved','Rejected','Recorded') NOT NULL DEFAULT 'Pending Review',
    details TEXT NOT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hearing_legal_actions_hearing (hearing_id,created_at),
    CONSTRAINT fk_hla_hearing FOREIGN KEY (hearing_id) REFERENCES hearings(hearing_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hla_party FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hla_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hla_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Safe column addition if not already present
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = 'agap_db'
      AND table_name = 'hearings'
      AND column_name = 'rescheduled_from_id'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE hearings ADD COLUMN rescheduled_from_id INT UNSIGNED NULL, ADD COLUMN reschedule_reason TEXT NULL, ADD CONSTRAINT fk_hearings_rescheduled_from FOREIGN KEY (rescheduled_from_id) REFERENCES hearings(hearing_id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE hearing_attendance
    MODIFY COLUMN attendance_status ENUM('Present','Absent','Late','Excused','Not Served') NOT NULL;

INSERT INTO document_templates (template_name, description) VALUES
    ('KP Form 18', 'Notice of Hearing (Failure to Appear - Complainant)'),
    ('KP Form 19', 'Notice of Hearing (Failure to Appear - Respondent)'),
    ('KP Form 21', 'Certificate to Bar Action'),
    ('KP Form 22', 'Certificate to Bar Action Counterclaim')
ON DUPLICATE KEY UPDATE description = VALUES(description);
