<?php
require_once '../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

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

        <div class="page-header page-header-with-actions">
            <div>
                <h1>Administrator Dashboard</h1>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['username'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="top-quick-actions" aria-label="Quick access actions">
                <a href="../complaints/complaint-create.php" class="btn-top-primary">+ File Complaint</a>
                <a href="../users/resident-verification.php" class="btn-top-secondary">Verify Residents</a>
                <a href="../paanyaya/index.php" class="btn-top-secondary">Paanyaya Stage 0</a>
                <a href="../reports/report-list.php" class="btn-top-secondary">LTIA &amp; Transmittals</a>
            </div>
        </div>

        <?php include '../../layouts/dashboard-overview.php'; ?>

    </div>

</div>

<script src="../../assets/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/dashboard.js'); ?>"></script>
<script src="../../assets/js/official-reports.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/official-reports.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
