-- Migration: Persist AI Classification Suggestion Alongside Complaint Intake Screening
-- Date: 2026-11-11

SET @col_ai_sug = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'complaint_intake_screenings'
      AND column_name = 'ai_suggestion_json'
);
SET @sql = IF(
    @col_ai_sug = 0,
    'ALTER TABLE complaint_intake_screenings ADD COLUMN ai_suggestion_json LONGTEXT NULL AFTER screening_notes',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
