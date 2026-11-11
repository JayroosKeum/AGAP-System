-- Migration: Unified Online/Walk-in Intake, Duplicate Linking, Role Seeds & Cross-Stage Minutes Carryover
-- Date: 2026-11-10
-- Target: MySQL 8.0+ / Azure Database for MySQL

-- 1. Seed additional workflow roles (BPSO, LISO, Resident) without altering existing role IDs 1..4
INSERT INTO roles (role_name) VALUES
    ('BPSO'),
    ('LISO'),
    ('Resident')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

-- 2. Complaints: source tracking, resident/submitter ownership, walk-in duplicate linking
SET @col_source = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'complaints'
      AND column_name = 'complaint_source'
);
SET @sql = IF(
    @col_source = 0,
    "ALTER TABLE complaints ADD COLUMN complaint_source ENUM('Online', 'Walk-in') NOT NULL DEFAULT 'Walk-in' AFTER encoded_by",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_linked = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'complaints'
      AND column_name = 'linked_to_complaint_id'
);
SET @sql = IF(
    @col_linked = 0,
    'ALTER TABLE complaints ADD COLUMN linked_to_complaint_id INT UNSIGNED NULL AFTER complaint_source, ADD KEY idx_complaints_linked_to (linked_to_complaint_id), ADD CONSTRAINT fk_complaints_linked_to FOREIGN KEY (linked_to_complaint_id) REFERENCES complaints (complaint_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_created_by = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'complaints'
      AND column_name = 'created_by'
);
SET @sql = IF(
    @col_created_by = 0,
    'ALTER TABLE complaints ADD COLUMN created_by INT UNSIGNED NULL AFTER linked_to_complaint_id, ADD KEY idx_complaints_created_by (created_by), ADD CONSTRAINT fk_complaints_created_by FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_walkin = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'complaints'
      AND column_name = 'walkin_verified_at'
);
SET @sql = IF(
    @col_walkin = 0,
    'ALTER TABLE complaints ADD COLUMN walkin_verified_at DATETIME NULL AFTER created_by, ADD COLUMN walkin_verified_by INT UNSIGNED NULL AFTER walkin_verified_at, ADD COLUMN walkin_notes TEXT NULL AFTER walkin_verified_by, ADD CONSTRAINT fk_complaints_walkin_verified_by FOREIGN KEY (walkin_verified_by) REFERENCES users (user_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Paanyaya minutes: structured carryover & generated document path
SET @col_pm_dispute = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'paanyaya_minutes'
      AND column_name = 'main_dispute_identified'
);
SET @sql = IF(
    @col_pm_dispute = 0,
    'ALTER TABLE paanyaya_minutes ADD COLUMN main_dispute_identified TEXT NULL AFTER discussion_summary, ADD COLUMN unresolved_issues TEXT NULL AFTER agreed_terms, ADD COLUMN generated_pdf_path VARCHAR(500) NULL AFTER scanned_minutes_path',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Hearing minutes: carryover provenance & generated document path
SET @col_hm_carried = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'hearing_minutes'
      AND column_name = 'carried_from_stage'
);
SET @sql = IF(
    @col_hm_carried = 0,
    'ALTER TABLE hearing_minutes ADD COLUMN carried_from_stage VARCHAR(50) NULL AFTER unresolved_issues, ADD COLUMN carried_from_id INT UNSIGNED NULL AFTER carried_from_stage, ADD COLUMN generated_pdf_path VARCHAR(500) NULL AFTER carried_from_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
