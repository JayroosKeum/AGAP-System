<?php
$workflowCaseId = isset($caseId) ? (int) $caseId : (int) ($_GET['case_id'] ?? $_GET['id'] ?? 0);
?>
<link rel="stylesheet" href="../assets/css/workflow-workspace.css">
<div id="caseWorkflowWorkspace" data-case-id="<?= $workflowCaseId ?>"></div>
<script src="../assets/js/workflow-workspace.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const workspace = document.getElementById('caseWorkflowWorkspace');
    const caseId = Number(workspace?.dataset.caseId || 0);
    if (caseId > 0 && window.AGAPWorkflowWorkspace) {
        window.AGAPWorkflowWorkspace.load(caseId);
    }
});
</script>
