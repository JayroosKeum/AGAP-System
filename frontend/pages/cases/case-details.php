<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) { die('Access Denied'); }
$caseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) { header('Location: case-list.php'); exit; }
$canManageHearings = in_array((int) $_SESSION['role_id'], [1, 2], true);
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<div class="dashboard-layout"><?php include '../../layouts/sidebar.php'; ?><div class="main-content"><?php include '../../layouts/navbar.php'; ?>
    <div class="page-header"><div><a href="case-list.php" class="back-link"><svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg><span>Back to Cases</span></a><h1 id="workspaceTitle">Case Workspace</h1><p id="workspaceSubtitle">Loading case information…</p></div><div class="action-buttons"><a id="assignLink" class="btn-create">Manage Team</a><?php if ($canManageHearings): ?><a id="hearingLink" class="btn-create">Schedule Mediation</a><?php endif; ?></div></div>
    <p id="workspaceMessage" role="alert"></p>
    <section class="workspace-grid">
      <div class="table-container"><h3>Case overview</h3><div id="caseOverview"></div></div>
      <div class="table-container"><h3>Case team</h3><div id="caseTeam"></div></div>
      <div class="table-container"><h3>Hearings and deadlines</h3><div id="caseHearings"></div></div>
      <div class="table-container"><h3>Generated documents</h3><div id="caseDocuments"></div></div>
      <div class="table-container"><h3>Proof of service</h3><div id="caseProofs"></div></div>
    </section>
</div></div>
<script>window.caseWorkspaceId = <?php echo (int) $caseId; ?>;</script><script src="../../assets/js/case-workspace.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/case-workspace.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
