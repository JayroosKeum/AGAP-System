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
        $this->audit = new AuditService();
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
     * Builds summary for Mediation Stage.
     */
    private function buildMediationOverview(array $case, ?array $stageRecord, string $pbName): array
    {
        $caseId = (int) $case['case_id'];

        // Mediation Hearings (type Initial Hearing or Mediation)
        $hStmt = $this->conn->prepare("
            SELECT h.hearing_id, h.hearing_type, h.hearing_date, h.venue, h.status,
                   h.complainant_attendance, h.respondent_attendance,
                   hm.minute_id, hm.status AS minutes_status, hm.session_outcome
            FROM hearings h
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
            WHERE h.case_id = ? AND h.hearing_type IN ('Initial Hearing', 'Mediation')
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

        // Determine Mediation Status
        $stageStatus = 'Not Started';
        $outcome = $stageRecord['outcome'] ?? 'Pending';
        $outcomeRemarks = $stageRecord['outcome_remarks'] ?? null;

        if ($stageRecord && !empty($stageRecord['stage_status'])) {
            $stageStatus = $stageRecord['stage_status'];
        } else {
            if ($case['case_status'] === 'Settled' || $outcome === 'Settled' || $hasSettledSession) {
                $stageStatus = 'Completed';
                $outcome = 'Settled';
            } elseif ($case['case_status'] === 'Conciliation' || in_array($case['case_status'], ['CFA Issued', 'Dismissed'], true)) {
                $stageStatus = 'Referred to Pangkat';
                $outcome = 'Referred to Pangkat';
            } elseif ($hasFailedSession) {
                $stageStatus = 'Ready for Referral';
                $outcome = 'Unsuccessful';
            } elseif ($sessionCount > 0) {
                $hasPendingOrFuture = false;
                foreach ($hearings as $h) {
                    if ($h['hearing_date'] >= $now || $h['status'] === 'Scheduled') {
                        $hasPendingOrFuture = true;
                        break;
                    }
                }
                $stageStatus = $hasPendingOrFuture ? 'In Progress' : 'Awaiting Outcome';
            }
        }

        // Attendance Finalized status
        $attendanceFinalized = ($sessionCount > 0 && ($complainantAtt['present'] + $complainantAtt['absent'] + $complainantAtt['excused']) > 0);

        // Actions prerequisites
        $isLapsed = !empty($case['mediation_timer']['is_expired']);
        $canProceedToPangkat = ($stageStatus === 'Ready for Referral' || $outcome === 'Unsuccessful' || $hasFailedSession || $isLapsed || $case['case_status'] === 'Conciliation');

        return [
            'stage_type' => 'Mediation',
            'stage_title' => '7. Mediation',
            'stage_subtitle' => 'Punong Barangay · Initial dispute resolution',
            'stage_status' => $stageStatus,
            'stage_badge_class' => self::getBadgeClass($stageStatus),
            'presiding_officer' => $pbName,
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
                'can_record_attendance' => ($sessionCount > 0),
                'can_add_minutes' => ($sessionCount > 0),
                'can_print_notice' => ($noticeCount > 0 || $sessionCount > 0),
                'can_generate_settlement' => ($hasSettledSession || $outcome === 'Settled' || $case['case_status'] === 'Settled'),
                'can_record_outcome' => ($sessionCount > 0),
                'can_proceed_to_pangkat' => $canProceedToPangkat
            ],
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

        $isReadyForReferral = ($medStage['stage_status'] === 'Ready for Referral' || $medStage['outcome'] === 'Unsuccessful' || !empty($case['mediation_timer']['is_expired']));

        $stageStatus = 'Locked';
        if (!$isUnlocked) {
            $stageStatus = $isReadyForReferral ? 'Ready for Referral' : 'Locked';
        } elseif ($stageRecord && !empty($stageRecord['stage_status']) && $stageRecord['stage_status'] !== 'Locked') {
            $stageStatus = $stageRecord['stage_status'];
        } else {
            if ($case['case_status'] === 'Settled') {
                $stageStatus = 'Completed';
            } elseif ($case['case_status'] === 'Conciliation') {
                $stageStatus = 'In Progress';
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
                } elseif (in_array($h['session_outcome'], ['Failed', 'Pending CFA'], true)) {
                    $hasFailedSession = true;
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

        // Conciliation Deadline (15 days from formation_date)
        $conciliationDeadline = null;
        if (!empty($pangkatInfo['formation_date'])) {
            $targetDate = MediationDeadlineService::calculateDeadline($pangkatInfo['formation_date'], 15);
            $conciliationDeadline = [
                'target_date' => $targetDate,
                'formation_date' => $pangkatInfo['formation_date']
            ];
        }

        $outcome = $stageRecord['outcome'] ?? 'Pending';
        if ($case['case_status'] === 'Settled' || $hasSettledSession) {
            $outcome = 'Settled';
            $stageStatus = 'Completed';
        }

        return [
            'stage_type' => 'Conciliation',
            'stage_title' => '8. Pangkat Conciliation',
            'stage_subtitle' => 'Pangkat ng Tagapagkasundo · Formal conciliation',
            'stage_status' => $stageStatus,
            'stage_badge_class' => self::getBadgeClass($stageStatus),
            'is_locked' => ($stageStatus === 'Locked'),
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
                'can_issue_cfa' => ($isUnlocked && ($hasFailedSession || $outcome === 'Unsuccessful' || $case['case_status'] === 'CFA Issued'))
            ],
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

        return [
            'stage_name' => 'Arbitration',
            'stage_description' => 'Binding dispute arbitration pursuant to KP Form 14 / Sec 413, RA 7160',
            'stage_status' => $stageStatus,
            'badge_class' => self::getBadgeClass($stageStatus),
            'is_locked' => !$isUnlocked,
            'presiding_officer' => $stageRecord['presiding_officer'] ?? $pbName,
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

        // Fetch members from pangkat_members joined with users
        $members = [];
        if ($group) {
            $memStmt = $this->conn->prepare("
                SELECT pm.position, pm.member_id,
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
                    'contact_number' => $row['contact_number']
                ];
            }
        }

        $isConstituted = (count($members) >= 2);
        $chairperson = null;
        $secretary = null;
        $regularMember = null;

        foreach ($members as $m) {
            if ($m['position'] === 'Chairman' && !$chairperson) $chairperson = $m;
            elseif ($m['position'] === 'Secretary' && !$secretary) $secretary = $m;
            elseif (!$regularMember) $regularMember = $m;
        }

        return [
            'is_constituted' => $isConstituted,
            'formation_date' => $group['formation_date'] ?? null,
            'selection_method' => $group['selection_method'] ?? 'Party Agreement',
            'selection_notes' => $group['selection_notes'] ?? null,
            'quorum_size' => (int) ($group['quorum_size'] ?? 3),
            'members' => $members,
            'chairperson' => $chairperson,
            'secretary' => $secretary,
            'member' => $regularMember,
            'assignment_status' => $isConstituted ? sprintf('Constituted (%d Members)', count($members)) : 'Pending Formation'
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
                   h.complainant_attendance, h.respondent_attendance, h.remarks,
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
            LEFT JOIN hearing_minutes hm ON hm.hearing_id = h.hearing_id
            LEFT JOIN users rec_u ON rec_u.user_id = hm.recorded_by
            LEFT JOIN users fin_u ON fin_u.user_id = hm.finalized_by
            WHERE h.case_id = ? AND h.hearing_type IN ('$inClause')
            ORDER BY h.hearing_date ASC
        ");
        $stmt->execute([$caseId]);
        $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $partyStmt = $this->conn->prepare("
            SELECT cp.resident_id, cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name,
                   r.address,
                   ha.attendance_status, ha.is_justified, ha.justification_reason,
                   ha.remarks AS party_remarks, ha.verification_status, ha.determination_notes,
                   (
                       (SELECT COUNT(*) FROM hearing_party_services hps WHERE hps.hearing_id = ? AND hps.resident_id = cp.resident_id AND hps.service_result = 'Served')
                       +
                       (SELECT COUNT(*) FROM summon_deliveries sd WHERE sd.hearing_id = ? AND (sd.resident_id = cp.resident_id OR sd.party_type = cp.party_type) AND sd.delivery_status IN ('Served Personal', 'Served Substituted', 'Served Refused'))
                   ) AS service_confirmed_count
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            INNER JOIN cases c ON c.complaint_id = cp.complaint_id
            LEFT JOIN hearing_attendance ha ON ha.hearing_id = ? AND ha.resident_id = cp.resident_id
            WHERE c.case_id = ?
            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name
        ");

        $sessions = [];
        $index = 1;
        foreach ($hearings as $h) {
            $hId = (int) $h['hearing_id'];
            $partyStmt->execute([$hId, $hId, $hId, $caseId]);
            $parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute proof of service indicator
            $proofStatus = 'Pending Delivery';
            $servedAny = false;
            foreach ($parties as $p) {
                if ((int) $p['service_confirmed_count'] > 0) {
                    $servedAny = true;
                    break;
                }
            }
            if ($servedAny) {
                $proofStatus = 'Served';
            }

            $sessions[] = [
                'session_index' => $index,
                'session_number' => sprintf('%s Session %d', $stageType, $index),
                'hearing_id' => $hId,
                'hearing_type' => $h['hearing_type'],
                'hearing_date' => $h['hearing_date'],
                'venue' => $h['venue'],
                'status' => $h['status'],
                'complainant_attendance' => $h['complainant_attendance'] ?: 'Pending',
                'respondent_attendance' => $h['respondent_attendance'] ?: 'Pending',
                'remarks' => $h['remarks'],
                'proof_of_service_status' => $proofStatus,
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
            $index++;
        }

        return $sessions;
    }

    /**
     * Records stage procedural outcome (Settled, Unsuccessful, Repudiated, Dismissed, Arbitration Award).
     */
    public function recordOutcome(int $caseId, string $stageType, string $outcome, ?string $remarks, int $userId): array
    {
        if (!in_array($stageType, ['Mediation', 'Conciliation', 'Arbitration'], true)) {
            return ['success' => false, 'message' => 'Invalid stage type.'];
        }

        $allowedOutcomes = ['Settled', 'Unsuccessful', 'Repudiated', 'Dismissed', 'Arbitration Award'];
        if (!in_array($outcome, $allowedOutcomes, true)) {
            return ['success' => false, 'message' => 'Please select a valid stage outcome (Settled, Unsuccessful, Repudiated, Dismissed, or Arbitration Award).'];
        }

        $overview = $this->getStageOverview($caseId);
        if (!$overview) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        $currentStage = ($stageType === 'Mediation')
            ? $overview['mediation']
            : (($stageType === 'Conciliation') ? $overview['conciliation'] : ($overview['arbitration'] ?? []));
        if ($stageType === 'Conciliation' && !empty($currentStage['is_locked'])) {
            return ['success' => false, 'message' => 'Pangkat Conciliation is locked. Complete mediation and record an authorized referral first.'];
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

            // Case status update if Settled or Arbitration Award
            if ($outcome === 'Settled') {
                $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Settled', updated_at = NOW() WHERE case_id = ?");
                $updCase->execute([$caseId]);
                $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Settled', co.updated_at = NOW() WHERE c.case_id = ?");
                $updComp->execute([$caseId]);
            } elseif ($outcome === 'Arbitration Award') {
                $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Arbitration', updated_at = NOW() WHERE case_id = ?");
                $updCase->execute([$caseId]);
                $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Arbitration', co.updated_at = NOW() WHERE c.case_id = ?");
                $updComp->execute([$caseId]);
            }

            // Case history entry
            $hist = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)");
            $hist->execute([
                $caseId,
                ($outcome === 'Settled' ? 'Settled' : $overview['case']['case_status']),
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
                'outcome' => $outcome
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
        $overview = $this->getStageOverview($caseId);
        if (!$overview) {
            return ['success' => false, 'message' => 'Case not found.'];
        }

        $med = $overview['mediation'];
        $canRefer = $med['documents_and_actions']['can_proceed_to_pangkat']
            || in_array($med['stage_status'], ['Ready for Referral', 'Awaiting Outcome', 'Completed'], true)
            || in_array($med['outcome'], ['Unsuccessful', 'Failed', 'Pending'], true);

        if (!$canRefer) {
            return [
                'success' => false,
                'message' => 'Mediation requirements not satisfied. Complete mediation sessions or record an unsuccessful mediation outcome before referring to Pangkat.'
            ];
        }

        $referralDate = !empty($data['referral_date']) ? date('Y-m-d', strtotime($data['referral_date'])) : date('Y-m-d');
        $referralReason = trim((string) ($data['referral_reason'] ?? 'Mediation before the Punong Barangay failed to produce an amicable settlement. Referred for formal conciliation before the Pangkat ng Tagapagkasundo.'));
        $selectionMethod = in_array($data['selection_method'] ?? '', ['Party Agreement', 'PB Assignment', 'Raffle Draw'], true) ? $data['selection_method'] : 'Party Agreement';
        $selectionNotes = trim((string) ($data['selection_notes'] ?? ''));
        $quorumSize = !empty($data['quorum_size']) ? (int) $data['quorum_size'] : 3;

        $manageTx = false;
        try {
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $manageTx = true;
            }

            // 1. Update Mediation Stage in case_stages
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

            // 2. Unlock Conciliation Stage in case_stages
            $conStmt = $this->conn->prepare("
                INSERT INTO case_stages (case_id, stage_type, stage_status, started_at)
                VALUES (?, 'Conciliation', 'In Progress', NOW())
                ON DUPLICATE KEY UPDATE
                    stage_status = 'In Progress',
                    started_at = COALESCE(started_at, NOW()),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $conStmt->execute([$caseId]);

            // 3. Update cases & complaints status to Conciliation
            $updCase = $this->conn->prepare("UPDATE cases SET case_status = 'Conciliation', updated_at = NOW() WHERE case_id = ?");
            $updCase->execute([$caseId]);

            $updComp = $this->conn->prepare("UPDATE complaints co INNER JOIN cases c ON c.complaint_id = co.complaint_id SET co.status = 'Conciliation', co.updated_at = NOW() WHERE c.case_id = ?");
            $updComp->execute([$caseId]);

            // 4. Upsert 15-day Conciliation deadline
            require_once __DIR__ . '/../services/MediationDeadlineService.php';
            $targetDate = MediationDeadlineService::calculateDeadline($referralDate, 15);

            $dlStmt = $this->conn->prepare("
                INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                VALUES (?, 'Conciliation Period', ?, 'Pending')
                ON DUPLICATE KEY UPDATE
                    due_date = VALUES(due_date),
                    status = 'Pending',
                    updated_at = CURRENT_TIMESTAMP
            ");
            $dlStmt->execute([$caseId, $targetDate]);

            // 5. Pangkat Panel Members Assignment (if provided)
            $chairmanId = !empty($data['chairman_id']) ? (int) $data['chairman_id'] : (!empty($data['head_id']) ? (int) $data['head_id'] : null);
            $secretaryId = !empty($data['secretary_id']) ? (int) $data['secretary_id'] : null;
            $memberId = !empty($data['member_id']) ? (int) $data['member_id'] : null;

            if ($chairmanId && $secretaryId) {
                // Upsert pangkat_groups
                $insGroup = $this->conn->prepare("
                    INSERT INTO pangkat_groups (case_id, formation_date, selection_method, selection_notes, quorum_size)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        formation_date = VALUES(formation_date),
                        selection_method = VALUES(selection_method),
                        selection_notes = VALUES(selection_notes),
                        quorum_size = VALUES(quorum_size),
                        pangkat_id = LAST_INSERT_ID(pangkat_id)
                ");
                $insGroup->execute([$caseId, $referralDate, $selectionMethod, $selectionNotes ?: null, $quorumSize]);
                $pangkatId = (int) $this->conn->lastInsertId();

                if ($pangkatId > 0) {
                    $this->conn->prepare("DELETE FROM pangkat_members WHERE pangkat_id = ?")->execute([$pangkatId]);
                    $insMem = $this->conn->prepare("INSERT INTO pangkat_members (pangkat_id, member_id, position) VALUES (?, ?, ?)");
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

            // 6. Generate KP Form 10 (Notice to Constitute the Pangkat Tagapagkasundo)
            require_once __DIR__ . '/../services/PDFService.php';
            $pdfService = new PDFService();
            $kp10Result = $pdfService->generateKp10($caseId, $userId, [
                'venue' => 'Lupon Office, Barangay Tumana',
                'constitution_date' => $referralDate
            ], $this->conn);

            // 7. Record Stage Transition Audit
            $trans = $this->conn->prepare("
                INSERT INTO stage_transitions (case_id, stage_type, from_status, to_status, action_type, performed_by, remarks)
                VALUES (?, 'Mediation', ?, 'Referred to Pangkat', 'Refer to Pangkat', ?, ?)
            ");
            $trans->execute([
                $caseId,
                $med['stage_status'],
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
            }

            return [
                'success' => true,
                'message' => 'Case successfully referred to Pangkat ng Tagapagkasundo. Conciliation workspace unlocked and KP Form 10 generated.',
                'case_id' => $caseId,
                'kp10' => $kp10Result
            ];
        } catch (Throwable $e) {
            if ($manageTx && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Referral to Pangkat failed: ' . $e->getMessage()];
        }
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
