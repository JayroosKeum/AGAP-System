<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditService.php';

class HearingAttendance
{
    private PDO $conn;
    private AuditService $audit;

    public function __construct(?PDO $conn = null)
    {
        $this->conn = $conn ?? (new Database())->connect();
        $this->audit = new AuditService();
    }

    /**
     * Retrieves attendance details, party records, and situational analysis for a specific hearing.
     */
    public function getHearingAttendance(int $hearingId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.remarks AS hearing_remarks,
                    c.case_number, c.case_status, c.docket_date, c.complaint_id,
                    co.complaint_number, co.complaint_title
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             INNER JOIN complaints co ON co.complaint_id = c.complaint_id
             WHERE h.hearing_id = ?"
        );
        $stmt->execute([$hearingId]);
        $hearing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }

        // Count summons issued for this case to differentiate 1st vs 2nd summons
        $summonsStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM generated_documents gd
             INNER JOIN document_templates dt ON dt.template_id = gd.template_id
             WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')"
        );
        $summonsStmt->execute([$hearing['case_id']]);
        $summonsCount = (int) $summonsStmt->fetchColumn();

        // Get parties for this case (Complainants, Respondents, Witnesses)
        $partiesStmt = $this->conn->prepare(
            "SELECT cp.resident_id, cp.party_type,
                    TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name,
                    r.contact_no, r.purok, r.address,
                    ha.attendance_id, ha.attendance_status, ha.is_justified, ha.justification_reason,
                    ha.remarks AS attendance_remarks, ha.recorded_at,
                    (SELECT COUNT(*) FROM hearing_party_services hps WHERE hps.hearing_id = ? AND hps.resident_id = cp.resident_id AND hps.service_result = 'Served') AS successful_service_count,
                    (SELECT sd.delivery_status FROM summon_deliveries sd WHERE sd.hearing_id = ? AND (sd.resident_id = cp.resident_id OR sd.party_type = cp.party_type) ORDER BY sd.delivery_id DESC LIMIT 1) AS summon_delivery_status,
                    (SELECT sd.unserved_reason FROM summon_deliveries sd WHERE sd.hearing_id = ? AND (sd.resident_id = cp.resident_id OR sd.party_type = cp.party_type) ORDER BY sd.delivery_id DESC LIMIT 1) AS summon_unserved_reason,
                    (SELECT sd.served_at FROM summon_deliveries sd WHERE sd.hearing_id = ? AND (sd.resident_id = cp.resident_id OR sd.party_type = cp.party_type) ORDER BY sd.delivery_id DESC LIMIT 1) AS summon_served_at,
                    TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS recorded_by_name
             FROM complaint_parties cp
             INNER JOIN residents r ON r.resident_id = cp.resident_id
             LEFT JOIN hearing_attendance ha ON ha.hearing_id = ? AND ha.resident_id = cp.resident_id
             LEFT JOIN users u ON u.user_id = ha.recorded_by
             WHERE cp.complaint_id = ?
             ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent', 'Witness'), r.last_name, r.first_name"
        );
        $partiesStmt->execute([$hearingId, $hearingId, $hearingId, $hearingId, $hearingId, $hearing['complaint_id']]);
        $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize party attendance info
        $parties = array_map(function ($p) {
            $summonStatus = $p['summon_delivery_status'] ?? null;
            $summonServed = in_array($summonStatus, ['Served Personal', 'Served Substituted', 'Served Refused'], true);
            $serviceConfirmed = ((int) ($p['successful_service_count'] ?? 0) > 0) || $summonServed;

            return [
                'resident_id' => (int) $p['resident_id'],
                'resident_name' => $p['resident_name'] ?: 'Resident #' . $p['resident_id'],
                'full_name' => $p['resident_name'] ?: 'Resident #' . $p['resident_id'],
                'party_type' => $p['party_type'],
                'contact_no' => $p['contact_no'] ?? '',
                'purok' => $p['purok'] ?? '',
                'purok_name' => $p['purok'] ?? '',
                'address' => $p['address'] ?? '',
                'attendance_id' => $p['attendance_id'] ? (int) $p['attendance_id'] : null,
                'service_confirmed' => $serviceConfirmed,
                'summon_delivery_status' => $summonStatus ?: 'Pending',
                'summon_unserved_reason' => $p['summon_unserved_reason'] ?? '',
                'summon_served_at' => $p['summon_served_at'] ?? null,
                'attendance_status' => $p['attendance_status'] ?: 'Pending',
                'is_justified' => (int) ($p['is_justified'] ?? 0),
                'justification_reason' => $p['justification_reason'] ?? '',
                'remarks' => $p['attendance_remarks'] ?? '',
                'recorded_at' => $p['recorded_at'] ?? null,
                'recorded_by_name' => $p['recorded_by_name'] ?? null,
            ];
        }, $parties);

        $serviceHistoryStmt = $this->conn->prepare("SELECT s.service_id, s.document_id, s.service_date, s.service_result, s.reason, s.officer_return, s.supporting_file, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS officer_name, dt.template_name FROM hearing_party_services s LEFT JOIN users u ON u.user_id = s.serving_officer LEFT JOIN generated_documents gd ON gd.document_id = s.document_id LEFT JOIN document_templates dt ON dt.template_id = gd.template_id WHERE s.hearing_id = ? AND s.resident_id = ? ORDER BY s.service_date DESC, s.service_id DESC");
        $explanationsStmt = $this->conn->prepare("SELECT he.explanation_id, he.explanation, he.outcome, he.supporting_file, he.decided_at, he.created_at, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS decided_by_name FROM hearing_explanations he LEFT JOIN users u ON u.user_id = he.decided_by WHERE he.hearing_id = ? AND he.resident_id = ? ORDER BY he.created_at DESC");
        $legalActionsStmt = $this->conn->prepare("SELECT hla.legal_action_id, hla.action_type, hla.status, hla.details, hla.reviewed_at, hla.created_at, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS reviewed_by_name FROM hearing_legal_actions hla LEFT JOIN users u ON u.user_id = hla.reviewed_by WHERE hla.hearing_id = ? AND hla.resident_id = ? ORDER BY hla.created_at DESC");
        foreach ($parties as &$party) {
            if (!in_array($party['party_type'], ['Complainant', 'Respondent'], true)) { $party['service_history'] = []; $party['explanations'] = []; $party['legal_actions'] = []; continue; }
            $serviceHistoryStmt->execute([$hearingId,$party['resident_id']]);
            $party['service_history'] = $serviceHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
            $explanationsStmt->execute([$hearingId, $party['resident_id']]);
            $party['explanations'] = $explanationsStmt->fetchAll(PDO::FETCH_ASSOC);
            $legalActionsStmt->execute([$hearingId, $party['resident_id']]);
            $party['legal_actions'] = $legalActionsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($party);

        // Evaluate situation based on primary Complainant & Respondent attendance
        // Available documents for service dropdown
        $docStmt = $this->conn->prepare("SELECT gd.document_id, gd.template_id, dt.template_name, gd.service_status, gd.generated_at, gd.file_path FROM generated_documents gd INNER JOIN document_templates dt ON dt.template_id = gd.template_id WHERE gd.case_id = ? ORDER BY gd.generated_at DESC");
        $docStmt->execute([$hearing['case_id']]);
        $availableDocuments = $docStmt->fetchAll(PDO::FETCH_ASSOC);

        // Summons servers for officer dropdown
        $serversStmt = $this->conn->query("SELECT u.user_id, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS full_name FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE u.status = 'Active' AND r.role_name = 'Summons Server' ORDER BY u.first_name, u.last_name");
        $summonsServers = $serversStmt->fetchAll(PDO::FETCH_ASSOC);

        $situation = $this->evaluateSituation($parties, $summonsCount, $hearing['hearing_type'], $hearing['case_status']);

        return [
            'success' => true,
            'hearing' => $hearing,
            'summons_count' => $summonsCount,
            'parties' => $parties,
            'situation' => $situation,
            'available_documents' => $availableDocuments,
            'summons_servers' => $summonsServers,
        ];
    }

    /**
     * Records or updates attendance for parties in a scheduled hearing.
     */
    public function recordAttendance(int $hearingId, array $records, int $userId): array
    {
        $hearingStmt = $this->conn->prepare(
            "SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue,
                    c.case_number, c.case_status, c.complaint_id
             FROM hearings h
             INNER JOIN cases c ON c.case_id = h.case_id
             WHERE h.hearing_id = ?"
        );
        $hearingStmt->execute([$hearingId]);
        $hearing = $hearingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hearing) {
            return ['success' => false, 'message' => 'Hearing not found.'];
        }

        try {
            $this->conn->beginTransaction();

            $upsertAttendance = $this->conn->prepare(
                "INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified, justification_reason, remarks, verification_status, determination_notes, recorded_by, recorded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                     attendance_status = VALUES(attendance_status),
                     is_justified = VALUES(is_justified),
                     justification_reason = VALUES(justification_reason),
                     remarks = VALUES(remarks),
                     verification_status = VALUES(verification_status),
                     determination_notes = VALUES(determination_notes),
                     recorded_by = VALUES(recorded_by),
                     recorded_at = CURRENT_TIMESTAMP"
            );

            $serviceLookup = $this->conn->prepare("
                SELECT (
                    (SELECT COUNT(*) FROM hearing_party_services WHERE hearing_id = ? AND resident_id = ? AND service_result = 'Served')
                    +
                    (SELECT COUNT(*) FROM summon_deliveries WHERE hearing_id = ? AND (resident_id = ? OR party_type = ?) AND delivery_status IN ('Served Personal', 'Served Substituted', 'Served Refused'))
                ) AS total_served
            ");

            $savedCount = 0;
            $updatedParties = [];

            $partyLookupStmt = $this->conn->prepare("SELECT party_type FROM complaint_parties WHERE complaint_id = ? AND resident_id = ? LIMIT 1");

            foreach ($records as $item) {
                $residentId = filter_var($item['resident_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$residentId || !is_array($item)) {
                    throw new InvalidArgumentException('Each attendance record must identify a valid party.');
                }

                $partyLookupStmt->execute([$hearing['complaint_id'], $residentId]);
                $foundType = $partyLookupStmt->fetchColumn();

                if (!$foundType) {
                    throw new InvalidArgumentException('The selected party does not belong to this hearing case.');
                }
                $partyType = $foundType;

                $status = $item['attendance_status'] ?? '';
                if ($partyType === 'Witness') {
                    if (!in_array($status, ['Present', 'Absent', 'Late', 'Excused', 'Not Served', 'Pending Verification'], true)) {
                        $status = 'Present';
                    }
                } else {
                    if (!in_array($status, ['Present', 'Absent', 'Late', 'Excused', 'Not Served', 'Pending Verification'], true)) {
                        throw new InvalidArgumentException('Choose a valid attendance status (Present, Absent, Late, Excused, Not Served, or Pending Verification).');
                    }
                    if ($status === 'Absent') {
                        $serviceLookup->execute([$hearingId, $residentId, $hearingId, $residentId, $partyType]);
                        if ((int) $serviceLookup->fetchColumn() < 1) {
                            throw new InvalidArgumentException('Cannot record Failure to Appear because service for this party has not been confirmed. Record Not Served, Pending Verification, or verify service first.');
                        }
                    }
                }

                $isJustified = !empty($item['is_justified']) || $status === 'Excused' ? 1 : 0;
                $justificationReason = trim((string) ($item['justification_reason'] ?? ''));
                if ($isJustified && $justificationReason === '') {
                    $justificationReason = ($status === 'Excused') ? 'Excused Non-Appearance' : 'Verified Justification';
                } elseif (!$isJustified) {
                    $justificationReason = null;
                }

                $remarks = trim((string) ($item['remarks'] ?? ''));
                $verificationStatus = in_array($item['verification_status'] ?? '', ['Pending', 'Verified Served', 'Unverified', 'Excused Approved'], true)
                    ? $item['verification_status']
                    : ($status === 'Present' ? 'Verified Served' : 'Pending');
                $determinationNotes = trim((string) ($item['determination_notes'] ?? ''));

                $upsertAttendance->execute([
                    $hearingId,
                    $residentId,
                    $status,
                    $isJustified,
                    $justificationReason,
                    $remarks ?: null,
                    $verificationStatus,
                    $determinationNotes ?: null,
                    $userId
                ]);

                $updatedParties[] = [
                    'resident_id' => $residentId,
                    'party_type' => $partyType,
                    'attendance_status' => $status,
                    'is_justified' => $isJustified,
                    'justification_reason' => $justificationReason,
                    'remarks' => $remarks
                ];
                $savedCount++;
            }

            // Summons count for situational recommendation
            $summonsStmt = $this->conn->prepare(
                "SELECT COUNT(*) FROM generated_documents gd
                 INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                 WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')"
            );
            $summonsStmt->execute([$hearing['case_id']]);
            $summonsCount = (int) $summonsStmt->fetchColumn();

            $situation = $this->evaluateSituation($updatedParties, $summonsCount, $hearing['hearing_type'], $hearing['case_status']);

            // Record in case history
            $histRemarks = sprintf(
                'Hearing attendance recorded for %s (%s). Situation: %s.',
                $hearing['hearing_type'],
                date('M j, Y g:i A', strtotime($hearing['hearing_date'])),
                $situation['title']
            );
            $histStmt = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)");
            $histStmt->execute([$hearing['case_id'], $hearing['case_status'], $histRemarks, $userId]);

            $this->conn->commit();

            // Log in audit trail
            $this->audit->log($userId, 'Recorded hearing attendance: ' . $situation['title'], 'Hearings', $hearingId);

            return [
                'success' => true,
                'message' => 'Hearing attendance recorded successfully. ' . $situation['title'] . '.',
                'saved_count' => $savedCount,
                'situation' => $situation
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Error recording hearing attendance: ' . $e->getMessage());
            return ['success' => false, 'message' => ($e instanceof InvalidArgumentException) ? $e->getMessage() : 'Unable to record hearing attendance. Please try again.'];
        }
    }

    /**
     * Evaluates attendance records against Katarungang Pambarangay (KP) rules and determines the situation.
     */
    public function evaluateSituation(array $parties, int $summonsCount, string $hearingType, string $caseStatus): array
    {
        $comp = null;
        $resp = null;

        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant' && !$comp) {
                $comp = $p;
            } elseif ($p['party_type'] === 'Respondent' && !$resp) {
                $resp = $p;
            }
        }

        $compStatus = $comp['attendance_status'] ?? 'Pending';
        $respStatus = $resp['attendance_status'] ?? 'Pending';
        $compJustified = !empty($comp['is_justified']) || $compStatus === 'Excused';
        $respJustified = !empty($resp['is_justified']) || $respStatus === 'Excused';

        // Check if pending / unrecorded
        if ($compStatus === 'Pending' && $respStatus === 'Pending') {
            return [
                'code' => 'PENDING_RECORDING',
                'title' => 'Attendance Pending',
                'badge' => 'badge-secondary',
                'severity' => 'neutral',
                'summary' => 'Attendance has not yet been recorded for this scheduled session.',
                'kp_reference' => 'Katarungang Pambarangay Rules',
                'legal_consequence' => 'Pending attendance intake upon appearance of parties.',
                'recommended_actions' => [
                    'Take attendance of complainant and respondent upon their arrival at the Barangay Hall.'
                ],
                'action_keys' => ['record_attendance']
            ];
        }

        // Situation: Not Served
        if ($compStatus === 'Not Served' && $respStatus === 'Not Served') {
            return [
                'code' => 'BOTH_NOT_SERVED',
                'title' => 'Both Parties Not Served',
                'badge' => 'badge-warning',
                'severity' => 'warning',
                'summary' => 'Neither the complainant nor the respondent has verified service of notice/summons.',
                'kp_reference' => 'Sec. 410(b), RA 7160 (Service of Summons)',
                'legal_consequence' => 'No failure to appear or adverse sanctions may be imposed without verified service.',
                'recommended_actions' => [
                    'Review Officer’s Return reasons for service failure.',
                    'Verify addresses of parties and re-issue notices/summonses for renewed service.'
                ],
                'action_keys' => ['reissue_summons', 'reschedule']
            ];
        }

        if ($respStatus === 'Not Served') {
            return [
                'code' => 'RESPONDENT_NOT_SERVED',
                'title' => 'Respondent Not Served',
                'badge' => 'badge-warning',
                'severity' => 'warning',
                'summary' => 'Summons or notice has not been successfully served to the respondent. Failure to appear cannot be charged.',
                'kp_reference' => 'Sec. 410(b), RA 7160 (Due Service Requirement)',
                'legal_consequence' => 'Proceedings cannot impose sanctions, counter-claim bars, or Certificate to File Action due to lack of verified service.',
                'recommended_actions' => [
                    'Review Officer’s Return for failure reason (e.g. wrong address, not found).',
                    'Re-issue summons with updated address and dispatch Summons Server.'
                ],
                'action_keys' => ['reissue_summons', 'reschedule']
            ];
        }

        if ($compStatus === 'Not Served') {
            return [
                'code' => 'COMPLAINANT_NOT_SERVED',
                'title' => 'Complainant Not Served',
                'badge' => 'badge-warning',
                'severity' => 'warning',
                'summary' => 'Notice was not successfully served to the complainant. Failure to appear or complaint dismissal cannot be charged.',
                'kp_reference' => 'Katarungang Pambarangay Rules',
                'legal_consequence' => 'Complaint cannot be dismissed for failure to prosecute without verified service of notice.',
                'recommended_actions' => [
                    'Verify complainant contact information and address.',
                    'Re-issue notice of hearing and reschedule appearance date.'
                ],
                'action_keys' => ['reissue_notice', 'reschedule']
            ];
        }

        // Situation 1: Both Present
        if ($compStatus === 'Present' && $respStatus === 'Present') {
            return [
                'code' => 'BOTH_PRESENT',
                'title' => 'Both Parties Present',
                'badge' => 'badge-success',
                'severity' => 'success',
                'summary' => 'Both complainant and respondent appeared for the scheduled session.',
                'kp_reference' => 'Sec. 410 / Sec. 412, RA 7160',
                'legal_consequence' => 'The hearing may proceed to formal mediation discussion before the Punong Barangay (or conciliation before the Pangkat).',
                'recommended_actions' => [
                    'Proceed with mediation/conciliation discussion.',
                    'If an agreement is reached: Draft and execute KP Form 16 (Amicable Settlement).',
                    'If more time is needed: Schedule the next session (up to 3 mediation or conciliation sessions within the 15-day limit).',
                    'If parties fail to agree: Elevate to Pangkat Tagapagkasundo (if currently in Mediation) or issue Certificate to File Action (CFA).'
                ],
                'action_keys' => ['settlement', 'reschedule', 'elevate_pangkat', 'cfa']
            ];
        }

        // Situation 2: Respondent Absent (Unjustified), Complainant Present
        if ($compStatus === 'Present' && $respStatus === 'Absent' && !$respJustified) {
            if ($summonsCount <= 1) {
                return [
                    'code' => 'RESPONDENT_UNJUSTIFIED_ABSENT_1ST',
                    'title' => 'Respondent Absent (Unjustified - 1st Absence)',
                    'badge' => 'badge-danger',
                    'severity' => 'danger',
                    'summary' => 'Respondent willfully failed to appear without justifiable cause after the 1st summons.',
                    'kp_reference' => 'Sec. 415, RA 7160 (Willful Failure to Appear)',
                    'legal_consequence' => 'Under KP Law, respondent must be given a second notice/summons before sanctions and adverse certification apply.',
                    'recommended_actions' => [
                        'Issue 2nd Summons (KP Form 9) with explicit warning of indirect contempt and waiver of counterclaims.',
                        'Reschedule the appearance date for the 2nd Mediation session.'
                    ],
                    'action_keys' => ['reissue_summons', 'reschedule']
                ];
            } else {
                return [
                    'code' => 'RESPONDENT_UNJUSTIFIED_ABSENT_2ND',
                    'title' => 'Respondent Repeated Unjustified Non-Appearance (2nd Absence)',
                    'badge' => 'badge-danger',
                    'severity' => 'critical',
                    'summary' => 'Respondent failed to appear after 2 summons attempts without justifiable cause.',
                    'kp_reference' => 'Sec. 415, RA 7160 (Barred from Counterclaim & Court Action Certificate)',
                    'legal_consequence' => 'Respondent is legally barred from filing a counterclaim in court regarding this dispute. Punong Barangay / Lupon may immediately issue Certificate to File Action (CFA) to complainant.',
                    'recommended_actions' => [
                        'Issue Certificate to File Action (CFA - KP Form 20) allowing complainant to file case directly in court.',
                        'Note in records that respondent is barred from filing a counterclaim.',
                        'Option to transmit certification of indirect contempt to Municipal Trial Court (MTC).'
                    ],
                    'action_keys' => ['cfa', 'contempt_cert']
                ];
            }
        }

        // Situation 3: Complainant Absent (Unjustified), Respondent Present
        if ($compStatus === 'Absent' && !$compJustified && $respStatus === 'Present') {
            return [
                'code' => 'COMPLAINANT_UNJUSTIFIED_ABSENT',
                'title' => 'Complainant Absent (Unjustified Non-Appearance)',
                'badge' => 'badge-danger',
                'severity' => 'critical',
                'summary' => 'Complainant willfully failed or refused to appear without justifiable cause while respondent was present.',
                'kp_reference' => 'Sec. 415, RA 7160 (Sanctions for Non-Appearance)',
                'legal_consequence' => 'Complainant is barred from seeking judicial recourse in court for the same cause of action, and complaint is subject to dismissal.',
                'recommended_actions' => [
                    'Dismiss the complaint for lack of interest / failure to prosecute.',
                    'Issue Certificate of Barred Action to the respondent certifying complainant\'s unjustified refusal to appear.',
                    'Option to give 1 final warning / reschedule once if notice delivery was delayed.'
                ],
                'action_keys' => ['dismiss_complaint', 'reschedule_warning']
            ];
        }

        // Situation 4: Both Absent (Unjustified)
        if ($compStatus === 'Absent' && !$compJustified && $respStatus === 'Absent' && !$respJustified) {
            return [
                'code' => 'BOTH_UNJUSTIFIED_ABSENT',
                'title' => 'Both Parties Absent (Unjustified)',
                'badge' => 'badge-danger',
                'severity' => 'danger',
                'summary' => 'Neither the complainant nor the respondent appeared, and neither presented a valid justification.',
                'kp_reference' => 'Sec. 415, RA 7160',
                'legal_consequence' => 'Total non-appearance indicates abandonment or lack of interest by both parties.',
                'recommended_actions' => [
                    'Dismiss complaint without prejudice or archive the case.',
                    'Or schedule a final notice if delivery issues are suspected.'
                ],
                'action_keys' => ['dismiss_complaint', 'archive_case', 'reschedule']
            ];
        }

        // Situation 5: Either or Both Excused / Justified
        if ($compJustified || $respJustified) {
            $excusedParty = ($compJustified && $respJustified)
                ? 'Both parties have'
                : ($compJustified ? 'Complainant has' : 'Respondent has');

            return [
                'code' => 'JUSTIFIED_EXCUSED',
                'title' => 'Justified / Excused Non-Appearance',
                'badge' => 'badge-warning',
                'severity' => 'warning',
                'summary' => $excusedParty . ' verified valid excuse (medical emergency, official duty, or force majeure).',
                'kp_reference' => 'Sec. 410(b), RA 7160',
                'legal_consequence' => 'Under KP Law, no sanctions, dismissal, or adverse CFA apply for verified justifiable absences.',
                'recommended_actions' => [
                    'Reschedule the hearing to the next available date without prejudice.',
                    'If in Mediation and extra calendar days are needed, log a Justified Suspension (Pause Clock).'
                ],
                'action_keys' => ['reschedule', 'pause_clock']
            ];
        }

        // Situation 6: Late Appearance
        if ($compStatus === 'Late' || $respStatus === 'Late') {
            return [
                'code' => 'LATE_APPEARANCE',
                'title' => 'Late Appearance Recorded',
                'badge' => 'badge-info',
                'severity' => 'info',
                'summary' => 'One or more parties arrived late. Hearing may proceed or adjourn briefly.',
                'kp_reference' => 'Katarungang Pambarangay Rules',
                'legal_consequence' => 'Appearance is acknowledged; proceedings may commence.',
                'recommended_actions' => [
                    'Proceed with the scheduled session.',
                    'Or adjourn to a later time today if parties agree.'
                ],
                'action_keys' => ['proceed', 'reschedule']
            ];
        }

        // Fallback / Partial
        return [
            'code' => 'PARTIAL_ATTENDANCE',
            'title' => 'Attendance Partially Recorded',
            'badge' => 'badge-secondary',
            'severity' => 'neutral',
            'summary' => sprintf('Complainant: %s | Respondent: %s', $compStatus, $respStatus),
            'kp_reference' => 'Katarungang Pambarangay Rules',
            'legal_consequence' => 'Review individual party remarks to determine proceeding.',
            'recommended_actions' => ['Review party notes and determine whether to proceed or reschedule.'],
            'action_keys' => ['reschedule', 'proceed']
        ];
    }

    /**
     * Retrieves all mediation and conciliation hearings with their attendance status and KPI metrics.
     */
    public function getAttendanceMonitoringList(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $stage = trim((string) ($filters['stage'] ?? ''));
        $situationFilter = trim((string) ($filters['situation'] ?? ''));
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $q = trim((string) ($filters['q'] ?? ''));

        $sql = "
            SELECT h.hearing_id, h.case_id, h.hearing_type, h.hearing_date, h.venue, h.remarks,
                   c.case_number, c.case_status, c.complaint_id,
                   co.complaint_number, co.complaint_title
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            INNER JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE h.hearing_type IN ('Mediation', 'Conciliation')
        ";
        $params = [];

        if ($stage !== '' && in_array($stage, ['Mediation', 'Conciliation'], true)) {
            $sql .= " AND h.hearing_type = ?";
            $params[] = $stage;
        }

        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $sql .= " AND DATE(h.hearing_date) >= ?";
            $params[] = $dateFrom;
        }

        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $sql .= " AND DATE(h.hearing_date) <= ?";
            $params[] = $dateTo;
        }

        if ($q !== '') {
            $sql .= " AND (c.case_number LIKE ? OR co.complaint_number LIKE ? OR co.complaint_title LIKE ? OR h.venue LIKE ?)";
            $term = "%{$q}%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY h.hearing_date DESC, h.hearing_id DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $allHearings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all attendance and parties for these hearings
        $results = [];
        $kpi = [
            'total_hearings' => count($allHearings),
            'both_present' => 0,
            'unjustified_absences' => 0,
            'excused_count' => 0,
            'pending_count' => 0,
        ];

        foreach ($allHearings as $h) {
            $hId = (int) $h['hearing_id'];
            $attDetails = $this->getHearingAttendance($hId);
            $situation = $attDetails['situation'];

            // Update KPI
            if ($situation['code'] === 'BOTH_PRESENT') {
                $kpi['both_present']++;
            } elseif (in_array($situation['code'], ['RESPONDENT_UNJUSTIFIED_ABSENT_1ST', 'RESPONDENT_UNJUSTIFIED_ABSENT_2ND', 'COMPLAINANT_UNJUSTIFIED_ABSENT', 'BOTH_UNJUSTIFIED_ABSENT'], true)) {
                $kpi['unjustified_absences']++;
            } elseif ($situation['code'] === 'JUSTIFIED_EXCUSED') {
                $kpi['excused_count']++;
            } elseif ($situation['code'] === 'PENDING_RECORDING') {
                $kpi['pending_count']++;
            }

            // Apply situation filter if requested
            if ($situationFilter !== '') {
                if ($situationFilter === 'both_present' && $situation['code'] !== 'BOTH_PRESENT') continue;
                if ($situationFilter === 'unjustified' && !str_contains($situation['code'], 'UNJUSTIFIED')) continue;
                if ($situationFilter === 'excused' && $situation['code'] !== 'JUSTIFIED_EXCUSED') continue;
                if ($situationFilter === 'pending' && $situation['code'] !== 'PENDING_RECORDING') continue;
            }

            $h['situation'] = $situation;
            $h['parties'] = $attDetails['parties'];
            $h['summons_count'] = $attDetails['summons_count'];
            $results[] = $h;
        }

        $totalFiltered = count($results);
        $totalPages = $totalFiltered > 0 ? (int) ceil($totalFiltered / $perPage) : 1;
        $offset = ($page - 1) * $perPage;
        $paginatedResults = array_slice($results, $offset, $perPage);

        return [
            'records' => $paginatedResults,
            'kpi' => $kpi,
            'pagination' => [
                'total_records' => $totalFiltered,
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
            ]
        ];
    }
}
