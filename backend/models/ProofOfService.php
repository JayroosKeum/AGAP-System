<?php

require_once __DIR__ . '/../config/database.php';

class ProofOfService
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
            INSERT INTO proof_of_service
            (
                case_id,
                served_by,
                served_date,
                remarks,
                image_path
            )
            VALUES
            (
                ?,?,NOW(),?,?
            )
        ");

        return $stmt->execute([
            $data['case_id'],
            $data['served_by'],
            $data['remarks'],
            $data['image_path']
        ]);
    }

    public function getByCase($caseId)
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM proof_of_service
            WHERE case_id = ?
        ");

        $stmt->execute([$caseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}