USE remastered_db;

DROP PROCEDURE IF EXISTS add_column_if_missing;
DROP PROCEDURE IF EXISTS add_index_if_missing;

DELIMITER $$

CREATE PROCEDURE add_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_column_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND column_name = p_column_name
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', REPLACE(p_table_name, '`', '``'),
            '` ADD COLUMN `', REPLACE(p_column_name, '`', '``'),
            '` ', p_column_definition
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE PROCEDURE add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_index_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND index_name = p_index_name
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', REPLACE(p_table_name, '`', '``'),
            '` ADD ', p_index_definition
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL add_column_if_missing(
    'summon_deliveries',
    'request_id',
    'CHAR(36) NULL AFTER `delivery_id`'
);

CALL add_column_if_missing(
    'summon_deliveries',
    'recorded_by',
    'BIGINT UNSIGNED NULL AFTER `unserved_reason`'
);

CALL add_column_if_missing(
    'summon_deliveries',
    'recorded_at',
    'DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER `recorded_by`'
);

CALL add_column_if_missing(
    'summon_deliveries',
    'corrected_from_delivery_id',
    'BIGINT UNSIGNED NULL AFTER `recorded_at`'
);

CALL add_column_if_missing(
    'summon_deliveries',
    'correction_reason',
    'VARCHAR(500) NULL AFTER `corrected_from_delivery_id`'
);

CALL add_column_if_missing(
    'summon_deliveries',
    'is_void',
    'TINYINT(1) NOT NULL DEFAULT 0 AFTER `correction_reason`'
);

CALL add_column_if_missing(
    'summon_deliveries',
    'voided_by',
    'BIGINT UNSIGNED NULL AFTER `is_void`'
);

CALL add_column_if_missing(
    'summon_deliveries',
    'voided_at',
    'DATETIME NULL AFTER `voided_by`'
);

CALL add_column_if_missing(
    'summon_deliveries',
    'void_reason',
    'VARCHAR(500) NULL AFTER `voided_at`'
);

UPDATE summon_deliveries
SET request_id = UUID()
WHERE request_id IS NULL OR TRIM(request_id) = '';

UPDATE summon_deliveries
SET recorded_at = COALESCE(recorded_at, attempted_at, served_at, created_at, NOW())
WHERE recorded_at IS NULL;

ALTER TABLE summon_deliveries
    MODIFY COLUMN request_id CHAR(36) NOT NULL;

CALL add_index_if_missing(
    'summon_deliveries',
    'uq_summon_deliveries_request',
    'UNIQUE INDEX `uq_summon_deliveries_request` (`request_id`)'
);

CALL add_index_if_missing(
    'summon_deliveries',
    'idx_summon_delivery_case_party_attempt',
    'INDEX `idx_summon_delivery_case_party_attempt` (`case_id`, `party_type`, `attempt_number`)'
);

CALL add_index_if_missing(
    'summon_deliveries',
    'idx_summon_delivery_case_status',
    'INDEX `idx_summon_delivery_case_status` (`case_id`, `delivery_status`, `is_void`)'
);

CALL add_index_if_missing(
    'summon_deliveries',
    'idx_summon_delivery_document',
    'INDEX `idx_summon_delivery_document` (`document_id`)'
);

DROP PROCEDURE IF EXISTS add_column_if_missing;
DROP PROCEDURE IF EXISTS add_index_if_missing;

SELECT 'SUMMON_DELIVERIES_REFINED' AS result;
