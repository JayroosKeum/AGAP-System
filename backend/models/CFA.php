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

        // 3. Check Finalized minutes from operative Conciliation hearing ONLY
        require_once __DIR__ . '/Hearing.php';
        $nonSupersededCon = Hearing::nonSupersededSql('h', 'next_h');

        // Check FINALIZED minutes from the operative Conciliation hearing ONLY.
        // Exclude Draft minutes, cancelled/superseded hearings, non-Conciliation stages, stage outcomes, and hearing counts.
        $minOutcomeStmt = $this->conn->prepare("
            SELECT hm.session_outcome
            FROM hearings h
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
                AND hm.minute_id = (
                    SELECT MAX(hm2.minute_id)
                    FROM hearing_minutes hm2
                    WHERE hm2.hearing_id = h.hearing_id
                      AND hm2.status = 'Finalized'
                )
            WHERE h.case_id = ?
              AND h.hearing_type = 'Conciliation'
              AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
              AND {$nonSupersededCon}
            ORDER BY h.hearing_date DESC, h.hearing_id DESC
            LIMIT 1
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
                'respondent_resident_id' => $isRespDefault ? (int) $findingsByRole['Respondent']['resident_id'] : null,
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

        // Priority D: Finalized Conciliation Impasse from operative Conciliation hearing minutes
        $isMinutesConFailed = in_array($lastConOutcome, ['Failed', 'Pending CFA'], true);
        if ($isMinutesConFailed) {
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
                $absPath = $filePath ? dirname(__DIR__, 2) . '/' . ltrim((string) $filePath, '/') : null;
                $pdfAvailable = ($absPath && is_file($absPath) && filesize($absPath) > 0);
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
                    'pdf_available' => $pdfAvailable,
                    'needs_pdf_recovery' => !$pdfAvailable,
                    'pdf_url' => $pdfAvailable ? self::pdfUrl((int) $existingDoc['document_id']) : null,
                ];
            }

            // Insert new cfa_records entry
            $residentId = match ($targetForm) {
                'KP Form 20-A' => $eligibility['complainant_resident_id'] ?? null,
                'KP Form 20-B' => $eligibility['respondent_resident_id'] ?? null,
                default => null,
            };
            $stmt = $this->conn->prepare("
                INSERT INTO cfa_records (case_id, cfa_type, issuance_date, reason, resident_id)
                VALUES (?, ?, CURDATE(), ?, ?)
            ");
            $stmt->execute([$caseId, $targetForm, $reason, $residentId]);
            $newCfaId = (int) $this->conn->lastInsertId();
            $issuedRowStmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE cfa_id = ? LIMIT 1");
            $issuedRowStmt->execute([$newCfaId]);
            $issuedCfaRow = $issuedRowStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'cfa_id' => $newCfaId,
                'case_id' => $caseId,
                'cfa_type' => $targetForm,
                'issuance_date' => date('Y-m-d'),
                'reason' => $reason,
            ];

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
                'cfa_record' => $issuedCfaRow,
                'reason' => $reason,
                'strict_pdf' => true,
                'resident_id' => $residentId,
            ]);
            if (empty($genDoc['success']) || empty($genDoc['document_id']) || empty($genDoc['file_path'])) {
                throw new RuntimeException($genDoc['message'] ?? "Document generation failed for {$targetForm}.");
            }
            $docId = (int) $genDoc['document_id'];
            $filePath = (string) $genDoc['file_path'];
            $generatedAbsolutePath = dirname(__DIR__, 2) . '/' . ltrim($filePath, '/');

            require_once __DIR__ . '/../services/AuditService.php';
            $audit = new AuditService($this->conn);
            if (!$audit->log($userId ?: 1, "Issued {$targetForm}", 'CFA', $caseId)) {
                throw new RuntimeException('Unable to record CFA issuance audit log.');
            }

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
                'issuance_date' => $issuedCfaRow['issuance_date'],
                'reason' => $reason,
                'case_id' => $caseId,
                'case_number' => $case['case_number'],
                'case_status' => $newCaseStatus,
                'document_id' => $docId,
                'file_path' => $filePath,
                'pdf_available' => true,
                'needs_pdf_recovery' => false,
                'pdf_url' => self::pdfUrl($docId),
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

    /**
     * Recovers or reuses a usable PDF document for an existing CFA issuance
     * without changing its form type, issuance date, reason, or case history.
     */
    public function recoverPdf(array $data, ?int $userId = null): array
    {
        $cfaId = filter_var($data['cfa_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $requestedType = trim((string) ($data['cfa_type'] ?? ''));

        if (!$cfaId && !$caseId) {
            return ['success' => false, 'message' => 'Specify a valid cfa_id or case_id to recover the CFA PDF.'];
        }

        $startedTrans = !$this->conn->inTransaction();
        if ($startedTrans) {
            $this->conn->beginTransaction();
        }

        $generatedAbsolutePath = null;

        try {
            if ($cfaId) {
                $cfaStmt = $this->conn->prepare("
                    SELECT r.*, c.case_number, c.complaint_id, c.case_status
                    FROM cfa_records r
                    INNER JOIN cases c ON c.case_id = r.case_id
                    WHERE r.cfa_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $cfaStmt->execute([(int) $cfaId]);
                $existingCfa = $cfaStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingCfa) {
                    throw new RuntimeException("CFA issuance #{$cfaId} was not found.");
                }
                if ($caseId && (int) $existingCfa['case_id'] !== (int) $caseId) {
                    throw new RuntimeException('The requested CFA record does not belong to the specified case.');
                }
                if ($requestedType !== '' && strcasecmp((string) ($existingCfa['cfa_type'] ?? ''), $requestedType) !== 0) {
                    throw new RuntimeException('The requested form type does not match the saved CFA issuance.');
                }
            } elseif ($requestedType !== '') {
                $cfaStmt = $this->conn->prepare("
                    SELECT r.*, c.case_number, c.complaint_id, c.case_status
                    FROM cfa_records r
                    INNER JOIN cases c ON c.case_id = r.case_id
                    WHERE r.case_id = ? AND r.cfa_type = ?
                    ORDER BY r.cfa_id DESC
                    LIMIT 1
                    FOR UPDATE
                ");
                $cfaStmt->execute([(int) $caseId, $requestedType]);
                $existingCfa = $cfaStmt->fetch(PDO::FETCH_ASSOC);
                if (!$existingCfa) {
                    throw new RuntimeException("No saved {$requestedType} issuance exists for this case.");
                }
            } else {
                $cfaStmt = $this->conn->prepare("
                    SELECT r.*, c.case_number, c.complaint_id, c.case_status
                    FROM cfa_records r
                    INNER JOIN cases c ON c.case_id = r.case_id
                    WHERE r.case_id = ?
                    ORDER BY r.cfa_id ASC
                    FOR UPDATE
                ");
                $cfaStmt->execute([(int) $caseId]);
                $rows = $cfaStmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) === 0) {
                    throw new RuntimeException('No saved CFA issuance exists for this case.');
                }
                if (count($rows) > 1) {
                    throw new RuntimeException('Multiple CFA issuances exist for this case. Specify cfa_id or cfa_type.');
                }
                $existingCfa = $rows[0];
            }

            $resolvedCaseId = (int) $existingCfa['case_id'];
            $resolvedCfaId = (int) $existingCfa['cfa_id'];
            $targetForm = trim((string) ($existingCfa['cfa_type'] ?? '')) ?: 'KP Form 20';

            $lockCase = $this->conn->prepare("SELECT case_id FROM cases WHERE case_id = ? FOR UPDATE");
            $lockCase->execute([$resolvedCaseId]);
            $lockCase->fetchColumn();

            $docStmt = $this->conn->prepare("
                SELECT gd.document_id, gd.file_path
                FROM generated_documents gd
                INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                WHERE gd.case_id = ? AND dt.template_name = ?
                ORDER BY gd.document_id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $docStmt->execute([$resolvedCaseId, $targetForm]);
            $existingDoc = $docStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingDoc && !empty($existingDoc['file_path'])) {
                $existingAbs = dirname(__DIR__, 2) . '/' . ltrim((string) $existingDoc['file_path'], '/');
                if (is_file($existingAbs) && filesize($existingAbs) > 0) {
                    if ($startedTrans && $this->conn->inTransaction()) {
                        $this->conn->commit();
                    }
                    return [
                        'success' => true,
                        'recovered' => false,
                        'reused' => true,
                        'message' => "{$targetForm} PDF is already available and was reused without duplication.",
                        'cfa_id' => $resolvedCfaId,
                        'cfa_type' => $targetForm,
                        'issuance_date' => $existingCfa['issuance_date'],
                        'reason' => $existingCfa['reason'],
                        'case_id' => $resolvedCaseId,
                        'case_number' => $existingCfa['case_number'],
                        'document_id' => (int) $existingDoc['document_id'],
                        'file_path' => $existingDoc['file_path'],
                        'pdf_available' => true,
                        'needs_pdf_recovery' => false,
                        'pdf_url' => self::pdfUrl((int) $existingDoc['document_id']),
                    ];
                }
            }

            $residentId = !empty($existingCfa['resident_id']) ? (int) $existingCfa['resident_id'] : null;
            if (!$residentId && in_array($targetForm, ['KP Form 20-A', 'KP Form 20-B'], true)) {
                $role = ($targetForm === 'KP Form 20-A') ? 'Complainant' : 'Respondent';
                $scStmt = $this->conn->prepare("
                    SELECT DISTINCT sce.resident_id FROM show_cause_evaluations sce
                    JOIN complaint_parties cp ON cp.complaint_id = ? AND cp.party_type = sce.party_type AND cp.resident_id = sce.resident_id
                    WHERE sce.case_id = ? AND sce.party_type = ? AND sce.is_justified = 0 AND sce.notice_delivery_id IS NOT NULL
                      AND DATE(sce.created_at) <= ?
                ");
                $scStmt->execute([(int) $existingCfa['complaint_id'], $resolvedCaseId, $role, $existingCfa['issuance_date']]);
                $candidates = $scStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!$candidates) {
                    $cpStmt = $this->conn->prepare("
                        SELECT cp.resident_id
                        FROM complaint_parties cp
                        WHERE cp.complaint_id = ? AND cp.party_type = ?
                    ");
                    $cpStmt->execute([(int) $existingCfa['complaint_id'], $role]);
                    $candidates = $cpStmt->fetchAll(PDO::FETCH_COLUMN);
                }
                if (count($candidates) !== 1) {
                    throw new RuntimeException('The original certificate recipient is ambiguous. Locate the issued document or review the historical record before recovering it.');
                }
                $residentId = (int) $candidates[0];
            }

            $kpService = $this->createKpFormService();
            $genDoc = $kpService->generateAndPersistForm($targetForm, $resolvedCaseId, $userId ?: 1, [
                'cfa_record' => $existingCfa,
                'reason' => $existingCfa['reason'],
                'strict_pdf' => true,
                'bypass_prerequisites' => true,
                'resident_id' => $residentId,
                'existing_document_id' => $existingDoc ? (int) $existingDoc['document_id'] : null,
                'skip_delivery' => true,
            ]);

            if (empty($genDoc['success']) || empty($genDoc['document_id']) || empty($genDoc['file_path'])) {
                throw new RuntimeException($genDoc['message'] ?? "Unable to render PDF for {$targetForm}.");
            }

            $filePath = (string) $genDoc['file_path'];
            $generatedAbsolutePath = dirname(__DIR__, 2) . '/' . ltrim($filePath, '/');
            if (!is_file($generatedAbsolutePath) || filesize($generatedAbsolutePath) === 0) {
                throw new RuntimeException("Rendered PDF file for {$targetForm} is missing or empty on disk.");
            }

            require_once __DIR__ . '/../services/AuditService.php';
            $audit = new AuditService($this->conn);
            if (!$audit->log($userId ?: 1, "Recovered {$targetForm} PDF (CFA #{$resolvedCfaId})", 'CFA', $resolvedCaseId)) {
                throw new RuntimeException('Unable to record CFA PDF recovery audit entry.');
            }

            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'recovered' => true,
                'reused' => false,
                'message' => "{$targetForm} PDF recovered cleanly while preserving original issuance details.",
                'cfa_id' => $resolvedCfaId,
                'cfa_type' => $targetForm,
                'issuance_date' => $existingCfa['issuance_date'],
                'reason' => $existingCfa['reason'],
                'case_id' => $resolvedCaseId,
                'case_number' => $existingCfa['case_number'],
                'document_id' => (int) $genDoc['document_id'],
                'file_path' => $filePath,
                'pdf_available' => true,
                'needs_pdf_recovery' => false,
                'pdf_url' => self::pdfUrl((int) $genDoc['document_id']),
            ];
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            if ($generatedAbsolutePath && is_file($generatedAbsolutePath)) {
                @unlink($generatedAbsolutePath);
            }
            error_log('CFA PDF recovery error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to recover CFA PDF: ' . $e->getMessage(),
            ];
        }
    }

    protected function createKpFormService(): KpFormService
    {
        require_once __DIR__ . '/../services/KpFormService.php';
        return new KpFormService($this->conn);
    }

    private static function pdfUrl(int $documentId): string
    {
        return '../../../backend/api/documents/download.php?id=' . $documentId . '&stored=1';
    }

    private function attachDocumentState(array $row): array
    {
        $docStmt = $this->conn->prepare("
            SELECT gd.document_id, gd.file_path
            FROM generated_documents gd
            INNER JOIN document_templates dt ON dt.template_id = gd.template_id
            WHERE gd.case_id = ? AND dt.template_name = ?
            ORDER BY gd.document_id DESC
            LIMIT 1
        ");
        $formType = trim((string) ($row['cfa_type'] ?? '')) ?: 'KP Form 20';
        $docStmt->execute([(int) $row['case_id'], $formType]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
        $filePath = $doc['file_path'] ?? null;
        $absPath = $filePath ? dirname(__DIR__, 2) . '/' . ltrim((string) $filePath, '/') : null;
        $pdfAvailable = ($absPath && is_file($absPath) && filesize($absPath) > 0);
        $row['cfa_type'] = $formType;
        $row['document_id'] = $doc ? (int) $doc['document_id'] : null;
        $row['file_path'] = $filePath;
        $row['pdf_available'] = $pdfAvailable;
        $row['needs_pdf_recovery'] = !$pdfAvailable;
        $row['pdf_url'] = $pdfAvailable ? self::pdfUrl((int) $doc['document_id']) : null;
        return $row;
    }

    public function getByCase(int $caseId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE case_id = ? ORDER BY cfa_id DESC LIMIT 1");
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->attachDocumentState($row) : false;
    }

    public function getAllByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE case_id = ? ORDER BY cfa_id ASC");
        $stmt->execute([$caseId]);
        return array_map(fn (array $row) => $this->attachDocumentState($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
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
        return array_map(fn (array $row) => $this->attachDocumentState($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
