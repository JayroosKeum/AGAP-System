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
            SELECT
                h.*,
                c.case_number,
                co.complaint_number,
                co.complaint_title
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            ORDER BY h.hearing_date ASC
        ");

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id)
    {
        $stmt = $this->conn->prepare("
            SELECT
                h.*,
                c.case_number,
                co.complaint_number,
                co.complaint_title
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE h.hearing_id = ?
        ");

        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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
