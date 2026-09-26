<?php

require_once __DIR__ . '/../config/database.php';

class CaseProgress
{
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function getCaseProgress(int $complaintId): array|false
    {
        if ($complaintId < 1) {
            return false;
        }

        // 1. Fetch Complaint Info
        $complaintStmt = $this->conn->prepare(
            "SELECT c.*, cat.category_name
             FROM complaints c
             LEFT JOIN complaint_categories cat ON cat.category_id = c.category_id
             WHERE c.complaint_id = ?"
        );
        $complaintStmt->execute([$complaintId]);
        $complaint = $complaintStmt->fetch(PDO::FETCH_ASSOC);
        if (!$complaint) {
            return false;
        }

        // 2. Fetch Linked Case Info
        $caseStmt = $this->conn->prepare(
            "SELECT * FROM cases WHERE complaint_id = ?"
        );
        $caseStmt->execute([$complaintId]);
        $case = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $caseId = $case ? (int) $case['case_id'] : null;


        // 3. Fetch Summons Documents
        $summons = [];
        if ($caseId) {
            $summonsStmt = $this->conn->prepare(
                "SELECT gd.document_id, gd.case_id, gd.service_status, gd.generated_at, gd.file_path,
                        TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS issued_by_name,
                        dt.template_name
                 FROM generated_documents gd
                 INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                 LEFT JOIN users u ON u.user_id = gd.generated_by
                 WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')
                 ORDER BY gd.document_id ASC"
            );
            $summonsStmt->execute([$caseId]);
            $summons = $summonsStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 4. Fetch Proof of Service attempts
        $proofs = [];
        if ($caseId) {
            $proofStmt = $this->conn->prepare(
                "SELECT pos.*,
                        TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS served_by_name,
                        dt.template_name
                 FROM proof_of_service pos
                 LEFT JOIN users u ON u.user_id = pos.served_by
                 LEFT JOIN generated_documents gd ON gd.document_id = pos.document_id
                 LEFT JOIN document_templates dt ON dt.template_id = gd.template_id
                 WHERE pos.case_id = ?
                 ORDER BY pos.served_date ASC, pos.proof_id ASC"
            );
            $proofStmt->execute([$caseId]);
            $proofs = $proofStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 5. Fetch Hearings & Attendance
        $hearings = [];
        if ($caseId) {
            $hearingStmt = $this->conn->prepare(
                "SELECT h.*,
                        (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id AND ha.attendance_status = 'Present') AS present_count,
                        (SELECT COUNT(*) FROM hearing_attendance ha WHERE ha.hearing_id = h.hearing_id AND ha.attendance_status = 'Absent') AS absent_count
                 FROM hearings h
                 WHERE h.case_id = ?
                 ORDER BY h.hearing_date ASC, h.hearing_id ASC"
            );
            $hearingStmt->execute([$caseId]);
            $hearings = $hearingStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 6. Fetch Pangkat Group
        $pangkat = null;
        if ($caseId) {
            $pangkatStmt = $this->conn->prepare(
                "SELECT pg.*,
                        TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS chairman_name
                 FROM pangkat_groups pg
                 LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.position = 'Chairman'
                 LEFT JOIN users u ON u.user_id = pm.member_id
                 WHERE pg.case_id = ?"
            );
            $pangkatStmt->execute([$caseId]);
            $pangkat = $pangkatStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // 7. Fetch Settlement, Arbitration, CFA
        $settlement = null;
        $cfa = null;
        $arbitration = null;
        if ($caseId) {
            $settleStmt = $this->conn->prepare("SELECT * FROM settlements WHERE case_id = ?");
            $settleStmt->execute([$caseId]);
            $settlement = $settleStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $cfaStmt = $this->conn->prepare("SELECT * FROM cfa_records WHERE case_id = ?");
            $cfaStmt->execute([$caseId]);
            $cfa = $cfaStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $arbStmt = $this->conn->prepare("SELECT * FROM arbitration_records WHERE case_id = ?");
            $arbStmt->execute([$caseId]);
            $arbitration = $arbStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }


        // 8. Fetch Case History
        $history = [];
        if ($caseId) {
            $histStmt = $this->conn->prepare(
                "SELECT ch.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS updated_by_name
                 FROM case_history ch
                 LEFT JOIN users u ON u.user_id = ch.updated_by
                 WHERE ch.case_id = ?
                 ORDER BY ch.updated_at ASC, ch.history_id ASC"
            );
            $histStmt->execute([$caseId]);
            $history = $histStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Determine Prerequisite for 1st Mediation: enabled if ANY valid summon/service record for the case has a successful Served result
        $anyServed = false;
        foreach ($proofs as $p) {
            if (($p['service_result'] ?? '') === 'Served') {
                $anyServed = true;
                break;
            }
        }
        if (!$anyServed) {
            foreach ($summons as $s) {
                if (($s['service_status'] ?? '') === 'Served') {
                    $anyServed = true;
                    break;
                }
            }
        }
        $summonsPrerequisiteMet = $anyServed;


        // Build the 9 stages
        $stages = $this->computeStages($complaint, $case, $summons, $proofs, $hearings, $pangkat, $settlement, $cfa, $arbitration);

        // Determine current stage index
        $currentStageIdx = 0;
        foreach ($stages as $idx => $stage) {
            if ($stage['state'] === 'current') {
                $currentStageIdx = $idx;
                break;
            }
            if ($stage['state'] === 'completed') {
                $currentStageIdx = $idx;
            }
        }

        // Determine button actions
        $actions = $this->computeButtonActions($complaint, $case, $summons, $proofs, $hearings, $summonsPrerequisiteMet);

        return [
            'complaint_id' => (int) $complaint['complaint_id'],
            'case_id' => $caseId,
            'case_number' => $case['case_number'] ?? null,
            'case_status' => $case['case_status'] ?? null,
            'summons_prerequisite_met' => $summonsPrerequisiteMet,
            'summons_prerequisite_message' => '1st Mediation is unavailable until a summons has been successfully served.',
            'current_stage_index' => $currentStageIdx,
            'stages' => $stages,
            'actions' => $actions,
            'summons' => $summons,
            'proofs' => $proofs,
            'hearings' => $hearings,
            'history' => $this->buildUnifiedHistory($complaint, $case, $summons, $proofs, $hearings, $history, $settlement, $cfa),
        ];
    }

    private function computeStages(
        array $complaint,
        ?array $case,
        array $summons,
        array $proofs,
        array $hearings,
        ?array $pangkat,
        ?array $settlement,
        ?array $cfa,
        ?array $arbitration
    ): array {
        $stages = [];

        // -------------------------------------------------------------
        // STAGE 1: Case Created / Docketed
        // -------------------------------------------------------------
        $isDocketed = !empty($case['case_id']);
        if ($isDocketed) {
            $stages[] = [
                'id' => 'case_created',
                'number' => 1,
                'title' => 'Case Created',
                'subtitle' => $case['case_number'] . ($case['docket_date'] ? ' · ' . date('M j, Y', strtotime($case['docket_date'])) : ''),
                'state' => 'completed',
                'date' => $case['docket_date'] ?? $case['created_at'],
                'details' => 'Case #' . $case['case_number'] . ' officially created and docketed.',
            ];
        } else {
            $stages[] = [
                'id' => 'case_created',
                'number' => 1,
                'title' => 'Case Created',
                'subtitle' => 'Pending Docketing',
                'state' => in_array($complaint['status'], ['Filed', 'Under Review', 'Accepted', 'Needs Information'], true) ? 'current' : 'upcoming',
                'date' => $complaint['created_at'],
                'details' => 'Complaint filed; awaiting case creation and docketing.',
            ];
        }

        // -------------------------------------------------------------
        // STAGE 2: Summon Issued
        // -------------------------------------------------------------
        $hasSummons = count($summons) > 0;
        if ($hasSummons) {
            $firstSummon = $summons[0];
            $latestSummon = end($summons);
            $sumCount = count($summons);
            $label = $sumCount === 1 ? '1st Summon Issued' : sprintf('Summons #%d Issued', $sumCount);
            $stages[] = [
                'id' => 'summon_issued',
                'number' => 2,
                'title' => 'Summon Issued',
                'subtitle' => $label . ($firstSummon['generated_at'] ? ' · ' . date('M j, Y', strtotime($firstSummon['generated_at'])) : ''),
                'state' => 'completed',
                'date' => $firstSummon['generated_at'],
                'details' => sprintf('Total summons issued: %d. Latest issued on %s by %s.', $sumCount, date('M j, Y g:i A', strtotime($latestSummon['generated_at'])), $latestSummon['issued_by_name'] ?: 'Barangay Staff'),
            ];
        } else {
            $stages[] = [
                'id' => 'summon_issued',
                'number' => 2,
                'title' => 'Summon Issued',
                'subtitle' => 'Not Issued',
                'state' => $isDocketed ? 'current' : 'upcoming',
                'date' => null,
                'details' => 'First summons has not been issued yet.',
            ];
        }

        // -------------------------------------------------------------
        // STAGE 3: Summons Service
        // -------------------------------------------------------------
        $hasServiceAttempt = count($proofs) > 0;
        $anyServed = false;
        $latestProof = null;
        foreach ($proofs as $p) {
            if (($p['service_result'] ?? '') === 'Served') {
                $anyServed = true;
            }
            $latestProof = $p;
        }

        if ($anyServed) {
            $stages[] = [
                'id' => 'summons_service',
                'number' => 3,
                'title' => 'Summons Service',
                'subtitle' => 'Served' . ($latestProof['served_date'] ? ' · ' . date('M j, Y', strtotime($latestProof['served_date'])) : ''),
                'state' => 'completed',
                'date' => $latestProof['served_date'] ?? null,
                'details' => 'Summons successfully served to respondent.',
            ];
        } elseif ($hasServiceAttempt) {
            $res = $latestProof['service_result'] ?? 'Service Attempted';
            $stages[] = [
                'id' => 'summons_service',
                'number' => 3,
                'title' => 'Summons Service',
                'subtitle' => $res . ' (Follow-up Required)',
                'state' => 'current',
                'flag' => 'warning',
                'date' => $latestProof['served_date'] ?? null,
                'details' => sprintf('Service attempted: %s. Remarks: %s. Follow-up summons required.', $res, $latestProof['remarks'] ?: 'None'),
            ];
        } else {
            $stages[] = [
                'id' => 'summons_service',
                'number' => 3,
                'title' => 'Summons Service',
                'subtitle' => $hasSummons ? 'Pending Service' : 'Upcoming',
                'state' => ($hasSummons && !$hasServiceAttempt) ? 'current' : 'upcoming',
                'date' => null,
                'details' => $hasSummons ? 'Summons issued; awaiting service attempt.' : 'Upcoming stage.',
            ];
        }

        // -------------------------------------------------------------
        // STAGE 4: Proof of Service
        // -------------------------------------------------------------
        // Completed only when service was Served AND proof recorded
        if ($anyServed && $hasServiceAttempt) {
            $stages[] = [
                'id' => 'proof_of_service',
                'number' => 4,
                'title' => 'Proof of Service',
                'subtitle' => 'Verified / Recorded',
                'state' => 'completed',
                'date' => $latestProof['served_date'] ?? null,
                'details' => 'Proof of service submitted and verified with supporting records.',
            ];
        } elseif ($hasServiceAttempt) {
            $stages[] = [
                'id' => 'proof_of_service',
                'number' => 4,
                'title' => 'Proof of Service',
                'subtitle' => 'Service Incomplete',
                'state' => 'current',
                'flag' => 'warning',
                'date' => null,
                'details' => 'Service attempt recorded but summons not successfully served; valid proof pending.',
            ];
        } else {
            $stages[] = [
                'id' => 'proof_of_service',
                'number' => 4,
                'title' => 'Proof of Service',
                'subtitle' => 'Pending',
                'state' => 'upcoming',
                'date' => null,
                'details' => 'Awaiting service completion and proof submission.',
            ];
        }

        // -------------------------------------------------------------
        // STAGE 5: Hearing Scheduled
        // -------------------------------------------------------------
        $hasHearings = count($hearings) > 0;
        $firstHearing = $hasHearings ? $hearings[0] : null;
        if ($hasHearings) {
            $stages[] = [
                'id' => 'hearing_scheduled',
                'number' => 5,
                'title' => 'Hearing Scheduled',
                'subtitle' => $firstHearing['hearing_type'] . ' · ' . date('M j, Y', strtotime($firstHearing['hearing_date'])),
                'state' => 'completed',
                'date' => $firstHearing['hearing_date'],
                'details' => sprintf('%s scheduled on %s at %s.', $firstHearing['hearing_type'], date('M j, Y g:i A', strtotime($firstHearing['hearing_date'])), $firstHearing['venue']),
            ];
        } else {
            $stages[] = [
                'id' => 'hearing_scheduled',
                'number' => 5,
                'title' => 'Hearing Scheduled',
                'subtitle' => ($anyServed) ? 'Ready to Schedule' : 'Pending Summons',
                'state' => ($anyServed && !$hasHearings) ? 'current' : 'upcoming',
                'date' => null,
                'details' => $anyServed ? 'Summons served and proof verified. Ready to schedule 1st Mediation.' : 'Awaiting summons service before scheduling.',
            ];
        }

        // -------------------------------------------------------------
        // STAGE 6: 1st Mediation
        // -------------------------------------------------------------
        $mediationHearings = array_values(array_filter($hearings, fn($h) => $h['hearing_type'] === 'Mediation'));
        $firstMediation = $mediationHearings[0] ?? null;

        if ($firstMediation) {
            $mDate = new DateTimeImmutable($firstMediation['hearing_date']);
            $isPast = $mDate <= new DateTimeImmutable();
            $hasAttendance = ((int)$firstMediation['present_count'] + (int)$firstMediation['absent_count']) > 0;

            if ($settlement && $settlement['settlement_date'] <= $firstMediation['hearing_date']) {
                $sub = 'Settlement Reached';
                $st = 'completed';
            } elseif ($hasAttendance && (int)$firstMediation['absent_count'] > 0 && (int)$firstMediation['present_count'] === 0) {
                $sub = 'Respondent Absent';
                $st = 'completed';
            } elseif ($isPast || count($mediationHearings) > 1 || !empty($pangkat) || !empty($settlement)) {
                $sub = !empty($settlement) ? 'Settlement Reached' : (count($mediationHearings) > 1 ? 'Proceeded to Next Hearing' : 'Completed');
                $st = 'completed';
            } else {
                $sub = 'Scheduled · ' . $mDate->format('M j, Y');
                $st = 'current';
            }

            $stages[] = [
                'id' => 'mediation',
                'number' => 6,
                'title' => '1st Mediation',
                'subtitle' => $sub,
                'state' => $st,
                'date' => $firstMediation['hearing_date'],
                'details' => sprintf('1st Mediation hearing: %s on %s at %s.', $sub, $mDate->format('M j, Y g:i A'), $firstMediation['venue']),
            ];
        } else {
            $stages[] = [
                'id' => 'mediation',
                'number' => 6,
                'title' => '1st Mediation',
                'subtitle' => 'Not Scheduled',
                'state' => ($hasHearings && !$firstMediation) ? 'current' : 'upcoming',
                'date' => null,
                'details' => 'First mediation hearing has not been scheduled yet.',
            ];
        }

        // -------------------------------------------------------------
        // STAGE 7: Pangkat / Conciliation
        // -------------------------------------------------------------
        $conciliationHearings = array_values(array_filter($hearings, fn($h) => $h['hearing_type'] === 'Conciliation'));
        $hasConciliation = count($conciliationHearings) > 0 || !empty($pangkat) || ($case['case_status'] ?? '') === 'Conciliation';

        if (!empty($settlement) && count($conciliationHearings) > 0) {
            $stages[] = [
                'id' => 'conciliation',
                'number' => 7,
                'title' => 'Pangkat / Conciliation',
                'subtitle' => 'Settlement Reached',
                'state' => 'completed',
                'date' => $settlement['settlement_date'] ?? null,
                'details' => 'Amicable settlement reached during conciliation proceedings.',
            ];
        } elseif ($hasConciliation) {
            $isCaseClosed = in_array($case['case_status'] ?? '', ['Settled', 'CFA Issued', 'Dismissed', 'Archived'], true);
            $stages[] = [
                'id' => 'conciliation',
                'number' => 7,
                'title' => 'Pangkat / Conciliation',
                'subtitle' => !empty($pangkat) ? 'Pangkat Formed' : 'In Conciliation',
                'state' => $isCaseClosed ? 'completed' : 'current',
                'date' => $pangkat['formation_date'] ?? null,
                'details' => sprintf('Conciliation stage active. Pangkat Chairman: %s.', $pangkat['chairman_name'] ?? 'Assigned'),
            ];
        } else {
            $stages[] = [
                'id' => 'conciliation',
                'number' => 7,
                'title' => 'Pangkat / Conciliation',
                'subtitle' => 'Not Started',
                'state' => 'upcoming',
                'date' => null,
                'details' => 'Case has not proceeded to Pangkat formation or conciliation.',
            ];
        }

        // -------------------------------------------------------------
        // STAGE 8: CFA / Further Procedure
        // -------------------------------------------------------------
        if (!empty($cfa)) {
            $stages[] = [
                'id' => 'cfa',
                'number' => 8,
                'title' => 'CFA / Further Procedure',
                'subtitle' => 'CFA Issued · ' . date('M j, Y', strtotime($cfa['issuance_date'])),
                'state' => 'completed',
                'date' => $cfa['issuance_date'],
                'details' => 'Certificate to File Action (CFA) issued. Reason: ' . $cfa['reason'],
            ];
        } elseif (($case['case_status'] ?? '') === 'CFA Issued') {
            $stages[] = [
                'id' => 'cfa',
                'number' => 8,
                'title' => 'CFA / Further Procedure',
                'subtitle' => 'CFA Issued',
                'state' => 'completed',
                'date' => null,
                'details' => 'Certificate to File Action issued.',
            ];
        } elseif (count($mediationHearings) >= 3 && count($conciliationHearings) >= 3 && empty($settlement)) {
            $stages[] = [
                'id' => 'cfa',
                'number' => 8,
                'title' => 'CFA / Further Procedure',
                'subtitle' => 'Eligible for CFA',
                'state' => 'current',
                'date' => null,
                'details' => 'Mediation and conciliation sessions exhausted without settlement; eligible for Certificate to File Action.',
            ];
        } else {
            $stages[] = [
                'id' => 'cfa',
                'number' => 8,
                'title' => 'CFA / Further Procedure',
                'subtitle' => 'Not Applicable',
                'state' => 'upcoming',
                'date' => null,
                'details' => 'CFA not required or not yet reached.',
            ];
        }

        // -------------------------------------------------------------
        // STAGE 9: Resolution / Closure
        // -------------------------------------------------------------
        $caseStatus = $case['case_status'] ?? $complaint['status'] ?? 'Filed';
        $finalStatus = null;
        if (!empty($settlement) || $caseStatus === 'Settled') {
            $finalStatus = 'Amicable Settlement';
        } elseif (!empty($cfa) || $caseStatus === 'CFA Issued') {
            $finalStatus = 'CFA Issued';
        } elseif (!empty($arbitration) || $caseStatus === 'Arbitration') {
            $finalStatus = 'Arbitration Award';
        } elseif ($caseStatus === 'Dismissed') {
            $finalStatus = 'Dismissed';
        } elseif ($caseStatus === 'Archived') {
            $finalStatus = 'Archived';
        }

        if ($finalStatus !== null) {
            $stages[] = [
                'id' => 'resolution',
                'number' => 9,
                'title' => 'Resolution / Closure',
                'subtitle' => $finalStatus,
                'state' => 'completed',
                'date' => $settlement['settlement_date'] ?? $cfa['issuance_date'] ?? $case['archived_date'] ?? null,
                'details' => 'Case officially closed with disposition: ' . $finalStatus,
            ];
        } else {
            $stages[] = [
                'id' => 'resolution',
                'number' => 9,
                'title' => 'Resolution / Closure',
                'subtitle' => 'Pending',
                'state' => 'upcoming',
                'date' => null,
                'details' => 'Case is still actively in progress.',
            ];
        }

        return $stages;
    }

    private function computeButtonActions(
        array $complaint,
        ?array $case,
        array $summons,
        array $proofs,
        array $hearings,
        bool $summonsPrerequisiteMet
    ): array {
        $caseId = $case ? (int) $case['case_id'] : null;
        $summonsCount = count($summons);

        // Summons button state
        $summonButton = [
            'label' => 'Issue 1st Summon',
            'action' => 'issue',
            'disabled' => false,
            'url' => null,
            'tooltip' => 'Issue the 1st summons and record proof of service.',
        ];

        $anyServed = false;
        foreach ($proofs as $p) {
            if (($p['service_result'] ?? '') === 'Served') {
                $anyServed = true;
                break;
            }
        }

        if ($anyServed) {
            $summonButton['label'] = 'Summons Served';
            $summonButton['action'] = 'view_proof';
            $summonButton['disabled'] = false;
            $summonButton['url'] = '../gps/proof-service.php?case_id=' . $caseId;
            $summonButton['tooltip'] = 'Summons successfully served. View proof of service history.';
        } elseif ($summonsCount === 0) {
            $summonButton['label'] = 'Issue 1st Summon';
            $summonButton['action'] = 'issue';
            $summonButton['disabled'] = false;
            $summonButton['tooltip'] = 'Issue the 1st summons and record proof of service.';
        } elseif ($summonsCount === 1) {
            $summonButton['label'] = 'Issue 2nd Summon';
            $summonButton['action'] = 'issue';
            $summonButton['disabled'] = false;
            $summonButton['tooltip'] = 'Issue the 2nd attempt of summons.';
        } else {
            $latestSummon = end($summons);
            $summonButton['label'] = '2nd Summon Issued';
            $summonButton['action'] = 'view_proof';
            $summonButton['disabled'] = false;
            $summonButton['url'] = '../gps/proof-service.php?case_id=' . $caseId . '&document_id=' . ($latestSummon['document_id'] ?? '');
            $summonButton['tooltip'] = '2nd summons attempt issued. Click to record or view proof of service.';
        }

        // Mediation button state: only clickable after a summon is served
        $mediationButton = [
            'label' => 'Schedule 1st Mediation',
            'disabled' => !$summonsPrerequisiteMet,
            'prerequisite_met' => $summonsPrerequisiteMet,
            'tooltip' => $summonsPrerequisiteMet
                ? 'Proceed to schedule 1st Mediation hearing.'
                : '1st Mediation is unavailable until a summons has been successfully served.',
        ];

        return [
            'summon_button' => $summonButton,
            'mediation_button' => $mediationButton,
        ];

    }

    private function buildUnifiedHistory(
        array $complaint,
        ?array $case,
        array $summons,
        array $proofs,
        array $hearings,
        array $caseHistory,
        ?array $settlement,
        ?array $cfa
    ): array {
        $events = [];

        // 1. Complaint Filed
        $events[] = [
            'category' => 'Complaint',
            'title' => 'Complaint Filed',
            'date' => $complaint['created_at'],
            'actor' => 'Complainant / Intake Staff',
            'badge' => 'Filed',
            'badge_class' => 'status-filed',
            'details' => sprintf('Complaint #%s filed: "%s"', $complaint['complaint_number'], $complaint['complaint_title']),
        ];

        // 2. Case Docketed
        if ($case) {
            $events[] = [
                'category' => 'Docketing',
                'title' => 'Case Docketed',
                'date' => $case['docket_date'] . ' 08:00:00',
                'actor' => 'Barangay Office',
                'badge' => 'Docketed',
                'badge_class' => 'status-docketed',
                'details' => sprintf('Official Case #%s created with status %s.', $case['case_number'], $case['case_status']),
            ];
        }

        // 3. Summons Issuances
        foreach ($summons as $idx => $s) {
            $num = $idx + 1;
            $events[] = [
                'category' => 'Summons',
                'title' => sprintf('Summons #%d Issued', $num),
                'date' => $s['generated_at'],
                'actor' => $s['issued_by_name'] ?: 'Authorized Officer',
                'badge' => 'Issued',
                'badge_class' => 'badge-info',
                'details' => sprintf('KP Form 9 (Summons #%d) issued and assigned to Summons Server for service.', $num),
            ];
        }

        // 4. Service Attempts & Proof of Service
        foreach ($proofs as $idx => $p) {
            $attNum = $idx + 1;
            $res = $p['service_result'] ?? 'Attempted';
            $isServed = ($res === 'Served');
            $events[] = [
                'category' => 'Service',
                'title' => sprintf('Summons Service Attempt #%d — %s', $attNum, $res),
                'date' => $p['served_date'],
                'actor' => $p['served_by_name'] ?: 'Summons Server',
                'badge' => $res,
                'badge_class' => $isServed ? 'badge-success' : 'badge-danger',
                'details' => sprintf('Result: %s.%s%s', $res, $p['remarks'] ? ' Remarks: ' . $p['remarks'] : '', $p['image_path'] ? ' [Proof photo attached]' : ''),
            ];
        }

        // 5. Hearings & Attendance
        foreach ($hearings as $h) {
            $events[] = [
                'category' => 'Hearing',
                'title' => sprintf('%s Hearing Scheduled', $h['hearing_type']),
                'date' => $h['hearing_date'],
                'actor' => 'Hearing Officer',
                'badge' => $h['hearing_type'],
                'badge_class' => 'badge-primary',
                'details' => sprintf('Venue: %s.%s', $h['venue'], $h['remarks'] ? ' Remarks: ' . $h['remarks'] : ''),
            ];
        }

        // 6. Settlements
        if ($settlement) {
            $events[] = [
                'category' => 'Resolution',
                'title' => 'Amicable Settlement Reached',
                'date' => $settlement['settlement_date'] . ' 12:00:00',
                'actor' => 'Lupong Tagapamayapa',
                'badge' => 'Settled',
                'badge_class' => 'badge-success',
                'details' => 'Agreement details: ' . $settlement['agreement_details'],
            ];
        }

        // 7. CFA
        if ($cfa) {
            $events[] = [
                'category' => 'Resolution',
                'title' => 'Certificate to File Action (CFA) Issued',
                'date' => $cfa['issuance_date'] . ' 12:00:00',
                'actor' => 'Punong Barangay / Secretary',
                'badge' => 'CFA Issued',
                'badge_class' => 'badge-danger',
                'details' => 'Reason: ' . $cfa['reason'],
            ];
        }

        // Sort events chronologically (newest first for display, or oldest first)
        usort($events, function ($a, $b) {
            return strcmp($a['date'] ?? '', $b['date'] ?? '');
        });

        return $events;
    }

    private function ordinal(int $num): string
    {
        if ($num === 1) return '1st';
        if ($num === 2) return '2nd';
        if ($num === 3) return '3rd';
        return $num . 'th';
    }
}
