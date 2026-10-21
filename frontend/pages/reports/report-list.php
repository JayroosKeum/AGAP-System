<?php
session_start();
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2], true)) { http_response_code(403); die('Access Denied'); }
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<link rel="stylesheet" href="../../assets/css/reports.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/reports.css'); ?>">
<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>
        <div class="page-header">
            <h1>Reports and Export</h1>
            <p>Generate record-based monthly, quarterly, annual, and DILG reports.</p>
        </div>
        <main class="reports-content">
            <div id="reportMessage" role="alert" style="display: none;"></div>

            <!-- ── Standard Summary Report ─────────────────────────────── -->
            <section class="report-card">
                <form id="reportForm" class="report-filters">
                    <div class="form-group">
                        <label for="reportType">Report type</label>
                        <select id="reportType" name="type">
                            <option value="Monthly">Monthly</option>
                            <option value="Quarterly">Quarterly</option>
                            <option value="Annual">Annual</option>
                            <option value="DILG">DILG</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="reportYear">Year</label>
                        <input id="reportYear" name="year" type="number" min="2000" max="<?php echo date('Y') + 1; ?>" value="<?php echo date('Y'); ?>" required>
                    </div>
                    <div class="form-group" id="monthGroup">
                        <label for="reportMonth">Month</label>
                        <select id="reportMonth" name="month"></select>
                    </div>
                    <div class="form-group" id="quarterGroup" hidden>
                        <label for="reportQuarter">Quarter</label>
                        <select id="reportQuarter" name="quarter">
                            <option value="1">Quarter 1</option>
                            <option value="2">Quarter 2</option>
                            <option value="3">Quarter 3</option>
                            <option value="4">Quarter 4</option>
                        </select>
                    </div>
                    <div class="report-actions">
                        <button type="submit" class="btn-create">Generate / View</button>
                        <button type="button" id="exportReport" class="btn-export" disabled>Export CSV</button>
                        <button type="button" id="btnPrintDilg" class="btn-export" style="background:#0369a1; color:#fff; border-color:#0369a1;" title="Print / Export DILG/LTIA Monthly or Quarterly Summary">
                            🖨️ Print DILG / LTIA Report
                        </button>
                    </div>
                </form>
            </section>

            <!-- ── Case Register Excel Export (independent section) ────── -->
            <section class="report-card" style="margin-top:18px;">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                    <div>
                        <h3 style="margin:0 0 4px; font-size:0.97rem; font-weight:800; color:#0f172a;">📋 Case Register Export (Excel)</h3>
                        <p style="margin:0; font-size:0.82rem; color:#64748b;">
                            Exports a 1-row-per-case register with summons delivery timeliness, parties, hearing counts, and resolution outcome.
                            Compatible with Microsoft Excel (UTF-8 BOM, CSV format).
                        </p>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <div class="form-group" style="margin:0;">
                            <label for="registerStartDate" style="font-size:0.8rem; font-weight:600; color:#475569; display:block; margin-bottom:3px;">From Date</label>
                            <input type="date" id="registerStartDate" style="padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:0.84rem;">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label for="registerEndDate" style="font-size:0.8rem; font-weight:600; color:#475569; display:block; margin-bottom:3px;">To Date</label>
                            <input type="date" id="registerEndDate" style="padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-size:0.84rem;">
                        </div>
                        <button type="button" id="btnExportRegister" class="btn-create" style="background:#16a34a; border-color:#16a34a; align-self:flex-end; padding:7px 18px;">
                            ⬇ Export Case Register (Excel)
                        </button>
                    </div>
                </div>
            </section>

            <!-- ── Report Output ───────────────────────────────────────── -->
            <section id="reportOutput" hidden>
                <h2 id="reportTitle"></h2>
                <div id="summaryCards" class="report-summary"></div>
                <div class="report-grid">
                    <div class="report-card"><h3>Case status</h3><div id="statusTotals"></div></div>
                    <div class="report-card"><h3>Complaint categories</h3><div id="categoryTotals"></div></div>
                </div>
                <section class="report-card">
                    <h3>Case details</h3>
                    <div class="table-container">
                        <table>
                            <thead><tr><th>Case</th><th>Complaint</th><th>Category</th><th>Type</th><th>Status</th><th>Docketed</th><th>Hearings</th><th>Resolution / Archive</th></tr></thead>
                            <tbody id="reportCases"></tbody>
                        </table>
                    </div>
                </section>
            </section>
        </main>
    </div>
</div>
<script src="../../assets/js/reports.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/reports.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
