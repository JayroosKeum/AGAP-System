ALTER TABLE settlements
    ADD COLUMN award_type ENUM('Amicable Settlement', 'Arbitration Award') NOT NULL DEFAULT 'Amicable Settlement' AFTER repudiation_deadline,
    ADD COLUMN obligation_type ENUM('Monetary', 'Property Return', 'Specific Performance', 'Hybrid') NOT NULL DEFAULT 'Monetary' AFTER award_type,
    ADD COLUMN six_month_deadline DATE NULL AFTER obligation_type;

UPDATE settlements
SET repudiation_deadline = DATE_ADD(settlement_date, INTERVAL 10 DAY),
    six_month_deadline = DATE_ADD(settlement_date, INTERVAL 6 MONTH)
WHERE settlement_date IS NOT NULL;

CREATE TABLE IF NOT EXISTS execution_proceedings (
    execution_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    settlement_id INT UNSIGNED NOT NULL,
    motion_date DATE NOT NULL,
    motion_narrative TEXT NOT NULL,
    hearing_date DATETIME NOT NULL,
    complainant_attended ENUM('Present', 'Absent', 'Excused') NOT NULL DEFAULT 'Absent',
    respondent_attended ENUM('Present', 'Absent', 'Excused') NOT NULL DEFAULT 'Absent',
    non_appearance_reason VARCHAR(255) NULL,
    hearing_minutes TEXT NULL,
    grace_period_end_date DATE NULL,
    notice_of_execution_date DATE NULL,
    execution_status ENUM('Motion Filed', 'Hearing Scheduled', 'Grace Period Active', 'Notice of Execution Issued', 'Enforced / Complied', 'Lapsed to MTC') NOT NULL DEFAULT 'Motion Filed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY fk_exec_cases (case_id),
    KEY fk_exec_settlements (settlement_id),
    CONSTRAINT fk_exec_cases FOREIGN KEY (case_id) REFERENCES cases (case_id) ON DELETE CASCADE,
    CONSTRAINT fk_exec_settlements FOREIGN KEY (settlement_id) REFERENCES settlements (settlement_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
