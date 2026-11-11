<?php
require_once '../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

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

        <div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 8px;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h1 style="margin: 0; font-size: 1.6rem; color: #0f172a;">Lupon Member &amp; Conciliator Dashboard</h1>
                </div>
            </div>
            <!-- Senior-Friendly Accessibility Toolbar Controls -->
            <div class="lupon-accessibility-bar" style="margin-bottom: 0;">
                <div class="lupon-accessibility-info">
                    <span style="color:#64748b; font-size:0.85rem;">Senior Mode:</span>
                    <strong id="dutyDayHeaderTag" style="font-size:0.85rem; color:#0f172a;">Pangkat Duty Team</strong>
                </div>
                <div class="lupon-accessibility-controls">
                    <a href="#jurisdictionQueue" class="btn-accessibility-pill" style="background:#102b2d; color:#ffffff; border-color:#102b2d; text-decoration:none;">Verify Complaints (<span id="quickVerifyCount">0</span>)</a>
                    <button type="button" class="btn-accessibility-pill" onclick="window.open('../../../backend/api/reports/council_brief.php?autoprint=1', '_blank', 'width=1020,height=820,noopener,noreferrer')" title="I-print ang 1-Page Monthly Council Meeting Executive Brief" style="background:#0f172a; color:#ffffff; border-color:#0f172a;">
                        <span>1-Click Council Brief</span>
                    </button>
                    <?php $agapFontSliderCompact = true; include '../../layouts/font-size-slider.php'; ?>
                    <button type="button" class="btn-accessibility-pill" id="btnToggleContrast" title="Mataas na Contrast para sa Madaling Pagbasa">
                        <span>High Contrast</span>
                    </button>
                </div>
            </div>
        </div>

        <section class="dashboard-overview" aria-label="Lupon Member Dashboard Overview">

            <!-- ── Top KPIs Summary Cards (Senior-Friendly, High Contrast) ─────── -->
            <section class="lupon-kpis-grid" aria-label="Lupon Member KPIs">
                <!-- Duty Day Indicator -->
                <article class="lupon-kpi-card kpi-duty" data-lupon-card="duty">
                    <div class="kpi-card-header">
                        <span class="lupon-kpi-label">Araw ng Duty</span>
                        <span class="lupon-kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></span>
                    </div>
                    <div class="lupon-kpi-value" id="dutyDayDisplay">Wednesday</div>
                    <span class="lupon-kpi-sub" id="dutyDayDisplaySub">Nakatalagang araw ng pagseserbisyo</span>
                </article>

                <!-- Total Assigned Cases -->
                <article class="lupon-kpi-card kpi-assigned" data-lupon-card="assigned">
                    <div class="kpi-card-header">
                        <span class="lupon-kpi-label">Hawak na Kaso</span>
                        <span class="lupon-kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg></span>
                    </div>
                    <div class="lupon-kpi-value" id="kpiTotalAssigned">0</div>
                    <span class="lupon-kpi-sub">Kabuuang active pangkat cases</span>
                </article>

                <!-- As Chairperson -->
                <article class="lupon-kpi-card kpi-chair" data-lupon-card="chair">
                    <div class="kpi-card-header">
                        <span class="lupon-kpi-label">Bilang Chairperson</span>
                        <span class="lupon-kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"></circle><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"></path></svg></span>
                    </div>
                    <div class="lupon-kpi-value" id="kpiChairmanCount">0</div>
                    <span class="lupon-kpi-sub">Pinamumunuang panel</span>
                </article>

                <!-- Hearings Today -->
                <article class="lupon-kpi-card kpi-today" data-lupon-card="today">
                    <div class="kpi-card-header">
                        <span class="lupon-kpi-label">Pagdinig Ngayong Araw</span>
                        <span class="lupon-kpi-icon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></span>
                    </div>
                    <div class="lupon-kpi-value" id="kpiTodayHearings">0</div>
                    <span class="lupon-kpi-sub">Nakatakdang sesyon ngayon</span>
                </article>
            </section>

            <!-- ── Main Two-Column View: Duty Hearings & Assigned Cases ────────── -->

            <!-- Resident Submissions Jurisdiction Verification Panel (Lupon passes verified complaints to Clerk) -->
            <section class="jurisdiction-queue-panel" id="jurisdictionQueue" aria-label="Pending Resident Submissions Jurisdiction Review">
                <div class="lupon-panel-header" style="margin-bottom: 6px;">
                    <div class="lupon-panel-title-wrap">
                        <h2 style="margin:0; font-size:1.15rem; color:#102b2d;">Pending Resident Submissions — Jurisdiction Verification</h2>
                    </div>
                    <span class="lupon-panel-badge" id="jurisdictionQueueBadge">0 Pending</span>
                </div>
                <p style="margin: 0; font-size: 0.9rem; color: #475569;">
                    Suriin kung nasa saklaw ng Katarungang Pambarangay (KP) ang isinumiteng reklamo. Pagkatapos ma-verify, ipapasa ito sa Lupon Clerk para i-schedule ang Paanyaya (walang pag-edit o pag-schedule sa panig ng Lupon Member).
                </p>
                <div class="jurisdiction-queue-grid" id="luponJurisdictionQueueContainer">
                    <div style="padding: 18px; text-align: center; color: #64748b;">Kinukuha ang mga isinumiteng reklamo...</div>
                </div>
            </section>

            <div class="lupon-main-layout">

                <!-- Left Column: My Duty Day Hearing Calendar & Schedule -->
                <section class="lupon-panel-card" aria-label="My Duty Day Hearing Calendar">
                    <div class="lupon-panel-header">
                        <div class="lupon-panel-title-wrap">
                            <h2>My Duty Day Hearing Calendar</h2>
                        </div>
                        <span class="lupon-panel-badge" id="dutyHearingsCountBadge">0 Schedules</span>
                    </div>

                    <!-- Duty Banner -->
                    <div class="duty-day-banner">
                        <div class="duty-day-info">
                            <h3>Lupon Chamber / Court Room Conciliation</h3>
                            <p>Awtomatikong nakalaan para sa mga pagdinig ng inyong nakatalagang Pangkat Duty Team.</p>
                        </div>
                        <div class="duty-day-team-pill">
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
                <section class="lupon-panel-card" id="assignedCasesSection" aria-label="My Assigned Pangkat Cases">
                    <div class="lupon-panel-header">
                        <div class="lupon-panel-title-wrap">
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
                        Digital Case Dossier
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
                I-print ang Dossier
            </button>
            <button type="button" class="btn-lupon-touch" id="dossierCloseFooterBtn">
                Isara (Close)
            </button>
        </footer>
    </div>
</div>

<script src="../../assets/js/lupon-dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/lupon-dashboard.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
