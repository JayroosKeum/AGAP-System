<?php

require_once __DIR__ . '/../config/database.php';

class Resident
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function getAll()
    {
        $sql = "
            SELECT *
            FROM residents
            ORDER BY last_name ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id)
    {
        $sql = "
            SELECT *
            FROM residents
            WHERE resident_id = ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data)
    {
        $sql = "
            INSERT INTO residents
            (
                first_name,
                middle_name,
                last_name,
                birth_date,
                gender,
                civil_status,
                contact_no,
                email,
                address,
                purok,
                is_tenant
            )
            VALUES
            (
                ?,?,?,?,?,?,?,?,?,?,?
            )
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            $data['first_name'],
            $data['middle_name'],
            $data['last_name'],
            $data['birth_date'],
            $data['gender'],
            $data['civil_status'],
            $data['contact_no'],
            $data['email'],
            $data['address'],
            $data['purok'],
            $data['is_tenant']
        ]);
    }

    public function update($id, $data)
    {
        $sql = "
            UPDATE residents
            SET
                first_name=?,
                middle_name=?,
                last_name=?,
                birth_date=?,
                gender=?,
                civil_status=?,
                contact_no=?,
                email=?,
                address=?,
                purok=?,
                is_tenant=?
            WHERE resident_id=?
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            $data['first_name'],
            $data['middle_name'],
            $data['last_name'],
            $data['birth_date'],
            $data['gender'],
            $data['civil_status'],
            $data['contact_no'],
            $data['email'],
            $data['address'],
            $data['purok'],
            $data['is_tenant'],
            $id
        ]);
    }

    public function delete($id)
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM residents WHERE resident_id=?"
        );

        return $stmt->execute([$id]);
    }
}