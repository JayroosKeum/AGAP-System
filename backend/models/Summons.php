<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/CaseModel.php';

class Summons
{
    private PDO $conn;
    private NotificationService $notifications;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->notifications = new NotificationService();
    }

    /**
     * Issues a summons for a complaint / case.
     * If the complaint is not yet docketed, this atomically creates and dockets
     * the case first, ensuring compliance with the required sequence:
     * Complaint -> Case/Docket -> Issue Summon -> Serve Summon -> Proof of Service.
     */
    public function issueFirstSummon(int $complaintId, int $userId, array $mediationData = []): array
    {
        try {
            $this->conn->beginTransaction();

            $complaintStmt = $this->conn->prepare('SELECT complaint_id, complaint_number, complaint_title, status, case_type, created_at FROM complaints WHERE complaint_id = ? FOR UPDATE');
            $complaintStmt->execute([$complaintId]);
            $complaint = $complaintStmt->fetch(PDO::FETCH_ASSOC);

            if (!$complaint) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Complaint not found.'];
            }

            // Enforce Paanyaya prerequisite before allowing formal summons creation
            require_once __DIR__ . '/Paanyaya.php';
            $paaModel = new Paanyaya($this->conn);
            $prereq = $paaModel->checkSummonsPrerequisite($complaintId);
            if (!$prereq['allowed'] && empty($mediationData['override_paanyaya'])) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => $prereq['message'] ?? 'Complete the required Paanyaya process before issuing the first formal summons.'
                ];
            }

            // Parse and prepare mediation schedule parameters
            $medDate = !empty($mediationData['mediation_date']) ? trim((string)$mediationData['mediation_date']) : '';
            $medTime = !empty($mediationData['mediation_time']) ? trim((string)$mediationData['mediation_time']) : '';
            $venue = !empty($mediationData['venue']) ? trim((string)$mediationData['venue']) : 'Lupon Court Room';
            $remarks = !empty($mediationData['remarks']) ? trim((string)$mediationData['remarks']) : null;

            if ($medDate === '') {
                $medDate = date('Y-m-d', strtotime('+3 days'));
            }
            if ($medTime === '') {
                $medTime = '09:00';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $medDate) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $medTime)) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Please provide a valid 1st Mediation date and time.'];
            }

            require_once __DIR__ . '/../services/MediationDeadlineService.php';

            $dayOfWeek = (int) date('w', strtotime($medDate));
            $hour = (int) date('H', strtotime($medTime));
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Mediation hearings can only be scheduled on working days (Monday to Friday).'];
            }
            if (($hour < 8 || $hour >= 17) || ($hour >= 12 && $hour < 13)) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Mediation hearings must be during regular office hours (8:00 AM – 5:00 PM, excluding 12:00 PM – 1:00 PM lunch break).'];
            }
            if (MediationDeadlineService::isNonWorkingDay(new \DateTimeImmutable($medDate))) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Mediation hearings cannot be scheduled on non-working days or Philippine holidays.'];
            }

            // Check daily 8-slot capacity limit
            $capacityStmt = $this->conn->prepare("
                SELECT COUNT(*) FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                WHERE DATE(h.hearing_date) = ?
                  AND h.hearing_type = 'Mediation'
                  AND c.case_status NOT IN ('Dismissed', 'Settled', 'DISMISSED_BARRED', 'Archived', 'CFA Issued')
                  AND h.status NOT IN ('Cancelled', 'Office Cancelled')
            ");
            $capacityStmt->execute([$medDate]);
            if ((int) $capacityStmt->fetchColumn() >= 8) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'Daily capacity limit reached: All 8 mediation slots are fully booked for this date. Please select another date.'
                ];
            }

            // 15-working-day limit check removed

            $hearingDateTime = substr($medDate, 0, 10) . ' ' . substr($medTime, 0, 5) . ':00';

            // Check if case exists or needs to be created
            $caseStmt = $this->conn->prepare('SELECT case_id, case_number, case_status FROM cases WHERE complaint_id = ? FOR UPDATE');
            $caseStmt->execute([$complaintId]);
            $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                // Must locate active Administrator as automatic Head
                $adminStmt = $this->conn->prepare(
                    "SELECT u.user_id
                     FROM users u
                     INNER JOIN roles r ON r.role_id = u.role_id
                     WHERE u.status = 'Active' AND r.role_name = 'Administrator'
                     ORDER BY u.user_id ASC
                     LIMIT 1"
                );
                $adminStmt->execute();
                $administrator = $adminStmt->fetch(PDO::FETCH_ASSOC);
                if (!$administrator) {
                    $this->conn->rollBack();
                    return ['success' => false, 'message' => 'The active Administrator or Barangay Captain account could not be found.'];
                }

                $caseType = (!empty($complaint['case_type']) && in_array($complaint['case_type'], ['Civil', 'Criminal'], true))
                    ? $complaint['case_type']
                    : 'Civil';

                $insertCase = $this->conn->prepare("INSERT INTO cases (complaint_id, case_type, case_status, docket_date) VALUES (?, ?, 'Mediation', CURDATE())");
                $insertCase->execute([$complaintId, $caseType]);
                $caseId = (int) $this->conn->lastInsertId();
                $caseNumber = CaseModel::generateCaseNumber($this->conn, date('Y-m-d'), $caseId);

                $updateCaseNum = $this->conn->prepare('UPDATE cases SET case_number = ? WHERE case_id = ?');
                $updateCaseNum->execute([$caseNumber, $caseId]);

                $headAssignment = $this->conn->prepare("INSERT INTO case_assignments (case_id, member_id, assignment_role, assigned_date) VALUES (?, ?, 'Head', CURDATE())");
                $headAssignment->execute([$caseId, (int) $administrator['user_id']]);
            } else {
                $caseId = (int) $case['case_id'];
                $caseNumber = $case['case_number'];
            }

            // Automatically schedule the 1st Mediation hearing when issuing the summons
            $existingMediation = $this->conn->prepare("SELECT hearing_id FROM hearings WHERE case_id = ? AND hearing_type = 'Mediation' LIMIT 1");
            $existingMediation->execute([$caseId]);
            $hearingId = $existingMediation->fetchColumn();

            require_once __DIR__ . '/../services/MediationDeadlineService.php';
            $startDate = substr($hearingDateTime, 0, 10);
            $deadlineDate = MediationDeadlineService::calculateDeadline($startDate, 15);

            // Check for overlapping mediation on that day
            $conflict = $this->findMediationConflict(
                $hearingDateTime,
                $venue,
                $caseId,
                $hearingId ? (int)$hearingId : null
            );

            if ($conflict) {
                $this->conn->rollBack();
                $cDate = date('M j, Y', strtotime($conflict['hearing_date']));
                $cTime = date('g:i A', strtotime($conflict['hearing_date']));
                $caseRef = !empty($conflict['case_number']) ? ' for Case ' . $conflict['case_number'] : '';
                $msg = !empty($conflict['message'])
                    ? $conflict['message']
                    : sprintf(
                        'Schedule conflict: A %s is already scheduled%s on %s at %s (%s). Please select a valid non-conflicting time slot.',
                        $conflict['hearing_type'] ?? 'Mediation',
                        $caseRef,
                        $cDate,
                        $cTime,
                        $conflict['venue'] ?? 'Barangay Hall'
                    );
                return [
                    'success' => false,
                    'conflict' => true,
                    'message' => $msg
                ];
            }

            if (!$hearingId) {
                $insertHearing = $this->conn->prepare(
                    "INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, remarks, queue_status)
                     VALUES (?, 'Mediation', ?, ?, ?, 'Waiting')"
                );
                $insertHearing->execute([$caseId, $hearingDateTime, $venue, $remarks ?: '1st Mediation scheduled upon issuance of 1st Summons.']);
                $hearingId = (int) $this->conn->lastInsertId();
            } else {
                $updateHearing = $this->conn->prepare(
                    "UPDATE hearings SET hearing_date = ?, venue = ?, remarks = COALESCE(?, remarks), queue_status = COALESCE(queue_status, 'Waiting') WHERE hearing_id = ?"
                );
                $updateHearing->execute([$hearingDateTime, $venue, $remarks, $hearingId]);
            }

            // Set case status to Mediation and activate the 15-day mediation clock
            $updateCase = $this->conn->prepare(
                "UPDATE cases
                 SET case_status = 'Mediation',
                     mediation_start_date = COALESCE(mediation_start_date, ?),
                     mediation_deadline_date = ?,
                     is_paused = 0,
                     paused_at = NULL,
                     resumed_at = NULL,
                     pause_reason = NULL,
                     pause_notes = NULL
                 WHERE case_id = ?"
            );
            $updateCase->execute([$startDate, $deadlineDate, $caseId]);

            $updateComplaint = $this->conn->prepare("UPDATE complaints SET status = 'Mediation' WHERE complaint_id = ?");
            $updateComplaint->execute([$complaintId]);

            $deadline = $this->conn->prepare(
                "INSERT INTO case_deadlines (case_id, deadline_type, due_date, status)
                 VALUES (?, 'Mediation Period', ?, 'Pending')
                 ON DUPLICATE KEY UPDATE due_date = VALUES(due_date), status = IF(status = 'Completed', 'Completed', 'Pending')"
            );
            $deadline->execute([$caseId, $deadlineDate]);

            // Template ID for KP Form 8 (Notice of Hearing)
            $kp8TplStmt = $this->conn->prepare(
                "INSERT INTO document_templates (template_name, description)
                 VALUES ('KP Form 8', 'Notice of Hearing')
                 ON DUPLICATE KEY UPDATE description = VALUES(description), template_id = LAST_INSERT_ID(template_id)"
            );
            $kp8TplStmt->execute();
            $kp8TemplateId = (int) $this->conn->lastInsertId();
            if ($kp8TemplateId === 0) {
                $getKp8Tpl = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 8' LIMIT 1");
                $getKp8Tpl->execute();
                $kp8TemplateId = (int) $getKp8Tpl->fetchColumn();
            }

            // Template ID for KP Form 9 (Summons)
            $templateStmt = $this->conn->prepare(
                "INSERT INTO document_templates (template_name, description)
                 VALUES ('KP Form 9', 'Summons')
                 ON DUPLICATE KEY UPDATE description = VALUES(description), template_id = LAST_INSERT_ID(template_id)"
            );
            $templateStmt->execute();
            $templateId = (int) $this->conn->lastInsertId();
            if ($templateId === 0) {
                $getTemplate = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 9' LIMIT 1");
                $getTemplate->execute();
                $templateId = (int) $getTemplate->fetchColumn();
            }

            // Check existing summons documents
            $existingStmt = $this->conn->prepare(
                "SELECT gd.document_id, gd.service_status, gd.generated_at
                 FROM generated_documents gd
                 WHERE gd.case_id = ? AND gd.template_id = ?
                 ORDER BY gd.document_id ASC"
            );
            $existingStmt->execute([$caseId, $templateId]);
            $existingSummons = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
            $existingCount = count($existingSummons);

            // 1. Check if any summons was already successfully served
            $anyServedStmt = $this->conn->prepare("SELECT 1 FROM proof_of_service WHERE case_id = ? AND service_result = 'Served'");
            $anyServedStmt->execute([$caseId]);
            if ($anyServedStmt->fetchColumn()) {
                $this->conn->commit();
                $latest = !empty($existingSummons) ? end($existingSummons) : null;
                return [
                    'success' => true,
                    'already_issued' => true,
                    'case_id' => $caseId,
                    'case_number' => $caseNumber,
                    'document_id' => $latest ? (int) $latest['document_id'] : 0,
                    'summons_number' => $existingCount,
                    'message' => 'Summons has already been successfully served.',
                ];
            }

            // 2. Gate: before issuing the next summons, a service attempt must be recorded for the most recent one
            if ($existingCount >= 1) {
                $latestDoc = end($existingSummons);
                $latestDocId = (int) $latestDoc['document_id'];
                $attemptCheck = $this->conn->prepare("
                    SELECT 1 FROM proof_of_service pos
                    INNER JOIN generated_documents gd ON gd.document_id = pos.document_id
                    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
                    WHERE pos.document_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')
                    UNION
                    SELECT 1 FROM summon_deliveries sd
                    WHERE (sd.case_id = ? OR sd.hearing_id IN (SELECT hearing_id FROM hearings WHERE case_id = ?))
                      AND sd.party_type = 'Respondent'
                      AND (sd.document_id = ? OR sd.attempt_number = ?)
                      AND (
                          sd.delivery_status NOT IN ('Pending', 'Unissued')
                          OR sd.attempted_at IS NOT NULL
                          OR sd.served_at IS NOT NULL
                          OR (sd.unserved_reason IS NOT NULL AND TRIM(sd.unserved_reason) <> '')
                      )
                    LIMIT 1
                ");
                $attemptCheck->execute([$latestDocId, $caseId, $caseId, $latestDocId, $existingCount]);
                if (!$attemptCheck->fetchColumn()) {
                    $this->conn->commit();
                    $ordinals = ['', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th'];
                    $currOrd = $ordinals[$existingCount] ?? ($existingCount . 'th');
                    $nextOrd = $ordinals[$existingCount + 1] ?? (($existingCount + 1) . 'th');
                    return [
                        'success' => false,
                        'message' => "An attempt to deliver the {$currOrd} summons must first be made and recorded before issuing the {$nextOrd} attempt. Please log the Officer's Return in Proof of Service.",
                    ];
                }
            }

            // 3. Cap at 6 summons per mediation/conciliation
            if ($existingCount >= 6) {
                $this->conn->commit();
                $latest = end($existingSummons);
                return [
                    'success' => true,
                    'already_issued' => true,
                    'case_id' => $caseId,
                    'case_number' => $caseNumber,
                    'document_id' => (int) $latest['document_id'],
                    'summons_number' => 6,
                    'message' => 'All 6 summons attempts have already been issued. No further summons can be issued.',
                ];
            }

            $summonsNum = $existingCount + 1;
            $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber);
            $fullDir = dirname(__DIR__, 2) . '/' . $relativeDir;
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0750, true);
            }

            // Fetch parties for PDF generation and delivery initialization
            $partiesStmt = $this->conn->prepare("
                SELECT cp.party_type, cp.resident_id,
                       TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
                FROM complaint_parties cp
                INNER JOIN residents r ON r.resident_id = cp.resident_id
                WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
                ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent'), cp.party_id ASC
            ");
            $partiesStmt->execute([$complaintId]);
            $allParties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

            $complainants = [];
            $respondents = [];
            $primaryComplainant = null;
            $primaryRespondent = null;

            foreach ($allParties as $p) {
                if ($p['party_type'] === 'Complainant') {
                    $complainants[] = $p;
                    if ($primaryComplainant === null) {
                        $primaryComplainant = $p;
                    }
                } elseif ($p['party_type'] === 'Respondent') {
                    $respondents[] = $p;
                    if ($primaryRespondent === null) {
                        $primaryRespondent = $p;
                    }
                }
            }

            // Presiding official / Barangay Captain name from Authorized Signatory Profiles in system_settings
            $pbStmt = $this->conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'captain_name' LIMIT 1");
            $pbStmt->execute();
            $captainVal = trim((string) $pbStmt->fetchColumn());
            $officialName = (!empty($captainVal) && strcasecmp($captainVal, 'Admin User') !== 0) ? $captainVal : 'HON. AKIKO LYNNE CENTENO';

            require_once __DIR__ . '/../services/PDFService.php';
            $pdfService = new PDFService();
            $timestamp = date('Ymd-His');

            // 1. Generate KP Form 8 (Notice of Hearing for Complainant)
            $kp8FileName = sprintf('KP-Form-8-Notice-%s.pdf', $timestamp);
            $kp8FilePath = $relativeDir . '/' . $kp8FileName;
            $kp8FullPath = $fullDir . '/' . $kp8FileName;

            $kp8Data = [
                'case_number' => $caseNumber,
                'complaint_title' => $complaint['complaint_title'],
                'complainants' => $complainants,
                'respondents' => $respondents,
                'target_party_name' => $primaryComplainant['full_name'] ?? '',
                'venue' => $venue,
                'hearing_date' => $hearingDateTime,
                'notice_date' => date('Y-m-d'),
                'official_name' => $officialName,
            ];

            try {
                $pdfService->generateNotice('KP Form 8', $kp8Data, $kp8FullPath);
            } catch (Throwable $pe) {
                error_log('Error generating KP Form 8 PDF: ' . $pe->getMessage());
                @file_put_contents($kp8FullPath, '%PDF-1.4 KP Form 8 Notice of Hearing for ' . $caseNumber);
            }

            $docKp8Stmt = $this->conn->prepare(
                "INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status)
                 VALUES (?, ?, ?, ?, 'Served')"
            );
            $docKp8Stmt->execute([$caseId, $kp8TemplateId, $userId, $kp8FilePath]);
            $kp8DocumentId = (int) $this->conn->lastInsertId();

            // 2. Generate KP Form 9 (Summons for Respondent)
            $kp9FileName = sprintf('KP-Form-9-Summons-%d-%s.pdf', $summonsNum, $timestamp);
            $kp9FilePath = $relativeDir . '/' . $kp9FileName;
            $kp9FullPath = $fullDir . '/' . $kp9FileName;

            $kp9Data = [
                'case_number' => $caseNumber,
                'complaint_title' => $complaint['complaint_title'],
                'complainants' => $complainants,
                'respondents' => $respondents,
                'target_party_name' => $primaryRespondent['full_name'] ?? '',
                'venue' => $venue,
                'hearing_date' => $hearingDateTime,
                'notice_date' => date('Y-m-d'),
                'official_name' => $officialName,
            ];

            try {
                $pdfService->generateNotice('KP Form 9', $kp9Data, $kp9FullPath);
            } catch (Throwable $pe) {
                error_log('Error generating KP Form 9 PDF: ' . $pe->getMessage());
                @file_put_contents($kp9FullPath, '%PDF-1.4 KP Form 9 Summons for ' . $caseNumber);
            }

            $docKp9Stmt = $this->conn->prepare(
                "INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status)
                 VALUES (?, ?, ?, ?, 'For Service')"
            );
            $docKp9Stmt->execute([$caseId, $templateId, $userId, $kp9FilePath]);
            $documentId = (int) $this->conn->lastInsertId();

            // Initialize summon_deliveries: Complainant notice is automatically Served upon issuance of summons
            if ($primaryComplainant) {
                $compDelivStmt = $this->conn->prepare("
                    INSERT INTO summon_deliveries (
                        hearing_id, case_id, party_type, resident_id, form_type, delivery_status,
                        document_id, recipient_name, served_at, served_by, served_by_user_id,
                        service_method, remarks
                    )
                    VALUES (
                        ?, ?, 'Complainant', ?, 'Notice of Hearing', 'Served',
                        ?, ?, NOW(), ?, ?,
                        'Personal Notice', 'Personal notice served to complainant upon issuance of 1st mediation summons.'
                    )
                    ON DUPLICATE KEY UPDATE
                        delivery_status = 'Served',
                        served_at = COALESCE(served_at, NOW()),
                        served_by = COALESCE(served_by, VALUES(served_by)),
                        served_by_user_id = COALESCE(served_by_user_id, VALUES(served_by_user_id)),
                        service_method = 'Personal Notice',
                        remarks = 'Personal notice served to complainant upon issuance of 1st mediation summons.',
                        document_id = COALESCE(VALUES(document_id), document_id),
                        recipient_name = COALESCE(VALUES(recipient_name), recipient_name)
                ");
                $compDelivStmt->execute([
                    $hearingId,
                    $caseId,
                    (int) $primaryComplainant['resident_id'],
                    $kp8DocumentId,
                    $primaryComplainant['full_name'] ?? null,
                    $userId,
                    $userId
                ]);
            }

            if ($primaryRespondent) {
                $respDelivStmt = $this->conn->prepare("
                    INSERT INTO summon_deliveries (hearing_id, case_id, party_type, resident_id, form_type, delivery_status, document_id, recipient_name)
                    VALUES (?, ?, 'Respondent', ?, 'Summon', 'Pending', ?, ?)
                    ON DUPLICATE KEY UPDATE
                        resident_id = COALESCE(VALUES(resident_id), resident_id),
                        case_id = COALESCE(VALUES(case_id), case_id),
                        document_id = COALESCE(VALUES(document_id), document_id),
                        recipient_name = COALESCE(VALUES(recipient_name), recipient_name)
                ");
                $respDelivStmt->execute([
                    $hearingId,
                    $caseId,
                    (int) $primaryRespondent['resident_id'],
                    $documentId,
                    $primaryRespondent['full_name'] ?? null
                ]);
            }

            // Record in case history
            $histRemarks = sprintf(
                'Issued KP Form 8 (Notice of Hearing) and KP Form 9 (Summons #%d); 1st Mediation scheduled for %s at %s. Assigned for service.',
                $summonsNum,
                date('M j, Y g:i A', strtotime($hearingDateTime)),
                $venue
            );
            $histStmt = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks, updated_by)
                 SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?"
            );
            $histStmt->execute([$histRemarks, $userId, $caseId]);

            // Mark statutory Next-Working-Day Summons SLA as completed
            try {
                $slaCompStmt = $this->conn->prepare("
                    UPDATE case_deadlines
                    SET status = 'Completed', updated_at = CURRENT_TIMESTAMP
                    WHERE case_id = ? AND deadline_type = 'Next-Working-Day Summons SLA'
                ");
                $slaCompStmt->execute([$caseId]);
            } catch (Throwable $sE) {
                error_log('Failed to complete Next-Working-Day Summons SLA: ' . $sE->getMessage());
            }

            $this->conn->commit();

            // Notify Summons Server users
            $this->notifySummonsServers($caseNumber, $summonsNum, $userId);

            return [
                'success' => true,
                'already_issued' => false,
                'case_id' => $caseId,
                'case_number' => $caseNumber,
                'document_id' => $documentId,
                'kp8_document_id' => $kp8DocumentId,
                'kp9_document_id' => $documentId,
                'hearing_id' => (int) $hearingId,
                'summons_number' => $summonsNum,
                'message' => sprintf(
                    'KP Form 8 (Notice of Hearing) and KP Form 9 (Summons #%d) issued. 1st Mediation scheduled for %s.',
                    $summonsNum,
                    date('M j, Y g:i A', strtotime($hearingDateTime))
                ),
            ];
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Error issuing summons: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to issue summons. Please try again.'];
        }


    }

    public function getSummonsByCase(int $caseId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT gd.document_id, gd.case_id, gd.generated_at, gd.service_status, gd.file_path,
                    TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS issued_by_name,
                    dt.template_name
             FROM generated_documents gd
             INNER JOIN document_templates dt ON dt.template_id = gd.template_id
             LEFT JOIN users u ON u.user_id = gd.generated_by
             WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')
             ORDER BY gd.document_id ASC"
        );
        $stmt->execute([$caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Creates a fresh service copy after a recorded unjustified non-appearance. */
    public function issueFollowUpForNonAppearance(int $hearingId, int $userId): array
    {
        try {
            $this->conn->beginTransaction();
            $hearing = $this->conn->prepare("SELECT h.case_id, c.case_number, c.case_status, hn.nonappearance_id FROM hearings h INNER JOIN cases c ON c.case_id = h.case_id INNER JOIN hearing_nonappearances hn ON hn.hearing_id = h.hearing_id WHERE h.hearing_id = ? AND hn.resolution = 'Pending' FOR UPDATE");
            $hearing->execute([$hearingId]);
            $row = $hearing->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->conn->rollBack(); return ['success' => false, 'message' => 'Record an unresolved unjustified non-appearance before issuing another summons.']; }
            $template = $this->conn->prepare("INSERT INTO document_templates (template_name, description) VALUES ('KP Form 9', 'Summons') ON DUPLICATE KEY UPDATE template_id = LAST_INSERT_ID(template_id)");
            $template->execute();
            $templateId = (int) $this->conn->lastInsertId();
            if (!$templateId) { $find = $this->conn->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 9'"); $find->execute(); $templateId = (int) $find->fetchColumn(); }
            $count = $this->conn->prepare('SELECT COUNT(*) FROM generated_documents WHERE case_id = ? AND template_id = ?');
            $count->execute([$row['case_id'], $templateId]);
            $number = (int) $count->fetchColumn() + 1;
            $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $row['case_number']);
            $fullDir = dirname(__DIR__, 2) . '/' . $relativeDir;
            if (!is_dir($fullDir)) @mkdir($fullDir, 0750, true);
            $fileName = sprintf('KP-Form-9-Summons-%d-%s.pdf', $number, date('Ymd-His'));
            $filePath = $relativeDir . '/' . $fileName;
            $fullPath = $fullDir . '/' . $fileName;

            try {
                require_once __DIR__ . '/../services/PDFService.php';
                $pdfService = new PDFService();
                $partiesStmt = $this->conn->prepare("
                    SELECT cp.party_type, TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    INNER JOIN cases c ON c.complaint_id = cp.complaint_id
                    WHERE c.case_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
                ");
                $partiesStmt->execute([$row['case_id']]);
                $allParties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);
                $compList = array_values(array_filter($allParties, fn($p) => $p['party_type'] === 'Complainant'));
                $respList = array_values(array_filter($allParties, fn($p) => $p['party_type'] === 'Respondent'));
                $firstResp = reset($respList);

                $hDateStmt = $this->conn->prepare("SELECT hearing_date, venue FROM hearings WHERE hearing_id = ?");
                $hDateStmt->execute([$hearingId]);
                $hInfo = $hDateStmt->fetch(PDO::FETCH_ASSOC);

                $pdfService->generateNotice('KP Form 9', [
                    'case_number' => $row['case_number'],
                    'complaint_title' => 'Follow-up Summons',
                    'complainants' => $compList,
                    'respondents' => $respList,
                    'target_party_name' => $firstResp['full_name'] ?? '',
                    'venue' => $hInfo['venue'] ?? 'Lupon Court Room',
                    'hearing_date' => $hInfo['hearing_date'] ?? date('Y-m-d 09:00:00', strtotime('+3 days')),
                    'notice_date' => date('Y-m-d'),
                ], $fullPath);
            } catch (Throwable $pe) {
                error_log('Error generating follow-up KP Form 9 PDF: ' . $pe->getMessage());
                @file_put_contents($fullPath, '%PDF-1.4 Follow-up KP Form 9 Summons');
            }

            $document = $this->conn->prepare("INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status, regeneration_reason) VALUES (?, ?, ?, ?, 'For Service', ?)");
            $document->execute([$row['case_id'], $templateId, $userId, $filePath, 'Re-issued after recorded unjustified non-appearance at hearing #' . $hearingId . '.']);
            $documentId = (int) $this->conn->lastInsertId();
            $resolve = $this->conn->prepare("UPDATE hearing_nonappearances SET resolution = 'Re-summons Issued', resolved_by = ?, resolved_at = NOW() WHERE nonappearance_id = ?");
            $resolve->execute([$userId, $row['nonappearance_id']]);
            $history = $this->conn->prepare('INSERT INTO case_history (case_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)');
            $history->execute([$row['case_id'], $row['case_status'], 'Follow-up summons #' . $number . ' issued after unjustified non-appearance.', $userId]);
            $this->conn->commit();
            $this->notifySummonsServers($row['case_number'], $number, $userId);
            return ['success' => true, 'case_id' => (int) $row['case_id'], 'case_number' => $row['case_number'], 'document_id' => $documentId, 'summons_number' => $number, 'message' => 'Follow-up summons issued and assigned for service.'];
        } catch (Throwable $exception) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            error_log('Error issuing follow-up summons: ' . $exception->getMessage());
            return ['success' => false, 'message' => 'Unable to issue the follow-up summons.'];
        }
    }

    /**
     * Checks if a proposed mediation schedule overlaps with other scheduled mediations that day,
     * or conflicts with any hearing at the same venue.
     * Mediation sessions are treated as having a standard 60-minute duration.
     */
    /**
     * Checks if a proposed mediation schedule conflicts with the Lupon Court Room master operating schedule
     * or conflicts with an unauthorized booking in the single shared Lupon Court Room.
     * Elastic duration: mediation sessions are treated as having target call times on the Tuesday afternoon docket
     * without rigid 60-minute duration locks.
     */
    public function findMediationConflict(
        string $hearingDateTime,
        string $venue,
        ?int $excludeCaseId = null,
        ?int $excludeHearingId = null,
        string $hearingType = 'Mediation'
    ): ?array {
        $ts = strtotime($hearingDateTime);
        $date = date('Y-m-d', $ts);
        $dow = (int) date('w', $ts); // 0 = Sun, 6 = Sat
        $hour = (int) date('H', $ts);
        $minute = (int) date('i', $ts);
        $timeMinutes = $hour * 60 + $minute;

        require_once __DIR__ . '/Setting.php';
        $rawMedDays = Setting::getSetting('mediation_day_of_week', 'Mon,Tue,Wed,Thu,Fri');
        $allowedMedDays = [];
        if (is_string($rawMedDays)) {
            $dec = json_decode($rawMedDays, true);
            $allowedMedDays = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $rawMedDays)));
        } elseif (is_array($rawMedDays)) {
            $allowedMedDays = $rawMedDays;
        }
        if (empty($allowedMedDays)) {
            $allowedMedDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        }
        $pluralMap = ['Mon' => 'Mondays', 'Tue' => 'Tuesdays', 'Wed' => 'Wednesdays', 'Thu' => 'Thursdays', 'Fri' => 'Fridays', 'Sat' => 'Saturdays', 'Sun' => 'Sundays'];
        $medDaysLabel = implode(', ', array_map(fn($d) => $pluralMap[$d] ?? $d, $allowedMedDays));
        $curDayCode = date('D', $ts);
        $isMedDay = in_array($curDayCode, $allowedMedDays, true);

        // Weekend check
        if ($dow === 0) {
            return [
                'hearing_type' => 'Sunday Restriction',
                'hearing_date' => $hearingDateTime,
                'venue' => $venue,
                'case_number' => '',
                'message' => 'Hearings cannot be scheduled on Sundays.'
            ];
        }
        if ($dow === 6 && (!$isMedDay || $hearingType !== 'Mediation')) {
            return [
                'hearing_type' => 'Saturday Restriction',
                'hearing_date' => $hearingDateTime,
                'venue' => $venue,
                'case_number' => '',
                'message' => 'Hearings cannot be scheduled on Saturdays unless designated in Settings.'
            ];
        }

        // Mediation designated days check
        if ($hearingType === 'Mediation') {
            if (!$isMedDay) {
                return [
                    'hearing_type' => 'Mediation Day Restriction',
                    'hearing_date' => $hearingDateTime,
                    'venue' => $venue,
                    'case_number' => '',
                    'message' => "Mediation hearings can only be scheduled on designated mediation days ({$medDaysLabel})."
                ];
            }
        } elseif ($hearingType === 'Conciliation') {
            if ($isMedDay) {
                return [
                    'hearing_type' => 'Mediation Reserved Day Restriction',
                    'hearing_date' => $hearingDateTime,
                    'venue' => $venue,
                    'case_number' => '',
                    'message' => "{$curDayCode} is a designated Mediation Day reserved exclusively for Punong Barangay Mediation ({$medDaysLabel}). Conciliation hearings must be scheduled on non-mediation weekdays."
                ];
            }
        }

        // Lunch break check
        if ($hour === 12) {
            return [
                'hearing_type' => 'Lunch Break Restriction',
                'hearing_date' => $hearingDateTime,
                'venue' => $venue,
                'case_number' => '',
                'message' => 'Hearings cannot be scheduled during lunch break (12:00 PM – 1:00 PM).'
            ];
        }

        // Operating hours check
        $medHoursStart = Setting::getSetting('mediation_hours_start', '08:00');
        $medHoursEnd = Setting::getSetting('mediation_hours_end', '17:00');
        $workHoursStart = Setting::getSetting('working_hours_start', '08:00');
        $workHoursEnd = Setting::getSetting('working_hours_end', '17:00');
        $timeStr = date('H:i', $ts);

        if ($hearingType === 'Mediation') {
            if ($timeStr < $medHoursStart || $timeStr > $medHoursEnd) {
                $startFmt = date('g:i A', strtotime($medHoursStart));
                $endFmt = date('g:i A', strtotime($medHoursEnd));
                return [
                    'hearing_type' => 'Mediation Window Restriction',
                    'hearing_date' => $hearingDateTime,
                    'venue' => $venue,
                    'case_number' => '',
                    'message' => "Mediation hearings must be scheduled during the designated mediation session window ({$startFmt} – {$endFmt})."
                ];
            }
        } else {
            if ($timeStr < $workHoursStart || $timeStr > $workHoursEnd) {
                $startFmt = date('g:i A', strtotime($workHoursStart));
                $endFmt = date('g:i A', strtotime($workHoursEnd));
                return [
                    'hearing_type' => 'Working Hours Restriction',
                    'hearing_date' => $hearingDateTime,
                    'venue' => $venue,
                    'case_number' => '',
                    'message' => "Conciliation hearings must be scheduled during working hours ({$startFmt} – {$endFmt})."
                ];
            }
        }

        // Check for time overlap with any active hearing at the same venue on this date (Elastic Duration: 45-min buffer)
        $proposedStart = $hearingDateTime;
        $proposedEnd = date('Y-m-d H:i:s', strtotime($hearingDateTime . ' +45 minutes'));

        $overlapSql = "
            SELECT h.hearing_id, h.case_id, h.hearing_date, h.venue, h.hearing_type,
                   h.actual_start_time, h.actual_end_time, h.status, h.queue_status,
                   c.case_number, co.complaint_title
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE DATE(h.hearing_date) = ?
              AND LOWER(TRIM(h.venue)) = LOWER(TRIM(?))
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
              AND (
                  CASE 
                    WHEN (h.status = 'Completed' OR h.queue_status = 'Concluded') THEN
                      (h.actual_start_time IS NOT NULL AND h.actual_end_time IS NOT NULL AND h.actual_start_time < ? AND h.actual_end_time > ?)
                    WHEN h.queue_status = 'In_Session' AND h.actual_start_time IS NOT NULL THEN
                      (h.actual_start_time < ? AND DATE_ADD(h.actual_start_time, INTERVAL GREATEST(TIMESTAMPDIFF(MINUTE, h.actual_start_time, NOW()), COALESCE(h.duration_minutes, 45)) MINUTE) > ?)
                    ELSE
                      (h.hearing_date < ? AND DATE_ADD(h.hearing_date, INTERVAL COALESCE(h.duration_minutes, 45) MINUTE) > ?)
                  END
              )
        ";
        $overlapParams = [
            $date, $venue,
            $proposedEnd, $proposedStart,
            $proposedEnd, $proposedStart,
            $proposedEnd, $proposedStart
        ];

        if ($excludeHearingId !== null && $excludeHearingId > 0) {
            $overlapSql .= " AND h.hearing_id <> ?";
            $overlapParams[] = $excludeHearingId;
        }
        if ($excludeCaseId !== null && $excludeCaseId > 0) {
            $overlapSql .= " AND h.case_id <> ?";
            $overlapParams[] = $excludeCaseId;
        }

        $overlapSql .= " LIMIT 1";
        $overlapStmt = $this->conn->prepare($overlapSql);
        $overlapStmt->execute($overlapParams);
        $overlapConflict = $overlapStmt->fetch(PDO::FETCH_ASSOC);

        if ($overlapConflict) {
            $effectiveStart = (!empty($overlapConflict['actual_start_time']) && (in_array($overlapConflict['status'], ['Completed', 'Attendance Recorded'], true) || ($overlapConflict['queue_status'] ?? '') === 'Concluded'))
                ? $overlapConflict['actual_start_time']
                : $overlapConflict['hearing_date'];
            $cStart = date('g:i A', strtotime($effectiveStart));
            $effectiveEnd = !empty($overlapConflict['actual_end_time'])
                ? $overlapConflict['actual_end_time']
                : (($overlapConflict['queue_status'] === 'In_Session' && !empty($overlapConflict['actual_start_time']))
                    ? date('Y-m-d H:i:s', strtotime($overlapConflict['actual_start_time'] . ' +' . max(45, (int)ceil((time() - strtotime($overlapConflict['actual_start_time'])) / 60)) . ' minutes'))
                    : date('Y-m-d H:i:s', strtotime($effectiveStart . ' +45 minutes')));
            $cEnd = date('g:i A', strtotime($effectiveEnd));
            $cDate = date('M j, Y', strtotime($effectiveStart));
            $cCase = !empty($overlapConflict['case_number']) ? $overlapConflict['case_number'] : 'N/A';
            $statusLabel = (($overlapConflict['queue_status'] ?? '') === 'In_Session') ? 'currently in-session' : 'currently scheduled';
            $venueName = $overlapConflict['venue'] ?? $venue;
            return [
                'hearing_id' => $overlapConflict['hearing_id'],
                'case_id' => $overlapConflict['case_id'],
                'hearing_date' => $overlapConflict['hearing_date'],
                'venue' => $venueName,
                'hearing_type' => $overlapConflict['hearing_type'] ?? 'Mediation',
                'case_number' => $overlapConflict['case_number'] ?? '',
                'queue_status' => $overlapConflict['queue_status'] ?? '',
                'status' => $overlapConflict['status'] ?? '',
                'message' => sprintf(
                    '⚠️ Schedule Conflict: Case %s is %s in the %s from %s to %s.',
                    $cCase,
                    $statusLabel,
                    $venueName,
                    $cStart,
                    $cEnd
                )
            ];
        }

        // Check if the EXACT same case already has another active mediation hearing scheduled on the same date
        if ($excludeCaseId !== null && $excludeCaseId > 0) {
            $sameCaseSql = "
                SELECT h.hearing_id, h.case_id, h.hearing_date, h.venue, h.hearing_type,
                       c.case_number, co.complaint_title
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
                WHERE h.case_id = ?
                  AND DATE(h.hearing_date) = ?
                  AND h.hearing_type = ?
                  AND h.status NOT IN ('Cancelled', 'Office Cancelled')
            ";
            $params = [$excludeCaseId, $date, $hearingType];
            if ($excludeHearingId !== null && $excludeHearingId > 0) {
                $sameCaseSql .= " AND h.hearing_id <> ?";
                $params[] = $excludeHearingId;
            }
            $sameCaseSql .= " LIMIT 1";
            $stmt = $this->conn->prepare($sameCaseSql);
            $stmt->execute($params);
            $dupCase = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dupCase) {
                $cDate = date('M j, Y', strtotime($dupCase['hearing_date']));
                $cTime = date('g:i A', strtotime($dupCase['hearing_date']));
                return [
                    'hearing_id' => $dupCase['hearing_id'],
                    'case_id' => $dupCase['case_id'],
                    'hearing_date' => $dupCase['hearing_date'],
                    'venue' => $dupCase['venue'],
                    'hearing_type' => $dupCase['hearing_type'] ?? $hearingType,
                    'case_number' => $dupCase['case_number'] ?? '',
                    'message' => "This case already has an active {$dupCase['hearing_type']} hearing scheduled on this date ({$cDate} at {$cTime}). Please select another date."
                ];
            }

            // Also check if proposed date is on a prior date relative to the latest active hearing
            $priorCaseSql = "
                SELECT h.hearing_id, h.case_id, h.hearing_date, h.venue, h.hearing_type,
                       c.case_number, co.complaint_title
                FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
                WHERE h.case_id = ?
                  AND DATE(h.hearing_date) > ?
                  AND h.hearing_type = ?
                    AND h.status NOT IN ('Cancelled', 'Office Cancelled', 'Completed')
              ";
              $priorParams = [$excludeCaseId, $date, ($hearingType === 'Conciliation' ? 'Conciliation' : 'Mediation')];
            if ($excludeHearingId !== null && $excludeHearingId > 0) {
                $priorCaseSql .= " AND h.hearing_id <> ?";
                $priorParams[] = $excludeHearingId;
            }
            $priorCaseSql .= " ORDER BY h.hearing_date DESC LIMIT 1";
            $priorStmt = $this->conn->prepare($priorCaseSql);
            $priorStmt->execute($priorParams);
            $priorCase = $priorStmt->fetch(PDO::FETCH_ASSOC);
            if ($priorCase) {
                $pDate = date('M j, Y', strtotime($priorCase['hearing_date']));
                return [
                    'hearing_id' => $priorCase['hearing_id'],
                    'case_id' => $priorCase['case_id'],
                    'hearing_date' => $priorCase['hearing_date'],
                    'venue' => $priorCase['venue'],
                    'hearing_type' => 'Mediation',
                    'case_number' => $priorCase['case_number'] ?? '',
                    'message' => "A hearing for this case is already scheduled on {$pDate}. Subsequent hearings must be scheduled on a date after {$pDate}."
                ];
            }
        }

        return null;
    }

    /**
     * Retrieves all scheduled mediations for a given date.
     */
    public function getScheduledHearingsForDate(string $date, string $hearingType = 'Mediation', ?int $excludeCaseId = null, ?int $excludeHearingId = null): array
    {
        $dateOnly = substr($date, 0, 10);
        $typeCondition = ($hearingType === 'Conciliation')
            ? "h.hearing_type IN ('Conciliation', 'Mediation')"
            : "h.hearing_type = 'Mediation'";
        $sql = "
            SELECT h.hearing_id, h.case_id, h.hearing_date, h.venue, h.hearing_type,
                   h.status, h.queue_status, h.duration_minutes, h.actual_start_time, h.actual_end_time,
                   c.case_number, co.complaint_title
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE DATE(h.hearing_date) = ?
              AND {$typeCondition}
              AND c.case_status NOT IN ('Dismissed', 'Settled', 'DISMISSED_BARRED', 'Archived', 'CFA Issued')
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
        ";
        $params = [$dateOnly];

        if ($excludeHearingId !== null && $excludeHearingId > 0) {
            $sql .= " AND h.hearing_id <> ?";
            $params[] = $excludeHearingId;
        } elseif ($excludeCaseId !== null && $excludeCaseId > 0) {
            $sql .= " AND h.case_id <> ?";
            $params[] = $excludeCaseId;
        }

        $sql .= " ORDER BY h.hearing_date ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $effectiveStart = (!empty($row['actual_start_time']) && (in_array($row['status'], ['Completed', 'Attendance Recorded'], true) || ($row['queue_status'] ?? '') === 'Concluded'))
                ? $row['actual_start_time']
                : $row['hearing_date'];
            $ts = strtotime($effectiveStart);
            $dur = (int) ($row['duration_minutes'] ?: 45);
            $effectiveEnd = !empty($row['actual_end_time'])
                ? $row['actual_end_time']
                : date('Y-m-d H:i:s', strtotime($effectiveStart . " +{$dur} minutes"));

            return [
                'hearing_id' => (int) $row['hearing_id'],
                'case_id' => (int) $row['case_id'],
                'case_number' => $row['case_number'],
                'complaint_title' => $row['complaint_title'],
                'hearing_date' => $row['hearing_date'],
                'start_time' => date('g:i A', $ts),
                'end_time' => date('g:i A', strtotime($effectiveEnd)),
                'time_24' => date('H:i', $ts),
                'venue' => $row['venue'],
                'status' => $row['status'],
                'queue_status' => $row['queue_status'] ?: 'Waiting',
                'actual_start_time' => $row['actual_start_time'],
                'actual_end_time' => $row['actual_end_time']
            ];
        }, $rows);
    }

    /**
     * Retrieves daily counts of scheduled mediation hearings within a date range.
     * Returns an associative array: ['YYYY-MM-DD' => count, ...]
     */
    public function getDailyHearingCountsInRange(string $startDate, string $endDate, ?int $excludeCaseId = null, string $hearingType = 'Mediation'): array
    {
        $typeCondition = ($hearingType === 'Conciliation')
            ? "h.hearing_type IN ('Conciliation', 'Mediation')"
            : "h.hearing_type = 'Mediation'";

        $sql = "
            SELECT DATE(h.hearing_date) AS h_date, COUNT(*) AS booked_count
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            WHERE DATE(h.hearing_date) BETWEEN ? AND ?
              AND {$typeCondition}
              AND h.status NOT IN ('Cancelled', 'Office Cancelled')
              AND c.case_status NOT IN ('Dismissed', 'Settled', 'DISMISSED_BARRED', 'Archived', 'CFA Issued')
        ";
        $params = [substr($startDate, 0, 10), substr($endDate, 0, 10)];

        if ($excludeCaseId !== null && $excludeCaseId > 0) {
            $sql .= " AND h.case_id <> ?";
            $params[] = $excludeCaseId;
        }

        $sql .= " GROUP BY DATE(h.hearing_date)";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[$row['h_date']] = (int) $row['booked_count'];
        }
        return $results;
    }

    public function getDailyMediationCountsInRange(string $startDate, string $endDate, ?int $excludeCaseId = null): array
    {
        return $this->getDailyHearingCountsInRange($startDate, $endDate, $excludeCaseId, 'Mediation');
    }

    private function notifySummonsServers(string $caseNumber, int $summonsNum, int $actorUserId): void
    {
        try {
            $stmt = $this->conn->prepare(
                "SELECT u.user_id
                 FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE u.status = 'Active' AND r.role_name = 'Summons Server'"
            );
            $stmt->execute();
            $servers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $title = sprintf('Summons #%d Issued', $summonsNum);
            $message = sprintf('Summons #%d for case %s has been issued and is ready for service.', $summonsNum, $caseNumber);

            require_once __DIR__ . '/../services/NotificationService.php';
            $notif = new NotificationService();
            $cIdStmt = $this->conn->prepare("SELECT case_id FROM cases WHERE case_number = ? LIMIT 1");
            $cIdStmt->execute([$caseNumber]);
            $cId = (int) $cIdStmt->fetchColumn() ?: 0;

            foreach ($servers as $serverId) {
                if ((int) $serverId !== $actorUserId) {
                    $notif->notifySummonsDispatched($cId, $caseNumber, (int)$serverId, 'Respondent', $actorUserId);
                }
            }
        } catch (Throwable $e) {
            error_log('Error notifying summons servers: ' . $e->getMessage());
        }
    }

    public function getScheduledMediationsForDate(string $date, ?int $excludeCaseId = null, ?int $excludeHearingId = null): array
    {
        return $this->getScheduledHearingsForDate($date, 'Mediation', $excludeCaseId, $excludeHearingId);
    }
}
