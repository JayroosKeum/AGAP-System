-- =========================================================================
-- Migration: 20261021_003_settings_module_and_user_preferences.sql
-- Description: Comprehensive Settings Module tables, columns, and default configurations
-- Author: Senior Full-Stack Architect / AGAP System
-- =========================================================================

-- 1. Extend complaint_categories with Tagalog translations and LTIA codes
-- (Run conditionally if columns do not exist)
SET @exist_cat_tagalog = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_categories' AND COLUMN_NAME = 'tagalog_name');
SET @sql_cat1 = IF(@exist_cat_tagalog = 0, 'ALTER TABLE complaint_categories ADD COLUMN tagalog_name VARCHAR(150) NULL AFTER category_name', 'SELECT 1');
PREPARE stmt1 FROM @sql_cat1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

SET @exist_cat_ltia = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_categories' AND COLUMN_NAME = 'ltia_code');
SET @sql_cat2 = IF(@exist_cat_ltia = 0, 'ALTER TABLE complaint_categories ADD COLUMN ltia_code VARCHAR(50) NULL AFTER tagalog_name', 'SELECT 1');
PREPARE stmt2 FROM @sql_cat2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

SET @exist_cat_active = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_categories' AND COLUMN_NAME = 'is_active');
SET @sql_cat3 = IF(@exist_cat_active = 0, 'ALTER TABLE complaint_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER description', 'SELECT 1');
PREPARE stmt3 FROM @sql_cat3; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;

-- Populate default translations & LTIA tracking codes for standard categories
UPDATE complaint_categories SET category_name = 'Non-Payment of Debt', tagalog_name = 'Hindi Pagbabayad ng Utang', ltia_code = 'LTIA-CIV-01' WHERE category_id = 1;
UPDATE complaint_categories SET category_name = 'Breach of Agreement / Contract', tagalog_name = 'Paglabag sa Kasunduan o Kontrata', ltia_code = 'LTIA-CIV-02' WHERE category_id = 2;
UPDATE complaint_categories SET category_name = 'Physical Injuries / Slight Harm', tagalog_name = 'Mababang Pananakit sa Katawan', ltia_code = 'LTIA-CRIM-01' WHERE category_id = 3;
UPDATE complaint_categories SET category_name = 'Defamation / Slander / Oral Defamation', tagalog_name = 'Paninirang-puri / Tsismis', ltia_code = 'LTIA-CRIM-02' WHERE category_id = 4;
UPDATE complaint_categories SET category_name = 'Threats / Light Threats', tagalog_name = 'Pagbabanta sa Buhay o Ari-arian', ltia_code = 'LTIA-CRIM-03' WHERE category_id = 5;
UPDATE complaint_categories SET category_name = 'Property & Rental Disputes', tagalog_name = 'Alitan sa Ari-arian at Paupahan', ltia_code = 'LTIA-CIV-03' WHERE category_id = 6;
UPDATE complaint_categories SET category_name = 'Disturbance & Public Disorder', tagalog_name = 'Panggugulo sa Kaayusan / Alingasngas', ltia_code = 'LTIA-CRIM-04' WHERE category_id = 7;
UPDATE complaint_categories SET category_name = 'Malicious Mischief / Vandalism', tagalog_name = 'Paninira ng Ari-arian ng Iba', ltia_code = 'LTIA-CRIM-05' WHERE category_id = 8;
UPDATE complaint_categories SET category_name = 'Unlawful Trespassing', tagalog_name = 'Ilegal na Pagpasok sa Tahanan o Lupa', ltia_code = 'LTIA-CRIM-06' WHERE category_id = 9;
UPDATE complaint_categories SET category_name = 'Family & Domestic Disputes', tagalog_name = 'Alitan sa Pamilya at Tahanan', ltia_code = 'LTIA-CIV-04' WHERE category_id = 10;

