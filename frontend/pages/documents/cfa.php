<?php
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    die('Access Denied');
}

$canIssueCfa = in_array($roleId, [1, 2], true);
$preselectedCaseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/documents.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/documents.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <a href="../cases/case-list.php" class="back-link">
                    <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    <span>Back to Cases</span>
                </a>
                <h1>Certificate to File Action (CFA) &amp; Bar Certification</h1>
                <p>Issue official Certification to File Action (KP Form 20), Certificate to Bar Action (KP Form 20-A), or Certificate to Bar Counterclaim (KP Form 20-B) in accordance with RA 7160 Sec. 412.</p>
            </div>
        </div>

        <div id="cfaMessage" role="alert" style="display: none;"></div>

        <?php if ($canIssueCfa): ?>
        <section class="document-card">
            <h2>Issue Certificate to File Action / Bar Certification</h2>
            <p>Under RA 7160 (Katarungang Pambarangay Sec. 412), the backend automatically verifies eligibility and recommends the correct statutory form variant (KP Form 20, 20-A, or 20-B) based on party appearance, conciliation outcomes, and show-cause records.</p>

            <form id="cfaIssueForm" class="cfa-form">
                <div class="form-group">
                    <label for="cfaCaseId">Select Case <span class="required-mark" aria-hidden="true">*</span></label>
                    <select id="cfaCaseId" name="case_id" required>
                        <option value="">Loading cases...</option>
                    </select>
                </div>

                <div id="cfaCasePreview" class="document-preview muted" style="margin-bottom: 1.25rem;">
                    Select a case above to review its current status, proceedings outcome, and eligibility.
                </div>

                <div class="form-group" id="cfaTypeGroup">
                    <label for="cfaType">CFA Form Variant <span class="required-mark" aria-hidden="true">*</span></label>
                    <select id="cfaType" name="cfa_type" required>
                        <option value="KP Form 20">KP Form 20 — Certificate to File Action (Katibayan Upang Makadulog sa Hukuman)</option>
                        <option value="KP Form 20-A">KP Form 20-A — Certificate to Bar Action (Katibayan Upang Hadlangan ang Paghahain ng Aksyon)</option>
                        <option value="KP Form 20-B">KP Form 20-B — Certificate to Bar Counterclaim (Katibayan Upang Hadlangan ang Ganting-Sakdal)</option>
                    </select>
                    <small id="cfaTypeHelp" style="color: #64748b; font-size: 0.8rem; display: block; margin-top: 4px;">Form variant is determined by case outcome and responsible party under RA 7160.</small>
                </div>

                <div class="form-group">
                    <label for="cfaPresetReason">Reason for Issuance <span class="required-mark" aria-hidden="true">*</span></label>
                    <select id="cfaPresetReason" required>
                        <option value="">Select a ground / reason</option>
                        <option value="Respondent summons unserved after documented delivery attempts (Delivery Failure).">Respondent summons unserved after documented delivery attempts (Delivery Failure)</option>
                        <option value="15-day statutory mediation period has lapsed without settlement or constitution of Pangkat Tagapagkasundo (RA 7160 Sec. 410(b)).">15-day statutory mediation period has lapsed (15-day limit reached)</option>
                        <option value="Respondent willfully failed or refused to appear for mediation after repeated summons without justifiable cause.">Respondent failed to appear after repeated summons</option>
                        <option value="Complainant willfully failed or refused to appear for hearing after summons without justifiable cause. Bar action issued pursuant to Sec. 412(b)(1).">Complainant failed to appear (Bar Action - KP Form 20-A)</option>
                        <option value="Conciliation proceedings before the Pangkat Tagapagkasundo failed to reach an amicable settlement within the statutory period.">Conciliation before Pangkat Tagapagkasundo failed</option>
                        <option value="Amicable settlement was formally repudiated by a party within the 10-day statutory period.">Settlement repudiated within 10 days</option>
                        <option value="custom">Other verified ground (specify below)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cfaReasonText">Reason / Specific Findings <span class="required-mark" aria-hidden="true">*</span></label>
                    <textarea id="cfaReasonText" name="reason" rows="4" maxlength="2000" placeholder="Provide details justifying the issuance of the Certificate to File Action or Bar Certificate." required></textarea>
                </div>

                <div class="modal-actions" style="margin-top: 1.5rem;">
                    <button type="submit" class="btn-create" id="btnSubmitCfa">
                        Issue Certificate to File Action
                    </button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <section class="document-card" style="margin-top: 1.5rem;">
            <h2>Recent CFA &amp; Bar Certification Records</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Case No.</th>
                            <th>Complaint Title</th>
                            <th>Form Type</th>
                            <th>Date Issued</th>
                            <th>Reason / Findings</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="cfaRecordsTable">
                        <tr>
                            <td colspan="6" class="empty-state">Loading CFA records...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
window.AGAP_CFA = Object.freeze({
    preselectedCaseId: <?php echo (int) $preselectedCaseId; ?>,
    canIssue: <?php echo $canIssueCfa ? 'true' : 'false'; ?>
});
</script>
<script src="../../assets/js/cfa.js?v=<?php echo filemtime(__DIR__ . '/../../layouts/header.php'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
