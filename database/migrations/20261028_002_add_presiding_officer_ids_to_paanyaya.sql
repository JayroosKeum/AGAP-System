-- Migration: Add presiding_officer_ids JSON column to paanyaya_minutes and paanyaya_sessions
-- Enables selecting multi-select presiding officers / co-presiders for Paanyaya dialogue sessions

ALTER TABLE paanyaya_minutes 
  ADD COLUMN presiding_officer_ids JSON NULL AFTER recorded_by;

ALTER TABLE paanyaya_sessions 
  ADD COLUMN presiding_officer_ids JSON NULL AFTER scheduled_by;
