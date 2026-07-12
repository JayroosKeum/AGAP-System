<?php

require_once __DIR__ . '/../config/database.php';

class Arbitration
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
            INSERT INTO arbitration_records
            (
                case_id,
                agreement_date,
                award_date,
                award_details
            )
            VALUES
            (
                ?,?,?,?
            )
            ON DUPLICATE KEY UPDATE
                agreement_date = VALUES(agreement_date),
                award_date = VALUES(award_date),
                award_details = VALUES(award_details)
        ");

        return $stmt->execute([
            $data['case_id'],
            $data['agreement_date'],
            $data['award_date'],
            $data['award_details']
        ]);
    }

    public function getByCase($caseId)
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM arbitration_records
            WHERE case_id=?
        ");

        $stmt->execute([$caseId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
