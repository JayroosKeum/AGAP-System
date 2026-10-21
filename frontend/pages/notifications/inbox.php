<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) {
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
        
        <div class="page-header notifications-header-row">
            <div>
                <h1>Notifications Inbox</h1>
                <p>Real-time workflow alerts, statutory deadlines, and case updates assigned to you.</p>
            </div>
            <div class="header-actions">
                <button type="button" class="btn-mark-all-read" id="btnMarkAllRead" onclick="markAllAsRead()">
                    <span class="btn-icon">✓</span> Mark All as Read
                </button>
            </div>
        </div>

        <section class="notifications-content">
            <!-- Filter Pills Navigation -->
            <div class="notif-filters-bar" role="tablist" aria-label="Notification Filters">
                <button type="button" class="notif-filter-pill active" data-filter="all" onclick="setNotifFilter('all')">
                    All <span class="filter-count-badge" id="countAll">0</span>
                </button>
                <button type="button" class="notif-filter-pill filter-urgent" data-filter="urgent" onclick="setNotifFilter('urgent')">
                    🔴 Overdue &amp; Urgent <span class="filter-count-badge" id="countUrgent">0</span>
                </button>
                <button type="button" class="notif-filter-pill filter-warning" data-filter="warning" onclick="setNotifFilter('warning')">
                    📅 Deadlines <span class="filter-count-badge" id="countWarning">0</span>
                </button>
                <button type="button" class="notif-filter-pill filter-info" data-filter="info" onclick="setNotifFilter('info')">
                    📄 Documents &amp; Updates <span class="filter-count-badge" id="countInfo">0</span>
                </button>
            </div>

            <div id="notificationMessage" role="alert" style="display: none;"></div>
            
            <div class="inbox-status-bar">
                <span id="notificationSummary" class="notification-summary">Loading notifications...</span>
                <button type="button" class="btn-refresh-inbox" onclick="loadNotifications()" title="Refresh inbox">
                    🔄 Refresh
                </button>
            </div>

            <!-- Modern Activity Card List -->
            <div id="notificationList" class="notification-list" aria-live="polite"></div>
        </section>
    </main>
</div>

<script src="../../assets/js/inbox.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/inbox.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
