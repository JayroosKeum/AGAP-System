-- AGAP Scheduling and Mediation Module Improvements Migration
-- Migration: 20261017_002_scheduling_conflict_and_mediation_improvements.sql
-- File: database/migrations/20261017_002_scheduling_conflict_and_mediation_improvements.sql

USE agap_db;

-- 1. Ensure daily hearing capacity setting is 8 per barangay requirements
INSERT INTO system_settings (setting_key, setting_value, description)
VALUES ('daily_hearing_capacity', '8', 'Maximum total hearings allowed per day across the barangay (max 8)')
ON DUPLICATE KEY UPDATE setting_value = '8', description = 'Maximum total hearings allowed per day across the barangay (max 8)';

-- 2. Add mediation_attempt to hearings table if not present
SET @mediation_attempt_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'hearings'
      AND column_name = 'mediation_attempt'
);
SET @mediation_attempt_sql := IF(
    @mediation_attempt_exists = 0,
    'ALTER TABLE hearings ADD COLUMN mediation_attempt TINYINT UNSIGNED NULL DEFAULT NULL AFTER hearing_type',
    'SELECT 1'
);
PREPARE stmt_mediation_attempt FROM @mediation_attempt_sql;
EXECUTE stmt_mediation_attempt;
DEALLOCATE PREPARE stmt_mediation_attempt;

-- 3. Add attempt_exception_reason to hearings table if not present
SET @attempt_exception_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'hearings'
      AND column_name = 'attempt_exception_reason'
);
SET @attempt_exception_sql := IF(
    @attempt_exception_exists = 0,
    'ALTER TABLE hearings ADD COLUMN attempt_exception_reason TEXT NULL AFTER mediation_attempt',
    'SELECT 1'
);
PREPARE stmt_attempt_exception FROM @attempt_exception_sql;
EXECUTE stmt_attempt_exception;
DEALLOCATE PREPARE stmt_attempt_exception;

-- 4. Add cancellation_category to hearings table if not present
SET @cancellation_category_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'hearings'
      AND column_name = 'cancellation_category'
);
SET @cancellation_category_sql := IF(
    @cancellation_category_exists = 0,
    'ALTER TABLE hearings ADD COLUMN cancellation_category VARCHAR(100) NULL AFTER cancellation_reason',
    'SELECT 1'
);
PREPARE stmt_cancellation_category FROM @cancellation_category_sql;
EXECUTE stmt_cancellation_category;
DEALLOCATE PREPARE stmt_cancellation_category;

-- 5. Expand complainant_attendance and respondent_attendance ENUMs to include Pending, Present, Absent, Late, and Excused
ALTER TABLE hearings
    MODIFY COLUMN complainant_attendance ENUM('Present', 'Absent', 'Excused', 'Pending', 'Late') NULL DEFAULT 'Pending',
    MODIFY COLUMN respondent_attendance ENUM('Present', 'Absent', 'Excused', 'Pending', 'Late') NULL DEFAULT 'Pending';

UPDATE hearings 
SET complainant_attendance = 'Pending' 
WHERE complainant_attendance IS NULL OR complainant_attendance = '';

UPDATE hearings 
SET respondent_attendance = 'Pending' 
WHERE respondent_attendance IS NULL OR respondent_attendance = '';

-- 6. Configured holidays table
CREATE TABLE IF NOT EXISTS scheduling_holidays (
    holiday_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    holiday_name VARCHAR(150) NOT NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 7. Venue unavailabilities / maintenance schedules table
CREATE TABLE IF NOT EXISTS venue_unavailabilities (
    unavailability_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue VARCHAR(255) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vu_venue_time (venue, start_time, end_time),
    CONSTRAINT fk_vu_user FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 8. Backfill mediation_attempt for existing mediation hearings if not set
UPDATE hearings h
SET mediation_attempt = (
    SELECT cnt FROM (
        SELECT h2.hearing_id,
               (SELECT COUNT(*) FROM hearings h3 
                WHERE h3.case_id = h2.case_id 
                  AND h3.hearing_type IN ('Initial Hearing', 'Mediation') 
                  AND h3.hearing_id <= h2.hearing_id) AS cnt
        FROM hearings h2
        WHERE h2.hearing_type IN ('Initial Hearing', 'Mediation')
    ) sub WHERE sub.hearing_id = h.hearing_id
)
WHERE h.hearing_type IN ('Initial Hearing', 'Mediation') AND h.mediation_attempt IS NULL;
