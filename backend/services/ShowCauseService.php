<?php



require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/AuditService.php';

require_once __DIR__ . '/SummonDeliveryService.php';

require_once __DIR__ . '/PDFService.php';



class ShowCauseService

{

    private PDO $conn;

    private AuditService $audit;

    private SummonDeliveryService $deliveryService;

    private ?PDFService $pdfService = null;



    public function __construct(?PDO $conn = null)

    {

        $this->conn = $conn ?? (new Database())->connect();

        $this->audit = new AuditService($this->conn);

        $this->deliveryService = new SummonDeliveryService($this->conn);



        try {

            $this->pdfService = new PDFService();

        } catch (Throwable $e) {

            $this->pdfService = null;

        }

    }



    /**

     * Records hearing attendance for both parties with independent toggles.

     * Enforces the rule that attendance and unexcused absences cannot be recorded

     * if summons or notices are unserved.

     */

    public function recordAttendance(int $hearingId, array $data, int $actorUserId): array

    {

        $partiesInput = (!empty($data['parties']) && is_array($data['parties'])) ? $data['parties'] : [];

        $absentComplainantIds = [];

        $absentRespondentIds = [];

        $validPerPartyStatuses = ['Present', 'Absent', 'Late', 'Excused', 'Not Served', 'Pending Verification'];

        if (!empty($partiesInput)) {
            $cpLookup = $this->conn->prepare("
                SELECT cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                INNER JOIN cases c ON c.complaint_id = cp.complaint_id
                INNER JOIN hearings h ON h.case_id = c.case_id
                WHERE h.hearing_id = ? AND cp.resident_id = ?
                LIMIT 1
            ");
            $compStatuses = [];
            $respStatuses = [];
            foreach ($partiesInput as $pItem) {
                $rId = (int) ($pItem['resident_id'] ?? 0);
                $cpLookup->execute([$hearingId, $rId]);
                $cpRow = $cpLookup->fetch(PDO::FETCH_ASSOC);
                if (!$cpRow) {
                    return ['success' => false, 'message' => 'All attendance records must belong to registered parties of this hearing.'];
                }
                $pType = (string) ($pItem['party_type'] ?? $cpRow['party_type']);
                $pStatus = trim((string) ($pItem['attendance_status'] ?? 'Pending Verification'));
                if ($pType !== 'Witness' && !in_array($pStatus, $validPerPartyStatuses, true)) {
                    return ['success' => false, 'message' => 'Invalid per-party attendance status: ' . $pStatus];
                }
                if ($pStatus === 'Absent' && in_array($pType, ['Complainant', 'Respondent'], true)) {
                    $ver = $this->deliveryService->verifyRecipientServiceForHearing($hearingId, $rId, $pType);
                    if (empty($ver['verified'])) {
                        return [
                            'success' => false,
                            'message' => 'Attendance locked for ' . ($cpRow['full_name'] ?: $pType) . ': '
                                . ($ver['next_action'] ?? 'Service has not been verified on the operative notice for this recipient.')
                        ];
                    }
                    if ($pType === 'Complainant') {
                        $absentComplainantIds[] = $rId;
                    } else {
                        $absentRespondentIds[] = $rId;
                    }
                }
                if ($pType === 'Complainant') $compStatuses[] = $pStatus;
                if ($pType === 'Respondent') $respStatuses[] = $pStatus;
            }

            $mapSummaryEnum = function (array $statuses): string {
                if (in_array('Absent', $statuses, true)) return 'Absent';
                if (in_array('Not Served', $statuses, true) || in_array('Pending Verification', $statuses, true)) {
                    return 'Pending';
                }
                if (in_array('Excused', $statuses, true) && !in_array('Present', $statuses, true) && !in_array('Late', $statuses, true)) {
                    return 'Excused';
                }
                if (in_array('Late', $statuses, true) && !in_array('Present', $statuses, true)) return 'Late';
                return !empty($statuses) ? 'Present' : 'Pending';
            };

            $complainantAtt = $mapSummaryEnum($compStatuses);
            $respondentAtt = $mapSummaryEnum($respStatuses);
        } else {
            $serviceCheck = $this->deliveryService->checkServiceStatus($hearingId);

            if (!$serviceCheck['allowed']) {

                return [

                    'success' => false,

                    'message' => 'Attendance locked: ' . $serviceCheck['message'],

                    'waiting_parties' => $serviceCheck['waiting_parties'] ?? [],

                ];

            }

            $complainantAtt = trim($data['complainant_attendance'] ?? '');

            $respondentAtt = trim($data['respondent_attendance'] ?? '');

            $validStatuses = ['Present', 'Absent', 'Late', 'Excused'];

            if (!in_array($complainantAtt, $validStatuses, true) || !in_array($respondentAtt, $validStatuses, true)) {

                return [

                    'success' => false,

                    'message' => 'Both Complainant and Respondent attendance must be marked (Present, Absent, Late, or Excused).',

                ];

            }
        }



        $stmt = $this->conn->prepare("

            SELECT h.*, c.case_number, c.case_status, c.complaint_id, co.complaint_title

            FROM hearings h

            INNER JOIN cases c ON c.case_id = h.case_id

            INNER JOIN complaints co ON co.complaint_id = c.complaint_id

            WHERE h.hearing_id = ?

        ");

        $stmt->execute([$hearingId]);

        $hearing = $stmt->fetch(PDO::FETCH_ASSOC);



        if (!$hearing) {

            return ['success' => false, 'message' => 'Hearing not found.'];

        }



        $caseId = (int) $hearing['case_id'];

        $attendanceNotes = trim($data['attendance_notes'] ?? '');

        $now = date('Y-m-d H:i:s');



        $isBothAppeared = in_array($complainantAtt, ['Present', 'Late'], true) && in_array($respondentAtt, ['Present', 'Late'], true);

        $hearingStatus = $isBothAppeared ? 'Completed' : 'Attendance Recorded';



        // Extract explanations

        $compExplanation = trim((string) ($data['complainant_explanation'] ?? $data['complainant_reason'] ?? ''));

        $respExplanation = trim((string) ($data['respondent_explanation'] ?? $data['respondent_reason'] ?? ''));



        $notesParts = [];

        if ($attendanceNotes !== '') {

            $notesParts[] = $attendanceNotes;

        }

        if ($complainantAtt === 'Absent') {

            $notesParts[] = 'Complainant absent: ' . ($compExplanation !== '' ? $compExplanation : 'No explanation provided');

        } elseif ($complainantAtt === 'Excused') {

            $notesParts[] = 'Complainant excused: ' . ($compExplanation !== '' ? $compExplanation : 'Excused by office');

        } elseif ($complainantAtt === 'Late') {

            $notesParts[] = 'Complainant late: ' . ($compExplanation !== '' ? $compExplanation : 'Late arrival noted');

        }



        if ($respondentAtt === 'Absent') {

            $notesParts[] = 'Respondent absent: ' . ($respExplanation !== '' ? $respExplanation : 'No explanation provided');

        } elseif ($respondentAtt === 'Excused') {

            $notesParts[] = 'Respondent excused: ' . ($respExplanation !== '' ? $respExplanation : 'Excused by office');

        } elseif ($respondentAtt === 'Late') {

            $notesParts[] = 'Respondent late: ' . ($respExplanation !== '' ? $respExplanation : 'Late arrival noted');

        }



        // Handle presider absence & substitution if provided

        $presiderAtt = trim((string) ($data['presider_attendance'] ?? 'Present'));

        $substituteId = filter_var($data['substitute_presider_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        $substituteReason = trim((string) ($data['substitute_reason'] ?? ''));

        $partiesConsent = !empty($data['parties_consent_to_substitute']);



        if ($presiderAtt === 'Absent') {

            if (!$substituteId || $substituteReason === '' || !$partiesConsent) {

                return [

                    'success' => false,

                    'message' => 'Presider is marked absent. A designated substitute presider, valid substitution reason, and confirmation of mutual party consent are required before proceeding.'

                ];

            }

            $notesParts[] = "Presider absent. Substitute #{$substituteId} designated (Reason: {$substituteReason}). Parties consented.";

        }



        $combinedNotes = implode(' | ', $notesParts);



        // Update hearings record - preserving presiding_officer_id!

        if ($presiderAtt === 'Absent' && $substituteId) {

            $updateHearing = $this->conn->prepare("

                UPDATE hearings

                SET complainant_attendance = ?,

                    respondent_attendance = ?,

                    substitute_presider_id = ?,

                    substitute_reason = ?,

                    parties_consent_to_substitute = 1,

                    attendance_recorded_at = ?,

                    attendance_notes = ?,

                    status = ?

                WHERE hearing_id = ?

            ");

            $updateHearing->execute([

                $complainantAtt,

                $respondentAtt,

                $substituteId,

                $substituteReason,

                $now,

                $combinedNotes ?: null,

                $hearingStatus,

                $hearingId,

            ]);

        } else {

            $updateHearing = $this->conn->prepare("

                UPDATE hearings

                SET complainant_attendance = ?,

                    respondent_attendance = ?,

                    attendance_recorded_at = ?,

                    attendance_notes = ?,

                    status = ?

                WHERE hearing_id = ?

            ");

            $updateHearing->execute([

                $complainantAtt,

                $respondentAtt,

                $now,

                $combinedNotes ?: null,

                $hearingStatus,

                $hearingId,

            ]);

        }



        // Sync with hearing_attendance table for party rows

        $this->syncHearingAttendanceTable(

            $hearingId,

            (int) $hearing['complaint_id'],

            $complainantAtt,

            $respondentAtt,

            $actorUserId,

            $compExplanation ?: null,

            $respExplanation ?: null,

            $partiesInput

        );



        // Fetch parties for document and hearing generation

        $parties = $this->getCaseParties(
            (int) $hearing['complaint_id'],
            $absentComplainantIds[0] ?? null,
            $absentRespondentIds[0] ?? null
        );



        $results = [

            'hearing_id' => $hearingId,

            'complainant_attendance' => $complainantAtt,

            'respondent_attendance' => $respondentAtt,

            'actions_triggered' => [],

        ];



        $stage = ($hearing['hearing_type'] === 'Conciliation' || ($hearing['case_status'] ?? '') === 'Conciliation') ? 'Conciliation' : 'Mediation';

        $hTypeName = $hearing['hearing_type'] ?? 'Hearing';



        // 1. Both Appeared (Present or Late)

        if ($isBothAppeared) {

            $lateNote = ($complainantAtt === 'Late' || $respondentAtt === 'Late') ? ' (Late arrival recorded)' : '';

            $msg = "{$hTypeName} hearing on " . date('F j, Y', strtotime($hearing['hearing_date'])) . " conducted. Both parties present{$lateNote}.";

            $this->logCaseHistory($caseId, $stage, $msg, $actorUserId);

            $this->audit->log($actorUserId, "Recorded hearing attendance: Both Present{$lateNote} for Case #{$hearing['case_number']}", 'Hearings', $caseId);



            return [

                'success' => true,

                'outcome' => 'both_present',

                'message' => "Both parties appeared. You may now proceed to {$stage} Notes and Settlement drafting.",

                'results' => $results,

            ];

        }



        // 1b. Excused Non-Appearance without Unexcused Absence

        if ($complainantAtt !== 'Absent' && $respondentAtt !== 'Absent' && ($complainantAtt === 'Excused' || $respondentAtt === 'Excused')) {

            $msg = "{$hTypeName} hearing on " . date('F j, Y', strtotime($hearing['hearing_date'])) . " attendance recorded with excused non-appearance. No sanctions imposed.";

            $this->logCaseHistory($caseId, $stage, $msg, $actorUserId);

            $this->audit->log($actorUserId, "Recorded hearing attendance: Excused non-appearance for Case #{$hearing['case_number']}", 'Hearings', $caseId);



            return [

                'success' => true,

                'outcome' => 'excused_non_appearance',

                'message' => "Attendance recorded with excused non-appearance. You may reschedule this {$stage} session without penalty.",

                'results' => $results,

            ];

        }



        // 1c. Partial / awaiting service verification (no Absent party)
        if ($complainantAtt !== 'Absent' && $respondentAtt !== 'Absent') {
            $msg = "{$hTypeName} hearing on " . date('F j, Y', strtotime($hearing['hearing_date'])) . " attendance saved while awaiting notice service verification.";
            $this->logCaseHistory($caseId, $stage, $msg, $actorUserId);
            return [
                'success' => true,
                'outcome' => 'pending_service_verification',
                'message' => 'Attendance recorded. Unserved parties remain in Not Served / Pending Verification and cannot advance to show cause.',
                'results' => $results,
            ];
        }

        // 2. Complainant Absent -> Trigger KP Form 18 Workflow

        if ($complainantAtt === 'Absent') {

            $showCauseDate = !empty($data['complainant_show_cause_date'])

                ? date('Y-m-d H:i:s', strtotime($data['complainant_show_cause_date']))

                : date('Y-m-d 09:00:00', strtotime('+3 days'));

            $showCauseVenue = trim($data['show_cause_venue'] ?? 'Lupon Court Room');



            $doc18 = $this->generateKpForm(

                $caseId,

                $hearingId,

                'KP Form 18',

                'Complainant',

                $parties,

                $hearing,

                $showCauseDate,

                $showCauseVenue,

                $actorUserId

            );



            // Schedule Show Cause Hearing for Complainant

            $scHearingId = $this->createShowCauseHearing(

                $caseId,

                'Show Cause (Complainant)',

                $showCauseDate,

                $showCauseVenue,

                $hearingId,

                "Show-cause hearing for Complainant non-appearance at " . strtolower($hTypeName) . " on " . date('M j, Y', strtotime($hearing['hearing_date']))

            );



            $targetCompId = !empty($absentComplainantIds) ? (int) $absentComplainantIds[0] : ($parties['complainant']['resident_id'] ?? null);
            $targetCompName = $parties['complainant']['full_name'] ?? null;

            // Create Summon Delivery record for this Show Cause hearing

            $this->createShowCauseDelivery(

                $scHearingId,

                'Complainant',

                'KP Form 18',

                $targetCompId,

                $caseId,

                $doc18['document_id'] ?? null,

                $targetCompName

            );



            $histMsg = "Complainant failed to appear at " . strtolower($hTypeName) . " hearing on " . date('M j, Y', strtotime($hearing['hearing_date'])) . ". KP Form 18 generated (Doc #{$doc18['document_id']}). Show-cause hearing scheduled for " . date('M j, Y g:i A', strtotime($showCauseDate)) . ".";

            $this->logCaseHistory($caseId, $stage, $histMsg, $actorUserId);

            $this->audit->log($actorUserId, "Complainant marked Absent. Triggered KP Form 18 and scheduled Show-Cause hearing for Case #{$hearing['case_number']}", 'Hearings', $caseId);

            // Auto-trigger re-issuance for Complainant subsequent notice attempt
            try {
                $this->deliveryService->initiateNextAttempt($caseId, $actorUserId, $hearingId, 'Complainant', null, $targetCompId);
            } catch (Throwable $reCompEx) {
                error_log("Notice re-issuance attempt error: " . $reCompEx->getMessage());
            }



            $results['actions_triggered'][] = [

                'type' => 'KP Form 18',

                'party' => 'Complainant',

                'document_id' => $doc18['document_id'],

                'show_cause_hearing_id' => $scHearingId,

                'show_cause_date' => $showCauseDate,

            ];

        }



        // 3. Respondent Absent -> Trigger KP Form 19 Workflow

        if ($respondentAtt === 'Absent') {

            $showCauseDate = !empty($data['respondent_show_cause_date'])

                ? date('Y-m-d H:i:s', strtotime($data['respondent_show_cause_date']))

                : date('Y-m-d 09:00:00', strtotime('+3 days'));

            $showCauseVenue = trim($data['show_cause_venue'] ?? 'Lupon Court Room');



            $doc19 = $this->generateKpForm(

                $caseId,

                $hearingId,

                'KP Form 19',

                'Respondent',

                $parties,

                $hearing,

                $showCauseDate,

                $showCauseVenue,

                $actorUserId

            );



            // Schedule Show Cause Hearing for Respondent

            $scHearingId = $this->createShowCauseHearing(

                $caseId,

                'Show Cause (Respondent)',

                $showCauseDate,

                $showCauseVenue,

                $hearingId,

                "Show-cause hearing for Respondent non-appearance at " . strtolower($hTypeName) . " on " . date('M j, Y', strtotime($hearing['hearing_date']))

            );



            $targetRespId = !empty($absentRespondentIds) ? (int) $absentRespondentIds[0] : ($parties['respondent']['resident_id'] ?? null);
            $targetRespName = $parties['respondent']['full_name'] ?? null;

            // Create Summon Delivery record for this Show Cause hearing

            $this->createShowCauseDelivery(

                $scHearingId,

                'Respondent',

                'KP Form 19',

                $targetRespId,

                $caseId,

                $doc19['document_id'] ?? null,

                $targetRespName

            );



            $histMsg = "Respondent failed to appear at " . strtolower($hTypeName) . " hearing on " . date('M j, Y', strtotime($hearing['hearing_date'])) . ". KP Form 19 generated (Doc #{$doc19['document_id']}). Show-cause hearing scheduled for " . date('M j, Y g:i A', strtotime($showCauseDate)) . ".";

            $this->logCaseHistory($caseId, $stage, $histMsg, $actorUserId);

            $this->audit->log($actorUserId, "Respondent marked Absent. Triggered KP Form 19 and scheduled Show-Cause hearing for Case #{$hearing['case_number']}", 'Hearings', $caseId);

            // Auto-trigger re-issuance for Respondent subsequent summons attempt
            try {
                $this->deliveryService->initiateNextAttempt($caseId, $actorUserId, $hearingId, 'Respondent', null, $targetRespId);
            } catch (Throwable $reRespEx) {
                error_log("Summons re-issuance attempt error: " . $reRespEx->getMessage());
            }



            $results['actions_triggered'][] = [

                'type' => 'KP Form 19',

                'party' => 'Respondent',

                'document_id' => $doc19['document_id'],

                'show_cause_hearing_id' => $scHearingId,

                'show_cause_date' => $showCauseDate,

            ];

        }



        $outcome = ($complainantAtt === 'Absent' && $respondentAtt === 'Absent') ? 'both_absent' : ($complainantAtt === 'Absent' ? 'complainant_absent' : 'respondent_absent');



        return [

            'success' => true,

            'outcome' => $outcome,

            'message' => 'Attendance recorded successfully. Non-appearance escalation workflow(s) initiated.',

            'results' => $results,

        ];

    }



    /**

     * Evaluates a Show-Cause Hearing (KP Form 18 or KP Form 19).

     */

    public function evaluateShowCause(int $hearingId, array $data, int $actorUserId): array
    {
        $startedTrans = !$this->conn->inTransaction();
        $newFiles = [];

        try {
            if ($startedTrans) {
                $this->conn->beginTransaction();
            }

            $stmt = $this->conn->prepare("
                SELECT sh.*, c.case_number, c.case_status, c.complaint_id, co.complaint_title,
                       origin.hearing_type AS origin_type
                FROM hearings sh
                JOIN cases c ON c.case_id = sh.case_id
                JOIN complaints co ON co.complaint_id = c.complaint_id
                JOIN hearings origin ON origin.hearing_id = sh.rescheduled_from_id AND origin.case_id = sh.case_id
                WHERE sh.hearing_id = ?
                  AND sh.hearing_type IN ('Show Cause (Complainant)', 'Show Cause (Respondent)')
                  AND sh.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
                  AND origin.hearing_type IN ('Initial Hearing', 'Mediation', 'Conciliation')
                  AND origin.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                  AND c.case_status NOT IN ('Archived', 'Settled')
                FOR UPDATE
            ");
            $stmt->execute([$hearingId]);
            $hearing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$hearing) {
                throw new DomainException('Select an open show-cause hearing linked to the original hearing.');
            }

            $caseId = (int) $hearing['case_id'];
            $originId = (int) $hearing['rescheduled_from_id'];
            $hearingRole = $hearing['hearing_type'] === 'Show Cause (Complainant)' ? 'Complainant' : 'Respondent';
            $partyType = trim((string) ($data['party_type'] ?? $hearingRole));
            if ($partyType !== $hearingRole && $partyType !== 'Both') {
                throw new DomainException('The selected party type does not match this show-cause hearing.');
            }

            $targets = [$hearingRole => $hearingId];
            if ($partyType === 'Both') {
                $otherRole = $hearingRole === 'Complainant' ? 'Respondent' : 'Complainant';
                $siblings = $this->conn->prepare("
                    SELECT hearing_id FROM hearings
                    WHERE case_id = ? AND rescheduled_from_id = ? AND hearing_type = ?
                      AND status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
                    FOR UPDATE
                ");
                $siblings->execute([$caseId, $originId, "Show Cause ({$otherRole})"]);
                $siblingIds = $siblings->fetchAll(PDO::FETCH_COLUMN);
                if (count($siblingIds) !== 1) {
                    throw new DomainException('Select one show-cause hearing at a time when there is no unique companion hearing.');
                }
                $targets[$otherRole] = (int) $siblingIds[0];
            }

            $residentId = filter_var($data['resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (isset($data['resident_id']) && !$residentId) {
                throw new DomainException('Select a valid show-cause recipient.');
            }

            $parties = $this->getCaseParties((int) $hearing['complaint_id']);
            $absent = $this->conn->prepare("
                SELECT ha.resident_id, cp.party_type,
                       TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
                FROM hearing_attendance ha
                JOIN complaint_parties cp ON cp.complaint_id = ? AND cp.resident_id = ha.resident_id
                JOIN residents r ON r.resident_id = ha.resident_id
                WHERE ha.hearing_id = ? AND cp.party_type = ?
                  AND ha.attendance_status = 'Absent' AND ha.is_justified = 0
                FOR UPDATE
            ");
            $evaluated = [];
            foreach ($targets as $role => $targetHearingId) {
                $absent->execute([$hearing['complaint_id'], $originId, $role]);
                $matches = [];
                foreach ($absent->fetchAll(PDO::FETCH_ASSOC) as $party) {
                    $id = (int) $party['resident_id'];
                    if ($residentId && $role === $hearingRole && $id !== $residentId) {
                        continue;
                    }
                    $noticeId = $this->deliveryService->getServedShowCauseNotice($targetHearingId, $id, $role);
                    $originalService = $this->deliveryService->verifyRecipientServiceForHearing($originId, $id, $role);
                    if ($noticeId && !empty($originalService['verified'])) {
                        $matches[] = ['party' => $party, 'notice_id' => $noticeId];
                    }
                }
                if (count($matches) !== 1) {
                    throw new DomainException("Select one {$role} with recorded unexcused absence and verified service of both the original and show-cause notices.");
                }
                $evaluated[$role] = $matches[0];
                $parties[strtolower($role)] = $matches[0]['party'];
            }

            $justified = !empty($data['is_justified']);
            $category = trim((string) ($data['justification_category'] ?? ''));
            if (!in_array($category, ['Medical Emergency', 'Force Majeure', 'Official Duty', 'Unjustified Absence', 'Willful Refusal', 'Other'], true)) {
                $category = $justified ? 'Medical Emergency' : 'Unjustified Absence';
            }
            $notes = trim((string) ($data['justification_notes'] ?? ''));
            $rescheduledHearingId = null;
            $barredRoles = $targets;
            if (!$justified) {
                foreach ($this->deliveryService->getVerifiedShowCauseFindings($caseId, $originId) as $finding) {
                    $role = $finding['party_type'];
                    if (!isset($barredRoles[$role])) {
                        $barredRoles[$role] = (int) $finding['hearing_id'];
                    }
                }
            }

            if ($justified) {
                $action = 'Rescheduled';
                $date = !empty($data['reschedule_date']) ? strtotime((string) $data['reschedule_date']) : strtotime('+3 days 09:00');
                if ($date === false) {
                    throw new DomainException('Enter a valid date for the rescheduled hearing.');
                }
                $venue = trim((string) ($data['reschedule_venue'] ?? 'Lupon Court Room'));
                $type = $hearing['origin_type'] === 'Conciliation' ? 'Conciliation' : 'Mediation';
                $rescheduledHearingId = $this->createRescheduledHearing(
                    $caseId, $type, date('Y-m-d H:i:s', $date), $venue, $hearingId,
                    "Show-cause absence justified ({$category}). Notes: {$notes}"
                );
                $justify = $this->conn->prepare("
                    UPDATE hearing_attendance SET is_justified = 1, justification_reason = ?
                    WHERE hearing_id = ? AND resident_id = ?
                ");
                foreach ($evaluated as $target) {
                    $justify->execute([$category, $originId, $target['party']['resident_id']]);
                }
                $message = "Absence was accepted as justified ({$category}). {$type} hearing rescheduled.";
            } elseif (isset($barredRoles['Complainant'])) {
                $action = 'Barred Action';
                $this->conn->prepare("UPDATE cases SET case_status = 'DISMISSED_BARRED' WHERE case_id = ?")->execute([$caseId]);
                $this->conn->prepare("UPDATE complaints SET status = 'DISMISSED_BARRED' WHERE complaint_id = ?")->execute([$hearing['complaint_id']]);
                $message = isset($barredRoles['Respondent'])
                    ? 'Ang kaso ay permanenteng isinara (DISMISSED_BARRED) dahil sa hindi makatwirang pagliban ng parehong panig. Inisyu ang KP Form 21 at KP Form 22.'
                    : 'Complainant absence was ruled unjustified. KP Form 21 records the bar.';
            } else {
                $action = 'CFA Issued';
                $message = 'Respondent absence was ruled unjustified. KP Form 20, KP Form 20-B, and KP Form 22 were issued.';
            }

            $insert = $this->conn->prepare("
                INSERT INTO show_cause_evaluations
                    (hearing_id, case_id, party_type, resident_id, notice_delivery_id, form_type, is_justified,
                     justification_category, justification_notes, rescheduled_hearing_id, action_taken, evaluated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $complete = $this->conn->prepare("UPDATE hearings SET status = 'Completed' WHERE hearing_id = ?");
            foreach ($targets as $role => $targetHearingId) {
                $target = $evaluated[$role];
                $insert->execute([
                    $targetHearingId, $caseId, $role, $target['party']['resident_id'], $target['notice_id'],
                    $role === 'Complainant' ? 'KP Form 18' : 'KP Form 19', (int) $justified,
                    $category, $notes ?: null, $rescheduledHearingId, $action, $actorUserId,
                ]);
                $complete->execute([$targetHearingId]);
            }

            if ($action === 'CFA Issued') {
                require_once __DIR__ . '/../models/CFA.php';
                $cfa = new CFA($this->conn);
                foreach (['KP Form 20-B', 'KP Form 20'] as $form) {
                    $issued = $cfa->create([
                        'case_id' => $caseId,
                        'cfa_type' => $form,
                        'reason' => "Respondent unjustified absence after show-cause notice ({$category}).",
                    ], $actorUserId);
                    if (empty($issued['success'])) {
                        throw new RuntimeException($issued['message']);
                    }
                    if (empty($issued['duplicate']) && !empty($issued['file_path'])) {
                        $newFiles[] = $issued['file_path'];
                    }
                }
            }
            if (!$justified) {
                foreach ($targets as $role => $targetHearingId) {
                    $doc = $this->generateKpForm(
                        $caseId, $targetHearingId, $role === 'Complainant' ? 'KP Form 21' : 'KP Form 22',
                        $role, $parties, $hearing, null, null, $actorUserId, true
                    );
                    $newFiles[] = $doc['file_path'];
                }
            }

            $historyStatus = $justified ? $hearing['case_status'] : ($action === 'CFA Issued' ? 'CFA Issued' : 'Dismissed');
            $history = $this->conn->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)");
            $history->execute([$caseId, $historyStatus, $message, $actorUserId]);
            if (!$this->audit->log($actorUserId, $message, 'Hearings', $hearingId)) {
                throw new RuntimeException('Unable to record the show-cause audit entry.');
            }
            if ($startedTrans) {
                $this->conn->commit();
            }
        } catch (Throwable $e) {
            if ($startedTrans && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            foreach ($newFiles as $path) {
                $absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
                if (is_file($absolute)) {
                    unlink($absolute);
                }
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if ($startedTrans && !$justified) {
            try {
                require_once __DIR__ . '/NotificationService.php';
                $notifications = new NotificationService();
                $url = '../cases/case-details.php?id=' . $caseId;
                $title = $action === 'CFA Issued' ? 'CFA Issued & Counterclaim Barred' : 'Case Dismissed and Barred';
                $notifications->notifyRoles(['Administrator', 'Lupon Clerk'], $title, $message, $actorUserId, 'warning', $url, $caseId);
                $notifications->notifyCaseMembers($caseId, $title, $message, $actorUserId, 'warning', $url);
                if ($action === 'CFA Issued') {
                    $complainants = $this->conn->prepare("
                        SELECT u.user_id FROM users u
                        JOIN complaint_parties cp ON cp.resident_id = u.resident_id
                        WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
                        UNION
                        SELECT created_by FROM complaints WHERE complaint_id = ? AND created_by IS NOT NULL
                    ");
                    $complainants->execute([$hearing['complaint_id'], $hearing['complaint_id']]);
                    foreach ($complainants->fetchAll(PDO::FETCH_COLUMN) as $recipient) {
                        $notifications->notifyUser((int) $recipient, $title, $message, $actorUserId, 'success', $url, $caseId);
                    }
                }
            } catch (Throwable $e) {
                error_log('Failed to dispatch show-cause outcome notifications: ' . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'is_justified' => $justified,
            'action_taken' => $action,
            'message' => $message,
            'rescheduled_hearing_id' => $rescheduledHearingId,
        ];
    }


    /**

     * Generates a KP Form record and writes the PDF file if PDFService is present.

     */

    public function generateKpForm(

        int $caseId,

        int $hearingId,

        string $formCode,

        string $targetPartyType,

        array $parties,

        array $hearing,

        ?string $explanationDate,

        ?string $venue,

        int $actorUserId,
        bool $strictPdf = false

    ): array {

        // Resolve template_id

        $tStmt = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = ? LIMIT 1");

        $tStmt->execute([$formCode]);

        $templateId = (int) $tStmt->fetchColumn();



        if (!$templateId) {

            $insTpl = $this->conn->prepare("INSERT INTO document_templates (template_name, description) VALUES (?, ?)");

            $insTpl->execute([$formCode, "Automated {$formCode} Notice"]);

            $templateId = (int) $this->conn->lastInsertId();

        }



        $targetPartyName = ($targetPartyType === 'Complainant')

            ? ($parties['complainant']['full_name'] ?? 'Complainant')

            : ($parties['respondent']['full_name'] ?? 'Respondent');



        $safeCaseNum = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($hearing['case_number'] ?? ('case_' . $caseId)));
        $fileName = 'kp_form_' . strtolower(str_replace(' ', '_', $formCode)) . '_' . $caseId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . $safeCaseNum;
        $fullDir = dirname(__DIR__, 2) . '/' . $relativeDir;
        if (!is_dir($fullDir)) {
            @mkdir($fullDir, 0777, true);
        }
        $relativePath = $relativeDir . '/' . $fileName;
        $absolutePath = $fullDir . '/' . $fileName;

        // Try generating PDF
        if ($this->pdfService) {
            try {
                $pdfData = [
                    'case_number' => $hearing['case_number'] ?? '',
                    'complaint_title' => $hearing['complaint_title'] ?? '',
                    'complainants' => isset($parties['complainant']) ? [$parties['complainant']] : [],
                    'respondents' => isset($parties['respondent']) ? [$parties['respondent']] : [],
                    'target_party_name' => $targetPartyName,
                    'venue' => $venue ?: ($hearing['venue'] ?? 'Lupon Court Room'),
                    'hearing_date' => $hearing['hearing_date'] ?? null,
                    'explanation_date' => $explanationDate,
                    'notice_date' => date('Y-m-d'),
                ];
                $this->pdfService->generateNotice($formCode, $pdfData, $absolutePath);
            } catch (Throwable $e) {
                if ($strictPdf) {
                    if (is_file($absolutePath)) {
                        unlink($absolutePath);
                    }
                    throw $e;
                }
                error_log("Failed to render PDF for {$formCode}: " . $e->getMessage());
            }
        }



        if ($strictPdf && !is_file($absolutePath)) {
            throw new RuntimeException("Unable to generate {$formCode}.");
        }

        // Insert into generated_documents

        $insDoc = $this->conn->prepare("

            INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status)

            VALUES (?, ?, ?, ?, 'For Service')

        ");

        try {
            $insDoc->execute([
                $caseId,
                $templateId,
                $actorUserId,
                $relativePath,
            ]);
        } catch (Throwable $e) {
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }
            throw $e;
        }

        $docId = (int) $this->conn->lastInsertId();



        return [

            'document_id' => $docId,

            'template_name' => $formCode,

            'file_path' => $relativePath,

        ];

    }



    private function createShowCauseHearing(

        int $caseId,

        string $hearingType,

        string $hearingDate,

        string $venue,

        int $rescheduledFromId,

        string $remarks

    ): int {

        $stmt = $this->conn->prepare("

            INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id, remarks)

            VALUES (?, ?, ?, ?, 'Scheduled', ?, ?)

        ");

        $stmt->execute([

            $caseId,

            $hearingType,

            $hearingDate,

            $venue,

            $rescheduledFromId,

            $remarks,

        ]);



        $hearingId = (int) $this->conn->lastInsertId();

        try {

            $this->deliveryService->initializeHearingDeliveries($hearingId);

        } catch (Throwable $e) {

            error_log("Failed to initialize deliveries for show cause hearing {$hearingId}: " . $e->getMessage());

        }

        return $hearingId;

    }



    private function createRescheduledHearing(

        int $caseId,

        string $hearingType,

        string $hearingDate,

        string $venue,

        int $rescheduledFromId,

        string $reason

    ): int {

        $stmt = $this->conn->prepare("

            INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id, reschedule_reason)

            VALUES (?, ?, ?, ?, 'Scheduled', ?, ?)

        ");

        $stmt->execute([

            $caseId,

            $hearingType,

            $hearingDate,

            $venue,

            $rescheduledFromId,

            $reason,

        ]);



        $hearingId = (int) $this->conn->lastInsertId();

        try {

            $this->deliveryService->initializeHearingDeliveries($hearingId);

        } catch (Throwable $e) {

            error_log("Failed to initialize deliveries for rescheduled {$hearingType} hearing {$hearingId}: " . $e->getMessage());

        }

        return $hearingId;

    }



    private function createRescheduledMediation(

        int $caseId,

        string $hearingDate,

        string $venue,

        int $rescheduledFromId,

        string $reason

    ): int {

        return $this->createRescheduledHearing($caseId, 'Mediation', $hearingDate, $venue, $rescheduledFromId, $reason);

    }



    private function createShowCauseDelivery(

        int $hearingId,

        string $partyType,

        string $formType,

        ?int $residentId,

        ?int $caseId = null,

        ?int $documentId = null,

        ?string $recipientName = null

    ): void {

        $check = $this->conn->prepare("

            SELECT delivery_id FROM summon_deliveries

            WHERE hearing_id = ? AND party_type = ? AND resident_id = ? AND form_type = ?

            LIMIT 1

        ");

        $check->execute([$hearingId, $partyType, $residentId, $formType]);

        $existingId = $check->fetchColumn();



        if ($existingId) {

            $this->conn->prepare("

                UPDATE summon_deliveries

                SET form_type = ?,

                    document_id = COALESCE(?, document_id),

                    recipient_name = COALESCE(?, recipient_name),

                    remarks = ?

                WHERE delivery_id = ?

            ")->execute([$formType, $documentId, $recipientName, "Officer's Return for {$formType}", $existingId]);

        } else {

            $stmt = $this->conn->prepare("

                INSERT INTO summon_deliveries (

                    hearing_id, case_id, party_type, resident_id, form_type, attempt_number,

                    delivery_status, document_id, recipient_name, remarks

                ) VALUES (?, ?, ?, ?, ?, 1, 'Pending', ?, ?, ?)

            ");

            $stmt->execute([

                $hearingId,

                $caseId,

                $partyType,

                $residentId,

                $formType,

                $documentId,

                $recipientName,

                "Officer's Return for {$formType}"

            ]);

        }

    }



    private function syncHearingAttendanceTable(

        int $hearingId,

        int $complaintId,

        string $complainantAtt,

        string $respondentAtt,

        int $actorUserId,

        ?string $compReason = null,

        ?string $respReason = null,

        array $partiesInput = []

    ): void {

        $upsertAtt = $this->conn->prepare("

            INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified, justification_reason, recorded_by)

            VALUES (?, ?, ?, ?, ?, ?)

            ON DUPLICATE KEY UPDATE 

                attendance_status = VALUES(attendance_status),

                is_justified = VALUES(is_justified),

                justification_reason = VALUES(justification_reason),

                recorded_by = VALUES(recorded_by)

        ");

        if (!empty($partiesInput)) {
            foreach ($partiesInput as $pItem) {
                $rId = (int) ($pItem['resident_id'] ?? 0);
                if ($rId <= 0) continue;
                $st = trim((string) ($pItem['attendance_status'] ?? 'Pending Verification'));
                $isJust = ($st === 'Excused' || (!empty($pItem['is_justified']) && $st !== 'Absent')) ? 1 : 0;
                $justReason = trim((string) ($pItem['justification_reason'] ?? $pItem['remarks'] ?? ''));
                if ($isJust && $justReason === '') {
                    $justReason = 'Excused Non-Appearance';
                } elseif (!$isJust) {
                    $justReason = $justReason !== '' ? $justReason : null;
                }
                $upsertAtt->execute([$hearingId, $rId, $st, $isJust, $justReason, $actorUserId]);
            }
            return;
        }

        $parties = $this->getCaseParties($complaintId);

        if (isset($parties['complainant']['resident_id'])) {

            $isJust = ($complainantAtt === 'Excused') ? 1 : 0;

            $justReason = $compReason ?: ($complainantAtt === 'Excused' ? 'Excused Non-Appearance' : null);

            $upsertAtt->execute([

                $hearingId,

                $parties['complainant']['resident_id'],

                $complainantAtt,

                $isJust,

                $justReason,

                $actorUserId,

            ]);

        }

        if (isset($parties['respondent']['resident_id'])) {

            $isJust = ($respondentAtt === 'Excused') ? 1 : 0;

            $justReason = $respReason ?: ($respondentAtt === 'Excused' ? 'Excused Non-Appearance' : null);

            $upsertAtt->execute([

                $hearingId,

                $parties['respondent']['resident_id'],

                $respondentAtt,

                $isJust,

                $justReason,

                $actorUserId,

            ]);

        }

    }



    public function getCaseParties(int $complaintId, ?int $complainantId = null, ?int $respondentId = null): array

    {

        $stmt = $this->conn->prepare("

            SELECT cp.resident_id, cp.party_type,

                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name

            FROM complaint_parties cp

            INNER JOIN residents r ON r.resident_id = cp.resident_id

            WHERE cp.complaint_id = ?

            ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent')

        ");

        $stmt->execute([$complaintId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);



        $result = ['complainant' => null, 'respondent' => null];

        foreach ($rows as $r) {

            if ($r['party_type'] === 'Complainant' && (!$result['complainant'] || (int) $r['resident_id'] === $complainantId)) {

                $result['complainant'] = $r;

            } elseif ($r['party_type'] === 'Respondent' && (!$result['respondent'] || (int) $r['resident_id'] === $respondentId)) {

                $result['respondent'] = $r;

            }

        }

        return $result;

    }



    private function logCaseHistory(int $caseId, string $status, string $remarks, int $userId): void
    {
        try {
            $validStatuses = ['Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Dismissed', 'CFA Issued', 'Archived'];
            $dbStatus = in_array($status, $validStatuses, true) ? $status : 'Dismissed';
            if ($status === 'CFA Issued' || $status === 'RESPONDENT_DEFAULT') {
                $dbStatus = 'CFA Issued';
            } elseif ($status === 'DISMISSED_BARRED') {
                $dbStatus = 'Dismissed';
            }

            $stmt = $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$caseId, $dbStatus, $remarks, $userId]);
        } catch (Throwable $e) {
            error_log("Failed to log case history: " . $e->getMessage());
        }
    }
}
