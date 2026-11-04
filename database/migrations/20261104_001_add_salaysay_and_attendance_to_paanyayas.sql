-- Migration: Add Salaysay and Attendance Tracking columns to paanyayas table
-- Date: 2026-11-04
-- Target: MySQL 8.0+ / Azure Database for MySQL

ALTER TABLE `paanyayas`
  MODIFY COLUMN `status` ENUM('Scheduled','Completed','Cancelled','Resolved','Unresolved','Escalated') NOT NULL DEFAULT 'Scheduled',
  ADD COLUMN `salaysay` TEXT NULL AFTER `venue`,
  ADD COLUMN `complainant_attended` ENUM('Present', 'Absent') NULL AFTER `salaysay`,
  ADD COLUMN `invitee_attended` ENUM('Present', 'Absent') NULL AFTER `complainant_attended`,
  ADD COLUMN `dialogue_notes` TEXT NULL AFTER `invitee_attended`,
  ADD COLUMN `attendance_recorded_at` DATETIME NULL AFTER `dialogue_notes`,
  ADD COLUMN `escalated_to_complaint_id` INT UNSIGNED NULL AFTER `attendance_recorded_at`;
