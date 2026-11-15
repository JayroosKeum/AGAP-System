-- Keep recipient-specific decisions available after a sibling's disposition.
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'show_cause_evaluations'
      AND COLUMN_NAME = 'action_taken' AND COLUMN_TYPE NOT LIKE '%Excused%'
), 'ALTER TABLE show_cause_evaluations MODIFY action_taken
    ENUM(''Rescheduled'', ''Barred Action'', ''Respondent Default'', ''CFA Issued'', ''Excused'') NOT NULL', 'SELECT 1');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

-- New bar certificates retain the issued recipient for document recovery.
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cfa_records' AND COLUMN_NAME = 'resident_id'
), 'SELECT 1', 'ALTER TABLE cfa_records
    ADD resident_id INT UNSIGNED NULL AFTER reason,
    ADD CONSTRAINT fk_cfa_records_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE SET NULL');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;
