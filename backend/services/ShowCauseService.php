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
        $serviceCheck = $this->deliveryService->checkServiceStatus($hearingId);
        if (!$serviceCheck['allowed']) {
            return [
                'success' => false,
                'message' => 'Attendance locked: ' . $serviceCheck['message'],
            ];
        }

        $complainantAtt = trim($data['complainant_attendance'] ?? '');
        $respondentAtt = trim($data['respondent_attendance'] ?? '');

        if (!in_array($complainantAtt, ['Present', 'Absent'], true) || !in_array($respondentAtt, ['Present', 'Absent'], true)) {
            return [
                'success' => false,
                'message' => 'Both Complainant and Respondent attendance must be marked as Present or Absent.',
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

        // Determine the correct hearing status:
        // - 'Completed'            → both parties present; no follow-up hearing needed.
        // - 'Attendance Recorded'  → at least one party absent; a Show Cause hearing will
        //                           be spawned, so the original hearing is not yet resolved.
        $hearingStatus = ($complainantAtt === 'Present' && $respondentAtt === 'Present')
            ? 'Completed'
            : 'Attendance Recorded';

        // Update hearings record
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
            $attendanceNotes ?: null,
            $hearingStatus,
            $hearingId,
        ]);

        // Sync with hearing_attendance table for party rows
        $this->syncHearingAttendanceTable($hearingId, (int) $hearing['complaint_id'], $complainantAtt, $respondentAtt, $actorUserId);

        // Fetch parties for document and hearing generation
        $parties = $this->getCaseParties((int) $hearing['complaint_id']);

        $results = [
            'hearing_id' => $hearingId,
            'complainant_attendance' => $complainantAtt,
            'respondent_attendance' => $respondentAtt,
            'actions_triggered' => [],
        ];

        // 1. Both Present
        if ($complainantAtt === 'Present' && $respondentAtt === 'Present') {
            $msg = "Mediation hearing on " . date('F j, Y', strtotime($hearing['hearing_date'])) . " conducted. Both parties present.";
            $this->logCaseHistory($caseId, 'Mediation', $msg, $actorUserId);
            $this->audit->log($actorUserId, "Recorded hearing attendance: Both Present for Case #{$hearing['case_number']}", 'Hearings', $caseId);

            return [
                'success' => true,
                'outcome' => 'both_present',
                'message' => 'Both parties are present. You may now proceed to Mediation Notes and Settlement drafting.',
                'results' => $results,
            ];
        }

        // 2. Complainant Absent -> Trigger KP Form 18 Workflow
        if ($complainantAtt === 'Absent') {
            $showCauseDate = !empty($data['complainant_show_cause_date'])
                ? date('Y-m-d H:i:s', strtotime($data['complainant_show_cause_date']))
                : date('Y-m-d 09:00:00', strtotime('+3 days'));
            $showCauseVenue = trim($data['show_cause_venue'] ?? 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall');

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
                "Show-cause hearing for Complainant non-appearance at mediation on " . date('M j, Y', strtotime($hearing['hearing_date']))
            );

            // Create Summon Delivery record for this Show Cause hearing
            $this->createShowCauseDelivery($scHearingId, 'Complainant', 'KP Form 18', $parties['complainant']['resident_id'] ?? null);

            $histMsg = "Complainant failed to appear at mediation hearing on " . date('M j, Y', strtotime($hearing['hearing_date'])) . ". KP Form 18 generated (Doc #{$doc18['document_id']}). Show-cause hearing scheduled for " . date('M j, Y g:i A', strtotime($showCauseDate)) . ".";
            $this->logCaseHistory($caseId, 'Mediation', $histMsg, $actorUserId);
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
            $showCauseVenue = trim($data['show_cause_venue'] ?? 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall');

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
                "Show-cause hearing for Respondent non-appearance at mediation on " . date('M j, Y', strtotime($hearing['hearing_date']))
            );

            // Create Summon Delivery record for this Show Cause hearing
            $this->createShowCauseDelivery($scHearingId, 'Respondent', 'KP Form 19', $parties['respondent']['resident_id'] ?? null);

            $histMsg = "Respondent failed to appear at mediation hearing on " . date('M j, Y', strtotime($hearing['hearing_date'])) . ". KP Form 19 generated (Doc #{$doc19['document_id']}). Show-cause hearing scheduled for " . date('M j, Y g:i A', strtotime($showCauseDate)) . ".";
            $this->logCaseHistory($caseId, 'Mediation', $histMsg, $actorUserId);
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
        if (!in_array($partyType, ['Complainant', 'Respondent'], true)) {
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
            // OUTCOME 1: Justified Reason -> Reschedule Mediation Hearing
            $rescheduleDate = !empty($data['reschedule_date'])
                ? date('Y-m-d H:i:s', strtotime($data['reschedule_date']))
                : date('Y-m-d 09:00:00', strtotime('+3 days'));
            $rescheduleVenue = trim($data['reschedule_venue'] ?? 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall');

            $rescheduledHearingId = $this->createRescheduledMediation(
                $caseId,
                $rescheduleDate,
                $rescheduleVenue,
                $hearingId,
                "Show-cause absence justified ({$justificationCategory}). Notes: {$justificationNotes}"
            );

            // Re-initialize summon deliveries for the new mediation hearing
            $this->deliveryService->getHearingDeliveries($rescheduledHearingId);

            $actionTaken = 'Rescheduled';
            $clientMessage = "Absence was accepted as justified ({$justificationCategory}). Mediation hearing rescheduled to " . date('F j, Y g:i A', strtotime($rescheduleDate)) . ".";

            $hist = "Show-cause evaluation: {$partyType} absence justified ({$justificationCategory}). Mediation rescheduled for " . date('M j, Y g:i A', strtotime($rescheduleDate)) . ".";
            $this->logCaseHistory($caseId, $hearing['case_status'], $hist, $actorUserId);
            $this->audit->log($actorUserId, "Show-cause absence justified for {$partyType} in Case #{$hearing['case_number']}", 'Hearings', $caseId);
        } else {
            // OUTCOME 2: Unjustified / Willful Refusal / Ignored Notice
            if ($partyType === 'Complainant') {
                // Complainant Unjustified: Case dismissed and complainant barred from refiling or taking to court
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

                $clientMessage = "Complainant absence was ruled UNJUSTIFIED. Case is DISMISSED and complainant is BARRED from refiling or taking matter to court. KP Form 21 issued.";
                $hist = "Complainant absence unjustified ({$justificationCategory}). Case marked DISMISSED_BARRED. KP Form 21 issued (Doc #{$docBar['document_id']}). Locked from further mediation.";
                $this->logCaseHistory($caseId, 'DISMISSED_BARRED', $hist, $actorUserId);
                $this->audit->log($actorUserId, "Complainant barred from action for Case #{$hearing['case_number']}. Status set to DISMISSED_BARRED.", 'Hearings', $caseId);
            } else {
                // Section M Fix: Route to authorized human consequence determination instead of auto-generating CFA or defaulting
                $actionTaken = 'Respondent Default';
                $newStatus = $hearing['case_status'];

                // Flag the case for Authorized Consequence Review without auto-generating KP Form 20 (CFA)
                $clientMessage = "Respondent absence evaluated as UNJUSTIFIED. Flagged for consequence determination by authorized personnel (Punong Barangay / Lupon). Automatic CFA generation withheld.";
                $hist = "Respondent absence unjustified ({$justificationCategory}). Flagged for authorized consequence determination. Auto-generation of CFA suppressed per KP guidelines.";
                $this->logCaseHistory($caseId, $newStatus, $hist, $actorUserId);
                $this->audit->log($actorUserId, "Respondent unjustified absence recorded for Case #{$hearing['case_number']}. Flagged for consequence determination.", 'Hearings', $caseId);
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

        // Mark the Show Cause hearing as completed
        $compH = $this->conn->prepare("UPDATE hearings SET status = 'Completed' WHERE hearing_id = ?");
        $compH->execute([$hearingId]);

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
                    'venue' => $venue ?: ($hearing['venue'] ?? 'Tanggapan ng Lupong Tagapamayapa, Barangay Hall'),
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

        return (int) $this->conn->lastInsertId();
    }

    private function createRescheduledMediation(
        int $caseId,
        string $hearingDate,
        string $venue,
        int $rescheduledFromId,
        string $reason
    ): int {
        $stmt = $this->conn->prepare("
            INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, status, rescheduled_from_id, reschedule_reason)
            VALUES (?, 'Mediation', ?, ?, 'Scheduled', ?, ?)
        ");
        $stmt->execute([
            $caseId,
            $hearingDate,
            $venue,
            $rescheduledFromId,
            $reason,
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function createShowCauseDelivery(int $hearingId, string $partyType, string $formType, ?int $residentId): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO summon_deliveries (hearing_id, party_type, resident_id, form_type, delivery_status)
            VALUES (?, ?, ?, ?, 'Pending')
            ON DUPLICATE KEY UPDATE form_type = VALUES(form_type)
        ");
        $stmt->execute([
            $hearingId,
            $partyType,
            $residentId,
            $formType,
        ]);
    }

    private function syncHearingAttendanceTable(int $hearingId, int $complaintId, string $complainantAtt, string $respondentAtt, int $actorUserId): void
    {
        $parties = $this->getCaseParties($complaintId);

        $upsertAtt = $this->conn->prepare("
            INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, recorded_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE attendance_status = VALUES(attendance_status), recorded_by = VALUES(recorded_by)
        ");

        if (isset($parties['complainant']['resident_id'])) {
            $upsertAtt->execute([
                $hearingId,
                $parties['complainant']['resident_id'],
                $complainantAtt,
                $actorUserId,
            ]);
        }

        if (isset($parties['respondent']['resident_id'])) {
            $upsertAtt->execute([
                $hearingId,
                $parties['respondent']['resident_id'],
                $respondentAtt,
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
            $stmt = $this->conn->prepare("
                INSERT INTO case_history (case_id, status, remarks, updated_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$caseId, $status, $remarks, $userId]);
        } catch (Throwable $e) {
            error_log("Failed to log case history: " . $e->getMessage());
        }
    }
}
