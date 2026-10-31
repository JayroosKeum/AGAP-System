-- Migration: 20261031_002_clean_paanyaya_stages_officer_and_status.sql
-- Purpose: Remove assigned_officer_id and update status enum on paanyaya_stages table

UPDATE paanyaya_stages 
SET status = 'Completed' 
WHERE status IN ('Successfully Delivered') OR status LIKE 'Attempts Exhausted%';

-- Drop foreign key and column assigned_officer_id
ALTER TABLE paanyaya_stages DROP FOREIGN KEY fk_paanyaya_stage_assigned;
ALTER TABLE paanyaya_stages DROP COLUMN assigned_officer_id;

-- Modify status column to canonical enum
ALTER TABLE paanyaya_stages MODIFY COLUMN status ENUM('Not Started', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Not Started';
