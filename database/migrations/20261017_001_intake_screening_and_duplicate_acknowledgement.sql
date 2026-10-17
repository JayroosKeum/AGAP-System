-- Records the intake officer's preliminary KP eligibility screen. This is an
-- administrative assessment only; final legal review remains a separate step.
CREATE TABLE IF NOT EXISTS complaint_intake_screenings (
    screening_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    party_is_government TINYINT(1) NOT NULL DEFAULT 0,
    party_is_public_officer TINYINT(1) NOT NULL DEFAULT 0,
    parties_in_diff_lgu TINYINT(1) NOT NULL DEFAULT 0,
    property_in_diff_lgu TINYINT(1) NOT NULL DEFAULT 0,
    accused_under_detention TINYINT(1) NOT NULL DEFAULT 0,
    habeas_corpus_needed TINYINT(1) NOT NULL DEFAULT 0,
    provisional_remedy_needed TINYINT(1) NOT NULL DEFAULT 0,
    prescription_risk TINYINT(1) NOT NULL DEFAULT 0,
    indigenous_community TINYINT(1) NOT NULL DEFAULT 0,
    offense_imprisonment_max DECIMAL(6,2) NOT NULL DEFAULT 0,
    offense_fine_max DECIMAL(12,2) NOT NULL DEFAULT 0,
    has_private_offended_party TINYINT(1) NOT NULL DEFAULT 1,
    duplicate_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    screening_notes TEXT NULL,
    screened_by INT UNSIGNED NULL,
    screened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_complaint_intake_screening (complaint_id),
    CONSTRAINT fk_cis_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (complaint_id) ON DELETE CASCADE,
    CONSTRAINT fk_cis_user FOREIGN KEY (screened_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
