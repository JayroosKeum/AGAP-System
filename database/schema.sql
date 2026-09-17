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

-- =====================================================
-- PEOPLE AND COMPLAINT INTAKE
-- =====================================================

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
    is_tenant TINYINT(1) NOT NULL DEFAULT 0,
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
    complaint_title VARCHAR(255) NOT NULL,
    incident_date DATE NULL,
    incident_time TIME NULL,
    incident_location VARCHAR(255) NULL,
    incident_landmark VARCHAR(255) NULL,
    narrative LONGTEXT NOT NULL,
    additional_details LONGTEXT NULL,
    review_notes TEXT NULL,
    status ENUM('Filed', 'Under Review', 'Needs Information', 'Accepted', 'Rejected', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived') NOT NULL DEFAULT 'Filed',
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
    case_status ENUM('Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived') NOT NULL DEFAULT 'Docketed',
    docket_date DATE NOT NULL,
    archived_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cases_complaint_id (complaint_id),
    UNIQUE KEY uq_cases_number (case_number),
    KEY idx_cases_status_docket_date (case_status, docket_date),
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
    hearing_type ENUM('Initial Hearing', 'Mediation', 'Conciliation', 'Arbitration') NOT NULL,
    hearing_date DATETIME NOT NULL,
    venue VARCHAR(255) NOT NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hearings_case_id_date (case_id, hearing_date),
    CONSTRAINT fk_hearings_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE hearing_attendance (
    attendance_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    attendance_status ENUM('Present', 'Absent', 'Late', 'Excused') NOT NULL,
    remarks TEXT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hearing_attendance_resident (hearing_id, resident_id),
    KEY idx_hearing_attendance_resident_id (resident_id),
    CONSTRAINT fk_hearing_attendance_hearing
        FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_hearing_attendance_resident
        FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =====================================================
-- RESOLUTION, DOCUMENTS, AND DELIVERY
-- =====================================================

CREATE TABLE settlements (
    settlement_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    settlement_date DATE NOT NULL,
    agreement_details LONGTEXT NOT NULL,
    compliance_status ENUM('Pending', 'Complied', 'Violated') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settlements_case_id (case_id),
    CONSTRAINT fk_settlements_case
        FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE
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

-- Local development accounts only. Every account below uses password: password
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
    ('KP Form 14', 'Arbitration Agreement'),
    ('KP Form 15', 'Arbitration Award'),
    ('KP Form 16', 'Amicable Settlement'),
    ('KP Form 20', 'Certificate to File Action'),
    ('KP Form 21', 'Certificate to File Action'),
    ('KP Form 22', 'Certificate to File Action')
ON DUPLICATE KEY UPDATE description = VALUES(description);
