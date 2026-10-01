<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/NotificationService.php';

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

            // Parse and prepare mediation schedule parameters
            $medDate = !empty($mediationData['mediation_date']) ? trim((string)$mediationData['mediation_date']) : '';
            $medTime = !empty($mediationData['mediation_time']) ? trim((string)$mediationData['mediation_time']) : '';
            $venue = !empty($mediationData['venue']) ? trim((string)$mediationData['venue']) : 'Barangay Hall';
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

            // Check non-working day (weekends & holidays)
            $scheduledDateObj = new \DateTimeImmutable($medDate);
            if (MediationDeadlineService::isNonWorkingDay($scheduledDateObj)) {
                $this->conn->rollBack();
                $isWeekend = in_array((int)$scheduledDateObj->format('N'), [6, 7], true);
                return [
                    'success' => false,
                    'message' => $isWeekend
                        ? 'Mediation hearings can only be scheduled on weekdays (Monday to Friday).'
                        : 'The selected date is an official regular holiday / non-working day. Please select a regular working day.'
                ];
            }

            // Check 1-hour office hour slot (8:00 AM - 12:00 PM or 1:00 PM - 5:00 PM)
            $timeParts = explode(':', $medTime);
            $hours = (int) $timeParts[0];
            $minutes = (int) ($timeParts[1] ?? 0);
            $totalMinutes = $hours * 60 + $minutes;

            if ($totalMinutes >= 720 && $totalMinutes < 780) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'Mediation sessions cannot be scheduled during lunch break (12:00 PM – 1:00 PM).'
                ];
            }

            $isMorning = ($totalMinutes >= 480 && ($totalMinutes + 60) <= 720);
            $isAfternoon = ($totalMinutes >= 780 && ($totalMinutes + 60) <= 1020);
            if (!$isMorning && !$isAfternoon) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => '1-hour mediation sessions must be scheduled within office hours (8:00 AM – 12:00 PM or 1:00 PM – 5:00 PM).'
                ];
            }

            // Check daily 8-slot capacity limit
            $capacityStmt = $this->conn->prepare("
                SELECT COUNT(*) FROM hearings h
                INNER JOIN cases c ON c.case_id = h.case_id
                WHERE DATE(h.hearing_date) = ?
                  AND h.hearing_type = 'Mediation'
                  AND c.case_status NOT IN ('Dismissed', 'Settled')
            ");
            $capacityStmt->execute([$medDate]);
            if ((int) $capacityStmt->fetchColumn() >= 8) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'Daily capacity limit reached: All 8 mediation slots for this date are fully booked. Please select another date.'
                ];
            }

            // Enforce: mediation must be scheduled within 15 working days of complaint filing date
            if (!empty($complaint['created_at'])) {
                $filingDateStr = substr($complaint['created_at'], 0, 10);
                $filingDeadline = MediationDeadlineService::calculateDeadline($filingDateStr, 15);
                $scheduledDate = new \DateTime($medDate);
                $deadlineDate = new \DateTime($filingDeadline);
                if ($scheduledDate > $deadlineDate) {
                    $this->conn->rollBack();
                    return [
                        'success' => false,
                        'message' => sprintf(
                            'The mediation date must be within 15 working days of the complaint filing date. Deadline: %s.',
                            $deadlineDate->format('F j, Y')
                        ),
                    ];
                }
                if ($scheduledDate < new \DateTime('today')) {
                    $this->conn->rollBack();
                    return ['success' => false, 'message' => 'The mediation date cannot be in the past.'];
                }
            }

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
                $caseNumber = sprintf('KP-%s-%05d', date('Y'), $caseId);

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
                $cStart = date('g:i A', strtotime($conflict['hearing_date']));
                $cEnd = date('g:i A', strtotime($conflict['hearing_date'] . ' +60 minutes'));
                $cDate = date('M j, Y', strtotime($conflict['hearing_date']));
                $caseRef = !empty($conflict['case_number']) ? ' for Case ' . $conflict['case_number'] : '';
                return [
                    'success' => false,
                    'conflict' => true,
                    'message' => sprintf(
                        'Schedule conflict: A %s is already scheduled%s on %s from %s to %s (%s). Mediation sessions cannot overlap on the same day. Please select a non-overlapping time slot.',
                        $conflict['hearing_type'] ?? 'Mediation',
                        $caseRef,
                        $cDate,
                        $cStart,
                        $cEnd,
                        $conflict['venue'] ?? 'Barangay Hall'
                    )
                ];
            }

            if (!$hearingId) {
                $insertHearing = $this->conn->prepare(
                    "INSERT INTO hearings (case_id, hearing_type, hearing_date, venue, remarks)
                     VALUES (?, 'Mediation', ?, ?, ?)"
                );
                $insertHearing->execute([$caseId, $hearingDateTime, $venue, $remarks ?: '1st Mediation scheduled upon issuance of 1st Summons.']);
                $hearingId = (int) $this->conn->lastInsertId();
            } else {
                $updateHearing = $this->conn->prepare(
                    "UPDATE hearings SET hearing_date = ?, venue = ?, remarks = COALESCE(?, remarks) WHERE hearing_id = ?"
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
                $attemptCheck = $this->conn->prepare(
                    "SELECT 1 FROM proof_of_service WHERE document_id = ? LIMIT 1"
                );
                $attemptCheck->execute([$latestDocId]);
                if (!$attemptCheck->fetchColumn()) {
                    $this->conn->commit();
                    return [
                        'success' => false,
                        'message' => 'A service attempt must be recorded for Summons #' . $existingCount
                            . ' before a new summons can be issued. Please record the proof of service first.',
                    ];
                }
            }

            // 3. Cap at 3 summons per mediation/conciliation
            if ($existingCount >= 3) {
                $this->conn->commit();
                $latest = end($existingSummons);
                return [
                    'success' => true,
                    'already_issued' => true,
                    'case_id' => $caseId,
                    'case_number' => $caseNumber,
                    'document_id' => (int) $latest['document_id'],
                    'summons_number' => 3,
                    'message' => 'All 3 summons attempts have already been issued. No further summons can be issued.',
                ];
            }


            $summonsNum = $existingCount + 1;
            $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber);
            $fullDir = dirname(__DIR__, 2) . '/' . $relativeDir;
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0750, true);
            }
            $fileName = sprintf('KP-Form-9-Summons-%d-%s.pdf', $summonsNum, date('Ymd-His'));
            $filePath = $relativeDir . '/' . $fileName;

            // Insert generated document
            $docStmt = $this->conn->prepare(
                "INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status)
                 VALUES (?, ?, ?, ?, 'For Service')"
            );
            $docStmt->execute([$caseId, $templateId, $userId, $filePath]);
            $documentId = (int) $this->conn->lastInsertId();

            // Record in case history
            $histRemarks = sprintf('Summons #%d issued and 1st Mediation scheduled for %s at %s. Assigned for service.', $summonsNum, date('M j, Y g:i A', strtotime($hearingDateTime)), $venue);
            $histStmt = $this->conn->prepare(
                "INSERT INTO case_history (case_id, status, remarks, updated_by)
                 SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?"
            );
            $histStmt->execute([$histRemarks, $userId, $caseId]);

            $this->conn->commit();

            // Notify Summons Server users
            $this->notifySummonsServers($caseNumber, $summonsNum, $userId);

            return [
                'success' => true,
                'already_issued' => false,
                'case_id' => $caseId,
                'case_number' => $caseNumber,
                'document_id' => $documentId,
                'hearing_id' => (int) $hearingId,
                'summons_number' => $summonsNum,
                'message' => sprintf('Summons #%d issued and 1st Mediation scheduled for %s.', $summonsNum, date('M j, Y g:i A', strtotime($hearingDateTime))),
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
            $filePath = $relativeDir . '/' . sprintf('KP-Form-9-Summons-%d-%s.pdf', $number, date('Ymd-His'));
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
    public function findMediationConflict(
        string $hearingDateTime,
        string $venue,
        ?int $excludeCaseId = null,
        ?int $excludeHearingId = null
    ): ?array {
        $date = substr($hearingDateTime, 0, 10);

        $sql = "
            SELECT h.hearing_id, h.case_id, h.hearing_date, h.venue, h.hearing_type,
                   c.case_number, co.complaint_title
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE DATE(h.hearing_date) = ?
              AND c.case_status NOT IN ('Dismissed', 'Settled')
              AND (
                  -- Another mediation on the same day overlapping in time (60-minute session)
                  (h.hearing_type = 'Mediation' AND ABS(TIMESTAMPDIFF(MINUTE, h.hearing_date, ?)) < 60)
                  OR
                  -- Venue conflict at same date/time (60-minute session)
                  (LOWER(TRIM(h.venue)) = LOWER(TRIM(?)) AND ABS(TIMESTAMPDIFF(MINUTE, h.hearing_date, ?)) < 60)
              )
        ";
        $params = [$date, $hearingDateTime, $venue, $hearingDateTime];

        if ($excludeHearingId !== null && $excludeHearingId > 0) {
            $sql .= " AND h.hearing_id <> ?";
            $params[] = $excludeHearingId;
        } elseif ($excludeCaseId !== null && $excludeCaseId > 0) {
            $sql .= " AND h.case_id <> ?";
            $params[] = $excludeCaseId;
        }

        $sql .= " ORDER BY h.hearing_date ASC LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);

        return $conflict ?: null;
    }

    /**
     * Retrieves all scheduled mediations for a given date.
     */
    public function getScheduledMediationsForDate(string $date, ?int $excludeCaseId = null, ?int $excludeHearingId = null): array
    {
        $dateOnly = substr($date, 0, 10);
        $sql = "
            SELECT h.hearing_id, h.case_id, h.hearing_date, h.venue, h.hearing_type,
                   c.case_number, co.complaint_title
            FROM hearings h
            INNER JOIN cases c ON c.case_id = h.case_id
            LEFT JOIN complaints co ON co.complaint_id = c.complaint_id
            WHERE DATE(h.hearing_date) = ?
              AND h.hearing_type = 'Mediation'
              AND c.case_status NOT IN ('Dismissed', 'Settled')
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
            $ts = strtotime($row['hearing_date']);
            return [
                'hearing_id' => (int) $row['hearing_id'],
                'case_id' => (int) $row['case_id'],
                'case_number' => $row['case_number'],
                'complaint_title' => $row['complaint_title'],
                'hearing_date' => $row['hearing_date'],
                'start_time' => date('g:i A', $ts),
                'end_time' => date('g:i A', strtotime('+60 minutes', $ts)),
                'time_24' => date('H:i', $ts),
                'venue' => $row['venue']
            ];
        }, $rows);
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

            foreach ($servers as $serverId) {
                if ((int) $serverId !== $actorUserId) {
                    $insert = $this->conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
                    $insert->execute([(int) $serverId, $title, $message]);
                }
            }
        } catch (Throwable $e) {
            error_log('Error notifying summons servers: ' . $e->getMessage());
        }
    }
}
