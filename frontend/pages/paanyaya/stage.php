<?php

declare(strict_types=1);

session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3, 4], true)) {
    header('Location: ../auth/login.php');
    exit;
}

$complaintId = filter_var($_GET['complaint_id'] ?? ($_GET['id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($complaintId <= 0) {
    header('Location: index.php');
    exit;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div style="padding: 24px 30px 0;">
            <a href="index.php" class="back-link" style="display: inline-flex; margin-bottom: 14px;">
                &larr; Back to Paanyaya
            </a>
        </div>

        <?php include __DIR__ . '/../complaints/tabs/paanyaya-tab.php'; ?>
    </div>
</div>

<?php include '../../layouts/footer.php'; ?>
