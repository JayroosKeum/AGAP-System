<?php
declare(strict_types=1);
$_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conciliation | AGAP-Remastered</title>
    <link rel="stylesheet" href="frontend/assets/css/app.css">
    <link rel="stylesheet" href="frontend/assets/css/dashboard.css">
</head>
<body class="dashboard-body">
<div class="dashboard-layout">
    <?php require __DIR__ . '/../components/sidebar.php'; ?>
    <div class="dashboard-workspace">
        <header class="dashboard-topbar">
            <div><p class="dashboard-section-label">Cases / Main Process</p><h1>Conciliation</h1></div>
            <a class="workspace-button secondary" href="index.php?page=cases">Back to Cases</a>
        </header>
        <main class="dashboard-content">
            <section class="dashboard-panel">
                <div class="panel-heading"><div><p class="dashboard-section-label">Process stage</p><h2>Conciliation</h2></div></div>
                <div class="empty-state"><strong>Conciliation module shell</strong><p>This page is ready for the existing Conciliation workflow to be traced, reused, and connected without creating a parallel process.</p></div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
