<?php

require_once __DIR__ . '/../config/database.php';

class HearingMinutes
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
    }

    public function getByHearing(int $hearingId): array|false
    {
        $stmt = $this->conn->prepare("
            SELECT hm.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name,
                   h.hearing_type, h.hearing_date, h.venue, h.status AS hearing_status,
                   c.case_number, c.case_status
            FROM hearing_minutes hm
            INNER JOIN hearings h ON h.hearing_id = hm.hearing_id
            INNER JOIN cases c ON c.case_id = hm.case_id
            LEFT JOIN users u ON u.user_id = hm.recorded_by
            WHERE hm.hearing_id = ?
        ");
        $stmt->execute([$hearingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        // Add dual-compatibility aliases for frontend binding
        $row['parties_identified'] = (int) ($row['identity_verified'] ?? 0);
        $row['complaint_read_confirmed'] = (int) ($row['complaint_reviewed'] ?? 0);
        $row['complainant_statement_summary'] = $row['complainant_statement'] ?? '';
        $row['respondent_statement_summary'] = $row['respondent_statement'] ?? '';
        $row['dispute_summary'] = $row['main_dispute_identified'] ?? '';
        $row['counter_offer'] = $row['counteroffer'] ?? '';
        $row['session_notes'] = $row['settlement_discussion_notes'] ?? '';
        $row['duration_exceed_reason'] = $row['outcome_remarks'] ?? '';

        return $row;
    }

    public function getByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare("
            SELECT hm.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name,
                   h.hearing_type, h.hearing_date, h.venue
            FROM hearing_minutes hm
            INNER JOIN hearings h ON h.hearing_id = hm.hearing_id
            LEFT JOIN users u ON u.user_id = hm.recorded_by
            WHERE hm.case_id = ?
            ORDER BY h.hearing_date ASC, hm.minute_id ASC
        ");
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $data, int $userId): array
    {
        // 1. Validate hearing_id
        $hearingId = filter_var($data['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$hearingId) {
            return ['success' => false, 'message' => 'A valid hearing ID is required.'];
        }

        $hStmt = $this->conn->prepare("
            SELECT h.*, c.case_id, c.case_number, c.case_status, c.complaint_id
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            WHERE h.hearing_id = ?
        ");
        $hStmt->execute([$hearingId]);
        $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hearing) {
            return ['success' => false, 'message' => 'Specified hearing session record was not found.'];
        }

        $caseId = (int) $hearing['case_id'];

        // 2. Normalize and validate session_type
        $rawSessionType = trim((string) ($data['session_type'] ?? $hearing['hearing_type'] ?? '1st Mediation'));
        $allowedSessionTypes = ['1st Mediation', '2nd Mediation', '3rd Mediation', 'Conciliation', 'Arbitration', 'Show Cause'];
        $sessionType = in_array($rawSessionType, $allowedSessionTypes, true) ? $rawSessionType : '1st Mediation';

        // 3. Normalize and validate checkboxes (0 or 1)
        $opening = !empty($data['opening_conducted']) ? 1 : 0;
        $identity = (!empty($data['identity_verified']) || !empty($data['parties_identified'])) ? 1 : 0;
        $reviewed = (!empty($data['complaint_reviewed']) || !empty($data['complaint_read_confirmed'])) ? 1 : 0;
        $caucus = !empty($data['caucus_conducted']) ? 1 : 0;

        // 4. Normalize and sanitize text statements (with length boundaries)
        $compStmt = mb_substr(trim(strip_tags((string) ($data['complainant_statement'] ?? $data['complainant_statement_summary'] ?? ''))), 0, 5000);
        $respStmt = mb_substr(trim(strip_tags((string) ($data['respondent_statement'] ?? $data['respondent_statement_summary'] ?? ''))), 0, 5000);
        $dispute = mb_substr(trim(strip_tags((string) ($data['main_dispute_identified'] ?? $data['dispute_summary'] ?? ''))), 0, 1000);
        $discussion = mb_substr(trim(strip_tags((string) ($data['settlement_discussion_notes'] ?? $data['session_notes'] ?? ''))), 0, 5000);
        $caucusNotes = mb_substr(trim(strip_tags((string) ($data['caucus_notes'] ?? ''))), 0, 3000);
        $prevProposal = mb_substr(trim(strip_tags((string) ($data['previous_proposal'] ?? ''))), 0, 3000);
        $newProposal = mb_substr(trim(strip_tags((string) ($data['new_proposal'] ?? ''))), 0, 3000);
        $counteroffer = mb_substr(trim(strip_tags((string) ($data['counteroffer'] ?? $data['counter_offer'] ?? ''))), 0, 3000);
        $additionalEvidence = mb_substr(trim(strip_tags((string) ($data['additional_evidence_notes'] ?? ''))), 0, 3000);
        $remarks = mb_substr(trim(strip_tags((string) ($data['outcome_remarks'] ?? $data['duration_exceed_reason'] ?? ''))), 0, 1000);

        // 5. Validate session outcome enum
        $rawOutcome = trim((string) ($data['session_outcome'] ?? ''));
        $allowedOutcomes = [
            'Settled', 'Continue Mediation', 'Failed', 'Party Absent',
            'Rescheduled', 'Elevate to Pangkat', 'Pending'
        ];
        if (!$rawOutcome || !in_array($rawOutcome, $allowedOutcomes, true)) {
            return ['success' => false, 'message' => 'Please select a valid session outcome (Settled, Continue Mediation, Failed, Party Absent, Rescheduled, or Elevate to Pangkat).'];
        }
        $outcome = $rawOutcome;

        // 6. Validate actual_end_time format if provided
        $actualEndTime = null;
        if (!empty($data['actual_end_time'])) {
            $ts = strtotime($data['actual_end_time']);
            if ($ts === false) {
                return ['success' => false, 'message' => 'Actual session end time has an invalid datetime format.'];
            }
            $actualEndTime = date('Y-m-d H:i:s', $ts);
        }

        try {
            $this->conn->beginTransaction();

            $sql = "
                INSERT INTO hearing_minutes (
                    hearing_id, case_id, session_type, opening_conducted, identity_verified,
                    complaint_reviewed, complainant_statement, respondent_statement,
                    main_dispute_identified, settlement_discussion_notes, caucus_conducted,
                    caucus_notes, previous_proposal, new_proposal, counteroffer,
                    additional_evidence_notes, session_outcome, outcome_remarks,
                    actual_end_time, recorded_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    session_type = VALUES(session_type),
                    opening_conducted = VALUES(opening_conducted),
                    identity_verified = VALUES(identity_verified),
                    complaint_reviewed = VALUES(complaint_reviewed),
                    complainant_statement = VALUES(complainant_statement),
                    respondent_statement = VALUES(respondent_statement),
                    main_dispute_identified = VALUES(main_dispute_identified),
                    settlement_discussion_notes = VALUES(settlement_discussion_notes),
                    caucus_conducted = VALUES(caucus_conducted),
                    caucus_notes = VALUES(caucus_notes),
                    previous_proposal = VALUES(previous_proposal),
                    new_proposal = VALUES(new_proposal),
                    counteroffer = VALUES(counteroffer),
                    additional_evidence_notes = VALUES(additional_evidence_notes),
                    session_outcome = VALUES(session_outcome),
                    outcome_remarks = VALUES(outcome_remarks),
                    actual_end_time = VALUES(actual_end_time),
                    recorded_by = VALUES(recorded_by),
                    updated_at = CURRENT_TIMESTAMP
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $hearingId, $caseId, $sessionType, $opening, $identity,
                $reviewed, $compStmt ?: null, $respStmt ?: null,
                $dispute ?: null, $discussion ?: null, $caucus,
                $caucusNotes ?: null, $prevProposal ?: null, $newProposal ?: null, $counteroffer ?: null,
                $additionalEvidence ?: null, $outcome, $remarks ?: null,
                $actualEndTime, $userId
            ]);

            // If actual end time was specified, also update hearings table
            if ($actualEndTime) {
                $updH = $this->conn->prepare("UPDATE hearings SET actual_end_time = ? WHERE hearing_id = ?");
                $updH->execute([$actualEndTime, $hearingId]);
            }

            // Outcome workflow triggers
            if ($outcome === 'Settled') {
                $histMsg = sprintf('%s session concluded: Settlement reached. Proceed to prepare Amicable Settlement.', $sessionType);
                $this->logCaseHistory($caseId, $hearing['case_status'], $histMsg, $userId);
            } elseif ($outcome === 'Failed') {
                $histMsg = sprintf('%s session concluded: Mediation failed to reach settlement. Case eligible for Pangkat constitution (KP Form 10).', $sessionType);
                $this->logCaseHistory($caseId, $hearing['case_status'], $histMsg, $userId);
            } elseif ($outcome === 'Elevate to Pangkat') {
                $histMsg = sprintf('%s session concluded: Case elevated to Pangkat Tagapagkasundo (Conciliation).', $sessionType);
                $this->logCaseHistory($caseId, 'Conciliation', $histMsg, $userId);
            }

            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Hearing session minutes recorded successfully.',
                'hearing_id' => $hearingId,
                'outcome' => $outcome,
                'case_id' => $caseId
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('HearingMinutes save error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to save hearing minutes: ' . $e->getMessage()];
        }
    }

    private function logCaseHistory(int $caseId, string $status, string $remarks, int $userId): void
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$caseId, $status, $remarks, $userId]);
        } catch (Throwable $e) {
            error_log('logCaseHistory error: ' . $e->getMessage());
        }
    }
}
