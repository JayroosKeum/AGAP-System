<?php
/**
 * GET /backend/api/reports/council_brief.php
 *
 * Dedicated 1-Page Printable Monthly Barangay Council Assembly Executive Summary Brief.
 * Formally styled as an Official Sangguniang Barangay / DILG-Compliant Executive Memorandum
 * with official Barangay Tumana and Marikina City seals.
 */

declare(strict_types=1);

session_start();
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3, 5], true)) {
    http_response_code(403);
    die('Access Denied');
}

require_once __DIR__ . '/../../controllers/ReportController.php';
require_once __DIR__ . '/../../models/Setting.php';
require_once __DIR__ . '/../../services/DocxTemplateService.php';
require_once __DIR__ . '/../../config/database.php';

$year  = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');
$month = filter_var($_GET['month'] ?? date('n'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: (int) date('n');

$startDate = !empty($_GET['start_date']) ? $_GET['start_date'] : sprintf('%04d-%02d-01', $year, $month);
$endDate   = !empty($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-t', strtotime($startDate));

$monthTimestamp = strtotime($startDate);
$periodLabel = !empty($_GET['period_label'])
    ? trim((string) $_GET['period_label'])
    : date('F Y', $monthTimestamp);
$quarterNum = (int) ceil(((int) date('n', $monthTimestamp)) / 3);

// Load Barangay & Official Signatories from system_settings & shared branding
$settingModel = new Setting();
$sysSettings  = $settingModel->getAllSettings();
$branding     = DocxTemplateService::getSharedBrandingDefaults();

$barangay    = !empty($sysSettings['barangay_name']) ? $sysSettings['barangay_name'] : 'Barangay Tumana';
$city        = !empty($sysSettings['city_name']) ? $sysSettings['city_name'] : 'City of Marikina';
$province    = !empty($sysSettings['province_name']) ? $sysSettings['province_name'] : 'Metro Manila · National Capital Region';
$address     = !empty($sysSettings['barangay_hall_address']) ? $sysSettings['barangay_hall_address'] : ($branding['address'] ?? 'Barangay Hall, Tumana, Marikina City, Metro Manila');
$pbName      = !empty($sysSettings['captain_name']) ? $sysSettings['captain_name'] : 'HON. AKIKO LYNNE CENTENO';
$luponSec    = !empty($sysSettings['lupon_secretary_name']) ? $sysSettings['lupon_secretary_name'] : 'PAMELA CONSULTA';
$brgySec     = !empty($sysSettings['barangay_secretary_name']) ? $sysSettings['barangay_secretary_name'] : 'PAMELA CONSULTA';

// Resolve Official Logos (Base64 Data URIs with relative URL fallback)
$tumanaLogoUri   = DocxTemplateService::getLogoDataUri('tumana_logo.jpg', 'tumana_logo.jpg') ?: '../../../frontend/assets/images/tumana_logo.jpg';
$marikinaLogoUri = DocxTemplateService::getLogoDataUri('marikina_logo.jpg', 'marikina_logo.jpg') ?: '../../../frontend/assets/images/marikina_logo.jpg';

// Fetch Case Register & Analytics via Report model
$reportModel = new Report();
$cases       = $reportModel->getCaseRegister($startDate, $endDate);
$analytics   = $reportModel->getAnalyticsSummary($startDate, $endDate);

$isBaselineAnalytics = false;
if (empty($analytics['top_categories']) || array_sum(array_column($analytics['top_categories'], 'total')) === 0) {
    $analytics = $reportModel->getAnalyticsSummary(null, null);
    $isBaselineAnalytics = true;
}

// Query Stage 0 (Paanyaya) & Intake metrics for the period
$db = (new Database())->connect();
$totalComplaintsFiled = 0;
$paanyayaIssued       = 0;
$paanyayaSettled      = 0;
$paanyayaPending      = 0;

try {
    $stmtFiled = $db->prepare("SELECT COUNT(*) FROM complaints WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmtFiled->execute([$startDate, $endDate]);
    $totalComplaintsFiled = (int) $stmtFiled->fetchColumn();

    $stmtPaa = $db->prepare("SELECT COUNT(DISTINCT complaint_id) FROM paanyayas WHERE scheduled_date BETWEEN ? AND ?");
    $stmtPaa->execute([$startDate, $endDate]);
    $paanyayaIssued = (int) $stmtPaa->fetchColumn();

    $stmtPaaSettled = $db->prepare(
        "SELECT COUNT(*) FROM complaints
         WHERE (status IN ('Settled', 'Resolved at Paanyaya / Closed') OR paanyaya_1_outcome = 'Settled')
           AND DATE(created_at) BETWEEN ? AND ?"
    );
    $stmtPaaSettled->execute([$startDate, $endDate]);
    $paanyayaSettled = (int) $stmtPaaSettled->fetchColumn();

    $stmtPaaPending = $db->prepare(
        "SELECT COUNT(*) FROM complaints
         WHERE status = 'Pre-Docketed (Paanyaya)'
           AND DATE(created_at) BETWEEN ? AND ?"
    );
    $stmtPaaPending->execute([$startDate, $endDate]);
    $paanyayaPending = (int) $stmtPaaPending->fetchColumn();
} catch (Throwable $e) {
    // Graceful fallback
}

// Compute Formal Docketed KP Case Metrics
$civil = 0;
$criminal = 0;
$others = 0;

$settledMediation = 0;
$settledConciliation = 0;
$settledArbitration = 0;

$repudiated = 0;
$dropped = 0;
$pending = 0;
$dismissed = 0;
$cfaIssued = 0;
$referred = 0;

$slaOnTime = 0;
$slaDelayed = 0;
$slaNotServed = 0;

foreach ($cases as $c) {
    $type = strtolower((string) ($c['case_type'] ?? ''));
    if ($type === 'civil') $civil++;
    elseif ($type === 'criminal') $criminal++;
    else $others++;

    $status = strtolower((string) ($c['case_status'] ?? ''));
    $hasSettlement = !empty($c['settlement_date']);
    $hasCfa = !empty($c['cfa_issuance_date']);
    $concilSessions = (int) ($c['conciliation_sessions'] ?? 0);

    if ($status === 'settled' || $hasSettlement) {
        if ($concilSessions > 0) {
            $settledConciliation++;
        } else {
            $settledMediation++;
        }
    } elseif ($status === 'arbitration' || !empty($c['award_date'])) {
        $settledArbitration++;
    } elseif (str_contains($status, 'repudiat')) {
        $repudiated++;
    } elseif (str_contains($status, 'drop') || str_contains($status, 'withdraw')) {
        $dropped++;
    } elseif (str_contains($status, 'dismiss')) {
        $dismissed++;
    } elseif ($status === 'cfa issued' || $hasCfa) {
        $cfaIssued++;
    } elseif (str_contains($status, 'refer')) {
        $referred++;
    } else {
        $pending++;
    }

    $sla = $c['summons_timeliness'] ?? '';
    if ($sla === 'On Time') $slaOnTime++;
    elseif ($sla === 'Delayed') $slaDelayed++;
    else $slaNotServed++;
}

$totalDocketed  = count($cases);
$totalIntakeAll = max($totalComplaintsFiled, $totalDocketed + $paanyayaSettled + $paanyayaPending);
$totalSettledFormal = $settledMediation + $settledConciliation + $settledArbitration;
$totalResolvedAll   = $totalSettledFormal + $paanyayaSettled;

$settlementRateFormal = $totalDocketed > 0 ? round(($totalSettledFormal / $totalDocketed) * 100, 1) : 0;
$overallResolutionRate = $totalIntakeAll > 0 ? round(($totalResolvedAll / $totalIntakeAll) * 100, 1) : $settlementRateFormal;

$savingsPerCase = 9500.00;
$totalSavingsWithPaanyaya = $totalResolvedAll * $savingsPerCase;

$totalServedSummons = $slaOnTime + $slaDelayed;
$slaComplianceRate = $totalServedSummons > 0 ? round(($slaOnTime / $totalServedSummons) * 100, 1) : 100;

$topCategories = array_slice($analytics['top_categories'] ?? [], 0, 5);
$purokHotspots = array_slice($analytics['purok_hotspots'] ?? [], 0, 5);
$genderData    = $analytics['gender_breakdown'] ?? ['Male' => 0, 'Female' => 0, 'Other' => 0];
$ageData       = $analytics['age_distribution'] ?? ['minors' => 0, 'youth' => 0, 'adults' => 0, 'seniors' => 0, 'total' => 0];

$topCategoryName = !empty($topCategories[0]['display_name']) ? $topCategories[0]['display_name'] : (!empty($topCategories[0]['category_name']) ? $topCategories[0]['category_name'] : 'Civil / Neighborhood Disputes');
$topPurokName = !empty($purokHotspots[0]['purok_name']) ? $purokHotspots[0]['purok_name'] : 'Barangay Tumana';

$priorityCases = array_slice(array_reverse($cases), 0, 5);

$firstMondayTs = strtotime('first monday of ' . date('F Y', $monthTimestamp));
$assemblyDateFormatted = date('F j, Y', $firstMondayTs ?: time());
$printedAt = date('F j, Y \a\t h:i A');
$controlNumber = sprintf('SB-KP-%04d-%02d-Q%d', $year, $month, $quarterNum);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Barangay Council Assembly Brief – <?php echo htmlspecialchars($periodLabel); ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.5pt;
            line-height: 1.3;
            color: #111827;
            background: #cbd5e1;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Screen Toolbar */
        .no-print-toolbar {
            position: sticky;
            top: 0;
            z-index: 999;
            background: #0f172a;
            color: #ffffff;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.25);
        }
        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .toolbar-badge {
            background: #1e3a8a;
            border: 1px solid #3b82f6;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 4px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .toolbar-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #f8fafc;
        }
        .toolbar-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .toolbar-select {
            background: #1e293b;
            color: #f8fafc;
            border: 1px solid #475569;
            border-radius: 5px;
            padding: 6px 10px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-toolbar {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 5px;
            font-size: 0.82rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-toolbar-print {
            background: #1e3a8a;
            color: #ffffff;
            border: 1px solid #3b82f6;
        }
        .btn-toolbar-print:hover {
            background: #1d4ed8;
        }
        .btn-toolbar-secondary {
            background: #334155;
            color: #f8fafc;
        }
        .btn-toolbar-secondary:hover {
            background: #475569;
        }

        /* 1-Page Formal Executive Sheet */
        .council-brief-sheet {
            position: relative;
            background: #ffffff;
            width: 210mm;
            min-height: 280mm;
            max-width: 100%;
            margin: 18px auto 28px;
            padding: 10mm 12mm;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.25);
            border: 1px solid #94a3b8;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        /* Subtle Official Seal Watermark */
        .sheet-watermark {
            position: absolute;
            top: 52%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 310px;
            height: 310px;
            opacity: 0.035;
            pointer-events: none;
            user-select: none;
            z-index: 0;
            object-fit: contain;
        }
        .sheet-content-layer {
            position: relative;
            z-index: 1;
        }

        /* Formal Philippine Government Letterhead */
        .gov-letterhead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 7px;
            border-bottom: 3px double #0f2547;
        }
        .gov-seal {
            width: 64px;
            height: 64px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .gov-header-text {
            text-align: center;
            flex: 1;
            font-family: 'Times New Roman', Georgia, serif;
            color: #0f172a;
            line-height: 1.18;
        }
        .gov-header-text .rep-line {
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .gov-header-text .city-line {
            font-size: 10pt;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #1e293b;
        }
        .gov-header-text .brgy-line {
            font-size: 13pt;
            font-weight: 900;
            letter-spacing: 0.05em;
            color: #0f2547;
            text-transform: uppercase;
            margin: 1px 0;
        }
        .gov-header-text .office-line {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.2pt;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #1e3a8a;
            text-transform: uppercase;
            margin-top: 2px;
        }
        .gov-header-text .addr-line {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7.2pt;
            color: #475569;
            margin-top: 1px;
        }

        /* Formal Memorandum Header Block */
        .memo-title-banner {
            margin-top: 6px;
            background: #0f2547;
            color: #ffffff;
            text-align: center;
            padding: 4.5px 10px;
            font-size: 8.8pt;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            border: 1px solid #0f2547;
        }
        .memo-meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
            margin-bottom: 6px;
            font-size: 7.6pt;
            border: 1px solid #94a3b8;
        }
        .memo-meta-table td {
            padding: 3px 7px;
            border: 1px solid #cbd5e1;
        }
        .memo-meta-label {
            font-weight: 800;
            color: #0f2547;
            text-transform: uppercase;
            width: 13%;
            background: #f1f5f9;
            white-space: nowrap;
        }
        .memo-meta-val {
            color: #0f172a;
            width: 37%;
        }

        /* Section Headings */
        .sec-heading {
            font-size: 8pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #0f2547;
            background: #f1f5f9;
            border: 1px solid #94a3b8;
            border-left: 4px solid #0f2547;
            padding: 3px 7px;
            margin: 6px 0 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sec-heading span.sec-sub {
            font-size: 7pt;
            font-weight: 600;
            color: #334155;
            text-transform: none;
            letter-spacing: 0;
        }

        /* 6-Box Formal Executive KPI Matrix */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 5px;
            margin-bottom: 6px;
        }
        .kpi-card {
            border: 1px solid #94a3b8;
            padding: 5px 5px;
            background: #ffffff;
            text-align: center;
        }
        .kpi-card.highlight-formal {
            background: #f8fafc;
            border-top: 2.5px solid #0f2547;
        }
        .kpi-val {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 13pt;
            font-weight: 800;
            color: #0f2547;
            line-height: 1.05;
        }
        .kpi-card.savings-cell .kpi-val {
            font-size: 10.5pt;
            color: #14532d;
        }
        .kpi-lbl {
            font-size: 6.6pt;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            margin-top: 2px;
            line-height: 1.15;
        }

        /* Two-Column Council Intelligence Grid */
        .two-col-grid {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 8px;
            margin-bottom: 5px;
        }
        .panel-box {
            border: 1px solid #94a3b8;
            padding: 5px 7px;
            background: #ffffff;
        }
        .panel-subtitle {
            font-size: 7.5pt;
            font-weight: 800;
            text-transform: uppercase;
            color: #0f2547;
            border-bottom: 1.5px solid #0f2547;
            padding-bottom: 2px;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .panel-subtitle small {
            font-size: 6.7pt;
            font-weight: 600;
            color: #475569;
            text-transform: none;
        }

        /* Formal Government Tables */
        table.brief-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.4pt;
            border: 1px solid #cbd5e1;
        }
        table.brief-table th {
            background: #e2e8f0;
            color: #0f172a;
            font-weight: 800;
            text-align: left;
            padding: 3px 5px;
            border: 1px solid #94a3b8;
            font-size: 6.9pt;
            text-transform: uppercase;
        }
        table.brief-table td {
            padding: 2.8px 5px;
            border: 1px solid #cbd5e1;
            color: #111827;
        }
        table.brief-table tr:nth-child(even) td {
            background: #f8fafc;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* Mini SLA & Demographics Table */
        .mini-stat-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            margin-top: 4px;
        }
        .mini-stat-pill {
            border: 1px solid #94a3b8;
            padding: 3px 4px;
            text-align: center;
            background: #f8fafc;
        }
        .mini-stat-pill strong {
            display: block;
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 9.5pt;
            color: #0f2547;
            line-height: 1.05;
        }
        .mini-stat-pill span {
            font-size: 6.4pt;
            color: #334155;
            font-weight: 700;
            text-transform: uppercase;
        }

        /* Executive Narrative Callout */
        .executive-synthesis-box {
            background: #f8fafc;
            border: 1px solid #64748b;
            border-left: 4px solid #0f2547;
            padding: 5.5px 8px;
            margin: 5px 0;
            font-size: 7.5pt;
            color: #0f172a;
            line-height: 1.35;
            text-align: justify;
        }
        .executive-synthesis-box strong {
            color: #0f2547;
        }

        /* Formal Certification & Signatories */
        .certification-statement {
            font-family: 'Times New Roman', Georgia, serif;
            font-size: 7.8pt;
            font-style: italic;
            color: #1e293b;
            margin-top: 4px;
            margin-bottom: 10px;
            text-align: center;
        }
        .signatories-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-top: 4px;
        }
        .sig-block {
            text-align: center;
            font-size: 7.5pt;
        }
        .sig-role-caption {
            font-size: 6.7pt;
            color: #334155;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 18px;
            text-align: left;
        }
        .sig-line {
            border-top: 1.2px solid #0f172a;
            padding-top: 3px;
            font-family: 'Times New Roman', Georgia, serif;
            font-weight: 800;
            font-size: 8.5pt;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .sig-sub {
            font-size: 6.9pt;
            color: #334155;
            font-weight: 700;
        }

        /* Footer */
        .brief-footer {
            margin-top: 6px;
            padding-top: 4px;
            border-top: 1.5px solid #0f2547;
            display: flex;
            justify-content: space-between;
            font-size: 6.7pt;
            color: #334155;
            font-weight: 600;
        }

        @media print {
            body {
                background: #ffffff;
            }
            .no-print-toolbar {
                display: none !important;
            }
            .council-brief-sheet {
                width: 100%;
                min-height: auto;
                margin: 0;
                padding: 0;
                border: none;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

<!-- Screen-Only Action & Period Selector Bar -->
<div class="no-print-toolbar">
    <div class="toolbar-left">
        <span class="toolbar-badge">Official 1-Page Executive Brief</span>
        <span class="toolbar-title">Sangguniang Barangay Monthly Assembly Report — <?php echo htmlspecialchars($periodLabel); ?></span>
    </div>
    <form method="GET" action="council_brief.php" class="toolbar-controls">
        <select name="month" class="toolbar-select" aria-label="Select Month" onchange="this.form.submit()">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?php echo $m; ?>" <?php echo $m === (int) $month ? 'selected' : ''; ?>>
                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                </option>
            <?php endfor; ?>
        </select>
        <select name="year" class="toolbar-select" aria-label="Select Year" onchange="this.form.submit()">
            <?php for ($y = (int) date('Y') + 1; $y >= 2023; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $y === (int) $year ? 'selected' : ''; ?>>
                    <?php echo $y; ?>
                </option>
            <?php endfor; ?>
        </select>
        <button type="button" class="btn-toolbar btn-toolbar-print" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Print 1-Page Brief / Save PDF
        </button>
        <button type="button" class="btn-toolbar btn-toolbar-secondary" onclick="window.close()">
            Close
        </button>
    </form>
</div>

<div class="council-brief-sheet">
    <!-- Subtle Official Barangay Tumana Seal Watermark -->
    <img src="<?php echo htmlspecialchars($tumanaLogoUri); ?>" alt="" class="sheet-watermark" aria-hidden="true">

    <div class="sheet-content-layer">
        <!-- OFFICIAL GOVERNMENT LETTERHEAD WITH BARANGAY TUMANA & MARIKINA CITY SEALS -->
        <header class="gov-letterhead">
            <img src="<?php echo htmlspecialchars($tumanaLogoUri); ?>" alt="Barangay Tumana Official Seal" class="gov-seal">
            <div class="gov-header-text">
                <div class="rep-line">Republic of the Philippines</div>
                <div class="city-line"><?php echo htmlspecialchars($city); ?> &bull; <?php echo htmlspecialchars($province); ?></div>
                <div class="brgy-line"><?php echo htmlspecialchars($barangay); ?></div>
                <div class="office-line">Office of the Sangguniang Barangay &amp; Lupong Tagapamayapa</div>
                <div class="addr-line"><?php echo htmlspecialchars($address); ?></div>
            </div>
            <img src="<?php echo htmlspecialchars($marikinaLogoUri); ?>" alt="City of Marikina Official Seal" class="gov-seal">
        </header>

        <!-- FORMAL MEMORANDUM TITLE & METADATA TABLE -->
        <div class="memo-title-banner">
            Monthly Barangay Council Assembly — Katarungang Pambarangay Executive Brief
        </div>
        <table class="memo-meta-table">
            <tr>
                <td class="memo-meta-label">FOR:</td>
                <td class="memo-meta-val"><strong>The Honorable Sangguniang Barangay</strong> (Regular Monthly Council Session)</td>
                <td class="memo-meta-label">CONTROL NO.:</td>
                <td class="memo-meta-val"><strong><?php echo htmlspecialchars($controlNumber); ?></strong></td>
            </tr>
            <tr>
                <td class="memo-meta-label">FROM:</td>
                <td class="memo-meta-val"><strong><?php echo htmlspecialchars($pbName); ?></strong>, Punong Barangay &amp; Lupon Chairperson</td>
                <td class="memo-meta-label">PERIOD COVERED:</td>
                <td class="memo-meta-val"><strong><?php echo htmlspecialchars(strtoupper($periodLabel)); ?></strong> (CY <?php echo (int) $year; ?> &bull; Q<?php echo $quarterNum; ?>)</td>
            </tr>
            <tr>
                <td class="memo-meta-label">SUBJECT:</td>
                <td class="memo-meta-val">Monthly Case Disposition, LTIA Court Savings &amp; Peace and Order Intelligence</td>
                <td class="memo-meta-label">ASSEMBLY DATE:</td>
                <td class="memo-meta-val"><?php echo htmlspecialchars($assemblyDateFormatted); ?> (1st Monday Session)</td>
            </tr>
        </table>

        <!-- SECTION I: EXECUTIVE KPI STRIP -->
        <div class="sec-heading">
            <span>I. Executive Performance &amp; Judiciary Decongestion Summary</span>
            <span class="sec-sub">Pursuant to Sec. 399–422, R.A. No. 7160 (Local Government Code of 1991) &amp; DILG LTIA Criteria</span>
        </div>
        <div class="kpi-strip">
            <div class="kpi-card highlight-formal">
                <div class="kpi-val"><?php echo (int) $totalIntakeAll; ?></div>
                <div class="kpi-lbl">Total Grievances<br>&amp; Intakes</div>
            </div>
            <div class="kpi-card highlight-formal">
                <div class="kpi-val"><?php echo (int) $paanyayaSettled; ?></div>
                <div class="kpi-lbl">Resolved at<br>Paanyaya (Stage 0)</div>
            </div>
            <div class="kpi-card highlight-formal">
                <div class="kpi-val"><?php echo (int) $totalDocketed; ?></div>
                <div class="kpi-lbl">Formal KP Cases<br>Docketed</div>
            </div>
            <div class="kpi-card highlight-formal">
                <div class="kpi-val"><?php echo (int) $totalSettledFormal; ?></div>
                <div class="kpi-lbl">Amicably Settled<br>(Formal Docket)</div>
            </div>
            <div class="kpi-card highlight-formal">
                <div class="kpi-val"><?php echo $overallResolutionRate; ?>%</div>
                <div class="kpi-lbl">Overall Dispute<br>Resolution Rate</div>
            </div>
            <div class="kpi-card highlight-formal savings-cell">
                <div class="kpi-val">PHP <?php echo number_format($totalSavingsWithPaanyaya, 0); ?></div>
                <div class="kpi-lbl">Est. Judiciary<br>Savings (₱9.5k/case)</div>
            </div>
        </div>

        <!-- SECTION II: TWO-COLUMN COUNCIL BRIEFING GRID -->
        <div class="two-col-grid">
            <!-- Left Column: Stage Disposition & Summons SLA -->
            <div class="panel-box">
                <div class="panel-subtitle">
                    <span>II. Adjudication &amp; Settlement Disposition Matrix</span>
                    <small>Civil: <?php echo $civil; ?> | Crim: <?php echo $criminal; ?> | Others: <?php echo $others; ?></small>
                </div>
                <table class="brief-table">
                    <thead>
                        <tr>
                            <th>Adjudication / Settlement Stage</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Stage 0: Paanyaya</strong> (Pre-Docketing Dialogue Resolved)</td>
                            <td class="text-right"><strong><?php echo (int) $paanyayaSettled; ?></strong></td>
                            <td class="text-right"><?php echo $totalIntakeAll > 0 ? round(($paanyayaSettled / $totalIntakeAll) * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>Stage 1: Punong Barangay Mediation</strong> (KP Form 16)</td>
                            <td class="text-right"><strong><?php echo (int) $settledMediation; ?></strong></td>
                            <td class="text-right"><?php echo $totalDocketed > 0 ? round(($settledMediation / $totalDocketed) * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>Stage 2: Pangkat Conciliation</strong> (3-Member Panel)</td>
                            <td class="text-right"><strong><?php echo (int) $settledConciliation; ?></strong></td>
                            <td class="text-right"><?php echo $totalDocketed > 0 ? round(($settledConciliation / $totalDocketed) * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>Stage 3: Arbitration Award</strong> (KP Form 15)</td>
                            <td class="text-right"><strong><?php echo (int) $settledArbitration; ?></strong></td>
                            <td class="text-right"><?php echo $totalDocketed > 0 ? round(($settledArbitration / $totalDocketed) * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>CFA Issued (KP Form 20)</strong> / Certified for Court Action</td>
                            <td class="text-right"><?php echo (int) $cfaIssued; ?></td>
                            <td class="text-right"><?php echo $totalDocketed > 0 ? round(($cfaIssued / $totalDocketed) * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>Dismissed / Dropped / Withdrawn / Repudiated</strong></td>
                            <td class="text-right"><?php echo (int) ($dismissed + $dropped + $repudiated + $referred); ?></td>
                            <td class="text-right"><?php echo $totalDocketed > 0 ? round((($dismissed + $dropped + $repudiated + $referred) / $totalDocketed) * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>Pending Carry-Over</strong> (Ongoing Mediation / Pangkat)</td>
                            <td class="text-right"><strong><?php echo (int) ($pending + $paanyayaPending); ?></strong></td>
                            <td class="text-right">—</td>
                        </tr>
                    </tbody>
                </table>

                <div class="panel-subtitle" style="margin-top:6px;">
                    <span>Process Server Summons SLA (1-Day Target)</span>
                    <small>SLA Rate: <?php echo $slaComplianceRate; ?>%</small>
                </div>
                <div class="mini-stat-row">
                    <div class="mini-stat-pill">
                        <strong><?php echo (int) $slaOnTime; ?></strong>
                        <span>Served On-Time (&le;1d)</span>
                    </div>
                    <div class="mini-stat-pill">
                        <strong><?php echo (int) $slaDelayed; ?></strong>
                        <span>Delayed (&gt;1d)</span>
                    </div>
                    <div class="mini-stat-pill">
                        <strong><?php echo (int) $slaNotServed; ?></strong>
                        <span>Pending / Unserved</span>
                    </div>
                </div>
            </div>

            <!-- Right Column: Peace & Order Intelligence -->
            <div class="panel-box">
                <div class="panel-subtitle">
                    <span>III. Peace &amp; Order Committee Intelligence</span>
                    <small><?php echo $isBaselineAnalytics ? 'Cumulative Barangay Profile' : 'Period Breakdown'; ?></small>
                </div>
                <table class="brief-table">
                    <thead>
                        <tr>
                            <th>Leading Nature of Disputes / Categories</th>
                            <th class="text-right">Cases</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($topCategories)): ?>
                            <?php foreach (array_slice($topCategories, 0, 4) as $cat): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cat['display_name'] ?? $cat['category_name'] ?? '—'); ?></td>
                                <td class="text-right"><strong><?php echo (int) ($cat['total'] ?? 0); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="2" class="text-center">No categorized complaints recorded.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <table class="brief-table" style="margin-top:5px;">
                    <thead>
                        <tr>
                            <th>Purok Dispute Hotspots (Peacekeeping Focus)</th>
                            <th class="text-right">Volume</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($purokHotspots)): ?>
                            <?php foreach (array_slice($purokHotspots, 0, 4) as $ph): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ph['purok_name'] ?? 'Tumana'); ?></td>
                                <td class="text-right"><strong><?php echo (int) ($ph['total'] ?? 0); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="2" class="text-center">No purok hotspot data recorded.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="mini-stat-row" style="grid-template-columns: repeat(4, 1fr); margin-top:4px;">
                    <div class="mini-stat-pill">
                        <strong><?php echo (int) ($genderData['Female'] ?? 0); ?>F / <?php echo (int) ($genderData['Male'] ?? 0); ?>M</strong>
                        <span>Gender Split</span>
                    </div>
                    <div class="mini-stat-pill">
                        <strong><?php echo (int) ($ageData['youth'] ?? 0); ?></strong>
                        <span>Youth (18-29)</span>
                    </div>
                    <div class="mini-stat-pill">
                        <strong><?php echo (int) ($ageData['adults'] ?? 0); ?></strong>
                        <span>Adults (30-59)</span>
                    </div>
                    <div class="mini-stat-pill">
                        <strong><?php echo (int) ($ageData['seniors'] ?? 0); ?></strong>
                        <span>Seniors (60+)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION IV: PRIORITY CASE DOCKET SNAPSHOT -->
        <div class="sec-heading">
            <span>IV. Priority Docketed Cases &amp; Council Monitoring Registry (<?php echo count($cases); ?> Docketed in Period)</span>
            <span class="sec-sub">Representative summary of formal cases acted upon by the Lupon</span>
        </div>
        <table class="brief-table">
            <thead>
                <tr>
                    <th>Case No.</th>
                    <th>Dispute / Complaint Title</th>
                    <th>Category</th>
                    <th>Nature</th>
                    <th>Parties (Complainant vs. Respondent)</th>
                    <th class="text-center">Hearings</th>
                    <th>Disposition / Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($priorityCases)): ?>
                    <?php foreach ($priorityCases as $pc):
                        $sessionsCount = (int) ($pc['mediation_sessions'] ?? 0) + (int) ($pc['conciliation_sessions'] ?? 0);
                        $outcomeLabel = $pc['case_status'] ?? 'Active';
                        if (!empty($pc['settlement_date'])) {
                            $outcomeLabel = 'Settled (' . $pc['settlement_date'] . ')';
                        } elseif (!empty($pc['cfa_issuance_date'])) {
                            $outcomeLabel = 'CFA Issued (' . $pc['cfa_issuance_date'] . ')';
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($pc['case_number'] ?? '—'); ?></strong></td>
                        <td><?php echo htmlspecialchars(mb_strimwidth((string) ($pc['complaint_title'] ?? '—'), 0, 34, '…')); ?></td>
                        <td><?php echo htmlspecialchars(mb_strimwidth((string) ($pc['category_name'] ?? '—'), 0, 22, '…')); ?></td>
                        <td><?php echo htmlspecialchars($pc['case_type'] ?? 'Civil'); ?></td>
                        <td><?php echo htmlspecialchars(mb_strimwidth(($pc['complainant_name'] ?? '—') . ' vs. ' . ($pc['respondent_name'] ?? '—'), 0, 36, '…')); ?></td>
                        <td class="text-center"><?php echo $sessionsCount; ?></td>
                        <td><strong><?php echo htmlspecialchars($outcomeLabel); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center" style="padding: 6px; color: #475569;">
                            No formal KP cases were docketed during <?php echo htmlspecialchars($periodLabel); ?>. (Refer to Stage 0 Paanyaya &amp; Cumulative Barangay Profile above.)
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- SECTION V: AUTOMATED COUNCIL SYNTHESIS & POLICY BRIEF -->
        <div class="executive-synthesis-box">
            <strong>V. Executive Synthesis &amp; Legislative Recommendation:</strong>
            During the reporting period of <strong><?php echo htmlspecialchars($periodLabel); ?></strong>, the Lupong Tagapamayapa of <?php echo htmlspecialchars($barangay); ?> acted upon <strong><?php echo (int) $totalIntakeAll; ?></strong> community grievances
            (with <strong><?php echo (int) $paanyayaSettled; ?></strong> disputes amicably resolved at Stage 0 Pre-Docketing Paanyaya and <strong><?php echo (int) $totalSettledFormal; ?></strong> formally settled under Punong Barangay Mediation and Pangkat Conciliation),
            posting a <strong><?php echo $overallResolutionRate; ?>%</strong> overall settlement efficiency and saving the judiciary an estimated <strong>PHP <?php echo number_format($totalSavingsWithPaanyaya, 2); ?></strong> in trial court litigation costs.
            The Committee on Peace and Order is advised to prioritize preventive barangay ordinances and Purok dialogue initiatives addressing <strong><?php echo htmlspecialchars($topCategoryName); ?></strong>, with focused peacekeeping visibility in <strong><?php echo htmlspecialchars($topPurokName); ?></strong>.
        </div>
    </div>

    <!-- CERTIFICATION & FORMAL SIGNATORIES -->
    <div class="sheet-content-layer">
        <div class="certification-statement">
            I HEREBY CERTIFY to the correctness of the foregoing Monthly Katarungang Pambarangay Executive Brief presented before the Sangguniang Barangay this <?php echo htmlspecialchars($assemblyDateFormatted); ?> at <?php echo htmlspecialchars($barangay . ', ' . $city); ?>.
        </div>
        <div class="signatories-grid">
            <div class="sig-block">
                <div class="sig-role-caption">Prepared &amp; Submitted By:</div>
                <div class="sig-line"><?php echo htmlspecialchars($luponSec); ?></div>
                <div class="sig-sub">Lupon Secretary &bull; Lupong Tagapamayapa</div>
            </div>
            <div class="sig-block">
                <div class="sig-role-caption">Received for the Sanggunian:</div>
                <div class="sig-line"><?php echo htmlspecialchars($brgySec); ?></div>
                <div class="sig-sub">Barangay Secretary &bull; Sangguniang Barangay</div>
            </div>
            <div class="sig-block">
                <div class="sig-role-caption">Attested &amp; Approved By:</div>
                <div class="sig-line"><?php echo htmlspecialchars($pbName); ?></div>
                <div class="sig-sub">Punong Barangay &amp; Lupon Chairperson</div>
            </div>
        </div>

        <footer class="brief-footer">
            <span>Republic of the Philippines &bull; <?php echo htmlspecialchars($city . ' — ' . $barangay); ?> (Control No. <?php echo htmlspecialchars($controlNumber); ?>)</span>
            <span>Generated via AGAP Katarungang Pambarangay System on <?php echo htmlspecialchars($printedAt); ?> &bull; Page 1 of 1</span>
        </footer>
    </div>
</div>

<script>
    window.addEventListener('load', () => {
        window.focus();
        <?php if (!empty($_GET['autoprint'])): ?>
        setTimeout(() => window.print(), 350);
        <?php endif; ?>
    });
</script>
</body>
</html>
