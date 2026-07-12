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

            $this->conn->beginTransaction();
            $stmt =
            $this->conn->prepare("
                INSERT INTO complaints
                (
                    category_id,
                    complaint_title,
                    incident_date,
                    narrative,
                    status,
                    encoded_by
                )
                VALUES
                (
                    ?,?,?,?,?,?
                )
            ");

            $stmt->execute([
                $data['category_id'],
                $data['complaint_title'],
                $data['incident_date'],
                $data['narrative'],
                'Filed',
                $_SESSION['user_id']
            ]);

            $complaintId = (int) $this->conn->lastInsertId();
            $complaintNumber = sprintf('CMP-%s-%05d', date('Y'), $complaintId);
            $numberStatement = $this->conn->prepare(
                'UPDATE complaints SET complaint_number = ? WHERE complaint_id = ?'
            );
            $numberStatement->execute([$complaintNumber, $complaintId]);

            $this->conn->commit();
            return true;

        }
        catch(Exception $e)
        {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

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
