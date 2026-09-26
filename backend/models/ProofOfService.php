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

    public function create(int $caseId, int $documentId, int $servedBy, string $servedDate, ?string $remarks, ?string $imagePath, string $serviceResult = 'Served'): array
    {
        if (!$this->caseExists($caseId)) {
            return ['success' => false, 'message' => 'Active case not found.'];
        }
        $allowedResults = ['Served', 'Not Served', 'Refused', 'Respondent Not Found', 'Address Problem', 'Other'];
        if (!in_array($serviceResult, $allowedResults, true)) {
            $serviceResult = 'Served';
        }
        $document = $this->conn->prepare('SELECT document_id, template_id FROM generated_documents WHERE document_id = ? AND case_id = ?');
        $document->execute([$documentId, $caseId]);
        if (!$document->fetch()) return ['success' => false, 'message' => 'Select a generated document for this case.'];
        $duplicate = $this->conn->prepare('SELECT proof_id FROM proof_of_service WHERE document_id = ? AND served_by = ? AND served_date = ? LIMIT 1');
        $duplicate->execute([$documentId, $servedBy, $servedDate]);
        if ($duplicate->fetchColumn()) {
            return ['success' => false, 'message' => 'This service entry has already been recorded.'];
        }
        $stmt = $this->conn->prepare('INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks, image_path) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$caseId, $documentId, $serviceResult, $servedBy, $servedDate, $remarks, $imagePath]);
        $proofId = (int) $this->conn->lastInsertId();

        // Update document service status: 'Served' if Served, else 'Service Failed'
        $newDocStatus = ($serviceResult === 'Served') ? 'Served' : 'Service Failed';
        $updateDoc = $this->conn->prepare('UPDATE generated_documents SET service_status = ? WHERE document_id = ? AND case_id = ?');
        $updateDoc->execute([$newDocStatus, $documentId, $caseId]);

        // Keep complete case history of every service attempt
        $historyStmt = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?");
        $historyNote = sprintf('Summons service attempt: %s.%s', $serviceResult, $remarks ? ' Remarks: ' . $remarks : '');
        $historyStmt->execute([$historyNote, $servedBy, $caseId]);


        return ['success' => true, 'proof_id' => $proofId, 'service_result' => $serviceResult, 'document_status' => $newDocStatus];
    }

    public function getByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT p.*, CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) AS served_by_name, dt.template_name
             FROM proof_of_service p LEFT JOIN users u ON u.user_id = p.served_by
             LEFT JOIN generated_documents gd ON gd.document_id = p.document_id
             LEFT JOIN document_templates dt ON dt.template_id = gd.template_id
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
        $stmt = $this->conn->prepare("SELECT c.case_id, c.case_number, c.case_status, co.complaint_title, co.complaint_number FROM cases c INNER JOIN complaints co ON co.complaint_id = c.complaint_id WHERE c.case_status <> 'Archived' ORDER BY c.docket_date DESC, c.case_id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCaseSummary(int $caseId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT c.case_id, c.case_number, c.case_status, c.docket_date,
                    co.complaint_id, co.complaint_number, co.complaint_title, co.status AS complaint_status
             FROM cases c
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE c.case_id = ?"
        );
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return false;
        }

        $partiesStmt = $this->conn->prepare(
            "SELECT cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
             FROM complaint_parties cp
             INNER JOIN residents r ON r.resident_id = cp.resident_id
             WHERE cp.complaint_id = ?
             ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name"
        );
        $partiesStmt->execute([(int) $case['complaint_id']]);
        $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        $complainants = [];
        $respondents = [];
        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant') {
                $complainants[] = $p['full_name'];
            } elseif ($p['party_type'] === 'Respondent') {
                $respondents[] = $p['full_name'];
            }
        }
        $case['complainants'] = $complainants;
        $case['respondents'] = $respondents;

        return $case;
    }

    public function getSummonsServers(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.status = 'Active' AND r.role_name IN ('Summons Server', 'Lupon Clerk', 'Administrator')
             ORDER BY FIELD(r.role_name, 'Summons Server', 'Lupon Clerk', 'Administrator'), u.last_name, u.first_name"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
