<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4, 5, 6], true)) {
    http_response_code(403);
    die('Access Denied');
}
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/notifications.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/notifications.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../../layouts/navbar.php'; ?>
        
        <div class="notifications-page-container">
            <!-- Header Title -->
            <div class="notif-page-header">
                <h1 class="notif-page-title">Notifications</h1>
            </div>

            <!-- Underlined Tabs: Unread | Read (Format matching user requirement) -->
            <div class="notif-nav-tabs" role="tablist" aria-label="Notification view tabs">
                <button type="button" class="notif-tab-btn active" id="tabUnread" onclick="setNotifTab('unread')">
                    Unread <span class="notif-tab-count-badge" id="tabCountUnread" style="display:none;">0</span>
                </button>
                <button type="button" class="notif-tab-btn" id="tabRead" onclick="setNotifTab('read')">
                    Read <span class="notif-tab-count-badge" id="tabCountRead" style="display:none;">0</span>
                </button>
            </div>

            <!-- Controls Toolbar: Search, Filters, Sort & Actions -->
            <div class="notif-toolbar">
                <div class="notif-search-box">
                    <svg class="notif-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="notifSearchInput" placeholder="Search title, message, or case #..." autocomplete="off" oninput="handleSearchInput(event)">
                    <button type="button" id="notifClearSearch" class="notif-search-clear" onclick="clearSearch()" title="Clear search">&times;</button>
                </div>

                <div class="notif-toolbar-actions">
                    <!-- Severity / Priority Filter -->
                    <div class="notif-filter-select-box">
                        <select id="notifSeveritySelect" onchange="handleSeverityChange(event)" title="Filter by priority">
                            <option value="all">All Priorities</option>
                            <option value="urgent">Urgent / Critical</option>
                            <option value="warning">Timeline Risks</option>
                            <option value="info">Notices &amp; Updates</option>
                            <option value="success">Resolved / Closed</option>
                        </select>
                    </div>

                    <!-- Date Range Filter -->
                    <div class="notif-filter-select-box">
                        <select id="notifDateSelect" onchange="handleDateChange(event)" title="Filter by date range">
                            <option value="all">All Time</option>
                            <option value="today">Today</option>
                            <option value="week">Past 7 Days</option>
                            <option value="month">Past 30 Days</option>
                        </select>
                    </div>

                    <!-- Sort Dropdown -->
                    <div class="notif-sort-box">
                        <select id="notifSortSelect" onchange="handleSortChange(event)" title="Sort notifications">
                            <option value="date_desc">Newest first</option>
                            <option value="date_asc">Oldest first</option>
                            <option value="urgency">Highest Priority</option>
                        </select>
                    </div>

                    <!-- Clear / Reset Filters -->
                    <button type="button" class="notif-btn-clear-filters" id="btnClearFilters" onclick="resetAllFilters()" style="display:none;" title="Reset active filters">
                        Reset Filters
                    </button>

                    <button type="button" class="notif-btn-mark-all" id="btnMarkAllRead" onclick="markAllAsRead()">
                        Mark all as read
                    </button>
                </div>
            </div>

            <!-- Quick Category Filter Chips Row -->
            <div class="notif-filter-chips-row" role="tablist" aria-label="Category Filters">
                <button type="button" class="notif-chip active" id="chip_all" data-category="all" onclick="setCategoryFilter('all')">
                    All <span class="chip-count" id="chipCount_all">0</span>
                </button>
                <button type="button" class="notif-chip chip-critical" id="chip_critical" data-category="critical" onclick="setCategoryFilter('critical')">
                    Critical <span class="chip-count" id="chipCount_critical">0</span>
                </button>
                <button type="button" class="notif-chip chip-warning" id="chip_warning" data-category="warning" onclick="setCategoryFilter('warning')">
                    Timeline Risks <span class="chip-count" id="chipCount_warning">0</span>
                </button>
                <button type="button" class="notif-chip chip-hearing" id="chip_hearing" data-category="hearing" onclick="setCategoryFilter('hearing')">
                    Hearings <span class="chip-count" id="chipCount_hearing">0</span>
                </button>
                <button type="button" class="notif-chip chip-summons" id="chip_summons" data-category="summons" onclick="setCategoryFilter('summons')">
                    Summons <span class="chip-count" id="chipCount_summons">0</span>
                </button>
                <button type="button" class="notif-chip chip-pangkat" id="chip_pangkat" data-category="pangkat" onclick="setCategoryFilter('pangkat')">
                    Pangkat <span class="chip-count" id="chipCount_pangkat">0</span>
                </button>
                <button type="button" class="notif-chip chip-complaint" id="chip_complaint" data-category="complaint" onclick="setCategoryFilter('complaint')">
                    Intake <span class="chip-count" id="chipCount_complaint">0</span>
                </button>
                <button type="button" class="notif-chip chip-document" id="chip_document" data-category="document" onclick="setCategoryFilter('document')">
                    Documents <span class="chip-count" id="chipCount_document">0</span>
                </button>
                <button type="button" class="notif-chip chip-success" id="chip_success" data-category="success" onclick="setCategoryFilter('success')">
                    Resolved <span class="chip-count" id="chipCount_success">0</span>
                </button>
            </div>

            <!-- Notification Feed List Container -->
            <div id="notificationFeedCard" class="notif-feed-card">
                <!-- Selection & Bulk Actions Header Bar -->
                <div class="notif-selection-bar" id="notifSelectionBar">
                    <div class="selection-bar-left">
                        <label class="notif-checkbox-label" for="selectAllCheckbox">
                            <input type="checkbox" id="selectAllCheckbox" class="notif-checkbox" onchange="toggleSelectAll(event)">
                            <span id="selectAllLabel">Select all</span>
                        </label>
                        <span class="selection-count-badge" id="selectionCountBadge" style="display:none;">0 selected</span>
                    </div>

                    <div class="selection-bar-actions" id="selectionBarActions" style="display:none;">
                        <button type="button" class="btn-bulk-action btn-bulk-delete" onclick="deleteSelectedNotifications()" title="Delete selected notifications">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            Delete (<span class="bulk-count">0</span>)
                        </button>
                        <button type="button" class="btn-bulk-action btn-bulk-read" onclick="markSelectedAsRead()" title="Mark selected as read">
                            Mark read
                        </button>
                        <button type="button" class="btn-bulk-cancel" onclick="clearAllSelections()" title="Cancel selection">
                            Cancel
                        </button>
                    </div>
                </div>

                <div id="notificationList" class="notif-feed-list" aria-live="polite">
                    <div class="notif-loading-row">
                        <div class="notif-spinner"></div>
                        <span>Loading notifications...</span>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../../assets/js/inbox.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/inbox.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
