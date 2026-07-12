<?php

require_once __DIR__ . '/../config/database.php';

class Location
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function saveLocation($data)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO incident_locations
            (
                complaint_id,
                latitude,
                longitude,
                address
            )
            VALUES
            (
                ?,?,?,?
            )
            ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                address = VALUES(address)
        ");

        return $stmt->execute([
            $data['complaint_id'],
            $data['latitude'],
            $data['longitude'],
            $data['address']
        ]);
    }

    public function getLocation($complaintId)
    {
        $stmt = $this->conn->prepare("
            SELECT *
            FROM incident_locations
            WHERE complaint_id = ?
        ");

        $stmt->execute([$complaintId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
