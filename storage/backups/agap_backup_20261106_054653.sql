-- =====================================================
-- AGAP System Full Database Backup
-- Database: agap_db
-- Generated: 2026-11-06 05:46:52 (Asia/Manila)
-- PHP Version: 8.3.30
-- =====================================================

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+08:00";

-- -----------------------------------------------------
-- Structure for table `ai_logs`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `ai_logs`;
CREATE TABLE `ai_logs` (
  `ai_log_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `feature` enum('Chatbot','Narrative Generator') COLLATE utf8mb4_unicode_ci NOT NULL,
  `prompt` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `response` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ai_log_id`),
  KEY `idx_ai_logs_user_created_at` (`user_id`,`created_at`),
  CONSTRAINT `fk_ai_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `arbitration_records`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `arbitration_records`;
CREATE TABLE `arbitration_records` (
  `arbitration_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `agreement_date` date DEFAULT NULL,
  `award_date` date DEFAULT NULL,
  `award_details` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`arbitration_id`),
  UNIQUE KEY `uq_arbitration_records_case_id` (`case_id`),
  CONSTRAINT `fk_arbitration_records_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `audit_trails`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `audit_trails`;
CREATE TABLE `audit_trails` (
  `audit_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `affected_record` int unsigned DEFAULT NULL,
  `audit_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_trails_user_id_audit_date` (`user_id`,`audit_date`),
  KEY `idx_audit_trails_module_record` (`module_name`,`affected_record`),
  CONSTRAINT `fk_audit_trails_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `audit_trails` (1 rows)
INSERT INTO `audit_trails` (`audit_id`, `user_id`, `action`, `module_name`, `affected_record`, `audit_date`) VALUES
('1', '1', 'Created Pangkat duty group \"Wednesday Conciliation Panel A\"', 'Pangkat Duty Groups', '1', '2026-11-06 05:44:35');

-- -----------------------------------------------------
-- Structure for table `case_assignments`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `case_assignments`;
CREATE TABLE `case_assignments` (
  `assignment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `assignment_role` enum('Mediator','Head','Secretary','Member') COLLATE utf8mb4_unicode_ci NOT NULL,
  `assigned_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `uq_case_assignments_role` (`case_id`,`member_id`,`assignment_role`),
  UNIQUE KEY `uq_case_assignments_case_role` (`case_id`,`assignment_role`),
  KEY `idx_case_assignments_member_id` (`member_id`),
  CONSTRAINT `fk_case_assignments_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_assignments_member` FOREIGN KEY (`member_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `case_deadlines`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `case_deadlines`;
CREATE TABLE `case_deadlines` (
  `deadline_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `deadline_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('Pending','Completed','Overdue') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`deadline_id`),
  UNIQUE KEY `uq_case_deadlines_case_type` (`case_id`,`deadline_type`),
  KEY `idx_case_deadlines_case_id_due_date` (`case_id`,`due_date`),
  CONSTRAINT `fk_case_deadlines_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `case_history`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `case_history`;
CREATE TABLE `case_history` (
  `history_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `status` enum('Docketed','Mediation','Conciliation','Arbitration','Settled','Dismissed','CFA Issued','Archived','DISMISSED_BARRED','RESPONDENT_DEFAULT','Repudiated','Breached') COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `updated_by` int unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_case_history_case_id_updated_at` (`case_id`,`updated_at`),
  KEY `fk_case_history_updated_by` (`updated_by`),
  CONSTRAINT `fk_case_history_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_history_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `case_stages`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `case_stages`;
CREATE TABLE `case_stages` (
  `stage_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `stage_type` enum('Mediation','Conciliation','Arbitration') COLLATE utf8mb4_unicode_ci NOT NULL,
  `stage_status` enum('Locked','Ready for Referral','Not Started','In Progress','Awaiting Outcome','Completed','Referred to Pangkat') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Not Started',
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `outcome` enum('Settled','Unsuccessful','Repudiated','Dismissed','Referred to Pangkat','Arbitration','Arbitration Award','Pending') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `outcome_remarks` text COLLATE utf8mb4_unicode_ci,
  `referral_date` date DEFAULT NULL,
  `referring_officer_id` int unsigned DEFAULT NULL,
  `referral_reason` text COLLATE utf8mb4_unicode_ci,
  `records_transmitted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`stage_id`),
  UNIQUE KEY `uq_case_stage` (`case_id`,`stage_type`),
  KEY `idx_cs_case` (`case_id`),
  KEY `idx_cs_status` (`stage_status`),
  KEY `fk_cs_officer` (`referring_officer_id`),
  CONSTRAINT `fk_cs_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cs_officer` FOREIGN KEY (`referring_officer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `case_transmittals`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `case_transmittals`;
CREATE TABLE `case_transmittals` (
  `transmittal_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `transmittal_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `settlement_date` date NOT NULL,
  `resolution_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ten_day_expiry_date` date NOT NULL,
  `target_agency` enum('MTC','DILG','Both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Both',
  `transmittal_date` date DEFAULT NULL,
  `transmitting_officer_id` int unsigned DEFAULT NULL,
  `attesting_officer_id` int unsigned DEFAULT NULL,
  `status` enum('Pending','Generated','Transmitted','Acknowledged') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `transmittal_notified` tinyint(1) NOT NULL DEFAULT '0',
  `mtc_received_date` date DEFAULT NULL,
  `mtc_receiver_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dilg_received_date` date DEFAULT NULL,
  `dilg_receiver_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receiving_copy_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`transmittal_id`),
  UNIQUE KEY `transmittal_number` (`transmittal_number`),
  KEY `fk_case_transmittals_case` (`case_id`),
  CONSTRAINT `fk_case_transmittals_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `cases`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `cases`;
CREATE TABLE `cases` (
  `case_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `case_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `case_type` enum('Civil','Criminal') COLLATE utf8mb4_unicode_ci NOT NULL,
  `case_status` enum('Docketed','Mediation','Conciliation','Arbitration','Settled','Dismissed','CFA Issued','Archived','DISMISSED_BARRED','RESPONDENT_DEFAULT','Repudiated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Docketed',
  `docket_date` date NOT NULL,
  `archived_date` date DEFAULT NULL,
  `mediation_start_date` date DEFAULT NULL,
  `mediation_deadline_date` date DEFAULT NULL,
  `is_paused` tinyint(1) NOT NULL DEFAULT '0',
  `paused_at` datetime DEFAULT NULL,
  `resumed_at` datetime DEFAULT NULL,
  `pause_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pause_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`case_id`),
  UNIQUE KEY `uq_cases_complaint_id` (`complaint_id`),
  UNIQUE KEY `uq_cases_number` (`case_number`),
  KEY `idx_cases_status_docket_date` (`case_status`,`docket_date`),
  KEY `idx_cases_mediation_deadline` (`mediation_deadline_date`),
  CONSTRAINT `fk_cases_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `cfa_records`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `cfa_records`;
CREATE TABLE `cfa_records` (
  `cfa_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `cfa_type` enum('KP Form 20','KP Form 20-A','KP Form 20-B') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KP Form 20',
  `issuance_date` date NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cfa_id`),
  UNIQUE KEY `uq_cfa_records_case_type` (`case_id`,`cfa_type`),
  CONSTRAINT `fk_cfa_records_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `complaint_attachments`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `complaint_attachments`;