-- 2. Extend users table for personal preferences & avatar
SET @exist_u_audio = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'notification_audio');
SET @sql_u1 = IF(@exist_u_audio = 0, 'ALTER TABLE users ADD COLUMN notification_audio TINYINT(1) NOT NULL DEFAULT 1 AFTER status', 'SELECT 1');
PREPARE stmt_u1 FROM @sql_u1; EXECUTE stmt_u1; DEALLOCATE PREPARE stmt_u1;

SET @exist_u_inbox = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'notification_inbox');
SET @sql_u2 = IF(@exist_u_inbox = 0, 'ALTER TABLE users ADD COLUMN notification_inbox TINYINT(1) NOT NULL DEFAULT 1 AFTER notification_audio', 'SELECT 1');
PREPARE stmt_u2 FROM @sql_u2; EXECUTE stmt_u2; DEALLOCATE PREPARE stmt_u2;

SET @exist_u_lang = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'preferred_language');
SET @sql_u3 = IF(@exist_u_lang = 0, 'ALTER TABLE users ADD COLUMN preferred_language VARCHAR(10) NOT NULL DEFAULT \'fil\' AFTER notification_inbox', 'SELECT 1');
PREPARE stmt_u3 FROM @sql_u3; EXECUTE stmt_u3; DEALLOCATE PREPARE stmt_u3;

SET @exist_u_avatar = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_path');
SET @sql_u4 = IF(@exist_u_avatar = 0, 'ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER preferred_language', 'SELECT 1');
PREPARE stmt_u4 FROM @sql_u4; EXECUTE stmt_u4; DEALLOCATE PREPARE stmt_u4;

