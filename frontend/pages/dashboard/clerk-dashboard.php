<?php
session_start();

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <h1>Lupon Clerk Dashboard</h1>
            <p>Welcome, <?php echo $_SESSION['username']; ?></p>
        </div>

        <?php include '../../layouts/dashboard-overview.php'; ?>

    </div>

</div>

<script src="../../assets/js/dashboard.js"></script>

<?php include '../../layouts/footer.php'; ?>
