-- Apply once to an existing agap_db. The canonical definition remains schema.sql.
ALTER TABLE complaints
    ADD COLUMN additional_details LONGTEXT NULL AFTER narrative,
    ADD COLUMN review_notes TEXT NULL AFTER additional_details,
    MODIFY COLUMN status ENUM('Filed', 'Under Review', 'Needs Information', 'Accepted', 'Rejected', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived') NOT NULL DEFAULT 'Filed';

ALTER TABLE generated_documents
    ADD COLUMN service_status ENUM('Generated', 'For Service', 'Served', 'Service Failed') NOT NULL DEFAULT 'Generated' AFTER file_path,
    ADD COLUMN regeneration_reason TEXT NULL AFTER service_status;

ALTER TABLE proof_of_service
    ADD COLUMN document_id INT UNSIGNED NULL AFTER case_id,
    ADD KEY idx_proof_of_service_document_id (document_id),
    ADD CONSTRAINT fk_proof_of_service_document FOREIGN KEY (document_id) REFERENCES generated_documents (document_id) ON DELETE SET NULL;

-- Resolve any existing duplicate role rows per case before applying this unique key.
ALTER TABLE case_assignments
    ADD UNIQUE KEY uq_case_assignments_case_role (case_id, assignment_role);
