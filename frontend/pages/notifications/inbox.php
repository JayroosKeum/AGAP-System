<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3, 4], true)) { http_response_code(403); die('Access Denied'); }
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/notifications.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/notifications.css'); ?>">
<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    <main class="main-content">
        <?php include '../../layouts/navbar.php'; ?>
        <div class="page-header"><h1>Notifications</h1><p>Case and workflow updates assigned to you.</p></div>
        <section class="notifications-content"><div id="notificationMessage" role="alert"></div><p id="notificationSummary" class="notification-summary"></p><div id="notificationList" class="notification-list" aria-live="polite"></div></section>
    </main>
</div>
<script src="../../assets/js/notifications.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/notifications.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
