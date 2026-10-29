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

        $this->audit = new AuditService();

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

        if (empty($data['bypass_service_check'])) {
            $serviceCheck = $this->deliveryService->checkServiceStatus($hearingId);

            if (!$serviceCheck['allowed']) {

                return [

                    'success' => false,

                    'message' => 'Attendance locked: ' . $serviceCheck['message'],

                ];

            }
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

            $respExplanation ?: null

        );



        // Fetch parties for document and hearing generation

        $parties = $this->getCaseParties((int) $hearing['complaint_id']);



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



            // Create Summon Delivery record for this Show Cause hearing

            $this->createShowCauseDelivery(

                $scHearingId,

                'Complainant',

                'KP Form 18',

                $parties['complainant']['resident_id'] ?? null,

                $caseId,

                $doc18['document_id'] ?? null,

                $parties['complainant']['full_name'] ?? null

            );



            $histMsg = "Complainant failed to appear at " . strtolower($hTypeName) . " hearing on " . date('M j, Y', strtotime($hearing['hearing_date'])) . ". KP Form 18 generated (Doc #{$doc18['document_id']}). Show-cause hearing scheduled for " . date('M j, Y g:i A', strtotime($showCauseDate)) . ".";

            $this->logCaseHistory($caseId, $stage, $histMsg, $actorUserId);

            $this->audit->log($actorUserId, "Complainant marked Absent. Triggered KP Form 18 and scheduled Show-Cause hearing for Case #{$hearing['case_number']}", 'Hearings', $caseId);



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



            // Create Summon Delivery record for this Show Cause hearing

            $this->createShowCauseDelivery(

                $scHearingId,

                'Respondent',

                'KP Form 19',

                $parties['respondent']['resident_id'] ?? null,

                $caseId,

                $doc19['document_id'] ?? null,

                $parties['respondent']['full_name'] ?? null

            );



            $histMsg = "Respondent failed to appear at " . strtolower($hTypeName) . " hearing on " . date('M j, Y', strtotime($hearing['hearing_date'])) . ". KP Form 19 generated (Doc #{$doc19['document_id']}). Show-cause hearing scheduled for " . date('M j, Y g:i A', strtotime($showCauseDate)) . ".";

            $this->logCaseHistory($caseId, $stage, $histMsg, $actorUserId);

            $this->audit->log($actorUserId, "Respondent marked Absent. Triggered KP Form 19 and scheduled Show-Cause hearing for Case #{$hearing['case_number']}", 'Hearings', $caseId);



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

            return ['success' => false, 'message' => 'Show-cause hearing not found.'];

        }



        $caseId = (int) $hearing['case_id'];

        $complaintId = (int) $hearing['complaint_id'];



        $partyType = trim($data['party_type'] ?? '');
        $isBoth = ($partyType === 'Both');

        if (!in_array($partyType, ['Complainant', 'Respondent', 'Both'], true)) {

            // Infer from hearing_type if not explicitly passed

            if ($hearing['hearing_type'] === 'Show Cause (Complainant)') {

                $partyType = 'Complainant';

            } elseif ($hearing['hearing_type'] === 'Show Cause (Respondent)') {

                $partyType = 'Respondent';

            } else {

                return ['success' => false, 'message' => 'Invalid party type for show-cause evaluation.'];

            }

        }



        $formType = ($partyType === 'Complainant') ? 'KP Form 18' : 'KP Form 19';

        $isJustified = !empty($data['is_justified']) ? 1 : 0;

        $justificationCategory = trim($data['justification_category'] ?? '');

        $justificationNotes = trim($data['justification_notes'] ?? '');



        $validCategories = ['Medical Emergency', 'Force Majeure', 'Official Duty', 'Unjustified Absence', 'Willful Refusal', 'Other'];

        if (!in_array($justificationCategory, $validCategories, true)) {

            $justificationCategory = $isJustified ? 'Medical Emergency' : 'Unjustified Absence';

        }



        $parties = $this->getCaseParties($complaintId);

        $rescheduledHearingId = null;

        $actionTaken = '';

        $clientMessage = '';



        if ($isJustified) {

            // OUTCOME 1: Justified Reason -> Reschedule Hearing
            $origHearing = null;
            if (!empty($hearing['rescheduled_from_id'])) {
                $origStmt = $this->conn->prepare("SELECT hearing_id, hearing_type, hearing_date, venue FROM hearings WHERE hearing_id = ?");
                $origStmt->execute([$hearing['rescheduled_from_id']]);
                $origHearing = $origStmt->fetch(PDO::FETCH_ASSOC);
            }
            $targetType = ($origHearing && $origHearing['hearing_type'] === 'Conciliation') ? 'Conciliation' : 'Mediation';

            $rescheduleDate = !empty($data['reschedule_date'])

                ? date('Y-m-d H:i:s', strtotime($data['reschedule_date']))

                : date('Y-m-d 09:00:00', strtotime('+3 days'));

            $rescheduleVenue = trim($data['reschedule_venue'] ?? 'Lupon Court Room');



            $rescheduledHearingId = $this->createRescheduledHearing(

                $caseId,

                $targetType,

                $rescheduleDate,

                $rescheduleVenue,

                $hearingId,

                "Show-cause absence justified ({$justificationCategory}). Notes: {$justificationNotes}"

            );



            // Re-initialize summon deliveries for the new hearing

            $this->deliveryService->getHearingDeliveries($rescheduledHearingId);



            $actionTaken = 'Rescheduled';

            $clientMessage = "Absence was accepted as justified ({$justificationCategory}). {$targetType} hearing rescheduled to " . date('F j, Y g:i A', strtotime($rescheduleDate)) . ".";



            $hist = "Show-cause evaluation: {$partyType} absence justified ({$justificationCategory}). {$targetType} rescheduled for " . date('M j, Y g:i A', strtotime($rescheduleDate)) . ".";

            $this->logCaseHistory($caseId, $hearing['case_status'], $hist, $actorUserId);

            $this->audit->log($actorUserId, "Show-cause absence justified for {$partyType} in Case #{$hearing['case_number']}", 'Hearings', $caseId);

        } else {

            // OUTCOME 2: Unjustified / Willful Refusal / Ignored Notice

            // Checking mechanism: Check if the other party also has an unjustified show-cause evaluation
            $otherPartyType = ($partyType === 'Complainant') ? 'Respondent' : 'Complainant';
            $origHearingId = !empty($hearing['rescheduled_from_id']) ? (int) $hearing['rescheduled_from_id'] : null;

            $otherEval = null;
            if ($isBoth) {
                $otherEval = ['evaluation_id' => 1];
            } elseif ($origHearingId) {
                $chkOther = $this->conn->prepare("
                    SELECT sce.*
                    FROM show_cause_evaluations sce
                    INNER JOIN hearings h ON h.hearing_id = sce.hearing_id
                    WHERE sce.case_id = ?
                      AND sce.party_type = ?
                      AND sce.is_justified = 0
                      AND h.rescheduled_from_id = ?
                    ORDER BY sce.evaluation_id DESC
                    LIMIT 1
                ");
                $chkOther->execute([$caseId, $otherPartyType, $origHearingId]);
                $otherEval = $chkOther->fetch(PDO::FETCH_ASSOC);
            }

            if (!$otherEval) {
                $chkOtherFallback = $this->conn->prepare("
                    SELECT sce.*
                    FROM show_cause_evaluations sce
                    WHERE sce.case_id = ?
                      AND sce.party_type = ?
                      AND sce.is_justified = 0
                    ORDER BY sce.evaluation_id DESC
                    LIMIT 1
                ");
                $chkOtherFallback->execute([$caseId, $otherPartyType]);
                $otherEval = $chkOtherFallback->fetch(PDO::FETCH_ASSOC);
            }

            if ($otherEval) {
                // =========================================================================
                // COMBINED EVALUATION: Both Parties Failed / Unjustified
                // =========================================================================
                $actionTaken = 'Barred Action';
                $newStatus = 'DISMISSED_BARRED';

                // Update case and complaint status to DISMISSED_BARRED (Permanent Dismissal)
                $uCase = $this->conn->prepare("UPDATE cases SET case_status = 'DISMISSED_BARRED' WHERE case_id = ?");
                $uCase->execute([$caseId]);

                $uComp = $this->conn->prepare("UPDATE complaints SET status = 'DISMISSED_BARRED' WHERE complaint_id = ?");
                $uComp->execute([$complaintId]);

                // Auto-generate KP Form 21 (Certificate to Bar Action para sa Complainant)
                $doc21 = $this->generateKpForm(
                    $caseId,
                    $hearingId,
                    'KP Form 21',
                    'Complainant',
                    $parties,
                    $hearing,
                    null,
                    null,
                    $actorUserId
                );

                // Auto-generate KP Form 22 (Certificate to Bar Counterclaim para sa Respondent)
                $doc22 = $this->generateKpForm(
                    $caseId,
                    $hearingId,
                    'KP Form 22',
                    'Respondent',
                    $parties,
                    $hearing,
                    null,
                    null,
                    $actorUserId
                );

                $generatedDocs = [$doc21, $doc22];

                $clientMessage = "Parehong hindi tumugon o idineklarang UNJUSTIFIED ang magkabilang panig (Complainant at Respondent) matapos ang Show-Cause hearings. Ang kaso ay permanenteng isinara (DISMISSED_BARRED). Awtomatikong nabuo ang KP Form 21 at KP Form 22. Walang panig na maaaring dumulog sa hukuman.";

                $hist = "Show-cause combined evaluation: Parehong hindi tumugon o unjustified ang magkabilang panig matapos ang KP Form 18 at KP Form 19 ({$justificationCategory}). Ang kaso ay permanenteng isinara (DISMISSED_BARRED). Parehong panig ay hindi na maaaring dumulog sa korte dahil sa kusang pagliban ng magkabilang panig nang walang sapat na dahilan. Inisyu ang KP Form 21 (Doc #{$doc21['document_id']}) at KP Form 22 (Doc #{$doc22['document_id']}).";
                $this->logCaseHistory($caseId, 'DISMISSED_BARRED', $hist, $actorUserId);
                $this->audit->log($actorUserId, "Combined show-cause unjustified for both parties in Case #{$hearing['case_number']}. Status permanently set to DISMISSED_BARRED.", 'Hearings', $caseId);

                // Dispatch combined dismissal notifications
                try {
                    require_once __DIR__ . '/NotificationService.php';
                    $notifService = new NotificationService();
                    $notifTitle = "Kaso Permanenteng Isinara (Dismissed & Barred)";
                    $notifMsg = "Ang kaso #{$hearing['case_number']} ay permanenteng isinara (DISMISSED_BARRED) dahil sa hindi makatwirang pagliban ng parehong panig sa Show-Cause hearings. Inisyu ang KP Form 21 at KP Form 22; walang panig na maaaring dumulog sa hukuman.";
                    $actionUrl = "../cases/case-details.php?id=" . $caseId;

                    $notifService->notifyRoles(['Administrator', 'Lupon Clerk'], $notifTitle, $notifMsg, $actorUserId, 'warning', $actionUrl, $caseId);
                    $notifService->notifyCaseMembers($caseId, $notifTitle, $notifMsg, $actorUserId, 'warning', $actionUrl);
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch combined dismissal notifications: ' . $ne->getMessage());
                }

            } elseif ($partyType === 'Complainant') {
                // =========================================================================
                // COMPLAINANT UNJUSTIFIED (Single Party Default)
                // =========================================================================
                $actionTaken = 'Barred Action';
                $newStatus = 'DISMISSED_BARRED';

                // Update case and complaint status
                $uCase = $this->conn->prepare("UPDATE cases SET case_status = 'DISMISSED_BARRED' WHERE case_id = ?");
                $uCase->execute([$caseId]);

                $uComp = $this->conn->prepare("UPDATE complaints SET status = 'DISMISSED_BARRED' WHERE complaint_id = ?");
                $uComp->execute([$complaintId]);

                // Auto-generate KP Form 21 (Certificate to Bar Action)
                $docBar = $this->generateKpForm(
                    $caseId,
                    $hearingId,
                    'KP Form 21',
                    'Complainant',
                    $parties,
                    $hearing,
                    null,
                    null,
                    $actorUserId
                );
                $generatedDocs = [$docBar];

                $clientMessage = "Complainant absence was ruled UNJUSTIFIED. Case is DISMISSED and complainant is BARRED from refiling or taking matter to court. KP Form 21 issued.";
                $hist = "Complainant absence unjustified ({$justificationCategory}). Case marked DISMISSED_BARRED. KP Form 21 issued (Doc #{$docBar['document_id']}). Locked from further mediation.";
                $this->logCaseHistory($caseId, 'DISMISSED_BARRED', $hist, $actorUserId);
                $this->audit->log($actorUserId, "Complainant barred from action for Case #{$hearing['case_number']}. Status set to DISMISSED_BARRED.", 'Hearings', $caseId);

            } else {
                // =========================================================================
                // RESPONDENT UNJUSTIFIED (Single Party Default - Section 1 Enhancement)
                // =========================================================================
                $actionTaken = 'CFA Issued';
                $newStatus = 'CFA Issued';

                // 1. Update case and complaint status
                $uCase = $this->conn->prepare("UPDATE cases SET case_status = 'CFA Issued' WHERE case_id = ?");
                $uCase->execute([$caseId]);

                $uComp = $this->conn->prepare("UPDATE complaints SET status = 'CFA Issued' WHERE complaint_id = ?");
                $uComp->execute([$complaintId]);

                // Ensure cfa_records entry exists
                $chkCfa = $this->conn->prepare("SELECT cfa_id FROM cfa_records WHERE case_id = ?");
                $chkCfa->execute([$caseId]);
                if (!$chkCfa->fetchColumn()) {
                    $insCfa = $this->conn->prepare("INSERT INTO cfa_records (case_id, issuance_date, reason) VALUES (?, CURDATE(), ?)");
                    $insCfa->execute([
                        $caseId,
                        "Respondent unjustified absence after show-cause notice (KP Form 19). Certificate to File Action granted to Complainant; Counterclaim barred (KP Form 20-B & KP Form 22)."
                    ]);
                }

                // 2. Automatically generate and save the three consequence documents:
                // - KP Form 20-B (Certificate to Bar Counterclaim) para sa Respondent
                $doc20B = $this->generateKpForm(
                    $caseId,
                    $hearingId,
                    'KP Form 20-B',
                    'Respondent',
                    $parties,
                    $hearing,
                    null,
                    null,
                    $actorUserId
                );

                // - KP Form 20 (Certificate to File Action / CFA) para sa Complainant
                $doc20 = $this->generateKpForm(
                    $caseId,
                    $hearingId,
                    'KP Form 20',
                    'Complainant',
                    $parties,
                    $hearing,
                    null,
                    null,
                    $actorUserId
                );

                // - KP Form 22 (Certificate to Bar Action Counterclaim / Contempt Sanction Notice) para sa Respondent
                $doc22 = $this->generateKpForm(
                    $caseId,
                    $hearingId,
                    'KP Form 22',
                    'Respondent',
                    $parties,
                    $hearing,
                    null,
                    null,
                    $actorUserId
                );

                $generatedDocs = [$doc20B, $doc20, $doc22];

                $clientMessage = "Respondent absence evaluated as UNJUSTIFIED. Awtomatikong binuo ang KP Form 20-B (Bar Counterclaim), KP Form 20 (CFA), at KP Form 22 (Sanction Notice). Ang status ng kaso ay 'CFA Issued'. Handa na para sa pagsasampa sa hukuman ng nagrereklamo.";

                // 3. Log entry in case_history
                $hist = "Respondent absence unjustified ({$justificationCategory}) matapos ang KP Form 19. Case status: CFA Issued. Awtomatikong inisyu ang KP Form 20 (CFA Doc #{$doc20['document_id']}), KP Form 20-B (Doc #{$doc20B['document_id']}), at KP Form 22 (Doc #{$doc22['document_id']}). Pinagkalooban ng CFA ang nagrereklamo at barred ang anumang ganting-sakdal ng inirereklamo.";
                $this->logCaseHistory($caseId, 'CFA Issued', $hist, $actorUserId);
                $this->audit->log($actorUserId, "Respondent unjustified absence for Case #{$hearing['case_number']}. CFA Issued with KP Form 20, 20-B, and 22.", 'Hearings', $caseId);

                // 4. Send notifications to Complainant and Lupon
                try {
                    require_once __DIR__ . '/NotificationService.php';
                    $notifService = new NotificationService();
                    $notifTitle = "CFA Issued & Counterclaim Barred";
                    $notifMsg = "Pinagkalooban na ng Certificate to File Action (KP Form 20) ang nagrereklamo para sa Usapin Blg. {$hearing['case_number']}, at barred ang anumang ganting-sakdal ng inirereklamo (KP Form 20-B / KP Form 22) dahil sa hindi makatwirang pagliban.";
                    $actionUrl = "../cases/case-details.php?id=" . $caseId;

                    // Notify Lupon / Staff
                    $notifService->notifyRoles(['Administrator', 'Lupon Clerk'], $notifTitle, $notifMsg, $actorUserId, 'warning', $actionUrl, $caseId);
                    $notifService->notifyCaseMembers($caseId, $notifTitle, $notifMsg, $actorUserId, 'warning', $actionUrl);

                    // Notify Complainant user account if exists
                    $compUserStmt = $this->conn->prepare("
                        SELECT u.user_id 
                        FROM users u
                        INNER JOIN complaint_parties cp ON cp.resident_id = u.resident_id
                        WHERE cp.complaint_id = ? AND cp.party_type = 'Complainant'
                        UNION
                        SELECT created_by AS user_id FROM complaints WHERE complaint_id = ? AND created_by IS NOT NULL
                    ");
                    $compUserStmt->execute([$complaintId, $complaintId]);
                    $compUsers = $compUserStmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($compUsers as $cuId) {
                        if ($cuId) {
                            $notifService->notifyUser((int) $cuId, $notifTitle, $notifMsg, $actorUserId, 'success', $actionUrl, $caseId);
                        }
                    }
                } catch (Throwable $ne) {
                    error_log('Failed to dispatch respondent default notifications: ' . $ne->getMessage());
                }
            }

        }



        // Save Show-Cause Evaluation record

        $insEval = $this->conn->prepare("

            INSERT INTO show_cause_evaluations (

                hearing_id, case_id, party_type, form_type, is_justified,

                justification_category, justification_notes, rescheduled_hearing_id,

                action_taken, evaluated_by

            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

        ");

        if ($isBoth) {
            $insEval->execute([
                $hearingId,
                $caseId,
                'Complainant',
                'KP Form 18',
                $isJustified,
                $justificationCategory,
                $justificationNotes ?: null,
                $rescheduledHearingId,
                $actionTaken,
                $actorUserId,
            ]);
            $insEval->execute([
                $hearingId,
                $caseId,
                'Respondent',
                'KP Form 19',
                $isJustified,
                $justificationCategory,
                $justificationNotes ?: null,
                $rescheduledHearingId,
                $actionTaken,
                $actorUserId,
            ]);
        } else {
            $insEval->execute([
                $hearingId,
                $caseId,
                $partyType,
                $formType,
                $isJustified,
                $justificationCategory,
                $justificationNotes ?: null,
                $rescheduledHearingId,
                $actionTaken,
                $actorUserId,
            ]);
        }



        // Mark the Show Cause hearing as completed

        $origId = !empty($hearing['rescheduled_from_id']) ? (int) $hearing['rescheduled_from_id'] : 0;
        if ($isBoth && $origId) {
            $compH = $this->conn->prepare("
                UPDATE hearings 
                SET status = 'Completed' 
                WHERE hearing_id = ? 
                   OR (case_id = ? AND rescheduled_from_id = ? AND hearing_type IN ('Show Cause (Complainant)', 'Show Cause (Respondent)'))
            ");
            $compH->execute([$hearingId, $caseId, $origId]);
        } else {
            $compH = $this->conn->prepare("UPDATE hearings SET status = 'Completed' WHERE hearing_id = ?");
            $compH->execute([$hearingId]);
        }



        return [

            'success' => true,

            'is_justified' => (bool) $isJustified,

            'action_taken' => $actionTaken,

            'message' => $clientMessage,

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

        int $actorUserId

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

        $fileName = 'kp_form_' . strtolower(str_replace(' ', '_', $formCode)) . '_' . $caseId . '_' . time() . '.pdf';

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

                error_log("Failed to render PDF for {$formCode}: " . $e->getMessage());

            }

        }



        // Insert into generated_documents

        $insDoc = $this->conn->prepare("

            INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status)

            VALUES (?, ?, ?, ?, 'For Service')

        ");

        $insDoc->execute([

            $caseId,

            $templateId,

            $actorUserId,

            $relativePath,

        ]);

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

            WHERE hearing_id = ? AND party_type = ?

            LIMIT 1

        ");

        $check->execute([$hearingId, $partyType]);

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

        ?string $respReason = null

    ): void {

        $parties = $this->getCaseParties($complaintId);



        $upsertAtt = $this->conn->prepare("

            INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, is_justified, justification_reason, recorded_by)

            VALUES (?, ?, ?, ?, ?, ?)

            ON DUPLICATE KEY UPDATE 

                attendance_status = VALUES(attendance_status),

                is_justified = VALUES(is_justified),

                justification_reason = VALUES(justification_reason),

                recorded_by = VALUES(recorded_by)

        ");



        if (isset($parties['complainant']['resident_id'])) {

            $isJust = ($complainantAtt === 'Excused' || ($compReason !== null && $compReason !== '')) ? 1 : 0;

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

            $isJust = ($respondentAtt === 'Excused' || ($respReason !== null && $respReason !== '')) ? 1 : 0;

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



    public function getCaseParties(int $complaintId): array

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

            if ($r['party_type'] === 'Complainant' && !$result['complainant']) {

                $result['complainant'] = $r;

            } elseif ($r['party_type'] === 'Respondent' && !$result['respondent']) {

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
