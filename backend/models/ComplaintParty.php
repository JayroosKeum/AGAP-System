<?php

require_once __DIR__ . '/../config/database.php';

class ComplaintParty
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function create(int $complaintId, int $residentId, string $partyType): array
    {
        if (!in_array($partyType, ['Complainant', 'Respondent', 'Witness'], true)) {
            return ['success' => false, 'message' => 'Invalid party type.'];
        }

        if (!$this->recordExists('complaints', 'complaint_id', $complaintId)) {
            return ['success' => false, 'message' => 'Complaint not found.'];
        }

        if (!$this->recordExists('residents', 'resident_id', $residentId)) {
            return ['success' => false, 'message' => 'Resident not found.'];
        }

        $duplicate = $this->conn->prepare(
            'SELECT party_id FROM complaint_parties WHERE complaint_id = ? AND resident_id = ? AND party_type = ?'
        );
        $duplicate->execute([$complaintId, $residentId, $partyType]);
        if ($duplicate->fetchColumn()) {
            return ['success' => false, 'message' => 'This resident already has that role in the complaint.'];
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO complaint_parties (complaint_id, resident_id, party_type) VALUES (?, ?, ?)'
        );
        $stmt->execute([$complaintId, $residentId, $partyType]);

        return [
            'success' => true,
            'party_id' => (int) $this->conn->lastInsertId(),
            'message' => 'Complaint party added successfully.'
        ];
    }

    public function getByComplaint(int $complaintId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT cp.party_id, cp.party_type, r.resident_id,
                    TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name,
                    r.contact_no, r.purok, r.address
             FROM complaint_parties cp
             INNER JOIN residents r ON r.resident_id = cp.resident_id
             WHERE cp.complaint_id = ?
             ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name"
        );
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $partyId): array|false
    {
        $stmt = $this->conn->prepare('SELECT party_id, complaint_id FROM complaint_parties WHERE party_id = ?');
        $stmt->execute([$partyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function delete(int $partyId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM complaint_parties WHERE party_id = ?');
        $stmt->execute([$partyId]);
        return $stmt->rowCount() === 1;
    }

    private function recordExists(string $table, string $column, int $id): bool
    {
        $allowed = [
            'complaints.complaint_id',
            'residents.resident_id'
        ];
        if (!in_array($table . '.' . $column, $allowed, true)) {
            return false;
        }

        $stmt = $this->conn->prepare("SELECT 1 FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }
}
