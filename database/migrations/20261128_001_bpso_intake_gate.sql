-- Phase 1: BPSO Role, Blotter Logging & Online/Walk-in Validation Gate
-- Idempotent migration for existing databases.

-- 1. Seed BPSO role
INSERT INTO roles (role_name) VALUES ('BPSO')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

-- 2. Add BPSO intake validation & blotter columns on complaints
SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'bpso_status'
), 'SELECT 1', "ALTER TABLE complaints ADD COLUMN bpso_status ENUM('Pending BPSO Review', 'Endorsed to LISO', 'Needs Clarification', 'Rejected') NOT NULL DEFAULT 'Endorsed to LISO' AFTER intake_source");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'bpso_blotter_no'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN bpso_blotter_no VARCHAR(64) NULL AFTER bpso_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'bpso_respondent_in_tumana'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN bpso_respondent_in_tumana TINYINT(1) NULL AFTER bpso_blotter_no');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'bpso_identity_verified'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN bpso_identity_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER bpso_respondent_in_tumana');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'bpso_private_notes'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN bpso_private_notes TEXT NULL AFTER bpso_identity_verified');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'bpso_rejection_reason'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN bpso_rejection_reason VARCHAR(2000) NULL AFTER bpso_private_notes');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'bpso_reviewed_by'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN bpso_reviewed_by INT UNSIGNED NULL AFTER bpso_rejection_reason');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'bpso_reviewed_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN bpso_reviewed_at DATETIME NULL AFTER bpso_reviewed_by');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND INDEX_NAME = 'idx_complaints_bpso_queue'
), 'SELECT 1', 'ALTER TABLE complaints ADD KEY idx_complaints_bpso_queue (bpso_status, intake_source, created_at)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND CONSTRAINT_NAME = 'fk_complaints_bpso_reviewed_by'
), 'SELECT 1', 'ALTER TABLE complaints ADD CONSTRAINT fk_complaints_bpso_reviewed_by FOREIGN KEY (bpso_reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Seed default local BPSO desk officer account (same local seed password policy as existing accounts)
INSERT INTO users (
    first_name,
    middle_name,
    last_name,
    username,
    email,
    contact_no,
    password_hash,
    role_id,
    status,
    notification_audio,
    notification_inbox,
    preferred_language
)
SELECT
    'Danilo',
    'R.',
    'Mendoza',
    'bpso',
    'bpso@agap.test',
    '09171234568',
    '$2y$12$poLM7m4KluD/WLCixCJi8.TSKxaEIthqba6uIhZHiib62Bbu1bZnC',
    roles.role_id,
    'Active',
    1,
    1,
    'fil'
FROM roles
WHERE roles.role_name = 'BPSO'
  AND NOT EXISTS (
      SELECT 1 FROM users WHERE users.username = 'bpso'
  );
