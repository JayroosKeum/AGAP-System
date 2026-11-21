<?php
$residentTopbarEscape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$residentTopbarCsrf = CsrfService::token();
$residentTopbarDisplayName = isset($residentTopbarName) && is_string($residentTopbarName) ? trim($residentTopbarName) : '';
$residentCurrentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<header class="resident-topbar">
    <a class="resident-dashboard-brand" href="../resident/dashboard.php" aria-label="Tahanan ng AGAP Portal ng Residente" data-en-aria-label="AGAP Resident Portal home">
        <img src="../../assets/images/tumana_logo.jpg" alt="Selyo ng Barangay Tumana" data-en-alt="Barangay Tumana seal">
        <span><strong>AGAP</strong><small data-en="Resident Portal">Portal ng Residente</small></span>
    </a>
    <div class="resident-topbar-actions">
        <div class="lang-switch-wrapper" id="langSwitchWrapper">
            <button type="button" class="lang-switch-btn" id="langSwitchToggle" onclick="toggleAppLanguage()" aria-label="Palitan ang wika (Tagalog / English)" title="Kasalukuyang Wika: Tagalog (I-click para mag-English)">
                <span class="lang-pill lang-tl active" id="langPillTl">TL</span>
                <span class="lang-separator">|</span>
                <span class="lang-pill lang-en" id="langPillEn">EN</span>
            </button>
        </div>

        <div class="nav-notification-wrapper" id="navNotificationWrapper" data-resident="1">
            <button type="button" class="nav-bell-btn" id="navBellBtn" aria-label="Buksan ang mga abiso" data-en-aria-label="Open notifications" aria-expanded="false" title="Mga Abiso" data-en-title="Notifications" onclick="toggleNotificationPopover(event)">
                <svg class="nav-bell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                <span class="nav-bell-badge" id="navBellBadge" style="display: none;"></span>
            </button>

            <div class="nav-notification-popover" id="navNotificationPopover" style="display: none;" role="dialog" aria-label="Listahan ng mga Abiso" data-en-aria-label="Notifications Dropdown">
                <div class="nav-popover-header">
                    <div class="nav-popover-title-row">
                        <span class="nav-popover-title" data-en="Notifications">Mga Abiso</span>
                        <button type="button" class="nav-popover-markall" id="popoverMarkAllBtn" data-en="Mark all as read" onclick="markAllAsReadFromPopover(event)">Markahan lahat bilang nabasa</button>
                    </div>
                    <div class="nav-popover-tabs" role="tablist">
                        <button type="button" class="nav-tab-btn active" data-tab="all" onclick="setPopoverTab('all', event)">
                            <span data-en="All">Lahat</span> <span class="nav-tab-count" id="popoverCountAll">0</span>
                        </button>
                        <button type="button" class="nav-tab-btn" data-tab="urgent" onclick="setPopoverTab('urgent', event)">
                            <span data-en="Urgent">Agaran</span> <span class="nav-tab-count" id="popoverCountUrgent">0</span>
                        </button>
                        <button type="button" class="nav-tab-btn" data-tab="warning" onclick="setPopoverTab('warning', event)">
                            <span data-en="Updates">Mga Update</span> <span class="nav-tab-count" id="popoverCountWarning">0</span>
                        </button>
                    </div>
                </div>
                <div class="nav-popover-list" id="navPopoverList">
                    <div class="nav-popover-loading" data-en="Loading notifications...">Kinukuha ang mga abiso...</div>
                </div>
            </div>
        </div>

        <a href="../settings/index.php?tab=profile"
           class="resident-topbar-settings<?= $residentCurrentScript === 'index.php' ? ' is-active' : '' ?>"
           title="Mga Setting ng Account"
           data-en-title="Account Settings"
           aria-label="Mga Setting ng Account"
           data-en-aria-label="Account Settings">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span class="resident-topbar-settings-label" data-en="Account Settings">Mga Setting ng Account</span>
        </a>

        <?php if ($residentTopbarDisplayName !== ''): ?>
            <span class="resident-topbar-name"><span data-en="Signed in as">Naka-sign in bilang</span> <?= $residentTopbarEscape($residentTopbarDisplayName) ?></span>
        <?php endif; ?>

        <form method="post" action="../../../backend/api/auth/logout.php">
            <input type="hidden" name="csrf_token" value="<?= $residentTopbarEscape($residentTopbarCsrf) ?>">
            <button type="submit" class="resident-signout" data-en="Sign out">Mag-sign out</button>
        </form>
    </div>
</header>
<script>
if (!window.agapCsrfToken) {
    window.agapCsrfToken = <?= json_encode($residentTopbarCsrf) ?>;
    const unsafeMethods = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
        const method = String(init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const url = new URL(input instanceof Request ? input.url : input, window.location.href);
        if (unsafeMethods.has(method) && url.origin === window.location.origin) {
            const headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
            headers.set('X-CSRF-Token', window.agapCsrfToken);
            init = { ...init, headers, credentials: init.credentials || 'same-origin' };
        }
        return originalFetch(input, init);
    };
}
</script>
