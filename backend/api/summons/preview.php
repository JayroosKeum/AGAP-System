<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/PDFService.php';
require_once __DIR__ . '/../../services/AuditService.php';

$db = (new Database())->connect();

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$documentId = filter_var($_GET['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$complaintId = filter_var($_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hearingId = filter_var($_GET['hearing_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

// Resolve case_id if not directly passed
if (!$caseId && $documentId) {
    $stmt = $db->prepare('SELECT case_id FROM generated_documents WHERE document_id = ?');
    $stmt->execute([$documentId]);
    $caseId = (int) $stmt->fetchColumn();
}

if (!$caseId && $complaintId) {
    $stmt = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
    $stmt->execute([$complaintId]);
    $caseId = (int) $stmt->fetchColumn();
}

if (!$caseId) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'A valid case identifier is required for summons preview.']);
    exit;
}

// Fetch case details
$caseStmt = $db->prepare("
    SELECT c.case_id, c.case_number, c.case_status, c.complaint_id, c.docket_date,
           co.complaint_number, co.complaint_title
    FROM cases c
    INNER JOIN complaints co ON co.complaint_id = c.complaint_id
    WHERE c.case_id = ?
");
$caseStmt->execute([$caseId]);
$case = $caseStmt->fetch(PDO::FETCH_ASSOC);

if (!$case) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Case record not found.']);
    exit;
}

// Fetch scheduled hearing
if ($hearingId) {
    $hStmt = $db->prepare("SELECT * FROM hearings WHERE hearing_id = ? AND case_id = ?");
    $hStmt->execute([$hearingId, $caseId]);
    $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
} else {
    $hStmt = $db->prepare("
        SELECT * FROM hearings
        WHERE case_id = ? AND hearing_type = 'Mediation'
        ORDER BY hearing_date ASC, hearing_id ASC
        LIMIT 1
    ");
    $hStmt->execute([$caseId]);
    $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$hearing) {
    // If no specific mediation hearing found, check any latest hearing
    $hStmt = $db->prepare("SELECT * FROM hearings WHERE case_id = ? ORDER BY hearing_date DESC LIMIT 1");
    $hStmt->execute([$caseId]);
    $hearing = $hStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$hearing) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No scheduled hearing found for this case.']);
    exit;
}

// Fetch parties
$partiesStmt = $db->prepare("
    SELECT cp.party_type, cp.resident_id,
           TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) AS full_name
    FROM complaint_parties cp
    INNER JOIN residents r ON r.resident_id = cp.resident_id
    WHERE cp.complaint_id = ? AND cp.party_type IN ('Complainant', 'Respondent')
    ORDER BY FIELD(cp.party_type, 'Complainant', 'Respondent'), cp.party_id ASC
");
$partiesStmt->execute([(int) $case['complaint_id']]);
$allParties = $partiesStmt->fetchAll(PDO::FETCH_ASSOC);

$complainants = [];
$respondents = [];
$primaryComplainant = null;
$primaryRespondent = null;

foreach ($allParties as $p) {
    if ($p['party_type'] === 'Complainant') {
        $complainants[] = $p;
        if ($primaryComplainant === null) $primaryComplainant = $p;
    } elseif ($p['party_type'] === 'Respondent') {
        $respondents[] = $p;
        if ($primaryRespondent === null) $primaryRespondent = $p;
    }
}

if (empty($respondents)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Respondent information is missing for this case.']);
    exit;
}

// Fetch Presiding Official (Administrator / Barangay Captain)
$officialStmt = $db->prepare("
    SELECT TRIM(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)) AS official_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE u.status = 'Active' AND r.role_name = 'Administrator'
    ORDER BY u.user_id ASC
    LIMIT 1
");
$officialStmt->execute();
$officialName = (string) ($officialStmt->fetchColumn() ?: 'Punong Barangay');

