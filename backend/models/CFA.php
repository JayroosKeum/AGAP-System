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

        // 1. Check Complainant Show Cause Evaluation & Default
        $scCompStmt = $this->conn->prepare("
            SELECT * FROM show_cause_evaluations
            WHERE case_id = ? AND party_type = 'Complainant'
            ORDER BY evaluation_id DESC LIMIT 1
        ");
        $scCompStmt->execute([$caseId]);
        $scComp = $scCompStmt->fetch(PDO::FETCH_ASSOC);

        $isCompBarred = ($case['case_status'] === 'DISMISSED_BARRED')
            || (!empty($scComp) && (int) ($scComp['is_justified'] ?? 1) === 0);

        // 2. Check Respondent Show Cause Evaluation & Default
        $scRespStmt = $this->conn->prepare("
            SELECT * FROM show_cause_evaluations
            WHERE case_id = ? AND party_type = 'Respondent'
            ORDER BY evaluation_id DESC LIMIT 1
        ");
        $scRespStmt->execute([$caseId]);
        $scResp = $scRespStmt->fetch(PDO::FETCH_ASSOC);

        $isRespDefault = ($case['case_status'] === 'RESPONDENT_DEFAULT')
            || (!empty($scResp) && (int) ($scResp['is_justified'] ?? 1) === 0);

        // 3. Check Conciliation / Pangkat Stage outcome
        $pangkatStmt = $this->conn->prepare("
            SELECT outcome, stage_status FROM case_stages
            WHERE case_id = ? AND stage_type = 'Conciliation'
            ORDER BY stage_id DESC LIMIT 1
        ");
        $pangkatStmt->execute([$caseId]);
        $pangkatStage = $pangkatStmt->fetch(PDO::FETCH_ASSOC);

        // Check conciliation hearings
        $conHStmt = $this->conn->prepare("
            SELECT COUNT(*) FROM hearings
            WHERE case_id = ? AND hearing_type = 'Conciliation' AND status != 'Cancelled'
        ");
        $conHStmt->execute([$caseId]);
        $conCount = (int) $conHStmt->fetchColumn();

        // Check hearing minutes outcomes
        $minOutcomeStmt = $this->conn->prepare("
            SELECT hm.session_outcome FROM hearing_minutes hm
            INNER JOIN hearings h ON h.hearing_id = hm.hearing_id
            WHERE h.case_id = ?
            ORDER BY hm.minute_id DESC LIMIT 1
        ");
        $minOutcomeStmt->execute([$caseId]);
        $lastOutcome = (string) ($minOutcomeStmt->fetchColumn() ?: '');

        // 4. Check Repudiation
        $repudStmt = $this->conn->prepare("
            SELECT settlement_id, repudiation_status, repudiation_date FROM settlements
            WHERE case_id = ? ORDER BY settlement_id DESC LIMIT 1
        ");
        $repudStmt->execute([$caseId]);
        $settlement = $repudStmt->fetch(PDO::FETCH_ASSOC);
        $isRepudiated = ($case['case_status'] === 'Repudiated' || $lastOutcome === 'Repudiated' || !empty($settlement['repudiation_date']) || ($settlement['repudiation_status'] ?? '') === 'Repudiated');

        // 5. Check Summons Delivery
        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        $summonService = new SummonDeliveryService($this->conn);
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
                'available_forms' => ['KP Form 20-A'],
                'barred_complainant' => true,
                'defaulted_respondent' => false,
                'ground' => 'COMPLAINANT_DEFAULT',
                'reason' => 'Complainant willfully failed to appear for hearings without justifiable cause. Complainant is barred from filing an action in court (RA 7160 Sec. 412(b)(1)).',
                'responsible_party' => 'Complainant',
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

        // Priority D: Conciliation Impasse / Failure before Pangkat
        $isConFailed = in_array($pangkatStage['outcome'] ?? '', ['Unsuccessful', 'Failed', 'Pending CFA'], true)
            || in_array($lastOutcome, ['Failed', 'Pending CFA'], true)
            || ($conCount >= 3 && ($pangkatStage['stage_status'] ?? '') === 'Completed');
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

        // Priority F: Mediation Impasse / Lapsed (or For CFA / CFA Issued)
        if (in_array($case['case_status'], ['For CFA', 'CFA Issued'], true) || in_array($lastOutcome, ['Pending CFA', 'Unsuccessful'], true)) {
            return [
                'eligible' => true,
                'recommended_form' => 'KP Form 20',
                'available_forms' => ['KP Form 20'],
                'barred_complainant' => false,
                'defaulted_respondent' => false,
                'ground' => 'MEDIATION_IMPASSE',
                'reason' => 'Mediation failed to produce a settlement or referral within statutory timeframe.',
                'responsible_party' => 'Mediation Impasse',
                'existing_forms' => $existingTypes
            ];
        }

        // Priority G: Reopened Settled Case for Non-compliance
        if ($case['case_status'] === 'Settled') {
            return [
                'eligible' => false,
                'reason' => 'This case is already Settled. To issue a CFA for non-compliance, use the "Reopen for CFA" action on the Case Details page.',
                'recommended_form' => null,
                'available_forms' => [],
                'barred_complainant' => false,
                'defaulted_respondent' => false,
                'ground' => 'CASE_SETTLED'
            ];
        }

        // Not eligible
        return [
            'eligible' => false,
            'reason' => 'Case is not currently eligible for CFA. CFA requires conciliation failure, respondent default, complainant default (bar action), repudiated settlement, or failed summons delivery.',
            'recommended_form' => null,
            'available_forms' => [],
            'barred_complainant' => false,
            'defaulted_respondent' => false,
            'ground' => 'NOT_ELIGIBLE'
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

        $isReopenWorkflow = !empty($data['workflow']) && in_array($data['workflow'], ['noncompliance_reopen', 'settled_noncompliance'], true);

        // Server-side eligibility determination
        $eligibility = $this->determineCfaEligibility($caseId);

        if (!$eligibility['eligible'] && !$isReopenWorkflow) {
            return [
                'success' => false,
                'message' => $eligibility['reason'] ?? 'Case is not currently eligible for CFA issuance.'
            ];
        }

        // Determine CFA form type
        $requestedType = trim((string) ($data['cfa_type'] ?? ''));
        $targetForm = 'KP Form 20';

        if ($requestedType !== '') {
            // Verify requested form is authorized for this case
            if ($eligibility['barred_complainant'] && $requestedType !== 'KP Form 20-A') {
                return [
                    'success' => false,
                    'message' => 'KP Form 20 is barred: Complainant has an unjustified non-appearance on record. Only KP Form 20-A (Certificate to Bar Action) is permitted.'
                ];
            }
            if (!$isReopenWorkflow && !empty($eligibility['available_forms']) && !in_array($requestedType, $eligibility['available_forms'], true)) {
                $allowed = implode(', ', $eligibility['available_forms']);
                return [
                    'success' => false,
                    'message' => "Requested form {$requestedType} is not applicable to the case outcome. Allowed: {$allowed}."
                ];
            }
            $targetForm = $requestedType;
        } else {
            $targetForm = $eligibility['recommended_form'] ?? 'KP Form 20';
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

            if ($case['case_status'] === 'Settled' && !$isReopenWorkflow) {
                if ($startedTrans && $this->conn->inTransaction()) $this->conn->rollBack();
                return ['success' => false, 'message' => 'This case is already Settled. To issue a CFA for non-compliance, use the "Reopen for CFA" button on the Case Details page.'];
            }

            // Insert into cfa_records with exact cfa_type
            $stmt = $this->conn->prepare("
                INSERT INTO cfa_records (case_id, cfa_type, issuance_date, reason)
                VALUES (?, ?, CURDATE(), ?)
                ON DUPLICATE KEY UPDATE
                    issuance_date = VALUES(issuance_date),
                    reason = VALUES(reason)
            ");
            $stmt->execute([$caseId, $targetForm, $reason]);

            // Determine new case & complaint status based on form type
            $newCaseStatus = 'CFA Issued';
            $newCompStatus = 'CFA Issued';

            if ($targetForm === 'KP Form 20-A') {
                $newCaseStatus = 'DISMISSED_BARRED';
                $newCompStatus = 'Dismissed';
            } elseif ($targetForm === 'KP Form 20-B') {
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

            // Generate and persist the exact KP Form document
            $docId = null;
            $filePath = null;
            try {
                require_once __DIR__ . '/../services/KpFormService.php';
                $kpService = new KpFormService($this->conn);
                $genDoc = $kpService->generateAndPersistForm($targetForm, $caseId, $userId ?: 1, ['reason' => $reason]);
                $docId = $genDoc['document_id'] ?? null;
                $filePath = $genDoc['file_path'] ?? null;
            } catch (Throwable $pe) {
                error_log("{$targetForm} generation notice: " . $pe->getMessage());
            }

            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                $notif = new NotificationService();
                $notif->notifyCfaIssued($caseId, (string)$case['case_number'], $targetForm, $userId);
            } catch (Throwable $ne) {
                error_log('Failed to dispatch CFA notification: ' . $ne->getMessage());
            }

            return [
                'success' => true,
                'message' => "{$targetForm} has been issued successfully.",
                'cfa_type' => $targetForm,
                'case_id' => $caseId,
                'case_number' => $case['case_number'],
                'case_status' => $newCaseStatus,
                'document_id' => $docId,
                'file_path' => $filePath,
                'pdf_url' => $filePath ? ('../../' . $filePath) : null,
            ];
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log($e->getMessage());
            return ['success' => false, 'message' => 'Failed to issue CFA: ' . $e->getMessage()];
        }
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
