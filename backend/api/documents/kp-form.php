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
    } elseif (in_array($formCode, ['KP Form 23', 'KP Form 24', 'KP Form 25'], true)) {
        $settlementStmt = $conn->prepare('SELECT settlement_id, repudiation_status, repudiation_deadline, six_month_deadline FROM settlements WHERE case_id = ? LIMIT 1');
        $settlementStmt->execute([$caseId]);
        $settlement = $settlementStmt->fetch(PDO::FETCH_ASSOC);
        if (!$settlement || $settlement['repudiation_status'] === 'Repudiated') {
            http_response_code(403);
            exit($formCode . ' is unavailable for a repudiated or missing settlement.');
        }
        if ($formCode === 'KP Form 23' && (date('Y-m-d') <= $settlement['repudiation_deadline'] || date('Y-m-d') > $settlement['six_month_deadline'])) {
            http_response_code(403);
            exit('KP Form 23 is locked until finality and is unavailable after barangay execution jurisdiction lapses.');
        }
        $executionStmt = $conn->prepare('SELECT * FROM execution_proceedings WHERE settlement_id = ? ORDER BY execution_id DESC LIMIT 1');
        $executionStmt->execute([(int) $settlement['settlement_id']]);
        $execution = $executionStmt->fetch(PDO::FETCH_ASSOC);
        if ($formCode === 'KP Form 24' && (!$execution || !in_array($execution['execution_status'], ['Hearing Scheduled', 'Grace Period Active', 'Notice of Execution Issued', 'Enforced / Complied'], true))) {
            http_response_code(403);
            exit('KP Form 24 requires a filed Motion for Execution with a scheduled hearing.');
        }
        if ($formCode === 'KP Form 25' && (!$execution || $execution['execution_status'] !== 'Notice of Execution Issued')) {
            http_response_code(403);
            exit('KP Form 25 is locked until hearing minutes, an expired grace period, and notice issuance are recorded.');
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
    'KP Form 24' => 'kp_24',
    'KP Form 25' => 'kp_25',
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

// Enrich HTML with data-live-field bindings and #kpFormLiveMeta JSON for 2-Panel Split-Screen Live Editor
function enrichKpFormHtmlForLiveEditor(string $html, array $formData, string $formCode, int $caseId, ?int $hearingId): string {
    $compList = [];
    if (!empty($formData['complainants']) && is_array($formData['complainants'])) {
        foreach ($formData['complainants'] as $c) {
            $n = is_array($c) ? ($c['full_name'] ?? '') : (string)$c;
            if (trim($n) !== '') $compList[] = trim($n);
        }
    }
    $respList = [];
    if (!empty($formData['respondents']) && is_array($formData['respondents'])) {
        foreach ($formData['respondents'] as $r) {
            $n = is_array($r) ? ($r['full_name'] ?? '') : (string)$r;
            if (trim($n) !== '') $respList[] = trim($n);
        }
    }

    $compText = implode(', ', $compList);
    $respText = implode(', ', $respList);

    $primaryLabel = 'Primary Statement / Narrative / Terms';
    $primaryText = $formData['complaint_details'] ?? '';
    $secondaryLabel = 'Relief Sought / Additional Details';
    $secondaryText = $formData['relief_sought'] ?? '';

    if (str_contains($formCode, '16')) {
        $primaryLabel = 'Amicable Settlement Terms (Kasunduang Pag-aayos)';
        $primaryText = $formData['agreement_details'] ?? '';
        $secondaryLabel = 'Presiding Role';
        $secondaryText = $formData['presiding_role'] ?? 'Punong Barangay';
    } elseif (str_contains($formCode, '17')) {
        $primaryLabel = 'Sworn Grounds for Repudiation (Dahilan ng Pagtanggi)';
        $primaryText = $formData['repudiation_reason'] ?? ($formData['repudiation_grounds'] ?? '');
        $secondaryLabel = 'Repudiating Party Name (Tumangging Panig)';
        $secondaryText = $formData['repudiating_party'] ?? ($formData['repudiating_party_name'] ?? $respText);
    } elseif (str_contains($formCode, '23') || str_contains($formCode, '25')) {
        $primaryLabel = 'Violated Settlement Obligation / Agreement Details';
        $primaryText = $formData['obligation_violated'] ?? ($formData['agreement_details'] ?? '');
        $secondaryLabel = 'Amount or Relief for Execution';
        $secondaryText = $formData['amount_or_requirement'] ?? ($formData['execution_amount_words'] ?? '');
    } elseif (str_contains($formCode, '14')) {
        $primaryLabel = 'Arbitration Agreement Terms (Kasunduan sa Pag-aarbitro)';
        $primaryText = $formData['agreement_details'] ?? '';
    } elseif (str_contains($formCode, '15')) {
        $primaryLabel = 'Arbitration Award Decision (Gawad ng Paghahatol)';
        $primaryText = $formData['award_details'] ?? ($formData['award_terms'] ?? '');
    } elseif (str_contains($formCode, '20-A') || str_contains($formCode, '21') || str_contains($formCode, '20-B') || str_contains($formCode, '22')) {
        $primaryLabel = 'Unjustified Absence Findings / Bar Grounds';
        $primaryText = $formData['absence_reason'] ?? '';
    } elseif (str_contains($formCode, '20')) {
        $primaryLabel = 'Certification to File Action (CFA) Grounds';
        $primaryText = $formData['cfa_reason'] ?? '';
    } elseif (str_contains($formCode, '11')) {
        $secondaryLabel = 'Appointed Pangkat Members';
        $secondaryText = $formData['chosen_member'] ?? '';
    }

    // Wrap single-line & multi-line values in <span class="live-doc-field" data-live-field="...">
    $wrapMap = [
        'case_number' => (string)($formData['case_number'] ?? ''),
        'complaint_title' => (string)($formData['complaint_title'] ?? ''),
        'venue' => (string)($formData['venue'] ?? ''),
        'official_name' => (string)($formData['official_name'] ?? ($formData['captain_name'] ?? '')),
        'secretary_name' => (string)($formData['secretary_name'] ?? ''),
        'primary_body_text' => (string)$primaryText,
        'secondary_body_text' => (string)$secondaryText,
    ];

    foreach ($wrapMap as $fieldKey => $rawVal) {
        $trimmed = trim($rawVal);
        if ($trimmed === '' || mb_strlen($trimmed) < 2) continue;
        if (strpos($html, 'data-live-field="' . $fieldKey . '"') !== false) continue;

        $escaped = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
        $nl2brEscaped = nl2br($escaped);

        // Try nl2br match first, then single-line escaped match (outside HTML attributes)
        $candidates = array_unique([$nl2brEscaped, $escaped]);
        foreach ($candidates as $cand) {
            if ($cand === '') continue;
            $pattern = '/(?<![="\'a-zA-Z0-9_-])' . preg_quote($cand, '/') . '(?![a-zA-Z0-9_-])/u';
            $replaced = preg_replace(
                $pattern,
                '<span class="live-doc-field" data-live-field="' . $fieldKey . '">' . $cand . '</span>',
                $html,
                2,
                $count
            );
            if ($count > 0 && $replaced !== null) {
                $html = $replaced;
                break;
            }
        }
    }

    // Also wrap primary complainant & respondent names
    if (!empty($compList[0])) {
        $cEsc = htmlspecialchars($compList[0], ENT_QUOTES, 'UTF-8');
        $html = preg_replace(
            '/(?<![="\'a-zA-Z0-9_-])' . preg_quote($cEsc, '/') . '/u',
            '<span class="live-doc-field" data-live-field="complainants_text">' . $cEsc . '</span>',
            $html,
            2
        ) ?? $html;
    }
    if (!empty($respList[0])) {
        $rEsc = htmlspecialchars($respList[0], ENT_QUOTES, 'UTF-8');
        $html = preg_replace(
            '/(?<![="\'a-zA-Z0-9_-])' . preg_quote($rEsc, '/') . '/u',
            '<span class="live-doc-field" data-live-field="respondents_text">' . $rEsc . '</span>',
            $html,
            2
        ) ?? $html;
    }

    $metaPayload = [
        'case_id' => $caseId,
        'complaint_id' => (int)($formData['complaint_id'] ?? 0),
        'hearing_id' => $hearingId,
        'form_code' => $formCode,
        'fields' => [
            'case_number' => (string)($formData['case_number'] ?? ''),
            'complaint_title' => (string)($formData['complaint_title'] ?? ''),
            'complainants_text' => $compText,
            'respondents_text' => $respText,
            'venue' => (string)($formData['venue'] ?? ''),
            'hearing_date' => !empty($formData['hearing_date']) ? date('Y-m-d\TH:i', strtotime((string)$formData['hearing_date'])) : '',
            'notice_date' => !empty($formData['notice_date']) ? date('Y-m-d', strtotime((string)$formData['notice_date'])) : date('Y-m-d'),
            'official_name' => (string)($formData['official_name'] ?? ($formData['captain_name'] ?? '')),
            'secretary_name' => (string)($formData['secretary_name'] ?? ''),
            'primary_body_text' => (string)$primaryText,
            'secondary_body_text' => (string)$secondaryText,
        ],
        'labels' => [
            'primary_body_label' => $primaryLabel,
            'secondary_body_label' => $secondaryLabel,
        ]
    ];

    $injectBlock = '
<style>
    .live-doc-field {
        transition: background-color 0.25s ease, box-shadow 0.25s ease;
        border-radius: 2px;
    }
    .live-doc-field.live-field-flash {
        background-color: rgba(37, 99, 235, 0.18) !important;
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.28);
    }
    body.direct-edit-active .preview-sheet *,
    body.direct-edit-active .page-sheet * {
        cursor: text;
    }
    body.direct-edit-active .live-doc-field {
        outline: 1px dashed rgba(37, 99, 235, 0.45);
    }
    body.direct-edit-active [contenteditable="true"]:focus {
        outline: 2px solid #2563eb !important;
        background-color: rgba(239, 246, 255, 0.7) !important;
    }
</style>
<script id="kpFormLiveMeta" type="application/json">' . json_encode($metaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

    if (stripos($html, '</body>') !== false) {
        $html = str_ireplace('</body>', $injectBlock . "\n</body>", $html);
    } else {
        $html .= $injectBlock;
    }

    return $html;
}

$htmlBody = enrichKpFormHtmlForLiveEditor($htmlBody, $formData, $formCode, $caseId, $hearingId);

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
