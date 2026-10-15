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
    'pangkat_groups',
    'request_id',
    'CHAR(36) NULL AFTER `pangkat_id`'
);

CALL add_column_if_missing(
    'pangkat_groups',
    'constituted_by',
    'BIGINT UNSIGNED NULL AFTER `quorum_size`'
);

CALL add_column_if_missing(
    'pangkat_groups',
    'constituted_at',
    'DATETIME NULL AFTER `constituted_by`'
);

CALL add_column_if_missing(
    'pangkat_groups',
    'panel_status',
    'VARCHAR(30) NOT NULL DEFAULT ''Constituted'' AFTER `constituted_at`'
);

CALL add_column_if_missing(
    'pangkat_groups',
    'conflict_check_completed',
    'TINYINT(1) NOT NULL DEFAULT 0 AFTER `panel_status`'
);

CALL add_column_if_missing(
    'pangkat_groups',
    'conflict_check_notes',
    'VARCHAR(1000) NULL AFTER `conflict_check_completed`'
);

CALL add_column_if_missing(
    'pangkat_groups',
    'reconstitution_reason',
    'VARCHAR(1000) NULL AFTER `conflict_check_notes`'
);

UPDATE pangkat_groups
SET request_id = UUID()
WHERE request_id IS NULL OR TRIM(request_id) = '';

UPDATE pangkat_groups
SET constituted_at = COALESCE(
    constituted_at,
    CASE
        WHEN formation_date IS NOT NULL
            THEN TIMESTAMP(formation_date, '00:00:00')
        ELSE NOW()
    END
)
WHERE constituted_at IS NULL;

ALTER TABLE pangkat_groups
    MODIFY COLUMN request_id CHAR(36) NOT NULL;

CALL add_index_if_missing(
    'pangkat_groups',
    'uq_pangkat_groups_request',
    'UNIQUE INDEX `uq_pangkat_groups_request` (`request_id`)'
);

CALL add_index_if_missing(
    'pangkat_groups',
    'idx_pangkat_groups_case_status',
    'INDEX `idx_pangkat_groups_case_status` (`case_id`, `panel_status`)'
);

CALL add_column_if_missing(
    'pangkat_members',
    'acceptance_date',
    'DATE NULL AFTER `status`'
);

CALL add_column_if_missing(
    'pangkat_members',
    'conflict_declared',
    'TINYINT(1) NOT NULL DEFAULT 0 AFTER `acceptance_date`'
);

CALL add_column_if_missing(
    'pangkat_members',
    'conflict_notes',
    'VARCHAR(1000) NULL AFTER `conflict_declared`'
);

CALL add_column_if_missing(
    'pangkat_members',
    'replaced_at',
    'DATETIME NULL AFTER `conflict_notes`'
);

CALL add_column_if_missing(
    'pangkat_members',
    'replacement_reason',
    'VARCHAR(1000) NULL AFTER `replaced_at`'
);

CALL add_index_if_missing(
    'pangkat_members',
    'idx_pangkat_members_panel_status',
    'INDEX `idx_pangkat_members_panel_status` (`pangkat_id`, `status`, `position`)'
);

DROP PROCEDURE IF EXISTS add_column_if_missing;
DROP PROCEDURE IF EXISTS add_index_if_missing;

SELECT 'PANGKAT_CONCILIATION_REFINED' AS result;
