<?php

declare(strict_types=1);

final class MediationWorkflowService
{
    public const OUTCOME_SETTLED = 'SETTLED';
    public const OUTCOME_UNRESOLVED = 'UNRESOLVED';
    public const OUTCOME_WITHDRAWN = 'WITHDRAWN';
    public const OUTCOME_DISMISSED = 'DISMISSED';
    public const OUTCOME_ARBITRATION = 'ARBITRATION_AGREEMENT';

    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function overview(int $caseId): array
    {
        $caseStmt = $this->conn->prepare(
            'SELECT case_id, case_number, case_status, mediation_start_date, mediation_deadline_date,
                    is_paused, pause_reason, pause_notes
             FROM cases
             WHERE case_id = ?
             LIMIT 1'
        );
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return $this->fail('Case not found.');
        }

        $hearingsStmt = $this->conn->prepare(
            "SELECT hearing_id, hearing_date, venue, status, remarks
             FROM hearings
             WHERE case_id = ? AND hearing_type = 'Mediation'
             ORDER BY hearing_date ASC, hearing_id ASC"
        );
        $hearingsStmt->execute([$caseId]);

        $outcomeStmt = $this->conn->prepare(
            "SELECT outcome_id, hearing_id, outcome_code, reason_code, outcome_notes,
                    recorded_by, recorded_at
             FROM stage_outcomes
             WHERE case_id = ? AND stage_code = 'Mediation' AND is_void = 0
             ORDER BY recorded_at DESC, outcome_id DESC
             LIMIT 1"
        );
        $outcomeStmt->execute([$caseId]);
        $outcome = $outcomeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return [
            'success' => true,
            'data' => [
                'case' => $case,
                'hearings' => $hearingsStmt->fetchAll(PDO::FETCH_ASSOC),
                'final_outcome' => $outcome,
            ],
        ];
    }

    public function recordOutcome(array $input, int $actorUserId): array
    {
        $caseId = $this->positiveInt($input['case_id'] ?? null);
        $hearingId = $this->positiveInt($input['hearing_id'] ?? null);
        $requestId = strtolower(trim((string) ($input['request_id'] ?? '')));
        $outcomeCode = strtoupper(trim((string) ($input['outcome_code'] ?? '')));
        $reasonCode = $this->nullableText($input['reason_code'] ?? null, 80);
        $outcomeNotes = $this->nullableText($input['outcome_notes'] ?? null, 4000);
        $settlementDate = trim((string) ($input['settlement_date'] ?? ''));
        $settlementTerms = trim((string) ($input['settlement_terms'] ?? ''));
        $documentId = $this->positiveInt($input['document_id'] ?? null);

        if (!$caseId || !$hearingId) {
            return $this->fail('case_id and hearing_id are required.');
        }
        if (!$this->isUuid($requestId)) {
            return $this->fail('A valid UUID request_id is required.');
        }

        $allowed = [
            self::OUTCOME_SETTLED,
            self::OUTCOME_UNRESOLVED,
            self::OUTCOME_WITHDRAWN,
            self::OUTCOME_DISMISSED,
            self::OUTCOME_ARBITRATION,
        ];
        if (!in_array($outcomeCode, $allowed, true)) {
            return $this->fail('Invalid mediation outcome_code.');
        }
        if (in_array($outcomeCode, [self::OUTCOME_UNRESOLVED, self::OUTCOME_WITHDRAWN, self::OUTCOME_DISMISSED], true)
            && $reasonCode === null) {
            return $this->fail('reason_code is required for this outcome.');
        }
        if ($outcomeCode === self::OUTCOME_SETTLED) {
            if (!$this->validDate($settlementDate)) {
                return $this->fail('A valid settlement_date is required.');
            }
            if ($settlementTerms === '') {
                return $this->fail('settlement_terms are required.');
            }
            if (!$documentId) {
                return $this->fail('An executed settlement document_id is required.');
            }
        }

        try {
            $this->conn->beginTransaction();

            $existing = $this->conn->prepare(
                'SELECT outcome_id, case_id, hearing_id, outcome_code, recorded_at
                 FROM stage_outcomes
                 WHERE request_id = ?
                 LIMIT 1'
            );
            $existing->execute([$requestId]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingRow) {
                $this->conn->commit();
                return [
                    'success' => true,
                    'message' => 'Mediation outcome was already recorded.',
                    'data' => $existingRow,
                    'idempotent' => true,
                ];
            }

            $case = $this->lockCase($caseId);
            if (!$case) {
                throw new RuntimeException('Case not found.');
            }
            if ($case['case_status'] !== 'Mediation') {
                throw new RuntimeException('The case must be in Mediation.');
            }

            $hearing = $this->lockCompletedMediationHearing($caseId, $hearingId);
            if (!$hearing) {
                throw new RuntimeException('A completed Mediation hearing is required.');
            }

            $this->requireNoActiveOutcome($caseId);

            if ($outcomeCode === self::OUTCOME_SETTLED) {
                $this->requireCaseDocument($caseId, (int) $documentId);
            }

            $insertOutcome = $this->conn->prepare(
                "INSERT INTO stage_outcomes (
                    request_id, case_id, hearing_id, stage_code, outcome_code,
                    reason_code, outcome_notes, recorded_by, recorded_at, is_void
                 ) VALUES (?, ?, ?, 'Mediation', ?, ?, ?, ?, NOW(), 0)"
            );
            $insertOutcome->execute([
                $requestId,
                $caseId,
                $hearingId,
                $outcomeCode,
                $reasonCode,
                $outcomeNotes,
                $actorUserId,
            ]);
            $outcomeId = (int) $this->conn->lastInsertId();

            $nextStage = $this->nextStageFor($outcomeCode);
            $actionCode = $this->actionFor($outcomeCode);

            if ($outcomeCode === self::OUTCOME_SETTLED) {
                $settlement = $this->conn->prepare(
                    "INSERT INTO settlements (
                        outcome_id, case_id, hearing_id, settlement_date,
                        settlement_terms, document_id, execution_status,
                        executed_at, recorded_by, recorded_at
                     ) VALUES (?, ?, ?, ?, ?, ?, 'Executed', NOW(), ?, NOW())"
                );
                $settlement->execute([
                    $outcomeId,
                    $caseId,
                    $hearingId,
                    $settlementDate,
                    mb_substr($settlementTerms, 0, 20000),
                    $documentId,
                    $actorUserId,
                ]);
            }

            $updateCase = $this->conn->prepare(
                "UPDATE cases
                 SET case_status = ?, updated_at = NOW()
                 WHERE case_id = ? AND case_status = 'Mediation'"
            );
            $updateCase->execute([$nextStage, $caseId]);
            if ($updateCase->rowCount() !== 1) {
                throw new RuntimeException('The case stage changed before the outcome completed.');
            }

            $history = $this->conn->prepare(
                'INSERT INTO case_stage_history (
                    case_id, request_id, action_code, previous_stage, new_stage,
                    reason_code, reason_notes, source_type, source_id,
                    actor_user_id, created_at
                 ) VALUES (?, UUID(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $history->execute([
                $caseId,
                $actionCode,
                'Mediation',
                $nextStage,
                $reasonCode,
                $outcomeNotes,
                'hearing',
                $hearingId,
                $actorUserId,
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Mediation outcome recorded.',
                'data' => [
                    'outcome_id' => $outcomeId,
                    'case_id' => $caseId,
                    'hearing_id' => $hearingId,
                    'outcome_code' => $outcomeCode,
                    'new_stage' => $nextStage,
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

    private function nextStageFor(string $outcomeCode): string
    {
        return match ($outcomeCode) {
            self::OUTCOME_SETTLED => 'Settled',
            self::OUTCOME_UNRESOLVED => 'Pangkat Formation',
            self::OUTCOME_WITHDRAWN, self::OUTCOME_DISMISSED => 'Closed',
            self::OUTCOME_ARBITRATION => 'Closed',
            default => throw new RuntimeException('Unsupported outcome.'),
        };
    }

    private function actionFor(string $outcomeCode): string
    {
        return match ($outcomeCode) {
            self::OUTCOME_SETTLED => 'RECORD_MEDIATION_SETTLEMENT',
            self::OUTCOME_UNRESOLVED => 'REFER_TO_PANGKAT',
            self::OUTCOME_WITHDRAWN, self::OUTCOME_DISMISSED => 'RECORD_AUTHORIZED_CLOSURE',
            self::OUTCOME_ARBITRATION => 'RECORD_MEDIATION_ARBITRATION_AGREEMENT',
            default => throw new RuntimeException('Unsupported outcome.'),
        };
    }

    private function lockCase(int $caseId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT case_id, case_status FROM cases WHERE case_id = ? FOR UPDATE'
        );
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function lockCompletedMediationHearing(int $caseId, int $hearingId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT hearing_id, case_id, hearing_type, status
             FROM hearings
             WHERE hearing_id = ? AND case_id = ?
               AND hearing_type = 'Mediation' AND status = 'Completed'
             FOR UPDATE"
        );
        $stmt->execute([$hearingId, $caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function requireNoActiveOutcome(int $caseId): void
    {
        $stmt = $this->conn->prepare(
            "SELECT outcome_id
             FROM stage_outcomes
             WHERE case_id = ? AND stage_code = 'Mediation' AND is_void = 0
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$caseId]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('A final Mediation outcome already exists for this case.');
        }
    }

    private function requireCaseDocument(int $caseId, int $documentId): void
    {
        $stmt = $this->conn->prepare(
            'SELECT 1 FROM generated_documents WHERE document_id = ? AND case_id = ? LIMIT 1'
        );
        $stmt->execute([$documentId, $caseId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('The settlement document does not belong to this case.');
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $validated ? (int) $validated : null;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
