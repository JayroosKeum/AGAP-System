CREATE TABLE IF NOT EXISTS complaint_information_requests (
    request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NULL,
    request_message VARCHAR(2000) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    response_message VARCHAR(5000) NULL,
    responded_at DATETIME NULL,
    KEY idx_complaint_information_requests (complaint_id, request_id),
    CONSTRAINT fk_information_request_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_information_request_staff FOREIGN KEY (requested_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
