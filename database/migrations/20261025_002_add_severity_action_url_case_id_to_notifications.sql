-- Migration: 20261025_002_add_severity_action_url_case_id_to_notifications.sql
-- Description: Adds severity, action_url, and case_id to notifications table to align with database/schema.sql and Notification model

ALTER TABLE `notifications`
    ADD COLUMN `severity` ENUM('urgent', 'warning', 'info', 'success') NOT NULL DEFAULT 'info' AFTER `title`,
    ADD COLUMN `action_url` VARCHAR(255) NULL AFTER `message`,
    ADD COLUMN `case_id` INT UNSIGNED NULL AFTER `action_url`,
    ADD KEY `idx_notifications_user_read_created` (`user_id`, `is_read`, `created_at`);
