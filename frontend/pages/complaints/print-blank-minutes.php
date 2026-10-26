<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['role_id'] ?? 0), [1, 2, 3, 4], true)) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';

$complaintId = filter_var($_GET['id'] ?? $_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$db = (new Database())->connect();

$caseNumber = '____________________';
$caseFiled = '____________________';
$caseTitle = '________________________________________';
$complainants = '____________________';
$respondents = '____________________';

if ($caseId && !$complaintId) {
    $cStmt = $db->prepare("SELECT complaint_id, case_number, created_at FROM cases WHERE case_id = ? LIMIT 1");
    $cStmt->execute([$caseId]);
    $caseRow = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($caseRow) {
        $complaintId = (int)$caseRow['complaint_id'];
        $caseNumber = $caseRow['case_number'];
        $caseFiled = date('F j, Y', strtotime($caseRow['created_at']));
    }
}

if ($complaintId) {
    $stmt = $db->prepare("
        SELECT c.*,
               co.case_id, co.case_number, co.created_at AS case_created_at
        FROM complaints c
        LEFT JOIN cases co ON co.complaint_id = c.complaint_id
        WHERE c.complaint_id = ?
        LIMIT 1
    ");
    $stmt->execute([$complaintId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($c) {
        $caseNumber = $c['case_number'] ?: ($c['complaint_number'] ?: 'KP-' . str_pad((string)$complaintId, 4, '0', STR_PAD_LEFT));
        $dateFiled = !empty($c['case_created_at']) ? $c['case_created_at'] : $c['created_at'];
        $caseFiled = $dateFiled ? date('F j, Y', strtotime($dateFiled)) : '____________________';
        $caseTitle = $c['complaint_title'] ?: 'Barangay Dispute Resolution';

        $pStmt = $db->prepare("
            SELECT cp.party_type,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, COALESCE(cp.suffix, r.suffix))) AS full_name
            FROM complaint_parties cp
            INNER JOIN residents r ON r.resident_id = cp.resident_id
            WHERE cp.complaint_id = ?
            ORDER BY cp.party_type ASC, cp.party_id ASC
        ");
        $pStmt->execute([$complaintId]);
        $parties = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $cList = [];
        $rList = [];
        foreach ($parties as $p) {
            if ($p['party_type'] === 'Complainant') {
                $cList[] = $p['full_name'];
            } else {
                $rList[] = $p['full_name'];
            }
        }
        if (!empty($cList)) $complainants = implode(', ', $cList);
        if (!empty($rList)) $respondents = implode(', ', $rList);
    }
}

// Check logo image files
$tumanaLogoPath = __DIR__ . '/../../assets/images/tumana_logo.jpg';
$bagongLogoPath = __DIR__ . '/../../assets/images/bagong_pilipinas_logo.png';
$hasTumanaLogo = file_exists($tumanaLogoPath);
$hasBagongLogo = file_exists($bagongLogoPath);
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Katitikan ng Pagdinig (Blank Minutes) - <?= htmlspecialchars($caseNumber) ?></title>
    <link rel="icon" href="../../assets/images/tumana_logo.jpg" type="image/jpeg">
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            margin: 0;
            padding: 20px 0;
            line-height: 1.35;
        }
        .action-bar {
            max-width: 8.5in;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 18px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .action-bar .title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e3a8a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-bar .btn-group {
            display: flex;
            gap: 10px;
        }
        .btn-print {
            background: #1e40af;
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
            text-decoration: none;
        }
        .sheet-container {
            width: 8.5in;
            min-height: 11in;
            margin: 0 auto;
            background: #ffffff;
            padding: 0.5in 0.75in 0.5in 0.75in;
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            position: relative;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .header-logo-left {
            width: 75px;
            text-align: left;
            vertical-align: top;
        }
        .header-logo-right {
            width: 75px;
            text-align: right;
            vertical-align: top;
        }
        .header-logo-img {
            width: 68px;
            height: 68px;
            object-fit: contain;
        }
        .header-center-text {
            text-align: center;
            line-height: 1.25;
            vertical-align: top;
        }
        .header-center-text .rep-title {
            font-size: 9pt;
            letter-spacing: 0.5px;
            color: #1e293b;
        }
        .header-center-text .brgy-title {
            font-size: 12.5pt;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 0.5px;
            margin-top: 1px;
        }
        .header-center-text .city-title {
            font-size: 9pt;
            font-weight: bold;
            color: #1e293b;
            margin-top: 1px;
        }
        .header-center-text .addr-title {
            font-size: 7.8pt;
            color: #475569;
            margin-top: 1px;
        }
        .header-center-text .tel-title {
            font-size: 7.8pt;
            color: #475569;
            margin-top: 1px;
        }
        .header-center-text .office-title {
            font-size: 9.5pt;
            font-weight: bold;
            color: #1e3a8a;
            margin-top: 2px;
        }
        .header-center-text .ooo {
            font-size: 8pt;
            color: #64748b;
            margin-top: 1px;
        }
        .minutes-heading {
            font-size: 11pt;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 6px 0 8px 0;
            padding: 4px 0;
            border-top: 1.5px solid #0f172a;
            border-bottom: 1.5px solid #0f172a;
        }
        .particulars-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            line-height: 1.5;
            margin-bottom: 10px;
        }
        .particulars-table td {
            padding: 2px 4px;
            vertical-align: top;
            color: #0f172a;
        }
        .particulars-table strong {
            color: #0f172a;
        }
        .ruled-section {
            margin: 8px 0 0 0;
            border-top: 1.2px solid #0f172a;
        }
        .ruled-line {
            height: 25px;
            border-bottom: 1px solid #94a3b8;
            width: 100%;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
                color: #000000;
            }
            .action-bar {
                display: none !important;
            }
            .sheet-container {
                width: 100% !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }
            .minutes-heading {
                border-color: #000000 !important;
            }
            .ruled-section {
                border-top-color: #000000 !important;
            }
            .ruled-line {
                border-bottom: 1px solid #64748b !important;
            }
            @page {
                size: letter portrait;
                margin: 10mm 15mm 10mm 15mm;
            }
        }
    </style>
</head>
<body>

    <!-- On-screen Action Toolbar -->
    <div class="action-bar">
        <div class="title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>
            <span>Official Blank Minutes of Meeting Sheet &bull; <?= htmlspecialchars($caseNumber) ?></span>
        </div>
        <div class="btn-group">
            <button type="button" class="btn-print" onclick="window.print()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Print Blank Minutes Sheet
            </button>
            <button type="button" class="btn-close" onclick="window.close()">Close</button>
        </div>
    </div>

    <!-- Printable Paper Sheet (Exact Template Layout with Official Tumana Banner Head) -->
    <div class="sheet-container">
        <!-- Official Banner Head -->
        <table class="header-table">
            <tr>
                <td class="header-logo-left">
                    <?php if ($hasTumanaLogo): ?>
                        <img src="../../assets/images/tumana_logo.jpg" class="header-logo-img" alt="Barangay Tumana Logo">
                    <?php endif; ?>
                </td>
                <td class="header-center-text">
                    <div class="rep-title">Republika ng Pilipinas</div>
                    <div class="brgy-title">BARANGAY TUMANA</div>
                    <div class="city-title">CITY OF MARIKINA</div>
                    <div class="addr-title">ROAD 1 EXTENSION DOÑA PETRA , BARANGAY TUMANA, MARIKINA CITY</div>
                    <div class="tel-title">Tel. No. 02-8457-2818</div>
                    <div class="office-title">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
                    <div class="ooo">— oOo —</div>
                </td>
                <td class="header-logo-right">
                    <?php if ($hasBagongLogo): ?>
                        <img src="../../assets/images/bagong_pilipinas_logo.png" class="header-logo-img" alt="Bagong Pilipinas Logo">
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- Document Heading (As is provided in template) -->
        <div class="minutes-heading">
            MINUTES OF <?= htmlspecialchars($respondents) ?> vs. <?= htmlspecialchars($complainants) ?>
        </div>

        <!-- Case Particulars Grid (Exact fields from template) -->
        <table class="particulars-table">
            <tr>
                <td style="width: 50%;"><strong>CASE NO:</strong> &nbsp;<?= htmlspecialchars($caseNumber) ?></td>
                <td style="width: 50%;"><strong>CASE FILED:</strong> &nbsp;<?= htmlspecialchars($caseFiled) ?></td>
            </tr>
            <tr>
                <td colspan="2"><strong>CASE TITLE:</strong> &nbsp;<?= htmlspecialchars($caseTitle) ?></td>
            </tr>
            <tr>
                <td><strong>DATE/TIME:</strong> ____________________</td>
                <td><strong>CASE HEARING NO.:</strong> ____________________</td>
            </tr>
            <tr>
                <td><strong>LUPON:</strong> ____________________</td>
                <td><strong>PAHINA:</strong> ____________________</td>
            </tr>
        </table>

        <!-- Ruled Writing Lines (22 lines for handwriting) -->
        <div class="ruled-section">
            <?php for ($i = 1; $i <= 22; $i++): ?>
                <div class="ruled-line"></div>
            <?php endfor; ?>
        </div>
    </div>

    <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] == '1'): ?>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            window.print();
        });
    </script>
    <?php endif; ?>
</body>
</html>
