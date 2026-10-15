USE remastered_db;

CREATE TABLE IF NOT EXISTS workflow_integrity_events (
    integrity_event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id BIGINT UNSIGNED NULL,
    event_code VARCHAR(80) NOT NULL,
    severity VARCHAR(20) NOT NULL,
    details TEXT NOT NULL,
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    resolution_notes TEXT NULL,
    PRIMARY KEY (integrity_event_id),
    KEY idx_workflow_integrity_case (case_id, detected_at),
    KEY idx_workflow_integrity_open (resolved_at, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workflow_integrity_events (case_id, event_code, severity, details)
SELECT c.case_id, 'CASE_WITHOUT_STAGE_HISTORY', 'Warning', 'Case has no case_stage_history record.'
FROM cases c
WHERE NOT EXISTS (SELECT 1 FROM case_stage_history h WHERE h.case_id = c.case_id)
AND NOT EXISTS (
    SELECT 1 FROM workflow_integrity_events e
    WHERE e.case_id = c.case_id
      AND e.event_code = 'CASE_WITHOUT_STAGE_HISTORY'
      AND e.resolved_at IS NULL
);

INSERT INTO workflow_integrity_events (case_id, event_code, severity, details)
SELECT c.case_id, 'CASE_STAGE_MISMATCH', 'Critical',
       CONCAT('cases.case_status=', c.case_status, '; latest history stage=', h.new_stage)
FROM cases c
INNER JOIN case_stage_history h ON h.stage_history_id = (
    SELECT h2.stage_history_id
    FROM case_stage_history h2
    WHERE h2.case_id = c.case_id
    ORDER BY h2.created_at DESC, h2.stage_history_id DESC
    LIMIT 1
)
WHERE c.case_status <> h.new_stage
AND NOT EXISTS (
    SELECT 1 FROM workflow_integrity_events e
    WHERE e.case_id = c.case_id
      AND e.event_code = 'CASE_STAGE_MISMATCH'
      AND e.resolved_at IS NULL
);

INSERT INTO workflow_integrity_events (case_id, event_code, severity, details)
SELECT so.case_id, 'MULTIPLE_ACTIVE_STAGE_OUTCOMES', 'Critical',
       CONCAT('Multiple active outcomes detected for stage ', so.stage_code, '.')
FROM stage_outcomes so
WHERE so.is_void = 0
GROUP BY so.case_id, so.stage_code
HAVING COUNT(*) > 1;

INSERT INTO workflow_integrity_events (case_id, event_code, severity, details)
SELECT pg.case_id, 'INVALID_ACTIVE_PANGKAT', 'Critical',
       CONCAT('Active Pangkat ', pg.pangkat_id, ' does not have exactly three distinct active members.')
FROM pangkat_groups pg
LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.status = 'Active'
WHERE pg.panel_status = 'Active'
GROUP BY pg.pangkat_id, pg.case_id
HAVING COUNT(pm.member_id) <> 3 OR COUNT(DISTINCT pm.member_id) <> 3;

INSERT INTO workflow_integrity_events (case_id, event_code, severity, details)
SELECT cr.case_id, 'CFA_ISSUED_WITHOUT_CERTIFICATE', 'Critical',
       'CFA request is marked Issued but no active certificate exists.'
FROM cfa_requests cr
LEFT JOIN cfa_certificates cc ON cc.cfa_request_id = cr.cfa_request_id AND cc.voided_at IS NULL
WHERE cr.request_status = 'Issued'
  AND cc.certificate_id IS NULL;
