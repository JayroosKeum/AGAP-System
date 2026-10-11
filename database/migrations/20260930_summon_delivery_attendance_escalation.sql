-- Migration: Summon Delivery, Attendance Tracking, and Non-Appearance Escalation Workflow (KP Form 18 & KP Form 19)
-- Implements KP rules for summon service matrix, show-cause escalation, and barring/default state transitions.

-- 1. Extend case_status enum in cases table
ALTER TABLE cases
    MODIFY COLUMN case_status ENUM('Docketed','Mediation','Conciliation','Arbitration','Settled','Dismissed','CFA Issued','Archived','DISMISSED_BARRED','RESPONDENT_DEFAULT') NOT NULL DEFAULT 'Docketed';

-- 2. Extend status enum in complaints table
ALTER TABLE complaints
    MODIFY COLUMN status ENUM('Filed','Under Review','Needs Information','Accepted','Rejected','Docketed','Mediation','Conciliation','Arbitration','Settled','Dismissed','CFA Issued','Archived','DISMISSED_BARRED','RESPONDENT_DEFAULT') NOT NULL DEFAULT 'Filed';

-- 3. Extend hearings table with status, attendance columns, and show-cause hearing types
ALTER TABLE hearings
    MODIFY COLUMN hearing_type ENUM('Initial Hearing', 'Mediation', 'Conciliation', 'Arbitration', 'Show Cause (Complainant)', 'Show Cause (Respondent)') NOT NULL,
    ADD COLUMN status ENUM('Scheduled', 'Completed', 'Rescheduled', 'Cancelled') NOT NULL DEFAULT 'Scheduled' AFTER venue,
    ADD COLUMN complainant_attendance ENUM('Present', 'Absent', 'Excused') NULL AFTER status,
    ADD COLUMN respondent_attendance ENUM('Present', 'Absent', 'Excused') NULL AFTER complainant_attendance;

-- 4. Create summon_deliveries table to track independent service matrix
CREATE TABLE IF NOT EXISTS summon_deliveries (
    delivery_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NOT NULL,
    recipient_type ENUM('Complainant', 'Respondent') NOT NULL,
    recipient_name VARCHAR(255) NULL,
    delivery_status ENUM('Pending', 'Served Personal', 'Served Substituted', 'Served Refused', 'Unserved') NOT NULL DEFAULT 'Pending',
    relationship VARCHAR(100) NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    server_notes TEXT NULL,
    served_by INT UNSIGNED NULL,
    date_served DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_summon_delivery_hearing_recipient (hearing_id, recipient_type),
    KEY idx_summon_deliveries_hearing (hearing_id),
    KEY idx_summon_deliveries_case (case_id),
    KEY idx_summon_deliveries_status (delivery_status),
    CONSTRAINT fk_summon_deliveries_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_summon_deliveries_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_summon_deliveries_served_by FOREIGN KEY (served_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5. Create show_cause_evaluations table for KP Form 18 & KP Form 19 outcomes
CREATE TABLE IF NOT EXISTS show_cause_evaluations (
    evaluation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NOT NULL,
    party_type ENUM('Complainant', 'Respondent') NOT NULL,
    form_type ENUM('KP_18', 'KP_19') NOT NULL,
    is_justified TINYINT(1) NOT NULL DEFAULT 0,
    justification_reason TEXT NULL,
    resolution_action ENUM('reschedule', 'issue_cfa', 'bar_action') NOT NULL,
    evaluated_by INT UNSIGNED NULL,
    evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_show_cause_evaluations_hearing (hearing_id),
    KEY idx_show_cause_evaluations_case (case_id),
    CONSTRAINT fk_show_cause_evaluations_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_show_cause_evaluations_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_show_cause_evaluations_user FOREIGN KEY (evaluated_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 6. Insert KP Form 18, 19, and verify templates in document_templates
INSERT INTO document_templates (template_name, description) VALUES
    ('KP Form 18', 'Notice of Hearing for Failure to Appear (Complainant)'),
    ('KP Form 19', 'Notice of Hearing for Failure to Appear (Respondent)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
