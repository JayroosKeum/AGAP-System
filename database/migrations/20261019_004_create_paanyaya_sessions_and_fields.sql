-- Migration: 20261019_004_create_paanyaya_sessions_and_fields.sql
-- Description: Implement mandatory Paanyaya (Informal Invitation) Pre-Docketing Workflow & Dalawang Beses Lang state machine

-- 1. Add Paanyaya tracking columns to complaints table
ALTER TABLE complaints 
    ADD COLUMN paanyaya_attempt_count TINYINT UNSIGNED DEFAULT 1,
    ADD COLUMN max_paanyaya_reached TINYINT(1) DEFAULT 0,
    ADD COLUMN resolved_without_docketing TINYINT(1) DEFAULT 0,
    ADD COLUMN ltia_category VARCHAR(100) NULL;

-- 2. Create paanyaya_sessions table for tracking up to 2 informal dialogue attempts
CREATE TABLE IF NOT EXISTS paanyaya_sessions (
    session_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1, -- 1 or 2 only
    scheduled_date DATE NOT NULL,
    scheduled_time TIME NOT NULL,
    venue VARCHAR(255) NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
    has_e_signature TINYINT(1) NOT NULL DEFAULT 1,
    pdf_path VARCHAR(255) NULL,
    salaysay TEXT NULL,
    complainant_attended ENUM('Present', 'Absent') DEFAULT 'Absent',
    respondent_attended ENUM('Present', 'Absent') DEFAULT 'Absent',
    session_outcome ENUM('Settled', 'Absent_Retry', 'Continued_Dialogue', 'Impasse_Escalate', 'Max_Failed_Escalate') NULL,
    outcome_notes TEXT NULL,
    scheduled_by INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    escalated_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE,
    KEY idx_paanyaya_sessions_complaint (complaint_id),
    KEY idx_paanyaya_sessions_attempt (attempt_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
