<?php

declare(strict_types=1);

final class ConciliationWorkflowService
{
    public const OUTCOME_SETTLED = 'SETTLED';
    public const OUTCOME_UNRESOLVED = 'UNRESOLVED';
    public const OUTCOME_NO_CONFRONTATION = 'NO_CONFRONTATION_NO_FAULT_COMPLAINANT';
    public const OUTCOME_ARBITRATION = 'ARBITRATION_AGREEMENT';

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

        $panelStmt = $this->conn->prepare(
            'SELECT pangkat_id, formation_date, convening_deadline, selection_method,
                    selection_notes, quorum_size, constituted_by, constituted_at,
                    panel_status, conflict_check_completed, conflict_check_notes
             FROM pangkat_groups
             WHERE case_id = ?
             ORDER BY pangkat_id DESC
             LIMIT 1'
        );
        $panelStmt->execute([$caseId]);
        $panel = $panelStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $members = [];
        if ($panel) {
            $memberStmt = $this->conn->prepare(
                'SELECT pm.member_id, pm.position, pm.status, pm.acceptance_date,
                        pm.conflict_declared, pm.conflict_notes,
                        TRIM(CONCAT_WS(" ", u.first_name, u.middle_name, u.last_name)) AS member_name
                 FROM pangkat_members pm
                 INNER JOIN users u ON u.user_id = pm.member_id
                 WHERE pm.pangkat_id = ?
                 ORDER BY FIELD(pm.position, "Chairman", "Chairperson", "Secretary", "Member"), pm.member_id'
            );
            $memberStmt->execute([(int) $panel['pangkat_id']]);
            $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $hearingStmt = $this->conn->prepare(
            "SELECT hearing_id, hearing_date, venue, status, remarks
             FROM hearings
             WHERE case_id = ? AND hearing_type = 'Conciliation'
             ORDER BY hearing_date ASC, hearing_id ASC"
        );
        $hearingStmt->execute([$caseId]);

        $outcomeStmt = $this->conn->prepare(
            "SELECT outcome_id, hearing_id, outcome_code, reason_code, outcome_notes,
                    recorded_by, recorded_at
             FROM stage_outcomes
             WHERE case_id = ? AND stage_code = 'Conciliation' AND is_void = 0
             ORDER BY recorded_at DESC, outcome_id DESC
             LIMIT 1"
        );
        $outcomeStmt->execute([$caseId]);

        return [
            'success' => true,
            'data' => [
                'case' => $case,
                'panel' => $panel,
                'members' => $members,
                'hearings' => $hearingStmt->fetchAll(PDO::FETCH_ASSOC),
                'final_outcome' => $outcomeStmt->fetch(PDO::FETCH_ASSOC) ?: null,
            ],
        ];
    }

