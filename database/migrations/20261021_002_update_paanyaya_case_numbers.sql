-- Migration: Update Paanyaya complaints to use P-month-number-year format
-- e.g. P-10-86-2026 for Complaint 86 in October 2026

UPDATE complaints
SET complaint_number = CONCAT('P-', LPAD(MONTH(created_at), 2, '0'), '-', LPAD(complaint_id, 2, '0'), '-', YEAR(created_at))
WHERE status = 'Pre-Docketed (Paanyaya)'
  AND (complaint_number LIKE 'CMP-%' OR complaint_number IS NULL);
