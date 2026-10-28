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
    $isRepudiated = !empty($tData['is_repudiated']);
    $daysRemaining = (int) ($tData['working_days_remaining'] ?? 0);
    $unlockDate = $tData['unlock_date_formatted'] ?? ($tData['ten_day_expiry_date_formatted'] ?? 'N/A');

    $pdfService = new PDFService();
    $output = $pdfService->generateTransmittalLetter($caseId, [
        'return_html' => $returnHtml
    ]);

    if ($returnHtml) {
        header('Content-Type: text/html; charset=UTF-8');

        if ($isRepudiated) {
            // Repudiated notice overlay
            $repudiatedBanner = '
            <div style="max-width: 850px; margin: 15px auto 20px; background: #fef2f2; border: 2px solid #f87171; border-radius: 10px; padding: 18px 22px; box-shadow: 0 4px 14px rgba(220, 38, 38, 0.12); text-align: center;">
                <div style="font-weight: 800; font-size: 1.05rem; color: #991b1b; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <span>⚠️</span>
                    <span>Amicable Settlement Repudiated (RA 7160 Sec. 416)</span>
                </div>
                <p style="margin: 8px 0 10px; font-size: 0.88rem; color: #7f1d1d; line-height: 1.5;">
                    This settlement was repudiated by party under sworn statement. Court transmittal of settlement is cancelled. The case has been routed for issuance of <strong>KP Form 20 (Certificate to File Action)</strong>.
                </p>
            </div>';

            $printLockCss = '
            <style>
                @media print {
                    body * { display: none !important; }
                    body::before {
                        content: "SETTLEMENT REPUDIATED: Transmittal of settlement records is cancelled pursuant to RA 7160 Sec. 416.";
                        display: block !important;
                        font-family: Arial, sans-serif;
                        color: #991b1b;
                        font-size: 16pt;
                        font-weight: bold;
                        text-align: center;
                        padding: 60px 40px;
                        border: 3px dashed #dc2626;
                        margin: 40px auto;
                    }
                }
            </style>';

            // Inject after <body>
            $output = preg_replace('/<body([^>]*)>/i', '<body$1>' . $repudiatedBanner . $printLockCss, $output);
            echo $output;
            exit;
        }

        if ($isLocked) {
            // Statutory Repudiation Window Active: Strict print protection during 10-business-day window
            $printLockCss = '
            <style id="statutoryPrintLockCss">
                @media print {
                    body * {
                        display: none !important;
                        visibility: hidden !important;
                    }
                    html, body {
                        background: #ffffff !important;
                        height: auto !important;
                        overflow: visible !important;
                    }
                    body::before {
                        content: "🔒 OFFICIAL COURT TRANSMITTAL IS LOCKED PURSUANT TO RA 7160 SEC. 416\A\A The mandatory 10-business-day repudiation window is currently active (' . $daysRemaining . ' business day(s) remaining, unlocking on ' . addslashes($unlockDate) . ').\A\A OFFICIAL TRANSMITTAL DISPATCH AND PHYSICAL PRINTING ARE STRICTLY PROHIBITED BY STATUTORY LAW.";
                        white-space: pre-wrap !important;
                        display: block !important;
                        font-family: Arial, sans-serif !important;
                        color: #991b1b !important;
                        font-size: 15pt !important;
                        font-weight: 800 !important;
                        text-align: center !important;
                        padding: 60px 40px !important;
                        border: 4px dashed #dc2626 !important;
                        margin: 60px auto !important;
                        max-width: 650px !important;
                        line-height: 1.7 !important;
                        background: #fef2f2 !important;
                    }
                }
            </style>';

            // Inject inside body
            $output = preg_replace('/<body([^>]*)>/i', '<body$1>' . $printLockCss, $output);
        }

        echo $output;
        exit;
    }

    // When PDF download or file dispatch is requested while locked:
    if ($isLocked || $isRepudiated) {
        http_response_code(423); // 423 Locked
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'is_locked' => true,
            'is_repudiated' => $isRepudiated,
            'working_days_remaining' => $daysRemaining,
            'unlock_date' => $unlockDate,
            'message' => $isRepudiated
                ? 'Court transmittal is cancelled because the settlement was repudiated under RA 7160 Sec. 416.'
                : "Court transmittal letter is locked pursuant to RA 7160 Sec. 416. {$daysRemaining} business day(s) remaining before official dispatch unlocks on {$unlockDate}."
        ]);
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