-- Migration: Hearing attendance monitoring, justification, and recorder tracking
-- Implements attendance monitoring enhancements from the 2026-09-29 hearings update.
-- Adds justification tracking (is_justified, justification_reason) and actor audit (recorded_by).
-- Fresh installations receive these columns from database/schema.sql.
USE agap_db;

ALTER TABLE hearing_attendance
    ADD COLUMN is_justified TINYINT(1) NOT NULL DEFAULT 0 AFTER attendance_status,
    ADD COLUMN justification_reason VARCHAR(255) NULL AFTER is_justified,
    ADD COLUMN recorded_by INT UNSIGNED NULL AFTER remarks,
    ADD KEY idx_hearing_attendance_recorded_by (recorded_by),
    ADD CONSTRAINT fk_hearing_attendance_recorded_by
        FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE SET NULL;
