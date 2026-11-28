-- Phase 3: LISO Pre-Filing Counseling, External Agency Endorsements & Max-2 Paanyaya Setting
-- Idempotent migration for existing databases.

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'liso_endorsement_agency'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN liso_endorsement_agency VARCHAR(120) NULL AFTER review_notes');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'liso_counseling_notes'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN liso_counseling_notes TEXT NULL AFTER liso_endorsement_agency');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'liso_endorsed_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN liso_endorsed_at DATETIME NULL AFTER liso_counseling_notes');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO system_settings (setting_key, setting_value, description)
VALUES ('paanyaya_max_attempts', '2', 'Maximum Paanyaya pre-filing invitations prior to formal KP docketing (Step 3 & 4 rule)')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
