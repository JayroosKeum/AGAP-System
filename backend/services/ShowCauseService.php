<?php



require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/AuditService.php';

require_once __DIR__ . '/SummonDeliveryService.php';

require_once __DIR__ . '/PDFService.php';

require_once __DIR__ . '/OfficeLogisticsService.php';
require_once __DIR__ . '/ValidationService.php';
require_once __DIR__ . '/../models/Hearing.php';



class ShowCauseService

{
    private const EXCUSED_CATEGORIES = [
        'Medical Emergency' => 'Medical Emergency',
        'Official Duty' => 'Work / Official Duty',
        'Force Majeure' => 'Calamity / Force Majeure',
        'Other' => 'Other Valid Reason',
    ];
    private const DISPOSITION_STATUSES = ['DISMISSED_BARRED', 'CFA Issued', 'RESPONDENT_DEFAULT'];

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
        $ownsTransaction = !$this->conn->inTransaction();
        $noticeFiles = [];
        try {
            if ($ownsTransaction) {
                $this->conn->beginTransaction();
            } else {
                $this->conn->exec('SAVEPOINT show_cause_attendance');
            }

            $result = $this->applyAttendance($hearingId, $data, $actorUserId, $noticeFiles);
            if (empty($result['success'])) {
                throw new DomainException($result['message']);
            }
            if ($ownsTransaction) {
                $this->conn->commit();
            } else {
                $this->conn->exec('RELEASE SAVEPOINT show_cause_attendance');
            }
            return $result;
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) {
                if ($ownsTransaction) {
                    $this->conn->rollBack();
                } else {
                    $this->conn->exec('ROLLBACK TO SAVEPOINT show_cause_attendance');
                    $this->conn->exec('RELEASE SAVEPOINT show_cause_attendance');
                }
            }
            foreach ($noticeFiles as $path) {
                $file = dirname(__DIR__, 2) . '/' . $path;
                if (is_file($file)) {
                    unlink($file);
                }
            }
            return isset($result) && empty($result['success'])
                ? $result
                : ['success' => false, 'message' => $error->getMessage()];
        }
    }

    private function applyAttendance(int $hearingId, array $data, int $actorUserId, array &$noticeFiles): array

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
                $pType = $cpRow['party_type'];
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
            FOR UPDATE

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

        foreach (['Complainant' => $complainantAtt, 'Respondent' => $respondentAtt] as $role => $attendance) {
            if ($attendance !== 'Absent') {
                continue;
            }

            $roleKey = strtolower($role);
            $form = $role === 'Complainant' ? 'KP Form 18' : 'KP Form 19';
            $absentIds = $role === 'Complainant' ? $absentComplainantIds : $absentRespondentIds;
            $recipientIds = $absentIds ?: [(int) ($parties[$roleKey]['resident_id'] ?? 0)];
            $showCauseDate = !empty($data[$roleKey . '_show_cause_date'])
                ? date('Y-m-d H:i:s', strtotime($data[$roleKey . '_show_cause_date']))
                : date('Y-m-d 09:00:00', strtotime('+3 days'));
            $showCauseVenue = trim($data['show_cause_venue'] ?? 'Lupon Court Room');
            $showCauseHearingId = $this->createShowCauseHearing(
                $caseId, "Show Cause ({$role})", $showCauseDate, $showCauseVenue, $hearingId,
                "Show-cause hearing for {$role} non-appearance at " . strtolower($hTypeName) . ' on ' . date('M j, Y', strtotime($hearing['hearing_date']))
            );

            $recipientNameStmt = $this->conn->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM residents WHERE resident_id = ?");
            foreach (array_unique($recipientIds) as $recipientId) {
                $recipientNameStmt->execute([$recipientId]);
                $recipientName = $recipientNameStmt->fetchColumn();
                if (!$recipientName) {
                    throw new DomainException("Select a registered {$role} before issuing {$form}.");
                }
                $noticeParties = $parties;
                $noticeParties[$roleKey] = ['resident_id' => $recipientId, 'full_name' => $recipientName];
                $doc = $this->generateKpForm(
                    $caseId, $showCauseHearingId, $form, $role, $noticeParties, $hearing, $showCauseDate, $showCauseVenue, $actorUserId, true
                );
                $noticeFiles[] = $doc['file_path'];
                $this->createShowCauseDelivery($showCauseHearingId, $role, $form, $recipientId, $caseId, $doc['document_id'], $recipientName);
                $results['actions_triggered'][] = [
                    'type' => $form,
                    'party' => $role,
                    'resident_id' => $recipientId,
                    'document_id' => $doc['document_id'],
                    'show_cause_hearing_id' => $showCauseHearingId,
                    'show_cause_date' => $showCauseDate,
                ];
            }
            $this->logCaseHistory($caseId, $stage, "{$role} failed to appear at " . strtolower($hTypeName) . " hearing. {$form} issued for show-cause hearing #{$showCauseHearingId}.", $actorUserId);
            $this->audit->log($actorUserId, "{$role} marked Absent. Issued {$form} for show-cause hearing #{$showCauseHearingId}.", 'Hearings', $caseId);
        }


        $outcome = ($complainantAtt === 'Absent' && $respondentAtt === 'Absent') ? 'both_absent' : ($complainantAtt === 'Absent' ? 'complainant_absent' : 'respondent_absent');



        return [

            'success' => true,

            'outcome' => $outcome,

            'message' => 'Attendance recorded successfully. Non-appearance escalation workflow(s) initiated.',

            'results' => $results,

        ];

    }



    private function fetchOperativeNoticeDelivery(
        int $hearingId,
        int $residentId,
        string $partyType,
        string $formType,
        bool $forUpdate = false
    ): ?array {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $this->conn->prepare("
            SELECT delivery_id, document_id, form_type, delivery_status, attempt_number, attempted_at, served_at
            FROM summon_deliveries
            WHERE hearing_id = ? AND resident_id = ? AND party_type = ? AND form_type = ?
              AND delivery_status <> 'Superseded'
            ORDER BY attempt_number DESC, delivery_id DESC
            LIMIT 1{$lock}
        ");
        $stmt->execute([$hearingId, $residentId, $partyType, $formType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Shared readiness & eligibility evaluation for one recipient of a show-cause hearing.
     * Used by both workspace availability and evaluateShowCause submission under lock.
     */
    private function inspectShowCauseRecipient(array $hearingRow, int $residentId, string $role, bool $forUpdate = false): array
    {
        $scHearingId = (int) ($hearingRow['hearing_id'] ?? 0);
        $originId = (int) ($hearingRow['rescheduled_from_id'] ?? 0);
        $complaintId = (int) ($hearingRow['complaint_id'] ?? 0);
        $formCode = $role === 'Complainant' ? 'KP Form 18' : 'KP Form 19';
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        $cpStmt = $this->conn->prepare("
            SELECT cp.resident_id, cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ? AND cp.resident_id = ? AND cp.party_type = ?
            LIMIT 1{$lock}
        ");
        $cpStmt->execute([$complaintId, $residentId, $role]);
        $cpRow = $cpStmt->fetch(PDO::FETCH_ASSOC);
        $fullName = $cpRow['full_name'] ?? '';
        $belongsToCase = (bool) $cpRow;

        $evalStmt = $this->conn->prepare("
            SELECT sce.*, TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS evaluator_name
            FROM show_cause_evaluations sce
            LEFT JOIN users u ON u.user_id = sce.evaluated_by
            WHERE sce.hearing_id = ? AND sce.party_type = ? AND sce.resident_id = ?
            ORDER BY sce.evaluation_id DESC
            LIMIT 1{$lock}
        ");
        $evalStmt->execute([$scHearingId, $role, $residentId]);
        $evalRow = $evalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $evaluation = null;
        if ($evalRow) {
            $evaluation = [
                'evaluation_id' => (int) $evalRow['evaluation_id'],
                'resident_id' => (int) $evalRow['resident_id'],
                'notice_delivery_id' => $evalRow['notice_delivery_id'] ? (int) $evalRow['notice_delivery_id'] : null,
                'party_type' => $evalRow['party_type'],
                'form_type' => $evalRow['form_type'],
                'is_justified' => (int) $evalRow['is_justified'],
                'justification_category' => $evalRow['justification_category'],
                'justification_notes' => $evalRow['justification_notes'],
                'rescheduled_hearing_id' => $evalRow['rescheduled_hearing_id'] ? (int) $evalRow['rescheduled_hearing_id'] : null,
                'action_taken' => $evalRow['action_taken'],
                'evaluated_by' => $evalRow['evaluated_by'] ? (int) $evalRow['evaluated_by'] : null,
                'evaluator_name' => $evalRow['evaluator_name'] ?? null,
                'evaluated_at' => $evalRow['created_at'] ?? null,
            ];
        }

        if ($forUpdate) {
            $lockDelivStmt = $this->conn->prepare("
                SELECT delivery_id FROM summon_deliveries
                WHERE hearing_id IN (?, ?) AND resident_id = ?
                FOR UPDATE
            ");
            $lockDelivStmt->execute([$scHearingId, $originId ?: $scHearingId, $residentId]);
            $lockDelivStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $attRow = null;
        if ($originId > 0 && $belongsToCase) {
            $attStmt = $this->conn->prepare("
                SELECT attendance_status, is_justified, justification_reason
                FROM hearing_attendance
                WHERE hearing_id = ? AND resident_id = ?
                LIMIT 1{$lock}
            ");
            $attStmt->execute([$originId, $residentId]);
            $attRow = $attStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $hasUnexcusedAbsence = ($attRow && ($attRow['attendance_status'] ?? '') === 'Absent' && (int) ($attRow['is_justified'] ?? 0) === 0);

        $origService = ($originId > 0 && $belongsToCase)
            ? $this->deliveryService->verifyRecipientServiceForHearing($originId, $residentId, $role)
            : ['verified' => false, 'status' => 'Missing', 'delivery_id' => null, 'document_id' => null, 'attempt_number' => null, 'basis' => null, 'next_action' => 'Original hearing or case party missing.'];
        $origNoticeForm = null;
        if (!empty($origService['delivery_id'])) {
            $origFormStmt = $this->conn->prepare("SELECT form_type FROM summon_deliveries WHERE delivery_id = ? LIMIT 1");
            $origFormStmt->execute([(int) $origService['delivery_id']]);
            $origNoticeForm = $origFormStmt->fetchColumn() ?: null;
        }

        $operativeNotice = $belongsToCase
            ? $this->fetchOperativeNoticeDelivery($scHearingId, $residentId, $role, $formCode, $forUpdate)
            : null;
        $servedNoticeId = $belongsToCase
            ? $this->deliveryService->getServedShowCauseNotice($scHearingId, $residentId, $role)
            : null;
        $scService = $belongsToCase
            ? $this->deliveryService->verifyRecipientServiceForHearing($scHearingId, $residentId, $role)
            : ['verified' => false, 'status' => 'Missing', 'delivery_id' => null, 'document_id' => null, 'attempt_number' => null, 'basis' => null, 'next_action' => 'Recipient does not belong to case.'];

        $shStatus = (string) ($hearingRow['status'] ?? '');
        $origStatus = (string) ($hearingRow['origin_status'] ?? '');
        $caseStatus = (string) ($hearingRow['case_status'] ?? '');
        $hardClosedStatuses = ['Archived', 'Settled', 'Dismissed', 'Repudiated'];
        $hasDisposition = in_array($caseStatus, self::DISPOSITION_STATUSES, true);
        $hasSiblingUnjustifiedOnOrigin = false;
        if ($hasDisposition && $originId > 0) {
            $sibEvalStmt = $this->conn->prepare("
                SELECT 1
                FROM show_cause_evaluations prev
                INNER JOIN hearings ph ON ph.hearing_id = prev.hearing_id
                WHERE prev.case_id = ? AND ph.rescheduled_from_id = ?
                  AND prev.is_justified = 0 AND prev.resident_id IS NOT NULL AND prev.notice_delivery_id IS NOT NULL
                  AND NOT (prev.hearing_id = ? AND prev.resident_id = ?)
                LIMIT 1{$lock}
            ");
            $sibEvalStmt->execute([(int) $hearingRow['case_id'], $originId, $scHearingId, $residentId]);
            $hasSiblingUnjustifiedOnOrigin = (bool) $sibEvalStmt->fetchColumn();
        }

        $eligible = false;
        $readinessStatus = 'awaiting_service';
        $blockingReason = null;

        if (!$belongsToCase) {
            $readinessStatus = 'invalid_recipient';
            $blockingReason = "Selected recipient does not belong to this case as {$role}.";
        } elseif ($evaluation !== null) {
            $readinessStatus = 'decided';
            $blockingReason = "Saved determination already recorded ({$evaluation['action_taken']}).";
        } elseif (in_array($caseStatus, $hardClosedStatuses, true) || ($hasDisposition && !$hasSiblingUnjustifiedOnOrigin)) {
            $readinessStatus = 'closed';
            $blockingReason = "Case is {$caseStatus} and closed for new show-cause determinations.";
        } elseif (in_array($shStatus, ['Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed'], true)) {
            $readinessStatus = 'closed';
            $blockingReason = "Show-cause hearing is already {$shStatus}.";
        } elseif ($originId <= 0 || in_array($origStatus, ['Cancelled', 'Office Cancelled', 'Rescheduled'], true)) {
            $readinessStatus = 'closed';
            $blockingReason = 'The linked original hearing is missing, cancelled, or superseded.';
        } elseif (!$hasUnexcusedAbsence) {
            $readinessStatus = 'no_unexcused_absence';
            if ($attRow && ($attRow['attendance_status'] ?? '') === 'Absent' && (int) ($attRow['is_justified'] ?? 0) === 1) {
                $blockingReason = 'Absence at the original hearing was already excused/justified.';
            } else {
                $originLabel = $hearingRow['origin_type'] ?? 'original';
                $blockingReason = "No recorded unexcused absence at the original {$originLabel} hearing for this recipient.";
            }
        } elseif (empty($origService['verified'])) {
            $readinessStatus = 'awaiting_service';
            $origStatusText = $origService['status'] ?? 'Pending';
            $blockingReason = "Held: awaiting verified service of original hearing notice (current status: {$origStatusText}).";
        } elseif (!$operativeNotice) {
            $readinessStatus = 'awaiting_service';
            $blockingReason = "Held: no operative {$formCode} notice is assigned to this recipient yet.";
        } elseif (!$servedNoticeId || empty($scService['verified'])) {
            $readinessStatus = 'awaiting_service';
            $scStatusText = $operativeNotice['delivery_status'] ?? ($scService['status'] ?? 'Pending');
            $blockingReason = "Held: awaiting verified Proof of Service for operative {$formCode} (current status: {$scStatusText}).";
        } elseif ((int) $operativeNotice['delivery_id'] !== (int) $servedNoticeId) {
            $readinessStatus = 'awaiting_service';
            $scStatusText = $operativeNotice['delivery_status'] ?? 'Pending';
            $blockingReason = "Held: a newer operative {$formCode} attempt is pending verification (current status: {$scStatusText}).";
        } else {
            $eligible = true;
            $readinessStatus = 'ready';
        }

        $operativeNoticeId = $operativeNotice ? (int) $operativeNotice['delivery_id'] : (!empty($scService['delivery_id']) ? (int) $scService['delivery_id'] : null);
        $operativeDocId = ($operativeNotice && !empty($operativeNotice['document_id']))
            ? (int) $operativeNotice['document_id']
            : (!empty($scService['document_id']) ? (int) $scService['document_id'] : null);

        $previewDocId = null;
        if ($operativeDocId && $operativeNotice && !$forUpdate) {
            $shared = $this->conn->prepare('SELECT 1 FROM summon_deliveries WHERE document_id = ? AND (resident_id IS NULL OR resident_id <> ?) LIMIT 1');
            $shared->execute([$operativeDocId, $residentId]);
            if (!$shared->fetchColumn()) {
                $previewDocId = $operativeDocId;
            }
        }

        return [
            'resident_id' => $residentId,
            'full_name' => $fullName ?: "{$role} #{$residentId}",
            'party_type' => $role,
            'belongs_to_case' => $belongsToCase,
            'operative_form' => $formCode,
            'readiness_status' => $readinessStatus,
            'eligible' => $eligible,
            'ready_for_decision' => $eligible,
            'can_reschedule_if_excused' => $eligible && !$hasDisposition,
            'awaiting_service' => ($readinessStatus === 'awaiting_service'),
            'blocking_reason' => $blockingReason,
            'expected_notice_delivery_id' => $servedNoticeId ?: $operativeNoticeId,
            'expected_notice_document_id' => $operativeDocId,
            'expected_original_delivery_id' => !empty($origService['delivery_id']) ? (int) $origService['delivery_id'] : null,
            'operative_notice_delivery_id' => $operativeNoticeId,
            'operative_notice_document_id' => $operativeDocId,
            'preview_document_id' => $previewDocId,
            'served_notice_delivery_id' => $servedNoticeId,
            'attendance' => [
                'attendance_status' => $attRow['attendance_status'] ?? 'Not Recorded',
                'is_justified' => (int) ($attRow['is_justified'] ?? 0),
                'justification_reason' => $attRow['justification_reason'] ?? null,
                'has_unexcused_absence' => (bool) $hasUnexcusedAbsence,
            ],
            'original_notice' => [
                'delivery_id' => !empty($origService['delivery_id']) ? (int) $origService['delivery_id'] : null,
                'document_id' => !empty($origService['document_id']) ? (int) $origService['document_id'] : null,
                'form_type' => $origNoticeForm,
                'status' => $origService['status'] ?? 'Pending',
                'attempt_number' => $origService['attempt_number'] ?? null,
                'verified' => !empty($origService['verified']),
                'basis' => $origService['basis'] ?? null,
                'next_action' => $origService['next_action'] ?? null,
            ],
            'operative_notice' => [
                'delivery_id' => $operativeNoticeId,
                'document_id' => $operativeDocId,
                'form_type' => $formCode,
                'delivery_status' => $operativeNotice['delivery_status'] ?? ($scService['status'] ?? 'Pending'),
                'attempt_number' => $operativeNotice['attempt_number'] ?? ($scService['attempt_number'] ?? null),
                'served_at' => $operativeNotice['served_at'] ?? null,
                'verified' => !empty($servedNoticeId) && !empty($scService['verified']),
                'basis' => $scService['basis'] ?? null,
                'next_action' => $scService['next_action'] ?? null,
            ],
            'evaluation' => $evaluation,
        ];
    }

    /**
     * Returns structured show-cause items (original hearing, linked show-cause hearing,
     * per-recipient operative KP Form 18/19, service verification, readiness & saved decisions)
     * for Mediation and/or Conciliation workspaces.
     */
    public function getShowCauseWorkspaceItems(int $caseId, ?string $stageType = null): array
    {
        $stageFilterSql = '';
        $params = [$caseId];
        if ($stageType !== null && in_array($stageType, ['Mediation', 'Conciliation'], true)) {
            if ($stageType === 'Mediation') {
                $stageFilterSql = "
                    AND (
                        orig_h.hearing_type IN ('Mediation', 'Initial Hearing')
                        OR (orig_h.hearing_id IS NULL AND c.case_status IN ('Mediation', 'Docketed'))
                        OR (orig_h.hearing_id IS NULL AND LOWER(COALESCE(sh.remarks, '')) LIKE '%mediation%')
                    )
                ";
            } else {
                $stageFilterSql = "
                    AND (
                        orig_h.hearing_type = 'Conciliation'
                        OR (orig_h.hearing_id IS NULL AND c.case_status = 'Conciliation')
                        OR (orig_h.hearing_id IS NULL AND LOWER(COALESCE(sh.remarks, '')) LIKE '%conciliation%')
                    )
                ";
            }
        }

        $stmt = $this->conn->prepare("
            SELECT sh.hearing_id, sh.case_id, sh.hearing_type, sh.hearing_date, sh.venue, sh.status,
                   sh.queue_status, sh.actual_start_time, sh.actual_end_time, sh.actual_duration_minutes,
                   sh.rescheduled_from_id, sh.remarks,
                   sh.complainant_attendance, sh.respondent_attendance,
                   sh.attendance_recorded_at, sh.attendance_notes,
                   c.complaint_id, c.case_number, c.case_status,
                   TRIM(CONCAT_WS(' ', po.first_name, po.middle_name, po.last_name)) AS presiding_officer_name,
                   orig_h.hearing_type AS origin_type,
                   orig_h.hearing_date AS origin_hearing_date,
                   orig_h.venue AS origin_venue,
                   orig_h.status AS origin_status
            FROM hearings sh
            INNER JOIN cases c ON c.case_id = sh.case_id
            LEFT JOIN hearings orig_h ON orig_h.hearing_id = sh.rescheduled_from_id AND orig_h.case_id = sh.case_id
            LEFT JOIN users po ON po.user_id = sh.presiding_officer_id
            WHERE sh.case_id = ?
              AND sh.hearing_type IN ('Show Cause (Complainant)', 'Show Cause (Respondent)')
              {$stageFilterSql}
            ORDER BY sh.hearing_date ASC, sh.hearing_id ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $docStmt = $this->conn->prepare("
            SELECT gd.document_id, dt.template_name, gd.file_path, gd.generated_at
            FROM generated_documents gd
            INNER JOIN document_templates dt ON dt.template_id = gd.template_id
            WHERE gd.case_id = ?
            ORDER BY gd.document_id DESC
        ");
        $docStmt->execute([$caseId]);
        $caseDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

        $targetIdsStmt = $this->conn->prepare("
            SELECT cp.resident_id
            FROM complaint_parties cp
            WHERE cp.complaint_id = ? AND cp.party_type = ?
              AND (
                  EXISTS (
                      SELECT 1 FROM hearing_attendance ha
                      WHERE ha.hearing_id = ? AND ha.resident_id = cp.resident_id
                        AND ha.attendance_status = 'Absent' AND ha.is_justified = 0
                  )
                  OR EXISTS (
                      SELECT 1 FROM summon_deliveries sd
                      WHERE sd.hearing_id = ? AND sd.resident_id = cp.resident_id AND sd.party_type = cp.party_type
                  )
                  OR EXISTS (
                      SELECT 1 FROM show_cause_evaluations sce
                      WHERE sce.hearing_id = ? AND sce.resident_id = cp.resident_id AND sce.party_type = cp.party_type
                  )
              )
            ORDER BY cp.resident_id ASC
        ");
        $fallbackPartyStmt = $this->conn->prepare("
            SELECT cp.resident_id FROM complaint_parties cp
            WHERE cp.complaint_id = ? AND cp.party_type = ?
            ORDER BY cp.resident_id ASC
        ");
        $legacyEvaluationStmt = $this->conn->prepare("
            SELECT party_type, is_justified, justification_category, justification_notes, action_taken, created_at
            FROM show_cause_evaluations WHERE hearing_id = ? AND resident_id IS NULL ORDER BY evaluation_id
        ");

        $results = [];
        foreach ($rows as $row) {
            $hId = (int) $row['hearing_id'];
            $originId = (int) ($row['rescheduled_from_id'] ?? 0);
            $complaintId = (int) $row['complaint_id'];
            $targetParty = ($row['hearing_type'] === 'Show Cause (Complainant)') ? 'Complainant' : 'Respondent';
            $formCode = ($targetParty === 'Complainant') ? 'KP Form 18' : 'KP Form 19';

            $targetIdsStmt->execute([$complaintId, $targetParty, $originId, $hId, $hId]);
            $recipientIds = array_map('intval', $targetIdsStmt->fetchAll(PDO::FETCH_COLUMN));
            if (empty($recipientIds)) {
                $fallbackPartyStmt->execute([$complaintId, $targetParty]);
                $recipientIds = array_map('intval', $fallbackPartyStmt->fetchAll(PDO::FETCH_COLUMN));
            }

            $recipientItems = [];
            $partiesLegacy = [];
            $readyCount = 0;
            $awaitingCount = 0;
            $decidedCount = 0;
            $firstEvaluation = null;

            foreach ($recipientIds as $rId) {
                $item = $this->inspectShowCauseRecipient($row, $rId, $targetParty, false);
                $recipientItems[] = $item;
                if ($item['readiness_status'] === 'ready') {
                    $readyCount++;
                } elseif ($item['readiness_status'] === 'decided') {
                    $decidedCount++;
                    if ($firstEvaluation === null && !empty($item['evaluation'])) {
                        $firstEvaluation = $item['evaluation'];
                    }
                } elseif ($item['readiness_status'] === 'awaiting_service') {
                    $awaitingCount++;
                }

                $partiesLegacy[] = [
                    'resident_id' => $item['resident_id'],
                    'party_type' => $item['party_type'],
                    'full_name' => $item['full_name'],
                    'attendance_status' => $item['attendance']['attendance_status'],
                    'is_justified' => $item['attendance']['is_justified'],
                    'justification_reason' => $item['attendance']['justification_reason'],
                    'service_confirmed_count' => !empty($item['operative_notice']['verified']) ? 1 : 0,
                    'readiness_status' => $item['readiness_status'],
                    'blocking_reason' => $item['blocking_reason'],
                ];
            }

            $proofStatus = ($readyCount > 0 || $decidedCount > 0) ? 'Served' : 'Pending Delivery';
            $legacyEvaluationStmt->execute([$hId]);

            $relevantDocs = array_filter($caseDocs, function ($d) use ($formCode) {
                $name = $d['template_name'] ?? '';
                return str_contains($name, $formCode)
                    || str_contains($name, str_replace('KP Form ', '', $formCode))
                    || str_contains($name, '20')
                    || str_contains($name, '21')
                    || str_contains($name, '22');
            });

            $results[] = [
                'hearing_id' => $hId,
                'case_id' => (int) $row['case_id'],
                'hearing_type' => $row['hearing_type'],
                'hearing_date' => $row['hearing_date'],
                'venue' => $row['venue'],
                'status' => $row['status'],
                'queue_status' => $row['queue_status'] ?? 'Waiting',
                'rescheduled_from_id' => $originId ?: null,
                'original_hearing_id' => $originId ?: null,
                'original_hearing_type' => $row['origin_type'],
                'original_hearing_date' => $row['origin_hearing_date'],
                'original_hearing_venue' => $row['origin_venue'],
                'original_hearing_status' => $row['origin_status'],
                'remarks' => $row['remarks'],
                'complainant_attendance' => $row['complainant_attendance'],
                'respondent_attendance' => $row['respondent_attendance'],
                'presiding_officer_name' => $row['presiding_officer_name'],
                'proof_of_service_status' => $proofStatus,
                'target_party' => $targetParty,
                'form_code' => $formCode,
                'ready_count' => $readyCount,
                'awaiting_service_count' => $awaitingCount,
                'decided_count' => $decidedCount,
                'recipient_items' => $recipientItems,
                'legacy_evaluations' => $legacyEvaluationStmt->fetchAll(PDO::FETCH_ASSOC),
                'excused_categories' => self::EXCUSED_CATEGORIES,
                'parties' => $partiesLegacy,
                'evaluation' => $firstEvaluation,
                'documents' => array_values($relevantDocs),
            ];
        }

        return $results;
    }

    /**

     * Evaluates a Show-Cause Hearing (KP Form 18 or KP Form 19).

     */

    public function evaluateShowCause(int $hearingId, array $data, int $actorUserId): array
    {
        $startedTrans = !$this->conn->inTransaction();
        $newFiles = [];
        $hearingModel = new Hearing($this->conn);
        $scheduleLocks = [];

        try {
            if ($startedTrans) {
                $this->conn->beginTransaction();
            }

            $stmt = $this->conn->prepare("
                SELECT sh.*, c.case_number, c.case_status, c.complaint_id, co.complaint_title,
                       origin.hearing_type AS origin_type, origin.status AS origin_status,
                       origin.hearing_date AS origin_hearing_date, origin.venue AS origin_venue,
                       origin.presiding_officer_id AS origin_presider_id
                FROM hearings sh
                JOIN cases c ON c.case_id = sh.case_id
                JOIN complaints co ON co.complaint_id = c.complaint_id
                JOIN hearings origin ON origin.hearing_id = sh.rescheduled_from_id AND origin.case_id = sh.case_id
                WHERE sh.hearing_id = ?
                  AND sh.hearing_type IN ('Show Cause (Complainant)', 'Show Cause (Respondent)')
                  AND sh.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
                  AND origin.hearing_type IN ('Initial Hearing', 'Mediation', 'Conciliation')
                  AND origin.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled')
                  AND c.case_status NOT IN ('Archived', 'Settled', 'Dismissed')
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
            $targetRows = [$hearingRole => $hearing];
            if ($partyType === 'Both') {
                $otherRole = $hearingRole === 'Complainant' ? 'Respondent' : 'Complainant';
                $siblings = $this->conn->prepare("
                    SELECT sh.*, c.case_number, c.case_status, c.complaint_id, co.complaint_title,
                           origin.hearing_type AS origin_type, origin.status AS origin_status,
                           origin.hearing_date AS origin_hearing_date, origin.venue AS origin_venue
                    FROM hearings sh
                    JOIN cases c ON c.case_id = sh.case_id
                    JOIN complaints co ON co.complaint_id = c.complaint_id
                    JOIN hearings origin ON origin.hearing_id = sh.rescheduled_from_id AND origin.case_id = sh.case_id
                    WHERE sh.case_id = ? AND sh.rescheduled_from_id = ? AND sh.hearing_type = ?
                      AND sh.status NOT IN ('Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed')
                    FOR UPDATE
                ");
                $siblings->execute([$caseId, $originId, "Show Cause ({$otherRole})"]);
                $siblingRows = $siblings->fetchAll(PDO::FETCH_ASSOC);
                if (count($siblingRows) !== 1) {
                    throw new DomainException('Select one show-cause hearing at a time when there is no unique companion hearing.');
                }
                $targets[$otherRole] = (int) $siblingRows[0]['hearing_id'];
                $targetRows[$otherRole] = $siblingRows[0];
            }

            $residentId = filter_var($data['resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (array_key_exists('resident_id', $data) && $data['resident_id'] !== '' && $data['resident_id'] !== null && !$residentId) {
                throw new DomainException('Select a valid show-cause recipient.');
            }

            $hasExpectedNotice = array_key_exists('expected_notice_delivery_id', $data) && $data['expected_notice_delivery_id'] !== '' && $data['expected_notice_delivery_id'] !== null;
            $expectedNoticeId = $hasExpectedNotice ? filter_var($data['expected_notice_delivery_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
            if ($hasExpectedNotice && !$expectedNoticeId) {
                throw new DomainException('Stale or invalid operative show-cause notice reference. Refresh the workspace and try again.');
            }

            $hasExpectedOrig = array_key_exists('expected_original_delivery_id', $data) && $data['expected_original_delivery_id'] !== '' && $data['expected_original_delivery_id'] !== null;
            $expectedOrigId = $hasExpectedOrig ? filter_var($data['expected_original_delivery_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
            if ($hasExpectedOrig && !$expectedOrigId) {
                throw new DomainException('Stale or invalid original notice reference. Refresh the workspace and try again.');
            }

            $hasExpectedDoc = array_key_exists('expected_notice_document_id', $data) && $data['expected_notice_document_id'] !== '' && $data['expected_notice_document_id'] !== null;
            $expectedDocId = $hasExpectedDoc ? filter_var($data['expected_notice_document_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
            if ($hasExpectedDoc && !$expectedDocId) {
                throw new DomainException('Stale or invalid notice document reference. Refresh the workspace and try again.');
            }

            $parties = $this->getCaseParties((int) $hearing['complaint_id']);
            $absentListStmt = $this->conn->prepare("
                SELECT ha.resident_id
                FROM hearing_attendance ha
                JOIN complaint_parties cp ON cp.complaint_id = ? AND cp.resident_id = ha.resident_id
                WHERE ha.hearing_id = ? AND cp.party_type = ?
                  AND ha.attendance_status = 'Absent' AND ha.is_justified = 0
                FOR UPDATE
            ");
            $evaluated = [];
            foreach ($targets as $role => $targetHearingId) {
                $targetHearingRow = $targetRows[$role];
                if ($residentId && $role === $hearingRole) {
                    $inspection = $this->inspectShowCauseRecipient($targetHearingRow, (int) $residentId, $role, true);
                    if ($hasExpectedNotice) {
                        $currentOpNoticeId = (int) ($inspection['operative_notice_delivery_id'] ?? 0);
                        $currentServedId = (int) ($inspection['served_notice_delivery_id'] ?? 0);
                        if ($currentOpNoticeId !== (int) $expectedNoticeId || ($currentServedId > 0 && $currentServedId !== (int) $expectedNoticeId)) {
                            throw new DomainException('Stale show-cause selection: the operative notice changed after the workspace loaded. Refresh and review the updated record before submitting.');
                        }
                    }
                    if ($hasExpectedDoc && (int) ($inspection['operative_notice_document_id'] ?? 0) !== (int) $expectedDocId) {
                        throw new DomainException('Stale show-cause selection: the operative KP Form notice document changed after the workspace loaded.');
                    }
                    if ($hasExpectedOrig && (int) ($inspection['expected_original_delivery_id'] ?? 0) !== (int) $expectedOrigId) {
                        throw new DomainException('Stale show-cause selection: the operative original hearing notice changed after the workspace loaded.');
                    }
                    if (empty($inspection['eligible'])) {
                        throw new DomainException($inspection['blocking_reason'] ?: 'Recipient is not eligible for show-cause decision.');
                    }
                    $matchParty = [
                        'resident_id' => $inspection['resident_id'],
                        'party_type' => $role,
                        'full_name' => $inspection['full_name'],
                    ];
                    $evaluated[$role] = [
                        'party' => $matchParty,
                        'notice_id' => (int) $inspection['served_notice_delivery_id'],
                    ];
                    $parties[strtolower($role)] = $matchParty;
                    continue;
                }

                $absentListStmt->execute([$hearing['complaint_id'], $originId, $role]);
                $matches = [];
                foreach ($absentListStmt->fetchAll(PDO::FETCH_COLUMN) as $candId) {
                    $inspection = $this->inspectShowCauseRecipient($targetHearingRow, (int) $candId, $role, true);
                    if (!empty($inspection['eligible'])) {
                        $matches[] = [
                            'party' => [
                                'resident_id' => $inspection['resident_id'],
                                'party_type' => $role,
                                'full_name' => $inspection['full_name'],
                            ],
                            'notice_id' => (int) $inspection['served_notice_delivery_id'],
                        ];
                    }
                }
                if (count($matches) > 1) {
                    throw new DomainException("Multiple {$role} recipients are ready; select a specific recipient to evaluate.");
                }
                if (count($matches) !== 1) {
                    throw new DomainException("Select one {$role} with recorded unexcused absence and verified service of both the original and show-cause notices.");
                }
                $evaluated[$role] = $matches[0];
                $parties[strtolower($role)] = $matches[0]['party'];
            }

            $justified = !empty($data['is_justified']);
            $category = trim((string) ($data['justification_category'] ?? ''));
            if ($justified && !isset(self::EXCUSED_CATEGORIES[$category])) {
                throw new DomainException('Select a valid reason for excusing this absence.');
            }
            if (!$justified && !in_array($category, ['Willful Refusal', 'Unjustified Absence'], true)) {
                $category = 'Unjustified Absence';
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
                $action = 'Excused';
                $message = "Absence was accepted as justified ({$category}). The recorded case disposition stays in effect.";
                if (!in_array($hearing['case_status'], self::DISPOSITION_STATUSES, true)) {
                    $type = $hearing['origin_type'] === 'Conciliation' ? 'Conciliation' : 'Mediation';
                    $date = trim((string) ($data['reschedule_date'] ?? ''));
                    if (ValidationService::dateTime($date, 'Y-m-d H:i')) {
                        $date .= ':00';
                    }
                    if (!ValidationService::dateTime($date, 'Y-m-d H:i:s')) {
                        throw new DomainException('Enter a valid date and time for the replacement hearing.');
                    }
                    if ($date <= max(date('Y-m-d H:i:s'), $hearing['hearing_date'])) {
                        throw new DomainException('Choose a future appointment after the show-cause hearing.');
                    }
                    $venue = trim((string) ($data['reschedule_venue'] ?? '')) ?: trim((string) $hearing['origin_venue']);
                    $venue = $venue ?: (new OfficeLogisticsService($this->conn))->getConfiguredDefaultVenue($type);
                    $presiderId = !empty($hearing['origin_presider_id']) ? (int) $hearing['origin_presider_id'] : null;
                    $schedule = $hearingModel->validateHearingOperatingHours($date, null, $type);
                    if (!$schedule['valid']) {
                        throw new DomainException($schedule['message']);
                    }
                    $locks = $hearingModel->acquireScheduleLocks(['hearing_date' => $date, 'venue' => $venue, 'presiding_officer_id' => $presiderId]);
                    if ($locks === false) {
                        throw new DomainException('Another scheduling request is in progress. Try again.');
                    }
                    $scheduleLocks = $locks;
                    $progression = $hearingModel->validateHearingDateProgression($caseId, $date, $originId, $type);
                    if (!$progression['valid']) {
                        throw new DomainException($progression['message']);
                    }
                    $conflict = $hearingModel->findConflict($date, $venue, $caseId, null, $presiderId, $schedule['duration_minutes'], $type);
                    if ($conflict) {
                        throw new DomainException($conflict['message']);
                    }
                    $rescheduledHearingId = $this->createRescheduledHearing(
                        $caseId, $type, $date, $venue, $hearingId,
                        "Show-cause absence justified ({$category}). Notes: {$notes}", $schedule, $presiderId
                    );
                    $action = 'Rescheduled';
                    $message = "Absence was accepted as justified ({$category}). {$type} hearing rescheduled.";
                }
                $justify = $this->conn->prepare("
                    UPDATE hearing_attendance SET is_justified = 1, justification_reason = ?
                    WHERE hearing_id = ? AND resident_id = ?
                ");
                foreach ($evaluated as $target) {
                    $justify->execute([$category, $originId, $target['party']['resident_id']]);
                }
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
            $remainingStmt = $this->conn->prepare("
                SELECT COUNT(*)
                FROM hearing_attendance ha
                JOIN complaint_parties cp ON cp.complaint_id = ? AND cp.resident_id = ha.resident_id AND cp.party_type = ?
                WHERE ha.hearing_id = ? AND ha.attendance_status = 'Absent' AND ha.is_justified = 0
                  AND NOT EXISTS (
                      SELECT 1 FROM show_cause_evaluations sce
                      WHERE sce.hearing_id = ? AND sce.resident_id = ha.resident_id AND sce.party_type = cp.party_type
                  )
            ");
            foreach ($targets as $role => $targetHearingId) {
                $target = $evaluated[$role];
                $insert->execute([
                    $targetHearingId, $caseId, $role, $target['party']['resident_id'], $target['notice_id'],
                    $role === 'Complainant' ? 'KP Form 18' : 'KP Form 19', (int) $justified,
                    $category, $notes ?: null, $rescheduledHearingId, $action, $actorUserId,
                ]);
                $remainingStmt->execute([$hearing['complaint_id'], $role, $originId, $targetHearingId]);
                $remainingCount = (int) $remainingStmt->fetchColumn();
                if ($remainingCount === 0) {
                    $complete->execute([$targetHearingId]);
                }
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
        } finally {
            $hearingModel->releaseScheduleLocks($scheduleLocks);
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
                        SELECT submitted_by FROM complaints WHERE complaint_id = ? AND submitted_by IS NOT NULL
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

        $primaryTarget = $evaluated[$hearingRole] ?? reset($evaluated);
        return [
            'success' => true,
            'is_justified' => $justified,
            'action_taken' => $action,
            'case_status' => $historyStatus,
            'resident_id' => $primaryTarget ? (int) $primaryTarget['party']['resident_id'] : null,
            'notice_delivery_id' => $primaryTarget ? (int) $primaryTarget['notice_id'] : null,
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
                    'hearing_date' => $explanationDate ?? ($hearing['hearing_date'] ?? null),
                    'missed_hearing_date' => $hearing['hearing_date'] ?? null,
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

        string $reason,
        array $schedule = [],
        ?int $presidingOfficerId = null

    ): int {

        $stmt = $this->conn->prepare("

            INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id, reschedule_reason,
                                  end_time, duration_minutes, presiding_officer_id)

            VALUES (?, ?, ?, ?, 'Scheduled', ?, ?, ?, ?, ?)

        ");

        $stmt->execute([

            $caseId,

            $hearingType,

            $hearingDate,

            $venue,

            $rescheduledFromId,

            $reason,
            $schedule['end_time'] ?? null,
            $schedule['duration_minutes'] ?? null,
            $presidingOfficerId,

        ]);



        $hearingId = (int) $this->conn->lastInsertId();

        try {

            $this->deliveryService->initializeHearingDeliveries($hearingId);

        } catch (Throwable $e) {

            if ($schedule) {
                throw $e;
            }
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
