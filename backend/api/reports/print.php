<?php
/**
 * GET /backend/api/reports/print.php
 *
 * Renders a 1-click printable DILG / LTIA Summary Report (HTML + @media print).
 * Compatible with the Monthly and Quarterly report formats.
 *
 * Query parameters:
 *   type    = Monthly | Quarterly | Annual | DILG
 *   year    = YYYY
 *   month   = 1-12   (when type = Monthly)
 *   quarter = 1-4    (when type = Quarterly)
 */

declare(strict_types=1);

session_start();
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    die('Access Denied');
}

require_once __DIR__ . '/../../controllers/ReportController.php';

// ── Parse & validate parameters ──────────────────────────────────────────────
$type    = in_array($_GET['type'] ?? '', ['Monthly', 'Quarterly', 'Annual', 'DILG'], true)
           ? $_GET['type'] : 'Monthly';
$year    = filter_var($_GET['year']    ?? date('Y'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]])
           ?: (int) date('Y');
$month   = filter_var($_GET['month']   ?? date('n'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]])
           ?: (int) date('n');
$quarter = filter_var($_GET['quarter'] ?? ceil(date('n') / 3), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]])
           ?: (int) ceil(date('n') / 3);

// ── Fetch report data via controller ─────────────────────────────────────────
$controller = new ReportController();
$filters = ['type' => $type, 'year' => $year, 'month' => $month, 'quarter' => $quarter];
$generated = $controller->generate($filters);

if (!$generated['success']) {
    die('<p style="color:red; font-family:sans-serif; padding:2rem;">Error: ' . htmlspecialchars($generated['message'] ?? 'Could not generate report.') . '</p>');
}

$data        = $generated['data'];
$periodLabel = $data['period_label'] ?? ($type . ' ' . $year);
$totals      = $data['totals']       ?? [];
$statuses    = $data['status_totals'] ?? [];
$categories  = $data['categories']   ?? [];
$cases       = $data['cases']        ?? [];

// ── Barangay metadata ─────────────────────────────────────────────────────────
$barangay   = 'Barangay Tumana';
$city       = 'Marikina City';
$province   = 'Metro Manila';
$pbName     = 'Hon. [Punong Barangay Name]';
$secretName = '[Barangay Secretary Name]';

// ── Build settlement / CFA / hearing summary ──────────────────────────────────
$settledCount    = $totals['settled_cases']       ?? 0;
$cfaCount        = $totals['cfa_issued_cases']    ?? 0;
$mediationCount  = $totals['mediation_cases']     ?? 0;
$concilCount     = $totals['conciliation_cases']  ?? 0;
$docketedTotal   = $totals['total_cases']         ?? count($cases);

// Compute summons SLA from case data (if available)
$summonOnTime    = 0;
$summonDelayed   = 0;
$summonNotServed = 0;
foreach ($cases as $c) {
    $sla = $c['summons_timeliness'] ?? '';
    if ($sla === 'On Time')       $summonOnTime++;
    elseif ($sla === 'Delayed')   $summonDelayed++;
    else                          $summonNotServed++;
}

