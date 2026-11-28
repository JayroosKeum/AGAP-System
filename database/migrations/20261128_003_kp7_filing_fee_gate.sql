-- Phase 4: Step 5 Formal KP Form No. 7 Filing Fee Gate
-- Idempotent migration for existing databases.

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'filing_fee_status'
), 'SELECT 1', "ALTER TABLE complaints ADD COLUMN filing_fee_status ENUM('Unpaid', 'Paid', 'Exempt / Indigent') NOT NULL DEFAULT 'Unpaid' AFTER docketed_at");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'filing_fee_amount'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN filing_fee_amount DECIMAL(10,2) NULL AFTER filing_fee_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'filing_fee_or_number'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN filing_fee_or_number VARCHAR(64) NULL AFTER filing_fee_amount');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'filing_fee_paid_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN filing_fee_paid_at DATETIME NULL AFTER filing_fee_or_number');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'filing_fee_recorded_by'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN filing_fee_recorded_by INT UNSIGNED NULL AFTER filing_fee_paid_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'filing_fee_waiver_reason'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN filing_fee_waiver_reason VARCHAR(255) NULL AFTER filing_fee_recorded_by');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND CONSTRAINT_NAME = 'fk_complaints_filing_fee_recorded_by'
), 'SELECT 1', 'ALTER TABLE complaints ADD CONSTRAINT fk_complaints_filing_fee_recorded_by FOREIGN KEY (filing_fee_recorded_by) REFERENCES users (user_id) ON DELETE SET NULL');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('formal_kp_filing_fee_amount', '100.00', 'Official Barangay Tumana filing fee (PHP) for formal KP Form No. 7 docketing'),
    ('require_filing_fee_before_docket', '0', 'When 1, strictly require filing fee payment or indigency exemption before formal KP Form No. 7 docketing on all API endpoints')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
