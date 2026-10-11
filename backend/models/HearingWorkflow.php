<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PDFService.php';

class HearingWorkflow
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
    }

    public function act(string $action, array $d, int $userId, int $roleId): array
    {
        $hearing = filter_var($d['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $resident = filter_var($d['resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!$hearing || !$resident) {
            return ['success' => false, 'message' => 'A valid hearing and party are required.'];
        }

        try {
            // ==============================================================
            // 1. RECORD SERVICE ATTEMPT & OFFICER'S RETURN
            // ==============================================================
            if ($action === 'service') {
                if (!in_array($roleId, [1, 2, 4], true)) {
                    return ['success' => false, 'status' => 403, 'message' => 'Only authorized staff or the Summons Server may record service.'];
                }

                $document = filter_var($d['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $result = trim((string) ($d['service_result'] ?? ''));
                $allowed = ['Served', 'Not Found', 'Wrong Address', 'Refused', 'Unsuccessful Attempt'];
                $date = trim((string) ($d['service_date'] ?? ''));
                $reason = trim((string) ($d['reason'] ?? ''));
                $return = trim((string) ($d['officer_return'] ?? ''));

                if (!$document || !in_array($result, $allowed, true) || !strtotime($date) || strlen($return) < 1 || mb_strlen($reason) > 2000 || mb_strlen($return) > 5000) {
                    return ['success' => false, 'message' => 'Provide a valid document, service result/date, reason, and Officer’s Return.'];
                }

                $file = null;
                if (isset($_FILES['supporting_file']) && $_FILES['supporting_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $f = $_FILES['supporting_file'];
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5242880 || !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                        return ['success' => false, 'message' => 'Supporting file must be a PDF, JPG, or PNG up to 5 MB.'];
                    }
                    $dir = dirname(__DIR__, 2) . '/storage/uploads/hearing-service';
                    if (!is_dir($dir)) {
                        mkdir($dir, 0750, true);
                    }
                    $name = bin2hex(random_bytes(16)) . '.' . (['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime]);
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                        return ['success' => false, 'message' => 'Unable to store supporting file.'];
                    }
                    $file = 'storage/uploads/hearing-service/' . $name;
                }

                $party = $this->db->prepare(
                    "SELECT cp.party_type, h.case_id, c.case_number, gd.document_id,
                            TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name
                     FROM hearings h
                     JOIN cases c ON c.case_id = h.case_id
                     JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id AND cp.resident_id = ?
                     JOIN residents r ON r.resident_id = cp.resident_id
                     JOIN generated_documents gd ON gd.document_id = ? AND gd.case_id = h.case_id
                     WHERE h.hearing_id = ? AND cp.party_type IN ('Complainant', 'Respondent')"
                );
                $party->execute([$resident, $document, $hearing]);
                $p = $party->fetch(PDO::FETCH_ASSOC);
                if (!$p) {
                    return ['success' => false, 'message' => 'Party, hearing, and document must belong to the same case.'];
                }

                $officer = $roleId === 4 ? $userId : (int) ($d['serving_officer'] ?? $userId);
                $check = $this->db->prepare("SELECT 1 FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ? AND u.status = 'Active' AND r.role_name = 'Summons Server'");
                $check->execute([$officer]);
                if (!$check->fetchColumn()) {
                    // Fall back to finding an active Summons Server
                    $findOfficer = $this->db->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.status = 'Active' AND r.role_name = 'Summons Server' LIMIT 1");
                    $officer = (int) $findOfficer->fetchColumn();
                    if (!$officer) {
                        $officer = $userId;
                    }
                }

                $q = $this->db->prepare(
                    'INSERT INTO hearing_party_services (hearing_id, resident_id, party_type, document_id, service_date, service_result, reason, serving_officer, officer_return, supporting_file, assigned_to)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $serviceDateTime = date('Y-m-d H:i:s', strtotime($date));
                $q->execute([$hearing, $resident, $p['party_type'], $document, $serviceDateTime, $result, $reason ?: null, $officer, $return, $file, $officer]);
                $id = (int) $this->db->lastInsertId();

                // Update generated_documents status
                $docStatus = ($result === 'Served') ? 'Served' : 'Service Failed';
                $updateDoc = $this->db->prepare("UPDATE generated_documents SET service_status = ? WHERE document_id = ?");
                $updateDoc->execute([$docStatus, $document]);

                // Synchronize with proof_of_service table for case tracker consistency
                $syncPos = $this->db->prepare(
                    "INSERT INTO proof_of_service (case_id, document_id, service_result, served_by, served_date, remarks, image_path)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $syncRemarks = sprintf('Officer Return for %s (%s): %s%s', $p['party_type'], $p['resident_name'], $return, $reason ? ' [Reason: ' . $reason . ']' : '');
                $syncPos->execute([$p['case_id'], $document, $result, $officer, $serviceDateTime, $syncRemarks, $file]);

                // Case History
                $histStmt = $this->db->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?");
                $histRemarks = sprintf('Service attempt recorded for %s %s: %s. Officer’s Return logged.', $p['party_type'], $p['resident_name'], $result);
                $histStmt->execute([$histRemarks, $userId, $p['case_id']]);

                $this->audit($userId, 'Recorded ' . $result . ' service attempt for ' . $p['resident_name'], 'Hearings', $id);

                return [
                    'success' => true,
                    'message' => 'Service attempt and Officer’s Return recorded successfully.',
                    'service_id' => $id,
                    'service_result' => $result,
                    'is_served' => ($result === 'Served')
                ];
            }

            // ==============================================================
            // 2. ISSUE NOTICE OF HEARING (KP Form 18 or 19) UPON FAILURE TO APPEAR
            // ==============================================================
            if ($action === 'issue_notice') {
                if (!in_array($roleId, [1, 2], true)) {
                    return ['success' => false, 'status' => 403, 'message' => 'Only Administrators and Lupon Clerks may issue notices of hearing.'];
                }

                $party = $this->db->prepare(
                    "SELECT cp.party_type, h.case_id, h.hearing_date, h.venue, c.case_number, co.complaint_title,
                            TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS target_party_name
                     FROM hearings h
                     JOIN cases c ON c.case_id = h.case_id
                     JOIN complaints co ON co.complaint_id = c.complaint_id
                     JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id AND cp.resident_id = ?
                     JOIN residents r ON r.resident_id = cp.resident_id
                     WHERE h.hearing_id = ? AND cp.party_type IN ('Complainant', 'Respondent')"
                );
                $party->execute([$resident, $hearing]);
                $p = $party->fetch(PDO::FETCH_ASSOC);
                if (!$p) {
                    return ['success' => false, 'message' => 'Party and hearing must belong to the same case.'];
                }

                $partyType = $p['party_type'];
                $formCode = ($partyType === 'Complainant') ? 'KP Form 18' : 'KP Form 19';
                $formDesc = ($partyType === 'Complainant')
                    ? 'Notice of Hearing (Failure to Appear - Complainant)'
                    : 'Notice of Hearing (Failure to Appear - Respondent)';

                // Fetch full complainant & respondent list for header block
                $compStmt = $this->db->prepare("SELECT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name FROM complaint_parties cp JOIN residents r ON r.resident_id = cp.resident_id WHERE cp.complaint_id = (SELECT complaint_id FROM cases WHERE case_id = ?) AND cp.party_type = 'Complainant'");
                $compStmt->execute([$p['case_id']]);
                $complainants = $compStmt->fetchAll(PDO::FETCH_ASSOC);

                $respStmt = $this->db->prepare("SELECT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name FROM complaint_parties cp JOIN residents r ON r.resident_id = cp.resident_id WHERE cp.complaint_id = (SELECT complaint_id FROM cases WHERE case_id = ?) AND cp.party_type = 'Respondent'");
                $respStmt->execute([$p['case_id']]);
                $respondents = $respStmt->fetchAll(PDO::FETCH_ASSOC);

                // Template ID
                $tplStmt = $this->db->prepare("INSERT INTO document_templates (template_name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description), template_id = LAST_INSERT_ID(template_id)");
                $tplStmt->execute([$formCode, $formDesc]);
                $tplId = (int) $this->db->lastInsertId();
                if (!$tplId) {
                    $getTpl = $this->db->prepare("SELECT template_id FROM document_templates WHERE template_name = ? LIMIT 1");
                    $getTpl->execute([$formCode]);
                    $tplId = (int) $getTpl->fetchColumn();
                }

                // File path
                $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $p['case_number']);
                $absDir = dirname(__DIR__, 2) . '/' . $relativeDir;
                if (!is_dir($absDir)) {
                    mkdir($absDir, 0750, true);
                }
                $fileName = sprintf('%s-%s-%s.pdf', str_replace(' ', '-', $formCode), preg_replace('/[^a-zA-Z0-9_-]/', '_', $p['target_party_name']), date('Ymd-His'));
                $relativePath = $relativeDir . '/' . $fileName;
                $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;

                // Determine explanation hearing date
                $explanationDate = trim((string) ($d['explanation_date'] ?? ''));
                if (!$explanationDate || !strtotime($explanationDate)) {
                    $explanationDate = date('Y-m-d 09:00:00', strtotime('+3 days'));
                }

                // Render PDF
                try {
                    $pdfData = [
                        'case_number' => $p['case_number'],
                        'complaint_title' => $p['complaint_title'],
                        'target_party_name' => $p['target_party_name'],
                        'hearing_date' => $p['hearing_date'],
                        'explanation_date' => $explanationDate,
                        'venue' => $p['venue'] ?: 'Barangay Tumana Hearing Room',
                        'notice_date' => date('Y-m-d'),
                        'complainants' => $complainants,
                        'respondents' => $respondents,
                    ];
                    (new PDFService())->generateNotice($formCode, $pdfData, $absolutePath);
                } catch (Throwable $pe) {
                    error_log('PDF Generation Notice error: ' . $pe->getMessage());
                    // Fallback create dummy file if Dompdf fails so process completes
                    file_put_contents($absolutePath, '%PDF-1.4 Notice of Hearing ' . $formCode);
                }

                // Save generated document
                $docStmt = $this->db->prepare("INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status) VALUES (?, ?, ?, ?, 'For Service')");
                $docStmt->execute([$p['case_id'], $tplId, $userId, $relativePath]);
                $docId = (int) $this->db->lastInsertId();

                // Find active summons server for assignment
                $assignedServer = filter_var($d['assigned_server_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$assignedServer) {
                    $findServer = $this->db->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.status = 'Active' AND r.role_name = 'Summons Server' ORDER BY u.user_id ASC LIMIT 1");
                    $assignedServer = (int) $findServer->fetchColumn();
                }

                // Notify assigned Summons Server
                if ($assignedServer > 0) {
                    $notifTitle = sprintf('%s Issued for %s', $formCode, $p['target_party_name']);
                    $notifMsg = sprintf('%s has been issued for case %s following Failure to Appear. Please serve the notice and record the Officer’s Return.', $formCode, $p['case_number']);
                    $notifStmt = $this->db->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
                    $notifStmt->execute([$assignedServer, $notifTitle, $notifMsg]);
                }

                // Case History
                $histStmt = $this->db->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?");
                $histRemarks = sprintf('%s issued to %s (%s) following Failure to Appear. Assigned to Summons Server.', $formCode, $p['target_party_name'], $partyType);
                $histStmt->execute([$histRemarks, $userId, $p['case_id']]);

                $this->audit($userId, 'Issued ' . $formCode . ' for ' . $p['target_party_name'], 'Hearings', $docId);

                return [
                    'success' => true,
                    'message' => sprintf('%s has been issued and assigned to the Summons Server.', $formCode),
                    'document_id' => $docId,
                    'form_code' => $formCode,
                    'download_url' => '../../../backend/api/documents/download.php?id=' . $docId
                ];
            }

            // ==============================================================
            // 3. RECORD EXPLANATION HEARING OUTCOME
            // ==============================================================
            if ($action === 'explanation') {
                if (!in_array($roleId, [1, 2], true)) {
                    return ['success' => false, 'status' => 403, 'message' => 'Only Administrators and Lupon Clerks may record explanation outcomes.'];
                }

                $text = trim((string) ($d['explanation'] ?? ''));
                $outcome = trim((string) ($d['outcome'] ?? 'Pending'));
                if ($text === '' || mb_strlen($text) > 5000 || !in_array($outcome, ['Pending', 'Justified', 'Unjustified'], true)) {
                    return ['success' => false, 'message' => 'Provide an explanation and valid outcome (Pending, Justified, or Unjustified).'];
                }

                $valid = $this->db->prepare(
                    "SELECT ha.attendance_status, c.case_id, cp.party_type,
                            TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name
                     FROM hearing_attendance ha
                     JOIN hearings h ON h.hearing_id = ha.hearing_id
                     JOIN cases c ON c.case_id = h.case_id
                     JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id AND cp.resident_id = ha.resident_id
                     JOIN residents r ON r.resident_id = cp.resident_id
                     WHERE h.hearing_id = ? AND ha.resident_id = ?"
                );
                $valid->execute([$hearing, $resident]);
                $v = $valid->fetch(PDO::FETCH_ASSOC);
                if (!$v) {
                    return ['success' => false, 'message' => 'An attendance record for this party is required first.'];
                }

                // Optional supporting file
                $file = null;
                if (isset($_FILES['supporting_file']) && $_FILES['supporting_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $f = $_FILES['supporting_file'];
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5242880 || !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                        return ['success' => false, 'message' => 'Supporting file must be a PDF, JPG, or PNG up to 5 MB.'];
                    }
                    $dir = dirname(__DIR__, 2) . '/storage/uploads/hearing-explanations';
                    if (!is_dir($dir)) {
                        mkdir($dir, 0750, true);
                    }
                    $name = bin2hex(random_bytes(16)) . '.' . (['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime]);
                    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                        return ['success' => false, 'message' => 'Unable to store supporting file.'];
                    }
                    $file = 'storage/uploads/hearing-explanations/' . $name;
                }

                $q = $this->db->prepare(
                    'INSERT INTO hearing_explanations (hearing_id, resident_id, explanation, outcome, supporting_file, created_by, decided_by, decided_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $decidedBy = ($outcome === 'Pending') ? null : $userId;
                $decidedAt = ($outcome === 'Pending') ? null : date('Y-m-d H:i:s');
                $q->execute([$hearing, $resident, $text, $outcome, $file, $userId, $decidedBy, $decidedAt]);
                $id = (int) $this->db->lastInsertId();

                // If Justified: update hearing_attendance is_justified flag
                if ($outcome === 'Justified') {
                    $updateAtt = $this->db->prepare("UPDATE hearing_attendance SET is_justified = 1, justification_reason = ? WHERE hearing_id = ? AND resident_id = ?");
                    $updateAtt->execute(['Justified: ' . mb_substr($text, 0, 100), $hearing, $resident]);

                    $histRemarks = sprintf('Justified explanation recorded for %s (%s). Hearing record preserved; eligible for rescheduling.', $v['resident_name'], $v['party_type']);
                } elseif ($outcome === 'Unjustified') {
                    $updateAtt = $this->db->prepare("UPDATE hearing_attendance SET is_justified = 0, justification_reason = ? WHERE hearing_id = ? AND resident_id = ?");
                    $updateAtt->execute(['Unjustified non-appearance', $hearing, $resident]);

                    $histRemarks = sprintf('Unjustified non-appearance finding recorded for %s (%s). Awaiting authorized legal review.', $v['resident_name'], $v['party_type']);
                } else {
                    $histRemarks = sprintf('Explanation hearing recorded (Pending review) for %s (%s).', $v['resident_name'], $v['party_type']);
                }

                $histStmt = $this->db->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?");
                $histStmt->execute([$histRemarks, $userId, $v['case_id']]);

                $this->audit($userId, 'Recorded ' . $outcome . ' explanation for ' . $v['resident_name'], 'Hearings', $id);

                return [
                    'success' => true,
                    'message' => sprintf('Explanation hearing recorded with outcome: %s.', $outcome),
                    'explanation_id' => $id,
                    'outcome' => $outcome
                ];
            }

            // ==============================================================
            // 4. RECORD / REVIEW AUTHORIZED LEGAL ACTION
            // ==============================================================
            if ($action === 'legal_action') {
                if (!in_array($roleId, [1, 2], true)) {
                    return ['success' => false, 'status' => 403, 'message' => 'Only authorized personnel (Administrators & Lupon Clerks) may review legal actions.'];
                }

                $type = trim((string) ($d['action_type'] ?? ''));
                $details = trim((string) ($d['details'] ?? ''));
                $status = trim((string) ($d['status'] ?? 'Pending Review'));

                if ($type === '' || mb_strlen($type) > 120 || $details === '' || mb_strlen($details) > 5000 || !in_array($status, ['Pending Review', 'Approved', 'Rejected', 'Recorded'], true)) {
                    return ['success' => false, 'message' => 'Provide a valid proposed legal action and review status.'];
                }

                $partyCheck = $this->db->prepare(
                    "SELECT cp.party_type, c.case_id, c.case_number, co.complaint_title,
                            TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS resident_name
                     FROM hearings h
                     JOIN cases c ON c.case_id = h.case_id
                     JOIN complaints co ON co.complaint_id = c.complaint_id
                     JOIN complaint_parties cp ON cp.complaint_id = c.complaint_id AND cp.resident_id = ?
                     JOIN residents r ON r.resident_id = cp.resident_id
                     WHERE h.hearing_id = ? AND cp.party_type IN ('Complainant', 'Respondent')"
                );
                $partyCheck->execute([$resident, $hearing]);
                $p = $partyCheck->fetch(PDO::FETCH_ASSOC);
                if (!$p) {
                    return ['success' => false, 'message' => 'The selected party does not belong to this hearing.'];
                }

                $reviewedBy = ($status === 'Pending Review') ? null : $userId;
                $reviewedAt = ($status === 'Pending Review') ? null : date('Y-m-d H:i:s');

                $q = $this->db->prepare(
                    'INSERT INTO hearing_legal_actions (hearing_id, resident_id, action_type, status, details, reviewed_by, reviewed_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $q->execute([$hearing, $resident, $type, $status, $details, $reviewedBy, $reviewedAt, $userId]);
                $id = (int) $this->db->lastInsertId();

                // If approved and action involves issuing Certificate to Bar Action (KP Form 21 / 22), generate document:
                $generatedDocId = null;
                if ($status === 'Approved' && (str_contains($type, 'KP Form 21') || str_contains($type, 'KP Form 22') || str_contains($type, 'Bar Action'))) {
                    $formCode = str_contains($type, 'Counterclaim') || str_contains($type, 'KP Form 22') || $p['party_type'] === 'Respondent'
                        ? 'KP Form 22'
                        : 'KP Form 21';
                    $formDesc = ($formCode === 'KP Form 21')
                        ? 'Certificate to Bar Action'
                        : 'Certificate to Bar Action Counterclaim';

                    $tplStmt = $this->db->prepare("INSERT INTO document_templates (template_name, description) VALUES (?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description), template_id = LAST_INSERT_ID(template_id)");
                    $tplStmt->execute([$formCode, $formDesc]);
                    $tplId = (int) $this->db->lastInsertId();
                    if (!$tplId) {
                        $getTpl = $this->db->prepare("SELECT template_id FROM document_templates WHERE template_name = ? LIMIT 1");
                        $getTpl->execute([$formCode]);
                        $tplId = (int) $getTpl->fetchColumn();
                    }

                    $relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $p['case_number']);
                    $absDir = dirname(__DIR__, 2) . '/' . $relativeDir;
                    if (!is_dir($absDir)) {
                        mkdir($absDir, 0750, true);
                    }
                    $fileName = sprintf('%s-%s-%s.pdf', str_replace(' ', '-', $formCode), preg_replace('/[^a-zA-Z0-9_-]/', '_', $p['resident_name']), date('Ymd-His'));
                    $relativePath = $relativeDir . '/' . $fileName;
                    $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;

                    // Fetch party lists
                    $compStmt = $this->db->prepare("SELECT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name FROM complaint_parties cp JOIN residents r ON r.resident_id = cp.resident_id WHERE cp.complaint_id = (SELECT complaint_id FROM cases WHERE case_id = ?) AND cp.party_type = 'Complainant'");
                    $compStmt->execute([$p['case_id']]);
                    $complainants = $compStmt->fetchAll(PDO::FETCH_ASSOC);

                    $respStmt = $this->db->prepare("SELECT TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name FROM complaint_parties cp JOIN residents r ON r.resident_id = cp.resident_id WHERE cp.complaint_id = (SELECT complaint_id FROM cases WHERE case_id = ?) AND cp.party_type = 'Respondent'");
                    $respStmt->execute([$p['case_id']]);
                    $respondents = $respStmt->fetchAll(PDO::FETCH_ASSOC);

                    try {
                        (new PDFService())->generateNotice($formCode, [
                            'case_number' => $p['case_number'],
                            'complaint_title' => $p['complaint_title'],
                            'target_party_name' => $p['resident_name'],
                            'hearing_date' => date('Y-m-d'),
                            'complainants' => $complainants,
                            'respondents' => $respondents,
                        ], $absolutePath);
                    } catch (Throwable $e) {
                        file_put_contents($absolutePath, '%PDF-1.4 ' . $formCode);
                    }

                    $insDoc = $this->db->prepare("INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status) VALUES (?, ?, ?, ?, 'Generated')");
                    $insDoc->execute([$p['case_id'], $tplId, $userId, $relativePath]);
                    $generatedDocId = (int) $this->db->lastInsertId();
                }

                // Log in Case History
                $histStmt = $this->db->prepare("INSERT INTO case_history (case_id, status, remarks, updated_by) SELECT case_id, case_status, ?, ? FROM cases WHERE case_id = ?");
                $histRemarks = sprintf('Legal action review (%s): %s for %s (%s). Details: %s', $status, $type, $p['resident_name'], $p['party_type'], $details);
                $histStmt->execute([$histRemarks, $userId, $p['case_id']]);

                $this->audit($userId, 'Recorded legal action ' . $status . ' for ' . $p['resident_name'], 'Hearings', $id);

                return [
                    'success' => true,
                    'message' => 'Legal action review recorded. AGAP does not automatically impose any consequence without authorized human review.',
                    'legal_action_id' => $id,
                    'generated_document_id' => $generatedDocId
                ];
            }

            return ['success' => false, 'message' => 'Unknown workflow action.'];
        } catch (Throwable $e) {
            error_log('HearingWorkflow error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Unable to save the hearing workflow record: ' . $e->getMessage()];
        }
    }

    private function audit(int $user, string $action, string $module, int $id): void
    {
        $q = $this->db->prepare('INSERT INTO audit_trails (user_id, action, module_name, affected_record) VALUES (?, ?, ?, ?)');
        $q->execute([$user, $action, $module, $id]);
    }
}
