-- Run this read-only preflight against an existing agap_db before adding the
-- normalized constraints from schema.sql. Every result set must be empty.

USE agap_db;

-- Duplicate values that would violate new one-to-one or uniqueness rules.
SELECT complaint_id, COUNT(*) AS case_count
FROM cases GROUP BY complaint_id HAVING COUNT(*) > 1;

SELECT complaint_id, resident_id, party_type, COUNT(*) AS duplicate_count
FROM complaint_parties GROUP BY complaint_id, resident_id, party_type HAVING COUNT(*) > 1;

SELECT case_id, COUNT(*) AS pangkat_count
FROM pangkat_groups GROUP BY case_id HAVING COUNT(*) > 1;

SELECT pangkat_id, member_id, COUNT(*) AS duplicate_count
FROM pangkat_members GROUP BY pangkat_id, member_id HAVING COUNT(*) > 1;

SELECT hearing_id, resident_id, COUNT(*) AS duplicate_count
FROM hearing_attendance GROUP BY hearing_id, resident_id HAVING COUNT(*) > 1;

SELECT case_id, COUNT(*) AS settlement_count
FROM settlements GROUP BY case_id HAVING COUNT(*) > 1;

SELECT case_id, COUNT(*) AS arbitration_count
FROM arbitration_records GROUP BY case_id HAVING COUNT(*) > 1;

SELECT case_id, COUNT(*) AS cfa_count
FROM cfa_records GROUP BY case_id HAVING COUNT(*) > 1;

SELECT complaint_id, COUNT(*) AS location_count
FROM incident_locations GROUP BY complaint_id HAVING COUNT(*) > 1;

-- Orphan checks for relationships that are now mandatory.
SELECT c.complaint_id
FROM complaints c LEFT JOIN complaint_categories cc ON cc.category_id = c.category_id
WHERE cc.category_id IS NULL;

SELECT c.case_id
FROM cases c LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
WHERE co.complaint_id IS NULL;

SELECT cp.party_id
FROM complaint_parties cp
LEFT JOIN complaints c ON c.complaint_id = cp.complaint_id
LEFT JOIN residents r ON r.resident_id = cp.resident_id
WHERE c.complaint_id IS NULL OR r.resident_id IS NULL;

SELECT pm.id AS legacy_pangkat_member_id
FROM pangkat_members pm
LEFT JOIN pangkat_groups pg ON pg.pangkat_id = pm.pangkat_id
LEFT JOIN users u ON u.user_id = pm.member_id
WHERE pg.pangkat_id IS NULL OR u.user_id IS NULL;
