-- ==============================================================================
-- AGAP MIGRATION: 20261016_001_enhance_file_complaint_kp7.sql
-- Description: Enhances the File Complaint module with:
--   1. Document status & scanned handwritten KP Form 7 support
--   2. Versioned complaint scanned documents table
--   3. Minor complainant/respondent legal guardian and unavailable info fields
--   4. Requested resolution (prayer for relief), approximate/unknown time, and additional location details
--   5. Support for Draft complaint status
-- ==============================================================================

-- 1. Modify complaints.status ENUM to include 'Draft' if not already present
ALTER TABLE complaints
    MODIFY COLUMN status ENUM('Draft', 'Filed', 'Under Review', 'Needs Information', 'Accepted', 'Rejected', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT') NOT NULL DEFAULT 'Filed';

-- 2. Add document_status to complaints
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'document_status'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE complaints ADD COLUMN document_status ENUM('No Scan Uploaded', 'Scan Uploaded', 'Updated Scan Uploaded') NOT NULL DEFAULT 'No Scan Uploaded' AFTER status",
    "SELECT 'Column document_status already exists on complaints'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add requested_resolution to complaints
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'requested_resolution'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE complaints ADD COLUMN requested_resolution TEXT NULL AFTER additional_details",
    "SELECT 'Column requested_resolution already exists on complaints'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Add is_time_approximate to complaints
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'is_time_approximate'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE complaints ADD COLUMN is_time_approximate TINYINT(1) NOT NULL DEFAULT 0 AFTER incident_time",
    "SELECT 'Column is_time_approximate already exists on complaints'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Add is_time_unknown to complaints
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'is_time_unknown'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE complaints ADD COLUMN is_time_unknown TINYINT(1) NOT NULL DEFAULT 0 AFTER is_time_approximate",
    "SELECT 'Column is_time_unknown already exists on complaints'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Add incident_location_details to complaints
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'incident_location_details'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE complaints ADD COLUMN incident_location_details TEXT NULL AFTER incident_landmark",
    "SELECT 'Column incident_location_details already exists on complaints'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Add is_draft to complaints
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints' AND COLUMN_NAME = 'is_draft'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE complaints ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER document_status",
    "SELECT 'Column is_draft already exists on complaints'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. Add guardian, personal, and unknown info fields to complaint_parties
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_parties' AND COLUMN_NAME = 'is_minor'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE complaint_parties
        ADD COLUMN is_minor TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN guardian_name VARCHAR(255) NULL,
        ADD COLUMN guardian_relationship VARCHAR(100) NULL,
        ADD COLUMN guardian_contact VARCHAR(50) NULL,
        ADD COLUMN guardian_address TEXT NULL,
        ADD COLUMN preferred_contact ENUM('SMS', 'Call', 'Email', 'In-Person') NULL DEFAULT 'SMS',
        ADD COLUMN suffix VARCHAR(20) NULL,
        ADD COLUMN occupation VARCHAR(100) NULL,
        ADD COLUMN age INT NULL,
        ADD COLUMN birth_date DATE NULL,
        ADD COLUMN gender VARCHAR(50) NULL,
        ADD COLUMN civil_status VARCHAR(50) NULL,
        ADD COLUMN contact_unknown TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN middle_name_unknown TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN birthdate_unknown TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN address_requires_verification TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN residency_verified TINYINT(1) NOT NULL DEFAULT 1",
    "SELECT 'Columns already exist on complaint_parties'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. Add suffix, occupation, preferred_contact, residency_verified to residents
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'residents' AND COLUMN_NAME = 'suffix'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE residents
        ADD COLUMN suffix VARCHAR(20) NULL AFTER last_name,
        ADD COLUMN occupation VARCHAR(100) NULL AFTER civil_status,
        ADD COLUMN preferred_contact ENUM('SMS', 'Call', 'Email', 'In-Person') NULL DEFAULT 'SMS' AFTER email,
        ADD COLUMN residency_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER is_tenant",
    "SELECT 'Columns already exist on residents'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 10. Create complaint_scanned_documents table for handwritten KP Form 7 uploads with version history
CREATE TABLE IF NOT EXISTS complaint_scanned_documents (
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

-- 11. Ensure KP Form 7 template is present in document_templates
INSERT INTO document_templates (template_name, description)
VALUES ('KP Form 7', 'Sumbong (Complaint)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
