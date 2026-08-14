<?php

require_once __DIR__ . '/../config/database.php';

class Location
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function complaintExists(int $complaintId): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM complaints WHERE complaint_id = ?');
        $stmt->execute([$complaintId]);
        return (bool) $stmt->fetchColumn();
    }

    public function save(int $complaintId, ?float $latitude, ?float $longitude, ?string $address): array
    {
        if (!$this->complaintExists($complaintId)) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }
        $stmt = $this->conn->prepare(
            'INSERT INTO incident_locations (complaint_id, latitude, longitude, address) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude), address = VALUES(address)'
        );
        $stmt->execute([$complaintId, $latitude, $longitude, $address]);
        return ['success' => true, 'location_id' => (int) $this->conn->lastInsertId()];
    }

    public function getByComplaint(int $complaintId): array|false
    {
        $stmt = $this->conn->prepare('SELECT location_id, complaint_id, latitude, longitude, address, created_at, updated_at FROM incident_locations WHERE complaint_id = ?');
        $stmt->execute([$complaintId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT l.location_id, l.complaint_id, l.latitude, l.longitude, l.address, l.updated_at,
                    co.complaint_number, co.complaint_title, c.case_id, c.case_number, c.case_status
             FROM incident_locations l
             INNER JOIN complaints co ON co.complaint_id = l.complaint_id
             LEFT JOIN cases c ON c.complaint_id = l.complaint_id
             WHERE l.latitude IS NOT NULL AND l.longitude IS NOT NULL
             ORDER BY l.updated_at DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getComplaints(): array
    {
        $stmt = $this->conn->prepare('SELECT complaint_id, complaint_number, complaint_title FROM complaints ORDER BY created_at DESC');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
