-- Migration: Allow 'Case Register' report type in generated_reports
-- Date: 2026-10-26

ALTER TABLE generated_reports MODIFY COLUMN report_type VARCHAR(50) NOT NULL;
