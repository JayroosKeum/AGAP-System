<?php
require_once '../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

// The administrator overview contains management-wide statistics and report
// controls, so it must not be rendered for an expired session or another role.
if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || (int) $_SESSION['role_id'] !== 1) {
    header('Location: ../auth/login.php?error=login_required');
    exit;
}

require_once '../../../backend/models/Setting.php';
$captainName = '';
$barangayName = 'Barangay Tumana';
$cityName = 'Marikina City';
try {
    $captainName = trim((string) Setting::getSetting('captain_name', ''));
    $barangayName = trim((string) Setting::getSetting('barangay_name', 'Barangay Tumana')) ?: 'Barangay Tumana';
    $cityName = trim((string) Setting::getSetting('city_name', 'Marikina City')) ?: 'Marikina City';
} catch (Throwable) {
    // Fallback to session username below
}
if ($captainName === '') {
    $captainName = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Punong Barangay'));
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/reports.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/reports.css'); ?>">
<link rel="stylesheet" href="../../assets/css/official-reports.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/official-reports.css'); ?>">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content pb-captain-main">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header pb-captain-header">
            <div class="pb-header-identity">
                <div class="pb-role-pill">
                    <span class="pb-role-dot" aria-hidden="true"></span>
                    <span>Punong Barangay · Lupon Chairman (R.A. 7160 §399)</span>
                </div>
                <h1>Punong Barangay Dashboard</h1>
                <p>
                    <strong id="pbCaptainDisplayName"><?php echo htmlspecialchars($captainName, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span aria-hidden="true">·</span>
                    <span><?php echo htmlspecialchars($barangayName . ', ' . $cityName, ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
            </div>
            <div class="pb-header-toolbar">
                <div class="pb-period-pill-group" aria-label="Timeframe and date range">
                    <div class="period-indicator pb-date-range-pill" id="periodIndicator">
                        <span class="indicator-icon" aria-hidden="true">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        </span>
                        <div class="indicator-texts">
                            <strong id="periodLabelDisplay">All Time</strong>
                            <small id="periodDateRangeDisplay">All recorded data in the system</small>
                        </div>
                    </div>
                    <div class="period-nav-buttons" id="periodNavButtons" style="display:none;">
                        <button type="button" id="prevPeriodBtn" class="btn-period-nav" title="Previous period" aria-label="Previous period">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        </button>
                        <button type="button" id="currentPeriodBtn" class="btn-period-nav btn-period-current" title="Reset to current period">
                            <span id="currentPeriodText">Current</span>
                        </button>
                        <button type="button" id="nextPeriodBtn" class="btn-period-nav" title="Next period" aria-label="Next period">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </button>
                    </div>
                </div>

                <button type="button" class="pb-btn-add-widget" id="btnOpenWidgetDrawer" aria-haspopup="dialog" aria-controls="addWidgetDrawer">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><path d="M17.5 14v7M14 17.5h7"></path></svg>
                    <span>Add widget</span>
                </button>

                <div class="dash-dropdown" id="dropdownGenerateReport">
                    <button type="button" class="pb-btn-export" id="btnGenerateReportToggle" aria-haspopup="true" aria-expanded="false" title="Generate and export official KP reports">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <span>Export</span>
                        <svg class="dropdown-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="dash-dropdown-menu" id="menuGenerateReport" role="menu">
                        <p class="dash-dropdown-label">Executive Brief</p>
                        <button type="button" class="dash-dropdown-item official-report-launch" data-report-type="council-brief" role="menuitem">1-Page Barangay Council Brief</button>
                        <div class="dash-dropdown-separator" role="separator"></div>
                        <p class="dash-dropdown-label">Official KP Reports</p>
                        <button type="button" class="dash-dropdown-item official-report-launch" data-report-type="certificate" role="menuitem">Certificate to File Action Cases</button>
                        <button type="button" class="dash-dropdown-item official-report-launch" data-report-type="monitoring" role="menuitem">KP Monitoring Report</button>
                        <button type="button" class="dash-dropdown-item official-report-launch" data-report-type="form8" role="menuitem">LTIA Form 8 – Case &amp; Settlement Log</button>
                        <button type="button" class="dash-dropdown-item official-report-launch" data-report-type="form6" role="menuitem">LTIA Form 6 – Summary of Cases</button>
                        <div class="dash-dropdown-separator" role="separator"></div>
                        <a href="../reports/report-list.php" class="dash-dropdown-item" role="menuitem" style="text-decoration:none; color:inherit;">Open LTIA &amp; Transmittals Hub &rarr;</a>
                    </div>
                </div>
            </div>
        </div>

        <?php include '../../layouts/dashboard-overview.php'; ?>

    </div>

</div>

<script src="../../assets/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/dashboard.js'); ?>"></script>
<script src="../../assets/js/official-reports.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/official-reports.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
