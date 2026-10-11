<?php

require_once __DIR__ . '/../config/database.php';

class CFA
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    public function create(array $data, ?int $userId = null): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string) ($data['reason'] ?? ''));

        if (!$caseId) {
            return ['success' => false, 'message' => 'A valid case is required.'];
        }

        if ($reason === '') {
            return ['success' => false, 'message' => 'Please provide the reason for issuing the Certificate to File Action.'];
        }

        try {
            $this->conn->beginTransaction();

            $caseStmt = $this->conn->prepare('SELECT case_id, complaint_id, case_status, case_number FROM cases WHERE case_id = ? FOR UPDATE');
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Case not found.'];
            }

            if ($case['case_status'] === 'Archived') {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Cannot issue CFA for an archived case.'];
            }

            $stmt = $this->conn->prepare("
                INSERT INTO cfa_records (case_id, issuance_date, reason)
                VALUES (?, CURDATE(), ?)
                ON DUPLICATE KEY UPDATE
                    issuance_date = VALUES(issuance_date),
                    reason = VALUES(reason)
            ");
            $stmt->execute([$caseId, $reason]);

            // Update case status to 'CFA Issued'
            $updateCase = $this->conn->prepare("UPDATE cases SET case_status = 'CFA Issued' WHERE case_id = ?");
            $updateCase->execute([$caseId]);

            // Update complaint status to 'CFA Issued'
            $updateComplaint = $this->conn->prepare("UPDATE complaints SET status = 'CFA Issued' WHERE complaint_id = ?");
            $updateComplaint->execute([$case['complaint_id']]);

            // Mark any pending deadlines as completed
            $updateDeadlines = $this->conn->prepare("UPDATE case_deadlines SET status = 'Completed', completed_at = NOW() WHERE case_id = ? AND status = 'Pending'");
            $updateDeadlines->execute([$caseId]);

            // Log in case_history
            $history = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'CFA Issued', ?, ?)");
            $history->execute([$caseId, 'Certificate to File Action (CFA) issued: ' . $reason, $userId]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Certificate to File Action (CFA) has been issued successfully.',
                'case_id' => $caseId,
                'case_number' => $case['case_number'],
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log($e->getMessage());
            return ['success' => false, 'message' => 'Failed to issue CFA. Please try again.'];
        }
    }

    public function getByCase(int $caseId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE case_id = ?");
        $stmt->execute([$caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll(): array
    {
        $stmt = $this->conn->prepare("
            SELECT r.*, c.case_number, co.complaint_title, co.complaint_number
            FROM cfa_records r
            INNER JOIN cases c ON c.case_id = r.case_id
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            ORDER BY r.issuance_date DESC, r.cfa_id DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
