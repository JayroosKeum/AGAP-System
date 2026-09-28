<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) { die('Access Denied'); }
$caseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) { header('Location: case-list.php'); exit; }
$canManageHearings = in_array((int) $_SESSION['role_id'], [1, 2], true);
$canManageSuspension = in_array((int) $_SESSION['role_id'], [1, 2], true);
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<div class="dashboard-layout"><?php include '../../layouts/sidebar.php'; ?><div class="main-content"><?php include '../../layouts/navbar.php'; ?>
    <div class="page-header"><div><a href="case-list.php" class="back-link"><svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg><span>Back to Cases</span></a><h1 id="workspaceTitle">Case Workspace</h1><p id="workspaceSubtitle">Loading case information…</p></div><div class="action-buttons"><a id="assignLink" class="btn-create">Manage Team</a><?php if ($canManageHearings): ?><a id="hearingLink" class="btn-create">Schedule Mediation</a><?php endif; ?></div></div>
    <p id="workspaceMessage" role="alert" style="margin: 0 30px 16px;"></p>

    <!-- Statutory Mediation Timer / Lapsed Options Banner -->
    <div id="mediationTimerContainer"></div>

    <section class="workspace-grid">
      <div class="table-container"><h3>Case overview</h3><div id="caseOverview"></div></div>
      <div class="table-container"><h3>Case team</h3><div id="caseTeam"></div></div>
      <div class="table-container"><h3>Hearings and deadlines</h3><div id="caseHearings"></div></div>
      <div class="table-container"><h3>Generated documents</h3><div id="caseDocuments"></div></div>
      <div class="table-container"><h3>Proof of service</h3><div id="caseProofs"></div></div>
    </section>
</div></div>

<?php if ($canManageSuspension): ?>
<!-- Modal for Logging Justified Suspension -->
<div id="pauseMediationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Log Justified Suspension</h2>
            <button type="button" class="close-btn" onclick="closePauseModal()">&times;</button>
        </div>
        <p style="color: #64748b; font-size: 0.92rem; margin-top: 0;">
            Pausing the 15-day statutory mediation clock temporarily suspends the calendar day countdown until resumed.
        </p>
        <form id="pauseMediationForm">
            <div class="form-group">
                <label for="pauseReasonSelect">Reason for Suspension <span class="required-mark" aria-hidden="true">*</span></label>
                <select id="pauseReasonSelect" name="reason" required>
                    <option value="">Select a verified reason</option>
                    <option value="Justified Suspension - Verified Medical Emergency">Verified Medical Emergency</option>
                    <option value="Justified Suspension - Natural Disaster / Calamity">Natural Disaster / Calamity</option>
                    <option value="Justified Suspension - Excused Non-Appearance">Excused Non-Appearance (Official Notice)</option>
                    <option value="Summon Unserved">Summon Unserved</option>
                    <option value="Other Justified Reason">Other Exigent Ground</option>
                </select>
            </div>
            <div class="form-group">
                <label for="pauseNotesInput">Detailed Notes &amp; Verification <span class="required-mark" aria-hidden="true">*</span></label>
                <textarea id="pauseNotesInput" name="notes" rows="3" maxlength="1000" placeholder="State facts, dates, and medical/official proof details justifying the suspension." required></textarea>
            </div>
            <div class="modal-actions" style="margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closePauseModal()">Cancel</button>
                <button type="submit" class="btn-danger" id="btnSubmitPause">Pause Mediation Clock</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
window.caseWorkspaceId = <?php echo (int) $caseId; ?>;
window.canManageSuspension = <?php echo $canManageSuspension ? 'true' : 'false'; ?>;
</script>
<script src="../../assets/js/case-workspace.js?v=<?php echo filemtime(__DIR__ . '/../../layouts/header.php'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
