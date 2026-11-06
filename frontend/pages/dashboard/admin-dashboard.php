<?php
session_start();

// The administrator overview contains management-wide statistics and report
// controls, so it must not be rendered for an expired session or another role.
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || (int) $_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/reports.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/reports.css'); ?>">
<link rel="stylesheet" href="../../assets/css/official-reports.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/official-reports.css'); ?>">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <h1>Administrator Dashboard</h1>
            <p>Welcome, <?php echo $_SESSION['username']; ?></p>
        </div>

        <?php include '../../layouts/dashboard-overview.php'; ?>

    </div>

</div>

<script src="../../assets/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/dashboard.js'); ?>"></script>
<script src="../../assets/js/official-reports.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/official-reports.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
