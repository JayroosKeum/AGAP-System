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
                   TRIM(CONCAT_WS(' ', uf.first_name, uf.middle_name, uf.last_name)) AS finalized_by_name,
                   h.hearing_type, h.hearing_date, h.venue, h.status AS hearing_status,
                   c.case_number, c.case_status
            FROM hearing_minutes hm
            INNER JOIN hearings h ON h.hearing_id = hm.hearing_id
            INNER JOIN cases c ON c.case_id = hm.case_id
            LEFT JOIN users u ON u.user_id = hm.recorded_by
            LEFT JOIN users uf ON uf.user_id = hm.finalized_by
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
        $row['session_minutes'] = $row['settlement_discussion_notes'] ?? '';
        $row['duration_exceed_reason'] = $row['outcome_remarks'] ?? '';

        return $row;
    }

    public function getByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare("
            SELECT hm.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name,
                   TRIM(CONCAT_WS(' ', uf.first_name, uf.middle_name, uf.last_name)) AS finalized_by_name,
                   h.hearing_type, h.hearing_date, h.venue
            FROM hearing_minutes hm
            INNER JOIN hearings h ON h.hearing_id = hm.hearing_id
            LEFT JOIN users u ON u.user_id = hm.recorded_by
            LEFT JOIN users uf ON uf.user_id = hm.finalized_by
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
        $discussion = mb_substr(trim(strip_tags((string) ($data['settlement_discussion_notes'] ?? $data['session_notes'] ?? $data['session_minutes'] ?? ''))), 0, 5000);
        $caucusNotes = mb_substr(trim(strip_tags((string) ($data['caucus_notes'] ?? ''))), 0, 3000);
        $prevProposal = mb_substr(trim(strip_tags((string) ($data['previous_proposal'] ?? ''))), 0, 3000);
        $newProposal = mb_substr(trim(strip_tags((string) ($data['new_proposal'] ?? ''))), 0, 3000);
        $counteroffer = mb_substr(trim(strip_tags((string) ($data['counteroffer'] ?? $data['counter_offer'] ?? ''))), 0, 3000);
        $additionalEvidence = mb_substr(trim(strip_tags((string) ($data['additional_evidence_notes'] ?? ''))), 0, 3000);
        $remarks = mb_substr(trim(strip_tags((string) ($data['outcome_remarks'] ?? $data['duration_exceed_reason'] ?? ''))), 0, 1000);

        // 5. Validate session outcome enum
        $rawOutcome = trim((string) ($data['session_outcome'] ?? ''));
        if (in_array($rawOutcome, [
            'No Settlement - Set Another Meeting',
            'No Settlement → Set Another Meeting',
            'No Settlement -> Set Another Meeting'
        ], true)) {
            $rawOutcome = 'Continue Mediation';
        }
        $allowedOutcomes = [
            'Settled', 'Continue Mediation', 'Continue Conciliation', 'Continue Arbitration',
            'Failed', 'Party Absent', 'Rescheduled', 'Elevate to Pangkat',
            'Pending CFA', 'Arbitration Award', 'Pending'
        ];
        if (!$rawOutcome || !in_array($rawOutcome, $allowedOutcomes, true)) {
            return ['success' => false, 'message' => 'Please select a valid session outcome (Settled, Continue Mediation, Continue Conciliation, Continue Arbitration, Failed, Party Absent, Rescheduled, Elevate to Pangkat, Pending CFA, or Arbitration Award).'];
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

        // Check if existing minutes is finalized
        $checkExisting = $this->conn->prepare("SELECT status FROM hearing_minutes WHERE hearing_id = ?");
        $checkExisting->execute([$hearingId]);
        $existingStatus = $checkExisting->fetchColumn();
        if ($existingStatus === 'Finalized' && empty($data['force_edit'])) {
            return ['success' => false, 'message' => 'This session minutes has already been finalized and cannot be overwritten without authorized unlock.'];
        }

        // Additional fields
        $agendaTopics = mb_substr(trim(strip_tags((string) ($data['agenda_topics'] ?? ''))), 0, 3000);
        $actionItems = mb_substr(trim(strip_tags((string) ($data['agreements_action_items'] ?? $data['action_items'] ?? ''))), 0, 3000);
        $unresolvedIssues = mb_substr(trim(strip_tags((string) ($data['unresolved_issues'] ?? ''))), 0, 3000);
        $status = (!empty($data['status']) && in_array($data['status'], ['Draft', 'Finalized'], true)) ? $data['status'] : 'Draft';
        $finalizedBy = ($status === 'Finalized') ? $userId : null;
        $finalizedAt = ($status === 'Finalized') ? date('Y-m-d H:i:s') : null;

        $manageTx = false;
        try {
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $manageTx = true;
            }

            $sql = "
                INSERT INTO hearing_minutes (
                    hearing_id, case_id, session_type, opening_conducted, identity_verified,
                    complaint_reviewed, complainant_statement, respondent_statement,
                    main_dispute_identified, settlement_discussion_notes, caucus_conducted,
                    caucus_notes, previous_proposal, new_proposal, counteroffer,
                    additional_evidence_notes, session_outcome, status, agenda_topics,
                    agreements_action_items, unresolved_issues, finalized_by, finalized_at,
                    outcome_remarks, actual_end_time, recorded_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
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
                    status = VALUES(status),
                    agenda_topics = VALUES(agenda_topics),
                    agreements_action_items = VALUES(agreements_action_items),
                    unresolved_issues = VALUES(unresolved_issues),
                    finalized_by = VALUES(finalized_by),
                    finalized_at = VALUES(finalized_at),
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
                $additionalEvidence ?: null, $outcome, $status, $agendaTopics ?: null,
                $actionItems ?: null, $unresolvedIssues ?: null, $finalizedBy, $finalizedAt,
                $remarks ?: null, $actualEndTime, $userId
            ]);

            // When minutes/outcome are submitted, conclude hearing session and track actual duration
            $endTimestamp = $actualEndTime ?: date('Y-m-d H:i:s');
            try {
                $updH = $this->conn->prepare("
                    UPDATE hearings
                    SET actual_end_time = ?,
                        actual_duration_minutes = CASE
                            WHEN actual_start_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, actual_start_time, ?)
                            ELSE COALESCE(actual_duration_minutes, duration_minutes, 45)
                        END,
                        queue_status = 'Concluded',
                        status = IF(status IN ('Cancelled', 'Office Cancelled'), status, 'Completed')
                    WHERE hearing_id = ?
                ");
                $updH->execute([$endTimestamp, $endTimestamp, $hearingId]);
            } catch (Throwable $updEx) {
                // Safe fallback if elastic duration / queue columns are absent
                $updFallback = $this->conn->prepare("
                    UPDATE hearings
                    SET actual_end_time = ?,
                        status = IF(status IN ('Cancelled', 'Office Cancelled'), status, 'Completed')
                    WHERE hearing_id = ?
                ");
                $updFallback->execute([$endTimestamp, $hearingId]);
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
            } elseif ($outcome === 'Pending CFA') {
                $histMsg = sprintf('%s session concluded: Conciliation unsuccessful. Eligible for Certificate to File Action (KP Form 20).', $sessionType);
                $this->logCaseHistory($caseId, $hearing['case_status'], $histMsg, $userId);
            } elseif ($outcome === 'Arbitration Award') {
                $histMsg = sprintf('%s session concluded: Arbitration proceedings concluded. Ready for Arbitration Award.', $sessionType);
                $this->logCaseHistory($caseId, 'Arbitration', $histMsg, $userId);
            }

            if ($status === 'Finalized') {
                $this->logCaseHistory($caseId, $hearing['case_status'] ?? 'Mediation', "Session minutes for hearing #{$hearingId} ({$sessionType}) finalized and locked.", $userId);
            }

            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            // Direct Next Hearing Booking from Minutes of Meeting (RA 7160 Sec. 410)
            // When "No Settlement -> Set Another Meeting" is selected and date/time are provided,
            // immediately schedule the 2nd/3rd mediation session.
            // Redundant summons (KP Form 9) is suppressed because both parties are personally notified.
            $nextHearingBooked = false;
            $nextHearingInfo = null;
            $nextHearingError = null;

            $shouldBookNext = (
                (!empty($data['book_next_hearing']) || in_array($outcome, ['Continue Mediation', 'Continue Conciliation'], true)) &&
                !empty($data['next_hearing_date']) &&
                !empty($data['next_hearing_time'])
            );

            if ($shouldBookNext) {
                $nextDate = trim((string) $data['next_hearing_date']);
                $nextTime = trim((string) $data['next_hearing_time']);
                $nextDateTime = $nextDate . ' ' . (strlen($nextTime) === 5 ? ($nextTime . ':00') : $nextTime);
                $targetType = $hearing['hearing_type']; // 'Mediation' or 'Conciliation'

                // Verify if a subsequent hearing is already booked for this case & stage
                $checkNext = $this->conn->prepare("
                    SELECT hearing_id, hearing_date, venue FROM hearings
                    WHERE case_id = ? AND hearing_type = ? AND hearing_date > ? AND status NOT IN ('Cancelled', 'Office Cancelled')
                    ORDER BY hearing_date ASC LIMIT 1
                ");
                $checkNext->execute([$caseId, $targetType, $hearing['hearing_date']]);
                $alreadyScheduled = $checkNext->fetch(PDO::FETCH_ASSOC);

                if (!$alreadyScheduled) {
                    require_once __DIR__ . '/Hearing.php';
                    require_once __DIR__ . '/../services/DeadlineService.php';

                    try {
                        $hearingModel = new Hearing($this->conn);
                        $venue = trim((string) ($data['next_hearing_venue'] ?? '')) ?: ($hearing['venue'] ?: 'Lupon Court Room');
                        $presiderId = !empty($data['next_hearing_presider_id']) ? (int) $data['next_hearing_presider_id'] : ($hearing['presiding_officer_id'] ?? null);

                        $schedValues = [
                            'case_id' => $caseId,
                            'hearing_type' => $targetType,
                            'hearing_date' => $nextDateTime,
                            'venue' => $venue,
                            'remarks' => sprintf('Directly booked from %s session minutes (parties personally notified; summons KP Form 9 suppressed under RA 7160 Sec. 410).', $sessionType),
                            'presiding_officer_id' => $presiderId,
                            'duration_minutes' => 45,
                            'schedule_reviewed' => '1'
                        ];

                        $deadline = DeadlineService::deadlineForHearing($targetType, $nextDateTime);
                        $bookResult = $hearingModel->createProgression($schedValues, $deadline, $userId);

                        if (!empty($bookResult['success'])) {
                            $nextHearingBooked = true;
                            $nextHearingInfo = [
                                'hearing_id' => $bookResult['hearing_id'],
                                'sequence' => $bookResult['sequence'] ?? 2,
                                'hearing_date' => $nextDateTime,
                                'hearing_type' => $targetType,
                                'venue' => $venue,
                                'summons_suppressed' => true
                            ];
                            $ord = $hearingModel->ordinal($bookResult['sequence'] ?? 2);
                            $this->logCaseHistory(
                                $caseId,
                                $hearing['case_status'] ?? 'Mediation',
                                "Scheduled {$ord} {$targetType} for {$nextDateTime} directly from session minutes. Summons (KP Form 9) suppressed under RA 7160 Sec. 410.",
                                $userId
                            );
                        } else {
                            $nextHearingError = $bookResult['message'] ?? 'Unable to schedule next hearing session.';
                        }
                    } catch (Throwable $schedEx) {
                        error_log('Error booking next hearing from minutes: ' . $schedEx->getMessage());
                        $nextHearingError = $schedEx->getMessage();
                    }
                } else {
                    $nextHearingBooked = true;
                    $nextHearingInfo = [
                        'hearing_id' => $alreadyScheduled['hearing_id'],
                        'hearing_date' => $alreadyScheduled['hearing_date'],
                        'venue' => $alreadyScheduled['venue'],
                        'already_existed' => true
                    ];
                }
            }

            $response = [
                'success' => true,
                'message' => ($status === 'Finalized') ? 'Hearing session minutes finalized and locked successfully.' : 'Hearing session minutes saved successfully.',
                'hearing_id' => $hearingId,
                'outcome' => $outcome,
                'status' => $status,
                'case_id' => $caseId
            ];

            if ($nextHearingBooked && $nextHearingInfo) {
                $response['next_hearing_booked'] = true;
                $response['next_hearing'] = $nextHearingInfo;
                if (!empty($nextHearingInfo['already_existed'])) {
                    $response['message'] .= ' (Next session is already scheduled).';
                } else {
                    $response['message'] .= ' Next mediation session was also booked successfully without summons.';
                }
            } elseif ($nextHearingError) {
                $response['next_hearing_error'] = $nextHearingError;
                $response['message'] .= ' (Note: Next hearing could not be scheduled: ' . $nextHearingError . ')';
            }

            return $response;
        } catch (Throwable $e) {
            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('HearingMinutes save error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to save hearing minutes: ' . $e->getMessage()];
        }
    }

    public function finalize(int $hearingId, int $userId): array
    {
        $minutes = $this->getByHearing($hearingId);
        if (!$minutes) {
            return ['success' => false, 'message' => 'No session minutes found to finalize. Save a draft first.'];
        }

        try {
            $stmt = $this->conn->prepare("
                UPDATE hearing_minutes
                SET status = 'Finalized',
                    finalized_by = ?,
                    finalized_at = NOW(),
                    updated_at = NOW()
                WHERE hearing_id = ?
            ");
            $stmt->execute([$userId, $hearingId]);

            $this->logCaseHistory((int) $minutes['case_id'], $minutes['case_status'] ?? 'Mediation', "Session minutes for hearing #{$hearingId} ({$minutes['session_type']}) finalized and approved.", $userId);

            return [
                'success' => true,
                'message' => 'Session minutes finalized and locked successfully.',
                'hearing_id' => $hearingId,
                'status' => 'Finalized'
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Unable to finalize session minutes: ' . $e->getMessage()];
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
