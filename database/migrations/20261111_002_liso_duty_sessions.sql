-- LISO duty availability for walk-in intake screening.
-- A NULL active_marker means a completed session; the unique key permits only
-- one active session for each Lupon Member at a time.
CREATE TABLE IF NOT EXISTS liso_duty_sessions (
    duty_session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    active_marker TINYINT UNSIGNED NULL DEFAULT 1,
    started_by INT UNSIGNED NOT NULL,
    ended_by INT UNSIGNED NULL,
    end_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_liso_duty_one_active_session (user_id, active_marker),
    KEY idx_liso_duty_active_started (active_marker, started_at),
    CONSTRAINT fk_liso_duty_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_liso_duty_started_by FOREIGN KEY (started_by) REFERENCES users (user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_liso_duty_ended_by FOREIGN KEY (ended_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
