-- Migration: 20261014_001_pangkat_stage_enhancements.sql
-- Description: Supports RA 7160 statutory requirements for Pangkat Conciliation:
-- 1. 3-day convening deadline tracking from constitution
-- 2. 15-day conciliation period commencing on convening (not formation)
-- 3. Controlled extension records (discretionary up to 15 days, clearly meritorious exception)
-- 4. Modeling party choice, failed agreement leading to PB appointment/lots, member challenges, and replacements

-- Alter pangkat_groups
ALTER TABLE pangkat_groups
    ADD COLUMN convening_deadline DATE NULL AFTER formation_date,
    ADD COLUMN convened_date DATE NULL AFTER convening_deadline,
    ADD COLUMN conciliation_deadline DATE NULL AFTER convened_date,
    ADD COLUMN is_extended TINYINT(1) NOT NULL DEFAULT 0 AFTER conciliation_deadline,
    ADD COLUMN extended_days INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_extended,
    ADD COLUMN extension_type ENUM('None', 'Discretionary', 'Clearly Meritorious') NOT NULL DEFAULT 'None' AFTER extended_days,
    ADD COLUMN selection_stage ENUM('Party Choice', 'Failed Agreement - PB Lots', 'Failed Agreement - PB Appointment', 'Constituted') NOT NULL DEFAULT 'Constituted' AFTER selection_notes,
    ADD COLUMN party_agreement_failed TINYINT(1) NOT NULL DEFAULT 0 AFTER selection_stage,
    ADD COLUMN party_agreement_failure_reason TEXT NULL AFTER party_agreement_failed;

-- Alter pangkat_members
ALTER TABLE pangkat_members
    ADD COLUMN status ENUM('Active', 'Challenged', 'Disqualified', 'Replaced') NOT NULL DEFAULT 'Active' AFTER position,
    ADD COLUMN disqualified_at DATETIME NULL AFTER status,
    ADD COLUMN disqualification_reason TEXT NULL AFTER disqualified_at,
    ADD COLUMN replacement_for_member_id INT UNSIGNED NULL AFTER disqualification_reason;

-- Create pangkat_extensions table
CREATE TABLE IF NOT EXISTS pangkat_extensions (
    extension_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pangkat_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NOT NULL,
    extension_type ENUM('Discretionary', 'Clearly Meritorious') NOT NULL DEFAULT 'Discretionary',
    days_extended INT UNSIGNED NOT NULL DEFAULT 15,
    previous_deadline DATE NOT NULL,
    new_deadline DATE NOT NULL,
    justification TEXT NOT NULL,
    meritorious_basis TEXT NULL,
    approved_by INT UNSIGNED NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pe_pangkat (pangkat_id),
    INDEX idx_pe_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create pangkat_challenges table
CREATE TABLE IF NOT EXISTS pangkat_challenges (
    challenge_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pangkat_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NOT NULL,
    challenged_member_id INT UNSIGNED NOT NULL,
    challenger_party ENUM('Complainant', 'Respondent') NOT NULL,
    challenger_name VARCHAR(255) NULL,
    grounds ENUM('Relationship within 4th degree', 'Financial or Personal Interest', 'Bias or Partiality', 'Other') NOT NULL,
    ground_details TEXT NOT NULL,
    challenge_date DATE NOT NULL,
    status ENUM('Pending', 'Sustained', 'Dismissed') NOT NULL DEFAULT 'Pending',
    resolution_date DATE NULL,
    majority_decision ENUM('Pending', 'Disqualify and Replace', 'Dismiss Challenge') NOT NULL DEFAULT 'Pending',
    majority_decision_notes TEXT NULL,
    voting_record TEXT NULL,
    replacement_member_id INT UNSIGNED NULL,
    replacement_method ENUM('Party Agreement', 'Drawing of Lots', 'PB Appointment') NULL,
    replacement_date DATE NULL,
    resolved_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pc_pangkat (pangkat_id),
    INDEX idx_pc_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
