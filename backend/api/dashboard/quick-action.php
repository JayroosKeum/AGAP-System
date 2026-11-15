<?php
/**
 * Lupon Clerk Dashboard Quick Actions API
 * Handles 1-click attendance marking and 1-click outcome logging
 * for both Paanyaya invitations and scheduled hearings.
 */

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Paanyaya.php';
require_once __DIR__ . '/../../models/Setting.php';
require_once __DIR__ . '/../../models/HearingAttendance.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/CsrfService.php';
require_once __DIR__ . '/../../services/PaanyayaPreparationService.php';
require_once __DIR__ . '/../../services/PaanyayaServiceDelivery.php';
require_once __DIR__ . '/../../services/ValidationService.php';

// Authentication & Authorization: Admin (1) and Lupon Clerk (2)
$userId = (int) ($_SESSION['user_id'] ?? 0);
$roleId = (int) ($_SESSION['role_id'] ?? 0);

if ($userId <= 0 || !in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Lupon Clerks and Administrators may perform quick dashboard actions.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = trim((string) ($payload['action'] ?? ''));

/**
 * Resolve and validate a prepared Paanyaya invitation shortcut payload.
 * Returns null if the complaint is a legacy (non-prepared) workflow.
 */
function resolvePreparedPaanyayaContext(PDO $db, array $payload): ?array
{
    $paanyayaId = (int) ($payload['paanyaya_id'] ?? 0);
    $complaintId = (int) ($payload['complaint_id'] ?? 0);
    $paanyayaRow = null;

    if ($paanyayaId > 0) {
        $pStmt = $db->prepare('SELECT * FROM paanyayas WHERE paanyaya_id = ?');
        $pStmt->execute([$paanyayaId]);
        $paanyayaRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$paanyayaRow) {
            throw new DomainException('Paanyaya invitation not found.');
        }
        $linkedComplaintId = (int) ($paanyayaRow['complaint_id'] ?: ($paanyayaRow['escalated_to_complaint_id'] ?? 0));
        if ($complaintId > 0 && $linkedComplaintId > 0 && $complaintId !== $linkedComplaintId) {
            throw new DomainException('Submitted complaint ID does not match this Paanyaya invitation.');
        }
        if ($complaintId <= 0) {
            $complaintId = $linkedComplaintId;
        }
    }

    if ($complaintId <= 0 || !PaanyayaPreparationService::managesDialogue($db, $complaintId)) {
        return null;
    }

    $submittedComplaintId = (int) ($payload['complaint_id'] ?? 0);
    if ($submittedComplaintId <= 0 || $submittedComplaintId !== $complaintId) {
        throw new InvalidArgumentException('Valid complaint_id matching the prepared Paanyaya is required.');
    }

    $invitationNumber = filter_var(
        $payload['invitation_number'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2]]
    );
    if ($invitationNumber === false) {
        throw new InvalidArgumentException('Valid invitation_number (1 or 2) is required for prepared Paanyaya invitations.');
    }
    $invitationNumber = (int) $invitationNumber;

    $submittedVersion = trim((string) ($payload['document_version'] ?? ($payload['prepared_document_version'] ?? '')));
    if ($submittedVersion === '') {
        throw new InvalidArgumentException('Current invitation document_version is required.');
    }

    $sessionId = filter_var($payload['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($sessionId === false) {
        throw new InvalidArgumentException('Valid session_id is required for prepared Paanyaya invitations.');
    }
    $sessionId = (int) $sessionId;

    $compStmt = $db->prepare('SELECT status FROM complaints WHERE complaint_id = ?');
    $compStmt->execute([$complaintId]);
    $compStatus = $compStmt->fetchColumn();
    if ($compStatus !== 'Pre-Docketed (Paanyaya)') {
        throw new DomainException('This complaint is no longer in Pre-Docketed (Paanyaya) dialogue.');
    }

    $caseCheck = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
    $caseCheck->execute([$complaintId]);
    if ($caseCheck->fetchColumn()) {
        throw new DomainException('This complaint has already been docketed into a formal case.');
    }

    $prepStmt = $db->prepare(
        'SELECT complaint_id, invitation_number, appointment_at, venue, ' . PaanyayaPreparationService::CURRENT_VERSION . ' AS current_version
         FROM paanyaya_preparations p
         WHERE p.complaint_id = ? AND p.invitation_number = ?'
    );
    $prepStmt->execute([$complaintId, $invitationNumber]);
    $prep = $prepStmt->fetch(PDO::FETCH_ASSOC);
    if (!$prep) {
        throw new DomainException('Matching prepared Paanyaya invitation was not found.');
    }

    $currentVersion = (string) ($prep['current_version'] ?? '');
    if ($currentVersion === '' || !hash_equals($currentVersion, $submittedVersion)) {
        throw new DomainException('This invitation version is stale. Reload the dashboard to load the current Paanyaya invitation.');
    }

    $sessStmt = $db->prepare('SELECT * FROM paanyaya_sessions WHERE session_id = ? AND complaint_id = ? AND attempt_number = ?');
    $sessStmt->execute([$sessionId, $complaintId, $invitationNumber]);
    $session = $sessStmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        throw new DomainException('Submitted session_id does not match this complaint and invitation number.');
    }

    if ($paanyayaRow && (int) ($paanyayaRow['attempt_number'] ?? $invitationNumber) !== $invitationNumber) {
        throw new DomainException('Submitted invitation_number does not match the selected Paanyaya register row.');
    }

    $latestDelivery = PaanyayaServiceDelivery::latestAttempt($db, $complaintId, $invitationNumber);
    if (!$latestDelivery || ($latestDelivery['outcome'] ?? null) !== 'Delivered'
        || !hash_equals((string) ($latestDelivery['document_version'] ?? ''), $currentVersion)) {
        throw new DomainException('Verified delivery of the current Paanyaya invitation is required before recording attendance or outcomes.');
    }

    return [
        'complaint_id' => $complaintId,
        'paanyaya_id' => $paanyayaId,
        'paanyaya' => $paanyayaRow,
        'invitation_number' => $invitationNumber,
        'document_version' => $currentVersion,
        'session_id' => $sessionId,
        'session' => $session,
        'preparation' => $prep,
        'delivery' => $latestDelivery,
        'dialogue_url' => '../complaints/complaint-details.php?id=' . $complaintId . '#paanyaya-dialogue-' . $invitationNumber,
    ];
}

