-- Elastic Hearing Duration and Daily Docket Queue Schema Update

ALTER TABLE hearings 
    ADD COLUMN IF NOT EXISTS actual_start_time DATETIME NULL AFTER actual_end_time,
    ADD COLUMN IF NOT EXISTS actual_duration_minutes INT UNSIGNED NULL AFTER actual_start_time,
    ADD COLUMN IF NOT EXISTS queue_status ENUM('Waiting', 'Ready', 'In_Session', 'Standby', 'Concluded') DEFAULT 'Waiting' AFTER actual_duration_minutes;

ALTER TABLE paanyaya_sessions 
    ADD COLUMN IF NOT EXISTS actual_start_time DATETIME NULL,
    ADD COLUMN IF NOT EXISTS actual_end_time DATETIME NULL,
    ADD COLUMN IF NOT EXISTS queue_status ENUM('Waiting', 'Ready', 'In_Session', 'Standby', 'Concluded') DEFAULT 'Waiting';
