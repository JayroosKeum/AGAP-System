-- Migration: 20261026_003_create_hearing_minutes_documents.sql
-- Create hearing_minutes_documents for handwritten minutes scans and hearing evidence attachments

CREATE TABLE IF NOT EXISTS `hearing_minutes_documents` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `hearing_id` INT UNSIGNED NOT NULL,
  `case_id` INT UNSIGNED NOT NULL,
  `document_type` ENUM('handwritten_minutes', 'evidence_attachment') NOT NULL DEFAULT 'evidence_attachment',
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT UNSIGNED DEFAULT 0,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (`hearing_id`),
  INDEX (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
