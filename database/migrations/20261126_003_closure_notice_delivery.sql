-- Existing notices cannot be assumed delivered. Old Pending requests remain
-- in review until explicitly cancelled and re-issued through verified delivery.
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'closure_requests' AND COLUMN_NAME = 'notice_delivery_status'), 'SELECT 1', 'ALTER TABLE closure_requests ADD COLUMN notice_delivery_status ENUM(''Unverified'', ''Delivered'') NOT NULL DEFAULT ''Unverified'' AFTER notice_sent_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'closure_requests' AND COLUMN_NAME = 'notice_delivery_channels'), 'SELECT 1', 'ALTER TABLE closure_requests ADD COLUMN notice_delivery_channels VARCHAR(255) NULL AFTER notice_delivery_status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
