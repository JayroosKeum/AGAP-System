-- Adds structured incident-address fields for databases created before 2026-09-25.
-- Run once only after taking a backup. Fresh installations already receive these
-- fields from database/schema.sql.
USE agap_db;

ALTER TABLE complaints
    ADD COLUMN incident_city VARCHAR(100) NOT NULL DEFAULT 'Marikina City' AFTER incident_location,
    ADD COLUMN incident_barangay VARCHAR(100) NOT NULL DEFAULT 'Tumana' AFTER incident_city,
    ADD COLUMN incident_street VARCHAR(255) NULL AFTER incident_barangay,
    ADD COLUMN incident_purok VARCHAR(100) NULL AFTER incident_street;

-- Preserve legacy location data as the street component; do not alter the
-- existing incident_location value because existing reports may use it.
UPDATE complaints
SET incident_street = incident_location
WHERE incident_street IS NULL AND incident_location IS NOT NULL;
