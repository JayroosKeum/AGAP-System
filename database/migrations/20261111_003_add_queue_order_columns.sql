-- Migration: Ensure queue_order exists on hearings, paanyayas, and paanyaya_sessions
-- Date: 2026-11-11
-- Notes: Idempotent sync for existing databases created prior to queue_order in schema.sql

SET @db_name = DATABASE();

SET @col_h_qo = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db_name
      AND table_name = 'hearings'
      AND column_name = 'queue_order'
);
SET @sql = IF(
    @col_h_qo = 0,
    'ALTER TABLE hearings ADD COLUMN queue_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER attendance_notes',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_p_qo = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db_name
      AND table_name = 'paanyayas'
      AND column_name = 'queue_order'
);
SET @sql = IF(
    @col_p_qo = 0,
    'ALTER TABLE paanyayas ADD COLUMN queue_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER scheduled_by',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tbl_ps = (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = @db_name
      AND table_name = 'paanyaya_sessions'
);
SET @col_ps_qo = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db_name
      AND table_name = 'paanyaya_sessions'
      AND column_name = 'queue_order'
);
SET @sql = IF(
    @tbl_ps > 0 AND @col_ps_qo = 0,
    'ALTER TABLE paanyaya_sessions ADD COLUMN queue_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER queue_status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
