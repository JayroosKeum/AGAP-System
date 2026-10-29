-- =============================================================================
-- Migration: 20261029_002_fix_multi_party_summon_deliveries_unique_index.sql
-- Purpose:
--   Update unique key on summon_deliveries to include resident_id so that cases
--   with multiple complainants, respondents, or witnesses do not cause duplicate
--   key collisions on attempt_number.
-- =============================================================================

SET NAMES utf8mb4;

SET @drop_uq = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE summon_deliveries DROP INDEX uq_hearing_party_form_attempt',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'summon_deliveries'
      AND INDEX_NAME = 'uq_hearing_party_form_attempt'
);
PREPARE stmt_drop FROM @drop_uq;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;

SET @add_uq = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE summon_deliveries ADD UNIQUE KEY uq_hearing_party_form_attempt (hearing_id, party_type, resident_id, form_type, attempt_number)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'summon_deliveries'
      AND INDEX_NAME = 'uq_hearing_party_form_attempt'
);
PREPARE stmt_add FROM @add_uq;
EXECUTE stmt_add;
DEALLOCATE PREPARE stmt_add;

SELECT '[OK] Migration 20261029_002_fix_multi_party_summon_deliveries_unique_index executed successfully.' AS result;
