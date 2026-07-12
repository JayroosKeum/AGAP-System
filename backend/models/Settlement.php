<?php

require_once __DIR__ . '/../config/database.php';

class Settlement
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
            INSERT INTO settlements
            (
                case_id,
                settlement_date,
                agreement_details,
                compliance_status
            )
            VALUES
            (
                ?,CURDATE(),?,?
            )
            ON DUPLICATE KEY UPDATE
                settlement_date = VALUES(settlement_date),
                agreement_details = VALUES(agreement_details),
                compliance_status = VALUES(compliance_status)
        ");

        return $stmt->execute([
            $data['case_id'],
            $data['agreement_details'],
            $data['compliance_status']
        ]);
    }

    public function getByCase($caseId)
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM settlements
            WHERE case_id=?
        ");

        $stmt->execute([$caseId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
