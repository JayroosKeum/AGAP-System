-- Migration: Scheduling, Office Logistics, Mediation Process, Conciliation, and Settlement Logistics
-- File: database/migrations/20261006_scheduling_mediation_settlement_logistics.sql

-- 1. System Settings for configurable capacities and durations
CREATE TABLE IF NOT EXISTS system_settings (
    setting_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(64) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('daily_hearing_capacity', '14', 'Maximum total hearings allowed per day across the barangay (10-18)'),
    ('officer_daily_hearing_capacity', '6', 'Maximum hearings per presiding officer per day (5-8)'),
    ('mediation_default_duration_min', '45', 'Default duration for mediation session in minutes (30-60)'),
    ('mediation_max_duration_min', '60', 'Maximum duration before logged justification is required for mediation'),
    ('conciliation_default_duration_min', '60', 'Default duration for conciliation session in minutes (45-90)'),
    ('conciliation_max_duration_min', '90', 'Maximum duration before logged justification is required for conciliation'),
    ('mediation_session_interval_days', '3', 'Recommended minimum calendar days between mediation sessions (3-5)'),
    ('party_reschedule_limit', '2', 'Maximum allowed reschedules per party before authorized review is required (1-2)'),
    ('summons_max_attempts', '3', 'Maximum service attempts before escalation (2-3)'),
    ('summons_attempt_interval_days', '1', 'Minimum days between summons service attempts (1-3)')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 2. Modify hearings table with end times, duration, presider, cancellation, and reschedule enhancements
ALTER TABLE hearings
    ADD COLUMN end_time DATETIME NULL AFTER hearing_date,
    ADD COLUMN duration_minutes INT UNSIGNED NULL DEFAULT 45 AFTER end_time,
    ADD COLUMN actual_end_time DATETIME NULL AFTER duration_minutes,
    ADD COLUMN duration_exceed_reason TEXT NULL AFTER actual_end_time,
    ADD COLUMN presiding_officer_id INT UNSIGNED NULL AFTER remarks,
    ADD COLUMN substitute_presider_id INT UNSIGNED NULL AFTER presiding_officer_id,
    ADD COLUMN substitute_reason TEXT NULL AFTER substitute_presider_id,
    ADD COLUMN parties_consent_to_substitute TINYINT(1) NOT NULL DEFAULT 0 AFTER substitute_reason,
    ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER status,
    ADD COLUMN cancellation_reason TEXT NULL AFTER cancelled_by,
    ADD COLUMN cancellation_notice_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER cancellation_reason,
    ADD COLUMN rescheduled_by_party ENUM('Complainant', 'Respondent', 'Office', 'Both') NULL AFTER reschedule_reason,
    ADD COLUMN reschedule_justification_category VARCHAR(100) NULL AFTER rescheduled_by_party,
    ADD COLUMN reschedule_document_path VARCHAR(1024) NULL AFTER reschedule_justification_category,
    ADD COLUMN reschedule_approved_by INT UNSIGNED NULL AFTER reschedule_document_path,
    ADD COLUMN reschedule_approved_at DATETIME NULL AFTER reschedule_approved_by;

ALTER TABLE hearings MODIFY COLUMN status ENUM('Scheduled', 'Completed', 'Rescheduled', 'Cancelled', 'Office Cancelled', 'Attendance Recorded') NOT NULL DEFAULT 'Scheduled';

-- Foreign key constraints for hearing actors
ALTER TABLE hearings
    ADD CONSTRAINT fk_hearings_presiding FOREIGN KEY (presiding_officer_id) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_hearings_substitute FOREIGN KEY (substitute_presider_id) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_hearings_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_hearings_reschedule_approved_by FOREIGN KEY (reschedule_approved_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- 3. Hearing Minutes & Session Intake Table
CREATE TABLE IF NOT EXISTS hearing_minutes (
    minute_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL UNIQUE,
    case_id INT UNSIGNED NOT NULL,
    session_type ENUM('1st Mediation', '2nd Mediation', '3rd Mediation', 'Conciliation', 'Arbitration', 'Show Cause') NOT NULL,
    opening_conducted TINYINT(1) NOT NULL DEFAULT 0,
    identity_verified TINYINT(1) NOT NULL DEFAULT 0,
    complaint_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    complainant_statement TEXT NULL,
    respondent_statement TEXT NULL,
    main_dispute_identified TEXT NULL,
    settlement_discussion_notes TEXT NULL,
    caucus_conducted TINYINT(1) NOT NULL DEFAULT 0,
    caucus_notes TEXT NULL,
    previous_proposal TEXT NULL,
    new_proposal TEXT NULL,
    counteroffer TEXT NULL,
    additional_evidence_notes TEXT NULL,
    session_outcome ENUM('Settled', 'Continue Mediation', 'Failed', 'Party Absent', 'Rescheduled', 'Elevate to Pangkat', 'Pending') NOT NULL DEFAULT 'Pending',
    outcome_remarks TEXT NULL,
    actual_end_time DATETIME NULL,
    recorded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hm_case (case_id),
    CONSTRAINT fk_hm_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_hm_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_hm_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 4. Pangkat selection method and quorum enhancements
ALTER TABLE pangkat_groups
    ADD COLUMN selection_method ENUM('Party Agreement', 'PB Assignment', 'Raffle Draw') NOT NULL DEFAULT 'Party Agreement' AFTER formation_date,
    ADD COLUMN selection_notes TEXT NULL AFTER selection_method,
    ADD COLUMN quorum_size TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER selection_notes,
    ADD COLUMN quorum_exception_reason TEXT NULL AFTER quorum_size;

-- 5. Hearing party service attempts
ALTER TABLE hearing_party_services
    ADD COLUMN attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER resident_id;

-- 6. Settlements table expansion
ALTER TABLE settlements
    ADD COLUMN terms_read_to_parties TINYINT(1) NOT NULL DEFAULT 0 AFTER agreement_details,
    ADD COLUMN complainant_signed TINYINT(1) NOT NULL DEFAULT 0 AFTER terms_read_to_parties,
    ADD COLUMN respondent_signed TINYINT(1) NOT NULL DEFAULT 0 AFTER complainant_signed,
    ADD COLUMN pb_attested TINYINT(1) NOT NULL DEFAULT 0 AFTER respondent_signed,
    ADD COLUMN pb_attested_by INT UNSIGNED NULL AFTER pb_attested,
    ADD COLUMN pb_attested_at DATETIME NULL AFTER pb_attested_by,
    ADD COLUMN barangay_sealed TINYINT(1) NOT NULL DEFAULT 0 AFTER pb_attested_at,
    ADD COLUMN original_in_case_folder TINYINT(1) NOT NULL DEFAULT 0 AFTER barangay_sealed,
    ADD COLUMN certified_copies_issued TINYINT(1) NOT NULL DEFAULT 0 AFTER original_in_case_folder,
    ADD COLUMN total_amount DECIMAL(12,2) NULL DEFAULT 0.00 AFTER certified_copies_issued,
    ADD COLUMN responsible_party ENUM('Respondent', 'Complainant', 'Both') NOT NULL DEFAULT 'Respondent' AFTER total_amount,
    ADD COLUMN has_installment TINYINT(1) NOT NULL DEFAULT 0 AFTER responsible_party,
    ADD COLUMN compliance_due_date DATE NULL AFTER has_installment,
    ADD COLUMN compliance_date DATE NULL AFTER compliance_due_date,
    ADD COLUMN repudiation_deadline DATE NULL AFTER compliance_date,
    ADD COLUMN repudiation_status ENUM('Within Repudiation Period', 'Repudiation Expired', 'Repudiated', 'Enforceable') NOT NULL DEFAULT 'Within Repudiation Period' AFTER repudiation_deadline,
    ADD COLUMN repudiated_by INT UNSIGNED NULL AFTER repudiation_status,
    ADD COLUMN repudiation_reason TEXT NULL AFTER repudiated_by,
    ADD COLUMN repudiation_date DATETIME NULL AFTER repudiation_reason,
    ADD COLUMN supporting_document_path VARCHAR(1024) NULL AFTER repudiation_date;

ALTER TABLE settlements MODIFY COLUMN compliance_status ENUM('Pending', 'Partially Paid', 'Fully Paid', 'Overdue', 'Breached', 'Complied', 'Violated') NOT NULL DEFAULT 'Pending';

-- 7. Settlement Installments Table
CREATE TABLE IF NOT EXISTS settlement_installments (
    installment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_id INT UNSIGNED NOT NULL,
    installment_number INT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount_due DECIMAL(12,2) NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_date DATETIME NULL,
    payment_status ENUM('Pending', 'Partially Paid', 'Paid', 'Overdue') NOT NULL DEFAULT 'Pending',
    receipt_number VARCHAR(100) NULL,
    receipt_path VARCHAR(1024) NULL,
    notes TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_si_settlement (settlement_id),
    KEY idx_si_due_date (due_date),
    CONSTRAINT fk_si_settlement FOREIGN KEY (settlement_id) REFERENCES settlements (settlement_id) ON DELETE CASCADE,
    CONSTRAINT fk_si_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 8. Settlement Executions Table
CREATE TABLE IF NOT EXISTS settlement_executions (
    execution_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NOT NULL,
    obligation_violated TEXT NOT NULL,
    due_date DATE NOT NULL,
    amount_or_requirement TEXT NOT NULL,
    evidence_notes TEXT NULL,
    evidence_file_path VARCHAR(1024) NULL,
    motion_date DATE NOT NULL,
    motion_filed_by INT UNSIGNED NOT NULL,
    notice_of_execution_date DATE NULL,
    execution_status ENUM('Motion Filed', 'Notice Issued', 'Execution In Progress', 'Complied Under Execution', 'Execution Failed', 'Endorsed to Court') NOT NULL DEFAULT 'Motion Filed',
    action_taken TEXT NULL,
    officer_assigned INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_se_settlement (settlement_id),
    KEY idx_se_case (case_id),
    CONSTRAINT fk_se_settlement FOREIGN KEY (settlement_id) REFERENCES settlements (settlement_id) ON DELETE CASCADE,
    CONSTRAINT fk_se_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_se_resident FOREIGN KEY (motion_filed_by) REFERENCES residents (resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_se_officer FOREIGN KEY (officer_assigned) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 9. Insert KP Form 10 in document_templates
INSERT INTO document_templates (template_name, description) VALUES
    ('KP Form 10', 'Notice to Constitute the Pangkat Tagapagkasundo')
ON DUPLICATE KEY UPDATE description = VALUES(description);
