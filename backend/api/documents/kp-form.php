<?php

session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/PDFService.php';
require_once __DIR__ . '/../../services/KpFormService.php';

$conn = (new Database())->connect();
$documentId = filter_input(INPUT_GET, 'document_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['document_id']) ? (int) $_GET['document_id'] : null);
$caseId = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['case_id']) ? (int) $_GET['case_id'] : null);
$complaintId = filter_input(INPUT_GET, 'complaint_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['complaint_id']) ? (int) $_GET['complaint_id'] : null);
$hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['hearing_id']) ? (int) $_GET['hearing_id'] : null);
$formCode = trim((string) ($_GET['form_code'] ?? 'KP Form 10'));
$asPdf = isset($_GET['pdf']) && $_GET['pdf'] == '1';
$isPreview = isset($_GET['preview']) && in_array((string) $_GET['preview'], ['1', 'true'], true);

if ($documentId) {
    // If document_id is specified, lookup document joined with cases, pangkat_groups, and hearings
    $docStmt = $conn->prepare("
        SELECT gd.*, dt.template_name, c.case_id, c.case_number, c.complaint_id,
               pg.pangkat_id, pg.formation_date, pg.selection_method,
               h.hearing_id AS linked_hearing_id, h.hearing_date, h.venue AS hearing_venue
        FROM generated_documents gd
        INNER JOIN document_templates dt ON dt.template_id = gd.template_id
        INNER JOIN cases c ON c.case_id = gd.case_id
        LEFT JOIN pangkat_groups pg ON pg.case_id = c.case_id
        LEFT JOIN hearings h ON (h.kp12_document_id = gd.document_id OR (h.case_id = c.case_id AND h.hearing_type = 'Conciliation'))
        WHERE gd.document_id = ?
        ORDER BY (h.kp12_document_id = gd.document_id) DESC, h.hearing_date ASC, h.hearing_id ASC
        LIMIT 1
    ");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if ($doc) {
        $caseId = (int) $doc['case_id'];
        $formCode = $doc['template_name'];
        if (!$hearingId && !empty($doc['linked_hearing_id'])) {
            $hearingId = (int) $doc['linked_hearing_id'];
        }

        // If physical PDF file exists on disk, serve it if requested as PDF or direct download
        $root = realpath(dirname(__DIR__, 3));
        $rawPath = !empty($doc['file_path']) ? ltrim($doc['file_path'], '/\\') : '';
        $filePath = false;
        if ($root && $rawPath) {
            $candidate = $root . DIRECTORY_SEPARATOR . $rawPath;
            if (is_file($candidate)) {
                $filePath = realpath($candidate);
            }
        }

        if ($filePath && is_file($filePath)) {
            // Serve physical file immediately when PDF is requested or in standalone preview
            if ($asPdf || (!$isPreview && empty($_GET['bare']))) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
                readfile($filePath);
                exit;
            }
        }
    }
}

if (!$caseId && $complaintId) {
    $cStmt = $conn->prepare("SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1");
    $cStmt->execute([$complaintId]);
    $foundCaseId = $cStmt->fetchColumn();
    $caseId = $foundCaseId ? (int)$foundCaseId : (int)$complaintId;
}

if (!$caseId) {
    http_response_code(404);
    exit('Valid case ID or document ID required.');
}

