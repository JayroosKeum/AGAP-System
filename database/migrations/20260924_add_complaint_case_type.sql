-- Apply once to an existing agap_db that does not yet have complaints.case_type.
-- New installations already receive this column from database/schema.sql.
-- The Civil default is the canonical schema default and is applied to existing
-- complaints when the column is added. Linked cases are then used to retain the
-- known case type for complaints that have already been docketed.

ALTER TABLE complaints
    ADD COLUMN case_type ENUM('Civil', 'Criminal') NOT NULL DEFAULT 'Civil'
    AFTER category_id;

UPDATE complaints AS complaint
INNER JOIN cases AS existing_case
    ON existing_case.complaint_id = complaint.complaint_id
SET complaint.case_type = existing_case.case_type
WHERE complaint.case_type <> existing_case.case_type;
