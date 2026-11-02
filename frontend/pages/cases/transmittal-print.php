<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3, 4], true)) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/models/CaseTransmittal.php';
require_once __DIR__ . '/../../../backend/services/PDFService.php';

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$complaintId = filter_var($_GET['complaint_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$db = (new Database())->connect();

if (!$caseId && $complaintId) {
    $cStmt = $db->prepare("SELECT case_id FROM cases WHERE complaint_id = ? ORDER BY case_id DESC LIMIT 1");
    $cStmt->execute([$complaintId]);
    $caseId = (int) $cStmt->fetchColumn() ?: null;
}

if (!$caseId) {
    die("Invalid or missing case ID.");
}

$transmittalModel = new CaseTransmittal($db);
$transmittal = $transmittalModel->createOrGetTransmittal($caseId);

if (!$transmittal) {
    die("This case is not yet formally resolved. Court transmittal letter is unavailable.");
}

$details = $transmittalModel->getTransmittalFullDetails($caseId);
$tData = $details['transmittal'] ?? [];
$isLocked = false; // Always unlocked for immediate generation and filing per Lupon mandate
$daysRemaining = (int) ($tData['working_days_remaining'] ?? 0);
$unlockDate = $tData['unlock_date_formatted'] ?? ($tData['ten_day_expiry_date_formatted'] ?? 'N/A');

if ($isLocked) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Court Transmittal Letter Locked - Case #<?= htmlspecialchars($details['case']['case_number'] ?? '') ?></title>
        <style>
            body {
                font-family: system-ui, -apple-system, sans-serif;
                background: #f8fafc;
                color: #0f172a;
                margin: 0;
                padding: 50px 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 80vh;
            }
            .lock-card {
                max-width: 560px;
                background: #ffffff;
                border: 1px solid #fde68a;
                border-radius: 12px;
                padding: 34px 30px;
                box-shadow: 0 4px 18px rgba(0,0,0,0.06);
                text-align: center;
            }
            .countdown-badge {
                display: inline-block;
                background: #fef3c7;
                color: #92400e;
                border: 1px solid #fcd34d;
                padding: 8px 18px;
                border-radius: 9999px;
                font-weight: 700;
                font-size: 0.95rem;
                margin: 12px 0;
            }
        </style>
    </head>
    <body>
        <div class="lock-card">
            <div style="font-size: 3rem; margin-bottom: 12px;">🔒</div>
            <h2 style="margin: 0 0 10px; color: #78350f;">Court Transmittal Letter is Locked</h2>
            <p style="font-size: 0.92rem; line-height: 1.6; color: #92400e; margin: 0 0 16px;">
                Pursuant to <strong>Section 416 of Republic Act No. 7160</strong> (The Local Government Code of 1991), formal transmittal of the case records to the Metropolitan Trial Court is barred during the mandatory 10-working-day repudiation window.
            </p>
            <div class="countdown-badge">
                <?= $daysRemaining ?> working day<?= $daysRemaining === 1 ? '' : 's' ?> remaining
            </div>
            <p style="font-size: 0.82rem; color: #a16207; margin-top: 10px;">
                Statutory transmittal unlocks on <strong><?= htmlspecialchars($unlockDate) ?></strong> (excluding weekends and official Philippine holidays).
            </p>
            <div style="margin-top: 24px;">
                <button type="button" onclick="window.close()" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 8px 18px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                    Close Window
                </button>
            </div>
        </div>
    <script>
let isEditMode = false;
function toggleEditMode() {
    isEditMode = !isEditMode;
    const sheet = document.querySelector('.print-sheet-wrapper');
    const btn = document.getElementById('btnToggleEdit');
    if (isEditMode) {
        sheet.setAttribute('contenteditable', 'true');
        sheet.style.outline = '2px dashed #0284c7';
        btn.textContent = '💾 Finish Editing';
        btn.style.background = '#0284c7';
        btn.style.color = '#ffffff';
    } else {
        sheet.removeAttribute('contenteditable');
        sheet.style.outline = 'none';
        btn.textContent = '✏️ Edit Content';
        btn.style.background = '#e0f2fe';
        btn.style.color = '#0369a1';
    }
}

function toggleCaptainSignature(include) {
    const sigElements = document.querySelectorAll('.captain-signature-block, .punong-barangay-signature, [data-signature="captain"]');
    if (sigElements.length > 0) {
        sigElements.forEach(el => el.style.display = include ? '' : 'none');
    } else {
        const pTags = document.querySelectorAll('.print-sheet-wrapper p, .print-sheet-wrapper div');
        pTags.forEach(el => {
            if (el.textContent.includes('Punong Barangay')) {
                const parent = el.closest('td') || el;
                parent.style.opacity = include ? '1' : '0';
            }
        });
    }
}
</script>
</body>
    </html>
    <?php
    exit;
}

$pdfService = new PDFService();
$letterHtml = $pdfService->generateTransmittalLetter($caseId, ['return_html' => true]);
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <title>Court Transmittal Letter - <?= htmlspecialchars($details['case']['case_number'] ?? '') ?></title>
    <link rel="icon" href="../../assets/images/tumana_logo.jpg" type="image/jpeg">
    <style>
        .print-toolbar {
            max-width: 8.5in;
            margin: 16px auto 12px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 18px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            font-family: system-ui, -apple-system, sans-serif;
        }
        .btn-print {
            background: #0f766e;
            color: #ffffff;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-close {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
        }
        .print-sheet-wrapper {
            max-width: 8.5in;
            margin: 0 auto 30px auto;
            background: #ffffff;
            padding: 0.6in 0.75in;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        @media print {
            .print-toolbar {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                padding: 0 !important;
            }
            .print-sheet-wrapper {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }
            @page {
                size: A4 portrait;
                margin: 15mm 18mm 15mm 18mm;
            }
        }
    </style>
</head>
<body style="background: #f1f5f9; margin: 0; padding: 10px 0;">

    <div class="print-toolbar">
        <div style="font-weight: 700; color: #0f172a; font-size: 0.92rem;">
            Official Court Transmittal Letter &bull; <?= htmlspecialchars($details['case']['case_number'] ?? '') ?>
            <div style="font-size: 0.8rem; color: #b45309; font-weight: 600; margin-top: 2px;">
                ⏱️ <?= max(0, $daysRemaining) ?> working day<?= $daysRemaining === 1 ? '' : 's' ?> remaining in 10-day statutory filing window (MTC Marikina &amp; DILG)
            </div>
        </div>
        <div style="display: flex; gap: 10px;">
            <label style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; user-select: none;">
                <input type="checkbox" id="toggleCaptainSig" checked onchange="toggleCaptainSignature(this.checked)">
                <span>✍️ Include Captain Signature</span>
            </label>
            <button type="button" id="btnToggleEdit" onclick="toggleEditMode()" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 6px 12px; border-radius: 6px; font-size: 0.82rem; font-weight: 600; cursor: pointer;">
                ✏️ Edit Content
            </button>
            <button type="button" class="btn-print" onclick="window.print()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Print Transmittal Letter
            </button>
            <button type="button" class="btn-close" onclick="window.close()">Close</button>
        </div>
    </div>

    <div class="print-sheet-wrapper">
        <?php
        // Extract body content from $letterHtml
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $letterHtml, $matches)) {
            echo $matches[1];
        } else {
            echo $letterHtml;
        }
        ?>
    </div>

