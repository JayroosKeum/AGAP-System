-- AGAP safe, additive migration for the legal Mediation commencement event.
-- Target: MySQL / MariaDB
-- This does not replace hearings or existing deadline records.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `mediation_commencements` (
    `commencement_id` INT NOT NULL AUTO_INCREMENT,
    `case_id` INT NOT NULL,
    `hearing_id` INT NOT NULL,
    `first_actual_meeting_at` DATETIME NOT NULL,
    `mediation_deadline_at` DATETIME NOT NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'Attendance',
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`commencement_id`),
    UNIQUE KEY `uq_mediation_commencement_case` (`case_id`),
    UNIQUE KEY `uq_mediation_commencement_hearing` (`hearing_id`),
    KEY `idx_mediation_commencement_deadline` (`mediation_deadline_at`),
    KEY `idx_mediation_commencement_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT '[OK] mediation_commencements migration completed safely.' AS `migration_result`;
