-- Apply once only if case_deadlines does not already have this key.
-- Resolve duplicate (case_id, deadline_type) rows before running.
ALTER TABLE case_deadlines
    ADD CONSTRAINT uq_case_deadlines_case_type UNIQUE (case_id, deadline_type);
