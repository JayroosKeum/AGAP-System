-- Adds service_result column to proof_of_service for tracking service attempt outcomes
-- (e.g., 'Served', 'Respondent Not Found', 'Refused to Receive').
-- database/schema.sql already has this column in its canonical schema definition.
USE agap_db;

ALTER TABLE proof_of_service
    ADD COLUMN service_result VARCHAR(50) NOT NULL DEFAULT 'Served'
    AFTER document_id;
