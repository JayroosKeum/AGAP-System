CREATE DATABASE IF NOT EXISTS remastered_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE remastered_db;

CREATE TABLE IF NOT EXISTS profiles (
    profile_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(20) NULL,
    contact_number VARCHAR(30) NULL,
    email VARCHAR(190) NULL,
    address_text VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (profile_id),
    KEY idx_profiles_name (last_name, first_name),
    KEY idx_profiles_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cases (
    case_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_number VARCHAR(80) NOT NULL,
    complaint_id BIGINT UNSIGNED NULL,
    complaint_title VARCHAR(255) NULL,
    case_type VARCHAR(80) NULL,
    case_status VARCHAR(50) NOT NULL DEFAULT 'Docketed',
    filing_date DATE NULL,
    mediation_start_date DATE NULL,
    mediation_deadline_date DATE NULL,
    is_paused TINYINT(1) NOT NULL DEFAULT 0,
    paused_at DATETIME NULL,
    resumed_at DATETIME NULL,
    pause_reason VARCHAR(255) NULL,
    pause_notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (case_id),
    UNIQUE KEY uq_cases_case_number (case_number),
    KEY idx_cases_status (case_status),
    KEY idx_cases_complaint (complaint_id),
    KEY idx_cases_filing_date (filing_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cases
SET case_number = CONCAT('CASE-', DATE_FORMAT(COALESCE(created_at, NOW()), '%Y'), '-', LPAD(case_id, 6, '0'))
WHERE case_number IS NULL OR TRIM(case_number) = '';

ALTER TABLE cases
    MODIFY case_number VARCHAR(80) NOT NULL;

CREATE TABLE IF NOT EXISTS case_parties (
    case_party_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    party_id BIGINT UNSIGNED NOT NULL,
    party_type VARCHAR(30) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (case_party_id),
    UNIQUE KEY uq_case_parties_role (case_id, party_id, party_type),
    KEY idx_case_parties_case_type (case_id, party_type),
    KEY idx_case_parties_party (party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hearings (
    hearing_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    hearing_type VARCHAR(30) NOT NULL,
    hearing_date DATETIME NOT NULL,
    end_time TIME NULL,
    duration_minutes INT UNSIGNED NULL,
    venue VARCHAR(255) NULL,
    presiding_official_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
    remarks TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (hearing_id),
    KEY idx_hearings_case_type_date (case_id, hearing_type, hearing_date),
    KEY idx_hearings_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_templates (
    template_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_name VARCHAR(150) NOT NULL,
    form_code VARCHAR(30) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id),
    UNIQUE KEY uq_document_templates_name (template_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO document_templates (template_name, form_code) VALUES
('KP Form 8', 'KP_FORM_8'),
('KP Form 9', 'KP_FORM_9'),
('KP Form 10', 'KP_FORM_10'),
('KP Form 11', 'KP_FORM_11'),
('KP Form 12', 'KP_FORM_12'),
('KP Form 14', 'KP_FORM_14'),
('KP Form 16', 'KP_FORM_16'),
('KP Form 20', 'KP_FORM_20'),
('KP Form 21', 'KP_FORM_21'),
('KP Form 22', 'KP_FORM_22');

CREATE TABLE IF NOT EXISTS generated_documents (
    document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(1000) NULL,
    document_status VARCHAR(30) NOT NULL DEFAULT 'Generated',
    generated_by BIGINT UNSIGNED NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id),
    KEY idx_generated_documents_case (case_id, generated_at),
    KEY idx_generated_documents_template (template_id),
    KEY idx_generated_documents_status (document_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS summon_deliveries (
    delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    hearing_id BIGINT UNSIGNED NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    party_id BIGINT UNSIGNED NULL,
    party_type VARCHAR(30) NOT NULL,
    attempt_number INT UNSIGNED NOT NULL DEFAULT 1,
    delivery_status VARCHAR(40) NOT NULL DEFAULT 'Pending',
    delivery_method VARCHAR(60) NULL,
    recipient_name VARCHAR(255) NULL,
    recipient_relationship VARCHAR(100) NULL,
    service_address VARCHAR(500) NULL,
    served_by_user_id BIGINT UNSIGNED NULL,
    attempted_at DATETIME NULL,
    served_at DATETIME NULL,
    unserved_reason VARCHAR(1000) NULL,
    remarks TEXT NULL,
    proof_attachment_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (delivery_id),
    KEY idx_summon_deliveries_case (case_id),
    KEY idx_summon_deliveries_party (party_id, party_type),
    KEY idx_summon_deliveries_document (document_id),
    KEY idx_summon_deliveries_hearing (hearing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pangkat_groups (
    pangkat_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    formation_date DATE NOT NULL,
    convening_deadline DATE NULL,
    selection_method VARCHAR(80) NULL,
    selection_notes TEXT NULL,
    quorum_size TINYINT UNSIGNED NOT NULL DEFAULT 3,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (pangkat_id),
    UNIQUE KEY uq_pangkat_groups_case (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pangkat_members (
    pangkat_member_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pangkat_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    position VARCHAR(30) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Active',
    selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pangkat_member_id),
    UNIQUE KEY uq_pangkat_member_position (pangkat_id, position),
    UNIQUE KEY uq_pangkat_member_user (pangkat_id, member_id),
    KEY idx_pangkat_members_status (pangkat_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS case_deadlines (
    deadline_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    deadline_type VARCHAR(80) NOT NULL,
    start_date DATE NULL,
    due_date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (deadline_id),
    UNIQUE KEY uq_case_deadline_type (case_id, deadline_type),
    KEY idx_case_deadlines_due (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'CORE_CASE_WORKFLOW_READY' AS result;
