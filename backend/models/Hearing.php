<?php

require_once __DIR__ . '/../config/database.php';

class Hearing
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function getAll()
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM hearings
            ORDER BY hearing_date ASC
        ");

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByCase($caseId)
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM hearings
            WHERE case_id=?
            ORDER BY hearing_date ASC
        ");

        $stmt->execute([$caseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($data)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO hearings
            (
                case_id,
                hearing_type,
                hearing_date,
                venue,
                remarks
            )
            VALUES
            (
                ?,?,?,?,?
            )
        ");

        return $stmt->execute([
            $data['case_id'],
            $data['hearing_type'],
            $data['hearing_date'],
            $data['venue'],
            $data['remarks']
        ]);
    }

    public function update($id,$data)
    {
        $stmt = $this->conn->prepare("
            UPDATE hearings
            SET
                hearing_type=?,
                hearing_date=?,
                venue=?,
                remarks=?
            WHERE hearing_id=?
        ");

        return $stmt->execute([
            $data['hearing_type'],
            $data['hearing_date'],
            $data['venue'],
            $data['remarks'],
            $id
        ]);
    }
}