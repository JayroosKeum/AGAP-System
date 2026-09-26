<?php
session_start();
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 4], true)) {
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
        <div class="page-header">
            <div>
                <a id="backLink" href="../complaints/complaint-list.php" class="back-link" style="display:none; margin-bottom: 8px;">
                    <svg class="back-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span id="backLinkLabel">Back</span>
                </a>
                <h1>Proof of Service</h1>
                <p>Record field verification, summons delivery, and service attempts for active cases.</p>
            </div>
        </div>

        <div id="proofMessage" role="alert"></div>

        <!-- Case & Parties Context Summary Box (populated when case is selected/prefilled) -->
        <div id="caseSummaryCard" class="gps-card case-summary-card" style="display:none; margin-bottom: 20px;">
            <div class="case-summary-header">
                <div>
                    <span class="meta-pill case-pill" id="summaryCaseNumber">Case #...</span>
                    <span class="meta-pill" id="summaryComplaintNumber" style="background:#e0e7ff; color:#3730a3; margin-left: 6px;">CMP-...</span>
                    <h3 id="summaryComplaintTitle" style="margin: 8px 0 4px; font-size: 1.1rem; color: #1e293b;">Complaint Title</h3>
                </div>
            </div>
            <div class="case-summary-parties" style="display:flex; flex-wrap:wrap; gap: 20px; margin-top: 10px; font-size: 0.9rem;">
                <div><strong>Complainant(s):</strong> <span id="summaryComplainants">—</span></div>
                <div><strong>Respondent(s):</strong> <span id="summaryRespondents">—</span></div>
            </div>
        </div>

        <section class="gps-card">
            <h2>Record Summons Service Attempt</h2>
            <form id="proofForm" enctype="multipart/form-data">
                <div class="gps-grid">
                    <div class="form-group">
                        <label for="proofCaseId">Case <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="proofCaseId" name="case_id" required>
                            <option value="">Select a case</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="proofDocumentId">Summons / Generated Document <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="proofDocumentId" name="document_id" required disabled>
                            <option value="">Select a case first</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="serviceResult">Service Result <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="serviceResult" name="service_result" required>
                            <option value="Served" selected>Served</option>
                            <option value="Not Served">Not Served</option>
                            <option value="Refused">Refused</option>
                            <option value="Respondent Not Found">Respondent Not Found</option>
                            <option value="Address Problem">Address Problem</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="servedBy">Server / Assigned Personnel <span class="required-mark" aria-hidden="true">*</span></label>
                        <select id="servedBy" name="served_by" required>
                            <option value="<?php echo (int) $_SESSION['user_id']; ?>">
                                Current User (<?php echo htmlspecialchars($_SESSION['username'] ?? 'Me'); ?>)
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="servedDate">Service Date and Time <span class="required-mark" aria-hidden="true">*</span></label>
                        <input id="servedDate" type="datetime-local" name="served_date" required>
                    </div>

                    <div class="form-group">
                        <label for="proofPhoto">Proof Document / Image (optional)</label>
                        <input id="proofPhoto" type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                        <small>JPG, PNG, or WebP up to 5 MB.</small>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 14px;">
                    <label for="proofRemarks">Remarks / Reason / Field Observations</label>
                    <textarea id="proofRemarks" name="remarks" maxlength="2000" rows="3" placeholder="Enter service details, recipient remarks, or reason if not served (e.g., respondent relocated, refused signature, etc.)"></textarea>
                </div>

                <button class="btn-create" type="submit" style="margin-top: 12px;">Save Proof / Service Record</button>
            </form>
        </section>

        <section class="gps-card">
            <h2>Service History &amp; Attempts</h2>
            <p class="map-help">Chronological log of all summons service attempts and proof records for this case. Every attempt is preserved.</p>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Service Date</th>
                            <th>Summons / Document</th>
                            <th>Server</th>
                            <th>Result</th>
                            <th>Remarks / Reason</th>
                            <th>Proof Image</th>
                        </tr>
                    </thead>
                    <tbody id="proofsTable"></tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<script src="../../assets/js/gps.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/gps.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
