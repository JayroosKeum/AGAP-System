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
                <h1>Certificate to File Action (CFA)</h1>
                <p>Issue official Certification to File Action when mediation has lapsed or dispute resolution cannot be reached.</p>
            </div>
        </div>

        <div id="cfaMessage" role="alert"></div>

        <?php if ($canIssueCfa): ?>
        <section class="document-card">
            <h2>Issue Certificate to File Action</h2>
            <p>Under RA 7160 (Katarungang Pambarangay), when the 15-day mediation period has lapsed without settlement or constitution of the Pangkat, or when conciliation fails, a Certificate to File Action may be issued to allow the complainant to seek judicial relief.</p>

            <form id="cfaIssueForm" class="cfa-form">
                <div class="form-group">
                    <label for="cfaCaseId">Select Case <span class="required-mark" aria-hidden="true">*</span></label>
                    <select id="cfaCaseId" name="case_id" required>
                        <option value="">Loading cases...</option>
                    </select>
                </div>

                <div id="cfaCasePreview" class="document-preview muted" style="margin-bottom: 1.25rem;">
                    Select a case above to review its current status and mediation timeline.
                </div>

                <div class="form-group">
                    <label for="cfaPresetReason">Reason for Issuance <span class="required-mark" aria-hidden="true">*</span></label>
                    <select id="cfaPresetReason" required>
                        <option value="">Select a ground / reason</option>
                        <option value="15-day statutory mediation period has lapsed without settlement or constitution of Pangkat Tagapagkasundo (RA 7160 Sec. 410(b)).">15-day statutory mediation period has lapsed (15-day limit reached)</option>
                        <option value="Respondent willfully failed or refused to appear for mediation after repeated summons without justifiable cause.">Respondent failed to appear after repeated summons</option>
                        <option value="Conciliation proceedings before the Pangkat Tagapagkasundo failed to reach an amicable settlement within the statutory period.">Conciliation before Pangkat Tagapagkasundo failed</option>
                        <option value="Amicable settlement was formally repudiated by a party within the 10-day statutory period.">Settlement repudiated within 10 days</option>
                        <option value="custom">Other verified ground (specify below)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cfaReasonText">Reason / Specific Findings <span class="required-mark" aria-hidden="true">*</span></label>
                    <textarea id="cfaReasonText" name="reason" rows="4" maxlength="2000" placeholder="Provide details justifying the issuance of the Certificate to File Action." required></textarea>
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
            <h2>Recent CFA Records</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Case No.</th>
                            <th>Complaint Title</th>
                            <th>Date Issued</th>
                            <th>Reason / Findings</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="cfaRecordsTable">
                        <tr>
                            <td colspan="5" class="empty-state">Loading CFA records...</td>
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
