<?php
session_start();
if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}
if (!in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
    http_response_code(403);
    exit('Access denied for this role.');
}
$inboxRoleId = (int) $_SESSION['role_id'];
$backDashboardUrl = [
    1 => '../dashboard/admin-dashboard.php',
    2 => '../dashboard/clerk-dashboard.php',
    3 => '../dashboard/lupon-dashboard.php',
    4 => '../dashboard/server-dashboard.php',
][$inboxRoleId] ?? '../dashboard/admin-dashboard.php';
$settingsUrl = in_array($inboxRoleId, [1, 2], true)
    ? '../settings/index.php'
    : '../settings/index.php?tab=profile';

include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/notifications.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/notifications.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="notifications-page-container">
            <div class="notif-shell-card">
                <div class="notif-shell-top">
                    <a href="<?php echo htmlspecialchars($backDashboardUrl, ENT_QUOTES, 'UTF-8'); ?>" class="notif-back-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="19" y1="12" x2="5" y2="12"></line>
                            <polyline points="12 19 5 12 12 5"></polyline>
                        </svg>
                        <span>Back to Dashboard</span>
                    </a>
                </div>

                <!-- Header Row -->
                <div class="notif-page-header">
                    <div class="notif-header-copy">
                        <h1 class="notif-page-title">Notifications</h1>
                        <p class="notif-page-subtitle">Stay updated with your latest case activities, hearings, and barangay notices.</p>
                    </div>
                </div>

                <!-- Controls Toolbar -->
                <div class="notif-toolbar">
                    <div class="notif-search-box">
                        <svg class="notif-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" id="notifSearchInput" placeholder="Search notifications..." autocomplete="off" oninput="handleSearchInput(event)">
                        <button type="button" id="notifClearSearch" class="notif-search-clear" onclick="clearSearch()" title="Clear search" aria-label="Clear search">&times;</button>
                    </div>

                    <div class="notif-toolbar-actions">
                        <!-- Filter Popover (Status + Priority + Date + Sort) -->
                        <div class="notif-popover-wrap">
                            <button type="button" class="notif-tool-btn" id="btnNotifFilterMenu" aria-haspopup="true" aria-expanded="false" onclick="toggleToolbarPopover(event, 'notifFilterPopover')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                                </svg>
                                <span>Filter</span>
                                <span class="notif-tool-dot" id="notifFilterActiveDot" hidden></span>
                                <svg class="notif-tool-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>
                            <div class="notif-popover-menu" id="notifFilterPopover" hidden>
                                <div class="notif-popover-section">
                                    <div class="notif-popover-label">Status</div>
                                    <div class="notif-status-pills" role="group" aria-label="Filter by read status">
                                        <button type="button" class="notif-status-pill-btn is-active" data-status-filter="all" onclick="setStatusFilter('all')">All <span id="tabCountAll">0</span></button>
                                        <button type="button" class="notif-status-pill-btn" data-status-filter="unread" onclick="setStatusFilter('unread')">Unread <span id="tabCountUnread">0</span></button>
                                        <button type="button" class="notif-status-pill-btn" data-status-filter="read" onclick="setStatusFilter('read')">Read <span id="tabCountRead">0</span></button>
                                    </div>
                                </div>
                                <div class="notif-popover-section">
                                    <label class="notif-popover-label" for="notifSeveritySelect">Priority</label>
                                    <select id="notifSeveritySelect" onchange="handleSeverityChange(event)">
                                        <option value="all">All Priorities</option>
                                        <option value="urgent">Urgent / Critical</option>
                                        <option value="warning">Timeline Risks</option>
                                        <option value="info">Notices &amp; Updates</option>
                                        <option value="success">Resolved / Closed</option>
                                    </select>
                                </div>
                                <div class="notif-popover-section">
                                    <label class="notif-popover-label" for="notifDateSelect">Date Range</label>
                                    <select id="notifDateSelect" onchange="handleDateChange(event)">
                                        <option value="all">All Time</option>
                                        <option value="today">Today</option>
                                        <option value="week">Past 7 Days</option>
                                        <option value="month">Past 30 Days</option>
                                    </select>
                                </div>
                                <div class="notif-popover-section">
                                    <label class="notif-popover-label" for="notifSortSelect">Sort By</label>
                                    <select id="notifSortSelect" onchange="handleSortChange(event)">
                                        <option value="date_desc">Newest first</option>
                                        <option value="date_asc">Oldest first</option>
                                        <option value="urgency">Highest Priority</option>
                                    </select>
                                </div>
                                <div class="notif-popover-footer">
                                    <button type="button" class="notif-popover-reset" id="btnClearFilters" onclick="resetAllFilters()">Reset filters</button>
                                    <button type="button" class="notif-popover-done" onclick="closeToolbarPopovers()">Done</button>
                                </div>
                            </div>
                        </div>

                        <!-- Type Popover -->
                        <div class="notif-popover-wrap">
                            <button type="button" class="notif-tool-btn" id="btnNotifTypeMenu" aria-haspopup="true" aria-expanded="false" onclick="toggleToolbarPopover(event, 'notifTypePopover')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                                    <line x1="7" y1="7" x2="7.01" y2="7"></line>
                                </svg>
                                <span id="notifTypeBtnLabel">Type</span>
                                <svg class="notif-tool-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </button>
                            <div class="notif-popover-menu notif-type-menu" id="notifTypePopover" hidden>
                                <button type="button" class="notif-type-opt is-active" data-category="all" onclick="setCategoryFilter('all')">
                                    <span>All Types</span><span class="chip-count" id="chipCount_all">0</span>
                                </button>
                                <button type="button" class="notif-type-opt" data-category="critical" onclick="setCategoryFilter('critical')">
                                    <span>Critical Blocker</span><span class="chip-count" id="chipCount_critical">0</span>
                                </button>
                                <button type="button" class="notif-type-opt" data-category="warning" onclick="setCategoryFilter('warning')">
                                    <span>Timeline Risk</span><span class="chip-count" id="chipCount_warning">0</span>
                                </button>
                                <button type="button" class="notif-type-opt" data-category="hearing" onclick="setCategoryFilter('hearing')">
                                    <span>Hearing Schedule</span><span class="chip-count" id="chipCount_hearing">0</span>
                                </button>
                                <button type="button" class="notif-type-opt" data-category="summons" onclick="setCategoryFilter('summons')">
                                    <span>Summons Service</span><span class="chip-count" id="chipCount_summons">0</span>
                                </button>
                                <button type="button" class="notif-type-opt" data-category="pangkat" onclick="setCategoryFilter('pangkat')">
                                    <span>Pangkat Formation</span><span class="chip-count" id="chipCount_pangkat">0</span>
                                </button>
                                <button type="button" class="notif-type-opt" data-category="complaint" onclick="setCategoryFilter('complaint')">
                                    <span>Complaint Intake</span><span class="chip-count" id="chipCount_complaint">0</span>
                                </button>
                                <button type="button" class="notif-type-opt" data-category="document" onclick="setCategoryFilter('document')">
                                    <span>Legal Document</span><span class="chip-count" id="chipCount_document">0</span>
                                </button>
                                <button type="button" class="notif-type-opt" data-category="success" onclick="setCategoryFilter('success')">
                                    <span>Case Resolution</span><span class="chip-count" id="chipCount_success">0</span>
                                </button>
                            </div>
                        </div>

                        <!-- Analytics / Overview Toggle -->
                        <button type="button" class="notif-tool-btn" id="btnNotifAnalytics" aria-expanded="false" onclick="toggleAnalyticsStrip()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <line x1="18" y1="20" x2="18" y2="10"></line>
                                <line x1="12" y1="20" x2="12" y2="4"></line>
                                <line x1="6" y1="20" x2="6" y2="14"></line>
                            </svg>
                            <span>Analytics</span>
                        </button>
                    </div>
                </div>

                <!-- Optional Summary Strip toggled by Analytics button -->
                <div class="notif-analytics-strip" id="notifSummaryStrip" hidden>
                    <div class="notif-stat-pill">
                        <span class="notif-stat-label">Total</span>
                        <strong id="notifStatTotal">0</strong>
                    </div>
                    <div class="notif-stat-pill is-unread">
                        <span class="notif-stat-label">Unread</span>
                        <strong id="notifStatUnread">0</strong>
                    </div>
                    <div class="notif-stat-pill is-urgent">
                        <span class="notif-stat-label">Urgent / Risks</span>
                        <strong id="notifStatUrgent">0</strong>
                    </div>
                    <div class="notif-stat-pill">
                        <span class="notif-stat-label">Read</span>
                        <strong id="notifStatRead">0</strong>
                    </div>
                </div>

                <!-- Contextual Bulk Actions Bar (shown only when >=1 row selected) -->
                <div class="notif-selection-bar" id="notifSelectionBar" style="display:none;">
                    <div class="selection-bar-left">
                        <span class="selection-count-badge" id="selectionCountBadge">0 selected</span>
                    </div>
                    <div class="selection-bar-actions" id="selectionBarActions">
                        <button type="button" class="btn-bulk-action btn-bulk-read" onclick="markSelectedAsRead()" title="Mark selected as read">
                            Mark read
                        </button>
                        <button type="button" class="btn-bulk-action btn-bulk-delete" onclick="deleteSelectedNotifications()" title="Delete selected notifications">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            Delete (<span class="bulk-count">0</span>)
                        </button>
                        <button type="button" class="btn-bulk-cancel" onclick="clearAllSelections()" title="Cancel selection">
                            Cancel
                        </button>
                    </div>
                </div>

                <!-- Notification Feed Table Card -->
                <div id="notificationFeedCard" class="notif-feed-card">
                    <div class="notif-table-head">
                        <div class="notif-th-check">
                            <input type="checkbox" id="selectAllCheckbox" class="notif-checkbox" onchange="toggleSelectAll(event)" aria-label="Select all notifications">
                        </div>
                        <div class="notif-th-main">Notification</div>
                        <div class="notif-th-type">Type</div>
                        <div class="notif-th-time">Time</div>
                        <div class="notif-th-status">Status</div>
                    </div>

                    <div id="notificationList" class="notif-feed-list" aria-live="polite">
                        <div class="notif-loading-row">
                            <div class="notif-spinner"></div>
                            <span>Loading notifications...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../../assets/js/inbox.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/inbox.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
