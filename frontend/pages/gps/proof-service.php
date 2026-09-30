<?php
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!isset($_SESSION['user_id']) || !in_array($roleId, [1, 2, 4], true)) {
    http_response_code(403);
    die('Access Denied');
}

include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/gps.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/gps.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header proof-page-header">
            <div>
                <a id="backLink" href="../complaints/complaint-list.php" class="back-link" hidden>
                    <svg class="back-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    <span id="backLinkLabel">Back to Complaint</span>
                </a>
                <div class="proof-title-row">
                    <div>
                        <h1>Proof of Service</h1>
                        <p>View summons notices, service results, and recorded proof details for a case.</p>
                    </div>
                    <span class="view-only-badge" aria-label="This module is view only">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        View Only
                    </span>
                </div>
            </div>
        </div>

        <div id="proofMessage" class="proof-message" role="alert" aria-live="polite"></div>

        <section class="gps-card proof-filter-card" aria-labelledby="caseSelectionTitle">
            <div class="section-heading compact-heading">
                <div>
                    <span class="section-kicker">Case lookup</span>
                    <h2 id="caseSelectionTitle">Select a case to inspect</h2>
                    <p>No service action can be started or changed from this page.</p>
                </div>
            </div>

            <div class="form-group proof-case-picker">
                <label for="proofCaseId">Case</label>
                <select id="proofCaseId" name="case_id">
                    <option value="">Select a case</option>
                </select>
            </div>
        </section>

        <section id="caseSummaryCard" class="gps-card case-summary-card" hidden aria-labelledby="summaryComplaintTitle">
            <div class="case-summary-header">
                <div>
                    <div class="summary-pills">
                        <span class="meta-pill case-pill" id="summaryCaseNumber">Case</span>
                        <span class="meta-pill complaint-pill" id="summaryComplaintNumber">Complaint</span>
                    </div>
                    <h2 id="summaryComplaintTitle">Complaint Details</h2>
                </div>
                <a id="viewComplaintLink" class="btn-secondary-link" href="../complaints/complaint-details.php">View Complaint</a>
            </div>

            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-label">Complainant</span>
                    <strong id="summaryComplainants">Not available</strong>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Respondent</span>
                    <strong id="summaryRespondents">Not available</strong>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Case Status</span>
                    <strong id="summaryCaseStatus">Not available</strong>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Incident Location</span>
                    <strong id="summaryIncidentLocation">Not available</strong>
                </div>
            </div>
        </section>

        <section class="gps-card" aria-labelledby="summonsNoticesTitle">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Issued documents</span>
                    <h2 id="summonsNoticesTitle">Summons Notices</h2>
                    <p>Review issued summons records and their current service state.</p>
                </div>
                <span id="noticeCount" class="count-badge">0 notices</span>
            </div>

            <div class="table-responsive">
                <table class="data-table proof-table">
                    <thead>
                        <tr>
                            <th>Date Issued</th>
                            <th>Document</th>
                            <th>Service Status</th>
                            <th>Attempts</th>
                            <th>Document</th>
                        </tr>
                    </thead>
                    <tbody id="summonsNoticesTable">
                        <tr><td colspan="5" class="empty-state">Select a case to view its summons notices.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="gps-card" aria-labelledby="serviceHistoryTitle">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">Recorded activity</span>
                    <h2 id="serviceHistoryTitle">Service History &amp; Attempts</h2>
                    <p>All recorded attempts are shown in reverse chronological order.</p>
                </div>
                <span id="historyCount" class="count-badge">0 attempts</span>
            </div>

            <div class="table-responsive">
                <table class="data-table proof-table history-table">
                    <thead>
                        <tr>
                            <th>Attempt</th>
                            <th>Summons</th>
                            <th>Service Date</th>
                            <th>Served By</th>
                            <th>Result</th>
                            <th>Remarks</th>
                            <th>Proof Image</th>
                        </tr>
                    </thead>
                    <tbody id="proofHistoryTable">
                        <tr><td colspan="7" class="empty-state">Select a case to view its service history.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script src="../../assets/js/gps.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/gps.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
