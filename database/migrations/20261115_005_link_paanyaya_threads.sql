-- Order / Fix: Link iterative Paanyaya invitation attempts into a single progression thread.
-- Idempotent migration safe to run on existing databases upgraded through 20261115_004.

SET @has_parent_paanyaya_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'paanyayas'
      AND COLUMN_NAME = 'parent_paanyaya_id'
);

SET @ddl_add_parent_col := IF(
    @has_parent_paanyaya_col = 0,
    'ALTER TABLE paanyayas ADD COLUMN parent_paanyaya_id INT UNSIGNED NULL AFTER escalated_to_complaint_id',
    'SELECT 1'
);
PREPARE stmt_add_parent_col FROM @ddl_add_parent_col;
EXECUTE stmt_add_parent_col;
DEALLOCATE PREPARE stmt_add_parent_col;

SET @has_parent_paanyaya_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'paanyayas'
      AND INDEX_NAME = 'idx_paanyayas_parent'
);

SET @ddl_add_parent_idx := IF(
    @has_parent_paanyaya_idx = 0,
    'ALTER TABLE paanyayas ADD INDEX idx_paanyayas_parent (parent_paanyaya_id)',
    'SELECT 1'
);
PREPARE stmt_add_parent_idx FROM @ddl_add_parent_idx;
EXECUTE stmt_add_parent_idx;
DEALLOCATE PREPARE stmt_add_parent_idx;

-- A shared complaint and one first invitation establish the historical thread.
-- Standalone invitations without an existing link remain separate, even when the parties match.
UPDATE paanyayas child
JOIN (
    SELECT
        COALESCE(complaint_id, escalated_to_complaint_id) AS cid,
        MIN(paanyaya_id) AS root_id
    FROM paanyayas
    WHERE COALESCE(complaint_id, escalated_to_complaint_id) IS NOT NULL
      AND attempt_number = 1
      AND parent_paanyaya_id IS NULL
      AND (complaint_id IS NULL OR escalated_to_complaint_id IS NULL OR complaint_id = escalated_to_complaint_id)
    GROUP BY COALESCE(complaint_id, escalated_to_complaint_id)
    HAVING COUNT(*) = 1
) roots
  ON COALESCE(child.complaint_id, child.escalated_to_complaint_id) = roots.cid
SET child.parent_paanyaya_id = roots.root_id
WHERE child.parent_paanyaya_id IS NULL
  AND child.attempt_number >= 2
  AND (child.complaint_id IS NULL OR child.escalated_to_complaint_id IS NULL OR child.complaint_id = child.escalated_to_complaint_id);