-- 3. Insert / Update comprehensive system_settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    -- Tab 1: Barangay Info & Signatories
    ('barangay_name', 'Barangay Tumana', 'Official name of the local barangay'),
    ('city_name', 'Lungsod ng Marikina', 'City / Municipality of jurisdiction'),
    ('province_name', 'Kalakhang Maynila (NCR)', 'Province or metropolitan jurisdiction'),
    ('barangay_hall_address', 'E. Rodriguez St., Barangay Tumana, Marikina City, Metro Manila', 'Physical office address of the Barangay Hall'),
    ('barangay_contact', '(02) 8942-0123 / 0917-123-4567', 'Official telephone hotline and mobile contact'),
    ('barangay_email', 'tumana.marikina@gmail.com', 'Official government correspondence email'),
    ('captain_name', 'HON. AKIKO LYNNE CENTENO', 'Punong Barangay and Lupon Tagapamayapa Chairman'),
    ('barangay_secretary_name', 'MARY JANE G. RAMOS', 'Barangay Secretary'),
    ('lupon_secretary_name', 'MA. CRISTINA D. SANTOS', 'Lupon Secretary'),
    ('legal_officer_name', 'ATTY. CARLOS M. VALDEZ', 'City Legal Consultant / Barangay Legal Officer'),
    ('barangay_seal_path', '../../assets/images/tumana-logo.png', 'File path or URL for Barangay Tumana Official Seal'),
    ('city_seal_path', '../../assets/images/marikina-logo.png', 'File path or URL for Marikina City Official Seal'),
    ('signature_style', 'wet', 'Default signature style on printed forms: wet (physical ink) or digital (e-signature placeholder)'),

    -- Tab 2: KP Rules & Hearing Logistics
    ('daily_hearing_capacity', '8', 'Maximum total hearings allowed per day across the barangay (max 8)'),
    ('officer_daily_hearing_capacity', '6', 'Maximum hearings per presiding officer per day (5-8)'),
    ('mediation_default_duration_min', '45', 'Default duration for mediation session in minutes (30-60)'),
    ('mediation_max_duration_min', '60', 'Maximum duration before logged justification is required for mediation'),
    ('conciliation_default_duration_min', '60', 'Default duration for conciliation session in minutes (45-90)'),
    ('conciliation_max_duration_min', '90', 'Maximum duration before logged justification is required for conciliation'),
    ('mediation_max_sessions', '2', 'Strict maximum mediation sessions before mandatory elevation to Pangkat (Tumana Policy Cap)'),
    ('conciliation_max_sessions', '3', 'Strict maximum conciliation sessions before mandatory CFA issuance (RA 7160 Sec. 410b)'),
    ('paanyaya_max_attempts', '6', 'Maximum preliminary invitation attempts per stage (Tumana 6-attempt cap)'),
    ('mediation_session_interval_days', '3', 'Recommended minimum calendar days between mediation sessions (3-5)'),
    ('party_reschedule_limit', '2', 'Maximum allowed reschedules per party before authorized review is required (1-2)'),
    ('summons_max_attempts', '3', 'Maximum service attempts before escalation (2-3)'),
    ('summons_attempt_interval_days', '1', 'Minimum days between summons service attempts (1-3)'),
    ('working_hours_start', '08:00', 'Barangay Hall opening operational hour'),
    ('working_hours_end', '17:00', 'Barangay Hall closing operational hour'),
    ('working_days', '["Mon","Tue","Wed","Thu","Fri"]', 'Weekly operational working days for hearing scheduling'),
    ('mediation_day_of_week', 'Tue', 'Dedicated day of week for Punong Barangay mediation hearings (Tuesday Only rule)'),
    ('mediation_time_slots', '["13:00","14:00","15:00","16:00"]', 'Allowed hearing time slots for 1st Mediation (1:00 PM to 4:00 PM)'),
    ('configured_holidays', '[{"date":"2026-01-01","name":"New Year''s Day","type":"Regular Holiday"},{"date":"2026-04-02","name":"Maundy Thursday","type":"Regular Holiday"},{"date":"2026-04-03","name":"Good Friday","type":"Regular Holiday"},{"date":"2026-04-09","name":"Araw ng Kagitingan","type":"Regular Holiday"},{"date":"2026-05-01","name":"Labor Day","type":"Regular Holiday"},{"date":"2026-06-12","name":"Independence Day","type":"Regular Holiday"},{"date":"2026-08-31","name":"National Heroes Day","type":"Regular Holiday"},{"date":"2026-11-30","name":"Bonifacio Day","type":"Regular Holiday"},{"date":"2026-12-08","name":"Feast of the Immaculate Conception","type":"Special Non-Working"},{"date":"2026-12-25","name":"Christmas Day","type":"Regular Holiday"},{"date":"2026-12-30","name":"Rizal Day","type":"Regular Holiday"},{"date":"2026-04-16","name":"Marikina Cityhood Day","type":"Local Holiday"}]', 'JSON catalog of recognized national and Marikina local holidays for deadline exclusion'),

    -- Tab 3: Dispute Categories & Geographic Screening
    ('jurisdiction_max_imprisonment_years', '1', 'Maximum statutory imprisonment threshold (years) for Katarungang Pambarangay cognizance under RA 7160 Sec. 408'),
    ('jurisdiction_max_fine', '5000', 'Maximum statutory fine threshold (PHP) for KP cognizance under RA 7160 Sec. 408'),
    ('jurisdiction_auto_referral_notice', '1', 'Automatically display advisory referral to City Prosecutor when penalty thresholds are exceeded'),
    ('barangay_puroks', '["Purok 1","Purok 2","Purok 3","Purok 4","Purok 5","Purok 6","Singkamas","Tumana Bridge","Palay","Mais","Sampaguita","Kangkongan","Dike Area"]', 'Master list of official zones and puroks in Barangay Tumana'),
    ('default_map_latitude', '14.6644', 'Default map center latitude for Barangay Tumana incident pin positioning'),
    ('default_map_longitude', '121.0967', 'Default map center longitude for Barangay Tumana incident pin positioning'),
    ('default_map_zoom', '15', 'Default Leaflet zoom level for incident mapping in Barangay Tumana'),

    -- Tab 4: KP Documents & Forms
    ('document_paper_size', 'Legal', 'Primary paper size format for Dompdf document generation: Legal (8.5x14 inches) or A4 (8.27x11.69 inches)'),
    ('document_margin_top', '15', 'Page top margin in millimeters for printable documents'),
    ('document_margin_bottom', '15', 'Page bottom margin in millimeters for printable documents'),
    ('document_margin_left', '18', 'Page left margin in millimeters for printable documents'),
    ('document_margin_right', '18', 'Page right margin in millimeters for printable documents'),
    ('auto_serve_complainant_notice', '1', 'Automatically generate and mark Served the KP Form 8 Notice of Hearing for Complainant whenever Respondent Summons is issued'),
    ('summons_sla_working_days', '1', 'Statutory SLA in working days to issue summons following formal docketing (RA 7160 Sec. 410)'),
    ('summons_delivery_warning_days', '3', 'Advisory SLA threshold in working days to alert officers if summons remains unserved'),
    ('kp_header_title', 'REPUBLIKA NG PILIPINAS', 'Top legal header banner line on official KP notices and forms'),
    ('kp_lgu_header', 'LALAWIGAN NG RIZAL / LUNGSOD NG MARIKINA / BARANGAY TUMANA', 'Sub-header displaying local government hierarchy on official forms'),

    -- Tab 5: AI & External Services
    ('gemini_api_key', '', 'Google Gemini API key for automated legal assistant, narrative refinement, and document summaries'),
    ('gemini_model', 'gemini-1.5-flash', 'Active Gemini AI model identifier for processing queries'),
    ('ai_narrative_refinement_enabled', '1', 'Enable AI Salaysay Assistant to format raw complaint narrative into coherent legal facts'),
    ('ai_ocr_processing_enabled', '1', 'Enable AI OCR to auto-extract text from handwritten scanned KP Form 7 uploads'),
    ('ai_case_summaries_enabled', '1', 'Enable AI live dossier case briefing synthesis'),
    ('ai_rate_limit_per_minute', '15', 'Maximum AI API requests permitted per minute to prevent quota exhaustion'),
    ('sms_provider', 'Semaphore', 'SMS Gateway provider: Semaphore, Twilio, or Disabled'),
    ('sms_api_key', '', 'API Key or Auth Token for SMS Gateway service'),
    ('sms_sender_name', 'BRGY_TUMANA', 'Registered alphanumeric Sender ID for outbound SMS dispatches'),
    ('sms_paanyaya_template', 'Magandang araw. Mula sa Tanggapan ng Lupong Tagapamayapa ng Barangay Tumana: Kayo po ay inaanyayahan sa isang dialogue ukol sa Usaping Blg. {case_number} sa darating na {date} ganap na {time}. Salamat po.', 'SMS message template for informal Paanyaya dialogue invitations'),
    ('sms_hearing_template', 'Pabatid mula sa Lupon ng Brgy. Tumana: Ang pagdinig para sa Usaping {case_number} ay gaganapin sa {date} sa ganap na {time} sa Lupon Court Room.', 'SMS message template for formal Mediation and Conciliation hearing notices'),
    ('map_tile_provider', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', 'Tile server URL template for Leaflet OpenStreetMap layers'),

    -- Tab 6: Security, Backups & Maintenance
    ('session_inactivity_timeout_min', '30', 'Auto-logout idle sessions after specified inactive duration in minutes'),
    ('password_expiration_days', '0', 'Force users to rotate passwords after specified days (0 = disabled)'),
    ('max_failed_login_attempts', '5', 'Number of consecutive invalid password attempts before temporary account lockout'),
    ('lockout_duration_min', '15', 'Account lockout cooling-off duration in minutes')
ON DUPLICATE KEY UPDATE 
    description = VALUES(description);
