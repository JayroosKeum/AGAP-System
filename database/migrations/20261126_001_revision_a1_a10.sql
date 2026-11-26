-- AGAP Capstone Revision Implementation Migration (A1 - A10)
-- Idempotent migration for existing databases.

-- =====================================================
-- 1. COMPLAINTS: A1/A2 Routing, A3 Privacy Consent, A8 Soft Deletion, A9/A10 Resolution
-- =====================================================

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'proposed_category_id'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN proposed_category_id INT UNSIGNED NULL AFTER category_id');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'routing_route'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN routing_route VARCHAR(50) NULL AFTER ltia_category');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'assigned_queue'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN assigned_queue VARCHAR(80) NULL AFTER routing_route');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'routing_rule_version'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN routing_rule_version VARCHAR(32) NULL AFTER assigned_queue');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'routing_reasons'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN routing_reasons TEXT NULL AFTER routing_rule_version');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'routed_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN routed_at DATETIME NULL AFTER routing_reasons');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'routing_overridden_by'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN routing_overridden_by INT UNSIGNED NULL AFTER routed_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'routing_overridden_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN routing_overridden_at DATETIME NULL AFTER routing_overridden_by');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'routing_override_reason'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN routing_override_reason VARCHAR(500) NULL AFTER routing_overridden_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'privacy_notice_acknowledged'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN privacy_notice_acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER routing_override_reason');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'privacy_notice_version'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN privacy_notice_version VARCHAR(32) NULL AFTER privacy_notice_acknowledged');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'privacy_consented_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN privacy_consented_at DATETIME NULL AFTER privacy_notice_version');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'deleted_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN deleted_at DATETIME NULL AFTER updated_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'deleted_by'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'deletion_reason'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN deletion_reason VARCHAR(500) NULL AFTER deleted_by');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'resolution_type'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN resolution_type VARCHAR(50) NULL AFTER status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'resolution_notes'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN resolution_notes TEXT NULL AFTER resolution_type');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'resolved_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD COLUMN resolved_at DATETIME NULL AFTER resolution_notes');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND INDEX_NAME = 'idx_complaints_deleted_at'
), 'SELECT 1', 'ALTER TABLE complaints ADD INDEX idx_complaints_deleted_at (deleted_at)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND INDEX_NAME = 'idx_complaints_assigned_queue'
), 'SELECT 1', 'ALTER TABLE complaints ADD INDEX idx_complaints_assigned_queue (assigned_queue, routing_route)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A1: Routing evaluation and human override log table
CREATE TABLE IF NOT EXISTS complaint_routing_logs (
    routing_log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    event_type ENUM('Automated', 'Override') NOT NULL DEFAULT 'Automated',
    rule_version VARCHAR(32) NOT NULL,
    proposed_category_id INT UNSIGNED NULL,
    routing_route VARCHAR(50) NOT NULL,
    assigned_queue VARCHAR(80) NOT NULL,
    reasons_json TEXT NULL,
    matched_keywords_json TEXT NULL,
    previous_queue VARCHAR(80) NULL,
    override_reason VARCHAR(500) NULL,
    actor_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_routing_logs_complaint (complaint_id, created_at),
    CONSTRAINT fk_routing_logs_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_routing_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. ANONYMOUS REPORTING & HASHED-TOKEN FOLLOW-UP (A5)
-- =====================================================

CREATE TABLE IF NOT EXISTS anonymous_concerns (
    concern_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_code VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    category_hint VARCHAR(120) NULL,
    concern_title VARCHAR(255) NOT NULL,
    narrative LONGTEXT NOT NULL,
    barangay_area VARCHAR(120) NULL,
    routing_route VARCHAR(50) NOT NULL DEFAULT 'KP_ELIGIBLE',
    assigned_queue VARCHAR(80) NOT NULL DEFAULT 'LISO_SCREENING',
    routing_rule_version VARCHAR(32) NULL,
    status ENUM('Submitted', 'Under Review', 'Referred to Formal KP', 'Referred Externally', 'Action Taken', 'Closed') NOT NULL DEFAULT 'Submitted',
    public_milestone_note VARCHAR(500) NULL,
    attachment_path VARCHAR(1024) NULL,
    failed_lookup_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_anonymous_reference (reference_code),
    UNIQUE KEY uq_anonymous_token_hash (token_hash),
    KEY idx_anonymous_status_queue (status, assigned_queue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. AES-256 PII ENCRYPTION COLUMNS & BLIND INDEXES (A6)
-- =====================================================

ALTER TABLE residents
    MODIFY COLUMN contact_no VARCHAR(512) NULL,
    MODIFY COLUMN email VARCHAR(512) NULL,
    MODIFY COLUMN address TEXT NULL,
    MODIFY COLUMN house_street VARCHAR(512) NULL;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'contact_no_hash'
), 'SELECT 1', 'ALTER TABLE residents ADD COLUMN contact_no_hash CHAR(64) NULL AFTER contact_no');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'contact_last4_hash'
), 'SELECT 1', 'ALTER TABLE residents ADD COLUMN contact_last4_hash CHAR(64) NULL AFTER contact_no_hash');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'email_hash'
), 'SELECT 1', 'ALTER TABLE residents ADD COLUMN email_hash CHAR(64) NULL AFTER email');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND INDEX_NAME = 'idx_residents_contact_hash'
), 'SELECT 1', 'ALTER TABLE residents ADD INDEX idx_residents_contact_hash (contact_no_hash)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND INDEX_NAME = 'idx_residents_contact_last4_hash'
), 'SELECT 1', 'ALTER TABLE residents ADD INDEX idx_residents_contact_last4_hash (contact_last4_hash)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND INDEX_NAME = 'idx_residents_email_hash'
), 'SELECT 1', 'ALTER TABLE residents ADD INDEX idx_residents_email_hash (email_hash)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE complaint_parties
    MODIFY COLUMN guardian_name VARCHAR(512) NULL,
    MODIFY COLUMN guardian_contact VARCHAR(512) NULL,
    MODIFY COLUMN guardian_address TEXT NULL;

