-- Migration: Add Salaysay and Attendance Tracking columns to paanyayas table
-- Date: 2026-11-04

ALTER TABLE `paanyayas`
  MODIFY COLUMN `status` ENUM('Scheduled','Completed','Cancelled','Resolved','Unresolved','Escalated') NOT NULL DEFAULT 'Scheduled',
  ADD COLUMN IF NOT EXISTS `salaysay` TEXT NULL AFTER `venue`,
  ADD COLUMN IF NOT EXISTS `complainant_attended` ENUM('Present', 'Late', 'Absent') NULL AFTER `salaysay`,
  ADD COLUMN IF NOT EXISTS `invitee_attended` ENUM('Present', 'Late', 'Absent') NULL AFTER `complainant_attended`,
  ADD COLUMN IF NOT EXISTS `dialogue_notes` TEXT NULL AFTER `invitee_attended`,
  ADD COLUMN IF NOT EXISTS `attendance_recorded_at` DATETIME NULL AFTER `dialogue_notes`,
  ADD COLUMN IF NOT EXISTS `escalated_to_complaint_id` INT UNSIGNED NULL AFTER `attendance_recorded_at`;
