-- Migration: 20261027_001_kp_forms_persistence_and_linkage.sql
-- Description: Establish relational link between KP Form 12 / Officer's Return and initial Conciliation Hearing,
--              expand summon delivery support for all KP Forms, ensure template completeness, and support bidirectional traceability.

-- 1. Ensure KP Form templates exist in document_templates
INSERT INTO document_templates (template_name, description) VALUES
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
    ('KP Form 20-B', 'Katibayan Upang Hadlangan ang Ganting-Sakdal (Certificate to Bar Counterclaim)')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- 2. Modify summon_deliveries to support all KP form types, broader party types, and document linkage
ALTER TABLE summon_deliveries
    MODIFY COLUMN form_type VARCHAR(100) NOT NULL DEFAULT 'Notice of Hearing',
    MODIFY COLUMN party_type VARCHAR(50) NOT NULL DEFAULT 'Respondent',
    MODIFY COLUMN document_id INT UNSIGNED NULL DEFAULT NULL;

-- Add fk_summon_deliveries_document if not present
SET @fk_doc_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'summon_deliveries' AND CONSTRAINT_NAME = 'fk_summon_deliveries_document'
);
SET @sql_doc = IF(@fk_doc_exists = 0,
    'ALTER TABLE summon_deliveries ADD CONSTRAINT fk_summon_deliveries_document FOREIGN KEY (document_id) REFERENCES generated_documents(document_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_doc FROM @sql_doc; EXECUTE stmt_doc; DEALLOCATE PREPARE stmt_doc;

-- Add fk_summon_deliveries_case if not present
SET @fk_case_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'summon_deliveries' AND CONSTRAINT_NAME = 'fk_summon_deliveries_case'
);
SET @sql_case = IF(@fk_case_exists = 0,
    'ALTER TABLE summon_deliveries ADD CONSTRAINT fk_summon_deliveries_case FOREIGN KEY (case_id) REFERENCES cases(case_id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_case FROM @sql_case; EXECUTE stmt_case; DEALLOCATE PREPARE stmt_case;

-- 3. Add kp12_document_id column to hearings
SET @col_kp12 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hearings' AND COLUMN_NAME = 'kp12_document_id'
);
SET @sql_col = IF(@col_kp12 = 0, 'ALTER TABLE hearings ADD COLUMN kp12_document_id INT UNSIGNED NULL DEFAULT NULL AFTER remarks', 'SELECT 1');
PREPARE stmt_col FROM @sql_col; EXECUTE stmt_col; DEALLOCATE PREPARE stmt_col;

-- Add index and foreign key on hearings.kp12_document_id
SET @fk_h_kp12 = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'hearings' AND CONSTRAINT_NAME = 'fk_hearings_kp12_document'
);
SET @sql_h_kp12 = IF(@fk_h_kp12 = 0,
    'ALTER TABLE hearings ADD CONSTRAINT fk_hearings_kp12_document FOREIGN KEY (kp12_document_id) REFERENCES generated_documents(document_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt_h_kp12 FROM @sql_h_kp12; EXECUTE stmt_h_kp12; DEALLOCATE PREPARE stmt_h_kp12;

-- 4. Create or replace bidirectional traceability view for Conciliation hearings & Officer Returns
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
