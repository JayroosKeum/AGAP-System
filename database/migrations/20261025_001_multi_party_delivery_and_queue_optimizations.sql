-- Migration: 20261025_001_multi_party_delivery_and_queue_optimizations.sql
-- Description: Updates unique indexes on summon_deliveries to support multi-party complaints (multiple respondents/complainants)
--              and prevents duplicate key collisions during hearing rescheduling and service initialization.

ALTER TABLE `summon_deliveries`
    DROP INDEX `uq_summon_delivery_hearing_recipient`,
    ADD UNIQUE KEY `uq_summon_delivery_hearing_recipient` (`hearing_id`, `party_type`, `resident_id`);

ALTER TABLE `summon_deliveries`
    DROP INDEX `uq_hearing_party_form`,
    ADD UNIQUE KEY `uq_hearing_party_form` (`hearing_id`, `party_type`, `resident_id`, `form_type`);