// For KP Forms 10, 11, and 12, ensure 1st Conciliation hearing is linked if hearingId is not yet specified
if (!$hearingId && (str_contains($formCode, '10') || str_contains($formCode, '11') || str_contains($formCode, '12'))) {
    $hStmt = $conn->prepare("
        SELECT hearing_id FROM hearings
        WHERE case_id = ? AND hearing_type = 'Conciliation'
        ORDER BY hearing_date ASC, hearing_id ASC
        LIMIT 1
    ");
    $hStmt->execute([$caseId]);
    $resolvedHId = $hStmt->fetchColumn();
    if ($resolvedHId) {
        $hearingId = (int) $resolvedHId;
    }
}

$kpFormService = new KpFormService($conn);

try {
    $formData = $kpFormService->getFormData($formCode, $caseId, $hearingId);
} catch (Throwable $e) {
    http_response_code(404);
    exit(htmlspecialchars($e->getMessage()));
}

// Enforce strict party eligibility and locking rules for show cause unless in preview mode
if (!$isPreview) {
    if ($formCode === 'KP Form 18') {
        $isCompAbsent = false;
        if (!empty($hearingId)) {
            $haStmt = $conn->prepare("
                SELECT COUNT(*) FROM hearing_attendance ha
                INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
                WHERE (ha.hearing_id = ? OR ha.hearing_id = (SELECT rescheduled_from_id FROM hearings WHERE hearing_id = ?))
                  AND cp.party_type = 'Complainant' AND ha.attendance_status = 'Absent'
            ");
            $haStmt->execute([(int) $formData['complaint_id'], $hearingId, $hearingId]);
            $isCompAbsent = ((int) $haStmt->fetchColumn() > 0);
        } else {
            // Check any hearing in this case
            $haStmt = $conn->prepare("
                SELECT COUNT(*) FROM hearing_attendance ha
                INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
                WHERE cp.party_type = 'Complainant' AND ha.attendance_status = 'Absent'
            ");
            $haStmt->execute([(int) $formData['complaint_id']]);
            $isCompAbsent = ((int) $haStmt->fetchColumn() > 0);
        }
        if (!$isCompAbsent) {
            http_response_code(403);
            exit('KP Form 18 is locked: The Complainant has not been recorded absent in this session.');
        }
    } elseif ($formCode === 'KP Form 19') {
        $isRespAbsent = false;
        if (!empty($hearingId)) {
            $haStmt = $conn->prepare("
                SELECT COUNT(*) FROM hearing_attendance ha
                INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
                WHERE (ha.hearing_id = ? OR ha.hearing_id = (SELECT rescheduled_from_id FROM hearings WHERE hearing_id = ?))
                  AND cp.party_type = 'Respondent' AND ha.attendance_status = 'Absent'
            ");
            $haStmt->execute([(int) $formData['complaint_id'], $hearingId, $hearingId]);
            $isRespAbsent = ((int) $haStmt->fetchColumn() > 0);
        } else {
            // Check any hearing in this case
            $haStmt = $conn->prepare("
                SELECT COUNT(*) FROM hearing_attendance ha
                INNER JOIN complaint_parties cp ON cp.resident_id = ha.resident_id AND cp.complaint_id = ?
                WHERE cp.party_type = 'Respondent' AND ha.attendance_status = 'Absent'
            ");
            $haStmt->execute([(int) $formData['complaint_id']]);
            $isRespAbsent = ((int) $haStmt->fetchColumn() > 0);
        }
        if (!$isRespAbsent) {
            http_response_code(403);
            exit('KP Form 19 is locked: The Respondent has not been recorded absent in this session.');
        }
    }
}

if ($asPdf) {
    try {
        $pdfPath = $kpFormService->generatePdf($formCode, $caseId, $hearingId);
        $downloadName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $formCode) . '_' . ($formData['case_number'] ?? $caseId) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $downloadName . '"');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Failed to render PDF: ' . htmlspecialchars($e->getMessage()));
    }
}

// Render HTML Preview
// Master Document Standard: Parse & Render through DocxTemplateService when template exists
require_once __DIR__ . '/../../services/DocxTemplateService.php';

$formKeyMap = [
    'KP Form 20-A' => 'kp_20_a',
    'KP Form 20-B' => 'kp_20_b',
    'KP Form 20' => 'kp_20',
    'KP Form 23' => 'kp_23',
    'KP Form 22' => 'kp_22',
    'KP Form 21' => 'kp_21',
    'KP Form 16' => 'kp_16',
    'KP Form 17' => 'kp_17',
    'KP Form 14' => 'kp_14',
    'KP Form 15' => 'kp_15',
    'KP Form 10' => 'kp_10',
    'KP Form 11' => 'kp_11',
    'KP Form 12' => 'kp_12',
    'KP Form 13' => 'kp_13',
    'KP Form 7'  => 'kp_7',
    'KP Form 8'  => 'kp_8',
    'KP Form 9'  => 'kp_9',
];

$matchedKey = null;
foreach ($formKeyMap as $prefix => $key) {
    if (str_contains($formCode, $prefix) || str_contains($formCode, str_replace('KP Form', 'Blg.', $prefix))) {
        $matchedKey = $key;
        break;
    }
}

$compNames = [];
if (!empty($formData['complainants'])) {
    foreach ($formData['complainants'] as $c) {
        $compNames[] = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
    }
}
$respNames = [];
if (!empty($formData['respondents'])) {
    foreach ($formData['respondents'] as $r) {
        $respNames[] = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
    }
}

$masterData = array_merge($formData, [
    'case_number' => $formData['case_number'] ?? "KP-{$caseId}",
    'complaint_title' => $formData['complaint_title'] ?? '',
    'complainants' => implode(', ', array_filter($compNames)) ?: ($formData['complainants_names'] ?? 'May-Sumbong'),
    'respondents' => implode(', ', array_filter($respNames)) ?: ($formData['respondents_names'] ?? 'Ipinagsusumbong'),
    'settlement_terms' => $formData['agreement_details'] ?? '',
    'minutes_agreement_body' => $formData['agreement_details'] ?? '',
    'agreement_details' => $formData['agreement_details'] ?? '',
    'settlement_date' => !empty($formData['settlement_date']) ? date('F j, Y', strtotime($formData['settlement_date'])) : date('F j, Y'),
    'execution_date' => !empty($formData['execution_date']) ? date('F j, Y', strtotime($formData['execution_date'])) : date('F j, Y'),
    'date_today' => date('F j, Y'),
    'docket_date' => !empty($formData['docket_date']) ? date('F j, Y', strtotime($formData['docket_date'])) : date('F j, Y'),
    'hearing_date' => !empty($formData['hearing_date']) ? date('F j, Y', strtotime($formData['hearing_date'])) : date('F j, Y'),
    'punong_barangay' => 'HON. AKIKO LYNNE CENTENO',
    'lupon_secretary' => 'MARIA CRISTINA SANTOS',
    'barangay_name' => 'BARANGAY TUMANA',
    'city_municipality' => 'LUNGSOD NG MARIKINA',
    'province' => 'LALAWIGAN NG RIZAL'
]);

$dedicatedForms = ['7', '8', '9', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25'];
$useDedicatedPdfHtml = false;
foreach ($dedicatedForms as $df) {
    if (str_contains($formCode, $df)) {
        $useDedicatedPdfHtml = true;
        break;
    }
}

if ($useDedicatedPdfHtml) {
    $htmlBody = $kpFormService->renderHtml($formCode, $caseId, $hearingId, false);
} elseif ($matchedKey) {
    $registry = DocxTemplateService::getRegistry();
    $templateConfig = $registry[$matchedKey] ?? [];
    $htmlBody = DocxTemplateService::renderDocxHtmlPreview($matchedKey, $templateConfig, [], [], $masterData);
} else {
    $htmlBody = $kpFormService->renderHtml($formCode, $caseId, $hearingId, false);
}

// Check if this request is loaded in an iframe or wants the bare official form
$isBare = isset($_GET['bare']) && $_GET['bare'] == '1';

if ($isBare || $isPreview) {
    // Add print styles and responsive wrapper for iframe viewing
    header('Content-Type: text/html; charset=utf-8');
    echo $htmlBody;
    exit;
}

// Standalone full-page view with action bar
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="tl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($formCode) ?> &mdash; Case #<?= htmlspecialchars($formData['case_number']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .agap-actions-bar {
            position: sticky; top: 0; left: 0; right: 0; z-index: 1000;
            display: flex; justify-content: space-between; align-items: center;
            background: #ffffff; padding: 12px 24px; border-bottom: 1px solid #cbd5e1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .btn-act {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; font-size: 0.85rem; font-weight: 600;
            border-radius: 6px; border: 1px solid #cbd5e1;
            background: #fff; color: #1e293b; text-decoration: none; cursor: pointer;
            transition: all 0.15s;
        }
        .btn-act:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn-act-primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn-act-primary:hover { background: #1d4ed8; }
        @media print {
            .agap-actions-bar { display: none !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body>
    <div class="agap-actions-bar">
        <div>
            <strong style="color: #0f172a; font-size: 0.95rem;"><?= htmlspecialchars($formCode) ?></strong>
            <span style="color: #64748b; font-size: 0.84rem; margin-left: 8px;">Case #<?= htmlspecialchars($formData['case_number']) ?> &bull; <?= htmlspecialchars($formData['complaint_title']) ?></span>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <button type="button" class="btn-act" onclick="window.print()">
                🖨️ Print Form
            </button>
            <a href="kp-form.php?case_id=<?= urlencode((string)$caseId) ?><?= $hearingId ? '&hearing_id=' . urlencode((string)$hearingId) : '' ?>&form_code=<?= urlencode($formCode) ?>&pdf=1" class="btn-act btn-act-primary">
                Download PDF
            </a>
        </div>
    </div>
    <?= $htmlBody ?>
</body>
</html>