<script>
let isEditMode = false;
function toggleEditMode() {
    isEditMode = !isEditMode;
    const sheet = document.querySelector('.print-sheet-wrapper');
    const btn = document.getElementById('btnToggleEdit');
    if (isEditMode) {
        sheet.setAttribute('contenteditable', 'true');
        sheet.style.outline = '2px dashed #0284c7';
        btn.textContent = '💾 Finish Editing';
        btn.style.background = '#0284c7';
        btn.style.color = '#ffffff';
    } else {
        sheet.removeAttribute('contenteditable');
        sheet.style.outline = 'none';
        btn.textContent = '✏️ Edit Content';
        btn.style.background = '#e0f2fe';
        btn.style.color = '#0369a1';
    }
}

function toggleCaptainSignature(include) {
    const sigElements = document.querySelectorAll('.captain-signature-block, .punong-barangay-signature, [data-signature="captain"]');
    if (sigElements.length > 0) {
        sigElements.forEach(el => el.style.display = include ? '' : 'none');
    } else {
        const pTags = document.querySelectorAll('.print-sheet-wrapper p, .print-sheet-wrapper div');
        pTags.forEach(el => {
            if (el.textContent.includes('Punong Barangay')) {
                const parent = el.closest('td') || el;
                parent.style.opacity = include ? '1' : '0';
            }
        });
    }
}
</script>
</body>
</html>
