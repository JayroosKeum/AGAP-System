<?php

declare(strict_types=1);

final class WorkflowValidationService
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function run(?int $caseId = null): array
    {
        $results = [];

        $results[] = $this->testRequiredTables();
        $results[] = $this->testRequiredColumns();
        $results[] = $this->testStageHistoryCoverage($caseId);
        $results[] = $this->testStageConsistency($caseId);
        $results[] = $this->testOutcomeUniqueness($caseId);
        $results[] = $this->testSummonsSequence($caseId);
        $results[] = $this->testPangkatComposition($caseId);
        $results[] = $this->testCfaIssuance($caseId);
        $results[] = $this->testOrphanWorkflowRecords($caseId);

        $passed = count(array_filter($results, static fn(array $test): bool => $test['status'] === 'PASS'));
        $failed = count(array_filter($results, static fn(array $test): bool => $test['status'] === 'FAIL'));
        $warnings = count(array_filter($results, static fn(array $test): bool => $test['status'] === 'WARNING'));

        return [
            'success' => $failed === 0,
            'data' => [
                'database' => (string) $this->conn->query('SELECT DATABASE()')->fetchColumn(),
                'case_id' => $caseId,
                'summary' => [
                    'total' => count($results),
                    'passed' => $passed,
                    'failed' => $failed,
                    'warnings' => $warnings,
                ],
                'tests' => $results,
            ],
        ];
    }

    private function testRequiredTables(): array
    {
        $required = [
            'cases',
            'case_stage_history',
            'hearings',
            'summon_deliveries',
            'stage_outcomes',
            'settlements',
            'pangkat_groups',
            'pangkat_members',
            'cfa_requests',
            'cfa_certificates',
            'workflow_integrity_events',
        ];

        $placeholders = implode(',', array_fill(0, count($required), '?'));
        $stmt = $this->conn->prepare(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)"
        );
        $stmt->execute($required);
        $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($required, $found));

        return $this->result(
            'REQUIRED_TABLES',
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === [] ? 'All required workflow tables exist.' : 'Missing tables: ' . implode(', ', $missing),
            count($missing)
        );
    }

    private function testRequiredColumns(): array
    {
        $required = [
            'cases' => ['case_id', 'case_status'],
            'case_stage_history' => ['case_id', 'request_id', 'action_code', 'previous_stage', 'new_stage'],
            'summon_deliveries' => ['case_id', 'party_id', 'attempt_number', 'delivery_status', 'is_void'],
            'stage_outcomes' => ['case_id', 'hearing_id', 'stage_code', 'outcome_code', 'is_void'],
            'pangkat_groups' => ['case_id', 'panel_status', 'conflict_check_completed'],
            'pangkat_members' => ['pangkat_id', 'member_id', 'position', 'status', 'conflict_declared'],
            'cfa_requests' => ['case_id', 'source_outcome_id', 'ground_code', 'request_status'],
            'cfa_certificates' => ['case_id', 'cfa_request_id', 'certificate_number', 'verification_code'],
        ];

        $missing = [];
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?'
        );

        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                $stmt->execute([$table, $column]);
                if ((int) $stmt->fetchColumn() !== 1) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }

        return $this->result(
            'REQUIRED_COLUMNS',
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === [] ? 'All required workflow columns exist.' : 'Missing columns: ' . implode(', ', $missing),
            count($missing)
        );
    }

    private function testStageHistoryCoverage(?int $caseId): array
    {
        $params = [];
        $caseFilter = $this->caseFilter('c', $caseId, $params);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*)
             FROM cases c
             WHERE NOT EXISTS (
                 SELECT 1 FROM case_stage_history h WHERE h.case_id = c.case_id
             ) $caseFilter"
        );
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $this->result(
            'STAGE_HISTORY_COVERAGE',
            $count === 0 ? 'PASS' : 'FAIL',
            $count === 0 ? 'Every tested case has stage history.' : "$count case record(s) have no stage history.",
            $count
        );
    }

    private function testStageConsistency(?int $caseId): array
    {
        $params = [];
        $caseFilter = $this->caseFilter('c', $caseId, $params);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*)
             FROM cases c
             INNER JOIN case_stage_history h ON h.stage_history_id = (
                 SELECT h2.stage_history_id
                 FROM case_stage_history h2
                 WHERE h2.case_id = c.case_id
                 ORDER BY h2.created_at DESC, h2.stage_history_id DESC
                 LIMIT 1
             )
             WHERE c.case_status <> h.new_stage $caseFilter"
        );
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $this->result(
            'STAGE_CONSISTENCY',
            $count === 0 ? 'PASS' : 'FAIL',
            $count === 0 ? 'Current case stages match latest stage history.' : "$count stage mismatch(es) detected.",
            $count
        );
    }

    private function testOutcomeUniqueness(?int $caseId): array
    {
        $params = [];
        $filter = $this->directCaseFilter('so.case_id', $caseId, $params);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM (
                SELECT so.case_id, so.stage_code
                FROM stage_outcomes so
                WHERE so.is_void = 0 $filter
                GROUP BY so.case_id, so.stage_code
                HAVING COUNT(*) > 1
             ) duplicate_outcomes"
        );
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $this->result(
            'OUTCOME_UNIQUENESS',
            $count === 0 ? 'PASS' : 'FAIL',
            $count === 0 ? 'No stage has multiple active final outcomes.' : "$count duplicate active stage outcome group(s) detected.",
            $count
        );
    }

    private function testSummonsSequence(?int $caseId): array
    {
        $params = [];
        $filter = $this->directCaseFilter('sd.case_id', $caseId, $params);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM (
                SELECT sd.case_id, sd.party_id, sd.attempt_number
                FROM summon_deliveries sd
                WHERE sd.is_void = 0 $filter
                GROUP BY sd.case_id, sd.party_id, sd.attempt_number
                HAVING COUNT(*) > 1
             ) duplicate_attempts"
        );
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $this->result(
            'SUMMONS_SEQUENCE',
            $count === 0 ? 'PASS' : 'FAIL',
            $count === 0 ? 'No duplicate active summons attempt numbers were found.' : "$count duplicate summons attempt group(s) detected.",
            $count
        );
    }

    private function testPangkatComposition(?int $caseId): array
    {
        $params = [];
        $filter = $this->directCaseFilter('pg.case_id', $caseId, $params);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM (
                SELECT pg.pangkat_id
                FROM pangkat_groups pg
                LEFT JOIN pangkat_members pm
                  ON pm.pangkat_id = pg.pangkat_id
                 AND pm.status = 'Active'
                WHERE pg.panel_status = 'Active' $filter
                GROUP BY pg.pangkat_id
                HAVING COUNT(pm.member_id) <> 3
                    OR COUNT(DISTINCT pm.member_id) <> 3
             ) invalid_panels"
        );
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $this->result(
            'PANGKAT_COMPOSITION',
            $count === 0 ? 'PASS' : 'FAIL',
            $count === 0 ? 'Every active Pangkat has three distinct active members.' : "$count invalid active Pangkat panel(s) detected.",
            $count
        );
    }

    private function testCfaIssuance(?int $caseId): array
    {
        $params = [];
        $filter = $this->directCaseFilter('cr.case_id', $caseId, $params);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*)
             FROM cfa_requests cr
             LEFT JOIN cfa_certificates cc
               ON cc.cfa_request_id = cr.cfa_request_id
              AND cc.voided_at IS NULL
             WHERE cr.request_status = 'Issued'
               AND cc.certificate_id IS NULL $filter"
        );
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $this->result(
            'CFA_ISSUANCE',
            $count === 0 ? 'PASS' : 'FAIL',
            $count === 0 ? 'Every issued CFA request has an active certificate.' : "$count issued CFA request(s) have no active certificate.",
            $count
        );
    }

    private function testOrphanWorkflowRecords(?int $caseId): array
    {
        $params = [];
        $filter = $this->directCaseFilter('h.case_id', $caseId, $params);
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*)
             FROM case_stage_history h
             LEFT JOIN cases c ON c.case_id = h.case_id
             WHERE c.case_id IS NULL $filter"
        );
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        return $this->result(
            'ORPHAN_WORKFLOW_RECORDS',
            $count === 0 ? 'PASS' : 'FAIL',
            $count === 0 ? 'No orphan stage-history records were found.' : "$count orphan stage-history record(s) detected.",
            $count
        );
    }

    private function caseFilter(string $alias, ?int $caseId, array &$params): string
    {
        if ($caseId === null) {
            return '';
        }
        $params[] = $caseId;
        return " AND {$alias}.case_id = ?";
    }

    private function directCaseFilter(string $column, ?int $caseId, array &$params): string
    {
        if ($caseId === null) {
            return '';
        }
        $params[] = $caseId;
        return " AND {$column} = ?";
    }

    private function result(string $code, string $status, string $message, int $issueCount): array
    {
        return [
            'code' => $code,
            'status' => $status,
            'message' => $message,
            'issue_count' => $issueCount,
        ];
    }
}
