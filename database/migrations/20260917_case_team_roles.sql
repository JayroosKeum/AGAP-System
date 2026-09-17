-- Replace operational Pangkat assignment roles with unified case-team roles.
-- Existing pangkat_groups/pangkat_members remain internal document-compatibility data.
ALTER TABLE case_assignments
    MODIFY COLUMN assignment_role ENUM('Mediator', 'Pangkat Chairman', 'Pangkat Secretary', 'Pangkat Member', 'Head', 'Secretary', 'Member') NOT NULL;

UPDATE case_assignments
SET assignment_role = CASE assignment_role
    WHEN 'Pangkat Chairman' THEN 'Head'
    WHEN 'Pangkat Secretary' THEN 'Secretary'
    WHEN 'Pangkat Member' THEN 'Member'
    ELSE assignment_role
END
WHERE assignment_role IN ('Pangkat Chairman', 'Pangkat Secretary', 'Pangkat Member');

ALTER TABLE case_assignments
    MODIFY COLUMN assignment_role ENUM('Mediator', 'Head', 'Secretary', 'Member') NOT NULL;
