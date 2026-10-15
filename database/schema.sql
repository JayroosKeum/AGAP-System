-- ============================================================================
-- AGAP-Remastered Canonical Database Schema
-- Database: remastered_db
-- Generated: 2026-10-15 05:41:27
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `remastered_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `remastered_db`;

-- ----------------------------------------------------------------------------
-- Table structure for `complaint_attachments`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `complaint_attachments`;
CREATE TABLE `complaint_attachments` (
  `attachment_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint unsigned NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL DEFAULT '0',
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_complaint_attachments_complaint` (`complaint_id`),
  CONSTRAINT `fk_complaint_attachments_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table structure for `complaint_categories`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `complaint_categories`;
CREATE TABLE `complaint_categories` (
  `category_id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_complaint_categories_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table structure for `complaint_history`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `complaint_history`;
CREATE TABLE `complaint_history` (
  `complaint_history_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint unsigned NOT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` longtext COLLATE utf8mb4_unicode_ci,
  `acted_by` int unsigned NOT NULL,
  `acted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_history_id`),
  KEY `idx_complaint_history_complaint` (`complaint_id`,`acted_at`),
  KEY `fk_complaint_history_actor` (`acted_by`),
  CONSTRAINT `fk_complaint_history_actor` FOREIGN KEY (`acted_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_complaint_history_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table structure for `complaint_parties`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `complaint_parties`;
CREATE TABLE `complaint_parties` (
  `complaint_party_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint unsigned NOT NULL,
  `party_type` enum('Complainant','Respondent','Witness') COLLATE utf8mb4_unicode_ci NOT NULL,
  `profile_id` bigint unsigned DEFAULT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_line` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purok_sitio` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_manual` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_party_id`),
  KEY `idx_complaint_parties_complaint` (`complaint_id`,`party_type`),
  KEY `idx_complaint_parties_profile` (`profile_id`),
  CONSTRAINT `fk_complaint_parties_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_complaint_parties_profile` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`profile_id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table structure for `complaints`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `complaints`;
CREATE TABLE `complaints` (
  `complaint_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `case_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int unsigned DEFAULT NULL,
  `case_type` enum('Civil','Criminal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Civil',
  `complaint_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complaint_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incident_at` datetime DEFAULT NULL,
  `incident_date` date DEFAULT NULL,
  `incident_time` time DEFAULT NULL,
  `narrative` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `additional_details` longtext COLLATE utf8mb4_unicode_ci,
  `proof_ocr_notes` longtext COLLATE utf8mb4_unicode_ci,
  `relief_sought` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incident_purok` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `incident_location` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `incident_city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Marikina City',
  `incident_barangay` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tumana',
  `incident_street` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incident_landmark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incident_latitude` decimal(10,7) DEFAULT NULL,
  `incident_longitude` decimal(10,7) DEFAULT NULL,
  `process_stage` enum('Intake','Summon','Mediation','Conciliation','CFA','Closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Intake',
  `status` enum('Filed','Pending Review','Active','Resolved','Dismissed','Archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Filed',
  `created_by` int unsigned NOT NULL,
  `updated_by` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_id`),
  UNIQUE KEY `uq_complaints_case_no` (`case_no`),
  KEY `idx_complaints_incident_at` (`incident_at`),
  KEY `idx_complaints_status_stage` (`status`,`process_stage`),
  KEY `fk_complaints_created_by` (`created_by`),
  KEY `fk_complaints_updated_by` (`updated_by`),
  CONSTRAINT `fk_complaints_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_complaints_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table structure for `profile_history`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `profile_history`;
CREATE TABLE `profile_history` (
  `profile_history_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profile_id` bigint unsigned NOT NULL,
  `action` enum('Created','Updated','Archived','Reactivated') COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_values` longtext COLLATE utf8mb4_unicode_ci,
  `new_values` longtext COLLATE utf8mb4_unicode_ci,
  `acted_by` int unsigned NOT NULL,
  `acted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`profile_history_id`),
  KEY `idx_profile_history_profile` (`profile_id`,`acted_at`),
  KEY `fk_profile_history_actor` (`acted_by`),
  CONSTRAINT `fk_profile_history_actor` FOREIGN KEY (`acted_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_profile_history_profile` FOREIGN KEY (`profile_id`) REFERENCES `profiles` (`profile_id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table structure for `profiles`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `profiles`;
CREATE TABLE `profiles` (
  `profile_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profile_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `suffix` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `sex` enum('Male','Female','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Male',
  `civil_status` enum('Single','Married','Widowed','Separated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Single',
  `contact_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purok_sitio` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barangay` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city_municipality` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `province` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `residency_status` enum('Permanent Resident','Temporary Resident','Tenant') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Permanent Resident',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `voter_status` enum('Registered','Not Registered','Not Specified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Not Specified',
  `occupation` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Active','Archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_by` int unsigned NOT NULL,
  `updated_by` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`profile_id`),
  UNIQUE KEY `uq_profiles_profile_no` (`profile_no`),
  KEY `idx_profiles_name` (`last_name`,`first_name`,`middle_name`),
  KEY `idx_profiles_contact_no` (`contact_no`),
  KEY `idx_profiles_status` (`status`),
  KEY `fk_profiles_created_by` (`created_by`),
  KEY `fk_profiles_updated_by` (`updated_by`),
  CONSTRAINT `fk_profiles_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_profiles_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table structure for `roles`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `role_id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `uq_roles_role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table structure for `users`
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` int unsigned NOT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Default Administrative User
-- ----------------------------------------------------------------------------
INSERT INTO `users` (`user_id`, `first_name`, `middle_name`, `last_name`, `username`, `email`, `contact_no`, `password_hash`, `role_id`, `status`, `created_at`, `updated_at`) VALUES ('1', 'System', NULL, 'Administrator', 'admin', 'admin@agap-remastered.test', NULL, '$2b$12$bHV3t9S5cOS4y0cn2Wm7negLVyGVFB9M/rBTDuUjz448.mOaj6y.O', '1', 'Active', '2026-10-14 22:12:19', '2026-10-14 22:12:19') ON DUPLICATE KEY UPDATE `username`=VALUES(`username`);

-- ----------------------------------------------------------------------------
-- Complaint Categories Seed Data
-- ----------------------------------------------------------------------------
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (1, 'Non-Payment of Debt', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (2, 'Breach of Agreement', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (3, 'Physical Injuries', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (4, 'Defamation', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (5, 'Threats', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (6, 'Property and Rental Disputes', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (7, 'Disturbance and Public Disorder', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (8, 'Malicious Mischief', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (9, 'Trespassing', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `category_code`, `description`, `is_active`) VALUES (10, 'Family and Domestic Disputes', NULL, NULL, 0) ON DUPLICATE KEY UPDATE `category_name`=VALUES(`category_name`);