try {
    $db = (new Database())->connect();
    $auditService = new AuditService();

    switch ($action) {
        // ── 1. Mark Hearing Attendance (1-click Present / Absent) ───────────────────
        case 'mark_hearing_attendance':
            CsrfService::enforceForApiRequest();
            $hearingId = (int) ($payload['hearing_id'] ?? 0);
            $status = trim((string) ($payload['status'] ?? 'Present'));
            if ($hearingId <= 0 || !in_array($status, ['Present', 'Absent'], true)) {
                throw new InvalidArgumentException('A valid hearing and attendance status are required.');
            }

            // Fetch hearing and case parties
            $db->beginTransaction();
            $hStmt = $db->prepare("SELECT h.*, c.complaint_id FROM hearings h INNER JOIN cases c ON c.case_id = h.case_id WHERE h.hearing_id = ? FOR UPDATE");
            $hStmt->execute([$hearingId]);
            $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hearing || in_array($hearing['status'], ['Cancelled', 'Office Cancelled', 'Rescheduled', 'Completed'], true)) {
                throw new InvalidArgumentException('Select an active hearing.');
            }

            require_once __DIR__ . '/../../services/SummonServiceGate.php';
            $gate = (new SummonServiceGate($db))->forHearing($hearingId);
            if (empty($gate['allowed'])) {
                $db->rollBack();
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'code' => $gate['code'] ?? 'SUMMONS_NOT_SERVED',
                    'message' => $gate['message'] ?? 'Serve notice and record proof of service before marking attendance.',
                ]);
                break;
            }

            $partiesStmt = $db->prepare("SELECT resident_id, party_type FROM complaint_parties WHERE complaint_id = ? AND party_type IN ('Complainant', 'Respondent')");
            $partiesStmt->execute([$hearing['complaint_id']]);
            $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($status === 'Absent') {
                $respondents = array_keys(array_column($parties, 'party_type'), 'Respondent', true);
                if (count($respondents) !== 1) {
                    $db->rollBack();
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Use Detailed Check-in to select which respondent was absent.',
                    ]);
                    break;
                }
                $recipient = (int) $parties[$respondents[0]]['resident_id'];
                $service = (new SummonDeliveryService($db))->verifyRecipientServiceForHearing($hearingId, $recipient, 'Respondent');
                if (empty($service['verified'])) {
                    $db->rollBack();
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => $service['next_action']]);
                    break;
                }
            }

            // Record attendance for each party
            $upsertStmt = $db->prepare("
                INSERT INTO hearing_attendance (hearing_id, resident_id, attendance_status, recorded_by, recorded_at, remarks)
                VALUES (?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    attendance_status = VALUES(attendance_status),
                    recorded_by = VALUES(recorded_by),
                    recorded_at = NOW(),
                    remarks = VALUES(remarks)
            ");

            foreach ($parties as $party) {
                // If status is Absent, typically respondent is absent; if Present, all are present
                $partyStatus = ($status === 'Absent' && $party['party_type'] === 'Respondent') ? 'Absent' : 'Present';
                $upsertStmt->execute([
                    $hearingId,
                    $party['resident_id'],
                    $partyStatus,
                    $userId,
                    "Marked via Clerk Quick Attendance: {$partyStatus}"
                ]);
            }

            // Update hearing status and party attendance columns
            $compAttended = 'Present';
            $respAttended = ($status === 'Absent') ? 'Absent' : 'Present';
            $updH = $db->prepare("
                UPDATE hearings 
                SET status = 'Attendance Recorded', 
                    queue_status = CASE WHEN queue_status IN ('Waiting', 'Ready') THEN ? ELSE queue_status END,
                    complainant_attendance = ?,
                    respondent_attendance = ?
                WHERE hearing_id = ?
            ");
            $updH->execute([$status === 'Present' ? 'Ready' : 'Waiting', $compAttended, $respAttended, $hearingId]);

            $db->commit();
            $auditService->log($userId, "1-Click Attendance Marked: Hearing #{$hearingId} ({$status})", "Hearings");

            echo json_encode([
                'success' => true,
                'message' => "Hearing attendance updated to {$status}."
            ]);
            break;

        // ── 2. Mark Paanyaya Attendance (1-click Present / Absent) ───────────────────
        case 'mark_paanyaya_attendance':
            $paanyayaId = (int) ($payload['paanyaya_id'] ?? 0);
            $status = trim((string) ($payload['status'] ?? 'Present'));
            if (!in_array($status, ['Present', 'Absent'], true)) {
                throw new InvalidArgumentException('Attendance status must be Present or Absent.');
            }

            $preparedCtx = resolvePreparedPaanyayaContext($db, $payload);
            if ($preparedCtx !== null) {
                $complaintId = $preparedCtx['complaint_id'];
                $invitationNumber = $preparedCtx['invitation_number'];
                $sessionId = $preparedCtx['session_id'];
                $compAttended = 'Present';
                $respAttended = ($status === 'Absent') ? 'Absent' : 'Present';

                $db->beginTransaction();
                $lockComp = $db->prepare('SELECT status FROM complaints WHERE complaint_id = ? FOR UPDATE');
                $lockComp->execute([$complaintId]);
                if ($lockComp->fetchColumn() !== 'Pre-Docketed (Paanyaya)') {
                    throw new DomainException('This complaint is no longer in Pre-Docketed (Paanyaya) dialogue.');
                }
                $latestCheck = PaanyayaServiceDelivery::latestAttempt($db, $complaintId, $invitationNumber);
                if (!$latestCheck || ($latestCheck['outcome'] ?? null) !== 'Delivered'
                    || !hash_equals((string) ($latestCheck['document_version'] ?? ''), $preparedCtx['document_version'])) {
                    throw new DomainException('Verified delivery of the current Paanyaya invitation is required before recording attendance.');
                }
                $stageType = $invitationNumber === 2 ? 'Paanyaya 2' : 'Paanyaya 1';
                $minCheck = $db->prepare('SELECT minutes_id FROM paanyaya_minutes WHERE complaint_id = ? AND stage_type = ? LIMIT 1');
                $minCheck->execute([$complaintId, $stageType]);
                if ($minCheck->fetchColumn() || (($preparedCtx['session']['queue_status'] ?? '') === 'Concluded')) {
                    throw new DomainException('Dialogue minutes have already been finalized for this invitation.');
                }

                if ($paanyayaId > 0) {
                    $db->prepare("
                        UPDATE paanyayas
                        SET complainant_attended = ?, invitee_attended = ?, attendance_recorded_at = NOW(), updated_at = NOW()
                        WHERE paanyaya_id = ?
                    ")->execute([$compAttended, $respAttended, $paanyayaId]);
                }

                $db->prepare("
                    UPDATE paanyaya_sessions
                    SET complainant_attended = ?, respondent_attended = ?, updated_at = NOW()
                    WHERE session_id = ? AND complaint_id = ? AND attempt_number = ?
                ")->execute([$compAttended, $respAttended, $sessionId, $complaintId, $invitationNumber]);

                $db->commit();
                $auditService->log($userId, "Prepared Paanyaya Attendance: Complaint #{$complaintId} Inv #{$invitationNumber} ({$respAttended})", "Paanyaya", $complaintId);

                echo json_encode([
                    'success' => true,
                    'message' => "Paanyaya attendance marked as {$respAttended}."
                ]);
                break;
            }

            if ($paanyayaId <= 0) {
                throw new InvalidArgumentException('Valid Paanyaya ID is required.');
            }

            // Get legacy paanyaya details
            $pStmt = $db->prepare("SELECT * FROM paanyayas WHERE paanyaya_id = ?");
            $pStmt->execute([$paanyayaId]);
            $paanyaya = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$paanyaya) {
                throw new RuntimeException('Paanyaya invitation not found.');
            }

            $complaintId = (int) ($paanyaya['complaint_id'] ?: ($paanyaya['escalated_to_complaint_id'] ?? 0));
            $compAttended = 'Present';
            $respAttended = ($status === 'Absent') ? 'Absent' : 'Present';

            $db->beginTransaction();
            $updP = $db->prepare("
                UPDATE paanyayas
                SET complainant_attended = ?, invitee_attended = ?, attendance_recorded_at = NOW(), updated_at = NOW()
                WHERE paanyaya_id = ?
            ");
            $updP->execute([$compAttended, $respAttended, $paanyayaId]);

            if ($complaintId > 0) {
                $sessStmt = $db->prepare("SELECT session_id FROM paanyaya_sessions WHERE complaint_id = ? AND scheduled_date = ? LIMIT 1");
                $sessStmt->execute([$complaintId, $paanyaya['scheduled_date']]);
                $sessionId = $sessStmt->fetchColumn();

                if ($sessionId) {
                    $updSession = $db->prepare("
                        UPDATE paanyaya_sessions
                        SET complainant_attended = ?, respondent_attended = ?, updated_at = NOW()
                        WHERE session_id = ?
                    ");
                    $updSession->execute([$compAttended, $respAttended, $sessionId]);
                } else {
                    $insSession = $db->prepare("
                        INSERT INTO paanyaya_sessions (complaint_id, attempt_number, scheduled_date, scheduled_time, venue, complainant_attended, respondent_attended, created_at)
                        VALUES (?, 1, ?, ?, ?, ?, ?, NOW())
                    ");
                    $insSession->execute([
                        $complaintId,
                        $paanyaya['scheduled_date'],
                        $paanyaya['scheduled_time'] ?? '14:00:00',
                        $paanyaya['venue'] ?? 'Tanggapan ng Barangay Tumana',
                        $compAttended,
                        $respAttended
                    ]);
                }
            }
            $db->commit();

            $auditService->log($userId, "1-Click Paanyaya Attendance: ID #{$paanyayaId} ({$respAttended})", "Paanyaya");

            echo json_encode([
                'success' => true,
                'message' => "Paanyaya attendance marked as {$respAttended}."
            ]);
            break;

        // ── 3. Log Paanyaya Outcome (1-click Settled / 2nd Paanyaya / Escalate) ───────
        case 'log_paanyaya_outcome':
            $paanyayaId = (int) ($payload['paanyaya_id'] ?? 0);
            $complaintId = (int) ($payload['complaint_id'] ?? 0);
            $outcomeRaw = trim((string) ($payload['outcome'] ?? ''));

            if ($complaintId <= 0 && $paanyayaId > 0) {
                $pStmt = $db->prepare("SELECT COALESCE(NULLIF(complaint_id, 0), escalated_to_complaint_id) FROM paanyayas WHERE paanyaya_id = ?");
                $pStmt->execute([$paanyayaId]);
                $complaintId = (int) $pStmt->fetchColumn();
            }

            // Map user-friendly outcome to workflow outcome
            $outcome = 'Settled';
            $paanyayaStatus = 'Settled';
            $notes = 'Logged via Clerk Dashboard 1-click outcome.';
            if (stripos($outcomeRaw, 'settle') !== false) {
                $outcome = 'Settled';
                $paanyayaStatus = 'Settled';
                $notes = 'Dispute settled amicably during informal dialogue.';
            } elseif (stripos($outcomeRaw, '2nd') !== false || stripos($outcomeRaw, 'retry') !== false) {
                $outcome = 'Absent_Retry';
                $paanyayaStatus = 'Scheduled';
                $notes = 'Party absent; 2nd Paanyaya invitation queued.';
            } elseif (stripos($outcomeRaw, 'escalate') !== false || stripos($outcomeRaw, 'mediation') !== false) {
                $outcome = 'Impasse_Escalate';
                $paanyayaStatus = 'Escalated';
                $notes = 'Parties unable to agree informally; escalated to formal mediation.';
            } else {
                throw new InvalidArgumentException('Unrecognized Paanyaya outcome action.');
            }

            $preparedCtx = resolvePreparedPaanyayaContext($db, $payload);
            if ($preparedCtx !== null) {
                $complaintId = $preparedCtx['complaint_id'];
                $invitationNumber = $preparedCtx['invitation_number'];
                $stageType = $invitationNumber === 2 ? 'Paanyaya 2' : 'Paanyaya 1';
                $dialogueUrl = $preparedCtx['dialogue_url'];
                $paanyayaModel = new Paanyaya($db);

                $existingMinutes = $paanyayaModel->getStageMinutes($complaintId, $stageType);
                if ($existingMinutes) {
                    if ($outcome === 'Impasse_Escalate' && ($existingMinutes['complainant_choice'] ?? '') === 'Escalate_Mediation') {
                        $escRes = $paanyayaModel->escalateToFormalDocketing($complaintId, $userId);
                        if (empty($escRes['success'])) {
                            http_response_code(422);
                            echo json_encode([
                                'success' => false,
                                'message' => $escRes['message'] ?? 'Unable to docket this Paanyaya dialogue.'
                            ]);
                            break;
                        }
                        echo json_encode([
                            'success' => true,
                            'message' => $escRes['message'] ?? 'Successfully docketed into Formal Mediation.',
                            'result' => $escRes
                        ]);
                        break;
                    }
                    throw new DomainException('Minutes have already been recorded for this dialogue.');
                }

                $discussionSummary = trim((string) ($payload['discussion_summary'] ?? ($payload['summary'] ?? '')));
                $agreedTerms = trim((string) ($payload['agreed_terms'] ?? ($payload['terms'] ?? '')));
                $rawDialogueDate = ValidationService::cleanText($payload['dialogue_date'] ?? null);
                $compAttended = trim((string) ($payload['complainant_attended'] ?? ''));
                $respAttended = trim((string) ($payload['respondent_attended'] ?? ''));

                $parsedDialogue = false;
                if (ValidationService::text($rawDialogueDate)) {
                    foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $format) {
                        if (ValidationService::dateTime($rawDialogueDate, $format)) {
                            $parsedDialogue = DateTimeImmutable::createFromFormat('!' . $format, $rawDialogueDate);
                            break;
                        }
                    }
                }
                $validDialogueDate = ($parsedDialogue instanceof DateTimeImmutable && $parsedDialogue <= new DateTimeImmutable());
                $validAttendance = in_array($compAttended, ['Present', 'Absent'], true)
                    && in_array($respAttended, ['Present', 'Absent'], true);

                if ($discussionSummary === ''
                    || ($outcome === 'Settled' && $agreedTerms === '')
                    || !$validDialogueDate
                    || !$validAttendance) {
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'requires_dialogue_form' => true,
                        'dialogue_url' => $dialogueUrl,
                        'message' => !$validDialogueDate
                            ? 'A valid actual dialogue date and time (not in the future) is required. Opening the Paanyaya dialogue form.'
                            : (!$validAttendance
                                ? 'Actual Complainant and Respondent attendance (Present or Absent) is required. Opening the Paanyaya dialogue form.'
                                : ($outcome === 'Settled'
                                    ? 'Agreed terms and dialogue minutes are required. Opening the Paanyaya dialogue form.'
                                    : 'Dialogue minutes are required for a prepared Paanyaya outcome. Opening the dialogue form.'))
                    ]);
                    break;
                }

                $minutesOutcome = match ($outcome) {
                    'Settled' => 'Settled',
                    'Absent_Retry' => 'Request_Paanyaya_2',
                    default => 'Escalate_Mediation',
                };
                $formattedDialogueDate = $parsedDialogue->format('Y-m-d\TH:i');

                $minutesData = [
                    'dialogue_date' => $formattedDialogueDate,
                    'complainant_attended' => $compAttended,
                    'respondent_attended' => $respAttended,
                    'outcome' => $minutesOutcome,
                    'complainant_choice' => $minutesOutcome === 'Settled' ? 'None' : $minutesOutcome,
                    'discussion_summary' => $discussionSummary,
                    'agreed_terms' => $agreedTerms,
                    'document_version' => $preparedCtx['document_version'],
                    'presiding_officer_ids' => $payload['presiding_officer_ids'] ?? [],
                ];

                $res = $paanyayaModel->recordMinutes($complaintId, $stageType, $minutesData, $userId);
                if (empty($res['success'])) {
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'requires_dialogue_form' => true,
                        'dialogue_url' => $dialogueUrl,
                        'message' => $res['message'] ?? 'Unable to save Paanyaya dialogue minutes.'
                    ]);
                    break;
                }

                echo json_encode([
                    'success' => true,
                    'message' => $res['message'] ?? "Successfully recorded outcome: {$outcomeRaw}",
                    'result' => $res
                ]);
                break;
            }

            $result = null;
            if ($complaintId > 0) {
                $paanyayaModel = new Paanyaya($db);
                $sessCheck = $paanyayaModel->getByComplaintId($complaintId);
                $sessionId = (int) ($sessCheck['session_id'] ?? 0);

                if ($sessionId <= 0) {
                    $newPaa = $paanyayaModel->schedule([
                        'complaint_id' => $complaintId,
                        'invitation_date' => date('Y-m-d'),
                        'invitation_time' => '14:00',
                        'venue' => Setting::getBarangayProfile($db)['default_venue'],
                    ], $userId);
                    if (empty($newPaa['success'])) {
                        http_response_code(422);
                        echo json_encode([
                            'success' => false,
                            'message' => $newPaa['message'] ?? 'Unable to initialize Paanyaya session.'
                        ]);
                        break;
                    }
                    $sessionId = (int) ($newPaa['session_id'] ?? 0);
                }

                $outcomeData = [
                    'outcome' => $outcome,
                    'outcome_notes' => $notes,
                    'complainant_attended' => 'Present',
                    'respondent_attended' => ($outcome === 'Absent_Retry' ? 'Absent' : 'Present'),
                    'mediation_date' => date('Y-m-d', strtotime('+3 days')),
                    'mediation_time' => '09:00',
                    'venue' => 'Lupon Court Room'
                ];

                $result = $paanyayaModel->recordOutcome($sessionId, $outcomeData, $userId);
                if (empty($result['success'])) {
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'message' => $result['message'] ?? 'Failed to record Paanyaya outcome.'
                    ]);
                    break;
                }
            }

            if ($paanyayaId > 0) {
                $updP = $db->prepare("
                    UPDATE paanyayas
                    SET status = ?, dialogue_notes = ?, updated_at = NOW()
                    WHERE paanyaya_id = ?
                ");
                $updP->execute([$paanyayaStatus, $notes, $paanyayaId]);
            }

            $refId = $complaintId > 0 ? "Complaint #{$complaintId}" : "Paanyaya #{$paanyayaId}";
            $auditService->log($userId, "1-Click Outcome Logged: {$refId} -> {$outcome}", "Paanyaya");

            echo json_encode([
                'success' => true,
                'message' => "Successfully recorded outcome: {$outcomeRaw}",
                'result' => $result
            ]);
            break;

        case 'batch_reschedule_pb_unavailable':
            require_once __DIR__ . '/../../controllers/HearingController.php';
            $hCtrl = new HearingController();
            $batchRes = $hCtrl->batchReschedulePbUnavailable($payload, $userId);
            if (!$batchRes['success']) {
                http_response_code(422);
            }
            echo json_encode($batchRes);
            break;

        default:
            throw new InvalidArgumentException("Unrecognized quick action: {$action}");
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
