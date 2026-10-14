<?php

require_once __DIR__ . '/../config/database.php';

class Pangkat
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function create($caseId, $selectionMethod = 'Party Agreement', $notes = null)
    {
        $existing = $this->conn->prepare('SELECT pangkat_id FROM pangkat_groups WHERE case_id = ?');
        $existing->execute([$caseId]);
        if ($existing->fetch()) {
            return false;
        }

        require_once __DIR__ . '/../services/MediationDeadlineService.php';
        $conveningDeadline = MediationDeadlineService::calculateDeadline(date('Y-m-d'), 3);

        $stmt = $this->conn->prepare("
            INSERT INTO pangkat_groups
            (
                case_id,
                formation_date,
                convening_deadline,
                selection_method,
                selection_notes,
                quorum_size
            )
            VALUES
            (
                ?, CURDATE(), ?, ?, ?, 3
            )
        ");

        $stmt->execute([$caseId, $conveningDeadline, $selectionMethod, $notes]);
        $pId = (int) $this->conn->lastInsertId();

        if ($pId > 0) {
            $dlStmt = $this->conn->prepare("
                INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                VALUES (?, 'Pangkat Convening', ?, 'Pending')
                ON DUPLICATE KEY UPDATE
                    due_date = VALUES(due_date),
                    status = IF(status = 'Completed', 'Completed', 'Pending'),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $dlStmt->execute([$caseId, $conveningDeadline]);
        }

        return $pId;
    }

    public function getService(): PangkatService
    {
        require_once __DIR__ . '/../services/PangkatService.php';
        return new PangkatService($this->conn);
    }

    public function getAll()
    {
        $stmt = $this->conn->query("
            SELECT p.*, c.case_number, co.complaint_number, co.complaint_title,
                (SELECT COUNT(*) FROM pangkat_members pm WHERE pm.pangkat_id = p.pangkat_id) AS member_count
            FROM pangkat_groups p
            INNER JOIN cases c ON c.case_id = p.case_id
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            ORDER BY p.formation_date DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addMember(
        $pangkatId,
        $memberId,
        $position
    )
    {
        if (!in_array($position, ['Chairman', 'Secretary', 'Member'], true)) {
            return false;
        }

        $eligibleMember = $this->conn->prepare(
            "SELECT u.user_id
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ?
               AND u.status = 'Active'
               AND r.role_name = 'Lupon Member'"
        );
        $eligibleMember->execute([$memberId]);
        if (!$eligibleMember->fetch()) {
            return false;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO pangkat_members
            (
                pangkat_id,
                member_id,
                position
            )
            VALUES
            (
                ?,?,?
            )
        ");

        return $stmt->execute([
            $pangkatId,
            $memberId,
            $position
        ]);
    }

    public function getMembers($pangkatId)
    {
        $stmt = $this->conn->prepare("
            SELECT pm.*, u.first_name, u.middle_name, u.last_name
            FROM pangkat_members pm
            INNER JOIN users u ON u.user_id = pm.member_id
            WHERE pm.pangkat_id=?
            ORDER BY FIELD(pm.position, 'Chairman', 'Secretary', 'Member'), u.last_name, u.first_name
        ");

        $stmt->execute([$pangkatId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLuponMembers()
    {
        $stmt = $this->conn->query(
            "SELECT u.user_id AS member_id, u.first_name, u.middle_name, u.last_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'Active'
               AND r.role_name = 'Lupon Member'
             ORDER BY u.last_name, u.first_name"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
