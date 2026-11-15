-- Order 4A & 4B: Signed settlement, arbitration agreement/award, finality, execution, and court packet linking.

-- 1. settlements: track formal signing/attestation state and linked KP Form 16 document
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settlements' AND COLUMN_NAME = 'settlement_status'
), 'SELECT 1', 'ALTER TABLE settlements
    ADD settlement_status ENUM(''Draft'', ''Signed and Attested'', ''Legacy Review Required'') NOT NULL DEFAULT ''Draft'' AFTER compliance_status,
    ADD document_id INT UNSIGNED NULL AFTER settlement_status,
    ADD KEY idx_settlements_document_id (document_id)');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

UPDATE settlements
SET settlement_status = 'Signed and Attested'
WHERE complainant_signed = 1
  AND respondent_signed = 1
  AND pb_attested = 1
  AND settlement_status = 'Draft';

UPDATE settlements
SET settlement_status = 'Legacy Review Required'
WHERE (complainant_signed = 0 OR respondent_signed = 0 OR pb_attested = 0)
  AND settlement_status = 'Draft';

-- 2. arbitration_records: track written agreement signing, 5-day repudiation, award status, documents, and deadlines
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'arbitration_records' AND COLUMN_NAME = 'award_status'
), 'SELECT 1', 'ALTER TABLE arbitration_records
    ADD agreement_signed TINYINT(1) NOT NULL DEFAULT 0 AFTER agreement_date,
    ADD agreement_repudiation_status ENUM(''None'', ''Within Repudiation Period'', ''Expired'', ''Repudiated'') NOT NULL DEFAULT ''None'' AFTER agreement_signed,
    ADD agreement_repudiated_by INT UNSIGNED NULL AFTER agreement_repudiation_status,
    ADD agreement_repudiated_at DATETIME NULL AFTER agreement_repudiated_by,
    ADD agreement_repudiation_reason TEXT NULL AFTER agreement_repudiated_at,
    ADD agreement_document_id INT UNSIGNED NULL AFTER agreement_repudiation_reason,
    ADD award_document_id INT UNSIGNED NULL AFTER award_details,
    ADD award_status ENUM(''Pending'', ''Finalized'', ''Legacy Review Required'', ''Violated'') NOT NULL DEFAULT ''Pending'' AFTER award_document_id,
    ADD arbitrator_name VARCHAR(255) NULL AFTER award_status,
    ADD issued_by INT UNSIGNED NULL AFTER arbitrator_name,
    ADD issued_at DATETIME NULL AFTER issued_by,
    ADD finalized_at DATETIME NULL AFTER issued_at,
    ADD repudiation_deadline DATE NULL AFTER finalized_at,
    ADD award_deadline DATE NULL AFTER repudiation_deadline,
    ADD six_month_deadline DATE NULL AFTER award_deadline,
    ADD KEY idx_arb_award_doc (award_document_id),
    ADD KEY idx_arb_agreement_doc (agreement_document_id)');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

UPDATE arbitration_records
SET agreement_signed = 1
WHERE agreement_date IS NOT NULL
  AND agreement_signed = 0;

UPDATE arbitration_records
SET award_status = 'Finalized',
    finalized_at = COALESCE(finalized_at, updated_at, created_at)
WHERE award_date IS NOT NULL
  AND TRIM(COALESCE(award_details, '')) <> ''
  AND award_status = 'Pending';

UPDATE arbitration_records
SET award_status = 'Legacy Review Required'
WHERE award_date IS NOT NULL
  AND TRIM(COALESCE(award_details, '')) = ''
  AND award_status = 'Pending';

-- 3. case_transmittals: link exact source instrument/document, allow Cancelled, and preserve superseded packets
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_transmittals'
      AND COLUMN_NAME = 'status' AND COLUMN_TYPE NOT LIKE '%Cancelled%'
), 'ALTER TABLE case_transmittals MODIFY status
    ENUM(''Pending'', ''Generated'', ''Transmitted'', ''Acknowledged'', ''Cancelled'') NOT NULL DEFAULT ''Pending''', 'SELECT 1');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_transmittals' AND COLUMN_NAME = 'source_type'
), 'SELECT 1', 'ALTER TABLE case_transmittals
    ADD source_type ENUM(''Settlement'', ''Arbitration Award'', ''CFA'') NULL AFTER resolution_type,
    ADD source_record_id INT UNSIGNED NULL AFTER source_type,
    ADD source_document_id INT UNSIGNED NULL AFTER source_record_id,
    ADD superseded_at DATETIME NULL AFTER receiving_copy_path,
    ADD KEY idx_ct_source (source_type, source_record_id)');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

-- 4. execution_proceedings: allow linking to either a settlement or a finalized arbitration award
SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'execution_proceedings' AND COLUMN_NAME = 'arbitration_id'
), 'SELECT 1', 'ALTER TABLE execution_proceedings
    MODIFY settlement_id INT UNSIGNED NULL,
    ADD arbitration_id INT UNSIGNED NULL AFTER settlement_id,
    ADD KEY idx_exec_arbitration (arbitration_id)');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;
