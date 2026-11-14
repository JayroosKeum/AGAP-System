-- Role-only legacy determinations are retained for history. Their recipient and
-- notice cannot be inferred safely when more than one party shared that role.
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'show_cause_evaluations' AND COLUMN_NAME = 'resident_id'
), 'SELECT 1', 'ALTER TABLE show_cause_evaluations
    ADD resident_id INT UNSIGNED NULL AFTER party_type,
    ADD notice_delivery_id INT UNSIGNED NULL AFTER resident_id,
    ADD CONSTRAINT fk_show_cause_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_show_cause_notice_delivery FOREIGN KEY (notice_delivery_id) REFERENCES summon_deliveries (delivery_id) ON DELETE SET NULL');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;
