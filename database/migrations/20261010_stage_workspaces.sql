-- Migration: AGAP Mediation & Pangkat Conciliation Stage Workspaces
-- File: database/migrations/20261010_stage_workspaces.sql

-- 1. Create case_stages table to track stage-specific lifecycle, referral, and outcomes
CREATE TABLE IF NOT EXISTS case_stages (
    stage_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    stage_type ENUM('Mediation', 'Conciliation') NOT NULL,
    stage_status ENUM('Locked', 'Ready for Referral', 'Not Started', 'In Progress', 'Awaiting Outcome', 'Completed', 'Referred to Pangkat') NOT NULL DEFAULT 'Not Started',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    outcome ENUM('Settled', 'Unsuccessful', 'Repudiated', 'Dismissed', 'Referred to Pangkat', 'Pending') NOT NULL DEFAULT 'Pending',
    outcome_remarks TEXT NULL,
    referral_date DATE NULL,
    referring_officer_id INT UNSIGNED NULL,
    referral_reason TEXT NULL,
    records_transmitted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_case_stage (case_id, stage_type),
    KEY idx_cs_case (case_id),
    KEY idx_cs_status (stage_status),
    CONSTRAINT fk_cs_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_officer FOREIGN KEY (referring_officer_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 2. Create stage_transitions audit table
CREATE TABLE IF NOT EXISTS stage_transitions (
    transition_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    stage_type ENUM('Mediation', 'Conciliation') NOT NULL,
    from_status VARCHAR(50) NOT NULL,
    to_status VARCHAR(50) NOT NULL,
    action_type VARCHAR(100) NOT NULL,
    performed_by INT UNSIGNED NOT NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_st_case (case_id),
    CONSTRAINT fk_st_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_st_user FOREIGN KEY (performed_by) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 3. Expand hearing_minutes table with status, agenda, action items, unresolved issues, and finalization metadata
ALTER TABLE hearing_minutes
    ADD COLUMN status ENUM('Draft', 'Finalized') NOT NULL DEFAULT 'Draft' AFTER session_outcome,
    ADD COLUMN agenda_topics TEXT NULL AFTER status,
    ADD COLUMN agreements_action_items TEXT NULL AFTER agenda_topics,
    ADD COLUMN unresolved_issues TEXT NULL AFTER agreements_action_items,
    ADD COLUMN finalized_by INT UNSIGNED NULL AFTER unresolved_issues,
    ADD COLUMN finalized_at DATETIME NULL AFTER finalized_by,
    ADD CONSTRAINT fk_hm_finalized_by FOREIGN KEY (finalized_by) REFERENCES users (user_id) ON DELETE SET NULL;

-- 4. Expand hearing_attendance with Pending Verification status and verification metadata
ALTER TABLE hearing_attendance
    MODIFY COLUMN attendance_status ENUM('Present', 'Absent', 'Late', 'Excused', 'Not Served', 'Pending Verification') NOT NULL DEFAULT 'Pending Verification',
    ADD COLUMN verification_status ENUM('Pending', 'Verified Served', 'Unverified', 'Excused Approved') NOT NULL DEFAULT 'Pending' AFTER remarks,
    ADD COLUMN determination_notes TEXT NULL AFTER verification_status,
    ADD COLUMN attachment_path VARCHAR(1024) NULL AFTER determination_notes;
