<?php

declare(strict_types=1);

final class CaseStageService
{
    public const DOCKETED = 'Docketed';
    public const SUMMONS = 'Summons';
    public const MEDIATION = 'Mediation';
    public const PANGKAT_FORMATION = 'Pangkat Formation';
    public const CONCILIATION = 'Conciliation';
    public const CFA_REVIEW = 'CFA Review';
    public const SETTLED = 'Settled';
    public const CFA_ISSUED = 'CFA Issued';
    public const CLOSED = 'Closed';
    public const ARCHIVED = 'Archived';

    private PDO $conn;

    private const COMMANDS = [
        'ISSUE_INITIAL_NOTICES' => [self::DOCKETED, self::SUMMONS],
        'CONFIRM_MEDIATION_READINESS' => [self::SUMMONS, self::MEDIATION],
        'RECORD_MEDIATION_SETTLEMENT' => [self::MEDIATION, self::SETTLED],
        'REFER_TO_PANGKAT' => [self::MEDIATION, self::PANGKAT_FORMATION],
        'RECORD_AUTHORIZED_CLOSURE' => [self::MEDIATION, self::CLOSED],
        'ACTIVATE_PANGKAT' => [self::PANGKAT_FORMATION, self::CONCILIATION],
        'RECORD_CONCILIATION_SETTLEMENT' => [self::CONCILIATION, self::SETTLED],
        'REQUEST_CFA_REVIEW' => [self::CONCILIATION, self::CFA_REVIEW],
        'RETURN_TO_CONCILIATION_RECORD' => [self::CFA_REVIEW, self::CONCILIATION],
        'APPROVE_AND_ISSUE_CFA' => [self::CFA_REVIEW, self::CFA_ISSUED],
        'FINALIZE_SETTLEMENT_CASE' => [self::SETTLED, self::CLOSED],
        'ARCHIVE_CFA_CASE' => [self::CFA_ISSUED, self::ARCHIVED],
        'ARCHIVE_CLOSED_CASE' => [self::CLOSED, self::ARCHIVED],
    ];

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function transition(array $input, int $actorUserId): array
    {
        $caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $command = strtoupper(trim((string) ($input['command'] ?? '')));
        $requestId = strtolower(trim((string) ($input['request_id'] ?? '')));
        $reasonCode = $this->nullableString($input['reason_code'] ?? null, 80);
        $reasonNotes = $this->nullableString($input['reason_notes'] ?? null, 2000);
        $sourceType = $this->nullableString($input['source_type'] ?? null, 50);
        $sourceId = filter_var($input['source_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (!$caseId) {
            return $this->fail('A valid case_id is required.');
        }
        if (!isset(self::COMMANDS[$command])) {
            return $this->fail('Invalid workflow command.');
        }
        if (!$this->isUuid($requestId)) {
            return $this->fail('A valid UUID request_id is required.');
        }
        if ($actorUserId < 1) {
            return $this->fail('A valid authenticated user is required.');
        }

        [$requiredStage, $nextStage] = self::COMMANDS[$command];

        try {
            $this->conn->beginTransaction();

            $existing = $this->conn->prepare(
                'SELECT stage_history_id, case_id, previous_stage, new_stage, action_code, created_at
                 FROM case_stage_history
                 WHERE request_id = ?
                 LIMIT 1'
            );
            $existing->execute([$requestId]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

            if ($existingRow) {
                $this->conn->commit();
                return [
                    'success' => true,
                    'message' => 'Workflow command was already completed.',
                    'data' => $existingRow,
                    'idempotent' => true,
                ];
            }

            $caseStmt = $this->conn->prepare(
                'SELECT case_id, case_status
                 FROM cases
                 WHERE case_id = ?
                 FOR UPDATE'
            );
            $caseStmt->execute([$caseId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                $this->conn->rollBack();
                return $this->fail('Case not found.');
            }

            $currentStage = trim((string) ($case['case_status'] ?? ''));
            if ($currentStage !== $requiredStage) {
                $this->conn->rollBack();
                return $this->fail(
                    sprintf(
                        'Command %s requires stage %s. Current stage is %s.',
                        $command,
                        $requiredStage,
                        $currentStage !== '' ? $currentStage : 'Unknown'
                    )
                );
            }

            $this->validateCommandPrerequisites($caseId, $command, $sourceType, $sourceId ?: null);

            $update = $this->conn->prepare(
                'UPDATE cases
                 SET case_status = ?, updated_at = NOW()
                 WHERE case_id = ? AND case_status = ?'
            );
            $update->execute([$nextStage, $caseId, $requiredStage]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The case stage changed before the command completed.');
            }

            $history = $this->conn->prepare(
                'INSERT INTO case_stage_history (
                    case_id,
                    request_id,
                    action_code,
                    previous_stage,
                    new_stage,
                    reason_code,
                    reason_notes,
                    source_type,
                    source_id,
                    actor_user_id
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $history->execute([
                $caseId,
                $requestId,
                $command,
                $requiredStage,
                $nextStage,
                $reasonCode,
                $reasonNotes,
                $sourceType,
                $sourceId ?: null,
                $actorUserId,
            ]);

            $historyId = (int) $this->conn->lastInsertId();
            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Case stage updated successfully.',
                'data' => [
                    'stage_history_id' => $historyId,
                    'case_id' => (int) $caseId,
                    'command' => $command,
                    'previous_stage' => $requiredStage,
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

    public function history(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT
                stage_history_id,
                case_id,
                request_id,
                action_code,
                previous_stage,
                new_stage,
                reason_code,
                reason_notes,
                source_type,
                source_id,
                actor_user_id,
                created_at
             FROM case_stage_history
             WHERE case_id = ?
             ORDER BY created_at ASC, stage_history_id ASC'
        );
        $stmt->execute([$caseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function validateCommandPrerequisites(
        int $caseId,
        string $command,
        ?string $sourceType,
        ?int $sourceId
    ): void {
        if ($command === 'ISSUE_INITIAL_NOTICES') {
            $this->requireSource($sourceType, $sourceId, 'hearing');
            $this->requireRecord(
                'SELECT 1 FROM hearings WHERE hearing_id = ? AND case_id = ? AND hearing_type = ? LIMIT 1',
                [$sourceId, $caseId, 'Mediation'],
                'A valid first mediation hearing is required.'
            );
            return;
        }

        if ($command === 'CONFIRM_MEDIATION_READINESS') {
            $this->requireRecord(
                "SELECT 1
                 FROM summon_deliveries
                 WHERE case_id = ?
                   AND party_type = 'Respondent'
                   AND delivery_status IN ('Served Personal', 'Served Substituted', 'Refused', 'Served')
                 LIMIT 1",
                [$caseId],
                'Completed respondent service is required before mediation.'
            );
            return;
        }

        if ($command === 'REFER_TO_PANGKAT') {
            $this->requireSource($sourceType, $sourceId, 'hearing');
            $this->requireRecord(
                "SELECT 1
                 FROM hearings
                 WHERE hearing_id = ?
                   AND case_id = ?
                   AND hearing_type = 'Mediation'
                   AND status = 'Completed'
                 LIMIT 1",
                [$sourceId, $caseId],
                'A completed mediation hearing is required.'
            );
            return;
        }

        if ($command === 'ACTIVATE_PANGKAT') {
            $this->requireRecord(
                "SELECT 1
                 FROM pangkat_groups pg
                 WHERE pg.case_id = ?
                   AND (
                       SELECT COUNT(DISTINCT pm.member_id)
                       FROM pangkat_members pm
                       WHERE pm.pangkat_id = pg.pangkat_id
                         AND pm.status = 'Active'
                   ) = 3
                 LIMIT 1",
                [$caseId],
                'A Pangkat with exactly three distinct active members is required.'
            );
            return;
        }

        if ($command === 'REQUEST_CFA_REVIEW') {
            $this->requireSource($sourceType, $sourceId, 'hearing');
            $this->requireRecord(
                "SELECT 1
                 FROM hearings
                 WHERE hearing_id = ?
                   AND case_id = ?
                   AND hearing_type = 'Conciliation'
                   AND status = 'Completed'
                 LIMIT 1",
                [$sourceId, $caseId],
                'A completed conciliation hearing is required.'
            );
            if ($this->recordExists(
                'SELECT 1 FROM cases WHERE case_id = ? AND case_status = ? LIMIT 1',
                [$caseId, self::SETTLED]
            )) {
                throw new RuntimeException('A settled case cannot proceed to CFA review.');
            }
            return;
        }

        if ($command === 'APPROVE_AND_ISSUE_CFA') {
            $this->requireSource($sourceType, $sourceId, 'cfa_request');
        }
    }

    private function requireSource(
        ?string $actualType,
        ?int $sourceId,
        string $requiredType
    ): void {
        if ($actualType !== $requiredType || !$sourceId) {
            throw new RuntimeException(
                sprintf('source_type %s and a valid source_id are required.', $requiredType)
            );
        }
    }

    private function requireRecord(string $sql, array $params, string $message): void
    {
        if (!$this->recordExists($sql, $params)) {
            throw new RuntimeException($message);
        }
    }

    private function recordExists(string $sql, array $params): bool
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        return mb_substr($text, 0, $maxLength);
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }

    private function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
