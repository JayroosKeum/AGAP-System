<?php
declare(strict_types=1);

$_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));

$caseProcess = [
    ['page' => 'summons', 'step' => '01', 'title' => 'Summon', 'description' => 'Prepare, issue, and track service of the required summons and notices.'],
    ['page' => 'mediation', 'step' => '02', 'title' => 'Mediation', 'description' => 'Manage mediation sessions before the Punong Barangay and record their outcomes.'],
    ['page' => 'conciliation', 'step' => '03', 'title' => 'Conciliation', 'description' => 'Manage Pangkat conciliation after a valid referral from mediation.'],
    ['page' => 'cfa', 'step' => '04', 'title' => 'CFA', 'description' => 'Review eligibility and issue the appropriate Certificate to File Action when requirements are satisfied.'],
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
                <?php foreach ($caseProcess as $process): ?>
                    <article class="process-card">
                        <span class="process-step"><?= htmlspecialchars($process['step'], ENT_QUOTES, 'UTF-8') ?></span>
                        <div>
                            <h2><?= htmlspecialchars($process['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= htmlspecialchars($process['description'], ENT_QUOTES, 'UTF-8') ?></p>
                            <a href="index.php?page=<?= urlencode($process['page']) ?>">Open <?= htmlspecialchars($process['title'], ENT_QUOTES, 'UTF-8') ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

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
</body>
</html>