    public function activatePanel(array $input, int $actorUserId): array
    {
        $caseId = $this->positiveInt($input['case_id'] ?? null);
        $pangkatId = $this->positiveInt($input['pangkat_id'] ?? null);
        $requestId = strtolower(trim((string) ($input['request_id'] ?? '')));
        $conflictNotes = $this->nullableText($input['conflict_check_notes'] ?? null, 1000);

        if (!$caseId || !$pangkatId) {
            return $this->fail('case_id and pangkat_id are required.');
        }
        if (!$this->isUuid($requestId)) {
            return $this->fail('A valid UUID request_id is required.');
        }

        try {
            $this->conn->beginTransaction();

            $case = $this->lockCase($caseId);
            if (!$case || $case['case_status'] !== 'Pangkat Formation') {
                throw new RuntimeException('The case must be in Pangkat Formation.');
            }

            $panel = $this->lockPanel($caseId, $pangkatId);
            if (!$panel) {
                throw new RuntimeException('Pangkat panel not found.');
            }

            $members = $this->activeMembers($pangkatId, true);
            $this->validatePanelMembers($members);

            $updatePanel = $this->conn->prepare(
                "UPDATE pangkat_groups
                 SET request_id = ?, constituted_by = ?, constituted_at = NOW(),
                     panel_status = 'Active', conflict_check_completed = 1,
                     conflict_check_notes = ?
                 WHERE pangkat_id = ? AND case_id = ?"
            );
            $updatePanel->execute([$requestId, $actorUserId, $conflictNotes, $pangkatId, $caseId]);

            $updateCase = $this->conn->prepare(
                "UPDATE cases SET case_status = 'Conciliation', updated_at = NOW()
                 WHERE case_id = ? AND case_status = 'Pangkat Formation'"
            );
            $updateCase->execute([$caseId]);
            if ($updateCase->rowCount() !== 1) {
                throw new RuntimeException('The case stage changed before activation completed.');
            }

            $history = $this->conn->prepare(
                "INSERT INTO case_stage_history (
                    case_id, request_id, action_code, previous_stage, new_stage,
                    reason_code, reason_notes, source_type, source_id,
                    actor_user_id, created_at
                 ) VALUES (?, UUID(), 'ACTIVATE_PANGKAT', 'Pangkat Formation', 'Conciliation',
                           'VALID_PANEL', ?, 'pangkat', ?, ?, NOW())"
            );
            $history->execute([$caseId, $conflictNotes, $pangkatId, $actorUserId]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Pangkat activated for Conciliation.',
                'data' => [
                    'case_id' => $caseId,
                    'pangkat_id' => $pangkatId,
                    'new_stage' => 'Conciliation',
                ],
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return $this->fail($e->getMessage());
        }
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
            self::OUTCOME_NO_CONFRONTATION,
            self::OUTCOME_ARBITRATION,
        ];
        if (!in_array($outcomeCode, $allowed, true)) {
            return $this->fail('Invalid Conciliation outcome_code.');
        }
        if ($outcomeCode !== self::OUTCOME_SETTLED && $reasonCode === null) {
            return $this->fail('reason_code is required for this outcome.');
        }
        if ($outcomeCode === self::OUTCOME_SETTLED) {
            if (!$this->validDate($settlementDate) || $settlementTerms === '' || !$documentId) {
                return $this->fail('settlement_date, settlement_terms, and document_id are required.');
            }
        }

        try {
            $this->conn->beginTransaction();

            $existing = $this->conn->prepare(
                'SELECT outcome_id, case_id, hearing_id, outcome_code, recorded_at
                 FROM stage_outcomes WHERE request_id = ? LIMIT 1'
            );
            $existing->execute([$requestId]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingRow) {
                $this->conn->commit();
                return [
                    'success' => true,
                    'message' => 'Conciliation outcome was already recorded.',
                    'data' => $existingRow,
                    'idempotent' => true,
                ];
            }

            $case = $this->lockCase($caseId);
            if (!$case || $case['case_status'] !== 'Conciliation') {
                throw new RuntimeException('The case must be in Conciliation.');
            }

            $panel = $this->activePanel($caseId, true);
            if (!$panel) {
                throw new RuntimeException('An active Pangkat is required.');
            }
            $this->validatePanelMembers($this->activeMembers((int) $panel['pangkat_id'], true));
            $this->requireCompletedHearing($caseId, $hearingId);
            $this->requireNoActiveOutcome($caseId);

            if ($outcomeCode === self::OUTCOME_SETTLED) {
                $this->requireCaseDocument($caseId, (int) $documentId);
            }

            $insertOutcome = $this->conn->prepare(
                "INSERT INTO stage_outcomes (
                    request_id, case_id, hearing_id, stage_code, outcome_code,
                    reason_code, outcome_notes, recorded_by, recorded_at, is_void
                 ) VALUES (?, ?, ?, 'Conciliation', ?, ?, ?, ?, NOW(), 0)"
            );
            $insertOutcome->execute([
                $requestId, $caseId, $hearingId, $outcomeCode,
                $reasonCode, $outcomeNotes, $actorUserId,
            ]);
            $outcomeId = (int) $this->conn->lastInsertId();

            $nextStage = $outcomeCode === self::OUTCOME_SETTLED ? 'Settled'
                : ($outcomeCode === self::OUTCOME_ARBITRATION ? 'Closed' : 'CFA Review');
            $actionCode = $outcomeCode === self::OUTCOME_SETTLED
                ? 'RECORD_CONCILIATION_SETTLEMENT'
                : ($outcomeCode === self::OUTCOME_ARBITRATION
                    ? 'RECORD_CONCILIATION_ARBITRATION_AGREEMENT'
                    : 'REQUEST_CFA_REVIEW');

