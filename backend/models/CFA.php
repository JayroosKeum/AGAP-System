<?php

require_once __DIR__ . '/../config/database.php';

class CFA
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function create($data)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO cfa_records
            (
                case_id,
                issuance_date,
                reason
            )
            VALUES
            (
                ?,CURDATE(),?
            )
        ");

        return $stmt->execute([
            $data['case_id'],
            $data['reason']
        ]);
    }

    public function getByCase($caseId)
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM cfa_records
            WHERE case_id=?
        ");

        $stmt->execute([$caseId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}