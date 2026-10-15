<?php

declare(strict_types=1);

final class WorkflowWorkspaceService
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function get(int $caseId): array
    {
        $caseStmt = $this->conn->prepare(
            'SELECT case_id, case_number, case_status, updated_at
             FROM cases
             WHERE case_id = ?
             LIMIT 1'
        );
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        $historyStmt = $this->conn->prepare(
            'SELECT stage_history_id, action_code, previous_stage, new_stage,
                    reason_code, reason_notes, source_type, source_id,
                    actor_user_id, created_at
             FROM case_stage_history
             WHERE case_id = ?
             ORDER BY created_at ASC, stage_history_id ASC'
        );
        $historyStmt->execute([$caseId]);

        $outcomeStmt = $this->conn->prepare(
            'SELECT outcome_id, hearing_id, stage_code, outcome_code,
                    reason_code, outcome_notes, recorded_by, recorded_at
             FROM stage_outcomes
             WHERE case_id = ? AND is_void = 0
             ORDER BY recorded_at ASC, outcome_id ASC'
        );
        $outcomeStmt->execute([$caseId]);

        $summonsStmt = $this->conn->prepare(
            "SELECT delivery_id, party_id, party_type, document_id, attempt_number,
                    delivery_status, attempted_at, served_at, unserved_reason
             FROM summon_deliveries
             WHERE case_id = ? AND is_void = 0
             ORDER BY party_type, party_id, attempt_number, delivery_id"
        );
        $summonsStmt->execute([$caseId]);

        $hearingStmt = $this->conn->prepare(
            'SELECT hearing_id, hearing_type, hearing_date, venue, status, remarks
             FROM hearings
             WHERE case_id = ?
             ORDER BY hearing_date ASC, hearing_id ASC'
        );
        $hearingStmt->execute([$caseId]);

        $pangkatStmt = $this->conn->prepare(
            'SELECT pangkat_id, formation_date, convening_deadline,
                    selection_method, panel_status, conflict_check_completed
             FROM pangkat_groups
             WHERE case_id = ?
             ORDER BY pangkat_id DESC
             LIMIT 1'
        );
        $pangkatStmt->execute([$caseId]);
        $pangkat = $pangkatStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $cfaRequestStmt = $this->conn->prepare(
            'SELECT cfa_request_id, ground_code, request_status,
                    requested_at, reviewed_at, review_decision
             FROM cfa_requests
             WHERE case_id = ?
             ORDER BY requested_at DESC, cfa_request_id DESC'
        );
        $cfaRequestStmt->execute([$caseId]);

        $certificateStmt = $this->conn->prepare(
            'SELECT certificate_id, certificate_number, form_code,
                    legal_ground_code, issued_at, verification_code,
                    release_status, released_at
             FROM cfa_certificates
             WHERE case_id = ? AND voided_at IS NULL
             ORDER BY issued_at DESC, certificate_id DESC'
        );
        $certificateStmt->execute([$caseId]);

        return [
            'success' => true,
            'data' => [
                'case' => $case,
                'stage_definition' => $this->stageDefinition((string) $case['case_status']),
                'available_actions' => $this->availableActions((string) $case['case_status']),
                'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC),
                'outcomes' => $outcomeStmt->fetchAll(PDO::FETCH_ASSOC),
                'summons' => $summonsStmt->fetchAll(PDO::FETCH_ASSOC),
                'hearings' => $hearingStmt->fetchAll(PDO::FETCH_ASSOC),
                'pangkat' => $pangkat,
                'cfa_requests' => $cfaRequestStmt->fetchAll(PDO::FETCH_ASSOC),
                'certificates' => $certificateStmt->fetchAll(PDO::FETCH_ASSOC),
            ],
        ];
    }

    private function stageDefinition(string $stage): array
    {
        $sequence = [
            'Docketed',
            'Summons',
            'Mediation',
            'Pangkat Formation',
            'Conciliation',
            'CFA Review',
            'CFA Issued',
        ];

        $index = array_search($stage, $sequence, true);

        return [
            'current' => $stage,
            'sequence' => $sequence,
            'current_index' => $index === false ? null : $index,
            'terminal' => in_array($stage, ['Settled', 'Closed', 'Archived', 'CFA Issued'], true),
        ];
    }

    private function availableActions(string $stage): array
    {
        return match ($stage) {
            'Docketed' => ['ISSUE_INITIAL_NOTICES'],
            'Summons' => ['START_SUMMONS_ATTEMPT', 'RECORD_SUMMONS_ATTEMPT', 'CONFIRM_MEDIATION_READINESS'],
            'Mediation' => ['VIEW_MEDIATION', 'RECORD_MEDIATION_OUTCOME'],
            'Pangkat Formation' => ['ACTIVATE_PANGKAT'],
            'Conciliation' => ['VIEW_CONCILIATION', 'RECORD_CONCILIATION_OUTCOME'],
            'CFA Review' => ['SUBMIT_CFA_REQUEST', 'REVIEW_CFA_REQUEST', 'ISSUE_CFA'],
            'CFA Issued' => ['RELEASE_CFA', 'ARCHIVE_CFA_CASE'],
            'Settled' => ['FINALIZE_SETTLEMENT_CASE'],
            'Closed' => ['ARCHIVE_CLOSED_CASE'],
            default => [],
        };
    }
}
