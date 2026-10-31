-- AGAP database schema (MySQL 8.0+ / MariaDB 10.5+)
-- This file provisions a new database. It intentionally does not drop existing tables.

CREATE DATABASE IF NOT EXISTS agap_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE agap_db;

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- ACCESS CONTROL
-- =====================================================

CREATE TABLE roles (
    role_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_role_name (role_name)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    permission_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permissions_permission_name (permission_name)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles (role_id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions (permission_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(150) NULL,
    contact_no VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    notification_audio TINYINT(1) NOT NULL DEFAULT 1,
    notification_inbox TINYINT(1) NOT NULL DEFAULT 1,
    preferred_language VARCHAR(10) NOT NULL DEFAULT 'fil',
    avatar_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_id (role_id),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (role_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
    reset_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_reset_tokens_hash (token_hash),
    KEY idx_password_reset_tokens_user_expiry (user_id, expires_at),
    CONSTRAINT fk_password_reset_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_logs (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    logout_time DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    KEY idx_login_logs_user_id_login_time (user_id, login_time),
    CONSTRAINT fk_login_logs_user
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE audit_trails (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(255) NOT NULL,
    module_name VARCHAR(100) NOT NULL,
    affected_record INT UNSIGNED NULL,
    audit_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_trails_user_id_audit_date (user_id, audit_date),
    KEY idx_audit_trails_module_record (module_name, affected_record),
    CONSTRAINT fk_audit_trails_user
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    setting_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(64) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================
-- PEOPLE AND COMPLAINT INTAKE
-- =====================================================

-- Resident profiles / directory (stores first name, middle name, last name, and address to avoid misreporting persons in complaints; not user login accounts)
CREATE TABLE residents (
    resident_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(20) NULL,
    birth_date DATE NULL,
    gender VARCHAR(50) NULL,
    civil_status VARCHAR(50) NULL,
    occupation VARCHAR(100) NULL,
    contact_no VARCHAR(20) NULL,
    email VARCHAR(150) NULL,
    preferred_contact ENUM('SMS', 'Call', 'Email', 'In-Person') NULL DEFAULT 'SMS',
    address TEXT NULL,
    house_street VARCHAR(255) NULL,
    barangay VARCHAR(100) NOT NULL DEFAULT 'Tumana',
    city_municipality VARCHAR(100) NOT NULL DEFAULT 'Marikina City',
    home_latitude DECIMAL(10, 8) NULL,
    home_longitude DECIMAL(11, 8) NULL,
    purok VARCHAR(100) NULL,
    is_tenant TINYINT(1) NOT NULL DEFAULT 0, -- 0 = Permanent Resident, 1 = Tenant / Renter, 2 = Non-Resident
    residency_verified TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_residents_name (last_name, first_name),
    KEY idx_residents_jurisdiction (barangay, is_tenant)
) ENGINE=InnoDB;

CREATE TABLE complaint_categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(255) NOT NULL,
    tagalog_name VARCHAR(150) NULL,
    ltia_code VARCHAR(50) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaint_categories_name (category_name)
) ENGINE=InnoDB;

CREATE TABLE complaints (
    complaint_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Assigned immediately after insert by the model so database-generated IDs remain race-safe.
    complaint_number VARCHAR(50) NULL,
    category_id INT UNSIGNED NOT NULL,
    other_category_specification VARCHAR(255) NULL,
    case_type ENUM('Civil', 'Criminal') NOT NULL DEFAULT 'Civil',
    complaint_title VARCHAR(255) NOT NULL,
    incident_date DATE NULL,
    incident_time TIME NULL,
    is_time_approximate TINYINT(1) NOT NULL DEFAULT 0,
    is_time_unknown TINYINT(1) NOT NULL DEFAULT 0,
    incident_location VARCHAR(255) NULL,
    incident_city VARCHAR(100) NOT NULL DEFAULT 'Marikina City',
    incident_barangay VARCHAR(100) NOT NULL DEFAULT 'Tumana',
    incident_street VARCHAR(255) NULL,
    incident_purok VARCHAR(100) NULL,
    incident_landmark VARCHAR(255) NULL,
    incident_location_details TEXT NULL,
    narrative LONGTEXT NOT NULL,
    additional_details LONGTEXT NULL,
    requested_resolution TEXT NULL,
    review_notes TEXT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'Filed',
    document_status ENUM('No Scan Uploaded', 'Scan Uploaded', 'Updated Scan Uploaded') NOT NULL DEFAULT 'No Scan Uploaded',
    is_draft TINYINT(1) NOT NULL DEFAULT 0,
    paanyaya_attempt_count TINYINT UNSIGNED DEFAULT 1,
    max_paanyaya_reached TINYINT(1) DEFAULT 0,
    resolved_without_docketing TINYINT(1) DEFAULT 0,
    paanyaya_1_outcome VARCHAR(50) NULL DEFAULT NULL,
    paanyaya_complainant_choice VARCHAR(50) NULL DEFAULT NULL,
    ltia_category VARCHAR(100) NULL,
    encoded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaints_number (complaint_number),
    KEY idx_complaints_category_id (category_id),
    KEY idx_complaints_status_created_at (status, created_at),
    CONSTRAINT fk_complaints_category
        FOREIGN KEY (category_id) REFERENCES complaint_categories (category_id) ON DELETE RESTRICT,
    CONSTRAINT fk_complaints_encoded_by
        FOREIGN KEY (encoded_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE complaint_parties (
    party_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    party_type ENUM('Complainant', 'Respondent', 'Witness') NOT NULL,
    is_minor TINYINT(1) NOT NULL DEFAULT 0,
    guardian_name VARCHAR(255) NULL,
    guardian_relationship VARCHAR(100) NULL,
    guardian_contact VARCHAR(50) NULL,
    guardian_address TEXT NULL,
    home_latitude DECIMAL(10, 8) NULL,
    home_longitude DECIMAL(11, 8) NULL,
    preferred_contact ENUM('SMS', 'Call', 'Email', 'In-Person') NULL DEFAULT 'SMS',
    suffix VARCHAR(20) NULL,
    occupation VARCHAR(100) NULL,
    age INT NULL,
    birth_date DATE NULL,
    gender VARCHAR(50) NULL,
    civil_status VARCHAR(50) NULL,
    contact_unknown TINYINT(1) NOT NULL DEFAULT 0,
    middle_name_unknown TINYINT(1) NOT NULL DEFAULT 0,
    birthdate_unknown TINYINT(1) NOT NULL DEFAULT 0,
    address_requires_verification TINYINT(1) NOT NULL DEFAULT 0,
    residency_verified TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaint_parties_role (complaint_id, resident_id, party_type),
    KEY idx_complaint_parties_resident_id (resident_id),
    CONSTRAINT fk_complaint_parties_complaint
        FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_complaint_parties_resident
        FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Preliminary intake eligibility assessment. It records screening facts and is
-- not a substitute for the authorized legal review of a complaint.
CREATE TABLE complaint_intake_screenings (
    screening_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    party_is_government TINYINT(1) NOT NULL DEFAULT 0,
    party_is_public_officer TINYINT(1) NOT NULL DEFAULT 0,
    parties_in_diff_lgu TINYINT(1) NOT NULL DEFAULT 0,
    property_in_diff_lgu TINYINT(1) NOT NULL DEFAULT 0,
    accused_under_detention TINYINT(1) NOT NULL DEFAULT 0,
    habeas_corpus_needed TINYINT(1) NOT NULL DEFAULT 0,
    provisional_remedy_needed TINYINT(1) NOT NULL DEFAULT 0,
    prescription_risk TINYINT(1) NOT NULL DEFAULT 0,
    indigenous_community TINYINT(1) NOT NULL DEFAULT 0,
    offense_imprisonment_max DECIMAL(6,2) NOT NULL DEFAULT 0,
    offense_fine_max DECIMAL(12,2) NOT NULL DEFAULT 0,
    has_private_offended_party TINYINT(1) NOT NULL DEFAULT 1,
    duplicate_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    screening_notes TEXT NULL,
    screened_by INT UNSIGNED NULL,
    screened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaint_intake_screening (complaint_id),
    CONSTRAINT fk_cis_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_cis_user FOREIGN KEY (screened_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE paanyaya_sessions (
    session_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    scheduled_date DATE NOT NULL,
    scheduled_time TIME NOT NULL,
    venue VARCHAR(255) NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
    has_e_signature TINYINT(1) NOT NULL DEFAULT 1,
    pdf_path VARCHAR(255) NULL,
    salaysay TEXT NULL,
    complainant_attended ENUM('Present', 'Late', 'Absent') DEFAULT 'Absent',
    respondent_attended ENUM('Present', 'Late', 'Absent') DEFAULT 'Absent',
    session_outcome ENUM('Settled', 'Absent_Retry', 'Continued_Dialogue', 'Impasse_Escalate', 'Max_Failed_Escalate', 'Dismissed') NULL,
    outcome_notes TEXT NULL,
    scheduled_by INT UNSIGNED NULL,
    presiding_officer_ids JSON NULL,
    resolved_at DATETIME NULL,
    escalated_at DATETIME NULL,
    actual_start_time DATETIME NULL,
    actual_end_time DATETIME NULL,
    queue_status ENUM('Waiting', 'Ready', 'In_Session', 'Standby', 'Concluded') NOT NULL DEFAULT 'Waiting',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_paanyaya_sessions_complaint (complaint_id),
    KEY idx_paanyaya_sessions_attempt (attempt_number),
    CONSTRAINT fk_paanyaya_sessions_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_paanyaya_sessions_user FOREIGN KEY (scheduled_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- PAANYAYA ENTITY (Independent Workflow)
-- =====================================================
-- Independent entity containing only Invitee Name, Scheduled Date, Scheduled Time, and status.
-- References Mediation/Complaint record without duplicating complaint details.
CREATE TABLE IF NOT EXISTS paanyayas (
    paanyaya_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NULL,
    invitee_name VARCHAR(255) NOT NULL,
    scheduled_date DATE NOT NULL,
    scheduled_time TIME NOT NULL,
    status ENUM('Scheduled', 'Completed', 'Cancelled', 'Resolved', 'Unresolved') NOT NULL DEFAULT 'Scheduled',
    venue VARCHAR(255) NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
    pdf_path VARCHAR(255) NULL,
    scheduled_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_paanyayas_complaint (complaint_id),
    KEY idx_paanyayas_status (status),
    KEY idx_paanyayas_date (scheduled_date),
    CONSTRAINT fk_paanyayas_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE SET NULL,
    CONSTRAINT fk_paanyayas_scheduled_by FOREIGN KEY (scheduled_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legacy compatibility record used alongside the newer paanyayas flow.
CREATE TABLE complaint_paanyaya (
    paanyaya_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    invitee_name VARCHAR(255) NULL,
    invitation_date DATE NOT NULL,
    invitation_time TIME NOT NULL,
    venue VARCHAR(255) NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
    hearing_day VARCHAR(50) NULL,
    status ENUM('Scheduled', 'Resolved', 'Failed', 'Escalated') NOT NULL DEFAULT 'Scheduled',
    resolution_notes TEXT NULL,
    pdf_path VARCHAR(255) NULL,
    has_e_signature TINYINT(1) NOT NULL DEFAULT 1,
    scheduled_by INT UNSIGNED NULL,
    presiding_officer_ids JSON NULL,
    resolved_at DATETIME NULL,
    escalated_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_paanyaya_complaint (complaint_id),
    KEY idx_paanyaya_status (status),
    CONSTRAINT fk_paanyaya_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS paanyaya_stages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    stage_type ENUM('Paanyaya 1', 'Paanyaya 2') NOT NULL,
    invitee_name VARCHAR(255) NULL,
    status ENUM('Not Started', 'In Progress', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Not Started',
    issued_at DATETIME NULL,
    issued_by INT UNSIGNED NULL,
    invitation_date DATE NULL,
    invitation_time TIME NULL,
    venue VARCHAR(255) NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
    has_e_signature TINYINT(1) NOT NULL DEFAULT 1,
    pdf_path VARCHAR(255) NULL,
    review_status ENUM('None', 'Pending Review', 'Approved for Paanyaya 2', 'Approved for Summons', 'Case Closed') NOT NULL DEFAULT 'None',
    review_notes TEXT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaint_stage (complaint_id, stage_type),
    KEY idx_paanyaya_stage_status (status),
    CONSTRAINT fk_paanyaya_stage_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_paanyaya_stage_issued_by FOREIGN KEY (issued_by) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_paanyaya_stage_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS paanyaya_minutes (
    minutes_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    stage_type ENUM('Paanyaya 1', 'Paanyaya 2') NOT NULL,
    dialogue_date DATETIME NOT NULL,
    complainant_attended ENUM('Present', 'Absent') DEFAULT 'Present',
    respondent_attended ENUM('Present', 'Absent') DEFAULT 'Present',
    discussion_summary TEXT NOT NULL,
    agreed_terms TEXT NULL,
    outcome ENUM('Settled', 'No_Agreement', 'Party_Absent', 'Dismissed') NOT NULL,
    complainant_choice ENUM('Request_Paanyaya_2', 'Escalate_Mediation', 'Dismissed', 'None') DEFAULT 'None',
    scanned_minutes_path VARCHAR(255) NULL,
    recorded_by INT UNSIGNED NOT NULL,
    presiding_officer_ids JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pm_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE complaint_scanned_documents (
    doc_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NULL,
    document_type VARCHAR(100) NOT NULL DEFAULT 'KP Form 7 Scanned Complaint',
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(1024) NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    version_number INT UNSIGNED NOT NULL DEFAULT 1,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    replacement_reason TEXT NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_csd_complaint (complaint_id),
    KEY idx_csd_case (case_id),
    CONSTRAINT fk_csd_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_csd_user FOREIGN KEY (uploaded_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE complaint_attachments (
    attachment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(1024) NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_complaint_attachments_complaint_id (complaint_id),
    CONSTRAINT fk_complaint_attachments_complaint
        FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE incident_locations (
    location_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10, 8) NULL,
    longitude DECIMAL(11, 8) NULL,
    address TEXT NULL,
    landmark VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_incident_locations_complaint_id (complaint_id),
    KEY idx_incident_lat_lng (latitude, longitude),
    CONSTRAINT fk_incident_locations_complaint
        FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT chk_incident_locations_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_incident_locations_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB;

-- =====================================================
-- CASE LIFECYCLE
-- =====================================================

CREATE TABLE cases (
    case_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    -- Assigned immediately after insert by the model so database-generated IDs remain race-safe.
    case_number VARCHAR(50) NULL,
    case_type ENUM('Civil', 'Criminal') NOT NULL,
    case_status ENUM('Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT') NOT NULL DEFAULT 'Docketed',
    docket_date DATE NOT NULL,
    archived_date DATE NULL,
    mediation_start_date DATE NULL,
    mediation_deadline_date DATE NULL,
    is_paused TINYINT(1) NOT NULL DEFAULT 0,
    paused_at DATETIME NULL,
    resumed_at DATETIME NULL,
    pause_reason VARCHAR(255) NULL,
    pause_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cases_complaint_id (complaint_id),
    UNIQUE KEY uq_cases_number (case_number),
    KEY idx_cases_status_docket_date (case_status, docket_date),
    KEY idx_cases_mediation_deadline (mediation_deadline_date),
    CONSTRAINT fk_cases_complaint
        FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE case_transmittals (
    transmittal_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    transmittal_number VARCHAR(50) NOT NULL UNIQUE,
    settlement_date DATE NOT NULL,
    ten_day_expiry_date DATE NOT NULL,
    target_agency ENUM('MTC', 'DILG', 'Both') NOT NULL DEFAULT 'Both',
    transmittal_date DATE NULL,
    transmitting_officer_id INT UNSIGNED NULL,
    attesting_officer_id INT UNSIGNED NULL,
    status ENUM('Pending', 'Generated', 'Transmitted', 'Acknowledged') NOT NULL DEFAULT 'Pending',
    mtc_received_date DATE NULL,
    mtc_receiver_name VARCHAR(150) NULL,
    dilg_received_date DATE NULL,
    dilg_receiver_name VARCHAR(150) NULL,
    receiving_copy_path VARCHAR(255) NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_case_transmittals_case FOREIGN KEY (case_id) REFERENCES cases(case_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE case_history (
    history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    status ENUM('Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived') NOT NULL,
    remarks TEXT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_case_history_case_id_updated_at (case_id, updated_at),
    CONSTRAINT fk_case_history_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_case_history_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE case_stages (
    stage_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    stage_type ENUM('Mediation', 'Conciliation', 'Arbitration') NOT NULL,
    stage_status ENUM('Locked', 'Ready for Referral', 'Not Started', 'In Progress', 'Awaiting Outcome', 'Completed', 'Referred to Pangkat') NOT NULL DEFAULT 'Not Started',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    outcome ENUM('Settled', 'Unsuccessful', 'Repudiated', 'Dismissed', 'Referred to Pangkat', 'Arbitration', 'Arbitration Award', 'Pending') NOT NULL DEFAULT 'Pending',
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

CREATE TABLE stage_transitions (
    transition_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    stage_type ENUM('Mediation', 'Conciliation', 'Arbitration') NOT NULL,
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

CREATE TABLE case_deadlines (
    deadline_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    deadline_type VARCHAR(100) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('Pending', 'Completed', 'Overdue') NOT NULL DEFAULT 'Pending',
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_case_deadlines_case_type (case_id, deadline_type),
    KEY idx_case_deadlines_case_id_due_date (case_id, due_date),
    CONSTRAINT fk_case_deadlines_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- LUPON, PANGKAT, AND HEARINGS
-- An active user with the "Lupon Member" role is eligible for assignment.
-- =====================================================

CREATE TABLE case_assignments (
    assignment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    -- References the user account of an active Lupon Member.
    member_id INT UNSIGNED NOT NULL,
    assignment_role ENUM('Mediator', 'Head', 'Secretary', 'Member') NOT NULL,
    assigned_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_case_assignments_role (case_id, member_id, assignment_role),
    UNIQUE KEY uq_case_assignments_case_role (case_id, assignment_role),
    KEY idx_case_assignments_member_id (member_id),
    CONSTRAINT fk_case_assignments_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_case_assignments_member
        FOREIGN KEY (member_id) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE pangkat_groups (
    pangkat_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    formation_date DATE NOT NULL,
    convening_deadline DATE NULL,
    convened_date DATE NULL,
    conciliation_deadline DATE NULL,
    is_extended TINYINT(1) NOT NULL DEFAULT 0,
    extended_days INT UNSIGNED NOT NULL DEFAULT 0,
    extension_type ENUM('None', 'Discretionary', 'Clearly Meritorious') NOT NULL DEFAULT 'None',
    selection_method ENUM('Party Agreement', 'PB Assignment', 'Raffle Draw') NOT NULL DEFAULT 'Party Agreement',
    selection_notes TEXT NULL,
    selection_stage ENUM('Party Choice', 'Failed Agreement - PB Lots', 'Failed Agreement - PB Appointment', 'Constituted') NOT NULL DEFAULT 'Constituted',
    party_agreement_failed TINYINT(1) NOT NULL DEFAULT 0,
    party_agreement_failure_reason TEXT NULL,
    quorum_size TINYINT UNSIGNED NOT NULL DEFAULT 3,
    quorum_exception_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pangkat_groups_case_id (case_id),
    CONSTRAINT fk_pangkat_groups_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pangkat_members (
    pangkat_member_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pangkat_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    position ENUM('Chairman', 'Secretary', 'Member') NOT NULL DEFAULT 'Member',
    status ENUM('Active', 'Challenged', 'Disqualified', 'Replaced') NOT NULL DEFAULT 'Active',
    disqualified_at DATETIME NULL,
    disqualification_reason TEXT NULL,
    replacement_for_member_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pangkat_members_member (pangkat_id, member_id),
    KEY idx_pangkat_members_member_id (member_id),
    CONSTRAINT fk_pangkat_members_group
        FOREIGN KEY (pangkat_id) REFERENCES pangkat_groups (pangkat_id) ON DELETE CASCADE,
    CONSTRAINT fk_pangkat_members_user
        FOREIGN KEY (member_id) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE pangkat_extensions (
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
    KEY idx_pe_pangkat (pangkat_id),
    KEY idx_pe_case (case_id)
) ENGINE=InnoDB;

CREATE TABLE pangkat_challenges (
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
    KEY idx_pc_pangkat (pangkat_id),
    KEY idx_pc_case (case_id)
) ENGINE=InnoDB;

CREATE TABLE hearings (
    hearing_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    hearing_type ENUM('Initial Hearing', 'Mediation', 'Conciliation', 'Arbitration', 'Show Cause (Complainant)', 'Show Cause (Respondent)') NOT NULL,
    mediation_attempt TINYINT UNSIGNED NULL DEFAULT NULL,
    attempt_exception_reason TEXT NULL,
    hearing_date DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_minutes INT UNSIGNED NULL DEFAULT 45,
    actual_end_time DATETIME NULL,
    actual_start_time DATETIME NULL,
    actual_duration_minutes INT UNSIGNED NULL,
    queue_status ENUM('Waiting', 'Ready', 'In_Session', 'Standby', 'Concluded') NOT NULL DEFAULT 'Waiting',
    duration_exceed_reason TEXT NULL,
    venue VARCHAR(255) NOT NULL,
    remarks TEXT NULL,
    kp12_document_id INT UNSIGNED NULL DEFAULT NULL,
    presiding_officer_id INT UNSIGNED NULL,
    substitute_presider_id INT UNSIGNED NULL,
    substitute_reason TEXT NULL,
    parties_consent_to_substitute TINYINT(1) NOT NULL DEFAULT 0,
    rescheduled_from_id INT UNSIGNED NULL,
    reschedule_reason TEXT NULL,
    rescheduled_by_party ENUM('Complainant', 'Respondent', 'Office', 'Both') NULL,
    reschedule_justification_category VARCHAR(100) NULL,
    reschedule_document_path VARCHAR(1024) NULL,
    reschedule_approved_by INT UNSIGNED NULL,
    reschedule_approved_at DATETIME NULL,
    status ENUM('Scheduled', 'Completed', 'Rescheduled', 'Cancelled', 'Office Cancelled', 'Attendance Recorded') NOT NULL DEFAULT 'Scheduled',
    cancelled_by INT UNSIGNED NULL,
    cancellation_reason TEXT NULL,
    cancellation_category VARCHAR(100) NULL,
    cancellation_notice_sent TINYINT(1) NOT NULL DEFAULT 0,
    complainant_attendance ENUM('Present', 'Absent', 'Excused', 'Pending', 'Late') NULL DEFAULT 'Pending',
    respondent_attendance ENUM('Present', 'Absent', 'Excused', 'Pending', 'Late') NULL DEFAULT 'Pending',
    attendance_recorded_at DATETIME NULL,
    attendance_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hearings_case_id_date (case_id, hearing_date),
    KEY idx_hearings_presiding (presiding_officer_id),
    KEY idx_hearings_kp12_document (kp12_document_id),
    CONSTRAINT fk_hearings_kp12_document FOREIGN KEY (kp12_document_id) REFERENCES generated_documents (document_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearings_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_hearings_rescheduled_from FOREIGN KEY (rescheduled_from_id) REFERENCES hearings (hearing_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hearings_presiding FOREIGN KEY (presiding_officer_id) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearings_substitute FOREIGN KEY (substitute_presider_id) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearings_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearings_reschedule_approved_by FOREIGN KEY (reschedule_approved_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE mediation_commencements (
    commencement_id INT NOT NULL AUTO_INCREMENT,
    case_id INT NOT NULL,
    hearing_id INT NOT NULL,
    first_actual_meeting_at DATETIME NOT NULL,
    mediation_deadline_at DATETIME NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'Attendance',
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (commencement_id),
    UNIQUE KEY uq_mediation_commencement_case (case_id),
    UNIQUE KEY uq_mediation_commencement_hearing (hearing_id),
    KEY idx_mediation_commencement_deadline (mediation_deadline_at),
    KEY idx_mediation_commencement_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hearing_minutes (
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
    session_outcome ENUM('Settled', 'Continue Mediation', 'Continue Conciliation', 'Continue Arbitration', 'Failed', 'Party Absent', 'Rescheduled', 'Elevate to Pangkat', 'Pending CFA', 'Arbitration', 'Arbitration Award', 'Repudiated', 'Dismissed', 'Pending') NOT NULL DEFAULT 'Pending',
    status ENUM('Draft', 'Finalized') NOT NULL DEFAULT 'Draft',
    agenda_topics TEXT NULL,
    agreements_action_items TEXT NULL,
    unresolved_issues TEXT NULL,
    finalized_by INT UNSIGNED NULL,
    finalized_at DATETIME NULL,
    outcome_remarks TEXT NULL,
    actual_end_time DATETIME NULL,
    recorded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hm_case (case_id),
    CONSTRAINT fk_hm_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_hm_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_hm_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hm_finalized_by FOREIGN KEY (finalized_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hearing_minutes_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NOT NULL,
    document_type ENUM('handwritten_minutes', 'evidence_attachment') NOT NULL DEFAULT 'evidence_attachment',
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT UNSIGNED DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (hearing_id),
    INDEX (case_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE summon_deliveries (
    delivery_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NULL,
    case_id INT UNSIGNED NULL,
    party_type VARCHAR(50) NOT NULL DEFAULT 'Respondent',
    resident_id INT UNSIGNED NULL,
    party_id INT NULL,
    form_type VARCHAR(100) NOT NULL DEFAULT 'Notice of Hearing',
    attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    delivery_status VARCHAR(50) NOT NULL DEFAULT 'Pending',
    service_method VARCHAR(50) NULL,
    attempted_at DATETIME NULL,
    served_at DATETIME NULL,
    served_by INT UNSIGNED NULL,
    served_by_user_id INT NULL,
    recipient_name VARCHAR(150) NULL,
    relationship VARCHAR(100) NULL,
    unserved_reason VARCHAR(100) NULL,
    failure_notes TEXT NULL,
    remarks TEXT NULL,
    service_address TEXT NULL,
    proof_file_path VARCHAR(500) NULL,
    document_id INT UNSIGNED NULL DEFAULT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hearing_party_form_attempt (hearing_id, party_type, resident_id, form_type, attempt_number),
    KEY idx_summon_deliveries_hearing_status (hearing_id, delivery_status),
    KEY idx_summon_deliveries_served_by (served_by),
    KEY idx_summon_deliveries_case (case_id),
    KEY idx_summon_deliveries_document (document_id),
    KEY idx_summon_deliveries_case_attempt (case_id, attempt_number),
    CONSTRAINT fk_summon_deliveries_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_summon_deliveries_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE SET NULL,
    CONSTRAINT fk_summon_deliveries_served_by FOREIGN KEY (served_by) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_summon_deliveries_document FOREIGN KEY (document_id) REFERENCES generated_documents (document_id) ON DELETE SET NULL,
    CONSTRAINT fk_summon_deliveries_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE show_cause_evaluations (
    evaluation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NOT NULL,
    party_type ENUM('Complainant', 'Respondent') NOT NULL,
    form_type ENUM('KP Form 18', 'KP Form 19') NOT NULL,
    is_justified TINYINT(1) NOT NULL,
    justification_category ENUM('Medical Emergency', 'Force Majeure', 'Official Duty', 'Unjustified Absence', 'Willful Refusal', 'Other') NOT NULL,
    justification_notes TEXT NULL,
    rescheduled_hearing_id INT UNSIGNED NULL,
    action_taken ENUM('Rescheduled', 'Barred Action', 'Respondent Default', 'CFA Issued') NOT NULL,
    evaluated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_show_cause_hearing (hearing_id),
    KEY idx_show_cause_case (case_id),
    CONSTRAINT fk_show_cause_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_show_cause_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_show_cause_rescheduled FOREIGN KEY (rescheduled_hearing_id) REFERENCES hearings (hearing_id) ON DELETE SET NULL,
    CONSTRAINT fk_show_cause_evaluated_by FOREIGN KEY (evaluated_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE hearing_attendance (
    attendance_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    attendance_status ENUM('Present', 'Absent', 'Late', 'Excused', 'Not Served', 'Pending Verification') NOT NULL DEFAULT 'Pending Verification',
    is_justified TINYINT(1) NOT NULL DEFAULT 0,
    justification_reason VARCHAR(255) NULL,
    remarks TEXT NULL,
    verification_status ENUM('Pending', 'Verified Served', 'Unverified', 'Excused Approved') NOT NULL DEFAULT 'Pending',
    determination_notes TEXT NULL,
    attachment_path VARCHAR(1024) NULL,
    recorded_by INT UNSIGNED NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hearing_attendance_resident (hearing_id, resident_id),
    KEY idx_hearing_attendance_resident_id (resident_id),
    KEY idx_hearing_attendance_recorded_by (recorded_by),
    CONSTRAINT fk_hearing_attendance_hearing
        FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_hearing_attendance_resident
        FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hearing_attendance_recorded_by
        FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;


CREATE TABLE hearing_nonappearances (
    nonappearance_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    party_type ENUM('Complainant', 'Respondent') NOT NULL,
    finding ENUM('Unjustified') NOT NULL DEFAULT 'Unjustified',
    remarks TEXT NULL,
    resolution ENUM('Pending', 'Rescheduled', 'Re-summons Issued') NOT NULL DEFAULT 'Pending',
    recorded_by INT UNSIGNED NULL,
    resolved_by INT UNSIGNED NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    UNIQUE KEY uq_hearing_nonappearance_party (hearing_id, resident_id),
    KEY idx_hearing_nonappearances_resolution (resolution),
    CONSTRAINT fk_hearing_nonappearances_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_hearing_nonappearances_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hearing_nonappearances_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearing_nonappearances_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE deadline_alert_log (
    deadline_alert_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deadline_id INT UNSIGNED NOT NULL,
    alert_type ENUM('Due Soon', 'Overdue') NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deadline_alert_once (deadline_id, alert_type),
    CONSTRAINT fk_deadline_alert_log_deadline FOREIGN KEY (deadline_id) REFERENCES case_deadlines (deadline_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE scheduling_holidays (
    holiday_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    holiday_name VARCHAR(150) NOT NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE venue_unavailabilities (
    unavailability_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue VARCHAR(255) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vu_venue_time (venue, start_time, end_time),
    CONSTRAINT fk_vu_user FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- RESOLUTION, DOCUMENTS, AND DELIVERY
-- =====================================================

CREATE TABLE settlements (
    settlement_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    settlement_date DATE NOT NULL,
    agreement_details LONGTEXT NOT NULL,
    terms_read_to_parties TINYINT(1) NOT NULL DEFAULT 0,
    complainant_signed TINYINT(1) NOT NULL DEFAULT 0,
    respondent_signed TINYINT(1) NOT NULL DEFAULT 0,
    pb_attested TINYINT(1) NOT NULL DEFAULT 0,
    pb_attested_by INT UNSIGNED NULL,
    pb_attested_at DATETIME NULL,
    barangay_sealed TINYINT(1) NOT NULL DEFAULT 0,
    original_in_case_folder TINYINT(1) NOT NULL DEFAULT 0,
    certified_copies_issued TINYINT(1) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NULL DEFAULT 0.00,
    responsible_party ENUM('Respondent', 'Complainant', 'Both') NOT NULL DEFAULT 'Respondent',
    has_installment TINYINT(1) NOT NULL DEFAULT 0,
    compliance_due_date DATE NULL,
    compliance_date DATE NULL,
    repudiation_deadline DATE NULL,
    repudiation_status ENUM('Within Repudiation Period', 'Repudiation Expired', 'Repudiated', 'Enforceable') NOT NULL DEFAULT 'Within Repudiation Period',
    repudiated_by INT UNSIGNED NULL,
    repudiation_reason TEXT NULL,
    repudiation_date DATETIME NULL,
    supporting_document_path VARCHAR(1024) NULL,
    compliance_status ENUM('Pending', 'Partially Paid', 'Fully Paid', 'Overdue', 'Breached', 'Complied', 'Violated') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settlements_case_id (case_id),
    CONSTRAINT fk_settlements_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_settlements_pb_attested_by
        FOREIGN KEY (pb_attested_by) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_settlements_repudiated_by
        FOREIGN KEY (repudiated_by) REFERENCES residents (resident_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE settlement_installments (
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

CREATE TABLE settlement_executions (
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

CREATE TABLE arbitration_records (
    arbitration_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    agreement_date DATE NULL,
    award_date DATE NULL,
    award_details LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_arbitration_records_case_id (case_id),
    CONSTRAINT fk_arbitration_records_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cfa_records (
    cfa_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    issuance_date DATE NOT NULL,
    reason TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cfa_records_case_id (case_id),
    CONSTRAINT fk_cfa_records_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE document_templates (
    template_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_templates_name (template_name)
) ENGINE=InnoDB;

CREATE TABLE generated_documents (
    document_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    generated_by INT UNSIGNED NULL,
    file_path VARCHAR(1024) NOT NULL,
    service_status ENUM('Generated', 'For Service', 'Served', 'Service Failed') NOT NULL DEFAULT 'Generated',
    regeneration_reason TEXT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_generated_documents_case_id (case_id),
    KEY idx_generated_documents_template_id (template_id),
    CONSTRAINT fk_generated_documents_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_generated_documents_template
        FOREIGN KEY (template_id) REFERENCES document_templates (template_id) ON DELETE RESTRICT,
    CONSTRAINT fk_generated_documents_user
        FOREIGN KEY (generated_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE proof_of_service (
    proof_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    document_id INT UNSIGNED NULL,
    service_result VARCHAR(50) NOT NULL DEFAULT 'Served',
    served_by INT UNSIGNED NULL,
    served_date DATETIME NOT NULL,
    remarks TEXT NULL,
    image_path VARCHAR(1024) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_proof_of_service_case_id (case_id),
    KEY idx_proof_of_service_document_id (document_id),
    CONSTRAINT fk_proof_of_service_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_proof_of_service_document
        FOREIGN KEY (document_id) REFERENCES generated_documents (document_id) ON DELETE SET NULL,
    CONSTRAINT fk_proof_of_service_user
        FOREIGN KEY (served_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- NOTIFICATIONS, AI, AND REPORTS
-- =====================================================

CREATE TABLE hearing_party_services (
    service_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    party_type ENUM('Complainant','Respondent') NOT NULL,
    document_id INT UNSIGNED NOT NULL,
    service_date DATETIME NOT NULL,
    service_result ENUM('Served','Not Found','Wrong Address','Refused','Unsuccessful Attempt') NOT NULL,
    reason TEXT NULL,
    serving_officer INT UNSIGNED NOT NULL,
    officer_return TEXT NULL,
    supporting_file VARCHAR(1024) NULL,
    assigned_to INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hearing_party_services_party (hearing_id,resident_id,service_date),
    CONSTRAINT fk_hps_hearing FOREIGN KEY (hearing_id) REFERENCES hearings(hearing_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hps_party FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hps_document FOREIGN KEY (document_id) REFERENCES generated_documents(document_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hps_officer FOREIGN KEY (serving_officer) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hps_assigned FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE hearing_explanations (
    explanation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    explanation TEXT NOT NULL,
    outcome ENUM('Pending','Justified','Unjustified') NOT NULL DEFAULT 'Pending',
    supporting_file VARCHAR(1024) NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hearing_explanations_party (hearing_id,resident_id),
    CONSTRAINT fk_hex_hearing FOREIGN KEY (hearing_id) REFERENCES hearings(hearing_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hex_party FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hex_decider FOREIGN KEY (decided_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hex_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE hearing_legal_actions (
    legal_action_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(120) NOT NULL,
    status ENUM('Pending Review','Approved','Rejected','Recorded') NOT NULL DEFAULT 'Pending Review',
    details TEXT NOT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hearing_legal_actions_hearing (hearing_id,created_at),
    CONSTRAINT fk_hla_hearing FOREIGN KEY (hearing_id) REFERENCES hearings(hearing_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hla_party FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hla_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hla_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    severity ENUM('urgent', 'warning', 'info', 'success') NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    action_url VARCHAR(255) NULL,
    case_id INT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user_read_created (user_id, is_read, created_at),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ai_logs (
    ai_log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    feature ENUM('Chatbot', 'Narrative Generator') NOT NULL,
    prompt LONGTEXT NOT NULL,
    response LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_logs_user_created_at (user_id, created_at),
    CONSTRAINT fk_ai_logs_user
        FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE generated_reports (
    report_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_type VARCHAR(50) NOT NULL,
    generated_by INT UNSIGNED NULL,
    file_path VARCHAR(1024) NOT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_generated_reports_type_generated_at (report_type, generated_at),
    CONSTRAINT fk_generated_reports_user
        FOREIGN KEY (generated_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================
-- REFERENCE DATA
-- =====================================================

INSERT INTO roles (role_name) VALUES
    ('Administrator'),
    ('Lupon Clerk'),
    ('Lupon Member'),
    ('Summons Server')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

-- Local development accounts only. Every account below uses password: password.
-- They use the normal password-hash login flow; this is not a login bypass.
-- Remove or change these accounts before deploying outside a local/test environment.
INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Barangay', 'Captain', 'admin', 'admin@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', role_id, 'Active'
FROM roles WHERE role_name = 'Administrator'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Clerk', 'User', 'clerk', 'clerk@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Clerk'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'clerk');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Head', 'luponhead', 'luponhead@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponhead');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Secretary', 'luponsecretary', 'luponsecretary@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponsecretary');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Member', 'luponmember', 'luponmember@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponmember');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Summons', 'Server', 'summonsserver', 'summonsserver@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', role_id, 'Active'
FROM roles WHERE role_name = 'Summons Server'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'summonsserver');

INSERT INTO complaint_categories (category_name, tagalog_name) VALUES
    ('Abandonment of a Minor (Arts. 276, 277) / Pagpapabaya sa Bata o Menor de Edad', 'Pagpapabaya sa Bata o Menor de Edad'),
    ('Abandonment of Persons in Danger or One\'s Own Victim (Art. 275) / Pagpapabaya sa Taong nasa Panganib o Sariling Biktima', 'Pagpapabaya sa Taong nasa Panganib o Sariling Biktima'),
    ('Acts of Lasciviousness with Consent (Art. 339) / Malalaswang Gawa na may Pahintulot', 'Malalaswang Gawa na may Pahintulot'),
    ('Agrarian Disputes (LOI 1314) / Alitan sa Lupang Pang-agrikultura (Agraryo)', 'Alitan sa Lupang Pang-agrikultura (Agraryo)'),
    ('Alarms and Scandals (Art. 155) / Panggugulo at Alarma', 'Panggugulo at Alarma'),
    ('Alteration of Boundary Marks or Landmarks (Art. 313) / Pagbabago ng mga Palatandaan ng Hangganan', 'Pagbabago ng mga Palatandaan ng Hangganan'),
    ('Animal and Pet-Related Disputes / Alitan Dulot ng Alagang Hayop (Kagat, Ingay, Dumi)', 'Alitan Dulot ng Alagang Hayop (Kagat, Ingay, Dumi)'),
    ('Anti-Fencing Law (PD 1612) / Pagbili o Pagtanggap ng Nakaw na Ari-arian (Anti-Fencing)', 'Pagbili o Pagtanggap ng Nakaw na Ari-arian (Anti-Fencing)'),
    ('Assistance to Suicide (Art. 253) / Pagtulong sa Pagpapatiwakal', 'Pagtulong sa Pagpapatiwakal'),
    ('Bouncing Checks (BP 22) / Pag-isyu ng Talbog na Tseke (Bouncing Checks)', 'Pag-isyu ng Talbog na Tseke (Bouncing Checks)'),
    ('Boundary Dispute and Encroachment / Pagtatalo sa Hangganan ng Lupa at Bakod', 'Pagtatalo sa Hangganan ng Lupa at Bakod'),
    ('Breach of Contract or Agreement / Paglabag sa Kontrata o Kasunduan', 'Paglabag sa Kontrata o Kasunduan'),
    ('Coercions (Grave, Light, or Similar) (Arts. 286-289) / Pamimilit o Pagpilit (Coercion)', 'Pamimilit o Pagpilit (Coercion)'),
    ('Collection of Debts or Rentals / Paglikom ng mga Utang o Upa', 'Paglikom ng mga Utang o Upa'),
    ('Damages and Reimbursement / Bayad-Pinsala at Pagbabalik ng Halaga', 'Bayad-Pinsala at Pagbabalik ng Halaga'),
    ('Dispensing of Abortives by Pharmacist (Art. 259) / Pagbebenta o Pagbibigay ng Pampalaglag ng Parmasyutiko', 'Pagbebenta o Pagbibigay ng Pampalaglag ng Parmasyutiko'),
    ('Easement and Right-of-Way / Karapatan sa Daanan at Daluyan (Easement)', 'Karapatan sa Daanan at Daluyan (Easement)'),
    ('Estafa and Swindling (Arts. 315, 316, 317) / Panlilinlang o Estafa', 'Panlilinlang o Estafa'),
    ('False Certificates (Arts. 174, 175) / Paggamit o Pagbibigay ng Pekeng Sertipiko', 'Paggamit o Pagbibigay ng Pekeng Sertipiko'),
    ('False Testimony Against or In Favor of Defendant (Arts. 180, 181) / Pagsisinungaling o Maling Testimonya', 'Pagsisinungaling o Maling Testimonya'),
    ('Family and Domestic Relations / Mga Relasyong Pampamilya at Tahanan', 'Mga Relasyong Pampamilya at Tahanan'),
    ('Fictitious Name and Concealment of True Name (Art. 178) / Paggamit ng Huwad na Pangalan at Pagkukubli ng Tunay na Pangalan', 'Paggamit ng Huwad na Pangalan at Pagkukubli ng Tunay na Pangalan'),
    ('Grave Scandal (Art. 200) / Malubhang Eskandalo (Grave Scandal)', 'Malubhang Eskandalo (Grave Scandal)'),
    ('Illegal Use of Uniforms or Insignia (Art. 179) / Ilegal na Paggamit ng Uniporme o Sagisag', 'Ilegal na Paggamit ng Uniporme o Sagisag'),
    ('Imprudence and Negligence (Art. 365) / Pinsala dulot ng Kapabayaan o Imprudensya', 'Pinsala dulot ng Kapabayaan o Imprudensya'),
    ('Incriminating Innocent Persons & Intriguing Against Honor (Arts. 363, 364) / Pagsasangkot ng Walang-Salang Tao at Paninirang-Puri', 'Pagsasangkot ng Walang-Salang Tao at Paninirang-Puri'),
    ('Inducing Minor to Abandon Home (Art. 271) / Pag-uudyok sa Menor de Edad na Lumayas sa Tahanan', 'Pag-uudyok sa Menor de Edad na Lumayas sa Tahanan'),
    ('Lease and Rental Disputes / Pagpapaupa at Alitan sa Upa', 'Pagpapaupa at Alitan sa Upa'),
    ('Less Serious Physical Injuries (Art. 265) / Hindi Lubhang Malubhang Pananakit sa Katawan', 'Hindi Lubhang Malubhang Pananakit sa Katawan'),
    ('Malicious Mischief and Damage to Property (Arts. 327, 329) / Paninira ng Ari-arian (Malicious Mischief)', 'Paninira ng Ari-arian (Malicious Mischief)'),
    ('Neighborhood Nuisance (Noise, Odor, Waste) / Pambubulabog sa Kapaligiran (Ingay, Amoy, Basura)', 'Pambubulabog sa Kapaligiran (Ingay, Amoy, Basura)'),
    ('Non-Payment of Debt / Hindi Pagbabayad ng Utang', 'Hindi Pagbabayad ng Utang'),
    ('Occupation of Real Property or Usurpation of Real Rights (Art. 312) / Pang-aagaw o Ilegal na Pagsakop sa Ari-arian', 'Pang-aagaw o Ilegal na Pagsakop sa Ari-arian'),
    ('Physical Injuries in a Tumultuous Affray (Art. 252) / Pananakit sa Katawan sa Gitna ng Kaguluhan', 'Pananakit sa Katawan sa Gitna ng Kaguluhan'),
    ('Premature Marriages (Art. 351) / Maagang Pagpapakasal (Premature Marriage)', 'Maagang Pagpapakasal (Premature Marriage)'),
    ('Price Control Violations / Paglabag sa Pagkontrol ng Presyo ng Bilihin', 'Paglabag sa Pagkontrol ng Presyo ng Bilihin'),
    ('Removal, Sale or Pledge of Mortgaged Property (Art. 319) / Pag-aalis, Pagbenta, o Pagsangla ng Nakasanglang Ari-arian', 'Pag-aalis, Pagbenta, o Pagsangla ng Nakasanglang Ari-arian'),
    ('Responsibility of Participants in a Duel (Art. 260) / Pananagutan ng mga Kalahok sa Duelo', 'Pananagutan ng mga Kalahok sa Duelo'),
    ('Seizure of Correspondence or Secrets with Abuse of Office (Arts. 290, 291) / Pagharang ng Liham o Pagsisiwalat ng Lihim', 'Pagharang ng Liham o Pagsisiwalat ng Lihim'),
    ('Simple Seduction (Art. 338) / Simpleng Panunukso o Pang-aakit (Simple Seduction)', 'Simpleng Panunukso o Pang-aakit (Simple Seduction)'),
    ('Slander and Defamation (Arts. 358, 359) / Paninirang-Puri (Oral Defamation / Slander)', 'Paninirang-Puri (Oral Defamation / Slander)'),
    ('Slight Physical Injuries and Maltreatment (Art. 266) / Bahagyang Pananakit at Pagmamalupit (Slight Physical Injuries)', 'Bahagyang Pananakit at Pagmamalupit (Slight Physical Injuries)'),
    ('Specific Performance of Obligation / Tiyak na Pagtupad ng Obligasyon', 'Tiyak na Pagtupad ng Obligasyon'),
    ('Theft and Other Thefts (Arts. 309, 310) / Pagnanakaw (Theft)', 'Pagnanakaw (Theft)'),
    ('Threatening to Publish for Compensation or Prohibited Publications (Arts. 154, 356, 357) / Pagbabanta ng Paglalathala o Ipinagbabawal na Paglalathala', 'Pagbabanta ng Paglalathala o Ipinagbabawal na Paglalathala'),
    ('Threats (Grave, Light, or Other Threats) (Arts. 282, 283, 285) / Pagbabanta (Threats)', 'Pagbabanta (Threats)'),
    ('Trespass to Dwelling and Other Forms (Arts. 280, 281) / Ilegal na Pagpasok sa Bahay o Bakuran (Trespass)', 'Ilegal na Pagpasok sa Bahay o Bakuran (Trespass)'),
    ('Unjust Vexation (Art. 287) / Hindi Makatarungang Pang-iinis o Panggigipit (Unjust Vexation)', 'Hindi Makatarungang Pang-iinis o Panggigipit (Unjust Vexation)'),
    ('Unlawful Arrest (Art. 269) / Ilegal na Pag-aresto o Pagpigil', 'Ilegal na Pag-aresto o Pagpigil'),
    ('Violation of Local Ordinances / Paglabag sa Lokal na Ordinansa', 'Paglabag sa Lokal na Ordinansa'),
    ('Others (Please Specify) / Iba pa (Pakitukoy)', 'Iba pa (Pakitukoy)')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name), tagalog_name = VALUES(tagalog_name);

INSERT INTO document_templates (template_name, description) VALUES
    ('KP Form 7', 'Complaint'),
    ('KP Form 8', 'Notice of Hearing'),
    ('KP Form 9', 'Summons'),
    ('KP Form 10', 'Notice to Constitute the Pangkat Tagapagkasundo'),
    ('KP Form 14', 'Arbitration Agreement'),
    ('KP Form 15', 'Arbitration Award'),
    ('KP Form 16', 'Amicable Settlement'),
    ('KP Form 18', 'Notice of Hearing (Failure to Appear - Complainant)'),
    ('KP Form 19', 'Notice of Hearing (Failure to Appear - Respondent)'),
    ('KP Form 20', 'Certificate to File Action'),
    ('KP Form 21', 'Certificate to Bar Action'),
    ('KP Form 22', 'Certificate to Bar Action Counterclaim')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('captain_name', 'HON. AKIKO LYNNE CENTENO', 'Official Name of Punong Barangay'),
    ('daily_hearing_capacity', '8', 'Maximum total hearings allowed per day across the barangay (max 8)'),
    ('officer_daily_hearing_capacity', '6', 'Maximum hearings per presiding officer per day (5-8)'),
    ('mediation_default_duration_min', '45', 'Default duration for mediation session in minutes (30-60)'),
    ('mediation_max_duration_min', '60', 'Maximum duration before logged justification is required for mediation'),
    ('conciliation_default_duration_min', '60', 'Default duration for conciliation session in minutes (45-90)'),
    ('conciliation_max_duration_min', '90', 'Maximum duration before logged justification is required for conciliation'),
    ('mediation_session_interval_days', '3', 'Recommended minimum calendar days between mediation sessions (3-5)'),
    ('party_reschedule_limit', '2', 'Maximum allowed reschedules per party before authorized review is required (1-2)'),
    ('summons_max_attempts', '6', 'Standard cap for summons and officer return delivery attempts before CFA eligibility (1-6)'),
    ('summons_attempt_interval_days', '1', 'Minimum days between summons service attempts (1-3)')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);



CREATE OR REPLACE VIEW view_conciliation_hearing_returns AS
SELECT 
    h.hearing_id,
    h.case_id,
    c.case_number,
    c.complaint_id,
    h.hearing_type,
    h.hearing_date,
    h.venue,
    h.status AS hearing_status,
    h.kp12_document_id,
    gd.file_path AS kp12_file_path,
    gd.service_status AS kp12_service_status,
    gd.generated_at AS kp12_generated_at,
    sd.delivery_id,
    sd.party_type AS delivery_party_type,
    sd.resident_id AS delivery_resident_id,
    sd.recipient_name,
    sd.delivery_status,
    sd.served_at,
    sd.served_by,
    sd.service_method,
    sd.proof_file_path,
    sd.remarks AS delivery_remarks,
    hps.service_id AS officer_return_service_id,
    hps.service_date AS officer_return_service_date,
    hps.service_result AS officer_return_result,
    hps.officer_return,
    hps.serving_officer,
    TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS serving_officer_name
FROM hearings h
INNER JOIN cases c ON c.case_id = h.case_id
LEFT JOIN generated_documents gd ON gd.document_id = h.kp12_document_id
LEFT JOIN summon_deliveries sd ON sd.hearing_id = h.hearing_id OR (sd.case_id = h.case_id AND sd.document_id = h.kp12_document_id)
LEFT JOIN hearing_party_services hps ON hps.hearing_id = h.hearing_id OR (h.kp12_document_id IS NOT NULL AND hps.document_id = h.kp12_document_id)
LEFT JOIN users u ON u.user_id = COALESCE(hps.serving_officer, sd.served_by, sd.served_by_user_id)
WHERE h.hearing_type = 'Conciliation';

SET FOREIGN_KEY_CHECKS = 1;
