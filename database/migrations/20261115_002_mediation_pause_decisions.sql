-- Rerunnable migration: ensure mediation_pause_decisions exists for recorded pause & extension bases
CREATE TABLE IF NOT EXISTS `mediation_pause_decisions` (
    `decision_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id` INT UNSIGNED NOT NULL,
    `legal_basis` VARCHAR(120) NOT NULL,
    `reason` TEXT NOT NULL,
    `notes` TEXT NULL,
    `decided_by` INT UNSIGNED NULL,
    `paused_at` DATETIME NOT NULL,
    `resumed_at` DATETIME NULL,
    `extension_authorised` TINYINT(1) NOT NULL DEFAULT 0,
    `extension_days` INT NOT NULL DEFAULT 0,
    `extension_basis` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`decision_id`),
    KEY `idx_mpd_case_paused` (`case_id`, `paused_at`),
    CONSTRAINT `fk_mpd_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`case_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
