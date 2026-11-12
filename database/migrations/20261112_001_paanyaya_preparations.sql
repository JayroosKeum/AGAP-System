-- One printable first-invitation preparation per screened complaint.
-- Preparations do not enter the scheduled Paanyaya attendance queue.
CREATE TABLE IF NOT EXISTS paanyaya_preparations (
    complaint_id INT UNSIGNED PRIMARY KEY,
    appointment_at DATETIME NOT NULL,
    venue VARCHAR(255) NOT NULL,
    verified_parties JSON NOT NULL,
    document_pdf MEDIUMBLOB NOT NULL,
    prepared_by INT UNSIGNED NULL,
    prepared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ready_by INT UNSIGNED NULL,
    ready_at DATETIME NULL,
    FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    FOREIGN KEY (prepared_by) REFERENCES users (user_id) ON DELETE SET NULL,
    FOREIGN KEY (ready_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
