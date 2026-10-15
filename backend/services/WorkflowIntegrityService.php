<?php

declare(strict_types=1);

final class WorkflowIntegrityService
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function scan(?int $caseId = null): array
    {
        $issues = [];
        foreach ($this->cases($caseId) as $case) {
            $id = (int) $case['case_id'];
            $latest = $this->latestStage($id);

            if (!$latest) {
                $issues[] = $this->issue($id, 'CASE_WITHOUT_STAGE_HISTORY', 'Warning', 'Case has no stage-history record.');
            } elseif ((string) $case['case_status'] !== (string) $latest['new_stage']) {
                $issues[] = $this->issue($id, 'CASE_STAGE_MISMATCH', 'Critical', sprintf(
                    'Current case stage is %s; latest history stage is %s.',
                    $case['case_status'],
                    $latest['new_stage']
                ));
            }

            $issues = array_merge($issues, $this->activeOutcomeIssues($id));
            $issues = array_merge($issues, $this->pangkatIssues($id));
            $issues = array_merge($issues, $this->cfaIssues($id));
        }

        return [
            'success' => true,
            'data' => [
                'case_id' => $caseId,
                'issue_count' => count($issues),
                'issues' => $issues,
            ],
        ];
    }

    public function persist(array $issues): int
    {
        $inserted = 0;
        $stmt = $this->conn->prepare(
            'INSERT INTO workflow_integrity_events (case_id, event_code, severity, details, detected_at)
             SELECT ?, ?, ?, ?, NOW()
             WHERE NOT EXISTS (
                SELECT 1 FROM workflow_integrity_events
                WHERE case_id <=> ? AND event_code = ? AND resolved_at IS NULL
             )'
        );

        foreach ($issues as $issue) {
            $stmt->execute([
                $issue['case_id'], $issue['event_code'], $issue['severity'], $issue['details'],
                $issue['case_id'], $issue['event_code'],
            ]);
            $inserted += $stmt->rowCount();
        }

        return $inserted;
    }

    private function cases(?int $caseId): array
    {
        if ($caseId !== null) {
            $stmt = $this->conn->prepare('SELECT case_id, case_status FROM cases WHERE case_id = ?');
            $stmt->execute([$caseId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->conn->query('SELECT case_id, case_status FROM cases ORDER BY case_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function latestStage(int $caseId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT new_stage FROM case_stage_history
             WHERE case_id = ?
             ORDER BY created_at DESC, stage_history_id DESC
             LIMIT 1'
        );
        $stmt->execute([$caseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function activeOutcomeIssues(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT stage_code, COUNT(*) AS total
             FROM stage_outcomes
             WHERE case_id = ? AND is_void = 0
             GROUP BY stage_code
             HAVING COUNT(*) > 1'
        );
        $stmt->execute([$caseId]);
        $issues = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $issues[] = $this->issue($caseId, 'MULTIPLE_ACTIVE_STAGE_OUTCOMES', 'Critical', sprintf(
                '%s has %d active outcomes.', $row['stage_code'], $row['total']
            ));
        }
        return $issues;
    }

    private function pangkatIssues(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT pg.pangkat_id, COUNT(pm.member_id) AS total, COUNT(DISTINCT pm.member_id) AS distinct_total
             FROM pangkat_groups pg
             LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.status = 'Active'
             WHERE pg.case_id = ? AND pg.panel_status = 'Active'
             GROUP BY pg.pangkat_id"
        );
        $stmt->execute([$caseId]);
        $issues = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['total'] !== 3 || (int) $row['distinct_total'] !== 3) {
                $issues[] = $this->issue($caseId, 'INVALID_ACTIVE_PANGKAT', 'Critical', sprintf(
                    'Active Pangkat %d does not have exactly three distinct active members.', $row['pangkat_id']
                ));
            }
        }
        return $issues;
    }

    private function cfaIssues(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT cr.cfa_request_id
             FROM cfa_requests cr
             LEFT JOIN cfa_certificates cc ON cc.cfa_request_id = cr.cfa_request_id AND cc.voided_at IS NULL
             WHERE cr.case_id = ? AND cr.request_status = 'Issued' AND cc.certificate_id IS NULL"
        );
        $stmt->execute([$caseId]);
        $issues = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $issues[] = $this->issue($caseId, 'CFA_ISSUED_WITHOUT_CERTIFICATE', 'Critical', sprintf(
                'CFA request %d is Issued without an active certificate.', $row['cfa_request_id']
            ));
        }
        return $issues;
    }

    private function issue(int $caseId, string $code, string $severity, string $details): array
    {
        return ['case_id' => $caseId, 'event_code' => $code, 'severity' => $severity, 'details' => $details];
    }
}
