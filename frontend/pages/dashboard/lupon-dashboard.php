<?php
session_start();

// Enforce access control: Admin (1), Lupon Clerk (2), and Lupon Member (3)
if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 3], true)) {
    header('Location: ../auth/login.php');
    exit;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/lupon-dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/lupon-dashboard.css'); ?>">

<div class="dashboard-layout lupon-senior-view">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <!-- ── Senior-Friendly Accessibility Toolbar ──────────────────────── -->
        <section class="dashboard-overview" aria-label="Lupon Member Dashboard Overview" style="padding-bottom: 0;">
            <div class="lupon-accessibility-bar">
                <div class="lupon-accessibility-info">
                    <span style="font-size: 1.3rem;">👓</span>
                    <span>Senior-Friendly Mode:</span>
                    <strong id="dutyDayDisplaySub">Pangkat Duty Team</strong>
                </div>
                <div class="lupon-accessibility-controls">
                    <button type="button" class="btn-accessibility-pill" id="btnToggleFontSize" title="Palakihin o Paliitin ang Titik">
                        <span>🔤</span>
                        <span>Malaking Titik (A+)</span>
                    </button>
                    <button type="button" class="btn-accessibility-pill" id="btnToggleContrast" title="Mataas na Contrast para sa Madaling Pagbasa">
                        <span>🌓</span>
                        <span>High Contrast</span>
                    </button>
                </div>
            </div>

            <!-- Page Header -->
            <div class="page-header" style="padding: 0 0 20px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <h1 style="margin: 0; font-size: 1.85rem; color: #0f172a;">⚖️ Lupon Member &amp; Conciliator Dashboard</h1>
                        <span class="lupon-panel-badge" style="background:#dbeafe; color:#1e40af; border-color:#bfdbfe;">
                            Tungkulin sa Pangkat
                        </span>
                    </div>
                    <p style="margin: 6px 0 0; color: #475569; font-size: 1.05rem;">
                        Malugod na pagdating, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'Lupon Member'); ?></strong> · Barangay Tumana Katarungang Pambarangay
                    </p>
                </div>
            </div>

            <!-- ── Top KPIs Summary Cards (Senior-Friendly, High Contrast) ─────── -->
            <section class="lupon-kpis-grid" aria-label="Lupon Member KPIs">
                <!-- Duty Day Indicator -->
                <div class="lupon-kpi-card kpi-duty">
                    <div class="lupon-kpi-icon">📅</div>
                    <div class="lupon-kpi-content">
                        <span class="lupon-kpi-label">Araw ng Duty</span>
                        <div class="lupon-kpi-value" id="dutyDayDisplay">Wednesday</div>
                        <span class="lupon-kpi-sub">Nakatalagang araw ng pagseserbisyo</span>
                    </div>
                </div>

                <!-- Total Assigned Cases -->
                <div class="lupon-kpi-card kpi-assigned">
                    <div class="lupon-kpi-icon">📁</div>
                    <div class="lupon-kpi-content">
                        <span class="lupon-kpi-label">Hawak na Kaso</span>
                        <div class="lupon-kpi-value" id="kpiTotalAssigned">0</div>
                        <span class="lupon-kpi-sub">Kabuuang active pangkat cases</span>
                    </div>
                </div>

                <!-- As Chairperson -->
                <div class="lupon-kpi-card kpi-chair">
                    <div class="lupon-kpi-icon">🎖️</div>
                    <div class="lupon-kpi-content">
                        <span class="lupon-kpi-label">Bilang Chairperson</span>
                        <div class="lupon-kpi-value" id="kpiChairmanCount">0</div>
                        <span class="lupon-kpi-sub">Pinamumunuang panel</span>
                    </div>
                </div>

                <!-- Hearings Today -->
                <div class="lupon-kpi-card kpi-today">
                    <div class="lupon-kpi-icon">⏰</div>
                    <div class="lupon-kpi-content">
                        <span class="lupon-kpi-label">Pagdinig Ngayong Araw</span>
                        <div class="lupon-kpi-value" id="kpiTodayHearings">0</div>
                        <span class="lupon-kpi-sub">Nakatakdang sesyon ngayon</span>
                    </div>
                </div>
            </section>

            <!-- ── Main Two-Column View: Duty Hearings & Assigned Cases ────────── -->
            <div class="lupon-main-layout">

                <!-- Left Column: My Duty Day Hearing Calendar & Schedule -->
                <section class="lupon-panel-card" aria-label="My Duty Day Hearing Calendar">
                    <div class="lupon-panel-header">
                        <div class="lupon-panel-title-wrap">
                            <span style="font-size: 1.5rem;">🗓️</span>
                            <h2>My Duty Day Hearing Calendar</h2>
                        </div>
                        <span class="lupon-panel-badge" id="dutyHearingsCountBadge">0 Schedules</span>
                    </div>

                    <!-- Duty Banner -->
                    <div class="duty-day-banner">
                        <div class="duty-day-info">
                            <h3>🏛️ Lupon Chamber / Court Room Conciliation</h3>
                            <p>Awtomatikong nakalaan para sa mga pagdinig ng inyong nakatalagang Pangkat Duty Team.</p>
                        </div>
                        <div class="duty-day-team-pill">
                            <span>👥</span>
                            <span>Active Duty Panel</span>
                        </div>
                    </div>

                    <!-- Hearings Stream -->
                    <div class="duty-hearings-stream" id="luponDutyHearingsContainer">
                        <div style="padding: 24px; text-align: center; color: #64748b; font-size: 1rem;">
                            Kinukuha ang talaan ng mga pagdinig sa araw ng iyong duty...
                        </div>
                    </div>
                </section>

                <!-- Right Column: My Assigned Pangkat Cases -->
                <section class="lupon-panel-card" aria-label="My Assigned Pangkat Cases">
                    <div class="lupon-panel-header">
                        <div class="lupon-panel-title-wrap">
                            <span style="font-size: 1.5rem;">⚖️</span>
                            <h3>My Assigned Pangkat Cases</h3>
                        </div>
                        <span class="lupon-panel-badge" id="assignedCasesCountBadge">0 Kaso</span>
                    </div>

                    <p style="margin: 0 0 16px; color: #475569; font-size: 0.95rem;">
                        Mga aktibong kaso kung saan ikaw ay nakatalaga bilang <strong>Chairperson</strong>, <strong>Secretary</strong>, o <strong>Member</strong>:
                    </p>

                    <div class="pangkat-cases-grid" id="luponAssignedCasesContainer">
                        <div style="padding: 24px; text-align: center; color: #64748b; font-size: 1rem;">
                            Kinukuha ang talaan ng mga kaso...
                        </div>
                    </div>
                </section>

            </div>

        </section>

    </div>

