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
            SELECT *
            FROM pangkat_members
            WHERE pangkat_id=?
        ");

        $stmt->execute([$pangkatId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}