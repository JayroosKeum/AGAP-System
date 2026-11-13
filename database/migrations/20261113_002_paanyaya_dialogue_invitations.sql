-- Existing prepared invitations and delivery attempts belong to the first dialogue.
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyaya_service_attempts'
      AND CONSTRAINT_NAME = 'fk_paanyaya_service_preparation'
), 'ALTER TABLE paanyaya_service_attempts DROP FOREIGN KEY fk_paanyaya_service_preparation', 'SELECT 1');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyaya_preparations' AND COLUMN_NAME = 'invitation_number'
), 'SELECT 1', 'ALTER TABLE paanyaya_preparations ADD invitation_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER complaint_id, DROP PRIMARY KEY, ADD PRIMARY KEY (complaint_id, invitation_number)');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyaya_service_attempts' AND COLUMN_NAME = 'invitation_number'
), 'SELECT 1', 'ALTER TABLE paanyaya_service_attempts ADD invitation_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER complaint_id, ADD KEY idx_paanyaya_service_invitation (complaint_id, invitation_number, attempt_id)');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyaya_service_attempts'
      AND CONSTRAINT_NAME = 'fk_paanyaya_service_invitation'
), 'SELECT 1', 'ALTER TABLE paanyaya_service_attempts ADD CONSTRAINT fk_paanyaya_service_invitation FOREIGN KEY (complaint_id, invitation_number) REFERENCES paanyaya_preparations (complaint_id, invitation_number) ON DELETE CASCADE');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'paanyaya_service_attempts' AND INDEX_NAME = 'idx_paanyaya_service_complaint'
), 'ALTER TABLE paanyaya_service_attempts DROP INDEX idx_paanyaya_service_complaint', 'SELECT 1');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;
