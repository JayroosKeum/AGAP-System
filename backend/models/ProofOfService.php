<?php

require_once __DIR__ . '/../config/database.php';

class ProofOfService
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function caseExists(int $caseId): bool
    {
        $stmt = $this->conn->prepare("SELECT 1 FROM cases WHERE case_id = ? AND case_status <> 'Archived'");
        $stmt->execute([$caseId]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(int $caseId, int $servedBy, string $servedDate, ?string $remarks, ?string $imagePath): array
    {
        if (!$this->caseExists($caseId)) {
            return ['success' => false, 'message' => 'Active case not found.'];
        }
        $duplicate = $this->conn->prepare('SELECT proof_id FROM proof_of_service WHERE case_id = ? AND served_by = ? AND served_date = ? LIMIT 1');
        $duplicate->execute([$caseId, $servedBy, $servedDate]);
        if ($duplicate->fetchColumn()) {
            return ['success' => false, 'message' => 'This service entry has already been recorded.'];
        }
        $stmt = $this->conn->prepare('INSERT INTO proof_of_service (case_id, served_by, served_date, remarks, image_path) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$caseId, $servedBy, $servedDate, $remarks, $imagePath]);
        return ['success' => true, 'proof_id' => (int) $this->conn->lastInsertId()];
    }

    public function getByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT p.*, CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) AS served_by_name
             FROM proof_of_service p LEFT JOIN users u ON u.user_id = p.served_by
             WHERE p.case_id = ? ORDER BY p.served_date DESC, p.proof_id DESC"
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $proofId): array|false
    {
        $stmt = $this->conn->prepare('SELECT proof_id, image_path FROM proof_of_service WHERE proof_id = ?');
        $stmt->execute([$proofId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAvailableCases(): array
    {
        $stmt = $this->conn->prepare("SELECT c.case_id, c.case_number, c.case_status, co.complaint_title FROM cases c INNER JOIN complaints co ON co.complaint_id = c.complaint_id WHERE c.case_status <> 'Archived' ORDER BY c.docket_date DESC, c.case_id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
