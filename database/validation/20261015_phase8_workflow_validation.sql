USE remastered_db;

SELECT 'CASE_WITHOUT_STAGE_HISTORY' AS test_code, c.case_id, c.case_status
FROM cases c
WHERE NOT EXISTS (
    SELECT 1 FROM case_stage_history h WHERE h.case_id = c.case_id
);

SELECT 'CASE_STAGE_MISMATCH' AS test_code, c.case_id, c.case_status, h.new_stage AS latest_history_stage
FROM cases c
INNER JOIN case_stage_history h ON h.stage_history_id = (
    SELECT h2.stage_history_id
    FROM case_stage_history h2
    WHERE h2.case_id = c.case_id
    ORDER BY h2.created_at DESC, h2.stage_history_id DESC
    LIMIT 1
)
WHERE c.case_status <> h.new_stage;

SELECT 'MULTIPLE_ACTIVE_STAGE_OUTCOMES' AS test_code, case_id, stage_code, COUNT(*) AS active_outcomes
FROM stage_outcomes
WHERE is_void = 0
GROUP BY case_id, stage_code
HAVING COUNT(*) > 1;

SELECT 'DUPLICATE_SUMMONS_ATTEMPTS' AS test_code, case_id, party_id, attempt_number, COUNT(*) AS duplicate_count
FROM summon_deliveries
WHERE is_void = 0
GROUP BY case_id, party_id, attempt_number
HAVING COUNT(*) > 1;

SELECT 'INVALID_ACTIVE_PANGKAT' AS test_code, pg.case_id, pg.pangkat_id,
       COUNT(pm.member_id) AS active_members,
       COUNT(DISTINCT pm.member_id) AS distinct_members
FROM pangkat_groups pg
LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.status = 'Active'
WHERE pg.panel_status = 'Active'
GROUP BY pg.case_id, pg.pangkat_id
HAVING COUNT(pm.member_id) <> 3 OR COUNT(DISTINCT pm.member_id) <> 3;

SELECT 'CFA_ISSUED_WITHOUT_CERTIFICATE' AS test_code, cr.case_id, cr.cfa_request_id
FROM cfa_requests cr
LEFT JOIN cfa_certificates cc ON cc.cfa_request_id = cr.cfa_request_id AND cc.voided_at IS NULL
WHERE cr.request_status = 'Issued'
  AND cc.certificate_id IS NULL;

SELECT 'ORPHAN_STAGE_HISTORY' AS test_code, h.stage_history_id, h.case_id
FROM case_stage_history h
LEFT JOIN cases c ON c.case_id = h.case_id
WHERE c.case_id IS NULL;
