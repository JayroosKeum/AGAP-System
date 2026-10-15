USE remastered_db;

CREATE TABLE IF NOT EXISTS case_stage_history (
    stage_history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NOT NULL,
    request_id CHAR(36) NOT NULL,
    action_code VARCHAR(80) NOT NULL,
    previous_stage VARCHAR(50) NOT NULL,
    new_stage VARCHAR(50) NOT NULL,
    reason_code VARCHAR(80) NULL,
    reason_notes TEXT NULL,
    source_type VARCHAR(50) NULL,
    source_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (stage_history_id),
    UNIQUE KEY uq_case_stage_history_request (request_id),
    KEY idx_case_stage_history_case_created (case_id, created_at),
    KEY idx_case_stage_history_action (action_code),
    KEY idx_case_stage_history_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO case_stage_history (
    case_id,
    request_id,
    action_code,
    previous_stage,
    new_stage,
    reason_code,
    reason_notes,
    source_type,
    source_id,
    actor_user_id,
    created_at
)
SELECT
    c.case_id,
    UUID(),
    'BASELINE_EXISTING_CASE',
    COALESCE(NULLIF(TRIM(c.case_status), ''), 'Docketed'),
    COALESCE(NULLIF(TRIM(c.case_status), ''), 'Docketed'),
    'MIGRATION_BASELINE',
    'Initial stage-history baseline created without changing the existing case status.',
    'cases',
    c.case_id,
    1,
    COALESCE(c.created_at, NOW())
FROM cases c
WHERE NOT EXISTS (
    SELECT 1
    FROM case_stage_history h
    WHERE h.case_id = c.case_id
);
