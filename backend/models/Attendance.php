<?php

require_once __DIR__ . '/../config/database.php';

class Attendance
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function record($data)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO hearing_attendance
            (
                hearing_id,
                resident_id,
                attendance_status,
                remarks
            )
            VALUES
            (
                ?,?,?,?
            )
            ON DUPLICATE KEY UPDATE
                attendance_status = VALUES(attendance_status),
                remarks = VALUES(remarks),
                recorded_at = CURRENT_TIMESTAMP
        ");

        return $stmt->execute([
            $data['hearing_id'],
            $data['resident_id'],
            $data['attendance_status'],
            $data['remarks']
        ]);
    }

    public function getByHearing($hearingId)
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM hearing_attendance
            WHERE hearing_id=?
        ");

        $stmt->execute([$hearingId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
