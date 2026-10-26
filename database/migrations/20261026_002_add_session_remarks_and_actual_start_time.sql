-- Migration: Add session delay remarks and actual start time to paanyaya_minutes
-- Date: 2026-10-26

SET @dbname = DATABASE();

-- Safe to run repeatedly in local, staging, and production databases.
SET @table_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @dbname AND table_name = 'paanyaya_minutes');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'paanyaya_minutes' AND column_name = 'actual_start_time');
SET @sql = IF(@table_exists > 0 AND @col_exists = 0, 'ALTER TABLE paanyaya_minutes ADD COLUMN actual_start_time TIME NULL AFTER dialogue_date', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'paanyaya_minutes' AND column_name = 'session_remarks');
SET @sql = IF(@table_exists > 0 AND @col_exists = 0, 'ALTER TABLE paanyaya_minutes ADD COLUMN session_remarks TEXT NULL AFTER agreed_terms', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @dbname AND table_name = 'paanyaya_sessions');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'paanyaya_sessions' AND column_name = 'session_remarks');
SET @sql = IF(@table_exists > 0 AND @col_exists = 0, 'ALTER TABLE paanyaya_sessions ADD COLUMN session_remarks TEXT NULL AFTER outcome_notes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
