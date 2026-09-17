<?php

require_once __DIR__ . '/../config/database.php';

class Assignment
{
    private $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function assign(array $data): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT);
        $memberId = filter_var($data['member_id'] ?? null, FILTER_VALIDATE_INT);
        $role = trim($data['assignment_role'] ?? '');
        $roles = ['Mediator', 'Head', 'Secretary', 'Member'];

        if (!$caseId || !$memberId || !in_array($role, $roles, true)) {
            return ['success' => false, 'message' => 'Please provide a valid case, Lupon member, and assignment role.'];
        }

        $member = $this->conn->prepare(
            "SELECT u.user_id
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ?
               AND u.status = 'Active'
               AND r.role_name = 'Lupon Member'"
        );
        $member->execute([$memberId]);
        if (!$member->fetch()) {
            return ['success' => false, 'message' => 'The selected Lupon member does not exist.'];
        }

        $exists = $this->conn->prepare(
            'SELECT assignment_id FROM case_assignments WHERE case_id = ? AND member_id = ? AND assignment_role = ?'
        );
        $exists->execute([$caseId, $memberId, $role]);
        if ($exists->fetch()) {
            return ['success' => false, 'message' => 'This member already has the selected assignment for this case.'];
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, ?, CURDATE())'
        );
        $stmt->execute([$caseId, $memberId, $role]);

        return ['success' => true, 'message' => 'Lupon member assigned successfully.'];
    }

    public function replaceCaseTeam(int $caseId, array $members): array
    {
        $roles = [
            'head_id' => 'Head',
            'secretary_id' => 'Secretary',
            'member_id' => 'Member',
        ];
        $ids = [];
        foreach ($roles as $field => $role) {
            $id = filter_var($members[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) return ['success' => false, 'message' => 'Choose a Head, Secretary, and Member.'];
            $ids[$field] = (int) $id;
        }
        if (count(array_unique($ids)) !== 3) return ['success' => false, 'message' => 'The Head, Secretary, and Member must be different Lupon Members.'];
        if (!$this->caseExists($caseId)) return ['success' => false, 'message' => 'Active case not found.'];
        foreach ($ids as $id) {
            if (!$this->isActiveLuponMember($id)) return ['success' => false, 'message' => 'Every selected person must be an active Lupon Member.'];
        }
        try {
            $this->conn->beginTransaction();
            $this->conn->prepare("DELETE FROM case_assignments WHERE case_id = ? AND assignment_role IN ('Head', 'Secretary', 'Member')")->execute([$caseId]);
            $insert = $this->conn->prepare('INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, ?, CURDATE())');
            foreach ($roles as $field => $role) $insert->execute([$caseId, $ids[$field], $role]);

            // Keep the legacy Pangkat record in sync for existing KP document templates.
            // It is not a separate user-facing assignment workflow.
            $group = $this->conn->prepare(
                'INSERT INTO pangkat_groups (case_id, formation_date) VALUES (?, CURDATE())
                 ON DUPLICATE KEY UPDATE formation_date = VALUES(formation_date), pangkat_id = LAST_INSERT_ID(pangkat_id)'
            );
            $group->execute([$caseId]);
            $pangkatId = (int) $this->conn->lastInsertId();
            $this->conn->prepare('DELETE FROM pangkat_members WHERE pangkat_id = ?')->execute([$pangkatId]);
            $memberInsert = $this->conn->prepare('INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, ?, ?)');
            $positions = ['head_id' => 'Chairman', 'secretary_id' => 'Secretary', 'member_id' => 'Member'];
            foreach ($positions as $field => $position) $memberInsert->execute([$pangkatId, $ids[$field], $position]);
            $this->conn->commit();
            return ['success' => true, 'message' => 'Case team saved.'];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to save the conciliation team.'];
        }
    }

    private function caseExists(int $caseId): bool
    {
        $stmt = $this->conn->prepare("SELECT 1 FROM cases WHERE case_id = ? AND case_status <> 'Archived'");
        $stmt->execute([$caseId]);
        return (bool) $stmt->fetchColumn();
    }

    private function isActiveLuponMember(int $memberId): bool
    {
        $stmt = $this->conn->prepare("SELECT 1 FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Lupon Member'");
        $stmt->execute([$memberId]);
        return (bool) $stmt->fetchColumn();
    }

    public function getAssignments(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT ca.assignment_id, ca.case_id, ca.assignment_role, ca.assigned_date,
                    u.user_id AS member_id,
                    u.first_name, u.middle_name, u.last_name
             FROM case_assignments ca
             INNER JOIN users u ON u.user_id = ca.member_id
             WHERE ca.case_id = ?
             ORDER BY ca.assigned_date DESC, u.last_name, u.first_name"
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLuponMembers(): array
    {
        return $this->conn->query(
            "SELECT u.user_id AS member_id, u.first_name, u.middle_name, u.last_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'Active'
               AND r.role_name = 'Lupon Member'
             ORDER BY u.last_name, u.first_name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
