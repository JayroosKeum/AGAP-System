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
$hearingId = filter_input(INPUT_GET, 'hearing_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: (isset($_GET['hearing_id']) ? (int) $_GET['hearing_id'] : null);
$formCode = trim((string) ($_GET['form_code'] ?? 'KP Form 10'));
$asPdf = isset($_GET['pdf']) && $_GET['pdf'] == '1';
$isPreview = isset($_GET['preview']) && in_array((string) $_GET['preview'], ['1', 'true'], true);

if ($documentId) {
    // If document_id is specified, lookup document
    $docStmt = $conn->prepare("
        SELECT gd.*, dt.template_name, c.case_id, c.case_number, c.complaint_id
        FROM generated_documents gd
        INNER JOIN document_templates dt ON dt.template_id = gd.template_id
        INNER JOIN cases c ON c.case_id = gd.case_id
        WHERE gd.document_id = ?
    ");
    $docStmt->execute([$documentId]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if ($doc) {
        $caseId = (int) $doc['case_id'];
        $formCode = $doc['template_name'];

        // If file exists, serve it
        $root = realpath(dirname(__DIR__, 3));
        $filePath = realpath($root . '/' . $doc['file_path']);
        if ($filePath && is_file($filePath)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
            readfile($filePath);
            exit;
        }
    }
}

if (!$caseId) {
    http_response_code(404);
    exit('Valid case ID or document ID required.');
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
                WHERE ha.hearing_id = ? AND cp.party_type = 'Complainant' AND ha.attendance_status = 'Absent'
            ");
            $haStmt->execute([(int) $formData['complaint_id'], $hearingId]);
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
                WHERE ha.hearing_id = ? AND cp.party_type = 'Respondent' AND ha.attendance_status = 'Absent'
            ");
            $haStmt->execute([(int) $formData['complaint_id'], $hearingId]);
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
$htmlBody = $kpFormService->renderHtml($formCode, $caseId, $hearingId, false);

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