            if ($outcomeCode === self::OUTCOME_SETTLED) {
                $settlement = $this->conn->prepare(
                    "INSERT INTO settlements (
                        outcome_id, case_id, hearing_id, settlement_date,
                        settlement_terms, document_id, execution_status,
                        executed_at, recorded_by, recorded_at
                     ) VALUES (?, ?, ?, ?, ?, ?, 'Executed', NOW(), ?, NOW())"
                );
                $settlement->execute([
                    $outcomeId, $caseId, $hearingId, $settlementDate,
                    mb_substr($settlementTerms, 0, 20000), $documentId, $actorUserId,
                ]);
            }

            $updateCase = $this->conn->prepare(
                "UPDATE cases SET case_status = ?, updated_at = NOW()
                 WHERE case_id = ? AND case_status = 'Conciliation'"
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
                $caseId, $actionCode, 'Conciliation', $nextStage,
                $reasonCode, $outcomeNotes, 'hearing', $hearingId, $actorUserId,
            ]);

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Conciliation outcome recorded.',
                'data' => [
                    'outcome_id' => $outcomeId,
                    'case_id' => $caseId,
                    'hearing_id' => $hearingId,
                    'outcome_code' => $outcomeCode,
                    'new_stage' => $nextStage,
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

    private function validatePanelMembers(array $members): void
    {
        if (count($members) !== 3) {
            throw new RuntimeException('Exactly three active Pangkat members are required.');
        }
        $ids = array_map(static fn(array $row): int => (int) $row['member_id'], $members);
        if (count(array_unique($ids)) !== 3) {
            throw new RuntimeException('Pangkat members must be distinct.');
        }
        foreach ($members as $member) {
            if ((int) ($member['conflict_declared'] ?? 0) === 1) {
                throw new RuntimeException('A member with a declared conflict cannot activate the Pangkat.');
            }
        }
        $positions = array_map(
            static fn(array $row): string => strtolower(trim((string) $row['position'])),
            $members
        );
        $hasChair = in_array('chairman', $positions, true) || in_array('chairperson', $positions, true);
        if (!$hasChair || !in_array('secretary', $positions, true) || !in_array('member', $positions, true)) {
            throw new RuntimeException('Chairperson, Secretary, and Member positions are required.');
        }
    }

    private function lockCase(int $caseId): ?array
    {
        $stmt = $this->conn->prepare('SELECT case_id, case_status FROM cases WHERE case_id = ? FOR UPDATE');
        $stmt->execute([$caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function lockPanel(int $caseId, int $pangkatId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT pangkat_id, case_id, panel_status FROM pangkat_groups
             WHERE pangkat_id = ? AND case_id = ? FOR UPDATE'
        );
        $stmt->execute([$pangkatId, $caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function activePanel(int $caseId, bool $lock = false): ?array
    {
        $sql = "SELECT pangkat_id, case_id FROM pangkat_groups
                WHERE case_id = ? AND panel_status = 'Active'
                ORDER BY pangkat_id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function activeMembers(int $pangkatId, bool $lock = false): array
    {
        $sql = "SELECT member_id, position, status, conflict_declared
                FROM pangkat_members
                WHERE pangkat_id = ? AND status = 'Active'
                ORDER BY member_id" . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$pangkatId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function requireCompletedHearing(int $caseId, int $hearingId): void
    {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM hearings
             WHERE hearing_id = ? AND case_id = ?
               AND hearing_type = 'Conciliation' AND status = 'Completed'
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$hearingId, $caseId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('A completed Conciliation hearing is required.');
        }
    }

    private function requireNoActiveOutcome(int $caseId): void
    {
        $stmt = $this->conn->prepare(
            "SELECT outcome_id FROM stage_outcomes
             WHERE case_id = ? AND stage_code = 'Conciliation' AND is_void = 0
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$caseId]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('A final Conciliation outcome already exists.');
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
