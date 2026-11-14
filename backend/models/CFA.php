<?php

require_once __DIR__ . '/../config/database.php';

class CFA
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    /**
     * Determines server-side CFA eligibility and form type (KP Form 20, 20-A, 20-B).
     */
    public function determineCfaEligibility(int $caseId): array
    {
        $caseStmt = $this->conn->prepare('SELECT case_id, complaint_id, case_status, case_number FROM cases WHERE case_id = ?');
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return [
                'eligible' => false,
                'reason' => 'Case not found.',
                'recommended_form' => null,
                'available_forms' => [],
                'barred_complainant' => false,
                'defaulted_respondent' => false,
                'ground' => 'CASE_NOT_FOUND',
            ];
        }

        if ($case['case_status'] === 'Archived') {
            return [
                'eligible' => false,
                'reason' => 'Cannot issue CFA for an archived case.',
                'recommended_form' => null,
                'available_forms' => [],
                'barred_complainant' => false,
                'defaulted_respondent' => false,
                'ground' => 'CASE_ARCHIVED',
            ];
        }

        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        $summonService = new SummonDeliveryService($this->conn);
        $findingsByRole = [];
        foreach ($summonService->getVerifiedShowCauseFindings($caseId) as $finding) {
            $findingsByRole[$finding['party_type']] ??= $finding;
        }
        $isCompBarred = isset($findingsByRole['Complainant']);
        $isRespDefault = isset($findingsByRole['Respondent']);

        // 3. Check Conciliation / Pangkat Stage outcome & Finalized Conciliation minutes ONLY
        $pangkatStmt = $this->conn->prepare("
            SELECT outcome, stage_status FROM case_stages
            WHERE case_id = ? AND stage_type = 'Conciliation'
            ORDER BY stage_id DESC LIMIT 1
        ");
        $pangkatStmt->execute([$caseId]);
        $pangkatStage = $pangkatStmt->fetch(PDO::FETCH_ASSOC);

        // Check active conciliation hearings
        $conHStmt = $this->conn->prepare("
            SELECT COUNT(*) FROM hearings
            WHERE case_id = ? AND hearing_type = 'Conciliation'
              AND status NOT IN ('Cancelled', 'Office Cancelled')
        ");
        $conHStmt->execute([$caseId]);
        $conCount = (int) $conHStmt->fetchColumn();

        // Check FINALIZED Conciliation hearing minutes ONLY (exclude Draft and non-Conciliation hearings)
        $minOutcomeStmt = $this->conn->prepare("
            SELECT hm.session_outcome
            FROM hearing_minutes hm
            INNER JOIN hearings h ON h.hearing_id = hm.hearing_id
            WHERE h.case_id = ?
              AND h.hearing_type = 'Conciliation'
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
              AND hm.status = 'Finalized'
            ORDER BY hm.minute_id DESC LIMIT 1
        ");
        $minOutcomeStmt->execute([$caseId]);
        $lastConOutcome = (string) ($minOutcomeStmt->fetchColumn() ?: '');

        // 4. Check Saved Settlement (Repudiation or Non-Compliance under RA 7160 Sec. 417)
        $repudStmt = $this->conn->prepare("
            SELECT settlement_id, repudiation_status, repudiation_date, repudiation_reason, compliance_status
            FROM settlements
            WHERE case_id = ? ORDER BY settlement_id DESC LIMIT 1
        ");
        $repudStmt->execute([$caseId]);
        $settlement = $repudStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $isRepudiated = !empty($settlement)
            && (($settlement['repudiation_status'] ?? '') === 'Repudiated' || !empty($settlement['repudiation_date']));

        // 5. Check Summons Delivery
        $summonElig = $summonService->getCfaEligibility($caseId);

        // 6. Check existing CFA records
        $cfaStmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE case_id = ?");
        $cfaStmt->execute([$caseId]);
        $existingCfas = $cfaStmt->fetchAll(PDO::FETCH_ASSOC);
        $existingTypes = array_column($existingCfas, 'cfa_type');

        // Ground evaluation:
        // Priority A: Complainant Default -> KP Form 20-A (Certificate to Bar Action). KP Form 20 is strictly barred!
        if ($isCompBarred) {
            return [
                'eligible' => true,
                'recommended_form' => 'KP Form 20-A',
                'available_forms' => $isRespDefault ? ['KP Form 20-A', 'KP Form 20-B'] : ['KP Form 20-A'],
                'barred_complainant' => true,
                'defaulted_respondent' => (bool) $isRespDefault,
                'ground' => 'COMPLAINANT_DEFAULT',
                'reason' => $isRespDefault
                    ? 'Both parties willfully failed to appear without justifiable cause. Complainant is barred from filing an action (KP Form 20-A / 21) and Respondent is barred from filing a counterclaim (KP Form 20-B / 22); KP Form 20 is strictly barred.'
                    : 'Complainant willfully failed to appear for hearings without justifiable cause. Complainant is barred from filing an action in court (RA 7160 Sec. 412(b)(1)).',
                'responsible_party' => $isRespDefault ? 'Both' : 'Complainant',
                'complainant_resident_id' => (int) $findingsByRole['Complainant']['resident_id'],
                'existing_forms' => $existingTypes
            ];
        }

        // Priority B: Respondent Default -> KP Form 20-B (Bar Counterclaim) and KP Form 20 (CFA for Complainant)
        if ($isRespDefault) {
            $avail = ['KP Form 20-B', 'KP Form 20'];
            return [
                'eligible' => true,
                'recommended_form' => in_array('KP Form 20-B', $existingTypes, true) ? 'KP Form 20' : 'KP Form 20-B',
                'available_forms' => $avail,
                'barred_complainant' => false,
                'defaulted_respondent' => true,
                'ground' => 'RESPONDENT_DEFAULT',
                'reason' => 'Respondent willfully failed to appear for hearings without justifiable cause. Respondent is barred from filing a counterclaim and Complainant is entitled to a Certificate to File Action (RA 7160 Sec. 412(b)(2)).',
                'responsible_party' => 'Respondent',
                'respondent_resident_id' => (int) $findingsByRole['Respondent']['resident_id'],
                'existing_forms' => $existingTypes
            ];
        }

        // Priority C: Repudiated Settlement
        if ($isRepudiated) {
            return [
                'eligible' => true,
                'recommended_form' => 'KP Form 20',
                'available_forms' => ['KP Form 20'],
                'barred_complainant' => false,
                'defaulted_respondent' => false,
                'ground' => 'REPUDIATED_SETTLEMENT',
                'reason' => 'Amicable settlement or arbitration agreement was formally repudiated within the statutory period.',
                'responsible_party' => 'Repudiating Party',
                'existing_forms' => $existingTypes
            ];
        }

        // Priority D: Finalized Conciliation Impasse / Failure before Pangkat
        $isStageConFailed = !empty($pangkatStage)
            && ($pangkatStage['stage_status'] ?? '') === 'Completed'
            && in_array($pangkatStage['outcome'] ?? '', ['Unsuccessful', 'Failed', 'Pending CFA'], true);
        $isMinutesConFailed = in_array($lastConOutcome, ['Failed', 'Pending CFA'], true);
        $isMaxConFailed = ($conCount >= 3
            && ($pangkatStage['stage_status'] ?? '') === 'Completed'
            && !in_array($pangkatStage['outcome'] ?? '', ['Settled', 'Arbitration', 'Arbitration Award'], true));

        $isConFailed = $isStageConFailed || $isMinutesConFailed || $isMaxConFailed;
        if ($isConFailed) {
            return [
                'eligible' => true,
                'recommended_form' => 'KP Form 20',
                'available_forms' => ['KP Form 20'],
                'barred_complainant' => false,
                'defaulted_respondent' => false,
                'ground' => 'CONCILIATION_IMPASSE',
                'reason' => 'Conciliation proceedings before the Pangkat Tagapagkasundo failed to reach an amicable settlement.',
                'responsible_party' => 'Dispute Impasse',
                'existing_forms' => $existingTypes
            ];
        }

        // Priority E: Summons Delivery Failure (documented attempts failed)
        if (!empty($summonElig['eligible'])) {
            return [
                'eligible' => true,
                'recommended_form' => 'KP Form 20',
                'available_forms' => ['KP Form 20'],
                'barred_complainant' => false,
                'defaulted_respondent' => false,
                'ground' => 'SUMMONS_DELIVERY_FAILURE',
                'reason' => $summonElig['reason'] ?? 'Respondent summons unserved after documented delivery attempts.',
                'responsible_party' => 'Respondent Unserved',
                'existing_forms' => $existingTypes
            ];
        }

        // Priority F: Documented Settlement Non-Compliance / Breach (RA 7160 Sec. 417)
        if (!empty($settlement) && in_array($settlement['compliance_status'] ?? '', ['Violated', 'Breached'], true)) {
            $noncompHistStmt = $this->conn->prepare("
                SELECT COUNT(*) FROM case_history
                WHERE case_id = ?
                  AND (remarks LIKE '%Non-compliance%' OR remarks LIKE '%breach%' OR remarks LIKE '%Violated%' OR remarks LIKE '%reopened for CFA%')
            ");
            $noncompHistStmt->execute([$caseId]);
            $hasDocumentedBreach = ((int) $noncompHistStmt->fetchColumn() > 0) || !empty($settlement['repudiation_reason']);

            if ($hasDocumentedBreach) {
                return [
                    'eligible' => true,
                    'recommended_form' => 'KP Form 20',
                    'available_forms' => ['KP Form 20'],
                    'barred_complainant' => false,
                    'defaulted_respondent' => false,
                    'ground' => 'SETTLEMENT_NONCOMPLIANCE',
                    'reason' => 'Amicable Settlement was breached / marked non-compliant with documented grounds on record (RA 7160 Sec. 417).',
                    'responsible_party' => 'Settlement Non-Compliance',
                    'existing_forms' => $existingTypes
                ];
            }
        }

        // Priority G: Settled Case without recorded breach/repudiation
        if ($case['case_status'] === 'Settled') {
            return [
                'eligible' => false,
                'reason' => 'This case is already Settled. To issue a CFA for non-compliance, first record the documented settlement breach using the "Reopen for CFA" action.',
                'recommended_form' => null,
                'available_forms' => [],
                'barred_complainant' => false,
                'defaulted_respondent' => false,
                'ground' => 'CASE_SETTLED',
                'existing_forms' => $existingTypes
            ];
        }

        // Not eligible
        return [
            'eligible' => false,
            'reason' => 'Case is not currently eligible for CFA. CFA requires a finalized conciliation failure, respondent default, complainant default (bar action), repudiated settlement, documented settlement non-compliance, or exhausted summons delivery failure.',
            'recommended_form' => null,
            'available_forms' => [],
            'barred_complainant' => false,
            'defaulted_respondent' => false,
            'ground' => 'NOT_ELIGIBLE',
            'existing_forms' => $existingTypes
        ];
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

        $requestedType = trim((string) ($data['cfa_type'] ?? ''));
        $allowedCfaTypes = ['KP Form 20', 'KP Form 20-A', 'KP Form 20-B'];
        if ($requestedType !== '' && !in_array($requestedType, $allowedCfaTypes, true)) {
            return ['success' => false, 'message' => "Invalid certificate type requested: {$requestedType}."];
        }

        $startedTrans = false;
        if (!$this->conn->inTransaction()) {
            $this->conn->beginTransaction();
            $startedTrans = true;
        }

        $generatedAbsolutePath = null;

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

            // Re-evaluate authoritative server-side eligibility inside transaction lock (no client workflow/status/reason bypasses)
            $eligibility = $this->determineCfaEligibility($caseId);
            if (empty($eligibility['eligible'])) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => $eligibility['reason'] ?? 'Case is not currently eligible for CFA issuance.'
                ];
            }

            $targetForm = $requestedType !== '' ? $requestedType : ($eligibility['recommended_form'] ?? '');
            if ($targetForm === '') {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'No eligible CFA form could be determined for this case.'];
            }

            if (!empty($eligibility['barred_complainant']) && $targetForm !== 'KP Form 20-A') {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'KP Form 20 is barred: Complainant has an unjustified non-appearance on record. Only KP Form 20-A (Certificate to Bar Action) is permitted.'
                ];
            }

            $availableForms = $eligibility['available_forms'] ?? [];
            if (empty($availableForms) || !in_array($targetForm, $availableForms, true)) {
                $allowed = implode(', ', $availableForms);
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => "Requested form {$targetForm} is not applicable to the recorded ground ({$eligibility['ground']}). Allowed: {$allowed}."
                ];
            }

            // Idempotent duplicate check: return existing issuance without modifying original date, reason, or history
            $dupStmt = $this->conn->prepare("
                SELECT cfa_id, cfa_type, issuance_date, reason
                FROM cfa_records
                WHERE case_id = ? AND cfa_type = ?
                LIMIT 1
                FOR UPDATE
            ");
            $dupStmt->execute([$caseId, $targetForm]);
            $existingCfa = $dupStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingCfa) {
                $docStmt = $this->conn->prepare("
                    SELECT gd.document_id, gd.file_path
                    FROM generated_documents gd
                    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                    WHERE gd.case_id = ? AND dt.template_name = ?
                    ORDER BY gd.document_id DESC
                    LIMIT 1
                ");
                $docStmt->execute([$caseId, $targetForm]);
                $existingDoc = $docStmt->fetch(PDO::FETCH_ASSOC);

                if ($startedTrans && $this->conn->inTransaction()) {
                    $this->conn->commit();
                }

                $filePath = $existingDoc['file_path'] ?? null;
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => "{$targetForm} was already issued on {$existingCfa['issuance_date']}. Returning existing issuance.",
                    'cfa_id' => (int) $existingCfa['cfa_id'],
                    'cfa_type' => $existingCfa['cfa_type'],
                    'issuance_date' => $existingCfa['issuance_date'],
                    'reason' => $existingCfa['reason'],
                    'case_id' => $caseId,
                    'case_number' => $case['case_number'],
                    'case_status' => $case['case_status'],
                    'document_id' => $existingDoc ? (int) $existingDoc['document_id'] : null,
                    'file_path' => $filePath,
                    'pdf_url' => $filePath ? ('../../' . $filePath) : null,
                ];
            }

            // Insert new cfa_records entry
            $stmt = $this->conn->prepare("
                INSERT INTO cfa_records (case_id, cfa_type, issuance_date, reason)
                VALUES (?, ?, CURDATE(), ?)
            ");
            $stmt->execute([$caseId, $targetForm, $reason]);
            $newCfaId = (int) $this->conn->lastInsertId();

            // Determine new case & complaint status based on form type
            $newCaseStatus = 'CFA Issued';
            $newCompStatus = 'CFA Issued';

            if ($targetForm === 'KP Form 20-A') {
                $newCaseStatus = 'DISMISSED_BARRED';
                $newCompStatus = 'Dismissed';
            } elseif ($targetForm === 'KP Form 20-B' && !in_array('KP Form 20', $eligibility['existing_forms'] ?? [], true)) {
                $newCaseStatus = 'RESPONDENT_DEFAULT';
                $newCompStatus = 'Mediation'; // Keeps proceeding for complainant's CFA
            }

            $updateCase = $this->conn->prepare("UPDATE cases SET case_status = ?, updated_at = NOW() WHERE case_id = ?");
            $updateCase->execute([$newCaseStatus, $caseId]);

            $updateComplaint = $this->conn->prepare("UPDATE complaints SET status = ?, updated_at = NOW() WHERE complaint_id = ?");
            $updateComplaint->execute([$newCompStatus, $case['complaint_id']]);

            // Mark any pending deadlines as completed
            $updateDeadlines = $this->conn->prepare("UPDATE case_deadlines SET status = 'Completed', completed_at = NOW() WHERE case_id = ? AND status = 'Pending'");
            $updateDeadlines->execute([$caseId]);

            // Log in case_history
            $history = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)");
            $history->execute([$caseId, $newCaseStatus, "{$targetForm} issued: {$reason}", $userId]);

            // Generate and persist the exact KP Form document; failures must abort & roll back cleanly so retry stays open
            $kpService = $this->createKpFormService();
            $genDoc = $kpService->generateAndPersistForm($targetForm, $caseId, $userId ?: 1, [
                'reason' => $reason,
                'strict_pdf' => true,
                'resident_id' => match ($targetForm) {
                    'KP Form 20-A' => $eligibility['complainant_resident_id'] ?? null,
                    'KP Form 20-B' => $eligibility['respondent_resident_id'] ?? null,
                    default => null,
                },
            ]);
            if (empty($genDoc['success']) || empty($genDoc['document_id']) || empty($genDoc['file_path'])) {
                throw new RuntimeException($genDoc['message'] ?? "Document generation failed for {$targetForm}.");
            }
            $docId = (int) $genDoc['document_id'];
            $filePath = (string) $genDoc['file_path'];
            $generatedAbsolutePath = dirname(__DIR__, 2) . '/' . ltrim($filePath, '/');

            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            if ($startedTrans) {
                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    $notif = new NotificationService();
                    $notif->notifyCfaIssued($caseId, (string)$case['case_number'], $targetForm, $userId);
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch CFA notification: ' . $ne->getMessage());
                }
            }

            return [
                'success' => true,
                'duplicate' => false,
                'message' => "{$targetForm} has been issued successfully.",
                'cfa_id' => $newCfaId,
                'cfa_type' => $targetForm,
                'issuance_date' => date('Y-m-d'),
                'reason' => $reason,
                'case_id' => $caseId,
                'case_number' => $case['case_number'],
                'case_status' => $newCaseStatus,
                'document_id' => $docId,
                'file_path' => $filePath,
                'pdf_url' => '../../' . $filePath,
            ];
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if ($generatedAbsolutePath && is_file($generatedAbsolutePath)) {
                @unlink($generatedAbsolutePath);
            }
            error_log($e->getMessage());
            return ['success' => false, 'message' => 'Failed to issue CFA: ' . $e->getMessage()];
        }
    }

    protected function createKpFormService(): KpFormService
    {
        require_once __DIR__ . '/../services/KpFormService.php';
        return new KpFormService($this->conn);
    }

    public function getByCase(int $caseId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE case_id = ? ORDER BY cfa_id DESC LIMIT 1");
        $stmt->execute([$caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE case_id = ? ORDER BY cfa_id ASC");
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
