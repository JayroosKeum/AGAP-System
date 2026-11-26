-- AGAP Closure Verification Hardening Migration (A10 OTP Brute-Force Lockout)
-- Idempotent migration for existing databases.

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'closure_requests' AND COLUMN_NAME = 'failed_verification_attempts'
), 'SELECT 1', 'ALTER TABLE closure_requests ADD COLUMN failed_verification_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_hash');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'closure_requests' AND COLUMN_NAME = 'locked_until'
), 'SELECT 1', 'ALTER TABLE closure_requests ADD COLUMN locked_until DATETIME NULL AFTER failed_verification_attempts');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
