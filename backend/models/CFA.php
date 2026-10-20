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

        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        $summonService = new SummonDeliveryService($this->conn);
        $eligibility = $summonService->getCfaEligibility($caseId);

        // Check if issuing basis is summons delivery failure
        $isSummonsFailureReason = (
            !empty($data['workflow']) && $data['workflow'] === 'summons_failure'
        ) || (
            stripos($reason, 'delivery attempt') !== false
            || stripos($reason, 'delivery failure') !== false
            || stripos($reason, 'unserved') !== false
            || stripos($reason, 'repeated summons') !== false
        );

        if ($isSummonsFailureReason && !$eligibility['eligible']) {
            return [
                'success' => false,
                'message' => $eligibility['reason'] ?? 'CFA Locked — All six (6) summons delivery attempts must result in qualifying failure before CFA can be issued.'
            ];
        }

        $startedTrans = false;
        if (!$this->conn->inTransaction()) {
            $this->conn->beginTransaction();
            $startedTrans = true;
        }

        try {
            $caseStmt = $this->conn->prepare('SELECT case_id, complaint_id, case_status, case_number FROM cases WHERE case_id = ? FOR UPDATE');
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'Case not found.'];
            }

            if ($case['case_status'] === 'Archived') {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'Cannot issue CFA for an archived case.'];
            }

            // Allow Settled cases to get a CFA when triggered by non-compliance (RA 7160 Sec. 417)
            $isReopenWorkflow = !empty($data['workflow']) && in_array($data['workflow'], ['noncompliance_reopen', 'settled_noncompliance'], true);
            if ($case['case_status'] === 'Settled' && !$isReopenWorkflow) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'This case is already Settled. To issue a CFA for non-compliance, use the "Reopen for CFA" button on the Case Details page.'];
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

            // Generate KP Form 20 (Certificate to File Action) PDF
            $docId = null;
            $filePath = null;
            try {
                require_once __DIR__ . '/../services/PDFService.php';
                $pdfService = new PDFService();

                // Fetch complaint title and parties
                $complaintStmt = $this->conn->prepare("SELECT complaint_title FROM complaints WHERE complaint_id = ?");
                $complaintStmt->execute([(int) $case['complaint_id']]);
                $complaintTitle = (string) $complaintStmt->fetchColumn() ?: 'Dispute';

                $partiesStmt = $this->conn->prepare("
                    SELECT cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
                ");
                $partiesStmt->execute([(int) $case['complaint_id']]);
                $allParties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);
                $complainants = array_values(array_filter($allParties, fn($p) => $p['party_type'] === 'Complainant'));
                $respondents = array_values(array_filter($allParties, fn($p) => $p['party_type'] === 'Respondent'));

                $root = dirname(__DIR__, 2);
                $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $case['case_number']);
                $fullDir = $root . '/' . $relativeDir;
                if (!is_dir($fullDir)) {
                    @mkdir($fullDir, 0750, true);
                }
                $fileName = sprintf('KP-Form-20-CFA-%s.pdf', date('Ymd-His'));
                $filePath = $relativeDir . '/' . $fileName;
                $fullPath = $fullDir . '/' . $fileName;

                $pdfData = [
                    'case_number' => $case['case_number'],
                    'complaint_title' => $complaintTitle,
                    'complainants' => $complainants,
                    'respondents' => $respondents,
                    'reason' => $reason,
                    'issuance_date' => date('Y-m-d'),
                ];

                $pdfService->generateKp20($pdfData, $fullPath);

                // Insert into generated_documents
                $tStmt = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 20' LIMIT 1");
                $tStmt->execute();
                $tplId = (int) $tStmt->fetchColumn() ?: 11;

                $insDoc = $this->conn->prepare("
                    INSERT INTO generated_documents (
                        case_id, template_id, file_path,
                        generated_by, service_status, generated_at
                    ) VALUES (
                        ?, ?, ?,
                        ?, 'Generated', NOW()
                    )
                ");
                $insDoc->execute([
                    $caseId,
                    $tplId,
                    $filePath,
                    $userId
                ]);
                $docId = (int) $this->conn->lastInsertId();
            } catch (Throwable $pe) {
                error_log('KP Form 20 PDF generation notice: ' . $pe->getMessage());
            }

            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'message' => 'Certificate to File Action (CFA) has been issued successfully.',
                'case_id' => $caseId,
                'case_number' => $case['case_number'],
                'document_id' => $docId,
                'file_path' => $filePath,
                'pdf_url' => $filePath ? ('../../' . $filePath) : null,
            ];
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
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
