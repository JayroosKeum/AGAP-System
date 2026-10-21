-- Migration: Add home_latitude and home_longitude to residents and complaint_parties
-- Purpose: Persist exact home pin coordinates so address pins never drift or rely solely on text geocoding

ALTER TABLE residents
    ADD COLUMN home_latitude DECIMAL(10, 8) NULL AFTER address,
    ADD COLUMN home_longitude DECIMAL(11, 8) NULL AFTER home_latitude;

ALTER TABLE complaint_parties
    ADD COLUMN home_latitude DECIMAL(10, 8) NULL AFTER guardian_address,
    ADD COLUMN home_longitude DECIMAL(11, 8) NULL AFTER home_latitude;