CREATE TABLE `complaint_attachments` (
  `attachment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_complaint_attachments_complaint_id` (`complaint_id`),
  CONSTRAINT `fk_complaint_attachments_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `complaint_categories`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `complaint_categories`;
CREATE TABLE `complaint_categories` (
  `category_id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tagalog_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ltia_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_complaint_categories_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `complaint_categories` (51 rows)
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `tagalog_name`, `ltia_code`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
('1', 'Abandonment of a Minor (Arts. 276, 277) / Pagpapabaya sa Bata o Menor de Edad', 'Pagpapabaya sa Bata o Menor de Edad', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('2', 'Abandonment of Persons in Danger or One\'s Own Victim (Art. 275) / Pagpapabaya sa Taong nasa Panganib o Sariling Biktima', 'Pagpapabaya sa Taong nasa Panganib o Sariling Biktima', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('3', 'Acts of Lasciviousness with Consent (Art. 339) / Malalaswang Gawa na may Pahintulot', 'Malalaswang Gawa na may Pahintulot', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('4', 'Agrarian Disputes (LOI 1314) / Alitan sa Lupang Pang-agrikultura (Agraryo)', 'Alitan sa Lupang Pang-agrikultura (Agraryo)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('5', 'Alarms and Scandals (Art. 155) / Panggugulo at Alarma', 'Panggugulo at Alarma', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('6', 'Alteration of Boundary Marks or Landmarks (Art. 313) / Pagbabago ng mga Palatandaan ng Hangganan', 'Pagbabago ng mga Palatandaan ng Hangganan', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('7', 'Anti-Fencing Law (PD 1612) / Pagbili o Pagtanggap ng Nakaw na Ari-arian (Anti-Fencing)', 'Pagbili o Pagtanggap ng Nakaw na Ari-arian (Anti-Fencing)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('8', 'Assistance to Suicide (Art. 253) / Pagtulong sa Pagpapatiwakal', 'Pagtulong sa Pagpapatiwakal', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('9', 'Bouncing Checks (BP 22) / Pag-isyu ng Talbog na Tseke (Bouncing Checks)', 'Pag-isyu ng Talbog na Tseke (Bouncing Checks)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('10', 'Boundary Dispute and Encroachment / Pagtatalo sa Hangganan ng Lupa at Bakod', 'Pagtatalo sa Hangganan ng Lupa at Bakod', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('11', 'Breach of Contract or Agreement / Paglabag sa Kontrata o Kasunduan', 'Paglabag sa Kontrata o Kasunduan', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('12', 'Coercions (Grave, Light, or Similar) (Arts. 286-289) / Pamimilit o Pagpilit (Coercion)', 'Pamimilit o Pagpilit (Coercion)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('13', 'Collection of Debts or Rentals / Paglikom ng mga Utang o Upa', 'Paglikom ng mga Utang o Upa', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('14', 'Damages and Reimbursement / Bayad-Pinsala at Pagbabalik ng Halaga', 'Bayad-Pinsala at Pagbabalik ng Halaga', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('15', 'Dispensing of Abortives by Pharmacist (Art. 259) / Pagbebenta o Pagbibigay ng Pampalaglag ng Parmasyutiko', 'Pagbebenta o Pagbibigay ng Pampalaglag ng Parmasyutiko', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('16', 'Easement and Right-of-Way / Karapatan sa Daanan at Daluyan (Easement)', 'Karapatan sa Daanan at Daluyan (Easement)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('17', 'Estafa and Swindling (Arts. 315, 316, 317) / Panlilinlang o Estafa', 'Panlilinlang o Estafa', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('18', 'False Certificates (Arts. 174, 175) / Paggamit o Pagbibigay ng Pekeng Sertipiko', 'Paggamit o Pagbibigay ng Pekeng Sertipiko', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('19', 'False Testimony Against or In Favor of Defendant (Arts. 180, 181) / Pagsisinungaling o Maling Testimonya', 'Pagsisinungaling o Maling Testimonya', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('20', 'Family and Domestic Relations / Mga Relasyong Pampamilya at Tahanan', 'Mga Relasyong Pampamilya at Tahanan', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('21', 'Fictitious Name and Concealment of True Name (Art. 178) / Paggamit ng Huwad na Pangalan at Pagkukubli ng Tunay na Pangalan', 'Paggamit ng Huwad na Pangalan at Pagkukubli ng Tunay na Pangalan', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('22', 'Grave Scandal (Art. 200) / Malubhang Eskandalo (Grave Scandal)', 'Malubhang Eskandalo (Grave Scandal)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('23', 'Illegal Use of Uniforms or Insignia (Art. 179) / Ilegal na Paggamit ng Uniporme o Sagisag', 'Ilegal na Paggamit ng Uniporme o Sagisag', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('24', 'Imprudence and Negligence (Art. 365) / Pinsala dulot ng Kapabayaan o Imprudensya', 'Pinsala dulot ng Kapabayaan o Imprudensya', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('25', 'Incriminating Innocent Persons & Intriguing Against Honor (Arts. 363, 364) / Pagsasangkot ng Walang-Salang Tao at Paninirang-Puri', 'Pagsasangkot ng Walang-Salang Tao at Paninirang-Puri', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('26', 'Inducing Minor to Abandon Home (Art. 271) / Pag-uudyok sa Menor de Edad na Lumayas sa Tahanan', 'Pag-uudyok sa Menor de Edad na Lumayas sa Tahanan', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('27', 'Lease and Rental Disputes / Pagpapaupa at Alitan sa Upa', 'Pagpapaupa at Alitan sa Upa', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('28', 'Less Serious Physical Injuries (Art. 265) / Hindi Lubhang Malubhang Pananakit sa Katawan', 'Hindi Lubhang Malubhang Pananakit sa Katawan', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('29', 'Malicious Mischief and Damage to Property (Arts. 327, 329) / Paninira ng Ari-arian (Malicious Mischief)', 'Paninira ng Ari-arian (Malicious Mischief)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('30', 'Neighborhood Nuisance & Animal Disturbances (Stray Animals, Barking Dogs, Loud Videoke, Garbage) / Pambubulabog sa Kapaligiran at Alagang Hayop (Gala na Hayop, Tahol ng Aso, Maingay na Videoke, Basura)', 'Pambubulabog sa Kapaligiran at Alagang Hayop (Gala na Hayop, Tahol ng Aso, Maingay na Videoke, Basura)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('31', 'Non-Payment of Debt / Hindi Pagbabayad ng Utang', 'Hindi Pagbabayad ng Utang', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('32', 'Occupation of Real Property or Usurpation of Real Rights (Art. 312) / Pang-aagaw o Ilegal na Pagsakop sa Ari-arian', 'Pang-aagaw o Ilegal na Pagsakop sa Ari-arian', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('33', 'Physical Injuries in a Tumultuous Affray (Art. 252) / Pananakit sa Katawan sa Gitna ng Kaguluhan', 'Pananakit sa Katawan sa Gitna ng Kaguluhan', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('34', 'Premature Marriages (Art. 351) / Maagang Pagpapakasal (Premature Marriage)', 'Maagang Pagpapakasal (Premature Marriage)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('35', 'Price Control Violations / Paglabag sa Pagkontrol ng Presyo ng Bilihin', 'Paglabag sa Pagkontrol ng Presyo ng Bilihin', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('36', 'Removal, Sale or Pledge of Mortgaged Property (Art. 319) / Pag-aalis, Pagbenta, o Pagsangla ng Nakasanglang Ari-arian', 'Pag-aalis, Pagbenta, o Pagsangla ng Nakasanglang Ari-arian', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('37', 'Responsibility of Participants in a Duel (Art. 260) / Pananagutan ng mga Kalahok sa Duelo', 'Pananagutan ng mga Kalahok sa Duelo', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('38', 'Safe Spaces Act: Catcalling & Whistling (RA 11313 — 1st Offense Only) / Bawal Bastos: Paninita at Pagsipol (Unang Paglabag Lamang)', 'Bawal Bastos: Paninita at Pagsipol (Unang Paglabag Lamang)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('39', 'Seizure of Correspondence or Secrets with Abuse of Office (Arts. 290, 291) / Pagharang ng Liham o Pagsisiwalat ng Lihim', 'Pagharang ng Liham o Pagsisiwalat ng Lihim', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('40', 'Simple Seduction (Art. 338) / Simpleng Panunukso o Pang-aakit (Simple Seduction)', 'Simpleng Panunukso o Pang-aakit (Simple Seduction)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('41', 'Slander and Defamation (Arts. 358, 359) / Paninirang-Puri (Oral Defamation / Slander)', 'Paninirang-Puri (Oral Defamation / Slander)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('42', 'Slight Physical Injuries and Maltreatment (Art. 266) / Bahagyang Pananakit at Pagmamalupit (Slight Physical Injuries)', 'Bahagyang Pananakit at Pagmamalupit (Slight Physical Injuries)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('43', 'Specific Performance of Obligation / Tiyak na Pagtupad ng Obligasyon', 'Tiyak na Pagtupad ng Obligasyon', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('44', 'Theft and Other Thefts (Arts. 309, 310) / Pagnanakaw (Theft)', 'Pagnanakaw (Theft)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('45', 'Threatening to Publish for Compensation or Prohibited Publications (Arts. 154, 356, 357) / Pagbabanta ng Paglalathala o Ipinagbabawal na Paglalathala', 'Pagbabanta ng Paglalathala o Ipinagbabawal na Paglalathala', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('46', 'Threats (Grave, Light, or Other Threats) (Arts. 282, 283, 285) / Pagbabanta (Threats)', 'Pagbabanta (Threats)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('47', 'Trespass to Dwelling and Other Forms (Arts. 280, 281) / Ilegal na Pagpasok sa Bahay o Bakuran (Trespass)', 'Ilegal na Pagpasok sa Bahay o Bakuran (Trespass)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('48', 'Unjust Vexation (Art. 287) / Hindi Makatarungang Pang-iinis o Panggigipit (Unjust Vexation)', 'Hindi Makatarungang Pang-iinis o Panggigipit (Unjust Vexation)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('49', 'Unlawful Arrest (Art. 269) / Ilegal na Pag-aresto o Pagpigil', 'Ilegal na Pag-aresto o Pagpigil', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('50', 'Violation of Local Ordinances / Paglabag sa Lokal na Ordinansa', 'Paglabag sa Lokal na Ordinansa', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07');
INSERT INTO `complaint_categories` (`category_id`, `category_name`, `tagalog_name`, `ltia_code`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
('51', 'Others (Please Specify) / Iba pa (Pakitukoy)', 'Iba pa (Pakitukoy)', NULL, NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07');

-- -----------------------------------------------------
-- Structure for table `complaint_intake_screenings`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `complaint_intake_screenings`;
CREATE TABLE `complaint_intake_screenings` (
  `screening_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `party_is_government` tinyint(1) NOT NULL DEFAULT '0',
  `party_is_public_officer` tinyint(1) NOT NULL DEFAULT '0',
  `parties_in_diff_lgu` tinyint(1) NOT NULL DEFAULT '0',
  `property_in_diff_lgu` tinyint(1) NOT NULL DEFAULT '0',
  `accused_under_detention` tinyint(1) NOT NULL DEFAULT '0',
  `habeas_corpus_needed` tinyint(1) NOT NULL DEFAULT '0',
  `provisional_remedy_needed` tinyint(1) NOT NULL DEFAULT '0',
  `prescription_risk` tinyint(1) NOT NULL DEFAULT '0',
  `indigenous_community` tinyint(1) NOT NULL DEFAULT '0',
  `offense_imprisonment_max` decimal(6,2) NOT NULL DEFAULT '0.00',
  `offense_fine_max` decimal(12,2) NOT NULL DEFAULT '0.00',
  `has_private_offended_party` tinyint(1) NOT NULL DEFAULT '1',
  `duplicate_acknowledged` tinyint(1) NOT NULL DEFAULT '0',
  `screening_notes` text COLLATE utf8mb4_unicode_ci,
  `screened_by` int unsigned DEFAULT NULL,
  `screened_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`screening_id`),
  UNIQUE KEY `uq_complaint_intake_screening` (`complaint_id`),
  KEY `fk_cis_user` (`screened_by`),
  CONSTRAINT `fk_cis_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cis_user` FOREIGN KEY (`screened_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `complaint_paanyaya`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `complaint_paanyaya`;
CREATE TABLE `complaint_paanyaya` (
  `paanyaya_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `invitee_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitation_date` date NOT NULL,
  `invitation_time` time NOT NULL,
  `venue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
  `hearing_day` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Scheduled','Resolved','Failed','Escalated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Scheduled',
  `resolution_notes` text COLLATE utf8mb4_unicode_ci,
  `pdf_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_e_signature` tinyint(1) NOT NULL DEFAULT '1',
  `scheduled_by` int unsigned DEFAULT NULL,
  `presiding_officer_ids` json DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `escalated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`paanyaya_id`),
  KEY `idx_paanyaya_complaint` (`complaint_id`),
  KEY `idx_paanyaya_status` (`status`),
  CONSTRAINT `fk_paanyaya_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `complaint_parties`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `complaint_parties`;
CREATE TABLE `complaint_parties` (
  `party_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `resident_id` int unsigned NOT NULL,
  `party_type` enum('Complainant','Respondent','Witness') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_minor` tinyint(1) NOT NULL DEFAULT '0',
  `guardian_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guardian_relationship` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guardian_contact` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guardian_address` text COLLATE utf8mb4_unicode_ci,
  `home_latitude` decimal(10,8) DEFAULT NULL,
  `home_longitude` decimal(11,8) DEFAULT NULL,
  `preferred_contact` enum('SMS','Call','Email','In-Person') COLLATE utf8mb4_unicode_ci DEFAULT 'SMS',
  `suffix` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occupation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `age` int DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `civil_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_unknown` tinyint(1) NOT NULL DEFAULT '0',
  `middle_name_unknown` tinyint(1) NOT NULL DEFAULT '0',
  `birthdate_unknown` tinyint(1) NOT NULL DEFAULT '0',
  `address_requires_verification` tinyint(1) NOT NULL DEFAULT '0',
  `residency_verified` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`party_id`),
  UNIQUE KEY `uq_complaint_parties_role` (`complaint_id`,`resident_id`,`party_type`),
  KEY `idx_complaint_parties_resident_id` (`resident_id`),
  CONSTRAINT `fk_complaint_parties_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_complaint_parties_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `complaint_scanned_documents`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `complaint_scanned_documents`;
CREATE TABLE `complaint_scanned_documents` (
  `doc_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `case_id` int unsigned DEFAULT NULL,
  `document_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KP Form 7 Scanned Complaint',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL DEFAULT '0',
  `version_number` int unsigned NOT NULL DEFAULT '1',
  `is_current` tinyint(1) NOT NULL DEFAULT '1',
  `replacement_reason` text COLLATE utf8mb4_unicode_ci,
  `uploaded_by` int unsigned DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`doc_id`),
  KEY `idx_csd_complaint` (`complaint_id`),
  KEY `idx_csd_case` (`case_id`),
  KEY `fk_csd_user` (`uploaded_by`),
  CONSTRAINT `fk_csd_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_csd_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `complaints`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `complaints`;
CREATE TABLE `complaints` (
  `complaint_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` int unsigned NOT NULL,
  `secondary_category_id` int unsigned DEFAULT NULL,
  `other_category_specification` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `secondary_other_category_specification` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `case_type` enum('Civil','Criminal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Civil',
  `complaint_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `incident_date` date DEFAULT NULL,
  `incident_time` time DEFAULT NULL,
  `is_time_approximate` tinyint(1) NOT NULL DEFAULT '0',
  `is_time_unknown` tinyint(1) NOT NULL DEFAULT '0',
  `incident_location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incident_city` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Marikina City',
  `incident_barangay` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tumana',
  `incident_street` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incident_purok` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incident_landmark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incident_location_details` text COLLATE utf8mb4_unicode_ci,
  `narrative` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `additional_details` longtext COLLATE utf8mb4_unicode_ci,
  `requested_resolution` text COLLATE utf8mb4_unicode_ci,
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Filed',
  `document_status` enum('No Scan Uploaded','Scan Uploaded','Updated Scan Uploaded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'No Scan Uploaded',
  `is_draft` tinyint(1) NOT NULL DEFAULT '0',
  `paanyaya_attempt_count` tinyint unsigned DEFAULT '1',
  `max_paanyaya_reached` tinyint(1) DEFAULT '0',
  `resolved_without_docketing` tinyint(1) DEFAULT '0',
  `paanyaya_1_outcome` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paanyaya_complainant_choice` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ltia_category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `encoded_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_id`),
  UNIQUE KEY `uq_complaints_number` (`complaint_number`),
  KEY `idx_complaints_category_id` (`category_id`),
  KEY `idx_complaints_secondary_category_id` (`secondary_category_id`),
  KEY `idx_complaints_status_created_at` (`status`,`created_at`),
  KEY `fk_complaints_encoded_by` (`encoded_by`),
  CONSTRAINT `fk_complaints_category` FOREIGN KEY (`category_id`) REFERENCES `complaint_categories` (`category_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_complaints_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_complaints_secondary_category` FOREIGN KEY (`secondary_category_id`) REFERENCES `complaint_categories` (`category_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `deadline_alert_log`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `deadline_alert_log`;
CREATE TABLE `deadline_alert_log` (
  `deadline_alert_id` int unsigned NOT NULL AUTO_INCREMENT,
  `deadline_id` int unsigned NOT NULL,
  `alert_type` enum('Due Soon','Overdue') COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`deadline_alert_id`),
  UNIQUE KEY `uq_deadline_alert_once` (`deadline_id`,`alert_type`),
  CONSTRAINT `fk_deadline_alert_log_deadline` FOREIGN KEY (`deadline_id`) REFERENCES `case_deadlines` (`deadline_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `document_templates`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `document_templates`;
CREATE TABLE `document_templates` (
  `template_id` int unsigned NOT NULL AUTO_INCREMENT,
  `template_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  UNIQUE KEY `uq_document_templates_name` (`template_name`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `document_templates` (21 rows)
INSERT INTO `document_templates` (`template_id`, `template_name`, `description`, `created_at`, `updated_at`) VALUES
('1', 'KP Form 7', 'Complaint (Sumbong)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('2', 'KP Form 8', 'Notice of Hearing (Paabiso ng Pagdinig)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('3', 'KP Form 9', 'Summons (Patawag)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('4', 'KP Form 10', 'Notice to Constitute the Pangkat Tagapagkasundo (Paabiso sa Pagbubuo ng Pangkat)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('5', 'KP Form 11', 'Notice to Chosen Pangkat Members (Patalastas sa Piniling Miyembro ng Pangkat)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('6', 'KP Form 12', 'Notice of Hearing for Conciliation (Paabiso ng Pagdinig sa Harap ng Pangkat)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('7', 'KP Form 13', 'Subpoena (Paanyaya sa mga Saksi)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('8', 'KP Form 14', 'Arbitration Agreement (Kasunduan sa Pag-aarbitro)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('9', 'KP Form 15', 'Arbitration Award (Gawad ng Arbitrasyon)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('10', 'KP Form 16', 'Amicable Settlement (Kasunduang Pag-aayos)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('11', 'KP Form 17', 'Repudiation of Settlement (Pagtatwa ng Kasunduan)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('12', 'KP Form 18', 'Notice of Hearing (Failure to Appear - Complainant)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('13', 'KP Form 19', 'Notice of Hearing (Failure to Appear - Respondent)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('14', 'KP Form 20', 'Certificate to File Action (Katibayan Upang Makadulog sa Hukuman)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('15', 'KP Form 20-A', 'Certificate to Bar Action (Katibayan Upang Hadlangan ang Paghahain ng Aksyon)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('16', 'KP Form 20-B', 'Certificate to Bar Counterclaim (Katibayan Upang Hadlangan ang Ganting-Sakdal)', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('17', 'KP Form 21', 'Certificate to Bar Action', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('18', 'KP Form 22', 'Certificate to Bar Action Counterclaim', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('19', 'PATAWAG (Pre-docketing Paanyaya Form)', 'Official Blank Paanyaya / Pre-docketing Invitation Form before formal case docketing.', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('20', 'Case Profile & Intake Documentation Jacket', 'Official Case Folder Jacket and Profile Record containing parties, timeline, and document inventory.', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('21', 'Minutes of Hearing (Katitikan ng Pagdinig)', 'Official Blank Minutes of Hearing Form for Mediation, Pangkat Conciliation, and Pre-docketing dialogues.', '2026-11-06 05:17:07', '2026-11-06 05:17:07');

-- -----------------------------------------------------
-- Structure for table `execution_proceedings`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `execution_proceedings`;
CREATE TABLE `execution_proceedings` (
  `execution_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `settlement_id` int unsigned NOT NULL,
  `motion_date` date NOT NULL,
  `motion_narrative` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `hearing_date` datetime NOT NULL,
  `complainant_attended` enum('Present','Absent','Excused') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Absent',
  `respondent_attended` enum('Present','Absent','Excused') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Absent',
  `non_appearance_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hearing_minutes` text COLLATE utf8mb4_unicode_ci,
  `grace_period_end_date` date DEFAULT NULL,
  `notice_of_execution_date` date DEFAULT NULL,
  `execution_status` enum('Motion Filed','Hearing Scheduled','Grace Period Active','Notice of Execution Issued','Enforced / Complied','Lapsed to MTC') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Motion Filed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`execution_id`),
  KEY `fk_exec_cases` (`case_id`),
  KEY `fk_exec_settlements` (`settlement_id`),
  CONSTRAINT `fk_exec_cases` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exec_settlements` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`settlement_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `generated_documents`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `generated_documents`;
CREATE TABLE `generated_documents` (
  `document_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `template_id` int unsigned NOT NULL,
  `generated_by` int unsigned DEFAULT NULL,
  `file_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_status` enum('Generated','For Service','Served','Service Failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Generated',
  `regeneration_reason` text COLLATE utf8mb4_unicode_ci,
  `generated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `idx_generated_documents_case_id` (`case_id`),
  KEY `idx_generated_documents_template_id` (`template_id`),
  KEY `fk_generated_documents_user` (`generated_by`),
  CONSTRAINT `fk_generated_documents_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_generated_documents_template` FOREIGN KEY (`template_id`) REFERENCES `document_templates` (`template_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_generated_documents_user` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `generated_reports`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `generated_reports`;
CREATE TABLE `generated_reports` (
  `report_id` int unsigned NOT NULL AUTO_INCREMENT,
  `report_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `generated_by` int unsigned DEFAULT NULL,
  `file_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `generated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`),
  KEY `idx_generated_reports_type_generated_at` (`report_type`,`generated_at`),
  KEY `fk_generated_reports_user` (`generated_by`),
  CONSTRAINT `fk_generated_reports_user` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `hearing_attendance`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `hearing_attendance`;
CREATE TABLE `hearing_attendance` (
  `attendance_id` int unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `resident_id` int unsigned NOT NULL,
  `attendance_status` enum('Present','Absent','Late','Excused','Not Served','Pending Verification') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending Verification',
  `is_justified` tinyint(1) NOT NULL DEFAULT '0',
  `justification_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `verification_status` enum('Pending','Verified Served','Unverified','Excused Approved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `determination_notes` text COLLATE utf8mb4_unicode_ci,
  `attachment_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by` int unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `uq_hearing_attendance_resident` (`hearing_id`,`resident_id`),
  KEY `idx_hearing_attendance_resident_id` (`resident_id`),
  KEY `idx_hearing_attendance_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_hearing_attendance_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hearing_attendance_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hearing_attendance_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `hearing_explanations`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `hearing_explanations`;
CREATE TABLE `hearing_explanations` (
  `explanation_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `resident_id` int unsigned NOT NULL,
  `explanation` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome` enum('Pending','Justified','Unjustified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `supporting_file` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decided_by` int unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`explanation_id`),
  KEY `idx_hearing_explanations_party` (`hearing_id`,`resident_id`),
  KEY `fk_hex_party` (`resident_id`),
  KEY `fk_hex_decider` (`decided_by`),
  KEY `fk_hex_creator` (`created_by`),
  CONSTRAINT `fk_hex_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hex_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hex_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hex_party` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `hearing_legal_actions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `hearing_legal_actions`;
CREATE TABLE `hearing_legal_actions` (
  `legal_action_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `resident_id` int unsigned NOT NULL,
  `action_type` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Pending Review','Approved','Rejected','Recorded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending Review',
  `details` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`legal_action_id`),
  KEY `idx_hearing_legal_actions_hearing` (`hearing_id`,`created_at`),
  KEY `fk_hla_party` (`resident_id`),
  KEY `fk_hla_reviewer` (`reviewed_by`),
  KEY `fk_hla_creator` (`created_by`),
  CONSTRAINT `fk_hla_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hla_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hla_party` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hla_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `hearing_minutes`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `hearing_minutes`;
CREATE TABLE `hearing_minutes` (
  `minute_id` int unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `case_id` int unsigned NOT NULL,
  `session_type` enum('1st Mediation','2nd Mediation','3rd Mediation','Conciliation','Arbitration','Show Cause') COLLATE utf8mb4_unicode_ci NOT NULL,
  `opening_conducted` tinyint(1) NOT NULL DEFAULT '0',
  `identity_verified` tinyint(1) NOT NULL DEFAULT '0',
  `complaint_reviewed` tinyint(1) NOT NULL DEFAULT '0',
  `complainant_statement` text COLLATE utf8mb4_unicode_ci,
  `respondent_statement` text COLLATE utf8mb4_unicode_ci,
  `main_dispute_identified` text COLLATE utf8mb4_unicode_ci,
  `settlement_discussion_notes` text COLLATE utf8mb4_unicode_ci,
  `caucus_conducted` tinyint(1) NOT NULL DEFAULT '0',
  `caucus_notes` text COLLATE utf8mb4_unicode_ci,
  `previous_proposal` text COLLATE utf8mb4_unicode_ci,
  `new_proposal` text COLLATE utf8mb4_unicode_ci,
  `counteroffer` text COLLATE utf8mb4_unicode_ci,
  `additional_evidence_notes` text COLLATE utf8mb4_unicode_ci,
  `session_outcome` enum('Settled','Continue Mediation','Continue Conciliation','Continue Arbitration','Failed','Party Absent','Rescheduled','Elevate to Pangkat','Pending CFA','Arbitration','Arbitration Award','Repudiated','Dismissed','Pending') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `status` enum('Draft','Finalized') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `agenda_topics` text COLLATE utf8mb4_unicode_ci,
  `agreements_action_items` text COLLATE utf8mb4_unicode_ci,
  `unresolved_issues` text COLLATE utf8mb4_unicode_ci,
  `finalized_by` int unsigned DEFAULT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `outcome_remarks` text COLLATE utf8mb4_unicode_ci,
  `actual_end_time` datetime DEFAULT NULL,
  `recorded_by` int unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`minute_id`),
  UNIQUE KEY `hearing_id` (`hearing_id`),
  KEY `idx_hm_case` (`case_id`),
  KEY `fk_hm_recorded_by` (`recorded_by`),
  KEY `fk_hm_finalized_by` (`finalized_by`),
  CONSTRAINT `fk_hm_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hm_finalized_by` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hm_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hm_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `hearing_minutes_documents`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `hearing_minutes_documents`;
CREATE TABLE `hearing_minutes_documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `case_id` int unsigned NOT NULL,
  `document_type` enum('handwritten_minutes','evidence_attachment') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'evidence_attachment',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned DEFAULT '0',
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `hearing_id` (`hearing_id`),
  KEY `case_id` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `hearing_nonappearances`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `hearing_nonappearances`;
CREATE TABLE `hearing_nonappearances` (
  `nonappearance_id` int unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `resident_id` int unsigned NOT NULL,
  `party_type` enum('Complainant','Respondent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `finding` enum('Unjustified') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Unjustified',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `resolution` enum('Pending','Rescheduled','Re-summons Issued') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `recorded_by` int unsigned DEFAULT NULL,
  `resolved_by` int unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`nonappearance_id`),
  UNIQUE KEY `uq_hearing_nonappearance_party` (`hearing_id`,`resident_id`),
  KEY `idx_hearing_nonappearances_resolution` (`resolution`),
  KEY `fk_hearing_nonappearances_resident` (`resident_id`),
  KEY `fk_hearing_nonappearances_recorded_by` (`recorded_by`),
  KEY `fk_hearing_nonappearances_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_hearing_nonappearances_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hearing_nonappearances_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hearing_nonappearances_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hearing_nonappearances_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `hearing_party_services`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `hearing_party_services`;
CREATE TABLE `hearing_party_services` (
  `service_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `resident_id` int unsigned NOT NULL,
  `attempt_number` tinyint unsigned NOT NULL DEFAULT '1',
  `party_type` enum('Complainant','Respondent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_id` int unsigned NOT NULL,
  `service_date` datetime NOT NULL,
  `service_result` enum('Served','Not Found','Wrong Address','Refused','Unsuccessful Attempt') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `serving_officer` int unsigned NOT NULL,
  `officer_return` text COLLATE utf8mb4_unicode_ci,
  `supporting_file` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assigned_to` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`service_id`),
  KEY `idx_hearing_party_services_party` (`hearing_id`,`resident_id`,`service_date`),
  KEY `fk_hps_party` (`resident_id`),
  KEY `fk_hps_document` (`document_id`),
  KEY `fk_hps_officer` (`serving_officer`),
  KEY `fk_hps_assigned` (`assigned_to`),
  CONSTRAINT `fk_hps_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hps_document` FOREIGN KEY (`document_id`) REFERENCES `generated_documents` (`document_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hps_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hps_officer` FOREIGN KEY (`serving_officer`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hps_party` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `hearings`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `hearings`;
CREATE TABLE `hearings` (
  `hearing_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `hearing_type` enum('Initial Hearing','Mediation','Conciliation','Arbitration','Show Cause (Complainant)','Show Cause (Respondent)') COLLATE utf8mb4_unicode_ci NOT NULL,
  `mediation_attempt` tinyint unsigned DEFAULT NULL,
  `attempt_exception_reason` text COLLATE utf8mb4_unicode_ci,
  `hearing_date` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `duration_minutes` int unsigned DEFAULT '45',
  `actual_end_time` datetime DEFAULT NULL,
  `actual_start_time` datetime DEFAULT NULL,
  `actual_duration_minutes` int unsigned DEFAULT NULL,
  `queue_status` enum('Waiting','Ready','In_Session','Standby','Concluded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Waiting',
  `duration_exceed_reason` text COLLATE utf8mb4_unicode_ci,
  `venue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `kp12_document_id` int unsigned DEFAULT NULL,
  `presiding_officer_id` int unsigned DEFAULT NULL,
  `substitute_presider_id` int unsigned DEFAULT NULL,
  `substitute_reason` text COLLATE utf8mb4_unicode_ci,
  `parties_consent_to_substitute` tinyint(1) NOT NULL DEFAULT '0',
  `rescheduled_from_id` int unsigned DEFAULT NULL,
  `reschedule_reason` text COLLATE utf8mb4_unicode_ci,
  `rescheduled_by_party` enum('Complainant','Respondent','Office','Both') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reschedule_justification_category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reschedule_document_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reschedule_approved_by` int unsigned DEFAULT NULL,
  `reschedule_approved_at` datetime DEFAULT NULL,
  `status` enum('Scheduled','Completed','Rescheduled','Cancelled','Office Cancelled','Attendance Recorded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Scheduled',
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancellation_reason` text COLLATE utf8mb4_unicode_ci,
  `cancellation_category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancellation_notice_sent` tinyint(1) NOT NULL DEFAULT '0',
  `complainant_attendance` enum('Present','Absent','Excused','Pending','Late') COLLATE utf8mb4_unicode_ci DEFAULT 'Pending',
  `respondent_attendance` enum('Present','Absent','Excused','Pending','Late') COLLATE utf8mb4_unicode_ci DEFAULT 'Pending',
  `attendance_recorded_at` datetime DEFAULT NULL,
  `attendance_notes` text COLLATE utf8mb4_unicode_ci,
  `queue_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`hearing_id`),
  KEY `idx_hearings_case_id_date` (`case_id`,`hearing_date`),
  KEY `idx_hearings_presiding` (`presiding_officer_id`),
  KEY `idx_hearings_kp12_document` (`kp12_document_id`),
  KEY `fk_hearings_rescheduled_from` (`rescheduled_from_id`),
  KEY `fk_hearings_substitute` (`substitute_presider_id`),
  KEY `fk_hearings_cancelled_by` (`cancelled_by`),
  KEY `fk_hearings_reschedule_approved_by` (`reschedule_approved_by`),
  CONSTRAINT `fk_hearings_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hearings_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hearings_kp12_document` FOREIGN KEY (`kp12_document_id`) REFERENCES `generated_documents` (`document_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hearings_presiding` FOREIGN KEY (`presiding_officer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hearings_reschedule_approved_by` FOREIGN KEY (`reschedule_approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_hearings_rescheduled_from` FOREIGN KEY (`rescheduled_from_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hearings_substitute` FOREIGN KEY (`substitute_presider_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `incident_locations`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `incident_locations`;
CREATE TABLE `incident_locations` (
  `location_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `landmark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `uq_incident_locations_complaint_id` (`complaint_id`),
  KEY `idx_incident_lat_lng` (`latitude`,`longitude`),
  CONSTRAINT `fk_incident_locations_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_incident_locations_latitude` CHECK (((`latitude` is null) or (`latitude` between -(90) and 90))),
  CONSTRAINT `chk_incident_locations_longitude` CHECK (((`longitude` is null) or (`longitude` between -(180) and 180)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `login_logs`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `login_logs`;
CREATE TABLE `login_logs` (
  `log_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `login_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_login_logs_user_id_login_time` (`user_id`,`login_time`),
  CONSTRAINT `fk_login_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `lupon_duty_schedules`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `lupon_duty_schedules`;
CREATE TABLE `lupon_duty_schedules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `duty_day` enum('Monday','Tuesday','Wednesday','Thursday','Friday') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Wednesday',
  `start_time` time NOT NULL DEFAULT '08:00:00',
  `end_time` time NOT NULL DEFAULT '17:00:00',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lupon_duty_user` (`user_id`),
  KEY `idx_lupon_duty_day` (`duty_day`),
  CONSTRAINT `fk_lupon_duty_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `lupon_duty_schedules` (4 rows)
INSERT INTO `lupon_duty_schedules` (`id`, `user_id`, `duty_day`, `start_time`, `end_time`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
('1', '6', 'Wednesday', '08:00:00', '17:00:00', NULL, '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('2', '5', 'Wednesday', '08:00:00', '17:00:00', 'Wednesday Conciliation Panel A · Member · Lupon Court Room', '1', '2026-11-06 05:17:07', '2026-11-06 05:44:35'),
('3', '3', 'Wednesday', '08:00:00', '17:00:00', 'Wednesday Conciliation Panel A · Chairman · Lupon Court Room', '1', '2026-11-06 05:17:07', '2026-11-06 05:44:35'),
('5', '4', 'Wednesday', '08:00:00', '17:00:00', 'Wednesday Conciliation Panel A · Secretary · Lupon Court Room', '1', '2026-11-06 05:44:35', '2026-11-06 05:44:35');

-- -----------------------------------------------------
-- Structure for table `mediation_commencements`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `mediation_commencements`;
CREATE TABLE `mediation_commencements` (
  `commencement_id` int NOT NULL AUTO_INCREMENT,
  `case_id` int NOT NULL,
  `hearing_id` int NOT NULL,
  `first_actual_meeting_at` datetime NOT NULL,
  `mediation_deadline_at` datetime NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Attendance',
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`commencement_id`),
  UNIQUE KEY `uq_mediation_commencement_case` (`case_id`),
  UNIQUE KEY `uq_mediation_commencement_hearing` (`hearing_id`),
  KEY `idx_mediation_commencement_deadline` (`mediation_deadline_at`),
  KEY `idx_mediation_commencement_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `notifications`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notification_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('urgent','warning','info','success') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `case_id` int unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `idx_notifications_user_read_created` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `paanyaya_minutes`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `paanyaya_minutes`;
CREATE TABLE `paanyaya_minutes` (
  `minutes_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `stage_type` enum('Paanyaya 1','Paanyaya 2') COLLATE utf8mb4_unicode_ci NOT NULL,
  `dialogue_date` datetime NOT NULL,
  `actual_start_time` time DEFAULT NULL,
  `complainant_attended` enum('Present','Absent') COLLATE utf8mb4_unicode_ci DEFAULT 'Present',
  `respondent_attended` enum('Present','Absent') COLLATE utf8mb4_unicode_ci DEFAULT 'Present',
  `discussion_summary` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `agreed_terms` text COLLATE utf8mb4_unicode_ci,
  `session_remarks` text COLLATE utf8mb4_unicode_ci,
  `outcome` enum('Settled','No_Agreement','Party_Absent','Dismissed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `complainant_choice` enum('Request_Paanyaya_2','Escalate_Mediation','Dismissed','None') COLLATE utf8mb4_unicode_ci DEFAULT 'None',
  `scanned_minutes_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_by` int unsigned NOT NULL,
  `presiding_officer_ids` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`minutes_id`),
  KEY `fk_pm_complaint` (`complaint_id`),
  CONSTRAINT `fk_pm_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `paanyaya_sessions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `paanyaya_sessions`;
CREATE TABLE `paanyaya_sessions` (
  `session_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `invitee_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempt_number` tinyint unsigned NOT NULL DEFAULT '1',
  `scheduled_date` date NOT NULL,
  `scheduled_time` time NOT NULL,
  `venue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
  `has_e_signature` tinyint(1) NOT NULL DEFAULT '1',
  `pdf_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `salaysay` text COLLATE utf8mb4_unicode_ci,
  `complainant_attended` enum('Present','Late','Absent') COLLATE utf8mb4_unicode_ci DEFAULT 'Absent',
  `respondent_attended` enum('Present','Late','Absent') COLLATE utf8mb4_unicode_ci DEFAULT 'Absent',
  `session_outcome` enum('Settled','Absent_Retry','Continued_Dialogue','Impasse_Escalate','Max_Failed_Escalate','Dismissed') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `outcome_notes` text COLLATE utf8mb4_unicode_ci,
  `session_remarks` text COLLATE utf8mb4_unicode_ci,
  `scheduled_by` int unsigned DEFAULT NULL,
  `presiding_officer_ids` json DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `escalated_at` datetime DEFAULT NULL,
  `actual_start_time` datetime DEFAULT NULL,
  `actual_end_time` datetime DEFAULT NULL,
  `queue_status` enum('Waiting','Ready','In_Session','Standby','Concluded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Waiting',
  `queue_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`),
  KEY `idx_paanyaya_sessions_complaint` (`complaint_id`),
  KEY `idx_paanyaya_sessions_attempt` (`attempt_number`),
  KEY `fk_paanyaya_sessions_user` (`scheduled_by`),
  CONSTRAINT `fk_paanyaya_sessions_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_paanyaya_sessions_user` FOREIGN KEY (`scheduled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `paanyaya_stages`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `paanyaya_stages`;
CREATE TABLE `paanyaya_stages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned NOT NULL,
  `stage_type` enum('Paanyaya 1','Paanyaya 2') COLLATE utf8mb4_unicode_ci NOT NULL,
  `invitee_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Not Started','In Progress','Completed','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Not Started',
  `issued_at` datetime DEFAULT NULL,
  `issued_by` int unsigned DEFAULT NULL,
  `invitation_date` date DEFAULT NULL,
  `invitation_time` time DEFAULT NULL,
  `venue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
  `has_e_signature` tinyint(1) NOT NULL DEFAULT '1',
  `pdf_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_status` enum('None','Pending Review','Approved for Paanyaya 2','Approved for Summons','Case Closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'None',
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_complaint_stage` (`complaint_id`,`stage_type`),
  KEY `idx_paanyaya_stage_status` (`status`),
  KEY `fk_paanyaya_stage_issued_by` (`issued_by`),
  KEY `fk_paanyaya_stage_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_paanyaya_stage_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_paanyaya_stage_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_paanyaya_stage_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `paanyayas`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `paanyayas`;
CREATE TABLE `paanyayas` (
  `paanyaya_id` int unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int unsigned DEFAULT NULL,
  `complainant_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitee_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_time` time NOT NULL,
  `status` enum('Scheduled','Completed','Cancelled','Resolved','Unresolved','Escalated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Scheduled',
  `attempt_number` int unsigned NOT NULL DEFAULT '1',
  `venue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tanggapan ng Barangay Tumana',
  `salaysay` text COLLATE utf8mb4_unicode_ci,
  `complainant_attended` enum('Present','Absent') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitee_attended` enum('Present','Absent') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complainant_resident_id` int unsigned DEFAULT NULL,
  `complainant_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complainant_purok` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complainant_contact_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complainant_email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitee_resident_id` int unsigned DEFAULT NULL,
  `invitee_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitee_purok` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitee_contact_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invitee_email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complainant_latitude` decimal(10,7) DEFAULT NULL,
  `complainant_longitude` decimal(10,7) DEFAULT NULL,
  `invitee_latitude` decimal(10,7) DEFAULT NULL,
  `invitee_longitude` decimal(10,7) DEFAULT NULL,
  `location_latitude` decimal(10,7) DEFAULT NULL,
  `location_longitude` decimal(10,7) DEFAULT NULL,
  `dialogue_notes` text COLLATE utf8mb4_unicode_ci,
  `attendance_recorded_at` datetime DEFAULT NULL,
  `escalated_to_complaint_id` int unsigned DEFAULT NULL,
  `pdf_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduled_by` int unsigned DEFAULT NULL,
  `queue_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`paanyaya_id`),
  KEY `idx_paanyayas_complaint` (`complaint_id`),
  KEY `idx_paanyayas_status` (`status`),
  KEY `idx_paanyayas_date` (`scheduled_date`),
  KEY `fk_paanyayas_scheduled_by` (`scheduled_by`),
  CONSTRAINT `fk_paanyayas_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`complaint_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_paanyayas_scheduled_by` FOREIGN KEY (`scheduled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `pangkat_challenges`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `pangkat_challenges`;
CREATE TABLE `pangkat_challenges` (
  `challenge_id` int unsigned NOT NULL AUTO_INCREMENT,
  `pangkat_id` int unsigned NOT NULL,
  `case_id` int unsigned NOT NULL,
  `challenged_member_id` int unsigned NOT NULL,
  `challenger_party` enum('Complainant','Respondent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `challenger_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grounds` enum('Relationship within 4th degree','Financial or Personal Interest','Bias or Partiality','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ground_details` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `challenge_date` date NOT NULL,
  `status` enum('Pending','Sustained','Dismissed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `resolution_date` date DEFAULT NULL,
  `majority_decision` enum('Pending','Disqualify and Replace','Dismiss Challenge') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `majority_decision_notes` text COLLATE utf8mb4_unicode_ci,
  `voting_record` text COLLATE utf8mb4_unicode_ci,
  `replacement_member_id` int unsigned DEFAULT NULL,
  `replacement_method` enum('Party Agreement','Drawing of Lots','PB Appointment') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replacement_date` date DEFAULT NULL,
  `resolved_by_user_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`challenge_id`),
  KEY `idx_pc_pangkat` (`pangkat_id`),
  KEY `idx_pc_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `pangkat_duty_group_members`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `pangkat_duty_group_members`;
CREATE TABLE `pangkat_duty_group_members` (
  `id` int NOT NULL AUTO_INCREMENT,
  `duty_group_id` int NOT NULL,
  `member_id` int NOT NULL,
  `position` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_duty_group_position` (`duty_group_id`,`position`),
  UNIQUE KEY `uq_duty_group_member` (`duty_group_id`,`member_id`),
  KEY `idx_duty_group_member` (`member_id`),
  CONSTRAINT `fk_duty_group_members_group` FOREIGN KEY (`duty_group_id`) REFERENCES `pangkat_duty_groups` (`duty_group_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `pangkat_duty_group_members` (3 rows)
INSERT INTO `pangkat_duty_group_members` (`id`, `duty_group_id`, `member_id`, `position`, `created_at`) VALUES
('1', '1', '3', 'Chairman', '2026-11-06 05:44:35'),
('2', '1', '4', 'Secretary', '2026-11-06 05:44:35'),
('3', '1', '5', 'Member', '2026-11-06 05:44:35');

-- -----------------------------------------------------
-- Structure for table `pangkat_duty_groups`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `pangkat_duty_groups`;
CREATE TABLE `pangkat_duty_groups` (
  `duty_group_id` int NOT NULL AUTO_INCREMENT,
  `group_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duty_day` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` time NOT NULL DEFAULT '08:00:00',
  `end_time` time NOT NULL DEFAULT '17:00:00',
  `station` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Lupon Court Room',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`duty_group_id`),
  UNIQUE KEY `uq_pangkat_duty_group_name` (`group_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `pangkat_duty_groups` (1 rows)
INSERT INTO `pangkat_duty_groups` (`duty_group_id`, `group_name`, `duty_day`, `start_time`, `end_time`, `station`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
('1', 'Wednesday Conciliation Panel A', 'Wednesday', '08:00:00', '17:00:00', 'Lupon Court Room', 'Primary Wednesday conciliation panel for civil and boundary disputes.', '1', '1', '2026-11-06 05:44:35', '2026-11-06 05:44:35');

-- -----------------------------------------------------
-- Structure for table `pangkat_extensions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `pangkat_extensions`;
CREATE TABLE `pangkat_extensions` (
  `extension_id` int unsigned NOT NULL AUTO_INCREMENT,
  `pangkat_id` int unsigned NOT NULL,
  `case_id` int unsigned NOT NULL,
  `extension_type` enum('Discretionary','Clearly Meritorious') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Discretionary',
  `days_extended` int unsigned NOT NULL DEFAULT '15',
  `previous_deadline` date NOT NULL,
  `new_deadline` date NOT NULL,
  `justification` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `meritorious_basis` text COLLATE utf8mb4_unicode_ci,
  `approved_by` int unsigned NOT NULL,
  `granted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`extension_id`),
  KEY `idx_pe_pangkat` (`pangkat_id`),
  KEY `idx_pe_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `pangkat_groups`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `pangkat_groups`;
CREATE TABLE `pangkat_groups` (
  `pangkat_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `formation_date` date NOT NULL,
  `convening_deadline` date DEFAULT NULL,
  `convened_date` date DEFAULT NULL,
  `conciliation_deadline` date DEFAULT NULL,
  `is_extended` tinyint(1) NOT NULL DEFAULT '0',
  `extended_days` int unsigned NOT NULL DEFAULT '0',
  `extension_type` enum('None','Discretionary','Clearly Meritorious') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'None',
  `selection_method` enum('Party Agreement','PB Assignment','Raffle Draw') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Party Agreement',
  `selection_notes` text COLLATE utf8mb4_unicode_ci,
  `selection_stage` enum('Party Choice','Failed Agreement - PB Lots','Failed Agreement - PB Appointment','Constituted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Constituted',
  `party_agreement_failed` tinyint(1) NOT NULL DEFAULT '0',
  `party_agreement_failure_reason` text COLLATE utf8mb4_unicode_ci,
  `quorum_size` tinyint unsigned NOT NULL DEFAULT '3',
  `quorum_exception_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `duty_group_id` int DEFAULT NULL,
  PRIMARY KEY (`pangkat_id`),
  UNIQUE KEY `uq_pangkat_groups_case_id` (`case_id`),
  CONSTRAINT `fk_pangkat_groups_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `pangkat_members`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `pangkat_members`;
CREATE TABLE `pangkat_members` (
  `pangkat_member_id` int unsigned NOT NULL AUTO_INCREMENT,
  `pangkat_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `position` enum('Chairman','Secretary','Member') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Member',
  `status` enum('Active','Challenged','Disqualified','Replaced') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `disqualified_at` datetime DEFAULT NULL,
  `disqualification_reason` text COLLATE utf8mb4_unicode_ci,
  `replacement_for_member_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`pangkat_member_id`),
  UNIQUE KEY `uq_pangkat_members_member` (`pangkat_id`,`member_id`),
  KEY `idx_pangkat_members_member_id` (`member_id`),
  CONSTRAINT `fk_pangkat_members_group` FOREIGN KEY (`pangkat_id`) REFERENCES `pangkat_groups` (`pangkat_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pangkat_members_user` FOREIGN KEY (`member_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `pangkat_roster_groups`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `pangkat_roster_groups`;
CREATE TABLE `pangkat_roster_groups` (
  `group_id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `duty_day` enum('Monday','Tuesday','Wednesday','Thursday','Friday') COLLATE utf8mb4_unicode_ci NOT NULL,
  `chairman_id` int unsigned NOT NULL,
  `secretary_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `station` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Barangay Hall - Lupon Court Room',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`group_id`),
  KEY `idx_pangkat_roster_duty_day` (`duty_day`),
  KEY `idx_pangkat_roster_active` (`is_active`),
  KEY `fk_pangkat_roster_chair` (`chairman_id`),
  KEY `fk_pangkat_roster_secretary` (`secretary_id`),
  KEY `fk_pangkat_roster_member` (`member_id`),
  CONSTRAINT `fk_pangkat_roster_chair` FOREIGN KEY (`chairman_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pangkat_roster_member` FOREIGN KEY (`member_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pangkat_roster_secretary` FOREIGN KEY (`secretary_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `pangkat_roster_groups` (1 rows)
INSERT INTO `pangkat_roster_groups` (`group_id`, `group_name`, `duty_day`, `chairman_id`, `secretary_id`, `member_id`, `station`, `notes`, `is_active`, `created_at`, `updated_at`) VALUES
('1', 'Team Alpha (Wednesday)', 'Wednesday', '3', '5', '6', 'Barangay Hall - Lupon Court Room', 'Primary Wednesday Conciliation Panel', '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07');

-- -----------------------------------------------------
-- Structure for table `password_reset_tokens`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
  `reset_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `uq_password_reset_tokens_hash` (`token_hash`),
  KEY `idx_password_reset_tokens_user_expiry` (`user_id`,`expires_at`),
  CONSTRAINT `fk_password_reset_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `permissions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `permission_id` int unsigned NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `uq_permissions_permission_name` (`permission_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `proof_of_service`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `proof_of_service`;
CREATE TABLE `proof_of_service` (
  `proof_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `document_id` int unsigned DEFAULT NULL,
  `service_result` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Served',
  `served_by` int unsigned DEFAULT NULL,
  `served_date` datetime NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `image_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`proof_id`),
  KEY `idx_proof_of_service_case_id` (`case_id`),
  KEY `idx_proof_of_service_document_id` (`document_id`),
  KEY `fk_proof_of_service_user` (`served_by`),
  CONSTRAINT `fk_proof_of_service_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_proof_of_service_document` FOREIGN KEY (`document_id`) REFERENCES `generated_documents` (`document_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_proof_of_service_user` FOREIGN KEY (`served_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `residents`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `residents`;
CREATE TABLE `residents` (
  `resident_id` int unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `suffix` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `civil_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occupation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_contact` enum('SMS','Call','Email','In-Person') COLLATE utf8mb4_unicode_ci DEFAULT 'SMS',
  `address` text COLLATE utf8mb4_unicode_ci,
  `house_street` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `barangay` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tumana',
  `city_municipality` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Marikina City',
  `home_latitude` decimal(10,8) DEFAULT NULL,
  `home_longitude` decimal(11,8) DEFAULT NULL,
  `purok` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_tenant` tinyint(1) NOT NULL DEFAULT '0',
  `residency_verified` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`resident_id`),
  KEY `idx_residents_name` (`last_name`,`first_name`),
  KEY `idx_residents_jurisdiction` (`barangay`,`is_tenant`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `residents` (3 rows)
INSERT INTO `residents` (`resident_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `birth_date`, `gender`, `civil_status`, `occupation`, `contact_no`, `email`, `preferred_contact`, `address`, `house_street`, `barangay`, `city_municipality`, `home_latitude`, `home_longitude`, `purok`, `is_tenant`, `residency_verified`, `created_at`, `updated_at`) VALUES
('1', 'Jose', 'P.', 'Rizal', NULL, NULL, 'Male', 'Married', 'Property Owner', '09170000001', 'jose.rizal@tumana.test', 'SMS', '123 J.P. Rizal St., Purok 1, Tumana, Marikina City', '123 J.P. Rizal St.', 'Tumana', 'Marikina City', NULL, NULL, NULL, '0', '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('2', 'Andres', 'C.', 'Bonifacio', NULL, NULL, 'Male', 'Married', 'Building Contractor', '09170000002', 'andres.bonifacio@tumana.test', 'SMS', '456 Katipunan Ave., Purok 2, Tumana, Marikina City', '456 Katipunan Ave.', 'Tumana', 'Marikina City', NULL, NULL, NULL, '0', '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('3', 'Gabriela', 'S.', 'Silang', NULL, NULL, 'Female', 'Single', 'Public School Teacher', '09170000003', 'gabriela.silang@tumana.test', 'SMS', '789 Silang St., Purok 3, Tumana, Marikina City', '789 Silang St.', 'Tumana', 'Marikina City', NULL, NULL, NULL, '0', '1', '2026-11-06 05:17:07', '2026-11-06 05:17:07');

-- -----------------------------------------------------
-- Structure for table `role_permissions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `role_id` int unsigned NOT NULL,
  `permission_id` int unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `roles`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `role_id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `uq_roles_role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `roles` (4 rows)
INSERT INTO `roles` (`role_id`, `role_name`, `created_at`) VALUES
('1', 'Administrator', '2026-11-06 05:17:07'),
('2', 'Lupon Clerk', '2026-11-06 05:17:07'),
('3', 'Lupon Member', '2026-11-06 05:17:07'),
('4', 'Summons Server', '2026-11-06 05:17:07');

-- -----------------------------------------------------
-- Structure for table `scheduling_holidays`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `scheduling_holidays`;
CREATE TABLE `scheduling_holidays` (
  `holiday_id` int unsigned NOT NULL AUTO_INCREMENT,
  `holiday_date` date NOT NULL,
  `holiday_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0',
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`holiday_id`),
  UNIQUE KEY `holiday_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `settlement_executions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `settlement_executions`;
CREATE TABLE `settlement_executions` (
  `execution_id` int unsigned NOT NULL AUTO_INCREMENT,
  `settlement_id` int unsigned NOT NULL,
  `case_id` int unsigned NOT NULL,
  `obligation_violated` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_date` date NOT NULL,
  `amount_or_requirement` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `evidence_notes` text COLLATE utf8mb4_unicode_ci,
  `evidence_file_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `motion_date` date NOT NULL,
  `motion_filed_by` int unsigned NOT NULL,
  `notice_of_execution_date` date DEFAULT NULL,
  `execution_status` enum('Motion Filed','Notice Issued','Execution In Progress','Complied Under Execution','Execution Failed','Endorsed to Court') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Motion Filed',
  `action_taken` text COLLATE utf8mb4_unicode_ci,
  `officer_assigned` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`execution_id`),
  KEY `idx_se_settlement` (`settlement_id`),
  KEY `idx_se_case` (`case_id`),
  KEY `fk_se_resident` (`motion_filed_by`),
  KEY `fk_se_officer` (`officer_assigned`),
  CONSTRAINT `fk_se_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_se_officer` FOREIGN KEY (`officer_assigned`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_se_resident` FOREIGN KEY (`motion_filed_by`) REFERENCES `residents` (`resident_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_se_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`settlement_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `settlement_installments`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `settlement_installments`;
CREATE TABLE `settlement_installments` (
  `installment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `settlement_id` int unsigned NOT NULL,
  `installment_number` int unsigned NOT NULL,
  `due_date` date NOT NULL,
  `amount_due` decimal(12,2) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_date` datetime DEFAULT NULL,
  `payment_status` enum('Pending','Partially Paid','Paid','Overdue') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `receipt_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `recorded_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`installment_id`),
  KEY `idx_si_settlement` (`settlement_id`),
  KEY `idx_si_due_date` (`due_date`),
  KEY `fk_si_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_si_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_si_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`settlement_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `settlements`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `settlements`;
CREATE TABLE `settlements` (
  `settlement_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `settlement_date` date NOT NULL,
  `agreement_details` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `terms_read_to_parties` tinyint(1) NOT NULL DEFAULT '0',
  `complainant_signed` tinyint(1) NOT NULL DEFAULT '0',
  `respondent_signed` tinyint(1) NOT NULL DEFAULT '0',
  `pb_attested` tinyint(1) NOT NULL DEFAULT '0',
  `pb_attested_by` int unsigned DEFAULT NULL,
  `pb_attested_at` datetime DEFAULT NULL,
  `barangay_sealed` tinyint(1) NOT NULL DEFAULT '0',
  `original_in_case_folder` tinyint(1) NOT NULL DEFAULT '0',
  `certified_copies_issued` tinyint(1) NOT NULL DEFAULT '0',
  `total_amount` decimal(12,2) DEFAULT '0.00',
  `responsible_party` enum('Respondent','Complainant','Both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Respondent',
  `has_installment` tinyint(1) NOT NULL DEFAULT '0',
  `compliance_due_date` date DEFAULT NULL,
  `compliance_date` date DEFAULT NULL,
  `repudiation_deadline` date DEFAULT NULL,
  `award_type` enum('Amicable Settlement','Arbitration Award') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Amicable Settlement',
  `obligation_type` enum('Monetary','Property Return','Specific Performance','Hybrid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Monetary',
  `six_month_deadline` date DEFAULT NULL,
  `repudiation_status` enum('Within Repudiation Period','Repudiation Expired','Repudiated','Enforceable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Within Repudiation Period',
  `repudiated_by` int unsigned DEFAULT NULL,
  `repudiation_reason` text COLLATE utf8mb4_unicode_ci,
  `repudiation_date` datetime DEFAULT NULL,
  `supporting_document_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compliance_status` enum('Pending','Partially Paid','Fully Paid','Overdue','Breached','Complied','Violated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`settlement_id`),
  UNIQUE KEY `uq_settlements_case_id` (`case_id`),
  KEY `fk_settlements_pb_attested_by` (`pb_attested_by`),
  KEY `fk_settlements_repudiated_by` (`repudiated_by`),
  CONSTRAINT `fk_settlements_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_settlements_pb_attested_by` FOREIGN KEY (`pb_attested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_settlements_repudiated_by` FOREIGN KEY (`repudiated_by`) REFERENCES `residents` (`resident_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `show_cause_evaluations`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `show_cause_evaluations`;
CREATE TABLE `show_cause_evaluations` (
  `evaluation_id` int unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned NOT NULL,
  `case_id` int unsigned NOT NULL,
  `party_type` enum('Complainant','Respondent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `form_type` enum('KP Form 18','KP Form 19') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_justified` tinyint(1) NOT NULL,
  `justification_category` enum('Medical Emergency','Force Majeure','Official Duty','Unjustified Absence','Willful Refusal','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `justification_notes` text COLLATE utf8mb4_unicode_ci,
  `rescheduled_hearing_id` int unsigned DEFAULT NULL,
  `action_taken` enum('Rescheduled','Barred Action','Respondent Default','CFA Issued') COLLATE utf8mb4_unicode_ci NOT NULL,
  `evaluated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`evaluation_id`),
  KEY `idx_show_cause_hearing` (`hearing_id`),
  KEY `idx_show_cause_case` (`case_id`),
  KEY `fk_show_cause_rescheduled` (`rescheduled_hearing_id`),
  KEY `fk_show_cause_evaluated_by` (`evaluated_by`),
  CONSTRAINT `fk_show_cause_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_show_cause_evaluated_by` FOREIGN KEY (`evaluated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_show_cause_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_show_cause_rescheduled` FOREIGN KEY (`rescheduled_hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `stage_transitions`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `stage_transitions`;
CREATE TABLE `stage_transitions` (
  `transition_id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `stage_type` enum('Mediation','Conciliation','Arbitration') COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `performed_by` int unsigned NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transition_id`),
  KEY `idx_st_case` (`case_id`),
  KEY `fk_st_user` (`performed_by`),
  CONSTRAINT `fk_st_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_st_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `summon_deliveries`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `summon_deliveries`;
CREATE TABLE `summon_deliveries` (
  `delivery_id` int unsigned NOT NULL AUTO_INCREMENT,
  `hearing_id` int unsigned DEFAULT NULL,
  `case_id` int unsigned DEFAULT NULL,
  `party_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Respondent',
  `resident_id` int unsigned DEFAULT NULL,
  `party_id` int DEFAULT NULL,
  `form_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Notice of Hearing',
  `attempt_number` tinyint unsigned NOT NULL DEFAULT '1',
  `attempt_count` tinyint unsigned NOT NULL DEFAULT '1',
  `delivery_status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `service_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempted_at` datetime DEFAULT NULL,
  `served_at` datetime DEFAULT NULL,
  `served_by` int unsigned DEFAULT NULL,
  `served_by_user_id` int DEFAULT NULL,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relationship` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unserved_reason` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `failure_notes` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `service_address` text COLLATE utf8mb4_unicode_ci,
  `proof_file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_id` int unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`delivery_id`),
  UNIQUE KEY `uq_hearing_party_form_attempt` (`hearing_id`,`party_type`,`resident_id`,`form_type`,`attempt_number`),
  KEY `idx_summon_deliveries_hearing_status` (`hearing_id`,`delivery_status`),
  KEY `idx_summon_deliveries_served_by` (`served_by`),
  KEY `idx_summon_deliveries_case` (`case_id`),
  KEY `idx_summon_deliveries_document` (`document_id`),
  KEY `idx_summon_deliveries_case_attempt` (`case_id`,`attempt_number`),
  KEY `fk_summon_deliveries_resident` (`resident_id`),
  CONSTRAINT `fk_summon_deliveries_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_summon_deliveries_document` FOREIGN KEY (`document_id`) REFERENCES `generated_documents` (`document_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_summon_deliveries_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `hearings` (`hearing_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_summon_deliveries_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_summon_deliveries_served_by` FOREIGN KEY (`served_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `system_settings`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `system_settings` (16 rows)
INSERT INTO `system_settings` (`setting_id`, `setting_key`, `setting_value`, `description`, `updated_at`) VALUES
('1', 'gemini_api_key', '', 'Google Gemini API Key for AI Narrative Refinement, OCR Scans, and Audio Transcription', '2026-11-06 05:17:07'),
('2', 'gemini_model', 'gemini-3.8-flash', 'Active Gemini AI Model', '2026-11-06 05:17:07'),
('3', 'captain_name', 'HON. AKIKO LYNNE CENTENO', 'Official Name of Punong Barangay', '2026-11-06 05:17:07'),
('4', 'mediation_default_duration_min', '45', 'Default duration for mediation session in minutes (30-60)', '2026-11-06 05:17:07'),
('5', 'mediation_max_duration_min', '60', 'Maximum duration before logged justification is required for mediation', '2026-11-06 05:17:07'),
('6', 'conciliation_default_duration_min', '60', 'Default duration for conciliation session in minutes (45-90)', '2026-11-06 05:17:07'),
('7', 'conciliation_max_duration_min', '90', 'Maximum duration before logged justification is required for conciliation', '2026-11-06 05:17:07'),
('8', 'mediation_session_interval_days', '3', 'Recommended minimum calendar days between mediation sessions (3-5)', '2026-11-06 05:17:07'),
('9', 'party_reschedule_limit', '2', 'Maximum allowed reschedules per party before authorized review is required (1-2)', '2026-11-06 05:17:07'),
('10', 'summons_max_attempts', '6', 'Standard cap for summons and officer return delivery attempts before CFA eligibility (1-6)', '2026-11-06 05:17:07'),
('11', 'summons_attempt_interval_days', '1', 'Minimum days between summons service attempts (1-3)', '2026-11-06 05:17:07'),
('12', 'mediation_day_of_week', 'Tue', 'Dedicated day of week for Punong Barangay mediation hearings (Tuesday Only rule)', '2026-11-06 05:17:07'),
('13', 'working_hours_start', '08:00', 'Fixed regular hearing-hours opening time', '2026-11-06 05:17:07'),
('14', 'working_hours_end', '17:00', 'Fixed regular hearing-hours closing time', '2026-11-06 05:17:07'),
('15', 'mediation_hours_start', '08:00', 'Fixed formal mediation opening time', '2026-11-06 05:17:07'),
('16', 'mediation_hours_end', '17:00', 'Fixed formal mediation closing time', '2026-11-06 05:17:07');

-- -----------------------------------------------------
-- Structure for table `users`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id` int unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `middle_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role_id` int unsigned NOT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `activation_token` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activation_expires_at` datetime DEFAULT NULL,
  `email_verification_token` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verification_expires_at` datetime DEFAULT NULL,
  `notification_audio` tinyint(1) NOT NULL DEFAULT '1',
  `notification_inbox` tinyint(1) NOT NULL DEFAULT '1',
  `preferred_language` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fil',
  `avatar_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_activation_token` (`activation_token`),
  UNIQUE KEY `uq_users_email_verification_token` (`email_verification_token`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_activation_expiry` (`activation_expires_at`),
  KEY `idx_users_email_verification_expiry` (`email_verification_expires_at`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table `users` (7 rows)
INSERT INTO `users` (`user_id`, `first_name`, `middle_name`, `last_name`, `username`, `email`, `contact_no`, `password_hash`, `role_id`, `status`, `activation_token`, `activation_expires_at`, `email_verification_token`, `email_verification_expires_at`, `notification_audio`, `notification_inbox`, `preferred_language`, `avatar_path`, `created_at`, `updated_at`) VALUES
('1', 'Akiko Lynne', 'M.', 'Centeno', 'admin', 'admin@agap.test', '09763198285', '$2y$12$poLM7m4KluD/WLCixCJi8.TSKxaEIthqba6uIhZHiib62Bbu1bZnC', '1', 'Active', NULL, NULL, NULL, NULL, '1', '1', 'fil', NULL, '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('2', 'Maria Clara', 'S.', 'Santos', 'clerk', 'clerk@agap.test', '09171234567', '$2y$12$poLM7m4KluD/WLCixCJi8.TSKxaEIthqba6uIhZHiib62Bbu1bZnC', '2', 'Active', NULL, NULL, NULL, NULL, '1', '1', 'fil', NULL, '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('3', 'Juan', 'D.', 'Dela Cruz', 'pangkatchair', 'pangkatchair@agap.test', '09181234561', '$2y$12$poLM7m4KluD/WLCixCJi8.TSKxaEIthqba6uIhZHiib62Bbu1bZnC', '3', 'Active', NULL, NULL, NULL, NULL, '1', '1', 'fil', NULL, '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('4', 'Lupon', 'D.', 'Head', 'luponhead', 'luponhead@agap.test', '09181234564', '$2y$12$poLM7m4KluD/WLCixCJi8.TSKxaEIthqba6uIhZHiib62Bbu1bZnC', '3', 'Active', NULL, NULL, NULL, NULL, '1', '1', 'fil', NULL, '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('5', 'Elena', 'S.', 'Bautista', 'luponsecretary', 'luponsecretary@agap.test', '09181234562', '$2y$12$poLM7m4KluD/WLCixCJi8.TSKxaEIthqba6uIhZHiib62Bbu1bZnC', '3', 'Active', NULL, NULL, NULL, NULL, '1', '1', 'fil', NULL, '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('6', 'Roberto', 'T.', 'Mercado', 'luponmember', 'luponmember@agap.test', '09181234563', '$2y$12$poLM7m4KluD/WLCixCJi8.TSKxaEIthqba6uIhZHiib62Bbu1bZnC', '3', 'Active', NULL, NULL, NULL, NULL, '1', '1', 'fil', NULL, '2026-11-06 05:17:07', '2026-11-06 05:17:07'),
('7', 'Ricardo', 'G.', 'Dalisay', 'summonsserver', 'summonsserver@agap.test', '09191234567', '$2y$12$poLM7m4KluD/WLCixCJi8.TSKxaEIthqba6uIhZHiib62Bbu1bZnC', '4', 'Active', NULL, NULL, NULL, NULL, '1', '1', 'fil', NULL, '2026-11-06 05:17:07', '2026-11-06 05:17:07');

-- -----------------------------------------------------
-- Structure for table `venue_unavailabilities`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `venue_unavailabilities`;
CREATE TABLE `venue_unavailabilities` (
  `unavailability_id` int unsigned NOT NULL AUTO_INCREMENT,
  `venue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorded_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`unavailability_id`),
  KEY `idx_vu_venue_time` (`venue`,`start_time`,`end_time`),
  KEY `fk_vu_user` (`recorded_by`),
  CONSTRAINT `fk_vu_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Structure for table `view_conciliation_hearing_returns`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `view_conciliation_hearing_returns`;
;

SET FOREIGN_KEY_CHECKS=1;
-- Backup completed successfully.
