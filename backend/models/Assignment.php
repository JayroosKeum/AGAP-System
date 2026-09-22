<?php

require_once __DIR__ . '/../config/database.php';

class Assignment
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    /**
     * Retained for compatibility with older code.
     * The unified case-team workflow should normally use replaceCaseTeam().
     */
    public function assign(array $data): array
    {
        $caseId = filter_var(
            $data['case_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $memberId = filter_var(
            $data['member_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $role = trim((string) ($data['assignment_role'] ?? ''));
        $allowedRoles = ['Mediator', 'Head', 'Secretary', 'Member'];

        if (!$caseId || !$memberId || !in_array($role, $allowedRoles, true)) {
            return [
                'success' => false,
                'message' => 'Please provide a valid case, member, and assignment role.'
            ];
        }

        $case = $this->getCase((int) $caseId);
        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        if ($case['case_status'] === 'Archived') {
            return [
                'success' => false,
                'message' => 'Assignments for an archived case cannot be changed.'
            ];
        }

        $isMediation = in_array($case['case_status'], ['Docketed', 'Mediation'], true);
        $isAutomaticMediationHead = $isMediation && $role === 'Head';

        if ($isAutomaticMediationHead) {
            $administrator = $this->getLuponHead();
            if (!$administrator) {
                return [
                    'success' => false,
                    'message' => 'The active Administrator or Barangay Captain account could not be found.'
                ];
            }
            $memberId = (int) $administrator['member_id'];
        }

        if ($isAutomaticMediationHead) {
            $eligible = $this->isEligibleMediationHead((int) $memberId);
        } else {
            $eligible = $this->isEligibleMember((int) $memberId);
        }

        if (!$eligible) {
            return [
                'success' => false,
                'message' => $isAutomaticMediationHead
                    ? 'The Mediation Head must be the active Administrator or Barangay Captain.'
                    : 'The selected user must be an active Lupon Member.'
            ];
        }

        $roleExists = $this->conn->prepare(
            'SELECT assignment_id FROM case_assignments WHERE case_id = ? AND assignment_role = ? LIMIT 1'
        );
        $roleExists->execute([$caseId, $role]);
        if ($roleExists->fetchColumn()) {
            return [
                'success' => false,
                'message' => 'This case already has a user assigned to the selected role.'
            ];
        }

        if (in_array($role, ['Head', 'Secretary', 'Member'], true)) {
            $memberExists = $this->conn->prepare(
                "SELECT assignment_id FROM case_assignments
                 WHERE case_id = ? AND member_id = ? AND assignment_role IN ('Head', 'Secretary', 'Member')
                 LIMIT 1"
            );
            $memberExists->execute([$caseId, $memberId]);
            if ($memberExists->fetchColumn()) {
                return [
                    'success' => false,
                    'message' => 'The selected user already has a team role for this case.'
                ];
            }
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date)
                 VALUES (?, ?, ?, CURDATE())'
            );
            $stmt->execute([$caseId, $memberId, $role]);

            return [
                'success' => true,
                'message' => $isAutomaticMediationHead
                    ? 'The Administrator or Barangay Captain was assigned as the Mediation Head.'
                    : 'Lupon member assigned successfully.'
            ];
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to save the assignment.'];
        }
    }

    /**
     * Replaces the complete Head, Secretary, and Member team.
     *
     * For Mediation cases (Docketed or Mediation):
     * - Head is the existing active Administrator.
     * - Submitted head_id is ignored.
     * - Secretary and Member must be active Lupon Members.
     *
     * For non-Mediation cases:
     * - Head, Secretary, and Member must be active Lupon Members.
     */
    public function replaceCaseTeam(int $caseId, array $members): array
    {
        if ($caseId < 1) {
            return ['success' => false, 'message' => 'A valid case is required.'];
        }

        $case = $this->getCase($caseId);
        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        if ($case['case_status'] === 'Archived') {
            return [
                'success' => false,
                'message' => 'The team of an archived case cannot be changed.'
            ];
        }

        $isMediation = in_array($case['case_status'], ['Docketed', 'Mediation'], true);

        if ($isMediation) {
            $administrator = $this->getLuponHead();
            if (!$administrator) {
                return [
                    'success' => false,
                    'message' => 'The active Administrator or Barangay Captain account could not be found.'
                ];
            }
            $headId = (int) $administrator['member_id'];
        } else {
            $headId = filter_var(
                $members['head_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
        }

        $secretaryId = filter_var(
            $members['secretary_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $memberId = filter_var(
            $members['member_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$headId || !$secretaryId || !$memberId) {
            return [
                'success' => false,
                'message' => $isMediation
                    ? 'Select the Secretary and Member.'
                    : 'Select the Head, Secretary, and Member.'
            ];
        }

        $selectedIds = [(int) $headId, (int) $secretaryId, (int) $memberId];
        if (count(array_unique($selectedIds)) !== 3) {
            return [
                'success' => false,
                'message' => 'Head, Secretary, and Member must be different users.'
            ];
        }

        if ($isMediation) {
            if (!$this->isEligibleMediationHead((int) $headId)) {
                return [
                    'success' => false,
                    'message' => 'The Mediation Head must be the active Administrator or Barangay Captain.'
                ];
            }
        } elseif (!$this->isEligibleMember((int) $headId)) {
            return [
                'success' => false,
                'message' => 'The selected Head must be an active Lupon Member.'
            ];
        }

        if (!$this->isEligibleMember((int) $secretaryId) || !$this->isEligibleMember((int) $memberId)) {
            return [
                'success' => false,
                'message' => 'Secretary and Member must be active Lupon Members.'
            ];
        }

        try {
            $this->conn->beginTransaction();

            $delete = $this->conn->prepare(
                "DELETE FROM case_assignments
                 WHERE case_id = ?
                   AND assignment_role IN ('Head', 'Secretary', 'Member')"
            );
            $delete->execute([$caseId]);

            $insert = $this->conn->prepare(
                'INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date)
                 VALUES (?, ?, ?, CURDATE())'
            );

            $team = [
                'Head' => (int) $headId,
                'Secretary' => (int) $secretaryId,
                'Member' => (int) $memberId
            ];

            foreach ($team as $assignmentRole => $teamMemberId) {
                $insert->execute([$caseId, $teamMemberId, $assignmentRole]);
            }

            // Keep the legacy Pangkat record in sync for existing KP document templates.
            $group = $this->conn->prepare(
                'INSERT INTO pangkat_groups (case_id, formation_date) VALUES (?, CURDATE())
                 ON DUPLICATE KEY UPDATE formation_date = VALUES(formation_date), pangkat_id = LAST_INSERT_ID(pangkat_id)'
            );
            $group->execute([$caseId]);
            $pangkatId = (int) $this->conn->lastInsertId();

            $this->conn->prepare('DELETE FROM pangkat_members WHERE pangkat_id = ?')->execute([$pangkatId]);
            $memberInsert = $this->conn->prepare('INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, ?, ?)');
            $positions = [
                'Head' => 'Chairman',
                'Secretary' => 'Secretary',
                'Member' => 'Member'
            ];
            foreach ($positions as $assignmentRole => $position) {
                $memberInsert->execute([$pangkatId, $team[$assignmentRole], $position]);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => $isMediation
                    ? 'Case team saved. The Administrator or Barangay Captain remains the automatic Head for Mediation.'
                    : 'Case team saved successfully.'
            ];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log($exception->getMessage());
            return [
                'success' => false,
                'message' => 'Unable to save the case team.'
            ];
        }
    }

    /**
     * Returns every assignment for one case.
     */
    public function getByCase(int $caseId): array
    {
        if ($caseId < 1) {
            return [];
        }

        $stmt = $this->conn->prepare(
            "SELECT
                ca.assignment_id,
                ca.case_id,
                ca.member_id,
                ca.assignment_role,
                ca.assigned_date,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.username,
                r.role_name
             FROM case_assignments ca
             INNER JOIN users u ON u.user_id = ca.member_id
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE ca.case_id = ?
             ORDER BY
                FIELD(ca.assignment_role, 'Head', 'Secretary', 'Member', 'Mediator'),
                ca.assigned_date,
                ca.assignment_id"
        );
        $stmt->execute([$caseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alias for getByCase() to maintain compatibility with legacy callers.
     */
    public function getAssignments(int $caseId): array
    {
        return $this->getByCase($caseId);
    }

    /**
     * Returns active Lupon Member accounts eligible for the
     * normal Head, Secretary, and Member dropdowns.
     */
    public function getLuponMembers(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT
                u.user_id AS member_id,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.username,
                r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'Active'
               AND r.role_name = 'Lupon Member'
             ORDER BY
                u.last_name,
                u.first_name,
                u.middle_name,
                u.user_id"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns the existing active Administrator.
     * In AGAP, the Administrator represents the Barangay Captain and Lupon Head.
     */
    public function getLuponHead(): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT
                u.user_id AS member_id,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.username,
                r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'Active'
               AND r.role_name = 'Administrator'
             ORDER BY u.user_id ASC
             LIMIT 1"
        );
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Ensures an existing Mediation case has the active Administrator assigned as Head.
     * This also repairs Mediation cases created before automatic Head assignment was implemented.
     */
    public function ensureMediationHead(int $caseId): array
    {
        if ($caseId < 1) {
            return ['success' => false, 'message' => 'A valid case is required.'];
        }

        $case = $this->getCase($caseId);
        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        if (!in_array($case['case_status'], ['Docketed', 'Mediation'], true)) {
            return [
                'success' => true,
                'message' => 'Automatic Mediation Head assignment is not required.'
            ];
        }

        $administrator = $this->getLuponHead();
        if (!$administrator) {
            return [
                'success' => false,
                'message' => 'The active Administrator or Barangay Captain account could not be found.'
            ];
        }

        $administratorId = (int) $administrator['member_id'];

        try {
            $this->conn->beginTransaction();

            // Remove any existing Head who is not the active Administrator.
            $deleteExistingHead = $this->conn->prepare(
                "DELETE FROM case_assignments
                 WHERE case_id = ?
                   AND assignment_role = 'Head'
                   AND member_id <> ?"
            );
            $deleteExistingHead->execute([$caseId, $administratorId]);

            $existing = $this->conn->prepare(
                "SELECT assignment_id
                 FROM case_assignments
                 WHERE case_id = ?
                   AND member_id = ?
                   AND assignment_role = 'Head'
                 LIMIT 1"
            );
            $existing->execute([$caseId, $administratorId]);

            if (!$existing->fetchColumn()) {
                $insert = $this->conn->prepare(
                    "INSERT INTO case_assignments (
                        case_id,
                        member_id,
                        assignment_role,
                        assigned_date
                     )
                     VALUES (?, ?, 'Head', CURDATE())"
                );
                $insert->execute([$caseId, $administratorId]);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'The Administrator or Barangay Captain is assigned as the Mediation Head.',
                'head' => $administrator
            ];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log($exception->getMessage());

            return [
                'success' => false,
                'message' => 'Unable to assign the automatic Mediation Head.'
            ];
        }
    }

    /**
     * Returns the case status needed for assignment rules.
     */
    public function getCase(int $caseId): array|false
    {
        $stmt = $this->conn->prepare(
            'SELECT case_id, case_status FROM cases WHERE case_id = ? LIMIT 1'
        );
        $stmt->execute([$caseId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function caseExists(int $caseId): bool
    {
        $stmt = $this->conn->prepare("SELECT 1 FROM cases WHERE case_id = ? AND case_status <> 'Archived'");
        $stmt->execute([$caseId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Checks eligibility for normal Lupon team roles.
     */
    public function isEligibleMember(int $memberId): bool
    {
        if ($memberId < 1) {
            return false;
        }

        $stmt = $this->conn->prepare(
            "SELECT 1 FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Lupon Member'
             LIMIT 1"
        );
        $stmt->execute([$memberId]);

        return (bool) $stmt->fetchColumn();
    }

    public function isActiveLuponMember(int $memberId): bool
    {
        return $this->isEligibleMember($memberId);
    }

    /**
     * Checks whether the selected user is the active Administrator allowed to serve as Mediation Head.
     */
    public function isEligibleMediationHead(int $memberId): bool
    {
        if ($memberId < 1) {
            return false;
        }

        $stmt = $this->conn->prepare(
            "SELECT 1 FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Administrator'
             LIMIT 1"
        );
        $stmt->execute([$memberId]);

        return (bool) $stmt->fetchColumn();
    }
}
