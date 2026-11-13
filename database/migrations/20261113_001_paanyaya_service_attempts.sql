-- Apply after 20261112_001_paanyaya_preparations.sql.
CREATE TABLE IF NOT EXISTS paanyaya_service_attempts (
    attempt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    appointment_at DATETIME NOT NULL,
    document_version CHAR(64) NOT NULL,
    assigned_to INT UNSIGNED NOT NULL,
    released_by INT UNSIGNED NULL,
    released_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    outcome ENUM('Delivered', 'Not Delivered') NULL,
    notes VARCHAR(2000) NULL,
    recorded_at DATETIME NULL,
    proof_mime VARCHAR(32) NULL,
    proof_image MEDIUMBLOB NULL,
    KEY idx_paanyaya_service_complaint (complaint_id, attempt_id),
    KEY idx_paanyaya_service_assignment (assigned_to, outcome, attempt_id),
    CONSTRAINT fk_paanyaya_service_preparation FOREIGN KEY (complaint_id) REFERENCES paanyaya_preparations (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_paanyaya_service_assignee FOREIGN KEY (assigned_to) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_paanyaya_service_releaser FOREIGN KEY (released_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
