<?php
$roleNames = [1 => 'Administrator', 2 => 'Lupon Clerk', 3 => 'Lupon Member', 4 => 'Summons Server'];
$roleName = $roleNames[(int) ($_SESSION['role_id'] ?? 0)] ?? 'Staff Member';
?>
<nav class="navbar">
    <button class="nav-menu-toggle" type="button" aria-label="Open navigation" aria-expanded="false" data-sidebar-toggle>Menu</button>
    <div class="navbar-context"><span class="navbar-eyebrow">Barangay Tumana</span><span class="navbar-title">Katarungang Pambarangay</span></div>
    <div class="navbar-right">

        <!-- Modern Notification Popover Trigger (Inspiration from Modern App Header) -->
        <div class="nav-notification-wrapper" id="navNotificationWrapper">
            <button type="button" class="nav-bell-btn" id="navBellBtn" aria-label="Open notifications" aria-expanded="false" title="Notifications" onclick="toggleNotificationPopover(event)">
                <svg class="nav-bell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <span class="nav-bell-badge" id="navBellBadge" style="display: none;"></span>
            </button>

            <!-- Dropdown Popover Card -->
            <div class="nav-notification-popover" id="navNotificationPopover" style="display: none;" role="dialog" aria-label="Notifications Dropdown">
                <!-- Header -->
                <div class="nav-popover-header">
                    <div class="nav-popover-title-row">
                        <span class="nav-popover-title">Notifications</span>
                        <button type="button" class="nav-popover-markall" id="popoverMarkAllBtn" onclick="markAllAsReadFromPopover(event)">Mark all as read</button>
                    </div>
                    <!-- Tabs -->
                    <div class="nav-popover-tabs" role="tablist">
                        <button type="button" class="nav-tab-btn active" data-tab="all" onclick="setPopoverTab('all', event)">
                            All <span class="nav-tab-count" id="popoverCountAll">0</span>
                        </button>
                        <button type="button" class="nav-tab-btn" data-tab="urgent" onclick="setPopoverTab('urgent', event)">
                            Urgent <span class="nav-tab-count" id="popoverCountUrgent">0</span>
                        </button>
                        <button type="button" class="nav-tab-btn" data-tab="warning" onclick="setPopoverTab('warning', event)">
                            Deadlines <span class="nav-tab-count" id="popoverCountWarning">0</span>
                        </button>
                        <a href="../notifications/inbox.php" class="nav-tab-icon-link" title="Full Notifications Center" aria-label="Full Notifications Center">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        </a>
                    </div>
                </div>

                <!-- Scrollable Item List -->
                <div class="nav-popover-list" id="navPopoverList">
                    <div class="nav-popover-loading">Loading notifications...</div>
                </div>

                <!-- Footer -->
                <div class="nav-popover-footer">
                    <a href="../notifications/inbox.php" class="nav-popover-viewall">
                        View all in Notifications Inbox &rarr;
                    </a>
                </div>
            </div>
        </div>

        <div class="user-summary"><strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo $roleName; ?></span></div>
        <a class="notification-link" href="../settings/index.php<?php echo in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true) ? '' : '?tab=profile'; ?>">Settings</a>
        <a class="notification-link" href="../auth/change-password.php">Change password</a>
        <form method="post" action="../../../backend/api/auth/logout.php" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CsrfService::token(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="logout-btn">Sign out</button>
        </form>
    </div>
</nav>
