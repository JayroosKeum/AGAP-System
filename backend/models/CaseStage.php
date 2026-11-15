<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditService.php';

class CaseStage
{
    private PDO $conn;
    private AuditService $audit;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService($this->conn);
    }

    /**
     * Instantiates the document generator service (overridable for failure-injection tests).
     */
    protected function createKpFormService(): KpFormService
    {
        require_once __DIR__ . '/../services/KpFormService.php';
        return new KpFormService($this->conn);
    }

    /**
     * Retrieves stage overview data for both Mediation and Pangkat Conciliation.
     */
    public function getStageOverview(int $caseId): array|false
    {
        $caseStmt = $this->conn->prepare("
            SELECT c.*,
                   co.complaint_id, co.complaint_number, co.complaint_title, co.narrative,
                   co.created_at AS filing_date, co.incident_date, co.incident_location,
                   (
                       SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                       FROM complaint_parties cp
                       INNER JOIN residents r ON r.resident_id = cp.resident_id
                       WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                   ) AS complainant_names,
                   (
                       SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                       FROM complaint_parties cp
                       INNER JOIN residents r ON r.resident_id = cp.resident_id
                       WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                   ) AS respondent_names
            FROM cases c
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE c.case_id = ?
        ");
        $caseStmt->execute([$caseId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$case) {
            return false;
        }

        // Mediation timer computation
        require_once __DIR__ . '/../services/MediationDeadlineService.php';
        $deadlineService = new MediationDeadlineService($this->conn);
        $case['mediation_timer'] = $deadlineService->computeStatus($case);

        // Fetch recorded stages from case_stages table
        $stagesStmt = $this->conn->prepare("
            SELECT cs.*,
                   TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS referring_officer_name
            FROM case_stages cs
            LEFT JOIN users u ON u.user_id = cs.referring_officer_id
            WHERE cs.case_id = ?
        ");
        $stagesStmt->execute([$caseId]);
        $dbStages = [];
        while ($row = $stagesStmt->fetch(PDO::FETCH_ASSOC)) {
            $dbStages[$row['stage_type']] = $row;
        }

        // Presiding Officer for Mediation (Barangay Captain / First Administrator)
        $pbStmt = $this->conn->prepare("
            SELECT TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS pb_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE r.role_name = 'Administrator' AND u.status = 'Active'
            ORDER BY u.user_id ASC LIMIT 1
        ");
        $pbStmt->execute();
        $pbName = $pbStmt->fetchColumn() ?: 'Punong Barangay';

        // 1. MEDIATION STAGE
        $medStage = $this->buildMediationOverview($case, $dbStages['Mediation'] ?? null, $pbName);

        // 2. CONCILIATION STAGE
        $conStage = $this->buildConciliationOverview($case, $dbStages['Conciliation'] ?? null, $medStage);

        // 3. ARBITRATION STAGE
        $arbStage = $this->buildArbitrationOverview($case, $dbStages['Arbitration'] ?? null, $pbName);

        return [
            'case' => $case,
            'mediation' => $medStage,
            'conciliation' => $conStage,
            'arbitration' => $arbStage
        ];
    }

    /**
     * Single server-side eligibility decision shared by the stage workspace and referToPangkat().
     * Counts distinct qualifying completed Mediation hearings (excluding Drafts, attendance-only
     * rows, cancellations, and superseded hearings) and evaluates any authorized deadline lapse.
     */
    public static function evaluateMediationReferralEligibility(PDO $conn, int $caseId, ?array $caseRow = null): array
    {
        if ($caseRow === null) {
            $cStmt = $conn->prepare('SELECT * FROM cases WHERE case_id = ? LIMIT 1');
            $cStmt->execute([$caseId]);
            $caseRow = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$caseRow) {
            return [
                'eligible' => false,
                'already_referred' => false,
                'is_arbitration' => false,
                'is_closed' => true,
                'is_settled' => false,
                'is_lapsed' => false,
                'completed_mediation_count' => 0,
                'required_mediation_sessions' => 2,
                'configured_max_sessions' => 2,
                'policy_conflict' => false,
                'policy_conflict_message' => null,
                'latest_qualifying_outcome' => null,
                'has_finalized_unsuccessful' => false,
                'stage_record' => null,
                'timer' => [],
                'basis' => null,
                'reason' => 'Case not found.',
            ];
        }

        $stageStmt = $conn->prepare("SELECT * FROM case_stages WHERE case_id = ? AND stage_type = 'Mediation' LIMIT 1");
        $stageStmt->execute([$caseId]);
        $stageRecord = $stageStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $arbStageStmt = $conn->prepare("SELECT stage_status, outcome FROM case_stages WHERE case_id = ? AND stage_type = 'Arbitration' LIMIT 1");
        $arbStageStmt->execute([$caseId]);
        $arbStage = $arbStageStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $arbRecStmt = $conn->prepare("SELECT 1 FROM arbitration_records WHERE case_id = ? LIMIT 1");
        $arbRecStmt->execute([$caseId]);
        $hasArbRecord = (bool) $arbRecStmt->fetchColumn();

        require_once __DIR__ . '/Hearing.php';
        require_once __DIR__ . '/../services/MediationDeadlineService.php';
        $medPolicy = MediationDeadlineService::getMediationPolicy($conn);
        $requiredSessions = $medPolicy['referral_required_sessions'];
        $deadlineSvc = new MediationDeadlineService($conn);
        $timer = $deadlineSvc->computeStatus($caseRow);

        $nonSupSql = Hearing::nonSupersededSql('h', 'next_h');
        $hStmt = $conn->prepare("
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.status,
                   hm.minute_id, hm.status AS minutes_status, hm.session_outcome
            FROM hearings h
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
            WHERE h.case_id = ?
              AND h.hearing_type IN ('Initial Hearing', 'Mediation')
              AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
              AND {$nonSupSql}
            ORDER BY h.hearing_date ASC, h.hearing_id ASC
        ");
        $hStmt->execute([$caseId]);
        $rows = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        $seenHearings = [];
        $completedMediationCount = 0;
        $hasSettledSession = false;
        $latestQualifyingOutcome = null;

        foreach ($rows as $row) {
            $hid = (int) ($row['hearing_id'] ?? 0);
            if ($hid <= 0 || isset($seenHearings[$hid])) {
                continue;
            }
            $seenHearings[$hid] = true;

            $isFinalized = !empty($row['minute_id']) && ($row['minutes_status'] ?? '') === 'Finalized';
            $outcomeVal = (string) ($row['session_outcome'] ?? '');
            $isCompletedQualifying = ($row['status'] === 'Completed' && $isFinalized && $outcomeVal !== 'Rescheduled');
            if (!$isCompletedQualifying) {
                continue;
            }

            $completedMediationCount++;
            $latestQualifyingOutcome = $outcomeVal;
            if ($outcomeVal === 'Settled') {
                $hasSettledSession = true;
            }
        }

        $hasFinalizedUnsuccessful = in_array($latestQualifyingOutcome, ['Failed', 'Elevate to Pangkat'], true);

        $caseStatus = (string) ($caseRow['case_status'] ?? '');
        $stageStatus = (string) ($stageRecord['stage_status'] ?? '');
        $stageOutcome = (string) ($stageRecord['outcome'] ?? '');
        $isSettled = ($caseStatus === 'Settled' || $stageOutcome === 'Settled' || $hasSettledSession);
        $isArbitration = (
            $caseStatus === 'Arbitration'
            || $stageOutcome === 'Arbitration'
            || $latestQualifyingOutcome === 'Arbitration'
            || $hasArbRecord
            || ($arbStage && ($arbStage['stage_status'] ?? 'Locked') !== 'Locked')
        );
        $isClosed = in_array($caseStatus, ['Settled', 'Resolved', 'Completed', 'Dismissed', 'DISMISSED_BARRED', 'Archived', 'CFA Issued', 'Repudiated'], true);
        $alreadyReferred = ($caseStatus === 'Conciliation' || $stageStatus === 'Referred to Pangkat');
        $isLapsed = !empty($timer['is_lapsed']) && empty($timer['is_paused']);

        $eligible = false;
        $basis = null;
        $reason = null;

        if ($isSettled) {
            $reason = 'Cannot elevate to Pangkat Conciliation: An amicable settlement was already reached for this case.';
        } elseif ($isArbitration) {
            $reason = 'Case is already in Arbitration and cannot be referred to Pangkat Conciliation.';
        } elseif ($isClosed) {
            $reason = 'This concluded case is read-only; it cannot be referred to Pangkat.';
        } elseif ($alreadyReferred) {
            $reason = 'Case has already been referred to Pangkat Conciliation.';
        } elseif ($caseStatus !== 'Mediation') {
            $reason = 'Only a case in active Mediation can be referred to Pangkat Conciliation.';
        } elseif ($isLapsed) {
            $eligible = true;
            $basis = 'statutory_deadline_lapsed';
        } elseif ($completedMediationCount < $requiredSessions) {
            $reason = sprintf(
                'Progression to Conciliation is locked: Completed %d of %d required mediation sessions. Barangay Tumana policy (RA 7160) requires %d mediation sessions before elevating to Pangkat Conciliation.',
                $completedMediationCount,
                $requiredSessions,
                $requiredSessions
            );
        } elseif (!$hasFinalizedUnsuccessful) {
            $reason = 'Finalize the latest Mediation session with an unsuccessful outcome (Failed or Elevate to Pangkat) before referring to Pangkat.';
        } else {
            $eligible = true;
            $basis = 'sessions_completed_unsuccessful';
        }

        return [
            'eligible' => $eligible,
            'already_referred' => $alreadyReferred,
            'is_arbitration' => $isArbitration,
            'is_closed' => $isClosed,
            'is_settled' => $isSettled,
            'is_lapsed' => $isLapsed,
            'completed_mediation_count' => $completedMediationCount,
            'required_mediation_sessions' => $requiredSessions,
            'configured_max_sessions' => $medPolicy['configured_max_sessions'],
            'policy_conflict' => $medPolicy['has_policy_conflict'],
            'policy_conflict_message' => $medPolicy['policy_conflict_message'],
            'latest_qualifying_outcome' => $latestQualifyingOutcome,
            'has_finalized_unsuccessful' => $hasFinalizedUnsuccessful,
            'stage_record' => $stageRecord,
            'timer' => $timer,
            'basis' => $basis,
            'reason' => $reason,
        ];
    }

    /**
     * Builds summary for Mediation Stage.
     */
    private function buildMediationOverview(array $case, ?array $stageRecord, string $pbName): array
    {
        $caseId = (int) $case['case_id'];

        // Mediation Hearings (type Initial Hearing or Mediation)
        require_once __DIR__ . '/Hearing.php';
        $nonSupSql = Hearing::nonSupersededSql('h', 'next_h');
        $hStmt = $this->conn->prepare("
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                   h.complainant_attendance, h.respondent_attendance,
                   hm.minute_id, hm.status AS minutes_status, hm.session_outcome
            FROM hearings h
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
            WHERE h.case_id = ? AND h.hearing_type IN ('Initial Hearing', 'Mediation')
              AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
              AND {$nonSupSql}
            ORDER BY h.hearing_date ASC
        ");
        $hStmt->execute([$caseId]);
        $hearings = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        $sessionCount = count($hearings);
        $nextHearing = null;
        $latestHearingDate = null;
        $savedMinutesCount = 0;
        $latestMinutesStatus = 'Not Recorded';
        $latestMinutesSessionDate = null;

        $complainantAtt = ['present' => 0, 'absent' => 0, 'excused' => 0, 'pending' => 0];
        $respondentAtt = ['present' => 0, 'absent' => 0, 'excused' => 0, 'pending' => 0];
        $hasSettledSession = false;
        $hasFailedSession = false;

        $now = date('Y-m-d H:i:s');
        foreach ($hearings as $h) {
            $latestHearingDate = $h['hearing_date'];
            if (!$nextHearing && $h['hearing_date'] >= $now && in_array($h['status'], ['Scheduled', 'Rescheduled'], true)) {
                $nextHearing = [
                    'hearing_id' => (int) $h['hearing_id'],
                    'hearing_date' => $h['hearing_date'],
                    'venue' => $h['venue'],
                    'hearing_type' => $h['hearing_type']
                ];
            }

            if (!empty($h['minute_id'])) {
                $savedMinutesCount++;
                $latestMinutesStatus = $h['minutes_status'] ?: 'Draft';
                $latestMinutesSessionDate = $h['hearing_date'];
                if ($h['session_outcome'] === 'Settled') {
                    $hasSettledSession = true;
                } elseif (in_array($h['session_outcome'], ['Failed', 'Elevate to Pangkat'], true)) {
                    $hasFailedSession = true;
                }
            }
        }

        // Aggregate party attendance counts for mediation hearings
        if ($sessionCount > 0) {
            $attStmt = $this->conn->prepare("
                SELECT cp.party_type, ha.attendance_status, COUNT(*) as cnt
                FROM hearing_attendance ha
                INNER JOIN hearings h ON h.hearing_id = ha.hearing_id
                INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
                WHERE h.case_id = ? AND h.hearing_type IN ('Initial Hearing', 'Mediation')
                  AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                  AND {$nonSupSql}
                GROUP BY cp.party_type, ha.attendance_status
            ");
            $attStmt->execute([(int) $case['complaint_id'], $caseId]);
            while ($attRow = $attStmt->fetch(PDO::FETCH_ASSOC)) {
                $pType = $attRow['party_type'];
                $st = strtolower($attRow['attendance_status']);
                $cnt = (int) $attRow['cnt'];
                if ($pType === 'Complainant') {
                    if (isset($complainantAtt[$st])) $complainantAtt[$st] += $cnt;
                    else $complainantAtt['pending'] += $cnt;
                } elseif ($pType === 'Respondent') {
                    if (isset($respondentAtt[$st])) $respondentAtt[$st] += $cnt;
                    else $respondentAtt['pending'] += $cnt;
                }
            }
        }

        // Notices/Summons check for Mediation
        $docStmt = $this->conn->prepare("
            SELECT COUNT(*) FROM generated_documents gd
            INNER JOIN document_templates dt ON dt.template_id = gd.template_id
            WHERE gd.case_id = ? AND dt.template_name IN ('KP Form 8', 'KP Form 9')
        ");
        $docStmt->execute([$caseId]);
        $noticeCount = (int) $docStmt->fetchColumn();

        // Count qualifying finalized completed mediation sessions & shared eligibility
        $eligibility = self::evaluateMediationReferralEligibility($this->conn, $caseId, $case);
        $completedMediationCount = $eligibility['completed_mediation_count'];
        $isLapsed = $eligibility['is_lapsed'];
        $hasSettledSession = $hasSettledSession || $eligibility['is_settled'];
        $hasFinalizedUnsuccessful = $eligibility['has_finalized_unsuccessful'];
        $canProceedToPangkat = $eligibility['eligible'];
        $pangkatLockReason = $eligibility['reason'];

        // Determine Mediation Status & Outcome
        $stageStatus = 'Not Started';
        $outcome = $stageRecord['outcome'] ?? 'Pending';
        $outcomeRemarks = $stageRecord['outcome_remarks'] ?? null;

        if (!empty($eligibility['is_settled']) || $case['case_status'] === 'Settled' || $outcome === 'Settled') {
            $stageStatus = 'Completed';
            $outcome = 'Settled';
        } elseif (!empty($eligibility['already_referred']) || in_array($case['case_status'], ['Conciliation', 'CFA Issued', 'Dismissed'], true)) {
            $stageStatus = 'Referred to Pangkat';
            $outcome = 'Referred to Pangkat';
        } elseif ($canProceedToPangkat) {
            $stageStatus = 'Ready for Referral';
            $outcome = 'Unsuccessful';
        } elseif ($stageRecord && !empty($stageRecord['stage_status']) && !in_array($stageRecord['stage_status'], ['Not Started', 'Pending', 'In Progress', 'Awaiting Outcome', 'Ready for Referral'], true)) {
            $stageStatus = $stageRecord['stage_status'];
        } elseif ($completedMediationCount >= ($eligibility['required_mediation_sessions'] ?? 2) && !$hasFinalizedUnsuccessful) {
            $stageStatus = 'Awaiting Outcome';
        } elseif ($sessionCount > 0) {
            $stageStatus = 'In Progress';
            if ($completedMediationCount === 1) {
                $outcome = '1st Mediation Unresolved';
            }
        }

        // Attendance Finalized status
        $attendanceFinalized = ($sessionCount > 0 && ($complainantAtt['present'] + $complainantAtt['absent'] + $complainantAtt['excused']) > 0);

        $canBookNextMediation = (
            $completedMediationCount >= 1
            && $completedMediationCount < ($eligibility['configured_max_sessions'] ?? 2)
            && !$hasSettledSession
            && empty($eligibility['already_referred'])
            && empty($eligibility['is_closed'])
            && empty($eligibility['is_arbitration'])
        );

        // Determine next permitted canonical action for workspace
        $hasActiveScheduledHearing = false;
        foreach ($hearings as $mh) {
            if (in_array($mh['status'], ['Scheduled', 'Pending'], true)) {
                $hasActiveScheduledHearing = true;
                break;
            }
        }

        $latestHearing = !empty($hearings) ? end($hearings) : null;
        $latestCompAtt = $latestHearing['complainant_attendance'] ?? null;
        $latestRespAtt = $latestHearing['respondent_attendance'] ?? null;

        $nextPermittedAction = [
            'action' => 'Schedule Mediation 1',
            'description' => 'Schedule the initial mediation session with the Punong Barangay (KP Form 8 / KP Form 9).',
            'action_type' => 'schedule_hearing',
            'badge' => 'badge-primary'
        ];

        if ($case['case_status'] === 'Settled' || $outcome === 'Settled' || $hasSettledSession) {
            $nextPermittedAction = [
                'action' => 'Amicable Settlement Reached',
                'description' => 'Dispute resolved amicably. Monitor settlement compliance or close case.',
                'action_type' => 'settlement_reached',
                'badge' => 'badge-success'
            ];
        } elseif ($case['case_status'] === 'Conciliation' || $stageStatus === 'Referred to Pangkat') {
            $nextPermittedAction = [
                'action' => 'Convene Pangkat Conciliation',
                'description' => 'Mediation concluded. Dispute elevated to Pangkat ng Tagapagkasundo panel.',
                'action_type' => 'pangkat_convened',
                'badge' => 'badge-info'
            ];
        } elseif ($sessionCount === 0) {
            $nextPermittedAction = [
                'action' => 'Schedule Mediation 1',
                'description' => 'Book the 1st mediation hearing session with the Punong Barangay.',
                'action_type' => 'schedule_hearing',
                'badge' => 'badge-primary'
            ];
        } elseif ($hasActiveScheduledHearing) {
            $nextPermittedAction = [
                'action' => 'Conduct Session & Record Attendance',
                'description' => 'An active hearing is scheduled. Verify summons service and record party attendance.',
                'action_type' => 'record_attendance',
                'badge' => 'badge-warning'
            ];
        } elseif ($latestCompAtt === 'Absent') {
            $nextPermittedAction = [
                'action' => 'Issue Show Cause Order (KP Form 18)',
                'description' => 'Complainant was absent without verified excuse. Require justification or dismiss complaint.',
                'action_type' => 'show_cause',
                'badge' => 'badge-danger'
            ];
        } elseif ($latestRespAtt === 'Absent') {
            $nextPermittedAction = [
                'action' => 'Issue Show Cause Notice (KP Form 19)',
                'description' => 'Respondent was absent without verified excuse. Require justification or note CFA eligibility.',
                'action_type' => 'show_cause',
                'badge' => 'badge-danger'
            ];
        } elseif ($completedMediationCount === 1) {
            $nextPermittedAction = [
                'action' => 'Schedule Mediation 2',
                'description' => 'Mediation 1 concluded without settlement. Proceed to second (final) mediation attempt.',
                'action_type' => 'schedule_hearing',
                'badge' => 'badge-primary'
            ];
        } elseif ($canProceedToPangkat) {
            $nextPermittedAction = [
                'action' => 'Elevate to Pangkat (KP Form 10)',
                'description' => 'Mediation eligibility satisfied. Refer dispute to Pangkat panel for conciliation.',
                'action_type' => 'elevate_pangkat',
                'badge' => 'badge-warning'
            ];
        } elseif (!empty($eligibility['policy_conflict'])) {
            $nextPermittedAction = [
                'action' => 'Policy Conflict: Single-Session Cap vs 2-Session Referral',
                'description' => $eligibility['policy_conflict_message'],
                'action_type' => 'policy_conflict',
                'badge' => 'badge-warning'
            ];
        }

        require_once __DIR__ . '/CFA.php';
        $cfaElig = (new CFA($this->conn))->determineCfaEligibility($caseId);

        return [
            'stage_type' => 'Mediation',
            'stage_title' => '7. Mediation',
            'stage_subtitle' => 'Punong Barangay · Initial dispute resolution',
            'stage_status' => $stageStatus,
            'stage_badge_class' => self::getBadgeClass($stageStatus),
            'presiding_officer' => $pbName,
            'sessions_count' => $sessionCount,
            'completed_mediation_count' => $completedMediationCount,
            'required_mediation_sessions' => $eligibility['required_mediation_sessions'] ?? 2,
            'configured_max_sessions' => $eligibility['configured_max_sessions'] ?? 2,
            'policy_conflict' => !empty($eligibility['policy_conflict']),
            'policy_conflict_message' => $eligibility['policy_conflict_message'] ?? null,
            'current_mediation_session' => min(2, max(1, $completedMediationCount + 1)),
            'next_hearing' => $nextHearing,
            'latest_hearing_date' => $latestHearingDate,
            'attendance_summary' => [
                'complainant' => $complainantAtt,
                'respondent' => $respondentAtt,
                'is_finalized' => $attendanceFinalized,
                'status_label' => $attendanceFinalized ? 'Attendance Recorded' : ($sessionCount > 0 ? 'Pending Verification' : 'No Sessions Yet')
            ],
            'minutes_summary' => [
                'count' => $savedMinutesCount,
                'latest_status' => $latestMinutesStatus,
                'latest_session_date' => $latestMinutesSessionDate
            ],
            'next_permitted_action' => $nextPermittedAction,
            'documents_and_actions' => [
                'can_record_attendance' => ($sessionCount > 0),
                'can_add_minutes' => ($sessionCount > 0),
                'can_print_notice' => ($noticeCount > 0 || $sessionCount > 0),
                'can_generate_settlement' => ($hasSettledSession || $outcome === 'Settled' || $case['case_status'] === 'Settled'),
                'can_record_outcome' => ($sessionCount > 0),
                'can_proceed_to_pangkat' => $canProceedToPangkat,
                'pangkat_lock_reason' => $pangkatLockReason,
                'policy_conflict' => !empty($eligibility['policy_conflict']),
                'policy_conflict_message' => $eligibility['policy_conflict_message'] ?? null,
                'can_book_next_mediation' => $canBookNextMediation,
                'next_mediation_session_number' => min(2, $completedMediationCount + 1),
                'completed_mediation_count' => $completedMediationCount,
                'can_issue_cfa' => !empty($cfaElig['eligible']),
                'next_permitted_action' => $nextPermittedAction,
            ],
            'cfa_eligibility' => $cfaElig,
            'outcome' => $outcome,
            'outcome_remarks' => $outcomeRemarks,
            'referral_date' => $stageRecord['referral_date'] ?? null,
            'referring_officer_name' => $stageRecord['referring_officer_name'] ?? $pbName,
            'referral_reason' => $stageRecord['referral_reason'] ?? null,
            'records_transmitted' => (bool) ($stageRecord['records_transmitted'] ?? 0)
        ];
    }

    /**
     * Builds summary for Pangkat Conciliation Stage.
     */
    private function buildConciliationOverview(array $case, ?array $stageRecord, array $medStage): array
    {
        $caseId = (int) $case['case_id'];

        // Determine if Locked
        // Must unlock when:
        // 1. case_status is 'Conciliation', OR
        // 2. Mediation stage is 'Referred to Pangkat', OR
        // 3. stageRecord status is not 'Locked'
        $isUnlocked = (
            $case['case_status'] === 'Conciliation' ||
            $case['case_status'] === 'Settled' ||
            $medStage['stage_status'] === 'Referred to Pangkat' ||
            ($stageRecord && in_array($stageRecord['stage_status'], ['Not Started', 'In Progress', 'Awaiting Outcome', 'Completed'], true))
        );

        $isReadyForReferral = !empty($medStage['documents_and_actions']['can_proceed_to_pangkat'])
            || ($medStage['stage_status'] ?? '') === 'Ready for Referral';

        $stageStatus = 'Locked';
        if (!$isUnlocked) {
            $stageStatus = $isReadyForReferral ? 'Ready for Referral' : 'Locked';
        } elseif ($stageRecord && !empty($stageRecord['stage_status']) && !in_array($stageRecord['stage_status'], ['Locked', 'In Progress', 'Not Started'], true)) {
            $stageStatus = $stageRecord['stage_status'];
        } else {
            if ($case['case_status'] === 'Settled') {
                $stageStatus = 'Completed';
            } elseif ($case['case_status'] === 'Conciliation') {
                $stageStatus = ($stageRecord && ($stageRecord['stage_status'] ?? '') === 'In Progress') ? 'In Progress' : 'Not Started';
            } else {
                $stageStatus = 'Not Started';
            }
        }

        // Pangkat Group & Members Assignment
        $pangkatInfo = $this->getPangkatAssignment($caseId);

        // Conciliation Hearings
        $hStmt = $this->conn->prepare("
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                   h.complainant_attendance, h.respondent_attendance,
                   hm.minute_id, hm.status AS minutes_status, hm.session_outcome
            FROM hearings h
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
            WHERE h.case_id = ? AND h.hearing_type = 'Conciliation'
              AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
              AND " . Hearing::nonSupersededSql('h', 'next_h') . "
            ORDER BY h.hearing_date ASC
        ");
        $hStmt->execute([$caseId]);
        $hearings = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        $sessionCount = count($hearings);
        $nextHearing = null;
        $latestHearingDate = null;
        $savedMinutesCount = 0;
        $latestMinutesStatus = 'Not Recorded';
        $latestMinutesSessionDate = null;
        $hasSettledSession = false;
        $hasFailedSession = false;

        $now = date('Y-m-d H:i:s');
        foreach ($hearings as $h) {
            $latestHearingDate = $h['hearing_date'];
            if (!$nextHearing && $h['hearing_date'] >= $now && in_array($h['status'], ['Scheduled', 'Rescheduled'], true)) {
                $nextHearing = [
                    'hearing_id' => (int) $h['hearing_id'],
                    'hearing_date' => $h['hearing_date'],
                    'venue' => $h['venue'],
                    'hearing_type' => $h['hearing_type']
                ];
            }

            if (!empty($h['minute_id'])) {
                $savedMinutesCount++;
                $latestMinutesStatus = $h['minutes_status'] ?: 'Draft';
                $latestMinutesSessionDate = $h['hearing_date'];
                if ($latestMinutesStatus === 'Finalized') {
                    if ($h['session_outcome'] === 'Settled') {
                        $hasSettledSession = true;
                    } elseif (in_array($h['session_outcome'], ['Failed', 'Pending CFA'], true)) {
                        $hasFailedSession = true;
                    }
                }
            }
        }

        // Party attendance counts for conciliation hearings
        $complainantAtt = ['present' => 0, 'absent' => 0, 'excused' => 0, 'pending' => 0];
        $respondentAtt = ['present' => 0, 'absent' => 0, 'excused' => 0, 'pending' => 0];

        if ($sessionCount > 0) {
            $attStmt = $this->conn->prepare("
                SELECT cp.party_type, ha.attendance_status, COUNT(*) as cnt
                FROM hearing_attendance ha
                INNER JOIN hearings h ON h.hearing_id = ha.hearing_id
                INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
                WHERE h.case_id = ? AND h.hearing_type = 'Conciliation'
                  AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                  AND NOT EXISTS (SELECT 1 FROM hearings next_h WHERE next_h.rescheduled_from_id = h.hearing_id)
                GROUP BY cp.party_type, ha.attendance_status
            ");
            $attStmt->execute([(int) $case['complaint_id'], $caseId]);
            while ($attRow = $attStmt->fetch(PDO::FETCH_ASSOC)) {
                $pType = $attRow['party_type'];
                $st = strtolower($attRow['attendance_status']);
                $cnt = (int) $attRow['cnt'];
                if ($pType === 'Complainant') {
                    if (isset($complainantAtt[$st])) $complainantAtt[$st] += $cnt;
                    else $complainantAtt['pending'] += $cnt;
                } elseif ($pType === 'Respondent') {
                    if (isset($respondentAtt[$st])) $respondentAtt[$st] += $cnt;
                    else $respondentAtt['pending'] += $cnt;
                }
            }
        }

        $attendanceFinalized = ($sessionCount > 0 && ($complainantAtt['present'] + $complainantAtt['absent'] + $complainantAtt['excused']) > 0);

        // Statutory Conciliation Timing (RA 7160 Sec. 410(c))
        require_once __DIR__ . '/../services/MediationDeadlineService.php';
        $deadlineSvc = new MediationDeadlineService($this->conn);
        $conStatus = $deadlineSvc->computeConciliationStatus($caseId);

        $conciliationDeadline = null;
        if ($conStatus['is_constituted']) {
            $conciliationDeadline = [
                'is_convened' => $conStatus['is_convened'],
                'convened_date' => $conStatus['convened_date'],
                'convening_deadline' => $conStatus['convening_deadline'],
                'convening_overdue' => $conStatus['convening_overdue'],
                'convening_days_left' => $conStatus['convening_days_left'],
                'target_date' => $conStatus['conciliation_deadline'],
                'formation_date' => $conStatus['formation_date'],
                'days_remaining' => $conStatus['days_remaining'],
                'is_lapsed' => $conStatus['is_lapsed'],
                'is_extended' => $conStatus['is_extended'],
                'extended_days' => $conStatus['extended_days'],
                'extension_type' => $conStatus['extension_type'],
                'pending_challenges_count' => $conStatus['pending_challenges_count']
            ];
        }

        $outcome = $stageRecord['outcome'] ?? 'Pending';
        if ($case['case_status'] === 'Settled' || $hasSettledSession) {
            $outcome = 'Settled';
            $stageStatus = 'Completed';
        }

        require_once __DIR__ . '/CFA.php';
        $cfaElig = (new CFA($this->conn))->determineCfaEligibility($caseId);
        $canIssueCfa = $isUnlocked && !empty($cfaElig['eligible']) && in_array('KP Form 20', $cfaElig['available_forms'] ?? [], true);

        $isBookingPending = ($isUnlocked && $sessionCount === 0 && !in_array($case['case_status'], ['Settled', 'Dismissed', 'CFA Issued', 'Archived'], true));
        if ($isBookingPending && $stageStatus === 'Not Started') {
            // Keep Conciliation booking visibly pending until 1st Conciliation hearing is saved.
            $stageStatus = 'Not Started';
        }

        return [
            'stage_type' => 'Conciliation',
            'stage_title' => '8. Pangkat Conciliation',
            'stage_subtitle' => 'Pangkat ng Tagapagkasundo · Formal conciliation',
            'stage_status' => $stageStatus,
            'stage_badge_class' => self::getBadgeClass($stageStatus),
            'is_locked' => ($stageStatus === 'Locked'),
            'is_booking_pending' => $isBookingPending,
            'booking_status' => $sessionCount > 0 ? 'Scheduled' : ($isUnlocked ? 'Pending Scheduling' : 'Locked'),
            'lock_message' => 'Pangkat Conciliation is locked. Complete the mediation stage and record an authorized referral to unlock this workspace.',
            'pangkat_assignment' => $pangkatInfo,
            'sessions_count' => $sessionCount,
            'next_hearing' => $nextHearing,
            'latest_hearing_date' => $latestHearingDate,
            'attendance_summary' => [
                'complainant' => $complainantAtt,
                'respondent' => $respondentAtt,
                'is_finalized' => $attendanceFinalized,
                'status_label' => $attendanceFinalized ? 'Attendance Recorded' : ($sessionCount > 0 ? 'Pending Verification' : 'No Sessions Yet')
            ],
            'minutes_summary' => [
                'count' => $savedMinutesCount,
                'latest_status' => $latestMinutesStatus,
                'latest_session_date' => $latestMinutesSessionDate
            ],
            'documents_and_actions' => [
                'can_record_attendance' => ($isUnlocked && $sessionCount > 0),
                'can_add_minutes' => ($isUnlocked && $sessionCount > 0),
                'can_print_notice' => ($isUnlocked && ($sessionCount > 0 || $pangkatInfo['is_constituted'])),
                'can_record_outcome' => ($isUnlocked && $sessionCount > 0),
                'can_generate_settlement' => ($isUnlocked && ($hasSettledSession || $outcome === 'Settled' || $case['case_status'] === 'Settled')),
                'can_issue_cfa' => $canIssueCfa
            ],
            'cfa_eligibility' => $cfaElig,
            'deadline' => $conciliationDeadline,
            'outcome' => $outcome,
            'outcome_remarks' => $stageRecord['outcome_remarks'] ?? null
        ];
    }

    /**
     * Builds summary for Arbitration Stage.
     */
    private function buildArbitrationOverview(array $case, ?array $stageRecord, string $pbName): array
    {
        $caseId = (int) $case['case_id'];

        // Check if arbitration record exists
        $arbStmt = $this->conn->prepare("SELECT * FROM arbitration_records WHERE case_id = ? LIMIT 1");
        $arbStmt->execute([$caseId]);
        $arbRecord = $arbStmt->fetch(PDO::FETCH_ASSOC);

        $isUnlocked = (
            $case['case_status'] === 'Arbitration' ||
            !empty($arbRecord) ||
            ($stageRecord && in_array($stageRecord['stage_status'], ['Not Started', 'In Progress', 'Awaiting Outcome', 'Completed'], true))
        );

        $stageStatus = 'Locked';
        if (!$isUnlocked) {
            $stageStatus = 'Locked';
        } elseif ($stageRecord && !empty($stageRecord['stage_status']) && $stageRecord['stage_status'] !== 'Locked') {
            $stageStatus = $stageRecord['stage_status'];
        } else {
            if (!empty($arbRecord['award_date'])) {
                $stageStatus = 'Completed';
            } elseif ($case['case_status'] === 'Arbitration') {
                $stageStatus = 'In Progress';
            } else {
                $stageStatus = 'Not Started';
            }
        }

        // Arbitration Hearings
        $hStmt = $this->conn->prepare("
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                   h.complainant_attendance, h.respondent_attendance,
                   hm.minute_id, hm.status AS minutes_status, hm.session_outcome
            FROM hearings h
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
            WHERE h.case_id = ? AND h.hearing_type = 'Arbitration'
              AND h.status != 'Cancelled'
            ORDER BY h.hearing_date ASC
        ");
        $hStmt->execute([$caseId]);
        $hearings = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        $sessionCount = count($hearings);
        $nextHearing = null;
        $latestHearingDate = null;
        $savedMinutesCount = 0;
        $latestMinutesStatus = 'Not Recorded';
        $latestMinutesSessionDate = null;
        $hasAwardSession = false;

        $now = date('Y-m-d H:i:s');
        foreach ($hearings as $h) {
            $latestHearingDate = $h['hearing_date'];
            if (!$nextHearing && $h['hearing_date'] >= $now && in_array($h['status'], ['Scheduled', 'Rescheduled'], true)) {
                $nextHearing = [
                    'hearing_id' => (int) $h['hearing_id'],
                    'hearing_date' => $h['hearing_date'],
                    'venue' => $h['venue'],
                    'hearing_type' => $h['hearing_type']
                ];
            }

            if (!empty($h['minute_id'])) {
                $savedMinutesCount++;
                $latestMinutesStatus = $h['minutes_status'] ?: 'Draft';
                $latestMinutesSessionDate = $h['hearing_date'];
                if (in_array($h['session_outcome'], ['Arbitration Award', 'Settled'], true)) {
                    $hasAwardSession = true;
                }
            }
        }

        $complainantAtt = ['present' => 0, 'absent' => 0, 'excused' => 0, 'pending' => 0];
        $respondentAtt = ['present' => 0, 'absent' => 0, 'excused' => 0, 'pending' => 0];

        if ($sessionCount > 0) {
            $attStmt = $this->conn->prepare("
                SELECT cp.party_type, ha.attendance_status, COUNT(*) as cnt
                FROM hearing_attendance ha
                INNER JOIN hearings h ON h.hearing_id = ha.hearing_id
                INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
                WHERE h.case_id = ? AND h.hearing_type = 'Arbitration'
                GROUP BY cp.party_type, ha.attendance_status
            ");
            $attStmt->execute([(int) $case['complaint_id'], $caseId]);
            while ($attRow = $attStmt->fetch(PDO::FETCH_ASSOC)) {
                $pType = $attRow['party_type'];
                $st = strtolower($attRow['attendance_status']);
                $cnt = (int) $attRow['cnt'];
                if ($pType === 'Complainant') {
                    if (isset($complainantAtt[$st])) $complainantAtt[$st] += $cnt;
                    else $complainantAtt['pending'] += $cnt;
                } elseif ($pType === 'Respondent') {
                    if (isset($respondentAtt[$st])) $respondentAtt[$st] += $cnt;
                    else $respondentAtt['pending'] += $cnt;
                }
            }
        }

        $attendanceFinalized = ($sessionCount > 0 && ($complainantAtt['present'] + $complainantAtt['absent'] + $complainantAtt['excused']) > 0);
        $outcome = $stageRecord['outcome'] ?? (!empty($arbRecord['award_date']) ? 'Arbitration Award' : 'Pending');

        // Presiding Officer determination: Pangkat Chairman if Pangkat constituted, else Punong Barangay
        $arbitratorName = $stageRecord['presiding_officer'] ?? $pbName;
        $pChairStmt = $this->conn->prepare("
            SELECT TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS chairman_name
            FROM pangkat_members pm
            INNER JOIN pangkat_groups pg ON pg.pangkat_id = pm.pangkat_id
            INNER JOIN users u ON u.user_id = pm.member_id
            WHERE pg.case_id = ? AND pm.position = 'Chairman'
            LIMIT 1
        ");
        $pChairStmt->execute([$caseId]);
        $pChairName = $pChairStmt->fetchColumn();
        if ($pChairName) {
            $arbitratorName = $pChairName . ' (Pangkat Chairman)';
        }

        return [
            'stage_name' => 'Arbitration',
            'stage_description' => 'Binding dispute arbitration pursuant to KP Form 14 / Sec 413, RA 7160',
            'stage_status' => $stageStatus,
            'badge_class' => self::getBadgeClass($stageStatus),
            'is_locked' => !$isUnlocked,
            'presiding_officer' => $arbitratorName,
            'sessions_count' => $sessionCount,
            'next_hearing' => $nextHearing,
            'latest_hearing_date' => $latestHearingDate,
            'arbitration_record' => $arbRecord ?: null,
            'attendance_summary' => [
                'complainant' => $complainantAtt,
                'respondent' => $respondentAtt,
                'is_finalized' => $attendanceFinalized,
                'status_label' => $attendanceFinalized ? 'Attendance Recorded' : ($sessionCount > 0 ? 'Pending Verification' : 'No Sessions Yet')
            ],
            'minutes_summary' => [
                'count' => $savedMinutesCount,
                'latest_status' => $latestMinutesStatus,
                'latest_session_date' => $latestMinutesSessionDate
            ],
            'documents_and_actions' => [
                'can_record_attendance' => ($isUnlocked && $sessionCount > 0),
                'can_add_minutes' => ($isUnlocked && $sessionCount > 0),
                'can_record_outcome' => ($isUnlocked && $sessionCount > 0),
                'can_render_award' => ($isUnlocked && ($hasAwardSession || $sessionCount > 0))
            ],
            'outcome' => $outcome,
            'outcome_remarks' => $stageRecord['outcome_remarks'] ?? null
        ];
    }

    /**
     * Retrieves assigned Pangkat members and metadata.
     */
    public function getPangkatAssignment(int $caseId): array
    {
        $groupStmt = $this->conn->prepare("
            SELECT pg.*
            FROM pangkat_groups pg
            WHERE pg.case_id = ?
            LIMIT 1
        ");
        $groupStmt->execute([$caseId]);
        $group = $groupStmt->fetch(PDO::FETCH_ASSOC);

        // Resolve KP Forms 10, 11, 12 document IDs for this case
        $kp10DocId = null;
        $kp11DocId = null;
        $kp12DocId = null;
        try {
            $docsStmt = $this->conn->prepare("
                SELECT gd.document_id, dt.template_name
                FROM generated_documents gd
                INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                WHERE gd.case_id = ? AND dt.template_name IN ('KP Form 10', 'KP Form 11', 'KP Form 12')
                ORDER BY gd.document_id DESC
            ");
            $docsStmt->execute([$caseId]);
            $kpDocs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($kpDocs as $kd) {
                if ($kd['template_name'] === 'KP Form 10' && !$kp10DocId) $kp10DocId = (int)$kd['document_id'];
                elseif ($kd['template_name'] === 'KP Form 11' && !$kp11DocId) $kp11DocId = (int)$kd['document_id'];
                elseif ($kd['template_name'] === 'KP Form 12' && !$kp12DocId) $kp12DocId = (int)$kd['document_id'];
            }
            if (!$kp12DocId) {
                $hDocStmt = $this->conn->prepare("SELECT kp12_document_id FROM hearings WHERE case_id = ? AND kp12_document_id IS NOT NULL ORDER BY hearing_id DESC LIMIT 1");
                $hDocStmt->execute([$caseId]);
                $kp12DocId = (int)$hDocStmt->fetchColumn() ?: null;
            }
        } catch (Throwable $e) {}

        // Fetch members from pangkat_members joined with users
        $members = [];
        if ($group) {
            $memStmt = $this->conn->prepare("
                SELECT pm.position, pm.member_id, u.status,
                       TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS member_name,
                       u.email, u.contact_no AS contact_number
                FROM pangkat_members pm
                INNER JOIN users u ON u.user_id = pm.member_id
                WHERE pm.pangkat_id = ?
                ORDER BY FIELD(pm.position, 'Chairman', 'Secretary', 'Member')
            ");
            $memStmt->execute([(int) $group['pangkat_id']]);
            $members = $memStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fallback: check case_assignments if pangkat_members empty
        if (empty($members)) {
            $caStmt = $this->conn->prepare("
                SELECT ca.assignment_role, ca.member_id,
                       TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS member_name,
                       u.email, u.contact_no AS contact_number, ca.assigned_date
                FROM case_assignments ca
                INNER JOIN users u ON u.user_id = ca.member_id
                INNER JOIN roles r ON r.role_id = u.role_id
                WHERE ca.case_id = ? AND r.role_name = 'Lupon Member'
                ORDER BY FIELD(ca.assignment_role, 'Head', 'Secretary', 'Member')
            ");
            $caStmt->execute([$caseId]);
            $caRows = $caStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($caRows as $row) {
                $pos = $row['assignment_role'] === 'Head' ? 'Chairman' : $row['assignment_role'];
                $members[] = [
                    'position' => $pos,
                    'member_id' => (int) $row['member_id'],
                    'member_name' => $row['member_name'],
                    'email' => $row['email'],
                    'contact_number' => $row['contact_number'],
                    'status' => 'Active'
                ];
            }
        }

        $activeMembers = array_filter($members, fn($m) => ($m['status'] ?? 'Active') === 'Active' || ($m['status'] ?? '') === 'Challenged');
        $isConstituted = (count($activeMembers) >= 2);
        $chairperson = null;
        $secretary = null;
        $regularMember = null;

        foreach ($activeMembers as $m) {
            if ($m['position'] === 'Chairman' && !$chairperson) $chairperson = $m;
            elseif ($m['position'] === 'Secretary' && !$secretary) $secretary = $m;
            elseif (!$regularMember) $regularMember = $m;
        }

        // Challenges & Extensions (Gracefully handled if optional tables not yet created)
        $challenges = [];
        $extensions = [];
        if ($group) {
            try {
                $cStmt = $this->conn->prepare("
                    SELECT pc.*,
                           TRIM(CONCAT_WS(' ', u_ch.first_name, u_ch.middle_name, u_ch.last_name)) AS challenged_name,
                           TRIM(CONCAT_WS(' ', u_rep.first_name, u_rep.middle_name, u_rep.last_name)) AS replacement_name
                    FROM pangkat_challenges pc
                    INNER JOIN users u_ch ON u_ch.user_id = pc.challenged_member_id
                    LEFT JOIN users u_rep ON u_rep.user_id = pc.replacement_member_id
                    WHERE pc.case_id = ?
                    ORDER BY pc.challenge_id DESC
                ");
                $cStmt->execute([$caseId]);
                $challenges = $cStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $challenges = [];
            }

            try {
                $eStmt = $this->conn->prepare("
                    SELECT pe.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS approver_name
                    FROM pangkat_extensions pe
                    INNER JOIN users u ON u.user_id = pe.approved_by
                    WHERE pe.case_id = ?
                    ORDER BY pe.extension_id DESC
                ");
                $eStmt->execute([$caseId]);
                $extensions = $eStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $extensions = [];
            }
        }

        return [
            'is_constituted' => $isConstituted,
            'formation_date' => $group['formation_date'] ?? null,
            'convening_deadline' => $group['convening_deadline'] ?? null,
            'convened_date' => $group['convened_date'] ?? null,
            'conciliation_deadline' => $group['conciliation_deadline'] ?? null,
            'is_extended' => !empty($group['is_extended']),
            'extended_days' => (int) ($group['extended_days'] ?? 0),
            'extension_type' => $group['extension_type'] ?? 'None',
            'selection_method' => $group['selection_method'] ?? 'Party Agreement',
            'selection_stage' => $group['selection_stage'] ?? 'Constituted',
            'party_agreement_failed' => !empty($group['party_agreement_failed']),
            'party_agreement_failure_reason' => $group['party_agreement_failure_reason'] ?? null,
            'selection_notes' => $group['selection_notes'] ?? null,
            'quorum_size' => (int) ($group['quorum_size'] ?? 3),
            'members' => array_values($activeMembers),
            'all_members' => $members,
            'chairperson' => $chairperson,
            'secretary' => $secretary,
            'member' => $regularMember,
            'challenges' => $challenges,
            'extensions' => $extensions,
            'kp10_document_id' => $kp10DocId,
            'kp11_document_id' => $kp11DocId,
            'kp12_document_id' => $kp12DocId,
            'assignment_status' => $isConstituted ? sprintf('Constituted (%d Members)', count($activeMembers)) : 'Pending Formation'
        ];
    }

    /**
     * Returns hearings and session details for a specific stage.
     */
    public function getSessions(int $caseId, string $stageType): array
    {
        $types = match ($stageType) {
            'Mediation' => ['Initial Hearing', 'Mediation'],
            'Conciliation' => ['Conciliation'],
            'Arbitration' => ['Arbitration'],
            default => ['Initial Hearing', 'Mediation']
        };

        $inClause = implode("','", $types);

        $stmt = $this->conn->prepare("
            SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                   h.queue_status, h.actual_start_time, h.actual_end_time, h.actual_duration_minutes,
                   h.mediation_attempt, h.attempt_exception_reason, h.cancellation_category, h.rescheduled_from_id,
                   (SELECT next_h.hearing_id
                    FROM hearings next_h
                    WHERE next_h.rescheduled_from_id = h.hearing_id
                      AND " . Hearing::operativeReplacementCondition('next_h') . "
                    ORDER BY next_h.hearing_date ASC, next_h.hearing_id ASC
                    LIMIT 1) AS rescheduled_to_id,
                   h.complainant_attendance, h.respondent_attendance, h.remarks,
                   h.presiding_officer_id, h.substitute_presider_id, h.substitute_reason, h.parties_consent_to_substitute,
                   h.attendance_recorded_at, h.attendance_notes,
                   TRIM(CONCAT_WS(' ', po.first_name, po.middle_name, po.last_name)) AS presiding_officer_name,
                   TRIM(CONCAT_WS(' ', sp.first_name, sp.middle_name, sp.last_name)) AS substitute_presider_name,
                   hm.minute_id, hm.status AS minutes_status, hm.session_outcome, hm.outcome_remarks,
                   hm.opening_conducted, hm.identity_verified, hm.complaint_reviewed,
                   hm.complainant_statement, hm.respondent_statement, hm.main_dispute_identified,
                   hm.settlement_discussion_notes, hm.caucus_conducted, hm.caucus_notes,
                   hm.new_proposal, hm.counteroffer, hm.actual_end_time,
                   hm.agenda_topics, hm.agreements_action_items, hm.unresolved_issues,
                   TRIM(CONCAT_WS(' ', rec_u.first_name, rec_u.middle_name, rec_u.last_name)) AS recorded_by_name,
                   TRIM(CONCAT_WS(' ', fin_u.first_name, fin_u.middle_name, fin_u.last_name)) AS finalized_by_name,
                   hm.finalized_at
            FROM hearings h
            LEFT JOIN users po ON po.user_id = h.presiding_officer_id
            LEFT JOIN users sp ON sp.user_id = h.substitute_presider_id
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
            LEFT JOIN users rec_u ON rec_u.user_id = hm.recorded_by
            LEFT JOIN users fin_u ON fin_u.user_id = hm.finalized_by
            WHERE h.case_id = ? AND h.hearing_type IN ('$inClause')
              AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
              AND " . Hearing::nonSupersededSql('h', 'next_h') . "
            ORDER BY h.hearing_date ASC
        ");
        $stmt->execute([$caseId]);
        $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $partyStmt = $this->conn->prepare("
            SELECT cp.resident_id, cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name,
                   r.address,
                   ha.attendance_status, ha.is_justified, ha.justification_reason,
                   ha.remarks AS party_remarks, ha.verification_status, ha.determination_notes
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            INNER JOIN cases c ON c.complaint_id = cp.complaint_id
            LEFT JOIN hearing_attendance ha ON ha.hearing_id = ? AND ha.resident_id = cp.resident_id
            WHERE c.case_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name
        ");

        // Build a resolved attempt map rather than relying on result-array
        // positions. An office-cancelled hearing did not take place, so its
        // replacement remains the same statutory mediation attempt.
        $attemptMap = [];
        $rescheduledMap = [];
        foreach ($hearings as $h) {
            if (!empty($h['rescheduled_from_id'])) {
                $rescheduledMap[(int)$h['hearing_id']] = (int)$h['rescheduled_from_id'];
            }
        }

        $runningAttempt = 1;
        $resolveAttempt = function (int $hearingId, array $visited = []) use (&$resolveAttempt, &$attemptMap, $rescheduledMap, $hearings, &$runningAttempt): int {
            if (isset($attemptMap[$hearingId])) {
                return $attemptMap[$hearingId];
            }
            if (isset($visited[$hearingId])) {
                return max(1, $runningAttempt);
            }
            $visited[$hearingId] = true;
            $hearing = null;
            foreach ($hearings as $candidate) {
                if ((int) $candidate['hearing_id'] === $hearingId) {
                    $hearing = $candidate;
                    break;
                }
            }
            if (!$hearing) {
                return max(1, $runningAttempt);
            }

            $parentHId = $rescheduledMap[$hearingId] ?? null;
            if ($parentHId) {
                $attemptNum = $resolveAttempt($parentHId, $visited);
            } elseif (!empty($hearing['mediation_attempt'])) {
                $attemptNum = max(1, (int) $hearing['mediation_attempt']);
            } else {
                $attemptNum = max(1, $runningAttempt);
            }

            $attemptMap[$hearingId] = $attemptNum;
            return $attemptNum;
        };

        foreach ($hearings as $h) {
            $hId = (int) $h['hearing_id'];
            $attemptNum = $resolveAttempt($hId);

            if (!in_array($h['status'], ['Cancelled', 'Office Cancelled'], true)) {
                $runningAttempt = max($runningAttempt, $attemptNum + 1);
            }
        }

        require_once __DIR__ . '/../services/SummonDeliveryService.php';
        $deliveryService = new SummonDeliveryService($this->conn);

        $sessions = [];
        $index = 1;
        foreach ($hearings as $h) {
            $hId = (int) $h['hearing_id'];
            $partyStmt->execute([$hId, $caseId]);
            $parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($parties as &$partyRow) {
                $verif = $deliveryService->verifyRecipientServiceForHearing($hId, (int) $partyRow['resident_id'], (string) ($partyRow['party_type'] ?? 'Respondent'));
                $partyRow['service_verified'] = !empty($verif['verified']);
                $partyRow['service_confirmed_count'] = !empty($verif['verified']) ? 1 : 0;
                $partyRow['service_status'] = $verif['status'] ?? 'Not Served';
                $partyRow['service_verification'] = $verif;
            }
            unset($partyRow);

            // Summons-return gate: attendance check-in unlocks only after an
            // appropriate Officer's Return (successful service) is logged for all required parties.
            $attendanceGate = ['allowed' => true, 'code' => 'NOT_MEDIATION', 'message' => ''];
            if ($stageType === 'Mediation') {
                try {
                    require_once __DIR__ . '/../services/SummonServiceGate.php';
                    $gateRes = (new SummonServiceGate($this->conn))->forHearing($hId);
                    $attendanceGate = [
                        'allowed' => (bool) ($gateRes['allowed'] ?? false),
                        'code' => (string) ($gateRes['code'] ?? ''),
                        'message' => (string) ($gateRes['message'] ?? ''),
                    ];
                } catch (Throwable $gateErr) {
                    error_log('CaseStage attendance gate notice: ' . $gateErr->getMessage());
                    $attendanceGate = [
                        'allowed' => false,
                        'code' => 'SERVICE_NOT_CONFIRMED',
                        'message' => 'Record the Summons Return before checking in parties.',
                    ];
                }
            }
            $proofStatus = (!empty($attendanceGate['allowed']) || ($stageType !== 'Mediation')) ? 'Served' : 'Pending Delivery';

                        $attemptNum = $attemptMap[$hId] ?? (!empty($h['mediation_attempt']) ? (int) $h['mediation_attempt'] : $index);
            $sessionNumber = $stageType === 'Mediation'
                ? sprintf('Mediation %d', $attemptNum)
                : sprintf('%s Session %d', $stageType, $attemptNum);

            $sessions[] = [
                'session_index' => $attemptNum,
                'session_number' => $sessionNumber,
                'mediation_attempt' => $attemptNum,
                'attempt_label' => $sessionNumber,
                'attempt_exception_reason' => $h['attempt_exception_reason'] ?? null,
                'cancellation_category' => $h['cancellation_category'] ?? null,
                'rescheduled_from_id' => $h['rescheduled_from_id'] ? (int) $h['rescheduled_from_id'] : null,
                'rescheduled_to_id' => $h['rescheduled_to_id'] ? (int) $h['rescheduled_to_id'] : null,
                'hearing_id' => $hId,
                'hearing_type' => $h['hearing_type'],
                'hearing_date' => $h['hearing_date'],
                'venue' => $h['venue'],
                'parties' => $parties,
                'status' => $h['status'],
                'queue_status' => $h['queue_status'] ?? 'Waiting',
                'actual_start_time' => $h['actual_start_time'] ?? null,
                'actual_end_time' => $h['actual_end_time'] ?? null,
                'actual_duration_minutes' => $h['actual_duration_minutes'] ? (int) $h['actual_duration_minutes'] : null,
                'complainant_attendance' => $h['complainant_attendance'] ?: 'Pending',
                'respondent_attendance' => $h['respondent_attendance'] ?: 'Pending',
                'presiding_officer_id' => $h['presiding_officer_id'] ? (int) $h['presiding_officer_id'] : null,
                'presiding_officer_name' => $h['presiding_officer_name'] ?: 'Punong Barangay / Lupon Chairman',
                'substitute_presider_id' => $h['substitute_presider_id'] ? (int) $h['substitute_presider_id'] : null,
                'substitute_presider_name' => $h['substitute_presider_name'],
                'substitute_reason' => $h['substitute_reason'],
                'parties_consent_to_substitute' => (int) ($h['parties_consent_to_substitute'] ?? 0),
                'attendance_notes' => $h['attendance_notes'],
                'remarks' => $h['remarks'],
                'proof_of_service_status' => $proofStatus,
                'attendance_gate' => $attendanceGate,
                'minutes_id' => $h['minute_id'] ? (int) $h['minute_id'] : null,
                'minutes_status' => $h['minutes_status'] ?: 'Not Recorded',
                'session_outcome' => $h['session_outcome'] ?: 'Pending',
                'outcome_remarks' => $h['outcome_remarks'],
                'opening_conducted' => (int) ($h['opening_conducted'] ?? 0),
                'identity_verified' => (int) ($h['identity_verified'] ?? 0),
                'complaint_reviewed' => (int) ($h['complaint_reviewed'] ?? 0),
                'complainant_statement' => $h['complainant_statement'] ?? '',
                'respondent_statement' => $h['respondent_statement'] ?? '',
                'main_dispute_identified' => $h['main_dispute_identified'] ?? '',
                'new_proposal' => $h['new_proposal'] ?? '',
                'counteroffer' => $h['counteroffer'] ?? '',
                'caucus_conducted' => (int) ($h['caucus_conducted'] ?? 0),
                'caucus_notes' => $h['caucus_notes'] ?? '',
                'settlement_discussion_notes' => $h['settlement_discussion_notes'] ?? '',
                'actual_end_time' => $h['actual_end_time'] ?? null,
                'agenda_topics' => $h['agenda_topics'],
                'agreements_action_items' => $h['agreements_action_items'],
                'unresolved_issues' => $h['unresolved_issues'],
                'recorded_by_name' => $h['recorded_by_name'],
                'finalized_by_name' => $h['finalized_by_name'],
                'finalized_at' => $h['finalized_at'],
                'parties' => $parties
            ];

            // Fetch any linked show-cause hearings spawned from this session
            $scStmt = $this->conn->prepare("
                SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                       sce.evaluation_id, sce.party_type AS evaluated_party, sce.is_justified, sce.action_taken,
                       sce.justification_category, sce.justification_notes
                FROM hearings h
                LEFT JOIN show_cause_evaluations sce ON sce.hearing_id = h.hearing_id
                WHERE h.case_id = ? AND h.rescheduled_from_id = ?
                  AND h.hearing_type IN ('Show Cause (Complainant)', 'Show Cause (Respondent)')
                ORDER BY h.hearing_date ASC
            ");
            $scStmt->execute([$caseId, $hId]);
            $sessions[count($sessions) - 1]['show_cause_hearings'] = $scStmt->fetchAll(PDO::FETCH_ASSOC);

            $index++;
        }

        return $sessions;
    }

    /**
     * Returns show-cause hearings and their evaluations for a specific stage (e.g., Conciliation).
     */
    public function getShowCauseHearings(int $caseId, string $stageType = 'Conciliation'): array
    {
        require_once __DIR__ . '/../services/ShowCauseService.php';
        return (new ShowCauseService($this->conn))->getShowCauseWorkspaceItems($caseId, $stageType);
    }

    /**
     * Records stage procedural outcome, including a voluntary agreement to arbitrate.
     */
    public function recordOutcome(int $caseId, string $stageType, string $outcome, ?string $remarks, int $userId): array
    {
        if (!in_array($stageType, ['Mediation', 'Conciliation', 'Arbitration'], true)) {
            return ['success' => false, 'message' => 'Invalid stage type.'];
        }

        $allowedOutcomes = ['Settled', 'Unsuccessful', 'Repudiated', 'Dismissed', 'Arbitration', 'Arbitration Award'];
        if (!in_array($outcome, $allowedOutcomes, true)) {
            return ['success' => false, 'message' => 'Please select a valid stage outcome.'];
        }

        $overview = $this->getStageOverview($caseId);
        if (!$overview) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        if (in_array((string) ($overview['case']['case_status'] ?? ''), ['Settled', 'Resolved', 'Completed', 'Dismissed', 'DISMISSED_BARRED', 'Archived', 'CFA Issued', 'Repudiated'], true)) {
            return ['success' => false, 'message' => 'This concluded case is read-only; stage outcomes cannot be changed.'];
        }

        $currentStage = ($stageType === 'Mediation')
            ? $overview['mediation']
            : (($stageType === 'Conciliation') ? $overview['conciliation'] : ($overview['arbitration'] ?? []));
        if ($stageType === 'Conciliation' && !empty($currentStage['is_locked'])) {
            return ['success' => false, 'message' => 'Pangkat Conciliation is locked. Complete mediation and record an authorized referral first.'];
        }

        if ($stageType === 'Conciliation' && $outcome === 'Unsuccessful') {
            require_once __DIR__ . '/Hearing.php';
            $nonSupersededCon = Hearing::nonSupersededSql('h', 'next_h');
            $minStmt = $this->conn->prepare("
                SELECT hm.session_outcome
                FROM hearings h
                LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
                    AND hm.minute_id = (
                        SELECT MAX(hm2.minute_id)
                        FROM hearing_minutes hm2
                        WHERE hm2.hearing_id = h.hearing_id
                          AND hm2.status = 'Finalized'
                    )
                WHERE h.case_id = ?
                  AND h.hearing_type = 'Conciliation'
                  AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                  AND {$nonSupersededCon}
                ORDER BY h.hearing_date DESC, h.hearing_id DESC
                LIMIT 1
            ");
            $minStmt->execute([$caseId]);
            $opOutcome = (string) ($minStmt->fetchColumn() ?: '');
            if (!in_array($opOutcome, ['Failed', 'Pending CFA'], true)) {
                return [
                    'success' => false,
                    'message' => 'Conciliation cannot be marked Unsuccessful via stage outcome alone. Record and finalize minutes on the operative Conciliation hearing with a Failed or Pending CFA outcome first.'
                ];
            }
        }

        if ($outcome === 'Repudiated') {
            $repStmt = $this->conn->prepare("
                SELECT COUNT(*)
                FROM settlements
                WHERE case_id = ?
                  AND (repudiation_status = 'Repudiated' OR repudiation_date IS NOT NULL)
            ");
            $repStmt->execute([$caseId]);
            if ((int) $repStmt->fetchColumn() === 0) {
                return [
                    'success' => false,
                    'message' => 'Repudiated outcome requires a sworn settlement repudiation filed through the Settlement Repudiation workflow.'
                ];
            }
        }

        $manageTx = false;
        try {
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $manageTx = true;
            }

            $newStatus = 'Completed';
            if ($outcome === 'Unsuccessful' && $stageType === 'Mediation') {
                $newStatus = 'Ready for Referral';
            } elseif ($outcome === 'Settled') {
                $newStatus = 'Completed';
            }

            // Upsert case_stages
            $upsert = $this->conn->prepare("
                INSERT INTO case_stages (case_id, stage_type, stage_status, outcome, outcome_remarks, completed_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    stage_status = VALUES(stage_status),
                    outcome = VALUES(outcome),
                    outcome_remarks = VALUES(outcome_remarks),
                    completed_at = VALUES(completed_at),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $upsert->execute([$caseId, $stageType, $newStatus, $outcome, $remarks ?: null]);

            // Audit in stage_transitions
            $trans = $this->conn->prepare("
                INSERT INTO stage_transitions (case_id, stage_type, from_status, to_status, action_type, performed_by, remarks)
                VALUES (?, ?, ?, ?, 'Record Stage Outcome', ?, ?)
            ");
            $trans->execute([
                $caseId,
                $stageType,
                $currentStage['stage_status'],
                $newStatus,
                $userId,
                "Outcome recorded: {$outcome}. Notes: " . ($remarks ?: 'None')
            ]);

            // An agreement to arbitrate opens the Arbitration stage; the award
            // is recorded later after the scheduled arbitration hearing.
            if ($outcome === 'Settled') {
                $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Settled', updated_at = NOW() WHERE case_id = ?");
                $updCase->execute([$caseId]);
                $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Settled', co.updated_at = NOW() WHERE c.case_id = ?");
                $updComp->execute([$caseId]);
            } elseif ($outcome === 'Dismissed') {
                $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Dismissed', updated_at = NOW() WHERE case_id = ?");
                $updCase->execute([$caseId]);
                $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Dismissed', co.updated_at = NOW() WHERE c.case_id = ?");
                $updComp->execute([$caseId]);
            } elseif ($outcome === 'Arbitration Award') {
                $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Settled', updated_at = NOW() WHERE case_id = ?");
                $updCase->execute([$caseId]);
                $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Settled', co.updated_at = NOW() WHERE c.case_id = ?");
                $updComp->execute([$caseId]);
            } elseif ($outcome === 'Arbitration') {
                $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Arbitration', updated_at = NOW() WHERE case_id = ?");
                $updCase->execute([$caseId]);
                $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Arbitration', co.updated_at = NOW() WHERE c.case_id = ?");
                $updComp->execute([$caseId]);

                $chkArb = $this->conn->prepare("SELECT arbitration_id FROM arbitration_records WHERE case_id = ? LIMIT 1");
                $chkArb->execute([$caseId]);
                $arbId = $chkArb->fetchColumn();
                if (!$arbId) {
                    $insArb = $this->conn->prepare("
                        INSERT INTO arbitration_records (case_id, agreement_date, award_details)
                        VALUES (?, CURDATE(), ?)
                    ");
                    $insArb->execute([
                        $caseId,
                        $remarks ?: 'Both parties voluntarily agreed in writing to submit their dispute to binding arbitration under RA 7160 Sec. 413 (KP Form 14).'
                    ]);
                } else {
                    $updArb = $this->conn->prepare("
                        UPDATE arbitration_records
                        SET agreement_date = COALESCE(agreement_date, CURDATE())
                        WHERE arbitration_id = ?
                    ");
                    $updArb->execute([$arbId]);
                }
            }

            // Case history entry
            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)");
            $hist->execute([
                $caseId,
                ($outcome === 'Settled' ? 'Settled' : ($outcome === 'Dismissed' ? 'Dismissed' : $overview['case']['case_status'])),
                "{$stageType} outcome recorded: {$outcome}. {$remarks}",
                $userId
            ]);

            $this->audit->log($userId, "Recorded {$stageType} Outcome: {$outcome}", 'Cases', $caseId);

            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->commit();
            }

            return [
                'success' => true,
                'message' => "{$stageType} outcome successfully recorded as {$outcome}.",
                'stage_status' => $newStatus,
                'outcome' => $outcome,
                'case_id' => $caseId,
                'complaint_id' => (int) ($overview['case']['complaint_id'] ?? 0)
            ];
        } catch (Throwable $e) {
            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Unable to record stage outcome: ' . $e->getMessage()];
        }
    }

    /**
     * Executes authorized referral from Mediation to Pangkat Conciliation.
     * Unlocks Pangkat Conciliation, constitutes Pangkat panel, updates case status,
     * and automatically generates KP Form 10.
     */
    public function referToPangkat(int $caseId, array $data, int $userId): array
    {
        $manageTx = false;
        $savepointCreated = false;
        $generatedPaths = [];
        try {
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $manageTx = true;
            }

            $caseLockStmt = $this->conn->prepare('SELECT * FROM cases WHERE case_id = ? FOR UPDATE');
            $caseLockStmt->execute([$caseId]);
            $lockedCase = $caseLockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedCase) {
                throw new DomainException('Case not found.');
            }

            $eligibility = self::evaluateMediationReferralEligibility($this->conn, $caseId, $lockedCase);
            if (empty($eligibility['eligible'])) {
                if ($manageTx && $this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return [
                    'success' => false,
                    'already_referred' => !empty($eligibility['already_referred']),
                    'message' => $eligibility['reason'] ?? 'Mediation requirements not satisfied before referring to Pangkat.'
                ];
            }

            if (!$manageTx) {
                $this->conn->exec('SAVEPOINT pangkat_referral');
                $savepointCreated = true;
            }

            // Bulk Pangkat Group Selection: when a standing duty group is chosen, its
            // seated members (resolved server-side) replace any individually submitted IDs.
            $dutyGroupId = filter_var($data['duty_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            if ($dutyGroupId) {
                require_once __DIR__ . '/../services/PangkatDutyGroupService.php';
                $resolved = (new PangkatDutyGroupService($this->conn))->resolvePanel((int) $dutyGroupId);
                if (!$resolved['success']) {
                    throw new DomainException($resolved['message']);
                }
                $data['chairman_id'] = $resolved['chairman_id'];
                $data['secretary_id'] = $resolved['secretary_id'];
                $data['member_id'] = $resolved['member_id'];
                $data['quorum_size'] = 3;
                $groupNote = "Panel drawn from standing duty group \"{$resolved['group']['group_name']}\" ({$resolved['group']['duty_day']} duty).";
                $data['selection_notes'] = trim($groupNote . ' ' . trim((string) ($data['selection_notes'] ?? '')));
            }

            $panelValidation = $this->validatePangkatPanel($data);
            if (!$panelValidation['valid']) {
                throw new DomainException($panelValidation['message']);
            }

            $referralDate = !empty($data['referral_date']) ? date('Y-m-d', strtotime($data['referral_date'])) : date('Y-m-d');
            $referralReason = trim((string) ($data['referral_reason'] ?? 'Mediation before the Punong Barangay failed to produce an amicable settlement. Referred for formal conciliation before the Pangkat ng Tagapagkasundo.'));
            $selectionMethod = in_array($data['selection_method'] ?? '', ['Party Agreement', 'PB Assignment', 'Raffle Draw'], true) ? $data['selection_method'] : 'Party Agreement';
            $selectionNotes = trim((string) ($data['selection_notes'] ?? ''));
            $quorumSize = !empty($data['quorum_size']) ? (int) $data['quorum_size'] : 3;

            require_once __DIR__ . '/../services/MediationDeadlineService.php';
            require_once __DIR__ . '/../services/OfficeLogisticsService.php';
            $logistics = new OfficeLogisticsService($this->conn);

            $conciliationDate = !empty($data['conciliation_date']) ? date('Y-m-d', strtotime((string) $data['conciliation_date'])) : null;
            $conciliationTime = !empty($data['conciliation_time']) ? trim((string) $data['conciliation_time']) : null;
            $venue = !empty($data['venue']) ? trim((string) $data['venue']) : $logistics->getConfiguredDefaultVenue('Conciliation');
            $concilDuration = max(15, (int) $logistics->getSetting('conciliation_default_duration_min', 60));

            $hearingDateTime = null;
            $hearingEndTime = null;
            if ($conciliationDate) {
                $workStart = (string) $logistics->getSetting('working_hours_start', '08:00');
                $defaultSlotTime = ($workStart > '09:00') ? $workStart : '09:00';
                $hearingTimeStr = $conciliationTime ? date('H:i', strtotime($conciliationTime)) : $defaultSlotTime;
                $schedValidation = $logistics->validateHearingSchedule(
                    "{$conciliationDate} {$hearingTimeStr}:00",
                    $concilDuration,
                    'Conciliation',
                    $data['duration_exceed_reason'] ?? null
                );
                if (empty($schedValidation['valid'])) {
                    throw new DomainException($schedValidation['message']);
                }
                $hearingDateTime = $schedValidation['start_time'];
                $hearingEndTime = $schedValidation['end_time'];
                $concilDuration = (int) ($schedValidation['duration_minutes'] ?? $concilDuration);

                $capCheck = $logistics->validateDailyCapacity($hearingDateTime, $panelValidation['chairman_id']);
                if (!$capCheck['valid']) {
                    throw new DomainException($capCheck['message']);
                }
                $venueCheck = $logistics->validateVenueAvailability($venue, $hearingDateTime, $hearingEndTime, null);
                if (!$venueCheck['available']) {
                    throw new DomainException($venueCheck['message']);
                }
                foreach (array_filter([$panelValidation['chairman_id'], $panelValidation['secretary_id'], $panelValidation['member_id']]) as $panelMemberId) {
                    $memCheck = $logistics->validateMemberAvailability((int) $panelMemberId, $hearingDateTime, $hearingEndTime, null, 'Pangkat Member');
                    if (!$memCheck['available']) {
                        throw new DomainException($memCheck['message']);
                    }
                }

                require_once __DIR__ . '/Summons.php';
                $summonsModel = new Summons($this->conn);
                $conflict = $summonsModel->findMediationConflict("{$conciliationDate} {$hearingTimeStr}", $venue, $caseId, null, 'Conciliation');
                if ($conflict !== null) {
                    throw new DomainException($conflict['message'] ?? 'Schedule conflict detected for conciliation hearing.');
                }
            }

            $prevMedStageStatus = $eligibility['stage_record']['stage_status'] ?? 'Ready for Referral';

            // 1. Update Mediation Stage in case_stages (records handoff & referral)
            $medStmt = $this->conn->prepare("
                INSERT INTO case_stages (case_id, stage_type, stage_status, outcome, referral_date, referring_officer_id, referral_reason, records_transmitted, completed_at)
                VALUES (?, 'Mediation', 'Referred to Pangkat', 'Referred to Pangkat', ?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    stage_status = 'Referred to Pangkat',
                    outcome = 'Referred to Pangkat',
                    referral_date = VALUES(referral_date),
                    referring_officer_id = VALUES(referring_officer_id),
                    referral_reason = VALUES(referral_reason),
                    records_transmitted = 1,
                    completed_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $medStmt->execute([$caseId, $referralDate, $userId, $referralReason]);

            // 2. Unlock Conciliation Stage in case_stages (remain 'Not Started' while separate booking is pending)
            $conStageStatus = $conciliationDate ? 'In Progress' : 'Not Started';
            $conStartedAt = $conciliationDate ? date('Y-m-d H:i:s') : null;
            $conStmt = $this->conn->prepare("
                INSERT INTO case_stages (case_id, stage_type, stage_status, started_at)
                VALUES (?, 'Conciliation', ?, ?)
                ON DUPLICATE KEY UPDATE
                    stage_status = VALUES(stage_status),
                    started_at = COALESCE(started_at, VALUES(started_at)),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $conStmt->execute([$caseId, $conStageStatus, $conStartedAt]);

            // 3. Update cases & complaints status to Conciliation
            $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Conciliation', updated_at = NOW() WHERE case_id = ?");
            $updCase->execute([$caseId]);

            $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Conciliation', co.updated_at = NOW() WHERE c.case_id = ?");
            $updComp->execute([$caseId]);

            $this->conn->prepare("
                UPDATE case_deadlines
                SET status = 'Completed', completed_at = NOW(), updated_at = CURRENT_TIMESTAMP
                WHERE case_id = ? AND status <> 'Completed'
                  AND deadline_type IN ('Mediation Period', '15-Day Mediation Deadline')
            ")->execute([$caseId]);

            // 4. Set 3-Day Statutory Convening Deadline (RA 7160 Sec. 410(c))
            $conveningDeadline = MediationDeadlineService::calculateDeadline($referralDate, MediationDeadlineService::PANGKAT_CONVENING_DAYS);

            $dlStmt = $this->conn->prepare("
                INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                VALUES (?, 'Pangkat Convening', ?, 'Pending')
                ON DUPLICATE KEY UPDATE
                    due_date = VALUES(due_date),
                    status = IF(status = 'Completed', 'Completed', 'Pending'),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $dlStmt->execute([$caseId, $conveningDeadline]);

            // 5. Pangkat Panel Members Assignment
            $chairmanId = $panelValidation['chairman_id'];
            $secretaryId = $panelValidation['secretary_id'];
            $memberId = $panelValidation['member_id'];
            $selectionStage = in_array($data['selection_stage'] ?? '', ['Party Choice', 'Failed Agreement - PB Lots', 'Failed Agreement - PB Appointment', 'Constituted'], true)
                ? $data['selection_stage']
                : 'Constituted';
            $partyAgreementFailed = !empty($data['party_agreement_failed']) ? 1 : 0;
            $partyAgreementFailureReason = trim((string) ($data['party_agreement_failure_reason'] ?? ''));

            if ($chairmanId && $secretaryId) {
                $insGroup = $this->conn->prepare("
                    INSERT INTO pangkat_groups (
                        case_id, formation_date, convening_deadline, selection_method, selection_notes,
                        selection_stage, party_agreement_failed, party_agreement_failure_reason, quorum_size
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        formation_date = VALUES(formation_date),
                        convening_deadline = VALUES(convening_deadline),
                        selection_method = VALUES(selection_method),
                        selection_notes = VALUES(selection_notes),
                        selection_stage = VALUES(selection_stage),
                        party_agreement_failed = VALUES(party_agreement_failed),
                        party_agreement_failure_reason = VALUES(party_agreement_failure_reason),
                        quorum_size = VALUES(quorum_size),
                        pangkat_id = LAST_INSERT_ID(pangkat_id)
                ");
                $insGroup->execute([
                    $caseId,
                    $referralDate,
                    $conveningDeadline,
                    $selectionMethod,
                    $selectionNotes ?: null,
                    $selectionStage,
                    $partyAgreementFailed,
                    $partyAgreementFailureReason ?: null,
                    $quorumSize
                ]);
                $pangkatId = (int) $this->conn->lastInsertId();

                if ($pangkatId > 0) {
                    try {
                        $this->conn->prepare("UPDATE pangkat_groups SET duty_group_id = ? WHERE pangkat_id = ?")->execute([$dutyGroupId, $pangkatId]);
                    } catch (PDOException $e) {
                        // Optional duty_group_id column
                    }
                    $this->conn->prepare("DELETE FROM pangkat_members WHERE pangkat_id = ?")->execute([$pangkatId]);
                    $insMem = $this->conn->prepare("INSERT INTO pangkat_members (pangkat_id, member_id, position, status) VALUES (?, ?, ?, 'Active')");
                    $insMem->execute([$pangkatId, $chairmanId, 'Chairman']);
                    $insMem->execute([$pangkatId, $secretaryId, 'Secretary']);
                    if ($memberId) {
                        $insMem->execute([$pangkatId, $memberId, 'Member']);
                    }
                }

                // Sync case_assignments
                $this->conn->prepare("DELETE FROM case_assignments WHERE case_id = ? AND assignment_role IN ('Head', 'Secretary', 'Member')")->execute([$caseId]);
                $insCa = $this->conn->prepare("INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, ?, CURDATE())");
                $insCa->execute([$caseId, $chairmanId, 'Head']);
                $insCa->execute([$caseId, $secretaryId, 'Secretary']);
                if ($memberId) {
                    $insCa->execute([$caseId, $memberId, 'Member']);
                }
            }

            // 5.5 Book 1st Conciliation Hearing if conciliation_date provided
            $conciliationHearingId = null;
            if ($conciliationDate && $hearingDateTime) {
                $insHearing = $this->conn->prepare("
                    INSERT INTO hearings (
                        case_id, hearing_type, hearing_date, end_time, duration_minutes, venue, status,
                        presiding_officer_id, remarks
                    ) VALUES (
                        ?, 'Conciliation', ?, ?, ?, ?, 'Scheduled',
                        ?, '1st Conciliation Hearing before Pangkat ng Tagapagkasundo'
                    )
                ");
                $insHearing->execute([
                    $caseId,
                    $hearingDateTime,
                    $hearingEndTime,
                    $concilDuration,
                    $venue,
                    $chairmanId ?: null
                ]);
                $conciliationHearingId = (int) $this->conn->lastInsertId();

                $conDeadline = MediationDeadlineService::calculateDeadline($conciliationDate, MediationDeadlineService::CONCILIATION_PERIOD_DAYS);
                $this->conn->prepare("
                    UPDATE pangkat_groups
                    SET convened_date = COALESCE(convened_date, ?),
                        conciliation_deadline = COALESCE(conciliation_deadline, ?),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE case_id = ?
                ")->execute([$conciliationDate, $conDeadline, $caseId]);

                $this->conn->prepare("
                    INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                    VALUES (?, 'Conciliation Period', ?, 'Pending')
                    ON DUPLICATE KEY UPDATE
                        due_date = VALUES(due_date),
                        status = IF(status = 'Completed', 'Completed', 'Pending'),
                        updated_at = CURRENT_TIMESTAMP
                ")->execute([$caseId, $conDeadline]);

                $this->conn->prepare("
                    UPDATE case_deadlines
                    SET status = 'Completed', completed_at = NOW(), updated_at = CURRENT_TIMESTAMP
                    WHERE case_id = ? AND deadline_type = 'Pangkat Convening'
                ")->execute([$caseId]);
            }

            // 6. Generate and Persist KP Forms 10, 11, and (when booked) 12 atomically
            $kpFormService = $this->createKpFormService();
            $noticeOptions = ['bypass_prerequisites' => true, 'strict_pdf' => true];
            $requireNotice = static function (array $result, string $form) use (&$generatedPaths): int {
                $path = $result['full_path'] ?? '';
                if ($path !== '') {
                    $generatedPaths[] = $path;
                }
                if (empty($result['success']) || empty($result['document_id']) || !is_file($path)) {
                    throw new RuntimeException($result['message'] ?? "Unable to generate {$form} during referral.");
                }
                return (int) $result['document_id'];
            };

            $kp10Result = $kpFormService->generateAndPersistKp10($caseId, $userId, $noticeOptions + [
                'venue' => $venue,
                'constitution_date' => $referralDate
            ]);
            $kp10DocId = $requireNotice($kp10Result, 'KP Form 10');

            $kp11Result = $kpFormService->generateAndPersistKp11($caseId, $userId, $noticeOptions);
            $kp11DocId = $requireNotice($kp11Result, 'KP Form 11');

            $kp12Result = null;
            $kp12DocId = null;
            if ($conciliationHearingId !== null) {
                $kp12Result = $kpFormService->generateAndPersistKp12($caseId, $userId, $noticeOptions + [
                    'hearing_id' => $conciliationHearingId,
                    'venue' => $venue
                ]);
                $kp12DocId = $requireNotice($kp12Result, 'KP Form 12');
                $this->conn->prepare("UPDATE hearings SET kp12_document_id = ? WHERE hearing_id = ?")
                    ->execute([$kp12DocId, $conciliationHearingId]);
            }

            // 7. Record Stage Transition Audit
            $trans = $this->conn->prepare("
                INSERT INTO stage_transitions (case_id, stage_type, from_status, to_status, action_type, performed_by, remarks)
                VALUES (?, 'Mediation', ?, 'Referred to Pangkat', 'Refer to Pangkat', ?, ?)
            ");
            $trans->execute([
                $caseId,
                $prevMedStageStatus,
                $userId,
                "Mediation elevated to Pangkat Conciliation. Reason: {$referralReason}"
            ]);

            // 8. History Entry
            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, 'Conciliation', ?, ?)");
            $hist->execute([
                $caseId,
                "Mediation concluded. Case elevated to Pangkat Conciliation (KP Form 10 generated). Referral reason: {$referralReason}",
                $userId
            ]);

            $this->audit->log($userId, 'Referred Case to Pangkat Conciliation', 'Cases', $caseId);

            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->commit();
            } elseif ($savepointCreated) {
                $this->conn->exec('RELEASE SAVEPOINT pangkat_referral');
            }

            return [
                'success' => true,
                'message' => $conciliationHearingId
                    ? 'Case successfully referred to Pangkat ng Tagapagkasundo. Conciliation workspace unlocked, 1st conciliation hearing booked, and KP Forms 10, 11, and 12 generated.'
                    : 'Case successfully referred to Pangkat ng Tagapagkasundo and KP Forms 10 and 11 generated. 1st conciliation booking remains pending until scheduled.',
                'case_id' => $caseId,
                'hearing_id' => $conciliationHearingId,
                'is_booking_pending' => ($conciliationHearingId === null),
                'kp10_document_id' => $kp10DocId,
                'kp11_document_id' => $kp11DocId,
                'kp12_document_id' => $kp12DocId,
                'kp10' => $kp10Result,
                'open_notices_modal' => ($conciliationHearingId !== null)
            ];
        } catch (Throwable $e) {
            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            } elseif ($savepointCreated && $this->conn->inTransaction()) {
                $this->conn->exec('ROLLBACK TO SAVEPOINT pangkat_referral');
                $this->conn->exec('RELEASE SAVEPOINT pangkat_referral');
            }
            foreach ($generatedPaths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            return [
                'success' => false,
                'message' => $e instanceof DomainException ? $e->getMessage() : 'Referral to Pangkat failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * A Conciliation referral must constitute a complete, distinct, active
     * Lupon panel before the case status is changed. Client-side dropdown
     * checks are helpful, but this guard protects direct API requests too.
     */
    private function validatePangkatPanel(array $data): array
    {
        $chairmanId = filter_var(
            $data['chairman_id'] ?? $data['head_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $secretaryId = filter_var(
            $data['secretary_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $memberId = filter_var(
            $data['member_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $quorumSize = !empty($data['quorum_size']) ? (int) $data['quorum_size'] : 3;
        if ($quorumSize === 2) {
            if (!$chairmanId || !$secretaryId) {
                return [
                    'valid' => false,
                    'message' => 'Choose a Head and Secretary before referring the case to Pangkat.'
                ];
            }
            if ($chairmanId === $secretaryId) {
                return [
                    'valid' => false,
                    'message' => 'The Pangkat Head and Secretary must be two different active Lupon Members.'
                ];
            }
            $memberIds = array_filter([(int) $chairmanId, (int) $secretaryId, $memberId ? (int) $memberId : null]);
        } else {
            if (!$chairmanId || !$secretaryId || !$memberId) {
                return [
                    'valid' => false,
                    'message' => 'Choose a Head, Secretary, and Member before referring the case to Pangkat.'
                ];
            }

            $memberIds = [(int) $chairmanId, (int) $secretaryId, (int) $memberId];
            if (count(array_unique($memberIds)) !== 3) {
                return [
                    'valid' => false,
                    'message' => 'The Pangkat Head, Secretary, and Member must be three different active Lupon Members.'
                ];
            }
        }

        require_once __DIR__ . '/Assignment.php';
        $assignment = new Assignment();
        foreach ($memberIds as $memberId) {
            if (!$assignment->isEligibleMember($memberId)) {
                return [
                    'valid' => false,
                    'message' => 'Every Pangkat panel member must be an active user with the Lupon Member role. The Administrator cannot serve on a Conciliation panel.'
                ];
            }
        }

        return [
            'valid' => true,
            'chairman_id' => (int) $chairmanId,
            'secretary_id' => (int) $secretaryId,
            'member_id' => (int) $memberId,
        ];
    }

    /**
     * Helper for CSS badge classes.
     */
    private static function getBadgeClass(string $status): string
    {
        return match ($status) {
            'Completed', 'Settled' => 'badge-success',
            'In Progress' => 'badge-primary',
            'Ready for Referral', 'Awaiting Outcome' => 'badge-warning',
            'Referred to Pangkat' => 'badge-info',
            'Locked', 'Not Started' => 'badge-secondary',
            default => 'badge-secondary'
        };
    }
}
