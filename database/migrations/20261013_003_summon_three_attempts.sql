-- =============================================================================
-- Migration: 20261013_003_summon_three_attempts.sql
-- Purpose:
--   1. Replace uq_hearing_party_form with uq_hearing_party_form_attempt to support
--      up to 3 independent delivery attempt records per hearing/party.
--   2. Ensure delivery_status is VARCHAR(50) to support all status variants.
--   3. Ensure unserved_reason supports 'Refused' and other failure reasons.
--   4. Ensure attempt_number and attempt index are present.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Modify delivery_status to VARCHAR(50)
ALTER TABLE summon_deliveries
    MODIFY COLUMN hearing_id INT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN case_id INT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN delivery_status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    MODIFY COLUMN unserved_reason VARCHAR(100) NULL DEFAULT NULL;

-- 2. Drop old uq_hearing_party_form unique index if it exists
SET @drop_uq = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE summon_deliveries DROP INDEX uq_hearing_party_form',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'summon_deliveries'
      AND INDEX_NAME = 'uq_hearing_party_form'
);
PREPARE stmt_drop FROM @drop_uq;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;

-- 3. Add uq_hearing_party_form_attempt unique index if not exists
SET @add_uq = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE summon_deliveries ADD UNIQUE KEY uq_hearing_party_form_attempt (hearing_id, party_type, form_type, attempt_number)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'summon_deliveries'
      AND INDEX_NAME = 'uq_hearing_party_form_attempt'
);
PREPARE stmt_add FROM @add_uq;
EXECUTE stmt_add;
DEALLOCATE PREPARE stmt_add;

-- 4. Ensure attempt_number column exists with default 1
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'summon_deliveries'
      AND COLUMN_NAME = 'attempt_number'
);
SET @sql_col = IF(@col_exists = 0, 'ALTER TABLE summon_deliveries ADD COLUMN attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER party_type', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- 5. Ensure index on (case_id, attempt_number) exists
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'summon_deliveries'
      AND INDEX_NAME = 'idx_summon_deliveries_case_attempt'
);
SET @sql_idx = IF(@idx_exists = 0, 'ALTER TABLE summon_deliveries ADD INDEX idx_summon_deliveries_case_attempt (case_id, attempt_number)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

SELECT '[OK] Migration 20261013_003_summon_three_attempts executed successfully.' AS result;
