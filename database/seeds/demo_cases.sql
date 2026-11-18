-- Clean demo dataset for local defense/presentation only.
-- Do not run on a production database.
USE agap_db;

SET @clerk_id = (SELECT user_id FROM users WHERE username = 'clerk' LIMIT 1);
SET @cat_id = (SELECT category_id FROM complaint_categories ORDER BY category_id ASC LIMIT 1);

-- 1. Anonymized demo residents
INSERT INTO residents (first_name, middle_name, last_name, gender, civil_status, occupation, contact_no, email, address, house_street, barangay, city_municipality, residency_verified)
SELECT 'Juan', 'M.', 'Dela Cruz', 'Male', 'Married', 'Vendor', '09171234567', 'juan.demo@agap.test', 'Block 4 Lot 12, Tumana, Marikina City', 'Block 4 Lot 12', 'Tumana', 'Marikina City', 1
WHERE NOT EXISTS (SELECT 1 FROM residents WHERE email = 'juan.demo@agap.test');

INSERT INTO residents (first_name, middle_name, last_name, gender, civil_status, occupation, contact_no, email, address, house_street, barangay, city_municipality, residency_verified)
SELECT 'Maria', 'S.', 'Santos', 'Female', 'Single', 'Clerk', '09187654321', 'maria.demo@agap.test', 'Block 7 Lot 3, Tumana, Marikina City', 'Block 7 Lot 3', 'Tumana', 'Marikina City', 1
WHERE NOT EXISTS (SELECT 1 FROM residents WHERE email = 'maria.demo@agap.test');

SET @res_juan = (SELECT resident_id FROM residents WHERE email = 'juan.demo@agap.test' LIMIT 1);
SET @res_maria = (SELECT resident_id FROM residents WHERE email = 'maria.demo@agap.test' LIMIT 1);

-- 2. Demo Complaint #1: Pre-Docketed (Paanyaya)
INSERT INTO complaints (
    complaint_number, intake_reference, category_id, case_type, complaint_title,
    incident_date, narrative, intake_source, submitted_by, screening_decision, status
)
SELECT
    'DEMO-CMP-001', 'CMP-2026-9001', @cat_id, 'Civil', 'Unpaid Personal Loan (Demo)',
    CURDATE(), 'Respondent borrowed PHP 15,000 for emergency expenses and has not settled despite reminders.',
    'Walk-in', @clerk_id, 'Eligible', 'Pre-Docketed (Paanyaya)'
WHERE NOT EXISTS (SELECT 1 FROM complaints WHERE intake_reference = 'CMP-2026-9001');

SET @cmp1 = (SELECT complaint_id FROM complaints WHERE intake_reference = 'CMP-2026-9001' LIMIT 1);

INSERT IGNORE INTO complaint_parties (complaint_id, resident_id, party_type)
VALUES (@cmp1, @res_juan, 'Complainant'), (@cmp1, @res_maria, 'Respondent');

-- 3. Demo Complaint #2: Docketed in Mediation
INSERT INTO complaints (
    complaint_number, intake_reference, category_id, case_type, complaint_title,
    incident_date, narrative, intake_source, submitted_by, screening_decision, docketed_by, docketed_at, status
)
SELECT
    'DEMO-CMP-002', 'CMP-2026-9002', @cat_id, 'Civil', 'Boundary Fence Dispute (Demo)',
    DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Neighboring fence encroached by approximately 0.5 meters along shared lot line.',
    'Walk-in', @clerk_id, 'Eligible', @clerk_id, NOW(), 'Docketed'
WHERE NOT EXISTS (SELECT 1 FROM complaints WHERE intake_reference = 'CMP-2026-9002');

SET @cmp2 = (SELECT complaint_id FROM complaints WHERE intake_reference = 'CMP-2026-9002' LIMIT 1);

INSERT IGNORE INTO complaint_parties (complaint_id, resident_id, party_type)
VALUES (@cmp2, @res_maria, 'Complainant'), (@cmp2, @res_juan, 'Respondent');

INSERT INTO cases (complaint_id, case_number, case_type, case_status, docket_date)
SELECT @cmp2, '11-18-2026-DEMO', 'Civil', 'Mediation', CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM cases WHERE case_number = '11-18-2026-DEMO');
