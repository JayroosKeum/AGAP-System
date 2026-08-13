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
        <div class="page-header"><h1>Reports and Export</h1><p>Generate record-based monthly, quarterly, annual, and DILG reports.</p></div>
        <main class="reports-content">
            <div id="reportMessage" role="alert"></div>
            <section class="report-card">
                <form id="reportForm" class="report-filters">
                    <div class="form-group"><label for="reportType">Report type</label><select id="reportType" name="type"><option value="Monthly">Monthly</option><option value="Quarterly">Quarterly</option><option value="Annual">Annual</option><option value="DILG">DILG</option></select></div>
                    <div class="form-group"><label for="reportYear">Year</label><input id="reportYear" name="year" type="number" min="2000" max="<?php echo date('Y') + 1; ?>" value="<?php echo date('Y'); ?>" required></div>
                    <div class="form-group" id="monthGroup"><label for="reportMonth">Month</label><select id="reportMonth" name="month"></select></div>
                    <div class="form-group" id="quarterGroup" hidden><label for="reportQuarter">Quarter</label><select id="reportQuarter" name="quarter"><option value="1">Quarter 1</option><option value="2">Quarter 2</option><option value="3">Quarter 3</option><option value="4">Quarter 4</option></select></div>
                    <div class="report-actions"><button type="submit" class="btn-create">Generate / View</button><button type="button" id="exportReport" class="btn-export" disabled>Export CSV</button></div>
                </form>
            </section>
            <section id="reportOutput" hidden>
                <h2 id="reportTitle"></h2>
                <div id="summaryCards" class="report-summary"></div>
                <div class="report-grid"><div class="report-card"><h3>Case status</h3><div id="statusTotals"></div></div><div class="report-card"><h3>Complaint categories</h3><div id="categoryTotals"></div></div></div>
                <section class="report-card"><h3>Case details</h3><div class="table-container"><table><thead><tr><th>Case</th><th>Complaint</th><th>Category</th><th>Type</th><th>Status</th><th>Docketed</th><th>Hearings</th><th>Resolution / Archive</th></tr></thead><tbody id="reportCases"></tbody></table></div></section>
            </section>
        </main>
    </div>
</div>
<script src="../../assets/js/reports.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/reports.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
