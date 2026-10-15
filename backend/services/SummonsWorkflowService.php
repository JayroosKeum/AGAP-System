<?php

declare(strict_types=1);

final class SummonsWorkflowService
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_FOR_SERVICE = 'For Service';
    public const STATUS_SERVED_PERSONAL = 'Served Personal';
    public const STATUS_SERVED_SUBSTITUTED = 'Served Substituted';
    public const STATUS_REFUSED = 'Refused';
    public const STATUS_NOT_SERVED = 'Not Served';

    private const COMPLETED_SERVICE_STATUSES = [
        self::STATUS_SERVED_PERSONAL,
        self::STATUS_SERVED_SUBSTITUTED,
        self::STATUS_REFUSED,
        'Served',
    ];

    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function readiness(int $caseId): array
    {
        $case = $this->fetchCase($caseId);
        if (!$case) {
            return $this->fail('Case not found.');
        }

        $respondents = $this->fetchRequiredRespondents($caseId);
        $deliveries = $this->fetchActiveRespondentDeliveries($caseId);
        $documents = $this->fetchCurrentSummonsDocuments($caseId);

        $latestByParty = [];
        foreach ($deliveries as $delivery) {
            $partyId = (int) ($delivery['party_id'] ?? 0);
            if ($partyId < 1) {
                continue;
            }
            if (!isset($latestByParty[$partyId])) {
                $latestByParty[$partyId] = $delivery;
            }
        }

        $partyResults = [];
        $ready = !empty($respondents);

        foreach ($respondents as $respondent) {
            $partyId = (int) $respondent['party_id'];
            $delivery = $latestByParty[$partyId] ?? null;
            $status = $delivery['delivery_status'] ?? null;
            $serviceComplete = $status !== null && in_array($status, self::COMPLETED_SERVICE_STATUSES, true);

            if (!$serviceComplete) {
                $ready = false;
            }

            $partyResults[] = [
                'party_id' => $partyId,
                'party_name' => $respondent['party_name'],
                'latest_attempt_number' => $delivery ? (int) $delivery['attempt_number'] : 0,
                'delivery_status' => $status,
                'service_complete' => $serviceComplete,
                'delivery_id' => $delivery ? (int) $delivery['delivery_id'] : null,
                'document_id' => $delivery && $delivery['document_id'] !== null
                    ? (int) $delivery['document_id']
                    : null,
            ];
        }

        return [
            'success' => true,
            'data' => [
                'case_id' => $caseId,
                'case_status' => $case['case_status'],
                'respondent_count' => count($respondents),
                'summons_document_count' => count($documents),
                'mediation_ready' => $ready,
                'parties' => $partyResults,
            ],
        ];
    }

    public function startNextAttempt(array $input, int $actorUserId): array
    {
        $caseId = $this->positiveInt($input['case_id'] ?? null);
        $partyId = $this->positiveInt($input['party_id'] ?? null);
        $documentId = $this->positiveInt($input['document_id'] ?? null);
        $requestId = strtolower(trim((string) ($input['request_id'] ?? '')));

        if (!$caseId || !$partyId || !$documentId) {
            return $this->fail('case_id, party_id, and document_id are required.');
        }
        if (!$this->isUuid($requestId)) {
            return $this->fail('A valid UUID request_id is required.');
        }

        try {
            $this->conn->beginTransaction();

            $existing = $this->conn->prepare(
                'SELECT delivery_id, case_id, party_id, attempt_number, delivery_status
                 FROM summon_deliveries
                 WHERE request_id = ?
                 LIMIT 1'
            );
            $existing->execute([$requestId]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingRow) {
                $this->conn->commit();
                return [
                    'success' => true,
                    'message' => 'Summons attempt was already created.',
                    'data' => $existingRow,
                    'idempotent' => true,
                ];
            }

            $case = $this->lockCase($caseId);
            if (!$case) {
                throw new RuntimeException('Case not found.');
            }
            if (!in_array($case['case_status'], ['Docketed', 'Summons'], true)) {
                throw new RuntimeException('A summons attempt can only be created for a Docketed or Summons case.');
            }

            $this->requireRespondent($caseId, $partyId);
            $this->requireSummonsDocument($caseId, $documentId);

            $latest = $this->latestDeliveryForUpdate($caseId, $partyId);
            if ($latest && !$this->attemptIsResolved($latest)) {
                throw new RuntimeException('Record the current delivery attempt before creating the next attempt.');
            }
            if ($latest && in_array($latest['delivery_status'], self::COMPLETED_SERVICE_STATUSES, true)) {
                throw new RuntimeException('Service is already complete for this respondent.');
            }

            $nextAttempt = $latest ? ((int) $latest['attempt_number'] + 1) : 1;

            $insert = $this->conn->prepare(
                "INSERT INTO summon_deliveries (
                    request_id,
                    case_id,
                    party_id,
                    party_type,
                    document_id,
                    attempt_number,
                    delivery_status,
                    recorded_by,
                    recorded_at,
                    is_void
                 ) VALUES (?, ?, ?, 'Respondent', ?, ?, ?, ?, NOW(), 0)"
            );
            $insert->execute([
                $requestId,
                $caseId,
                $partyId,
                $documentId,
                $nextAttempt,
                self::STATUS_FOR_SERVICE,
                $actorUserId,
            ]);

            $deliveryId = (int) $this->conn->lastInsertId();
            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Next summons attempt created.',
                'data' => [
                    'delivery_id' => $deliveryId,
                    'case_id' => $caseId,
                    'party_id' => $partyId,
                    'document_id' => $documentId,
                    'attempt_number' => $nextAttempt,
                    'delivery_status' => self::STATUS_FOR_SERVICE,
                    'request_id' => $requestId,
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

    public function recordAttempt(array $input, int $actorUserId): array
    {
        $deliveryId = $this->positiveInt($input['delivery_id'] ?? null);
        $status = trim((string) ($input['delivery_status'] ?? ''));
        $attemptedAt = trim((string) ($input['attempted_at'] ?? ''));
        $servedAt = trim((string) ($input['served_at'] ?? ''));
        $unservedReason = trim((string) ($input['unserved_reason'] ?? ''));

        $allowed = [
            self::STATUS_SERVED_PERSONAL,
            self::STATUS_SERVED_SUBSTITUTED,
            self::STATUS_REFUSED,
            self::STATUS_NOT_SERVED,
        ];

        if (!$deliveryId) {
            return $this->fail('A valid delivery_id is required.');
        }
        if (!in_array($status, $allowed, true)) {
            return $this->fail('Invalid delivery_status.');
        }
        if (!$this->validDateTime($attemptedAt)) {
            return $this->fail('A valid attempted_at value is required.');
        }
        if (in_array($status, self::COMPLETED_SERVICE_STATUSES, true) && !$this->validDateTime($servedAt)) {
            return $this->fail('A valid served_at value is required for completed service.');
        }
        if ($status === self::STATUS_NOT_SERVED && $unservedReason === '') {
            return $this->fail('unserved_reason is required when service is not completed.');
        }

        try {
            $this->conn->beginTransaction();

            $deliveryStmt = $this->conn->prepare(
                'SELECT delivery_id, delivery_status, is_void
                 FROM summon_deliveries
                 WHERE delivery_id = ?
                 FOR UPDATE'
            );
            $deliveryStmt->execute([$deliveryId]);
            $delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC);

            if (!$delivery || (int) $delivery['is_void'] === 1) {
                throw new RuntimeException('Active summons delivery not found.');
            }
            if ($this->attemptIsResolved($delivery)) {
                throw new RuntimeException('This summons attempt has already been finalized.');
            }

            $update = $this->conn->prepare(
                'UPDATE summon_deliveries
                 SET delivery_status = ?,
                     attempted_at = ?,
                     served_at = ?,
                     unserved_reason = ?,
                     recorded_by = ?,
                     recorded_at = NOW()
                 WHERE delivery_id = ? AND is_void = 0'
            );
            $update->execute([
                $status,
                $attemptedAt,
                $servedAt !== '' ? $servedAt : null,
                $unservedReason !== '' ? mb_substr($unservedReason, 0, 1000) : null,
                $actorUserId,
                $deliveryId,
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Summons delivery attempt recorded.',
                'data' => [
                    'delivery_id' => $deliveryId,
                    'delivery_status' => $status,
                ],
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return $this->fail($e->getMessage());
        }
    }

    private function fetchCase(int $caseId): ?array
    {
        $stmt = $this->conn->prepare('SELECT case_id, case_status FROM cases WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function lockCase(int $caseId): ?array
    {
        $stmt = $this->conn->prepare('SELECT case_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchRequiredRespondents(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT
                cp.party_id,
                TRIM(CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) AS party_name
             FROM case_parties cp
             INNER JOIN profiles p ON p.profile_id = cp.party_id
             WHERE cp.case_id = ?
               AND cp.party_type = 'Respondent'
             ORDER BY cp.party_id ASC"
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchActiveRespondentDeliveries(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT
                delivery_id,
                party_id,
                document_id,
                attempt_number,
                delivery_status,
                attempted_at,
                served_at
             FROM summon_deliveries
             WHERE case_id = ?
               AND party_type = 'Respondent'
               AND is_void = 0
             ORDER BY party_id ASC, attempt_number DESC, delivery_id DESC"
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchCurrentSummonsDocuments(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT gd.document_id
             FROM generated_documents gd
             INNER JOIN document_templates dt ON dt.template_id = gd.template_id
             WHERE gd.case_id = ?
               AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')
             ORDER BY gd.generated_at DESC, gd.document_id DESC"
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requireRespondent(int $caseId, int $partyId): void
    {
        $stmt = $this->conn->prepare(
            "SELECT 1
             FROM case_parties
             WHERE case_id = ? AND party_id = ? AND party_type = 'Respondent'
             LIMIT 1"
        );
        $stmt->execute([$caseId, $partyId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('The selected party is not a respondent in this case.');
        }
    }

    private function requireSummonsDocument(int $caseId, int $documentId): void
    {
        $stmt = $this->conn->prepare(
            "SELECT 1
             FROM generated_documents gd
             INNER JOIN document_templates dt ON dt.template_id = gd.template_id
             WHERE gd.document_id = ?
               AND gd.case_id = ?
               AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')
             LIMIT 1"
        );
        $stmt->execute([$documentId, $caseId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('A valid KP Form 9 summons document is required.');
        }
    }

    private function latestDeliveryForUpdate(int $caseId, int $partyId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT delivery_id, attempt_number, delivery_status, attempted_at, served_at, unserved_reason
             FROM summon_deliveries
             WHERE case_id = ?
               AND party_id = ?
               AND party_type = 'Respondent'
               AND is_void = 0
             ORDER BY attempt_number DESC, delivery_id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$caseId, $partyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function attemptIsResolved(array $delivery): bool
    {
        $status = trim((string) ($delivery['delivery_status'] ?? ''));
        return in_array($status, array_merge(self::COMPLETED_SERVICE_STATUSES, [self::STATUS_NOT_SERVED]), true);
    }

    private function positiveInt(mixed $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $validated ? (int) $validated : null;
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }

    private function validDateTime(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false;
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
