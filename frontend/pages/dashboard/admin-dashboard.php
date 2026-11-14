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
                <button type="button" class="pb-btn-customize-layout" id="btnToggleCustomizeLayout" aria-pressed="false" title="Resize, reorder, or hide dashboard widgets">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                    <span>Customize</span>
                </button>

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
                    </div>
                </div>
            </div>
        </div>

        <?php include '../../layouts/dashboard-overview.php'; ?>

    </div>

</div>

<script src="../../assets/js/docket-drag-schedule.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/docket-drag-schedule.js'); ?>"></script>
<script src="../../assets/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/dashboard.js'); ?>"></script>
<script src="../../assets/js/official-reports.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/official-reports.js'); ?>"></script>
<script src="../../assets/js/liso-duty.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/liso-duty.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