// Fetch or identify KP Form 9 document
$doc = null;
if ($documentId) {
    $dStmt = $db->prepare("
        SELECT gd.*, dt.template_name
        FROM generated_documents gd
        INNER JOIN document_templates dt ON dt.template_id = gd.template_id
        WHERE gd.document_id = ? AND gd.case_id = ?
    ");
    $dStmt->execute([$documentId, $caseId]);
    $doc = $dStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$doc) {
    $dStmt = $db->prepare("
        SELECT gd.*, dt.template_name
        FROM generated_documents gd
        INNER JOIN document_templates dt ON dt.template_id = gd.template_id
        WHERE gd.case_id = ? AND (dt.template_name = 'KP Form 9' OR dt.template_name LIKE '%Summon%')
        ORDER BY gd.generated_at DESC, gd.document_id DESC
        LIMIT 1
    ");
    $dStmt->execute([$caseId]);
    $doc = $dStmt->fetch(PDO::FETCH_ASSOC);
}

$pdfService = new PDFService();
$hearingDateTime = $hearing['hearing_date'];
$venue = $hearing['venue'] ?: 'Lupon Court Room';

$kp9Data = [
    'case_number' => $case['case_number'],
    'complaint_title' => $case['complaint_title'],
    'complainants' => $complainants,
    'respondents' => $respondents,
    'target_party_name' => $primaryRespondent['full_name'] ?? '',
    'venue' => $venue,
    'hearing_date' => $hearingDateTime,
    'notice_date' => date('Y-m-d'),
    'official_name' => $officialName,
];

$root = dirname(__DIR__, 3);
$relativeDir = 'storage/generated-documents/' . date('Y') . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $case['case_number']);
$fullDir = $root . '/' . $relativeDir;
$hTimestamp = strtotime($hearingDateTime);
$hTime = $hTimestamp;

// -------------------------------------------------------------------------
// 1. Fetch & Verify KP Form 8 (Notice of Hearing for Complainant)
// -------------------------------------------------------------------------
$docKp8 = null;
$dKp8Stmt = $db->prepare("
    SELECT gd.*, dt.template_name
    FROM generated_documents gd
    INNER JOIN document_templates dt ON dt.template_id = gd.template_id
    WHERE gd.case_id = ? AND dt.template_name = 'KP Form 8'
    ORDER BY gd.generated_at DESC, gd.document_id DESC
    LIMIT 1
");
$dKp8Stmt->execute([$caseId]);
$docKp8 = $dKp8Stmt->fetch(PDO::FETCH_ASSOC);

$kp8Data = [
    'case_number' => $case['case_number'],
    'complaint_title' => $case['complaint_title'],
    'complainants' => $complainants,
    'respondents' => $respondents,
    'target_party_name' => $primaryComplainant['full_name'] ?? '',
    'venue' => $venue,
    'hearing_date' => $hearingDateTime,
    'notice_date' => date('Y-m-d'),
    'official_name' => $officialName,
];

$needsKp8Regen = false;
if (!$docKp8 || empty($docKp8['file_path'])) {
    $needsKp8Regen = true;
} else {
    $fullKp8Path = $root . '/' . $docKp8['file_path'];
    if (!is_file($fullKp8Path)) {
        $needsKp8Regen = true;
    } else {
        $docTime = strtotime($docKp8['generated_at']);
        if ($hTime && $docTime && $docTime < strtotime($hearing['updated_at'] ?? '')) {
            $needsKp8Regen = true;
        }
    }
}

if ($needsKp8Regen) {
    if (!is_dir($fullDir)) {
        @mkdir($fullDir, 0750, true);
    }
    $timestamp = date('Ymd-His');
    $kp8FileName = sprintf('KP-Form-8-Notice-%s.pdf', $timestamp);
    $kp8FilePath = $relativeDir . '/' . $kp8FileName;
    $kp8FullPath = $fullDir . '/' . $kp8FileName;

    try {
        $pdfService->generateNotice('KP Form 8', $kp8Data, $kp8FullPath);

        $tStmt8 = $db->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 8' LIMIT 1");
        $tStmt8->execute();
        $templateId8 = (int) $tStmt8->fetchColumn() ?: 2;

        $userId = (int) ($_SESSION['user_id'] ?? 1);
        $insDoc8 = $db->prepare("
            INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status, regeneration_reason)
            VALUES (?, ?, ?, ?, 'For Service', 'Synchronized with current saved hearing schedule')
        ");
        $insDoc8->execute([$caseId, $templateId8, $userId, $kp8FilePath]);
        $newDocId8 = (int) $db->lastInsertId();

        $updDeliv8 = $db->prepare("
            UPDATE summon_deliveries
            SET document_id = ?
            WHERE case_id = ? AND party_type = 'Complainant' AND delivery_status = 'Pending'
        ");
        $updDeliv8->execute([$newDocId8, $caseId]);

        $docKp8 = [
            'document_id' => $newDocId8,
            'case_id' => $caseId,
            'file_path' => $kp8FilePath,
            'service_status' => 'For Service',
            'template_name' => 'KP Form 8'
        ];
    } catch (Throwable $e) {
        error_log('Error regenerating KP Form 8 preview PDF: ' . $e->getMessage());
    }
}

$previewHtmlKp8 = $pdfService->renderNoticeHtml('KP Form 8', $kp8Data);
$docIdKp8 = !empty($docKp8['document_id']) ? (int) $docKp8['document_id'] : 0;

// -------------------------------------------------------------------------
// 2. Fetch & Verify KP Form 9 (Summons for Respondent)
// -------------------------------------------------------------------------
$needsRegeneration = false;
if (!$doc || empty($doc['file_path'])) {
    $needsRegeneration = true;
} else {
    $fullPath = $root . '/' . $doc['file_path'];
    if (!is_file($fullPath)) {
        $needsRegeneration = true;
    } else {
        $docTime = strtotime($doc['generated_at']);
        if ($hTime && $docTime && $docTime < strtotime($hearing['updated_at'] ?? '')) {
            $needsRegeneration = true;
        }
    }
}

if ($needsRegeneration) {
    if (!is_dir($fullDir)) {
        @mkdir($fullDir, 0750, true);
    }
    $timestamp = date('Ymd-His');
    $kp9FileName = sprintf('KP-Form-9-Summons-1-%s.pdf', $timestamp);
    $kp9FilePath = $relativeDir . '/' . $kp9FileName;
    $kp9FullPath = $fullDir . '/' . $kp9FileName;

    try {
        $pdfService->generateNotice('KP Form 9', $kp9Data, $kp9FullPath);

        // Fetch or create template
        $tStmt = $db->prepare("SELECT template_id FROM document_templates WHERE template_name = 'KP Form 9' LIMIT 1");
        $tStmt->execute();
        $templateId = (int) $tStmt->fetchColumn();
        if (!$templateId) {
            $tIns = $db->prepare("INSERT INTO document_templates (template_name, description) VALUES ('KP Form 9', 'Summons')");
            $tIns->execute();
            $templateId = (int) $db->lastInsertId();
        }

        $userId = (int) ($_SESSION['user_id'] ?? 1);
        $insDoc = $db->prepare("
            INSERT INTO generated_documents (case_id, template_id, generated_by, file_path, service_status, regeneration_reason)
            VALUES (?, ?, ?, ?, 'For Service', 'Synchronized with current saved hearing schedule')
        ");
        $insDoc->execute([$caseId, $templateId, $userId, $kp9FilePath]);
        $newDocId = (int) $db->lastInsertId();

        // Update summon_deliveries for Respondent
        $updDeliv = $db->prepare("
            UPDATE summon_deliveries
            SET document_id = ?
            WHERE case_id = ? AND party_type = 'Respondent' AND delivery_status = 'Pending'
        ");
        $updDeliv->execute([$newDocId, $caseId]);

        (new AuditService())->log($userId, "Regenerated KP Form 9 summons to match saved hearing schedule", 'Summons', $newDocId);

        $doc = [
            'document_id' => $newDocId,
            'case_id' => $caseId,
            'file_path' => $kp9FilePath,
            'service_status' => 'For Service',
            'template_name' => 'KP Form 9'
        ];
    } catch (Throwable $e) {
        error_log('Error regenerating summons preview PDF: ' . $e->getMessage());
    }
}

// Generate the official HTML for KP Form 9
$previewHtmlKp9 = $pdfService->renderNoticeHtml('KP Form 9', $kp9Data);
$docIdKp9 = !empty($doc['document_id']) ? (int) $doc['document_id'] : 0;

echo json_encode([
    'success' => true,
    'case_id' => (int) $caseId,
    'case_number' => $case['case_number'],
    'complaint_id' => (int) $case['complaint_id'],
    'complaint_title' => $case['complaint_title'],
    'document_id' => $docIdKp9,
    'hearing_id' => (int) $hearing['hearing_id'],
    'hearing_type' => $hearing['hearing_type'] ?? 'Mediation',
    'hearing_date' => $hearingDateTime,
    'hearing_date_formatted' => $hTimestamp ? date('F j, Y \a\t g:i A', $hTimestamp) : $hearingDateTime,
    'venue' => $venue,
    'complainants' => $complainants,
    'respondents' => $respondents,
    'primary_complainant' => $primaryComplainant['full_name'] ?? 'Complainant',
    'primary_respondent' => $primaryRespondent['full_name'] ?? 'Respondent',
    'target_party_name' => $primaryRespondent['full_name'] ?? 'Respondent',
    'official_name' => $officialName,
    'service_status' => $doc['service_status'] ?? 'For Service',
    'pdf_url' => '../../../backend/api/documents/download.php?id=' . $docIdKp9,
    'download_url' => '../../../backend/api/documents/download.php?id=' . $docIdKp9 . '&download=1',
    'officer_return_url' => '../gps/proof-service.php?case_id=' . $caseId . ($docIdKp9 ? '&document_id=' . $docIdKp9 : ''),
    'html' => $previewHtmlKp9,
    'kp8' => [
        'document_id' => $docIdKp8,
        'html' => $previewHtmlKp8,
        'pdf_url' => '../../../backend/api/documents/download.php?id=' . $docIdKp8,
        'download_url' => '../../../backend/api/documents/download.php?id=' . $docIdKp8 . '&download=1',
        'target_party_name' => $primaryComplainant['full_name'] ?? 'Complainant',
        'form_name' => 'KP Form No. 8',
        'form_title' => 'Patalastas ng Pagdinig (Notice of Hearing)'
    ],
    'kp9' => [
        'document_id' => $docIdKp9,
        'html' => $previewHtmlKp9,
        'pdf_url' => '../../../backend/api/documents/download.php?id=' . $docIdKp9,
        'download_url' => '../../../backend/api/documents/download.php?id=' . $docIdKp9 . '&download=1',
        'target_party_name' => $primaryRespondent['full_name'] ?? 'Respondent',
        'form_name' => 'KP Form No. 9',
        'form_title' => 'Patawag (Summons)'
    ],
]);
