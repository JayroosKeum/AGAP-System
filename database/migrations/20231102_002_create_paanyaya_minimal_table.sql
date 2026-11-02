-- Migration: create simplified Paanyaya table
CREATE TABLE IF NOT EXISTS paanyaya_minimal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NULL,
    complainant_name VARCHAR(255) NOT NULL,
    respondent_name VARCHAR(255) NOT NULL,
    invitation_date DATE NOT NULL,
    invitation_month TINYINT NOT NULL,
    invitation_year SMALLINT NOT NULL,
    invitation_day_name VARCHAR(15) NOT NULL,
    invitation_time TIME NULL,
    short_narrative TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_paanyaya_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
