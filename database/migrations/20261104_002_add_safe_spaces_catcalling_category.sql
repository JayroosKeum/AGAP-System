-- =============================================================================
-- Migration: 20261104_002_add_safe_spaces_catcalling_category.sql
-- Purpose:
--   Add Safe Spaces Act (RA 11313) Catcalling/Whistling as a distinct offense
--   requiring mandatory '1st Offense Only (Catcalling/Whistling)' verification;
--   2nd and 3rd offenses route to VAWC / PNP.
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO complaint_categories (category_name, tagalog_name, ltia_code, description, is_active)
VALUES (
    'Safe Spaces Act: Catcalling & Whistling (RA 11313 — 1st Offense Only) / Bawal Bastos: Paninita at Pagsipol (Unang Paglabag Lamang)',
    'Bawal Bastos: Paninita at Pagsipol (Unang Paglabag Lamang)',
    'LTIA-CRIM-SSA',
    'Distinct offense for 1st-offense light gender-based street and public spaces harassment (catcalling, wolf-whistling, persistent unwanted remarks) under R.A. 11313 Sec. 11(a). Mandatory 1st-offense verification required; 2nd and 3rd offenses route directly to VAWC Desk / PNP.',
    1
)
ON DUPLICATE KEY UPDATE
    tagalog_name = VALUES(tagalog_name),
    ltia_code = VALUES(ltia_code),
    description = VALUES(description),
    is_active = 1;
