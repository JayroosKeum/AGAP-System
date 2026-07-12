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
        $roles = ['Mediator', 'Pangkat Chairman', 'Pangkat Secretary', 'Pangkat Member'];

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
