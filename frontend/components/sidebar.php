<?php
declare(strict_types=1);

$currentPage = (string) ($_GET['page'] ?? 'dashboard');

$caseProcessItems = [
    ['page' => 'summons', 'label' => 'Summon'],
    ['page' => 'mediation', 'label' => 'Mediation'],
    ['page' => 'conciliation', 'label' => 'Conciliation'],
    ['page' => 'cfa', 'label' => 'CFA'],
];

$caseProcessPages = array_column($caseProcessItems, 'page');
$isCasesActive = $currentPage === 'cases' || in_array($currentPage, $caseProcessPages, true);

$sections = [
    [
        'label' => 'Overview',
        'short' => 'OV',
        'pages' => ['dashboard'],
        'items' => [
            ['page' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'DB'],
        ],
    ],
    [
        'label' => 'Case Management',
        'short' => 'CM',
        'pages' => ['profiles', 'complaints', 'cases', 'summons', 'mediation', 'conciliation', 'cfa'],
        'items' => [
            ['page' => 'profiles', 'label' => 'Profiles', 'icon' => 'PF'],
            ['page' => 'complaints', 'label' => 'Complaints', 'icon' => 'CP'],
        ],
        'has_cases' => true,
    ],
    [
        'label' => 'Records',
        'short' => 'RC',
        'pages' => ['documents', 'reports'],
        'items' => [
            ['page' => 'documents', 'label' => 'Documents', 'icon' => 'DC'],
            ['page' => 'reports', 'label' => 'Reports', 'icon' => 'RP'],
        ],
    ],
    [
        'label' => 'Administration',
        'short' => 'AD',
        'pages' => ['users', 'settings'],
        'items' => [
            ['page' => 'users', 'label' => 'Users', 'icon' => 'US'],
            ['page' => 'settings', 'label' => 'Settings', 'icon' => 'SG'],
        ],
    ],
];
?>
<link rel="stylesheet" href="frontend/assets/css/navigation-pane.css">

<aside class="dashboard-sidebar smooth-sidebar" aria-label="Application navigation">
    <div class="sidebar-brand">
        <a class="sidebar-brand-link" href="index.php?page=dashboard">
            <span class="sidebar-brand-mark" aria-hidden="true">A</span>
            <span class="sidebar-brand-copy">
                <strong>AGAP</strong>
                <small>Remastered</small>
            </span>
        </a>
    </div>

    <nav class="sidebar-navigation smooth-navigation" aria-label="Main navigation">
        <?php foreach ($sections as $section): ?>
            <?php $sectionActive = in_array($currentPage, $section['pages'], true); ?>

            <section class="smooth-nav-section<?= $sectionActive ? ' has-active-page' : '' ?>">
                <div class="smooth-section-heading">
                    <span class="smooth-section-short" aria-hidden="true"><?= htmlspecialchars($section['short'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="smooth-section-label"><?= htmlspecialchars(strtoupper($section['label']), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="smooth-section-arrow" aria-hidden="true">⌄</span>
                </div>

                <div class="smooth-section-menu">
                    <ul class="sidebar-list">
                        <?php foreach ($section['items'] as $item): ?>
                            <?php $active = $currentPage === $item['page']; ?>
                            <li>
                                <a class="sidebar-link<?= $active ? ' is-active' : '' ?>" href="index.php?page=<?= urlencode($item['page']) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                                    <span class="sidebar-icon" aria-hidden="true"><?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="sidebar-link-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>

                        <?php if (!empty($section['has_cases'])): ?>
                            <li class="smooth-case-menu<?= $isCasesActive ? ' has-active-page' : '' ?>">
                                <a class="sidebar-link smooth-case-trigger<?= $isCasesActive ? ' is-active' : '' ?>" href="index.php?page=cases" <?= $currentPage === 'cases' ? 'aria-current="page"' : '' ?>>
                                    <span class="sidebar-icon" aria-hidden="true">CS</span>
                                    <span class="sidebar-link-label">Cases</span>
                                    <span class="smooth-case-arrow" aria-hidden="true">›</span>
                                </a>

                                <ul class="smooth-case-sublist" aria-label="Cases process">
                                    <?php foreach ($caseProcessItems as $process): ?>
                                        <?php $active = $currentPage === $process['page']; ?>
                                        <li>
                                            <a class="sidebar-sublink<?= $active ? ' is-active' : '' ?>" href="index.php?page=<?= urlencode($process['page']) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                                                <?= htmlspecialchars($process['label'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-account smooth-sidebar-account">
        <div class="sidebar-account-copy">
            <div class="sidebar-account-name"><?= htmlspecialchars(trim(($_SESSION['admin_first_name'] ?? 'System') . ' ' . ($_SESSION['admin_last_name'] ?? 'Administrator')), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="sidebar-account-role">Administrator</div>
        </div>

        <form method="post" action="backend/api/auth/logout.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['logout_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <button class="sidebar-logout" type="submit">Log out</button>
        </form>
    </div>
</aside>
