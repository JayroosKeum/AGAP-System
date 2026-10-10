-- Migration: Add Arbitration support to case_stages and stage_transitions
ALTER TABLE case_stages MODIFY COLUMN stage_type ENUM('Mediation', 'Conciliation', 'Arbitration') NOT NULL;
ALTER TABLE stage_transitions MODIFY COLUMN stage_type ENUM('Mediation', 'Conciliation', 'Arbitration') NOT NULL;
ALTER TABLE hearing_minutes MODIFY COLUMN session_outcome ENUM('Settled', 'Continue Mediation', 'Continue Conciliation', 'Continue Arbitration', 'Failed', 'Party Absent', 'Rescheduled', 'Elevate to Pangkat', 'Pending CFA', 'Arbitration Award', 'Pending') NOT NULL DEFAULT 'Pending';

