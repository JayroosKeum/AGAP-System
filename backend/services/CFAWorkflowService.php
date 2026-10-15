<?php

declare(strict_types=1);

final class CFAWorkflowService
{
    public const GROUND_NO_SETTLEMENT = 'NO_SETTLEMENT_AFTER_CONCILIATION';
    public const GROUND_NO_CONFRONTATION = 'NO_CONFRONTATION_NO_FAULT_COMPLAINANT';
    public const GROUND_REPUDIATED_SETTLEMENT = 'REPUDIATED_SETTLEMENT';

    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function overview(int $caseId): array
    {
        $caseStmt = $this->conn->prepare(
            'SELECT case_id, case_number, case_status FROM cases WHERE case_id = ? LIMIT 1'
        );
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return $this->fail('Case not found.');
        }

        $requestStmt = $this->conn->prepare(
            'SELECT cfa_request_id, request_id, source_outcome_id, source_hearing_id,
                    ground_code, ground_details, request_status, requested_by,
                    requested_at, reviewed_by, reviewed_at, review_decision,
                    review_notes, approved_at
             FROM cfa_requests
             WHERE case_id = ?
             ORDER BY requested_at DESC, cfa_request_id DESC'
        );
        $requestStmt->execute([$caseId]);
        $requests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);

        $certificateStmt = $this->conn->prepare(
            'SELECT certificate_id, cfa_request_id, certificate_number, form_code,
                    legal_ground_code, issuer_user_id, attesting_user_id,
                    issued_at, document_id, verification_code, release_status,
                    released_to_party_id, released_at, release_method
             FROM cfa_certificates
             WHERE case_id = ? AND voided_at IS NULL
             ORDER BY issued_at DESC, certificate_id DESC'
        );
        $certificateStmt->execute([$caseId]);

        return [
            'success' => true,
            'data' => [
                'case' => $case,
                'requests' => $requests,
                'certificates' => $certificateStmt->fetchAll(PDO::FETCH_ASSOC),
            ],
        ];
    }

    public function submit(array $input, int $actorUserId): array
    {
        $caseId = $this->positiveInt($input['case_id'] ?? null);
        $sourceOutcomeId = $this->positiveInt($input['source_outcome_id'] ?? null);
        $sourceHearingId = $this->positiveInt($input['source_hearing_id'] ?? null);
        $requestingPartyId = $this->positiveInt($input['requesting_party_id'] ?? null);
        $requestId = strtolower(trim((string) ($input['request_id'] ?? '')));
        $groundCode = strtoupper(trim((string) ($input['ground_code'] ?? '')));
        $groundDetails = $this->nullableText($input['ground_details'] ?? null, 4000);

        if (!$caseId || !$sourceOutcomeId || !$sourceHearingId) {
            return $this->fail('case_id, source_outcome_id, and source_hearing_id are required.');
        }
        if (!$this->isUuid($requestId)) {
            return $this->fail('A valid UUID request_id is required.');
        }
        if (!in_array($groundCode, $this->allowedGrounds(), true)) {
            return $this->fail('Invalid CFA ground_code.');
        }

        try {
            $this->conn->beginTransaction();

            $existing = $this->conn->prepare(
                'SELECT cfa_request_id, case_id, ground_code, request_status, requested_at
                 FROM cfa_requests WHERE request_id = ? LIMIT 1'
            );
            $existing->execute([$requestId]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingRow) {
                $this->conn->commit();
                return [
                    'success' => true,
                    'message' => 'CFA request was already submitted.',
                    'data' => $existingRow,
                    'idempotent' => true,
                ];
            }

            $case = $this->lockCase($caseId);
            if (!$case || $case['case_status'] !== 'CFA Review') {
                throw new RuntimeException('The case must be in CFA Review.');
            }

            $outcome = $this->lockEligibleOutcome($caseId, $sourceOutcomeId, $sourceHearingId);
            if (!$outcome) {
                throw new RuntimeException('An eligible finalized Conciliation outcome is required.');
            }
            $this->validateGroundAgainstOutcome($groundCode, $outcome['outcome_code']);
            $this->requireNoActiveRequest($caseId);

            $insert = $this->conn->prepare(
                "INSERT INTO cfa_requests (
                    request_id, case_id, requesting_party_id, source_outcome_id,
                    source_hearing_id, ground_code, ground_details, request_status,
                    requested_by, requested_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Submitted', ?, NOW())"
            );
            $insert->execute([
                $requestId,
                $caseId,
                $requestingPartyId,
                $sourceOutcomeId,
                $sourceHearingId,
                $groundCode,
                $groundDetails,
                $actorUserId,
            ]);

            $cfaRequestId = (int) $this->conn->lastInsertId();
            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'CFA request submitted for review.',
                'data' => [
                    'cfa_request_id' => $cfaRequestId,
                    'case_id' => $caseId,
                    'ground_code' => $groundCode,
                    'request_status' => 'Submitted',
                ],
                'idempotent' => false,
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return $this->fail($e->getMessage());
        }
    }

    public function review(array $input, int $actorUserId): array
    {
        $requestId = $this->positiveInt($input['cfa_request_id'] ?? null);
        $decision = strtoupper(trim((string) ($input['decision'] ?? '')));
        $reviewNotes = $this->nullableText($input['review_notes'] ?? null, 4000);

        if (!$requestId) {
            return $this->fail('A valid cfa_request_id is required.');
        }
        if (!in_array($decision, ['APPROVE', 'RETURN', 'REJECT'], true)) {
            return $this->fail('decision must be APPROVE, RETURN, or REJECT.');
        }
        if (in_array($decision, ['RETURN', 'REJECT'], true) && $reviewNotes === null) {
            return $this->fail('review_notes are required for RETURN or REJECT.');
        }

        try {
            $this->conn->beginTransaction();

            $request = $this->lockRequest($requestId);
            if (!$request) {
                throw new RuntimeException('CFA request not found.');
            }
            if (!in_array($request['request_status'], ['Submitted', 'Under Review', 'Returned'], true)) {
                throw new RuntimeException('This CFA request is not reviewable.');
            }

            $case = $this->lockCase((int) $request['case_id']);
            if (!$case || $case['case_status'] !== 'CFA Review') {
                throw new RuntimeException('The case must remain in CFA Review.');
            }

            $status = match ($decision) {
                'APPROVE' => 'Approved',
                'RETURN' => 'Returned',
                'REJECT' => 'Rejected',
            };

            $update = $this->conn->prepare(
                'UPDATE cfa_requests
                 SET request_status = ?, reviewed_by = ?, reviewed_at = NOW(),
                     review_decision = ?, review_notes = ?,
                     approved_at = CASE WHEN ? = "APPROVE" THEN NOW() ELSE NULL END,
                     returned_at = CASE WHEN ? = "RETURN" THEN NOW() ELSE returned_at END,
                     rejected_at = CASE WHEN ? = "REJECT" THEN NOW() ELSE rejected_at END
                 WHERE cfa_request_id = ?'
            );
            $update->execute([
                $status,
                $actorUserId,
                $decision,
                $reviewNotes,
                $decision,
                $decision,
                $decision,
                $requestId,
            ]);

            if ($decision === 'RETURN') {
                $caseUpdate = $this->conn->prepare(
                    "UPDATE cases SET case_status = 'Conciliation', updated_at = NOW()
                     WHERE case_id = ? AND case_status = 'CFA Review'"
                );
                $caseUpdate->execute([(int) $request['case_id']]);

                $this->insertHistory(
                    (int) $request['case_id'],
                    'RETURN_TO_CONCILIATION_RECORD',
                    'CFA Review',
                    'Conciliation',
                    'CFA_RECORD_DEFICIENCY',
                    $reviewNotes,
                    'cfa_request',
                    $requestId,
                    $actorUserId
                );
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'CFA review decision recorded.',
                'data' => [
                    'cfa_request_id' => $requestId,
                    'decision' => $decision,
                    'request_status' => $status,
                ],
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return $this->fail($e->getMessage());
        }
    }

    public function issue(array $input, int $actorUserId): array
    {
        $cfaRequestId = $this->positiveInt($input['cfa_request_id'] ?? null);
        $documentId = $this->positiveInt($input['document_id'] ?? null);
        $issuerUserId = $this->positiveInt($input['issuer_user_id'] ?? null);
        $attestingUserId = $this->positiveInt($input['attesting_user_id'] ?? null);
        $formCode = strtoupper(trim((string) ($input['form_code'] ?? '')));
        $certificateNumber = trim((string) ($input['certificate_number'] ?? ''));

        if (!$cfaRequestId || !$documentId || !$issuerUserId || !$attestingUserId) {
            return $this->fail('cfa_request_id, document_id, issuer_user_id, and attesting_user_id are required.');
        }
        if (!in_array($formCode, ['KP FORM 20', 'KP FORM 21', 'KP FORM 22'], true)) {
            return $this->fail('form_code must be KP FORM 20, KP FORM 21, or KP FORM 22.');
        }
        if ($certificateNumber === '') {
            return $this->fail('certificate_number is required.');
        }

        try {
            $this->conn->beginTransaction();

            $request = $this->lockRequest($cfaRequestId);
            if (!$request || $request['request_status'] !== 'Approved') {
                throw new RuntimeException('An approved CFA request is required.');
            }

            $caseId = (int) $request['case_id'];
            $case = $this->lockCase($caseId);
            if (!$case || $case['case_status'] !== 'CFA Review') {
                throw new RuntimeException('The case must be in CFA Review.');
            }

            $this->requireCaseDocument($caseId, $documentId);
            $this->requireActiveUser($issuerUserId);
            $this->requireActiveUser($attestingUserId);
            $this->requireNoCertificate($cfaRequestId);

            $verificationCode = $this->uuidV4();
            $insert = $this->conn->prepare(
                "INSERT INTO cfa_certificates (
                    cfa_request_id, case_id, certificate_number, form_code,
                    legal_ground_code, issuer_user_id, attesting_user_id,
                    issued_at, document_id, verification_code, release_status,
                    created_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 'Not Released', NOW())"
            );
            $insert->execute([
                $cfaRequestId,
                $caseId,
                mb_substr($certificateNumber, 0, 80),
                $formCode,
                $request['ground_code'],
                $issuerUserId,
                $attestingUserId,
                $documentId,
                $verificationCode,
            ]);
            $certificateId = (int) $this->conn->lastInsertId();

            $requestUpdate = $this->conn->prepare(
                "UPDATE cfa_requests SET request_status = 'Issued' WHERE cfa_request_id = ?"
            );
            $requestUpdate->execute([$cfaRequestId]);

            $caseUpdate = $this->conn->prepare(
                "UPDATE cases SET case_status = 'CFA Issued', updated_at = NOW()
                 WHERE case_id = ? AND case_status = 'CFA Review'"
            );
            $caseUpdate->execute([$caseId]);
            if ($caseUpdate->rowCount() !== 1) {
                throw new RuntimeException('The case stage changed before issuance completed.');
            }

            $this->insertHistory(
                $caseId,
                'APPROVE_AND_ISSUE_CFA',
                'CFA Review',
                'CFA Issued',
                (string) $request['ground_code'],
                null,
                'cfa_request',
                $cfaRequestId,
                $actorUserId
            );

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'CFA issued successfully.',
                'data' => [
                    'certificate_id' => $certificateId,
                    'cfa_request_id' => $cfaRequestId,
                    'case_id' => $caseId,
                    'certificate_number' => $certificateNumber,
                    'verification_code' => $verificationCode,
                    'new_stage' => 'CFA Issued',
                ],
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return $this->fail($e->getMessage());
        }
    }

    public function release(array $input, int $actorUserId): array
    {
        $certificateId = $this->positiveInt($input['certificate_id'] ?? null);
        $partyId = $this->positiveInt($input['released_to_party_id'] ?? null);
        $method = trim((string) ($input['release_method'] ?? ''));

        if (!$certificateId || !$partyId || $method === '') {
            return $this->fail('certificate_id, released_to_party_id, and release_method are required.');
        }

        try {
            $this->conn->beginTransaction();
            $stmt = $this->conn->prepare(
                'SELECT certificate_id, case_id, release_status, voided_at
                 FROM cfa_certificates WHERE certificate_id = ? FOR UPDATE'
            );
            $stmt->execute([$certificateId]);
            $certificate = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$certificate || $certificate['voided_at'] !== null) {
                throw new RuntimeException('Active certificate not found.');
            }
            if ($certificate['release_status'] === 'Released') {
                throw new RuntimeException('The certificate has already been released.');
            }

            $this->requireCaseParty((int) $certificate['case_id'], $partyId);

            $update = $this->conn->prepare(
                "UPDATE cfa_certificates
                 SET release_status = 'Released', released_to_party_id = ?,
                     released_at = NOW(), release_method = ?
                 WHERE certificate_id = ?"
            );
            $update->execute([$partyId, mb_substr($method, 0, 50), $certificateId]);
            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'CFA release recorded.',
                'data' => [
                    'certificate_id' => $certificateId,
                    'release_status' => 'Released',
                    'recorded_by' => $actorUserId,
                ],
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return $this->fail($e->getMessage());
        }
    }

    private function allowedGrounds(): array
    {
        return [self::GROUND_NO_SETTLEMENT, self::GROUND_NO_CONFRONTATION, self::GROUND_REPUDIATED_SETTLEMENT];
    }

    private function validateGroundAgainstOutcome(string $ground, string $outcome): void
    {
        $valid = ($ground === self::GROUND_NO_SETTLEMENT && $outcome === 'UNRESOLVED')
            || ($ground === self::GROUND_NO_CONFRONTATION && $outcome === 'NO_CONFRONTATION_NO_FAULT_COMPLAINANT')
            || $ground === self::GROUND_REPUDIATED_SETTLEMENT;
        if (!$valid) {
            throw new RuntimeException('The CFA ground does not match the finalized Conciliation outcome.');
        }
    }

    private function lockEligibleOutcome(int $caseId, int $outcomeId, int $hearingId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT outcome_id, outcome_code
             FROM stage_outcomes
             WHERE outcome_id = ? AND case_id = ? AND hearing_id = ?
               AND stage_code = 'Conciliation' AND is_void = 0
             FOR UPDATE"
        );
        $stmt->execute([$outcomeId, $caseId, $hearingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function requireNoActiveRequest(int $caseId): void
    {
        $stmt = $this->conn->prepare(
            "SELECT cfa_request_id FROM cfa_requests
             WHERE case_id = ? AND request_status IN ('Submitted', 'Under Review', 'Approved', 'Issued')
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$caseId]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('An active CFA request already exists for this case.');
        }
    }

    private function lockRequest(int $requestId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT cfa_request_id, case_id, ground_code, request_status
             FROM cfa_requests WHERE cfa_request_id = ? FOR UPDATE'
        );
        $stmt->execute([$requestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function lockCase(int $caseId): ?array
    {
        $stmt = $this->conn->prepare('SELECT case_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
        $stmt->execute([$caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function requireNoCertificate(int $requestId): void
    {
        $stmt = $this->conn->prepare(
            'SELECT certificate_id FROM cfa_certificates WHERE cfa_request_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$requestId]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('A certificate already exists for this CFA request.');
        }
    }

    private function requireCaseDocument(int $caseId, int $documentId): void
    {
        $stmt = $this->conn->prepare(
            'SELECT 1 FROM generated_documents WHERE document_id = ? AND case_id = ? LIMIT 1'
        );
        $stmt->execute([$documentId, $caseId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('The CFA document does not belong to this case.');
        }
    }

    private function requireActiveUser(int $userId): void
    {
        $stmt = $this->conn->prepare("SELECT 1 FROM users WHERE user_id = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Issuer and attesting users must be active.');
        }
    }

    private function requireCaseParty(int $caseId, int $partyId): void
    {
        $stmt = $this->conn->prepare(
            'SELECT 1 FROM case_parties WHERE case_id = ? AND party_id = ? LIMIT 1'
        );
        $stmt->execute([$caseId, $partyId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('The release recipient must be a party in this case.');
        }
    }

    private function insertHistory(
        int $caseId,
        string $action,
        string $previous,
        string $next,
        ?string $reason,
        ?string $notes,
        string $sourceType,
        int $sourceId,
        int $actorUserId
    ): void {
        $stmt = $this->conn->prepare(
            'INSERT INTO case_stage_history (
                case_id, request_id, action_code, previous_stage, new_stage,
                reason_code, reason_notes, source_type, source_id,
                actor_user_id, created_at
             ) VALUES (?, UUID(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $caseId, $action, $previous, $next, $reason, $notes,
            $sourceType, $sourceId, $actorUserId,
        ]);
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value ? (int) $value : null;
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
