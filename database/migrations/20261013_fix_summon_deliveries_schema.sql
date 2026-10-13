-- =============================================================================
-- Migration: 20261013_fix_summon_deliveries_schema.sql
-- Purpose : Fix summon_deliveries constraints preventing summons issuance and delivery:
--           1. Make case_id and hearing_id NULLable with default NULL
--           2. Convert delivery_status to VARCHAR(50) so all status variants are supported
--           3. Ensure all columns needed by both legacy and new workflows exist
-- =============================================================================

ALTER TABLE summon_deliveries
    MODIFY COLUMN hearing_id INT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN case_id INT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN delivery_status VARCHAR(50) NOT NULL DEFAULT 'Pending';

-- Ensure document_id exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'document_id'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN document_id INT NULL AFTER case_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure party_id exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'party_id'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN party_id INT NULL AFTER resident_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure attempt_number exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'attempt_number'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure service_method exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'service_method'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN service_method VARCHAR(50) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure attempted_at exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'attempted_at'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN attempted_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure served_by_user_id exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'served_by_user_id'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN served_by_user_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure service_address exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'service_address'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN service_address TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure proof_file_path exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'proof_file_path'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN proof_file_path VARCHAR(500) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure created_by exists
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'summon_deliveries'
      AND COLUMN_NAME  = 'created_by'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN created_by INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
