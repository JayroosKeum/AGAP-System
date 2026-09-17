-- Apply once after 20260914_core_case_workflow_redesign.sql to an existing agap_db.
-- database/schema.sql remains the canonical definition for fresh installations.
ALTER TABLE complaints
    ADD COLUMN incident_time TIME NULL AFTER incident_date,
    ADD COLUMN incident_location VARCHAR(255) NULL AFTER incident_time,
    ADD COLUMN incident_landmark VARCHAR(255) NULL AFTER incident_location;
