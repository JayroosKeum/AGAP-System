-- Keeps existing installations compatible with the complaint case workspace.
-- Fresh installations already receive these columns from database/schema.sql.
USE agap_db;

SET @attendance_recorded_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'hearings'
      AND column_name = 'attendance_recorded_at'
);
SET @attendance_recorded_at_sql := IF(
    @attendance_recorded_at_exists = 0,
    'ALTER TABLE hearings ADD COLUMN attendance_recorded_at DATETIME NULL AFTER respondent_attendance',
    'SELECT 1'
);
PREPARE attendance_recorded_at_statement FROM @attendance_recorded_at_sql;
EXECUTE attendance_recorded_at_statement;
DEALLOCATE PREPARE attendance_recorded_at_statement;

SET @attendance_notes_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'hearings'
      AND column_name = 'attendance_notes'
);
SET @attendance_notes_sql := IF(
    @attendance_notes_exists = 0,
    'ALTER TABLE hearings ADD COLUMN attendance_notes TEXT NULL AFTER attendance_recorded_at',
    'SELECT 1'
);
PREPARE attendance_notes_statement FROM @attendance_notes_sql;
EXECUTE attendance_notes_statement;
DEALLOCATE PREPARE attendance_notes_statement;
