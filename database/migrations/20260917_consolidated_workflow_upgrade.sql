-- One-time consolidated upgrade for an existing agap_db created before the
-- complaint-review, evidence/location, document-service, and case-team work.
-- Run this INSTEAD OF 20260914_core_case_workflow_redesign.sql,
-- 20260916_complaint_incident_details.sql, and 20260917_case_team_roles.sql.
-- For a new installation, use database/schema.sql only.

ALTER TABLE complaints
    ADD COLUMN additional_details LONGTEXT NULL AFTER narrative,
    ADD COLUMN review_notes TEXT NULL AFTER additional_details,
    ADD COLUMN incident_time TIME NULL AFTER incident_date,
    ADD COLUMN incident_location VARCHAR(255) NULL AFTER incident_time,
    ADD COLUMN incident_landmark VARCHAR(255) NULL AFTER incident_location,
    MODIFY COLUMN status ENUM('Filed', 'Under Review', 'Needs Information', 'Accepted', 'Rejected', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived') NOT NULL DEFAULT 'Filed';

ALTER TABLE generated_documents
    ADD COLUMN service_status ENUM('Generated', 'For Service', 'Served', 'Service Failed') NOT NULL DEFAULT 'Generated' AFTER file_path,
    ADD COLUMN regeneration_reason TEXT NULL AFTER service_status;

ALTER TABLE proof_of_service
    ADD COLUMN document_id INT UNSIGNED NULL AFTER case_id,
    ADD KEY idx_proof_of_service_document_id (document_id),
    ADD CONSTRAINT fk_proof_of_service_document FOREIGN KEY (document_id) REFERENCES generated_documents (document_id) ON DELETE SET NULL;

-- Expand the role enum before translating old records to the new case-team roles.
ALTER TABLE case_assignments
    MODIFY COLUMN assignment_role ENUM('Mediator', 'Pangkat Chairman', 'Pangkat Secretary', 'Pangkat Member', 'Head', 'Secretary', 'Member') NOT NULL;

UPDATE case_assignments
SET assignment_role = CASE assignment_role
    WHEN 'Pangkat Chairman' THEN 'Head'
    WHEN 'Pangkat Secretary' THEN 'Secretary'
    WHEN 'Pangkat Member' THEN 'Member'
    ELSE assignment_role
END
WHERE assignment_role IN ('Pangkat Chairman', 'Pangkat Secretary', 'Pangkat Member');

-- Resolve any duplicate case/role rows before applying this constraint.
ALTER TABLE case_assignments
    MODIFY COLUMN assignment_role ENUM('Mediator', 'Head', 'Secretary', 'Member') NOT NULL,
    ADD UNIQUE KEY uq_case_assignments_case_role (case_id, assignment_role);
