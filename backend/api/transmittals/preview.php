<?php

session_start();

if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3, 4], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/CaseTransmittal.php';
require_once __DIR__ . '/../../services/PDFService.php';

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$complaintId = filter_var($_GET['complaint_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$db = (new Database())->connect();

if (!$caseId && $complaintId) {
    $cStmt = $db->prepare("SELECT case_id FROM cases WHERE complaint_id = ? ORDER BY case_id DESC LIMIT 1");
    $cStmt->execute([$complaintId]);
    $caseId = (int) $cStmt->fetchColumn() ?: null;
}

if (!$caseId) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid or missing case ID.']);
    exit;
}

$returnHtml = isset($_GET['html']) && in_array((string) $_GET['html'], ['1', 'true'], true);
$download = isset($_GET['download']) && in_array((string) $_GET['download'], ['1', 'true'], true);

try {
    $transmittalModel = new CaseTransmittal($db);
    $transmittal = $transmittalModel->createOrGetTransmittal($caseId);

    if (!$transmittal) {
        if ($returnHtml) {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html><body style="font-family:sans-serif; text-align:center; padding:50px; color:#475569;"><h3>Case Pending Formal Resolution</h3><p>The Court Transmittal Letter will be generated once an Amicable Settlement, Arbitration Award, or CFA is issued.</p></body></html>';
            exit;
        }
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Case is not yet formally resolved.']);
        exit;
    }

    $details = $transmittalModel->getTransmittalFullDetails($caseId);
    $tData = $details['transmittal'] ?? [];
    $isLocked = !empty($tData['is_locked']);
    $daysRemaining = (int) ($tData['working_days_remaining'] ?? 0);
    $unlockDate = $tData['unlock_date_formatted'] ?? ($tData['ten_day_expiry_date_formatted'] ?? 'N/A');

    // GATING: Enforce statutory 10-business-day lock
    if ($isLocked) {
        if ($returnHtml) {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Court Transmittal Letter Locked</title>
    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 80vh;
        }
        .lock-card {
            max-width: 540px;
            background: #ffffff;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 32px 28px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            text-align: center;
        }
        .icon {
            font-size: 2.8rem;
            margin-bottom: 12px;
            display: block;
        }
        h2 {
            margin: 0 0 10px 0;
            font-size: 1.25rem;
            color: #78350f;
            font-weight: 800;
        }
        p {
            font-size: 0.9rem;
            line-height: 1.55;
            color: #92400e;
            margin: 0 0 18px 0;
        }
        .countdown-chip {
            display: inline-block;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 9999px;
            padding: 8px 18px;
            font-weight: 700;
            font-size: 0.95rem;
            color: #b45309;
        }
        .meta-sub {
            font-size: 0.8rem;
            color: #a16207;
            margin-top: 14px;
        }
    </style>
</head>
<body>
    <div class="lock-card">
        <span class="icon">🔒</span>
        <h2>Court Transmittal Letter is Locked</h2>
        <p>
            Pursuant to <strong>Section 416 of Republic Act No. 7160</strong> (The Local Government Code of 1991), formal transmittal of the case records to the Metropolitan Trial Court is barred during the mandatory 10-business-day repudiation window.
        </p>
        <div class="countdown-chip">
            ' . $daysRemaining . ' business day' . ($daysRemaining === 1 ? '' : 's') . ' remaining
        </div>
        <div class="meta-sub">
            Statutory transmittal unlocks on <strong>' . htmlspecialchars($unlockDate) . '</strong> (excluding weekends and official Philippine holidays).
        </div>
    </div>
</body>
</html>';
            exit;
        }

        http_response_code(423); // 423 Locked
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'is_locked' => true,
            'working_days_remaining' => $daysRemaining,
            'unlock_date' => $unlockDate,
            'message' => "Court transmittal letter is locked. {$daysRemaining} business day(s) remaining before statutory transmittal unlocks on {$unlockDate}."
        ]);
        exit;
    }

    $pdfService = new PDFService();
    $output = $pdfService->generateTransmittalLetter($caseId, [
        'return_html' => $returnHtml
    ]);

    if ($returnHtml) {
        header('Content-Type: text/html; charset=UTF-8');
        echo $output;
        exit;
    }

    $caseStmt = $db->prepare("SELECT case_number FROM cases WHERE case_id = ?");
    $caseStmt->execute([$caseId]);
    $caseNumber = $caseStmt->fetchColumn() ?: "Case-{$caseId}";
    $filename = sprintf('Court-Transmittal-Letter-MTC-%s.pdf', preg_replace('/[^a-zA-Z0-9_-]/', '_', $caseNumber));

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $output;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Failed to generate transmittal letter: ' . $e->getMessage()
    ]);
    exit;
}
