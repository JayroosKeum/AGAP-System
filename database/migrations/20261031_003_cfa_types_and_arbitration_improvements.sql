-- Migration: Add cfa_type to cfa_records and update unique index
SET @exist := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cfa_records'
      AND COLUMN_NAME = 'cfa_type'
);

SET @sqlstmt := IF(@exist = 0,
    "ALTER TABLE cfa_records ADD COLUMN cfa_type ENUM('KP Form 20', 'KP Form 20-A', 'KP Form 20-B') NOT NULL DEFAULT 'KP Form 20' AFTER case_id",
    "SELECT 'Column cfa_type already exists'"
);
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- First add composite unique index (case_id, cfa_type)
SET @new_index_exist := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cfa_records'
      AND INDEX_NAME = 'uq_cfa_records_case_type'
);

SET @sqlstmt := IF(@new_index_exist = 0,
    "ALTER TABLE cfa_records ADD UNIQUE KEY uq_cfa_records_case_type (case_id, cfa_type)",
    "SELECT 'Index uq_cfa_records_case_type already exists'"
);
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Now drop uq_cfa_records_case_id since uq_cfa_records_case_type satisfies fk_cfa_records_case
SET @index_exist := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cfa_records'
      AND INDEX_NAME = 'uq_cfa_records_case_id'
);

SET @sqlstmt := IF(@index_exist > 0,
    "ALTER TABLE cfa_records DROP INDEX uq_cfa_records_case_id",
    "SELECT 'Index uq_cfa_records_case_id does not exist'"
);
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