</div>

<!-- ── Digital Case Dossier Modal (High Contrast & Senior-Friendly) ──────────── -->
<div id="digitalCaseDossierModal" class="dossier-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dossierModalTitle">
    <div class="dossier-modal-container">
        <header class="dossier-modal-header">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 2px;">
                    <h2 class="dossier-modal-title" id="dossierModalTitle">
                        📂 Digital Case Dossier
                    </h2>
                    <span id="dossierModalCaseNumber" style="background:#3b82f6; color:#fff; font-size:0.85rem; font-weight:800; padding:3px 10px; border-radius:6px;">Case #</span>
                </div>
                <p class="dossier-modal-sub" id="dossierModalSub">Mabilisang pagsusuri ng reklamo, panig, telepono, at nakaraang kasunduan</p>
            </div>
            <button type="button" class="dossier-close-btn" id="dossierCloseBtn" aria-label="Isara ang Dossier">&times;</button>
        </header>

        <div class="dossier-modal-body" id="dossierModalBody">
            <div style="padding: 30px; text-align: center; color: #64748b; font-size: 1rem;">Kinukuha ang buong dossier...</div>
        </div>

        <footer class="dossier-modal-footer">
            <button type="button" class="btn-lupon-touch btn-secondary" id="dossierPrintBtn">
                🖨️ I-print ang Dossier
            </button>
            <button type="button" class="btn-lupon-touch" id="dossierCloseFooterBtn">
                Isara (Close)
            </button>
        </footer>
    </div>
</div>

<script src="../../assets/js/lupon-dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/lupon-dashboard.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
