<?php
require_once __DIR__ . '/../../backend/middleware/rbac.php';
$roleId = (int) ($_SESSION['role_id'] ?? 0);
$roleName = agapGetRoleName(isset($db) && $db instanceof PDO ? $db : null);
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$normalizedPath = str_replace('\\', '/', $requestPath);
$pathSegments = array_values(array_filter(explode('/', $normalizedPath), static fn($seg) => $seg !== ''));
$currentFile = !empty($pathSegments) ? end($pathSegments) : '';
$currentDir = count($pathSegments) >= 2 ? $pathSegments[count($pathSegments) - 2] : '';

$isActive = static function (array $pages, ?string $dir = null) use ($currentFile, $currentDir): string {
    if ($dir !== null && $currentDir !== $dir) {
        return '';
    }
    return in_array($currentFile, $pages, true) ? ' class="is-active" aria-current="page"' : '';
};
$isGroupActive = static function (array $dirs, array $pages = []) use ($currentFile, $currentDir): bool {
    if (!empty($dirs) && in_array($currentDir, $dirs, true)) {
        return true;
    }
    return !empty($pages) && in_array($currentFile, $pages, true);
};
$dashboardLink = [1 => '../dashboard/admin-dashboard.php', 2 => '../dashboard/clerk-dashboard.php', 3 => '../dashboard/lupon-dashboard.php', 4 => '../dashboard/server-dashboard.php', 5 => '../dashboard/clerk-dashboard.php'][$roleId] ?? '../auth/login.php';
?>
<aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-main">
            <img class="brand-mark" src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
            <div class="sidebar-brand-text"><strong>AGAP</strong><span>Case Management</span></div>
        </div>     
    </div>
    <nav class="sidebar-nav" aria-label="Main navigation">
        <details class="nav-group" open data-nav-group="workspace">
            <summary>Workspace</summary>
            <div class="nav-group-links">
                <a href="<?php echo $dashboardLink; ?>"<?php echo $isActive(['admin-dashboard.php', 'clerk-dashboard.php', 'lupon-dashboard.php', 'server-dashboard.php'], 'dashboard'); ?> title="Dashboard">
                    <span class="nav-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
                    </span>
                    <span class="nav-link-label">Dashboard</span>
                </a>
                <a href="../notifications/inbox.php"<?php echo $isActive(['inbox.php'], 'notifications'); ?> title="Notifications">
                    <span class="nav-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                    </span>
                    <span class="nav-link-label">Notifications</span>
                </a>
            </div>
        </details>

        <?php if (in_array($roleId, [1, 2, 5], true) || in_array($roleName, ['Administrator', 'Lupon Clerk', 'Secretary', 'BPSO', 'LISO'], true)): ?>
            <details class="nav-group"<?php echo $isGroupActive(['paanyaya', 'complaints', 'cases']) ? ' open' : ''; ?> data-nav-group="case-management">
                <summary>Case management</summary>
                <div class="nav-group-links">
                    <a href="../paanyaya/index.php"<?php echo $isActive(['index.php', 'create.php', 'details.php', 'stage.php'], 'paanyaya'); ?> title="Paanyaya (Invitations)">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        </span>
                        <span class="nav-link-label">Paanyaya</span>
                    </a>
                    <a href="../complaints/complaint-list.php"<?php echo $isActive(['complaint-list.php', 'complaint-create.php', 'complaint-details.php'], 'complaints'); ?> title="Complaints">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12.01" y2="18"></line><line x1="12" y1="11" x2="12" y2="15"></line></svg>
                        </span>
                        <span class="nav-link-label">Complaints</span>
                    </a>
                    <a href="../cases/case-list.php"<?php echo $isActive(['case-list.php', 'case-assignment.php'], 'cases'); ?> title="Cases and assignments">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="M7 21h10"></path><path d="M12 3v18"></path><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"></path></svg>
                        </span>
                        <span class="nav-link-label">Cases and assignments</span>
                    </a>
                </div>
            </details>
            <details class="nav-group"<?php echo $isGroupActive(['gps', 'reports']) ? ' open' : ''; ?> data-nav-group="operations">
                <summary>Operations</summary>
                <div class="nav-group-links">
                    <a href="../gps/proof-service.php"<?php echo $isActive(['proof-service.php'], 'gps'); ?> title="Proof of service">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"></path><path d="m9 10 2 2 4-4"></path></svg>
                        </span>
                        <span class="nav-link-label">Proof of service</span>
                    </a>
                    <?php if (in_array($roleId, [1, 2], true) || in_array($roleName, ['Administrator', 'Lupon Clerk'], true)): ?>
                        <a href="../reports/report-list.php"<?php echo $isActive(['report-list.php'], 'reports'); ?> title="LTIA &amp; DILG Reports">
                            <span class="nav-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg>
                            </span>
                            <span class="nav-link-label">LTIA &amp; Transmittals</span>
                        </a>
                    <?php endif; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($roleId === 3 || $roleName === 'Lupon Member'): ?>
            <details class="nav-group"<?php echo $isGroupActive(['dashboard', 'complaints']) ? ' open' : ''; ?> data-nav-group="lupon-review">
                <summary>Lupon Review (Read-Only)</summary>
                <div class="nav-group-links">
                    <a href="../dashboard/lupon-dashboard.php#jurisdictionQueue" title="Verify Resident Submissions">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                        </span>
                        <span class="nav-link-label">Jurisdiction Review</span>
                    </a>
                    <a href="../dashboard/lupon-dashboard.php#assignedCasesSection" title="Case Dossiers, Minutes &amp; KP Forms">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                        </span>
                        <span class="nav-link-label">Minutes &amp; KP Forms</span>
                    </a>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($roleId === 4): ?>
            <details class="nav-group"<?php echo $isGroupActive(['gps']) ? ' open' : ''; ?> data-nav-group="field-service">
                <summary>Field service</summary>
                <div class="nav-group-links">
                    <a href="../gps/proof-service.php"<?php echo $isActive(['proof-service.php'], 'gps'); ?> title="Proof of service">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"></path><path d="m9 10 2 2 4-4"></path></svg>
                        </span>
                        <span class="nav-link-label">Proof of service</span>
                    </a>
                </div>
            </details>
        <?php endif; ?>

        <?php if (in_array($roleId, [1, 2, 5], true) || in_array($roleName, ['Administrator', 'Lupon Clerk', 'Secretary', 'BPSO', 'LISO'], true)): ?>
            <details class="nav-group"<?php echo $isGroupActive(['users', 'settings']) ? ' open' : ''; ?> data-nav-group="administration">
                <summary>Administration</summary>
                <div class="nav-group-links">
                    <?php if (in_array($roleId, [1, 2, 5], true) || in_array($roleName, ['Administrator', 'Secretary', 'Lupon Clerk'], true)): ?>
                        <?php
                        $isAdminNav = ($roleId === 1 || $roleName === 'Administrator');
                        $usersNavHref = $isAdminNav ? '../users/user-list.php' : '../users/user-list.php?tab=residents';
                        $usersNavLabel = $isAdminNav ? 'Users & Residents' : 'Resident Verification';
                        ?>
                        <a href="<?php echo $usersNavHref; ?>"<?php echo $isActive(['user-list.php', 'resident-verification.php'], 'users'); ?> title="<?php echo htmlspecialchars($usersNavLabel, ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="nav-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </span>
                            <span class="nav-link-label"><?php echo htmlspecialchars($usersNavLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                        </a>
                    <?php endif; ?>
                    <a href="../settings/index.php"<?php echo $isActive(['index.php'], 'settings'); ?> title="Settings">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        </span>
                        <span class="nav-link-label">Settings</span>
                    </a>
                </div>
            </details>
        <?php else: ?>
            <details class="nav-group"<?php echo $isGroupActive(['settings']) ? ' open' : ''; ?> data-nav-group="personal">
                <summary>Personal</summary>
                <div class="nav-group-links">
                    <a href="../settings/index.php?tab=profile"<?php echo $isActive(['index.php'], 'settings'); ?> title="Account Settings">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        </span>
                        <span class="nav-link-label">Account Settings</span>
                    </a>
                </div>
            </details>
        <?php endif; ?>
    </nav>
</aside>