ALTER TABLE users
    MODIFY COLUMN email VARCHAR(512) NULL,
    MODIFY COLUMN contact_no VARCHAR(512) NULL;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_hash'
), 'SELECT 1', 'ALTER TABLE users ADD COLUMN email_hash CHAR(64) NULL AFTER email');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'contact_no_hash'
), 'SELECT 1', 'ALTER TABLE users ADD COLUMN contact_no_hash CHAR(64) NULL AFTER contact_no');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email_hash'
), 'SELECT 1', 'ALTER TABLE users ADD UNIQUE KEY uq_users_email_hash (email_hash)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================
-- 4. IMMUTABLE / TAMPER-EVIDENT AUDIT TRAIL (A7)
-- =====================================================

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_trails' AND COLUMN_NAME = 'before_state'
), 'SELECT 1', 'ALTER TABLE audit_trails ADD COLUMN before_state LONGTEXT NULL AFTER affected_record');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_trails' AND COLUMN_NAME = 'after_state'
), 'SELECT 1', 'ALTER TABLE audit_trails ADD COLUMN after_state LONGTEXT NULL AFTER before_state');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_trails' AND COLUMN_NAME = 'ip_address'
), 'SELECT 1', 'ALTER TABLE audit_trails ADD COLUMN ip_address VARCHAR(45) NULL AFTER after_state');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_trails' AND COLUMN_NAME = 'prev_hash'
), 'SELECT 1', 'ALTER TABLE audit_trails ADD COLUMN prev_hash CHAR(64) NULL AFTER ip_address');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_trails' AND COLUMN_NAME = 'entry_hash'
), 'SELECT 1', 'ALTER TABLE audit_trails ADD COLUMN entry_hash CHAR(64) NULL AFTER prev_hash');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_trails' AND CONSTRAINT_NAME = 'fk_audit_trails_user'
), 'ALTER TABLE audit_trails DROP FOREIGN KEY fk_audit_trails_user', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TRIGGER IF EXISTS trg_audit_trails_no_update;
DROP TRIGGER IF EXISTS trg_audit_trails_no_delete;

CREATE TRIGGER trg_audit_trails_no_update
BEFORE UPDATE ON audit_trails
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_trails is append-only; UPDATE is prohibited.';

CREATE TRIGGER trg_audit_trails_no_delete
BEFORE DELETE ON audit_trails
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_trails is append-only; DELETE is prohibited.';

-- =====================================================
-- 5. SOFT DELETION & SUPER ADMINISTRATOR ROLE (A8)
-- =====================================================

INSERT IGNORE INTO roles (role_name) VALUES ('Super Administrator');

INSERT IGNORE INTO permissions (permission_name) VALUES
    ('complaints.soft_delete'),
    ('complaints.view_deleted'),
    ('complaints.restore_deleted'),
    ('audit.verify');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM roles r
JOIN permissions p ON p.permission_name IN (
    'complaints.soft_delete',
    'complaints.view_deleted',
    'complaints.restore_deleted',
    'audit.verify'
)
WHERE r.role_name = 'Super Administrator';

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'deleted_at'
), 'SELECT 1', 'ALTER TABLE cases ADD COLUMN deleted_at DATETIME NULL AFTER updated_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'deleted_by'
), 'SELECT 1', 'ALTER TABLE cases ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'deletion_reason'
), 'SELECT 1', 'ALTER TABLE cases ADD COLUMN deletion_reason VARCHAR(500) NULL AFTER deleted_by');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================
-- 6. RESOLUTION STATES & PENDING CLOSE WORKFLOW (A9 / A10)
-- =====================================================

ALTER TABLE cases
    MODIFY COLUMN case_status ENUM(
        'Docketed', 'Mediation', 'Conciliation', 'Arbitration',
        'Settled', 'Dismissed', 'CFA Issued', 'Archived',
        'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Repudiated',
        'Pending Close', 'Closed'
    ) NOT NULL DEFAULT 'Docketed';

CREATE TABLE IF NOT EXISTS closure_requests (
    closure_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    case_id INT UNSIGNED NULL,
    requested_by INT UNSIGNED NOT NULL,
    resolution_type ENUM('Signed Settlement', 'Obligation Compliance', 'Administrative Closure', 'Dismissal', 'CFA Referral') NOT NULL DEFAULT 'Administrative Closure',
    request_reason TEXT NOT NULL,
    evidence_path VARCHAR(1024) NULL,
    previous_complaint_status VARCHAR(50) NULL,
    previous_case_status VARCHAR(50) NULL,
    notice_sent_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    verification_token_hash CHAR(64) NOT NULL,
    otp_hash CHAR(64) NULL,
    status ENUM('Pending', 'Confirmed', 'Objected', 'TimedOutClosed', 'Cancelled') NOT NULL DEFAULT 'Pending',
    confirmed_by_user_id INT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    objected_at DATETIME NULL,
    objection_reason TEXT NULL,
    closed_at DATETIME NULL,
    timeout_processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_closure_token_hash (verification_token_hash),
    KEY idx_closure_complaint_status (complaint_id, status),
    KEY idx_closure_pending_expiry (status, expires_at),
    CONSTRAINT fk_closure_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_closure_case FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE SET NULL,
    CONSTRAINT fk_closure_requester FOREIGN KEY (requested_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_closure_confirmer FOREIGN KEY (confirmed_by_user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
