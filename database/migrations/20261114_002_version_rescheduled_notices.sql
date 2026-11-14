SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyaya_preparations' AND COLUMN_NAME = 'document_version'
), 'SELECT 1', 'ALTER TABLE paanyaya_preparations ADD document_version CHAR(64) NULL AFTER document_pdf');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

CREATE TABLE IF NOT EXISTS paanyaya_invitation_versions (
    complaint_id INT UNSIGNED NOT NULL,
    invitation_number TINYINT UNSIGNED NOT NULL,
    document_version CHAR(64) NOT NULL,
    appointment_at DATETIME NOT NULL,
    venue VARCHAR(255) NOT NULL,
    document_pdf MEDIUMBLOB NOT NULL,
    PRIMARY KEY (complaint_id, invitation_number, document_version),
    CONSTRAINT fk_paanyaya_version_preparation FOREIGN KEY (complaint_id, invitation_number)
        REFERENCES paanyaya_preparations (complaint_id, invitation_number) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hearing_reschedule_notices (
    document_id INT UNSIGNED PRIMARY KEY,
    form_data JSON NOT NULL,
    CONSTRAINT fk_reschedule_notice_document FOREIGN KEY (document_id)
        REFERENCES generated_documents (document_id) ON DELETE CASCADE
) ENGINE=InnoDB;
