<?php

require_once __DIR__ . '/../config/database.php';

class CaseHistory
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function log(
        $caseId,
        $status,
        $remarks,
        $userId
    )
    {
        $stmt = $this->conn->prepare("
            INSERT INTO case_history
            (
                case_id,
                status,
                remarks,
                updated_by
            )
            VALUES
            (
                ?,?,?,?
            )
        ");

        return $stmt->execute([
            $caseId,
            $status,
            $remarks,
            $userId
        ]);
    }
}