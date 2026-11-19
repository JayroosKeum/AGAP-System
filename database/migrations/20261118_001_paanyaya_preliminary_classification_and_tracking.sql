-- Add preliminary classification and tracking reference columns to paanyayas.
-- Matches database/schema.sql from commit f6c3a9d; idempotent for existing databases.

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyayas' AND COLUMN_NAME = 'preliminary_category_id'
), 'SELECT 1', 'ALTER TABLE paanyayas ADD COLUMN preliminary_category_id INT UNSIGNED NULL AFTER location_longitude');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyayas' AND COLUMN_NAME = 'preliminary_case_type'
), 'SELECT 1', 'ALTER TABLE paanyayas ADD COLUMN preliminary_case_type ENUM(\'Civil\', \'Criminal\') NULL DEFAULT \'Civil\' AFTER preliminary_category_id');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyayas' AND COLUMN_NAME = 'tracking_reference'
), 'SELECT 1', 'ALTER TABLE paanyayas ADD COLUMN tracking_reference VARCHAR(50) NULL AFTER preliminary_case_type');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;
