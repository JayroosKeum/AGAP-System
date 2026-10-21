-- Migration: Add paanyaya outcome columns to complaints table
ALTER TABLE complaints
    ADD COLUMN paanyaya_1_outcome VARCHAR(50) NULL DEFAULT NULL AFTER resolved_without_docketing,
    ADD COLUMN paanyaya_complainant_choice VARCHAR(50) NULL DEFAULT NULL AFTER paanyaya_1_outcome;
