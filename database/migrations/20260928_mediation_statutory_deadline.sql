-- Migration: 15-day statutory mediation deadline tracking and suspension controls
-- Implements RA 7160 Section 410(b) mediation deadline rules.

ALTER TABLE cases
    ADD COLUMN mediation_start_date DATE NULL AFTER archived_date,
    ADD COLUMN mediation_deadline_date DATE NULL AFTER mediation_start_date,
    ADD COLUMN is_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER mediation_deadline_date,
    ADD COLUMN paused_at DATETIME NULL AFTER is_paused,
    ADD COLUMN resumed_at DATETIME NULL AFTER paused_at,
    ADD COLUMN pause_reason VARCHAR(255) NULL AFTER resumed_at,
    ADD COLUMN pause_notes TEXT NULL AFTER pause_reason,
    ADD KEY idx_cases_mediation_deadline (mediation_deadline_date);
