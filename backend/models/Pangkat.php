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

    public function create($caseId)
    {
        $existing = $this->conn->prepare('SELECT pangkat_id FROM pangkat_groups WHERE case_id = ?');
        $existing->execute([$caseId]);
        if ($existing->fetch()) {
            return false;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO pangkat_groups
            (
                case_id,
                formation_date
            )
            VALUES
            (
                ?,CURDATE()
            )
        ");

        $stmt->execute([$caseId]);

        return $this->conn->lastInsertId();
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
            SELECT pm.*, u.first_name, u.last_name
            FROM pangkat_members pm
            INNER JOIN users u ON u.user_id = pm.member_id
            WHERE pm.pangkat_id=?
        ");

        $stmt->execute([$pangkatId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLuponMembers()
    {
        $stmt = $this->conn->query('SELECT user_id, first_name, last_name FROM users WHERE role_id = 3 ORDER BY last_name, first_name');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
