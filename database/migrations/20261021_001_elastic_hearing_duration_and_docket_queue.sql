-- Elastic Hearing Duration and Daily Docket Queue Schema Update
-- Compatibility for MySQL 8.0+

SET @dbname = DATABASE();

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'hearings' AND column_name = 'actual_start_time');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE hearings ADD COLUMN actual_start_time DATETIME NULL AFTER actual_end_time', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'hearings' AND column_name = 'actual_duration_minutes');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE hearings ADD COLUMN actual_duration_minutes INT UNSIGNED NULL AFTER actual_start_time', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'hearings' AND column_name = 'queue_status');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE hearings ADD COLUMN queue_status ENUM(\'Waiting\', \'Ready\', \'In_Session\', \'Standby\', \'Concluded\') DEFAULT \'Waiting\' AFTER actual_duration_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @dbname AND table_name = 'paanyaya_sessions');

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'paanyaya_sessions' AND column_name = 'actual_start_time');
SET @sql = IF(@table_exists > 0 AND @col_exists = 0, 'ALTER TABLE paanyaya_sessions ADD COLUMN actual_start_time DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'paanyaya_sessions' AND column_name = 'actual_end_time');
SET @sql = IF(@table_exists > 0 AND @col_exists = 0, 'ALTER TABLE paanyaya_sessions ADD COLUMN actual_end_time DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @dbname AND table_name = 'paanyaya_sessions' AND column_name = 'queue_status');
SET @sql = IF(@table_exists > 0 AND @col_exists = 0, 'ALTER TABLE paanyaya_sessions ADD COLUMN queue_status ENUM(\'Waiting\', \'Ready\', \'In_Session\', \'Standby\', \'Concluded\') DEFAULT \'Waiting\'', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
