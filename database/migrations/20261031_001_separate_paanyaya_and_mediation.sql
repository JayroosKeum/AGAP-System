-- Migration: 20261031_001_separate_paanyaya_and_mediation.sql
-- Description: Separate Paanyaya and Mediation as distinct entities with independent workflows.
--              Remove Officer's Return from Paanyaya.
--              Paanyaya entity holds only Name, Date, Time (and status/reference to Mediation).
--              Complaint details remain strictly under Mediation.

-- 1. Create dedicated independent paanyayas table
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

-- 2. Ensure invitee_name column exists in legacy complaint_paanyaya, paanyaya_stages, and paanyaya_sessions
SET @dbname = DATABASE();

-- For complaint_paanyaya: add invitee_name, remove salaysay
SET @pre_stmt = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'complaint_paanyaya' AND COLUMN_NAME = 'invitee_name'),
    'SELECT 1',
    'ALTER TABLE complaint_paanyaya ADD COLUMN invitee_name VARCHAR(255) NULL AFTER complaint_id'
));
PREPARE stmt FROM @pre_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Populate invitee_name in complaint_paanyaya from respondent party if empty
UPDATE complaint_paanyaya cp
JOIN (
    SELECT cpa.complaint_id, GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ') AS respondent_names
    FROM complaint_parties cpa
    JOIN residents r ON r.resident_id = cpa.resident_id
    WHERE cpa.party_type = 'Respondent'
    GROUP BY cpa.complaint_id
) resp ON resp.complaint_id = cp.complaint_id
SET cp.invitee_name = resp.respondent_names
WHERE cp.invitee_name IS NULL OR cp.invitee_name = '';

-- Populate paanyayas from complaint_paanyaya
INSERT INTO paanyayas (complaint_id, invitee_name, scheduled_date, scheduled_time, status, venue, pdf_path, scheduled_by, created_at)
SELECT 
    cp.complaint_id,
    COALESCE(NULLIF(cp.invitee_name, ''), 'Invited Resident'),
    cp.invitation_date,
    cp.invitation_time,
    CASE 
        WHEN cp.status = 'Resolved' THEN 'Resolved'
        WHEN cp.status = 'Failed' THEN 'Unresolved'
        WHEN cp.status = 'Escalated' THEN 'Completed'
        ELSE 'Scheduled'
    END,
    COALESCE(cp.venue, 'Tanggapan ng Barangay Tumana'),
    cp.pdf_path,
    cp.scheduled_by,
    cp.created_at
FROM complaint_paanyaya cp
WHERE NOT EXISTS (
    SELECT 1 FROM paanyayas p WHERE p.complaint_id = cp.complaint_id AND p.scheduled_date = cp.invitation_date
);

-- For paanyaya_stages: add invitee_name
SET @pre_stmt = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'paanyaya_stages' AND COLUMN_NAME = 'invitee_name'),
    'SELECT 1',
    'ALTER TABLE paanyaya_stages ADD COLUMN invitee_name VARCHAR(255) NULL AFTER stage_type'
));
PREPARE stmt FROM @pre_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE paanyaya_stages ps
JOIN (
    SELECT cpa.complaint_id, GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ') AS respondent_names
    FROM complaint_parties cpa
    JOIN residents r ON r.resident_id = cpa.resident_id
    WHERE cpa.party_type = 'Respondent'
    GROUP BY cpa.complaint_id
) resp ON resp.complaint_id = ps.complaint_id
SET ps.invitee_name = resp.respondent_names
WHERE ps.invitee_name IS NULL OR ps.invitee_name = '';

-- For paanyaya_sessions: add invitee_name
SET @pre_stmt = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'paanyaya_sessions' AND COLUMN_NAME = 'invitee_name'),
    'SELECT 1',
    'ALTER TABLE paanyaya_sessions ADD COLUMN invitee_name VARCHAR(255) NULL AFTER attempt_number'
));
PREPARE stmt FROM @pre_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE paanyaya_sessions ps
JOIN (
    SELECT cpa.complaint_id, GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ') AS respondent_names
    FROM complaint_parties cpa
    JOIN residents r ON r.resident_id = cpa.resident_id
    WHERE cpa.party_type = 'Respondent'
    GROUP BY cpa.complaint_id
) resp ON resp.complaint_id = ps.complaint_id
SET ps.invitee_name = resp.respondent_names
WHERE ps.invitee_name IS NULL OR ps.invitee_name = '';

-- 3. Remove Officer's Return from Paanyaya: drop paanyaya_officer_returns table
DROP TABLE IF EXISTS paanyaya_officer_returns;

-- 4. Clean up columns that stored salaysay / complaint details in Paanyaya tables
-- Null out or drop salaysay from paanyaya_stages
SET @pre_stmt = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'paanyaya_stages' AND COLUMN_NAME = 'salaysay'),
    'ALTER TABLE paanyaya_stages DROP COLUMN salaysay',
    'SELECT 1'
));
PREPARE stmt FROM @pre_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Null out or drop salaysay from complaint_paanyaya
SET @pre_stmt = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'complaint_paanyaya' AND COLUMN_NAME = 'salaysay'),
    'ALTER TABLE complaint_paanyaya DROP COLUMN salaysay',
    'SELECT 1'
));
PREPARE stmt FROM @pre_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Null out or drop salaysay from paanyaya_sessions
SET @pre_stmt = (SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'paanyaya_sessions' AND COLUMN_NAME = 'salaysay'),
    'ALTER TABLE paanyaya_sessions DROP COLUMN salaysay',
    'SELECT 1'
));
PREPARE stmt FROM @pre_stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
