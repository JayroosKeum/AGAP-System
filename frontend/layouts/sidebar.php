<?php
$roleId = (int) ($_SESSION['role_id'] ?? 0);
$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$isActive = static function (array $pages) use ($currentPage): string {
    return in_array($currentPage, $pages, true) ? ' class="is-active" aria-current="page"' : '';
};
$isGroupActive = static function (array $pages) use ($currentPage): bool {
    return in_array($currentPage, $pages, true);
};
$dashboardLink = [1 => '../dashboard/admin-dashboard.php', 2 => '../dashboard/clerk-dashboard.php', 3 => '../dashboard/lupon-dashboard.php', 4 => '../dashboard/server-dashboard.php'][$roleId] ?? '../auth/login.php';
?>
<aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-main">
            <img class="brand-mark" src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
            <div class="sidebar-brand-text"><strong>AGAP</strong><span>Case Management</span></div>
        </div>
        <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseBtn" aria-label="Toggle sidebar" title="Toggle sidebar">
            <svg class="sidebar-collapse-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="11 17 6 12 11 7"></polyline>
                <polyline points="18 17 13 12 18 7"></polyline>
            </svg>
        </button>
    </div>
    <nav class="sidebar-nav" aria-label="Main navigation">
        <details class="nav-group" open data-nav-group="workspace">
            <summary>Workspace</summary>
            <div class="nav-group-links">
                <a href="<?php echo $dashboardLink; ?>"<?php echo $isActive(['admin-dashboard.php', 'clerk-dashboard.php', 'lupon-dashboard.php', 'server-dashboard.php']); ?> title="Dashboard">
                    <span class="nav-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
                    </span>
                    <span class="nav-link-label">Dashboard</span>
                </a>
                <a href="../notifications/inbox.php"<?php echo $isActive(['inbox.php']); ?> title="Notifications">
                    <span class="nav-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                    </span>
                    <span class="nav-link-label">Notifications</span>
                </a>
            </div>
        </details>

        <?php if (in_array($roleId, [1, 2], true)): ?>
            <details class="nav-group"<?php echo $isGroupActive(['complaint-list.php', 'complaint-create.php', 'complaint-details.php', 'case-list.php', 'create-case.php', 'case-assignment.php']) ? ' open' : ''; ?> data-nav-group="case-management">
                <summary>Case management</summary>
                <div class="nav-group-links">
                    <a href="../complaints/complaint-list.php"<?php echo $isActive(['complaint-list.php', 'complaint-create.php', 'complaint-details.php']); ?> title="Complaints">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12.01" y2="18"></line><line x1="12" y1="11" x2="12" y2="15"></line></svg>
                        </span>
                        <span class="nav-link-label">Complaints</span>
                    </a>
                    <a href="../cases/case-list.php"<?php echo $isActive(['case-list.php', 'create-case.php', 'case-assignment.php']); ?> title="Cases and assignments">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="M7 21h10"></path><path d="M12 3v18"></path><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"></path></svg>
                        </span>
                        <span class="nav-link-label">Cases and assignments</span>
                    </a>
                </div>
            </details>
            <details class="nav-group"<?php echo $isGroupActive(['document-center.php', 'summons.php', 'settlements.php', 'cfa.php', 'kp-form-9.php', 'proof-service.php']) ? ' open' : ''; ?> data-nav-group="operations">
                <summary>Operations</summary>
                <div class="nav-group-links">
                    <a href="../documents/document-center.php"<?php echo $isActive(['document-center.php', 'summons.php', 'settlements.php', 'cfa.php', 'kp-form-9.php']); ?> title="KP Documents and Forms">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        </span>
                        <span class="nav-link-label">KP Documents and Forms</span>
                    </a>
                    <a href="../gps/proof-service.php"<?php echo $isActive(['proof-service.php']); ?> title="Proof of service">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"></path><path d="m9 10 2 2 4-4"></path></svg>
                        </span>
                        <span class="nav-link-label">Proof of service</span>
                    </a>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($roleId === 4): ?>
            <details class="nav-group" open data-nav-group="field-service">
                <summary>Field service</summary>
                <div class="nav-group-links">
                    <a href="../gps/proof-service.php"<?php echo $isActive(['proof-service.php']); ?> title="Proof of service">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"></path><path d="m9 10 2 2 4-4"></path></svg>
                        </span>
                        <span class="nav-link-label">Proof of service</span>
                    </a>
                </div>
            </details>
        <?php endif; ?>

        <?php if (in_array($roleId, [1, 2], true)): ?>
            <details class="nav-group"<?php echo $isGroupActive(['user-list.php', 'roles.php', 'index.php']) ? ' open' : ''; ?> data-nav-group="administration">
                <summary>Administration</summary>
                <div class="nav-group-links">
                    <?php if ($roleId === 1): ?>
                        <a href="../users/user-list.php"<?php echo $isActive(['user-list.php', 'roles.php']); ?> title="Users and roles">
                            <span class="nav-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </span>
                            <span class="nav-link-label">Users and roles</span>
                        </a>
                    <?php endif; ?>
                    <a href="../settings/index.php"<?php echo $isActive(['index.php']); ?> title="Settings">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        </span>
                        <span class="nav-link-label">Settings</span>
                    </a>
                </div>
            </details>
        <?php else: ?>
            <details class="nav-group" open data-nav-group="personal">
                <summary>Personal</summary>
                <div class="nav-group-links">
                    <a href="../settings/index.php?tab=profile"<?php echo $isActive(['index.php']); ?> title="Account Settings">
                        <span class="nav-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        </span>
                        <span class="nav-link-label">Account Settings</span>
                    </a>
                </div>
            </details>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer"><span>AGAP</span><small>Barangay Tumana</small></div>
</aside>
