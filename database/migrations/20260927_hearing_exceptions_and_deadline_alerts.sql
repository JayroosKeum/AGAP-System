-- Hearing-exception actions and one-time deadline notification tracking.
CREATE TABLE hearing_nonappearances (
    nonappearance_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    party_type ENUM('Complainant', 'Respondent') NOT NULL,
    finding ENUM('Unjustified') NOT NULL DEFAULT 'Unjustified',
    remarks TEXT NULL,
    resolution ENUM('Pending', 'Rescheduled', 'Re-summons Issued') NOT NULL DEFAULT 'Pending',
    recorded_by INT UNSIGNED NULL,
    resolved_by INT UNSIGNED NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    UNIQUE KEY uq_hearing_nonappearance_party (hearing_id, resident_id),
    KEY idx_hearing_nonappearances_resolution (resolution),
    CONSTRAINT fk_hearing_nonappearances_hearing FOREIGN KEY (hearing_id) REFERENCES hearings (hearing_id) ON DELETE CASCADE,
    CONSTRAINT fk_hearing_nonappearances_resident FOREIGN KEY (resident_id) REFERENCES residents (resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_hearing_nonappearances_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_hearing_nonappearances_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE deadline_alert_log (
    deadline_alert_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deadline_id INT UNSIGNED NOT NULL,
    alert_type ENUM('Due Soon', 'Overdue') NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deadline_alert_once (deadline_id, alert_type),
    CONSTRAINT fk_deadline_alert_log_deadline FOREIGN KEY (deadline_id) REFERENCES case_deadlines (deadline_id) ON DELETE CASCADE
) ENGINE=InnoDB;
