<?php
declare(strict_types=1);

if (!isset($modulePage) || !is_array($modulePage)) {
    throw new RuntimeException('Module page configuration is required.');
}

$_SESSION['logout_csrf_token'] = bin2hex(random_bytes(32));

$section = (string) ($modulePage['section'] ?? 'AGAP-Remastered');
$title = (string) ($modulePage['title'] ?? 'Module');
$subtitle = (string) ($modulePage['subtitle'] ?? 'Module workspace');
$description = (string) ($modulePage['description'] ?? 'Module content will appear here.');
$emptyTitle = (string) ($modulePage['empty_title'] ?? 'No records available');
$emptyDescription = (string) ($modulePage['empty_description'] ?? 'Records will appear here after this module is connected to the database.');
$actionLabel = $modulePage['action_label'] ?? null;
$actionUrl = $modulePage['action_url'] ?? null;
$backLabel = $modulePage['back_label'] ?? null;
$backUrl = $modulePage['back_url'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | AGAP-Remastered</title>
    <link rel="stylesheet" href="frontend/assets/css/app.css">
    <link rel="stylesheet" href="frontend/assets/css/dashboard.css">
</head>
<body class="dashboard-body">
<div class="dashboard-layout">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="dashboard-workspace">
        <header class="dashboard-topbar">
            <div>
                <p class="dashboard-section-label"><?= htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?></p>
                <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            </div>

            <?php if ($actionLabel !== null && $actionUrl !== null): ?>
                <a class="workspace-button" href="<?= htmlspecialchars((string) $actionUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) $actionLabel, ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php elseif ($backLabel !== null && $backUrl !== null): ?>
                <a class="workspace-button secondary" href="<?= htmlspecialchars((string) $backUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) $backLabel, ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endif; ?>
        </header>

        <main class="dashboard-content">
            <section class="dashboard-welcome">
                <div>
                    <p class="dashboard-section-label"><?= htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?></p>
                    <h2><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </section>

            <section class="dashboard-panel module-content-panel">
                <div class="panel-heading">
                    <div>
                        <p class="dashboard-section-label">Records</p>
                        <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
                    </div>
                </div>

                <div class="empty-state">
                    <strong><?= htmlspecialchars($emptyTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                    <p><?= htmlspecialchars($emptyDescription, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
