-- Add transmittal_notified column to case_transmittals if not present
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'case_transmittals' 
      AND COLUMN_NAME = 'transmittal_notified'
);

SET @stmt = IF(@col_exists = 0, 
    'ALTER TABLE case_transmittals ADD COLUMN transmittal_notified TINYINT(1) NOT NULL DEFAULT 0 AFTER status;', 
    'SELECT "Column transmittal_notified already exists in case_transmittals";'
);

PREPARE alterIfNotExists FROM @stmt;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
