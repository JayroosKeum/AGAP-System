<?php

require_once __DIR__ . '/../config/database.php';

class Assignment
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function assign($data)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO case_assignments
            (
                case_id,
                member_id,
                assignment_role,
                assigned_date
            )
            VALUES
            (
                ?,?,?,CURDATE()
            )
        ");

        return $stmt->execute([
            $data['case_id'],
            $data['member_id'],
            $data['assignment_role']
        ]);
    }

    public function getAssignments($caseId)
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM case_assignments
            WHERE case_id = ?
        ");

        $stmt->execute([$caseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}