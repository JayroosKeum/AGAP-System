<?php

require_once __DIR__ . '/../config/database.php';

class ComplaintParty
{
    private $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function create(int $complaintId, int $residentId, string $partyType): bool
    {
        if (!in_array($partyType, ['Complainant', 'Respondent', 'Witness'], true)) {
            return false;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO complaint_parties (complaint_id, resident_id, party_type)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE party_id = LAST_INSERT_ID(party_id)'
        );

        return $stmt->execute([$complaintId, $residentId, $partyType]);
    }

    public function getByComplaint(int $complaintId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT cp.party_id, cp.party_type, r.resident_id,
                    TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name
             FROM complaint_parties cp
             INNER JOIN residents r ON r.resident_id = cp.resident_id
             WHERE cp.complaint_id = ?
             ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name"
        );
        $stmt->execute([$complaintId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
