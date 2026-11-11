-- Migration: KP Form Lifecycle Automation Support
-- Date: 2026-11-10
-- Notes: Idempotent schema additions for pre-docket KP Form 7 storage and complete KP Form 7-25 templates.

SET @db_name = DATABASE();

SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'complaints'
      AND COLUMN_NAME = 'generated_kp7_path'
);
SET @sql = IF(
    @col_exists = 0,
    'ALTER TABLE complaints ADD COLUMN generated_kp7_path VARCHAR(1024) NULL AFTER complaint_number',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO document_templates (template_name, description) VALUES
    ('KP Form 7', 'Sumbong (Complainant''s Complaint)'),
    ('KP Form 8', 'Paunawa ng Pagdinig (Notice of Hearing - Complainant)'),
    ('KP Form 9', 'Patawag (Summons - Respondent)'),
    ('KP Form 10', 'Paabiso sa Pagbubuo ng Pangkat (Notice to Constitute the Pangkat)'),
    ('KP Form 11', 'Patalastas sa Piniling Miyembro ng Pangkat (Notice to Chosen Pangkat Members)'),
    ('KP Form 12', 'Paabiso ng Pagdinig sa Harap ng Pangkat (Notice of Hearing for Conciliation)'),
    ('KP Form 13', 'Subpoena (Paanyaya sa mga Saksi)'),
    ('KP Form 14', 'Kasunduan sa Pag-aarbitro (Agreement for Arbitration)'),
    ('KP Form 15', 'Gawad ng Arbitrasyon (Arbitration Award)'),
    ('KP Form 16', 'Kasunduang Pag-aayos (Amicable Settlement)'),
    ('KP Form 17', 'Pagtatwa ng Kasunduan (Repudiation of Settlement)'),
    ('KP Form 18', 'Paabiso ng Pagdinig para sa May-Sumbong (Failure to Appear - Complainant)'),
    ('KP Form 19', 'Paabiso ng Pagdinig para sa Ipinagsusumbong (Failure to Appear - Respondent)'),
    ('KP Form 20', 'Katibayan Upang Makadulog sa Hukuman (Certificate to File Action)'),
    ('KP Form 20-A', 'Katibayan Upang Hadlangan ang Paghahain ng Aksyon (Certificate to Bar Action)'),
    ('KP Form 20-B', 'Katibayan Upang Hadlangan ang Ganting-Sakdal (Certificate to Bar Counterclaim)'),
    ('KP Form 21', 'Katibayan Upang Hadlangan ang Paghahain ng Aksyon (Certificate to Bar Action)'),
    ('KP Form 22', 'Katibayan Upang Hadlangan ang Ganting-Sakdal (Certificate to Bar Counterclaim)'),
    ('KP Form 23', 'Mosyon sa Pagpapatupad (Motion for Execution)'),
    ('KP Form 24', 'Paabiso ng Pagdinig ukol sa Pagpapatupad (Notice of Hearing - Execution)'),
    ('KP Form 25', 'Paabiso ng Pagpapatupad (Notice of Execution)')
ON DUPLICATE KEY UPDATE description = VALUES(description);
