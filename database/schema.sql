-- AGAP database schema (MySQL 8.0+ / MariaDB 10.5+)
-- This file provisions a new database. It intentionally does not drop existing tables.

CREATE DATABASE IF NOT EXISTS agap_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE agap_db;

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
    birth_date DATE NULL,
    gender VARCHAR(50) NULL,
    civil_status VARCHAR(50) NULL,
    contact_no VARCHAR(20) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    purok VARCHAR(100) NULL,
    is_tenant TINYINT(1) NOT NULL DEFAULT 0, -- 0 = Permanent Resident, 1 = Tenant / Renter, 2 = Non-Resident
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_residents_name (last_name, first_name)
) ENGINE=InnoDB;

CREATE TABLE complaint_categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaint_categories_name (category_name)
) ENGINE=InnoDB;

CREATE TABLE complaints (
    complaint_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Assigned immediately after insert by the model so database-generated IDs remain race-safe.
    complaint_number VARCHAR(50) NULL,
    category_id INT UNSIGNED NOT NULL,
    case_type ENUM('Civil', 'Criminal') NOT NULL DEFAULT 'Civil',
    complaint_title VARCHAR(255) NOT NULL,
    incident_date DATE NULL,
    incident_time TIME NULL,
    incident_location VARCHAR(255) NULL,
    incident_city VARCHAR(100) NOT NULL DEFAULT 'Marikina City',
    incident_barangay VARCHAR(100) NOT NULL DEFAULT 'Tumana',
    incident_street VARCHAR(255) NULL,
    incident_purok VARCHAR(100) NULL,
    incident_landmark VARCHAR(255) NULL,
    narrative LONGTEXT NOT NULL,
    additional_details LONGTEXT NULL,
    review_notes TEXT NULL,
    status ENUM('Filed', 'Under Review', 'Needs Information', 'Accepted', 'Rejected', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT') NOT NULL DEFAULT 'Filed',
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaint_parties_role (complaint_id, resident_id, party_type),
    KEY idx_complaint_parties_resident_id (resident_id),
    CONSTRAINT fk_complaint_parties_complaint
        FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_complaint_parties_resident
        FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_incident_locations_complaint_id (complaint_id),
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
    selection_method ENUM('Party Agreement', 'PB Assignment', 'Raffle Draw') NOT NULL DEFAULT 'Party Agreement',
    selection_notes TEXT NULL,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pangkat_members_member (pangkat_id, member_id),
    KEY idx_pangkat_members_member_id (member_id),
    CONSTRAINT fk_pangkat_members_group
        FOREIGN KEY (pangkat_id) REFERENCES pangkat_groups (pangkat_id) ON DELETE CASCADE,
    CONSTRAINT fk_pangkat_members_user
        FOREIGN KEY (member_id) REFERENCES users (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE hearings (
    hearing_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    hearing_type ENUM('Initial Hearing', 'Mediation', 'Conciliation', 'Arbitration', 'Show Cause (Complainant)', 'Show Cause (Respondent)') NOT NULL,
    hearing_date DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_minutes INT UNSIGNED NULL DEFAULT 45,
    actual_end_time DATETIME NULL,
    duration_exceed_reason TEXT NULL,
    venue VARCHAR(255) NOT NULL,
    remarks TEXT NULL,
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
    cancellation_notice_sent TINYINT(1) NOT NULL DEFAULT 0,
    complainant_attendance ENUM('Pending', 'Present', 'Absent') NOT NULL DEFAULT 'Pending',
    respondent_attendance ENUM('Pending', 'Present', 'Absent') NOT NULL DEFAULT 'Pending',
    attendance_recorded_at DATETIME NULL,
    attendance_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hearings_case_id_date (case_id, hearing_date),
    KEY idx_hearings_presiding (presiding_officer_id),
    CONSTRAINT fk_hearings_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_hearings_rescheduled_from FOREIGN KEY (rescheduled_from_id) REFERENCES hearings (hearing_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hearings_presiding FOREIGN KEY (presiding_officer_id) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearings_substitute FOREIGN KEY (substitute_presider_id) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearings_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearings_reschedule_approved_by FOREIGN KEY (reschedule_approved_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

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

CREATE TABLE summon_deliveries (
    delivery_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    party_type ENUM('Complainant', 'Respondent') NOT NULL,
    resident_id INT UNSIGNED NULL,
    form_type ENUM('Notice of Hearing', 'Summon', 'KP Form 18', 'KP Form 19') NOT NULL,
    delivery_status ENUM('Pending', 'Served Personal', 'Served Substituted', 'Served Refused', 'Unserved') NOT NULL DEFAULT 'Pending',
    served_at DATETIME NULL,
    served_by INT UNSIGNED NULL,
    recipient_name VARCHAR(150) NULL,
    relationship VARCHAR(100) NULL,
    unserved_reason ENUM('Moved Out', 'Wrong Address', 'No One Home', 'Other') NULL,
    failure_notes TEXT NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hearing_party_form (hearing_id, party_type, form_type),
    KEY idx_summon_deliveries_hearing_status (hearing_id, delivery_status),
    KEY idx_summon_deliveries_served_by (served_by),
    CONSTRAINT fk_summon_deliveries_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_summon_deliveries_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE SET NULL,
    CONSTRAINT fk_summon_deliveries_served_by FOREIGN KEY (served_by) REFERENCES users (user_id) ON DELETE SET NULL
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
    attendance_status ENUM('Present', 'Absent', 'Late', 'Excused', 'Not Served') NOT NULL,
    is_justified TINYINT(1) NOT NULL DEFAULT 0,
    justification_reason VARCHAR(255) NULL,
    remarks TEXT NULL,
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
    message TEXT NOT NULL,
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
    report_type ENUM('Monthly', 'Quarterly', 'Annual', 'DILG') NOT NULL,
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
SELECT 'Admin', 'User', 'admin', 'admin@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Administrator'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Clerk', 'User', 'clerk', 'clerk@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Clerk'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'clerk');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Head', 'luponhead', 'luponhead@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponhead');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Secretary', 'luponsecretary', 'luponsecretary@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponsecretary');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Lupon', 'Member', 'luponmember', 'luponmember@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Lupon Member'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'luponmember');

INSERT INTO users (first_name, last_name, username, email, password_hash, role_id, status)
SELECT 'Summons', 'Server', 'summonsserver', 'summonsserver@agap.test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', role_id, 'Active'
FROM roles WHERE role_name = 'Summons Server'
AND NOT EXISTS (SELECT 1 FROM users WHERE username = 'summonsserver');

INSERT INTO complaint_categories (category_name) VALUES
    ('Non-Payment of Debt'),
    ('Breach of Agreement'),
    ('Physical Injuries'),
    ('Defamation'),
    ('Threats'),
    ('Property and Rental Disputes'),
    ('Disturbance and Public Disorder'),
    ('Malicious Mischief'),
    ('Trespassing'),
    ('Family and Domestic Disputes')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name);

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

