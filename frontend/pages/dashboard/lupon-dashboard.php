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
            <h1>Lupon Member Dashboard</h1>
            <p>Welcome, <?php echo $_SESSION['username']; ?></p>
        </div>

        <div class="stats-grid">

            <div class="stat-card">
                <h3>Total Cases</h3>
                <h2 id="totalCases">0</h2>
            </div>

            <div class="stat-card">
                <h3>Settled Cases</h3>
                <h2 id="settledCases">0</h2>
            </div>

            <div class="stat-card">
                <h3>CFA Issued</h3>
                <h2 id="cfaCases">0</h2>
            </div>

            <div class="stat-card">
                <h3>Archived Cases</h3>
                <h2 id="archivedCases">0</h2>
            </div>

        </div>

    </div>

</div>

<script src="../../assets/js/dashboard.js"></script>

<?php include '../../layouts/footer.php'; ?>