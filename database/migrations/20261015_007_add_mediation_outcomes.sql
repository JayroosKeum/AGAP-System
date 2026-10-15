USE remastered_db;

CREATE TABLE IF NOT EXISTS stage_outcomes (
    outcome_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id CHAR(36) NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    hearing_id BIGINT UNSIGNED NOT NULL,
    stage_code VARCHAR(30) NOT NULL,
    outcome_code VARCHAR(50) NOT NULL,
    reason_code VARCHAR(80) NULL,
    outcome_notes TEXT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_void TINYINT(1) NOT NULL DEFAULT 0,
    voided_by BIGINT UNSIGNED NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(500) NULL,
    PRIMARY KEY (outcome_id),
    UNIQUE KEY uq_stage_outcomes_request (request_id),
    UNIQUE KEY uq_stage_outcomes_active_hearing (hearing_id, stage_code, is_void),
    KEY idx_stage_outcomes_case_stage (case_id, stage_code, recorded_at),
    KEY idx_stage_outcomes_code (outcome_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settlements (
    settlement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    outcome_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    hearing_id BIGINT UNSIGNED NOT NULL,
    settlement_date DATE NOT NULL,
    settlement_terms LONGTEXT NOT NULL,
    document_id BIGINT UNSIGNED NULL,
    execution_status VARCHAR(30) NOT NULL DEFAULT 'Draft',
    executed_at DATETIME NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (settlement_id),
    UNIQUE KEY uq_settlements_outcome (outcome_id),
    KEY idx_settlements_case (case_id, settlement_date),
    KEY idx_settlements_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
