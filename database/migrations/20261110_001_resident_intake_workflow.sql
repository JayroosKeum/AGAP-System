-- Resident online/walk-in intake and Lupon Member screening-to-docket workflow.
-- LISO is an operational assignment of a Lupon Member, not a separate role.
INSERT INTO roles (role_name) VALUES ('Resident')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

CREATE TABLE IF NOT EXISTS resident_user_profiles (
    user_id INT UNSIGNED PRIMARY KEY,
    resident_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_resident_user_profiles_resident (resident_id),
    CONSTRAINT fk_resident_user_profiles_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_resident_user_profiles_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'intake_reference') = 0, 'ALTER TABLE complaints ADD COLUMN intake_reference VARCHAR(50) NULL AFTER review_notes', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'intake_source') = 0, "ALTER TABLE complaints ADD COLUMN intake_source ENUM('Online','Walk-in') NOT NULL DEFAULT 'Walk-in' AFTER intake_reference", 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'submitted_by') = 0, 'ALTER TABLE complaints ADD COLUMN submitted_by INT UNSIGNED NULL AFTER intake_source', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'liso_reviewed_by') = 0, 'ALTER TABLE complaints ADD COLUMN liso_reviewed_by INT UNSIGNED NULL AFTER submitted_by, ADD COLUMN liso_reviewed_at DATETIME NULL AFTER liso_reviewed_by', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'screening_decision') = 0, "ALTER TABLE complaints ADD COLUMN screening_decision ENUM('Pending','Needs Information','Eligible','Referred','Rejected','Duplicate') NOT NULL DEFAULT 'Pending' AFTER liso_reviewed_at", 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'duplicate_of_complaint_id') = 0, 'ALTER TABLE complaints ADD COLUMN duplicate_of_complaint_id INT UNSIGNED NULL AFTER screening_decision', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'docketed_by') = 0, 'ALTER TABLE complaints ADD COLUMN docketed_by INT UNSIGNED NULL AFTER duplicate_of_complaint_id, ADD COLUMN docketed_at DATETIME NULL AFTER docketed_by', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND INDEX_NAME = 'uq_complaints_intake_reference') = 0, 'ALTER TABLE complaints ADD UNIQUE KEY uq_complaints_intake_reference (intake_reference)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND INDEX_NAME = 'idx_complaints_intake_queue') = 0, 'ALTER TABLE complaints ADD KEY idx_complaints_intake_queue (screening_decision, intake_source, created_at)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
UPDATE complaints
SET intake_source = 'Walk-in', screening_decision = 'Eligible'
WHERE complaint_number IS NOT NULL;
