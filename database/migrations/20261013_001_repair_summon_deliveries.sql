-- AGAP safe, additive migration for summon_deliveries
-- Converted from: 20261013_001_repair_summon_deliveries.php
-- Target: MySQL / MariaDB
-- This script does not drop the table, existing columns, or existing records.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `summon_deliveries` (
    `delivery_id` INT NOT NULL AUTO_INCREMENT,
    `document_id` INT NULL,
    `case_id` INT NULL,
    `party_id` INT NULL,
    `party_type` VARCHAR(30) NULL,
    `attempt_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `service_method` VARCHAR(50) NULL,
    `delivery_status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
    `attempted_at` DATETIME NULL,
    `served_at` DATETIME NULL,
    `served_by_user_id` INT NULL,
    `recipient_name` VARCHAR(255) NULL,
    `service_address` TEXT NULL,
    `remarks` TEXT NULL,
    `proof_file_path` VARCHAR(500) NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`delivery_id`),
    KEY `idx_summon_deliveries_document` (`document_id`),
    KEY `idx_summon_deliveries_case` (`case_id`),
    KEY `idx_summon_deliveries_status` (`delivery_status`),
    KEY `idx_summon_deliveries_case_attempt` (`case_id`, `attempt_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP PROCEDURE IF EXISTS `agap_add_column_if_missing`$$
CREATE PROCEDURE `agap_add_column_if_missing`(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_column_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @sql_statement = CONCAT(
            'ALTER TABLE `', REPLACE(p_table_name, '`', '``'),
            '` ADD COLUMN `', REPLACE(p_column_name, '`', '``'),
            '` ', p_column_definition
        );
        PREPARE agap_stmt FROM @sql_statement;
        EXECUTE agap_stmt;
        DEALLOCATE PREPARE agap_stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS `agap_add_index_if_missing`$$
CREATE PROCEDURE `agap_add_index_if_missing`(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_index_columns TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @sql_statement = CONCAT(
            'ALTER TABLE `', REPLACE(p_table_name, '`', '``'),
            '` ADD INDEX `', REPLACE(p_index_name, '`', '``'),
            '` (', p_index_columns, ')'
        );
        PREPARE agap_stmt FROM @sql_statement;
        EXECUTE agap_stmt;
        DEALLOCATE PREPARE agap_stmt;
    END IF;
END$$

CALL `agap_add_column_if_missing`('summon_deliveries', 'document_id', 'INT NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'case_id', 'INT NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'party_id', 'INT NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'party_type', 'VARCHAR(30) NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'attempt_number', 'TINYINT UNSIGNED NOT NULL DEFAULT 1')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'service_method', 'VARCHAR(50) NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'delivery_status', 'VARCHAR(30) NOT NULL DEFAULT ''Pending''')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'attempted_at', 'DATETIME NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'served_at', 'DATETIME NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'served_by_user_id', 'INT NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'recipient_name', 'VARCHAR(255) NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'service_address', 'TEXT NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'remarks', 'TEXT NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'proof_file_path', 'VARCHAR(500) NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'created_by', 'INT NULL')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP')$$
CALL `agap_add_column_if_missing`('summon_deliveries', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')$$

CALL `agap_add_index_if_missing`('summon_deliveries', 'idx_summon_deliveries_document', '`document_id`')$$
CALL `agap_add_index_if_missing`('summon_deliveries', 'idx_summon_deliveries_case', '`case_id`')$$
CALL `agap_add_index_if_missing`('summon_deliveries', 'idx_summon_deliveries_status', '`delivery_status`')$$
CALL `agap_add_index_if_missing`('summon_deliveries', 'idx_summon_deliveries_case_attempt', '`case_id`, `attempt_number`')$$

DROP PROCEDURE IF EXISTS `agap_add_column_if_missing`$$
DROP PROCEDURE IF EXISTS `agap_add_index_if_missing`$$
DELIMITER ;

UPDATE `summon_deliveries`
SET `delivery_status` = 'Pending'
WHERE `delivery_status` IS NULL OR TRIM(`delivery_status`) = '';

SELECT '[OK] summon_deliveries SQL migration completed safely.' AS `migration_result`;
