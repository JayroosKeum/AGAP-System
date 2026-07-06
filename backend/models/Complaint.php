<?php

require_once __DIR__ . '/../config/database.php';

class Complaint
{
    private $conn;

    public function __construct()
    {
        $database = new Database();

        $this->conn =
            $database->connect();
    }

    private function generateComplaintNumber()
    {
        $year = date('Y');

        $stmt =
        $this->conn->query("
            SELECT COUNT(*)
            FROM complaints
        ");

        $count =
        $stmt->fetchColumn() + 1;

        return sprintf(
            "CMP-%s-%05d",
            $year,
            $count
        );
    }

    public function getAll()
    {
        try {

            $stmt =
            $this->conn->prepare("
                SELECT
                    c.*,
                    cc.category_name
                FROM complaints c
                LEFT JOIN complaint_categories cc
                    ON c.category_id = cc.category_id
                ORDER BY created_at DESC
            ");

            $stmt->execute();

            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return [];
        }
    }

    public function getById($id)
    {
        try {

            $stmt =
            $this->conn->prepare("
                SELECT *
                FROM complaints
                WHERE complaint_id=?
            ");

            $stmt->execute([$id]);

            return $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    public function create($data)
    {
        try {

            if(session_status() === PHP_SESSION_NONE)
            {
                session_start();
            }

            $complaintNumber =
                $this->generateComplaintNumber();

            $stmt =
            $this->conn->prepare("
                INSERT INTO complaints
                (
                    complaint_number,
                    category_id,
                    complaint_title,
                    incident_date,
                    narrative,
                    status,
                    encoded_by
                )
                VALUES
                (
                    ?,?,?,?,?,?,?
                )
            ");

            return $stmt->execute([
                $complaintNumber,
                $data['category_id'],
                $data['complaint_title'],
                $data['incident_date'],
                $data['narrative'],
                'Filed',
                $_SESSION['user_id']
            ]);

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    public function update($id, $data)
    {
        try {

            $stmt =
            $this->conn->prepare("
                UPDATE complaints
                SET
                    category_id=?,
                    complaint_title=?,
                    incident_date=?,
                    narrative=?,
                    status=?
                WHERE complaint_id=?
            ");

            return $stmt->execute([
                $data['category_id'],
                $data['complaint_title'],
                $data['incident_date'],
                $data['narrative'],
                $data['status'],
                $id
            ]);

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    public function delete($id)
    {
        try {

            $stmt =
            $this->conn->prepare("
                DELETE FROM complaints
                WHERE complaint_id=?
            ");

            return $stmt->execute([
                $id
            ]);

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }
}