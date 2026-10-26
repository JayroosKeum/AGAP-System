-- Migration: Add session delay remarks and actual start time to paanyaya_minutes
-- Date: 2026-10-26

ALTER TABLE paanyaya_minutes
ADD COLUMN actual_start_time TIME NULL AFTER dialogue_date,
ADD COLUMN session_remarks TEXT NULL AFTER agreed_terms;

-- Also ensure paanyaya_sessions can hold session_remarks
ALTER TABLE paanyaya_sessions
ADD COLUMN session_remarks TEXT NULL AFTER outcome_notes;
