-- Keep Google account identifiers separate from email addresses.
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_subject'
), 'SELECT 1', 'ALTER TABLE users ADD COLUMN google_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_google_subject'
), 'SELECT 1', 'ALTER TABLE users ADD UNIQUE KEY uq_users_google_subject (google_subject)');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;
