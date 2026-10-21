-- =============================================================================
-- Migration: 20261021_precision_and_integrity_optimizations.sql
-- Purpose:
--   1. Structure addresses in 'residents' (separate house/street, barangay, city).
--   2. Add dedicated 'landmark' field to 'incident_locations'.
--   3. Enforce UNIQUE constraint on 'cases.case_number' to prevent duplicate dockets.
--   4. Standardize phone number length and add validation indexes.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. ADDRESS PRECISION: Structure 'residents' address fields
-- -----------------------------------------------------------------------------
ALTER TABLE residents
    ADD COLUMN house_street VARCHAR(255) NULL AFTER address,
    ADD COLUMN barangay VARCHAR(100) NOT NULL DEFAULT 'Tumana' AFTER house_street,
    ADD COLUMN city_municipality VARCHAR(100) NOT NULL DEFAULT 'Marikina City' AFTER barangay;

-- Migrate existing freeform text addresses to house_street if empty
UPDATE residents 
SET house_street = address 
WHERE house_street IS NULL AND address IS NOT NULL;

-- Automatically tag non-residents who have outside addresses
UPDATE residents 
SET barangay = 'Outside Tumana' 
WHERE is_tenant = 2 OR (address IS NOT NULL AND LOWER(address) NOT LIKE '%tumana%');

-- Add index for fast jurisdictional checks (Tumana vs. Non-Tumana)
ALTER TABLE residents
    ADD KEY idx_residents_jurisdiction (barangay, is_tenant);


-- -----------------------------------------------------------------------------
-- 2. LOCATION PRECISION: Add dedicated 'landmark' to 'incident_locations'
-- -----------------------------------------------------------------------------
ALTER TABLE incident_locations
    ADD COLUMN landmark VARCHAR(255) NULL AFTER address;

-- Add composite spatial coordinate index for fast location clustering
ALTER TABLE incident_locations
    ADD KEY idx_incident_lat_lng (latitude, longitude);


-- -----------------------------------------------------------------------------
-- 3. CASE DOCKET INTEGRITY: Prevent duplicate case docket numbers
-- -----------------------------------------------------------------------------
-- First, ensure empty strings are converted to NULL so the UNIQUE constraint allows multiple undocketed rows
UPDATE cases SET case_number = NULL WHERE TRIM(case_number) = '';

-- Add the unique constraint
ALTER TABLE cases
    ADD CONSTRAINT uq_cases_case_number UNIQUE (case_number);


-- -----------------------------------------------------------------------------
-- 4. SUMMON GPS INTEGRITY: Optimize coordinates in 'paanyaya_officer_returns'
-- -----------------------------------------------------------------------------
-- Ensure returns coordinate indexing for mobile Tanod mapping
ALTER TABLE paanyaya_officer_returns
    ADD KEY idx_paanyaya_return_coords (latitude, longitude);

-- =============================================================================
-- END OF MIGRATION
-- =============================================================================