$printedAt = date('F j, Y \a\t h:i A');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DILG/LTIA Report – <?php echo htmlspecialchars($periodLabel); ?></title>
    <style>
        /* ── Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            color: #111;
            background: #f8f9fa;
            padding: 0;
        }
        .page {
            background: #fff;
            max-width: 210mm;
            margin: 20px auto;
            padding: 18mm 20mm 20mm;
            box-shadow: 0 4px 24px rgba(0,0,0,0.12);
        }

        /* ── Header ── */
        .report-header {
            text-align: center;
            border-bottom: 3px double #1a3c6e;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .report-header .seal-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-bottom: 6px;
        }
        .seal-placeholder {
            width: 54px; height: 54px;
            border: 2px solid #1a3c6e;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 8pt; color: #1a3c6e; font-weight: bold; text-align: center;
        }
        .header-text h1 { font-size: 13pt; font-weight: bold; color: #1a3c6e; line-height: 1.2; }
        .header-text h2 { font-size: 11pt; font-weight: normal; color: #1a3c6e; }
        .header-text p  { font-size: 9.5pt; color: #374151; }
        .report-title-bar {
            margin-top: 10px;
            background: #1a3c6e;
            color: #fff;
            padding: 6px 12px;
            font-size: 11.5pt;
            font-weight: bold;
            text-align: center;
            letter-spacing: 0.03em;
        }
        .period-sub {
            font-size: 10pt;
            color: #374151;
            text-align: center;
            margin-top: 4px;
        }

        /* ── Section titles ── */
        h3.section-title {
            font-size: 10.5pt;
            font-weight: bold;
            color: #1a3c6e;
            border-left: 4px solid #1a3c6e;
            padding-left: 8px;
            margin: 18px 0 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ── KPI summary grid ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 6px;
        }
        .kpi-box {
            border: 1px solid #c7d2e0;
            border-radius: 6px;
            padding: 10px 8px;
            text-align: center;
            background: #f0f7ff;
        }
        .kpi-box .kpi-num { font-size: 22pt; font-weight: bold; color: #1a3c6e; line-height: 1; }
        .kpi-box .kpi-label { font-size: 8pt; color: #374151; margin-top: 3px; }

        /* ── Tables ── */
        table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 4px; }
        th {
            background: #1a3c6e;
            color: #fff;
            padding: 5px 7px;
            text-align: left;
            font-size: 8.5pt;
        }
        td { padding: 4px 7px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background: #f8fafc; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* ── SLA table ── */
        .sla-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 8px 0; }
        .sla-box {
            border: 1px solid #d1d5db;
            border-radius: 5px;
            padding: 8px 10px;
            text-align: center;
        }
        .sla-box.good  { background: #f0fdf4; border-color: #86efac; }
        .sla-box.warn  { background: #fffbeb; border-color: #fcd34d; }
        .sla-box.bad   { background: #fef2f2; border-color: #fca5a5; }
        .sla-box .num  { font-size: 18pt; font-weight: bold; color: #111; line-height: 1; }
        .sla-box .lbl  { font-size: 8pt; color: #374151; margin-top: 2px; }

        /* ── Signature block ── */
        .signature-section {
            margin-top: 30px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        .sig-box {
            border-top: 1.5px solid #111;
            padding-top: 5px;
            font-size: 9pt;
        }
        .sig-box .sig-name { font-weight: bold; font-size: 10pt; }
        .sig-box .sig-title { color: #374151; font-size: 8.5pt; }

        /* ── Footer ── */
        .report-footer {
            margin-top: 24px;
            padding-top: 8px;
            border-top: 1px solid #d1d5db;
            font-size: 8pt;
            color: #6b7280;
            display: flex;
            justify-content: space-between;
        }

        /* ── Print button (screen only) ── */
        .no-print {
            position: fixed;
            top: 16px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 100;
        }
        .btn-print {
            background: #1a3c6e;
            color: #fff;
            border: none;
            padding: 10px 22px;
            font-size: 10.5pt;
            font-weight: bold;
            border-radius: 7px;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(26,60,110,0.3);
        }
        .btn-print:hover { background: #0f2d5a; }

        /* ── @media print ── */
        @media print {
            body { background: #fff; }
            .page { box-shadow: none; margin: 0; padding: 12mm 14mm 15mm; max-width: 100%; }
            .no-print { display: none !important; }
            h3.section-title { break-after: avoid; }
            table { break-inside: auto; }
            tr { break-inside: avoid; break-after: auto; }
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>

<!-- ── Screen-only action bar ── -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Print This Report</button>
    <button class="btn-print" style="background:#374151;" onclick="window.close()">✕ Close</button>
</div>

<div class="page">

    <!-- ═══ HEADER ════════════════════════════════════════════════════════════ -->
    <div class="report-header">
        <div class="seal-row">
            <div class="seal-placeholder">MARIKINA<br>SEAL</div>
            <div class="header-text">
                <h1>Republic of the Philippines</h1>
                <h2>City of Marikina — <?php echo htmlspecialchars($barangay); ?></h2>
                <p>Lupong Tagapamayapa · Katarungang Pambarangay</p>
            </div>
            <div class="seal-placeholder">BRGY<br>SEAL</div>
        </div>
        <div class="report-title-bar">
            DILG / LTIA <?php echo htmlspecialchars(strtoupper($type)); ?> SUMMARY REPORT
        </div>
        <p class="period-sub">
            Reporting Period: <strong><?php echo htmlspecialchars($periodLabel); ?></strong> &nbsp;|&nbsp;
            Generated: <?php echo $printedAt; ?>
        </p>
    </div>

    <!-- ═══ KPI SUMMARY ════════════════════════════════════════════════════════ -->
    <h3 class="section-title">I. Case Summary Statistics</h3>
    <div class="kpi-grid">
        <div class="kpi-box">
            <div class="kpi-num"><?php echo (int) $docketedTotal; ?></div>
            <div class="kpi-label">Total Cases<br>Docketed</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-num"><?php echo (int) $settledCount; ?></div>
            <div class="kpi-label">Settled<br>(Amicable)</div>
        </div>
        <div class="kpi-box">
            <div class="kpi-num"><?php echo (int) $cfaCount; ?></div>
            <div class="kpi-label">CFA<br>Issued</div>
        </div>
        <div class="kpi-box" style="background:#f0fdf4;">
            <div class="kpi-num" style="color:#15803d;"><?php echo $docketedTotal > 0 ? round($settledCount / $docketedTotal * 100) : 0; ?>%</div>
            <div class="kpi-label">Settlement<br>Rate</div>
        </div>
    </div>

    <!-- ═══ CASE STATUS BREAKDOWN ══════════════════════════════════════════════ -->
    <h3 class="section-title">II. Case Status Breakdown</h3>
    <table>
        <thead>
            <tr>
                <th>Case Status</th>
                <th class="text-right">Count</th>
                <th class="text-right">% of Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($statuses as $status => $count): ?>
            <tr>
                <td><?php echo htmlspecialchars($status); ?></td>
                <td class="text-right"><?php echo (int) $count; ?></td>
                <td class="text-right"><?php echo $docketedTotal > 0 ? round($count / $docketedTotal * 100, 1) : 0; ?>%</td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($statuses)): ?>
            <tr><td colspan="3" class="text-center">No cases in this period.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- ═══ CATEGORY BREAKDOWN ═════════════════════════════════════════════════ -->
    <h3 class="section-title">III. Cases by Dispute Category</h3>
    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th class="text-right">Cases</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $cat): ?>
            <tr>
                <td><?php echo htmlspecialchars($cat['category_name'] ?? '—'); ?></td>
                <td class="text-right"><?php echo (int) ($cat['total'] ?? 0); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($categories)): ?>
            <tr><td colspan="2" class="text-center">No categories in this period.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- ═══ SUMMONS DELIVERY SLA ═══════════════════════════════════════════════ -->
    <h3 class="section-title">IV. Summons Delivery Timeliness (SLA)</h3>
    <div class="sla-grid">
        <div class="sla-box good">
            <div class="num"><?php echo $summonOnTime; ?></div>
            <div class="lbl">✓ On Time<br>(Within 1 Day)</div>
        </div>
        <div class="sla-box warn">
            <div class="num"><?php echo $summonDelayed; ?></div>
            <div class="lbl">⚠ Delayed<br>(Beyond 1 Day)</div>
        </div>
        <div class="sla-box bad">
            <div class="num"><?php echo $summonNotServed; ?></div>
            <div class="lbl">✗ Not Served</div>
        </div>
    </div>

    <!-- ═══ CASE LIST ═══════════════════════════════════════════════════════════ -->
    <h3 class="section-title">V. Case Registry (<?php echo count($cases); ?> cases)</h3>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Case No.</th>
                <th>Complaint Title</th>
                <th>Category</th>
                <th>Type</th>
                <th>Status</th>
                <th>Date Docketed</th>
                <th>Hearings</th>
                <th>Resolution</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $rowNum = 1;
            foreach ($cases as $c):
                $resolution = '';
                if (!empty($c['cfa_issuance_date']))  $resolution = 'CFA: '        . $c['cfa_issuance_date'];
                elseif (!empty($c['award_date']))      $resolution = 'Award: '      . $c['award_date'];
                elseif (!empty($c['settlement_date'])) $resolution = 'Settlement: ' . $c['settlement_date'];
                elseif (!empty($c['archived_date']))   $resolution = 'Archived: '   . $c['archived_date'];
                else                                   $resolution = '—';
            ?>
            <tr>
                <td class="text-center"><?php echo $rowNum++; ?></td>
                <td><?php echo htmlspecialchars($c['case_number'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($c['complaint_title'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($c['category_name'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($c['case_type'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($c['case_status'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($c['docket_date'] ?? '—'); ?></td>
                <td class="text-center"><?php echo (int) ($c['hearing_count'] ?? 0); ?></td>
                <td><?php echo htmlspecialchars($resolution); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($cases)): ?>
            <tr><td colspan="9" class="text-center">No cases in this period.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- ═══ ATTESTATION / SIGNATURE BLOCK ═════════════════════════════════════ -->
    <div class="signature-section" style="margin-top:36px;">
        <div class="sig-box">
            <div style="height:36px;"></div><!-- signature space -->
            <div class="sig-name"><?php echo htmlspecialchars($secretName); ?></div>
            <div class="sig-title">Barangay Secretary<br>Lupong Tagapamayapa</div>
        </div>
        <div class="sig-box">
            <div style="height:36px;"></div><!-- signature space -->
            <div class="sig-name"><?php echo htmlspecialchars($pbName); ?></div>
            <div class="sig-title">Punong Barangay<br><?php echo htmlspecialchars($barangay . ', ' . $city); ?></div>
        </div>
    </div>

    <!-- ═══ FOOTER ═════════════════════════════════════════════════════════════ -->
    <div class="report-footer">
        <span>AGAP Barangay Justice Management System · <?php echo htmlspecialchars($barangay); ?>, <?php echo htmlspecialchars($city); ?></span>
        <span>Generated: <?php echo $printedAt; ?> · <?php echo htmlspecialchars($type . ' Report — ' . $periodLabel); ?></span>
    </div>

</div><!-- /.page -->

<script>
    // Auto-focus the page for keyboard shortcut Ctrl+P
    window.addEventListener('load', () => window.focus());
</script>
</body>
</html>
