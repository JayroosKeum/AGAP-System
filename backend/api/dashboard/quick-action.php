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
require_once __DIR__ . '/../../models/HearingAttendance.php';
require_once __DIR__ . '/../../services/AuditService.php';

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

try {
    $db = (new Database())->connect();
    $auditService = new AuditService();

    switch ($action) {
        // ── 1. Mark Hearing Attendance (1-click Present / Absent) ───────────────────
        case 'mark_hearing_attendance':
            $hearingId = (int) ($payload['hearing_id'] ?? 0);
            $status = trim((string) ($payload['status'] ?? 'Present'));
            if ($hearingId <= 0) {
                throw new InvalidArgumentException('Valid hearing ID is required.');
            }

            // Fetch hearing and case parties
            $hStmt = $db->prepare("SELECT h.*, c.complaint_id FROM hearings h INNER JOIN cases c ON c.case_id = h.case_id WHERE h.hearing_id = ?");
            $hStmt->execute([$hearingId]);
            $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hearing) {
                throw new RuntimeException('Hearing not found.');
            }

            $partiesStmt = $db->prepare("SELECT resident_id, party_type FROM complaint_parties WHERE complaint_id = ?");
            $partiesStmt->execute([$hearing['complaint_id']]);
            $parties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

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

            // Update hearing status
            $updH = $db->prepare("UPDATE hearings SET status = 'Attendance Recorded', queue_status = 'In Progress' WHERE hearing_id = ?");
            $updH->execute([$hearingId]);

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
            if ($paanyayaId <= 0) {
                throw new InvalidArgumentException('Valid Paanyaya ID is required.');
            }

            // Get paanyaya details
            $pStmt = $db->prepare("SELECT * FROM paanyayas WHERE paanyaya_id = ?");
            $pStmt->execute([$paanyayaId]);
            $paanyaya = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$paanyaya) {
                throw new RuntimeException('Paanyaya invitation not found.');
            }

            $complaintId = (int) $paanyaya['complaint_id'];
            $compAttended = 'Present';
            $respAttended = ($status === 'Absent') ? 'Absent' : 'Present';

            // Find or create session
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
                $pStmt = $db->prepare("SELECT complaint_id FROM paanyayas WHERE paanyaya_id = ?");
                $pStmt->execute([$paanyayaId]);
                $complaintId = (int) $pStmt->fetchColumn();
            }

            if ($complaintId <= 0) {
                throw new InvalidArgumentException('Valid complaint or Paanyaya ID is required.');
            }

            // Map user-friendly outcome to model outcome
            $outcome = 'Settled';
            $notes = 'Logged via Clerk Dashboard 1-click outcome.';
            if (stripos($outcomeRaw, 'settle') !== false) {
                $outcome = 'Settled';
                $notes = 'Dispute settled amicably during informal dialogue.';
            } elseif (stripos($outcomeRaw, '2nd') !== false || stripos($outcomeRaw, 'retry') !== false) {
                $outcome = 'Absent_Retry';
                $notes = 'Party absent; 2nd Paanyaya invitation queued.';
            } elseif (stripos($outcomeRaw, 'escalate') !== false || stripos($outcomeRaw, 'mediation') !== false) {
                $outcome = 'Impasse_Escalate';
                $notes = 'Parties unable to agree informally; escalated to formal mediation.';
            }

            $paanyayaModel = new Paanyaya();
            $sessCheck = $paanyayaModel->getByComplaintId($complaintId);
            $sessionId = (int) ($sessCheck['session_id'] ?? 0);

            if ($sessionId <= 0) {
                $newPaa = $paanyayaModel->schedule([
                    'complaint_id' => $complaintId,
                    'invitation_date' => date('Y-m-d'),
                    'invitation_time' => '14:00',
                    'venue' => 'Barangay Tumana',
                ], $userId);
                $sessionId = (int) ($newPaa['session_id'] ?? 0);
            }

            $outcomeData = [
                'outcome' => $outcome,
                'outcome_notes' => $notes,
                'complainant_attended' => ($outcome === 'Absent_Retry' ? 'Present' : 'Present'),
                'respondent_attended' => ($outcome === 'Absent_Retry' ? 'Absent' : 'Present'),
                'mediation_date' => date('Y-m-d', strtotime('+3 days')),
                'mediation_time' => '09:00',
                'venue' => 'Lupon Court Room'
            ];

            $result = $paanyayaModel->recordOutcome($sessionId, $outcomeData, $userId);

            $auditService->log($userId, "1-Click Outcome Logged: Complaint #{$complaintId} -> {$outcome}", "Paanyaya");

            echo json_encode([
                'success' => true,
                'message' => "Successfully recorded outcome: {$outcomeRaw}",
                'result' => $result
            ]);
            break;

        default:
            throw new InvalidArgumentException("Unrecognized quick action: {$action}");
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
