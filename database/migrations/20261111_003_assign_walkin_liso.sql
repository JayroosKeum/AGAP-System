-- Link clerk-encoded walk-in intakes to the Lupon Member who was timed in
-- for LISO screening. Apply after 20261111_002_liso_duty_sessions.sql.
SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'liso_assigned_to') = 0,
    'ALTER TABLE complaints ADD COLUMN liso_assigned_to INT UNSIGNED NULL AFTER liso_reviewed_at, ADD COLUMN liso_assigned_duty_session_id BIGINT UNSIGNED NULL AFTER liso_assigned_to, ADD COLUMN liso_assigned_at DATETIME NULL AFTER liso_assigned_duty_session_id',
    'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND INDEX_NAME = 'idx_complaints_liso_assignment_queue') = 0,
    'ALTER TABLE complaints ADD KEY idx_complaints_liso_assignment_queue (liso_assigned_to, screening_decision, intake_source)',
    'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND CONSTRAINT_NAME = 'fk_complaints_liso_assigned_to') = 0,
    'ALTER TABLE complaints ADD CONSTRAINT fk_complaints_liso_assigned_to FOREIGN KEY (liso_assigned_to) REFERENCES users (user_id) ON DELETE SET NULL',
    'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND CONSTRAINT_NAME = 'fk_complaints_liso_assigned_duty_session') = 0,
    'ALTER TABLE complaints ADD CONSTRAINT fk_complaints_liso_assigned_duty_session FOREIGN KEY (liso_assigned_duty_session_id) REFERENCES liso_duty_sessions (duty_session_id) ON DELETE SET NULL',
    'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
