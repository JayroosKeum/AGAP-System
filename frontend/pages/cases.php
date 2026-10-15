<?php

declare(strict_types=1);

$_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));

$caseId = filter_var(
    $_GET['case_id'] ?? $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$caseId = $caseId ? (int) $caseId : 0;

$caseProcess = [
    [
        'page' => 'summons',
        'step' => '01',
        'title' => 'Summon',
        'description' => 'Prepare, issue, and track service of the required summons and notices.',
    ],
    [
        'page' => 'mediation',
        'step' => '02',
        'title' => 'Mediation',
        'description' => 'Manage mediation sessions before the Punong Barangay and record their outcomes.',
    ],
    [
        'page' => 'conciliation',
        'step' => '03',
        'title' => 'Conciliation',
        'description' => 'Manage Pangkat conciliation after a valid referral from mediation.',
    ],
    [
        'page' => 'cfa',
        'step' => '04',
        'title' => 'CFA',
        'description' => 'Review eligibility and issue the appropriate Certificate to File Action when requirements are satisfied.',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cases | AGAP-Remastered</title>
    <link rel="stylesheet" href="frontend/assets/css/app.css">
    <link rel="stylesheet" href="frontend/assets/css/dashboard.css">
    <link rel="stylesheet" href="frontend/assets/css/workflow-workspace.css">
</head>
<body class="dashboard-body">
<div class="dashboard-layout">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <div class="dashboard-workspace">
        <header class="dashboard-topbar">
            <div>
                <p class="dashboard-section-label">Case Management</p>
                <h1>Cases</h1>
            </div>
        </header>

        <main class="dashboard-content">
            <section class="dashboard-welcome">
                <div>
                    <p class="dashboard-section-label">Main case process</p>
                    <h2>Summon → Mediation → Conciliation → CFA</h2>
                    <p>Each case follows controlled backend transitions. A later stage must not proceed until its required prior-stage conditions are satisfied.</p>
                </div>
            </section>

            <section class="case-process" aria-label="Main case process">
                <?php
                foreach ($caseProcess as $process) {
                    $processUrl = 'index.php?page=' . urlencode($process['page']);
                    if ($caseId > 0) {
                        $processUrl .= '&case_id=' . $caseId;
                    }
                    ?>
                    <article class="process-card">
                        <span class="process-step"><?= htmlspecialchars($process['step'], ENT_QUOTES, 'UTF-8') ?></span>
                        <div>
                            <h2><?= htmlspecialchars($process['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= htmlspecialchars($process['description'], ENT_QUOTES, 'UTF-8') ?></p>
                            <a href="<?= htmlspecialchars($processUrl, ENT_QUOTES, 'UTF-8') ?>">
                                Open <?= htmlspecialchars($process['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </div>
                    </article>
                    <?php
                }
                ?>
            </section>

            <?php
            if ($caseId > 0) {
                ?>
                <section class="dashboard-panel cases-workflow-panel" aria-labelledby="caseWorkflowHeading">
                    <div class="panel-heading">
                        <div>
                            <p class="dashboard-section-label">Selected case</p>
                            <h2 id="caseWorkflowHeading">Case Workflow</h2>
                        </div>
                        <a href="index.php?page=cases">Close Workflow</a>
                    </div>

                    <div id="caseWorkflowWorkspace" data-case-id="<?= $caseId ?>">
                        <div class="workflow-loading">Loading case workflow...</div>
                    </div>
                </section>
                <?php
            }
            ?>

            <section class="dashboard-panel cases-list-panel">
                <div class="panel-heading">
                    <div>
                        <p class="dashboard-section-label">Case records</p>
                        <h2>All cases</h2>
                    </div>
                </div>

                <div class="empty-state">
                    <strong>No cases recorded</strong>
                    <p>Docketed cases will appear here after the Cases module is connected to the database.</p>
                </div>
            </section>
        </main>
    </div>
</div>

<script src="frontend/assets/js/workflow-workspace.js"></script>
<?php
if ($caseId > 0) {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const caseId = <?= (int) $caseId ?>;

        if (
            window.AGAPWorkflowWorkspace &&
            typeof window.AGAPWorkflowWorkspace.load === 'function'
        ) {
            window.AGAPWorkflowWorkspace.load(caseId, 'caseWorkflowWorkspace');
        }
    });

    window.addEventListener('agap:workflow-action', function (event) {
        const detail = event.detail || {};
        const action = detail.action || '';
        const selectedCaseId = Number(detail.caseId || 0);

        if (selectedCaseId < 1 || action === '') {
            return;
        }

        const routes = {
            ISSUE_INITIAL_NOTICES: 'index.php?page=summons&case_id=' + selectedCaseId,
            START_SUMMONS_ATTEMPT: 'index.php?page=summons&case_id=' + selectedCaseId,
            RECORD_SUMMONS_ATTEMPT: 'index.php?page=summons&case_id=' + selectedCaseId,
            CONFIRM_MEDIATION_READINESS: 'index.php?page=summons&case_id=' + selectedCaseId,
            VIEW_MEDIATION: 'index.php?page=mediation&case_id=' + selectedCaseId,
            RECORD_MEDIATION_OUTCOME: 'index.php?page=mediation&case_id=' + selectedCaseId,
            ACTIVATE_PANGKAT: 'index.php?page=conciliation&case_id=' + selectedCaseId,
            VIEW_CONCILIATION: 'index.php?page=conciliation&case_id=' + selectedCaseId,
            RECORD_CONCILIATION_OUTCOME: 'index.php?page=conciliation&case_id=' + selectedCaseId,
            SUBMIT_CFA_REQUEST: 'index.php?page=cfa&case_id=' + selectedCaseId,
            REVIEW_CFA_REQUEST: 'index.php?page=cfa&case_id=' + selectedCaseId,
            ISSUE_CFA: 'index.php?page=cfa&case_id=' + selectedCaseId,
            RELEASE_CFA: 'index.php?page=cfa&case_id=' + selectedCaseId,
            ARCHIVE_CFA_CASE: 'index.php?page=cases&case_id=' + selectedCaseId,
            FINALIZE_SETTLEMENT_CASE: 'index.php?page=cases&case_id=' + selectedCaseId,
            ARCHIVE_CLOSED_CASE: 'index.php?page=cases&case_id=' + selectedCaseId
        };

        if (routes[action]) {
            window.location.href = routes[action];
        }
    });
    </script>
    <?php
}
?>
</body>
</html>
