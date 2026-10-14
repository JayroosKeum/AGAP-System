<?php
declare(strict_types=1);

$_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));
$administratorName = trim(
    (string) ($_SESSION['admin_first_name'] ?? 'System') . ' ' .
    (string) ($_SESSION['admin_last_name'] ?? 'Administrator')
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | AGAP-Remastered</title>
    <link rel="stylesheet" href="frontend/assets/css/app.css">
    <link rel="stylesheet" href="frontend/assets/css/dashboard.css">
</head>
<body class="dashboard-body">
<div class="dashboard-layout">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>

    <div class="dashboard-workspace">
        <header class="dashboard-topbar">
            <div>
                <p class="dashboard-section-label">Administrator Workspace</p>
                <h1>Dashboard</h1>
            </div>

            <div class="topbar-account">
                <span class="topbar-account-label">Signed in as</span>
                <strong><?= htmlspecialchars($administratorName, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </header>

        <main class="dashboard-content">
            <section class="dashboard-welcome">
                <div>
                    <p class="dashboard-section-label">AGAP-Remastered</p>
                    <h2>Welcome, <?= htmlspecialchars($administratorName, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p>Use the side navigation to access case-management and administration modules.</p>
                </div>
            </section>

            <section class="dashboard-summary" aria-label="System summary">
                <article class="summary-card">
                    <span class="summary-label">Complaints</span>
                    <strong class="summary-value">0</strong>
                    <span class="summary-description">Recorded complaints</span>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Active Cases</span>
                    <strong class="summary-value">0</strong>
                    <span class="summary-description">Cases currently in process</span>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Upcoming Hearings</span>
                    <strong class="summary-value">0</strong>
                    <span class="summary-description">Scheduled proceedings</span>
                </article>

                <article class="summary-card">
                    <span class="summary-label">Pending Documents</span>
                    <strong class="summary-value">0</strong>
                    <span class="summary-description">Documents for completion</span>
                </article>
            </section>

            <section class="dashboard-panels">
                <article class="dashboard-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="dashboard-section-label">Cases</p>
                            <h2>Recent activity</h2>
                        </div>
                        <a href="index.php?page=cases">View cases</a>
                    </div>

                    <div class="empty-state">
                        <strong>No case activity yet</strong>
                        <p>Recent case updates will appear in this section.</p>
                    </div>
                </article>

                <article class="dashboard-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="dashboard-section-label">Schedule</p>
                            <h2>Upcoming hearings</h2>
                        </div>
                        <a href="index.php?page=hearings">View hearings</a>
                    </div>

                    <div class="empty-state">
                        <strong>No scheduled hearings</strong>
                        <p>Upcoming hearing schedules will appear in this section.</p>
                    </div>
                </article>
            </section>
        </main>
    </div>
</div>
</body>
</html>
