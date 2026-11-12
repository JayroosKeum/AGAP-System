<?php
require_once '../../../backend/services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

// Enforce access control: Admin (1), Lupon Clerk (2), and Secretary (5)
if (!isset($_SESSION['user_id']) || !in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 5], true)) {
    header('Location: ../auth/login.php');
    exit;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/reports.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/reports.css'); ?>">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">

        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 8px;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h1 style="margin: 0; font-size: 1.6rem; color: #0f172a;">Lupon Clerk Dashboard</h1>
                    <span style="background: #eef6f2; color: #1b6553; border: 1px solid #cde2d6; font-size: 0.75rem; font-weight: 700; padding: 3px 10px; border-radius: 9999px;">
                        Front-Desk &amp; Docket Intake
                    </span>
                </div>
            </div>
            <div class="top-quick-actions" aria-label="Clerk quick access actions">
                <a href="../complaints/complaint-create.php" class="btn-top-primary" id="btnClerkNewIntake">+ Walk-in Intake</a>
                <a href="../users/user-list.php?tab=residents" class="btn-top-secondary">Verify Residents</a>
                <a href="../paanyaya/index.php" class="btn-top-secondary" id="btnClerkIssuePaanyaya">Paanyaya Queue</a>
                <a href="../reports/report-list.php" class="btn-top-secondary" id="btnClerkPrintRepo">LTIA &amp; Transmittals</a>
            </div>
        </div>

        <section class="dashboard-overview" aria-label="Lupon Clerk Front-Desk Overview">

        <!-- ── Period Filter Tabs Bar ────────────────────────────────────────── -->
        <div class="dashboard-filter-bar" style="margin-bottom: 18px;">
            <div class="period-tabs-wrapper">
                <nav class="period-tabs" role="tablist" aria-label="Dashboard timeframe">
                    <button type="button" role="tab" class="period-tab active" data-period="all" aria-selected="true" id="tab-all">All Time</button>
                    <button type="button" role="tab" class="period-tab" data-period="weekly" aria-selected="false" id="tab-weekly">Weekly</button>
                    <button type="button" role="tab" class="period-tab" data-period="monthly" aria-selected="false" id="tab-monthly">Monthly</button>
                    <button type="button" role="tab" class="period-tab" data-period="quarterly" aria-selected="false" id="tab-quarterly">Quarterly</button>
                    <button type="button" role="tab" class="period-tab" data-period="annual" aria-selected="false" id="tab-annual">Annual</button>
                </nav>
            </div>

            <div class="period-controls">
                <div class="period-indicator" id="periodIndicator">
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
                        <span>Prev</span>
                    </button>
                    <button type="button" id="currentPeriodBtn" class="btn-period-nav btn-period-current" title="Reset to current period">
                        <span id="currentPeriodText">Current</span>
                    </button>
                    <button type="button" id="nextPeriodBtn" class="btn-period-nav" title="Next period" aria-label="Next period">
                        <span>Next</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </button>
                </div>
            </div>

            <!-- 1-Click Monthly Council Meeting Brief Action -->
            <div class="dashboard-report-actions">
                <button type="button" class="btn-report-dropdown" id="btnClerkCouncilBrief" style="background: #0f172a; color: #ffffff; border-color: #0f172a;" title="Generate 1-Page Printable Executive Summary for the Monthly Barangay Council Assembly">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    <span>1-Click Council Brief</span>
                </button>
            </div>
        </div>    

        <!-- ── 2. Daily Intake & Docket Queue ───────────────────────────────── -->
        <section class="clerk-docket-queue-grid" aria-label="Daily Intake & Docket Queue">
            <!-- Walk-ins / Today's Intakes -->
            <article class="docket-queue-card card-walkin" data-clerk-card="walkin">
                <div class="card-top">
                    <span class="card-badge">Daily Intake</span>
                    
                </div>
                <div class="card-count" id="clerkWalkinsToday">—</div>
                <h3 class="card-title">Walk-ins / Today's Intakes</h3>
                <p class="card-desc">Mga bagong reklamong naitala at sumailalim sa screening ngayong araw</p>
            </article>

            <!-- Active Paanyaya Invitations -->
            <article class="docket-queue-card card-paanyaya" data-clerk-card="paanyaya">
                <div class="card-top">
                    <span class="card-badge">Stage 0 Invitations</span>
                    
                </div>
                <div class="card-count" id="clerkActivePaanyaya">—</div>
                <h3 class="card-title">Active Paanyaya Invitations</h3>
                <p class="card-desc">1st at 2nd invitations na nakabinbin para sa impormal na pag-uusap</p>
            </article>

            <!-- Cases Pending Form Issuance -->
            <article class="docket-queue-card card-forms" data-clerk-card="forms">
                <div class="card-top">
                    <span class="card-badge">Forms &amp; Summons</span>
                    
                </div>
                <div class="card-count" id="clerkPendingForms">—</div>
                <h3 class="card-title">Transmittals &amp; LTIA Queue</h3>
                <p class="card-desc">Bawat form (KP 7–25) ay awtomatikong ginagawa sa loob mismo ng kaso; buksan ang LTIA &amp; Transmittals.</p>
            </article>

            <!-- 4th Top Card: Active Staff -->
            <article class="docket-queue-card card-staff">
                <div class="card-top">
                    <span class="card-badge" style="background:#f3f7f5; color:#1b6553; border:1px solid #d5e4dc;">Barangay Roster</span>
                </div>
                <div class="card-count" id="sysMetricActiveUsers">-</div>
                <h3 class="card-title">Active Staff &amp; Lupon</h3>
                <p class="card-desc">Bilang ng aktibong staff at Lupon members sa sistema</p>
            </article>
        </section>

        <!-- ── 4. Main Two-Column Layout ────────────────────────────────────── -->

        <!-- Clerk Bento Analytics Summary -->
        <section class="bento-visual-grid" aria-label="Clerk Case Stage and Settlement Breakdown">
            <div class="bento-card">
                <div class="bento-card-head">
                    <div>
                        <h3>Case Stage Pipeline</h3>
                        <span>End-to-end complaint-bound docket stages</span>
                    </div>
                    <div class="bento-big-number" id="sysMetricTotalCases">-</div>
                    <span id="bentoClerkTotal" hidden aria-hidden="true">-</span>
                </div>
                <div class="stage-progress-track" aria-hidden="true">
                    <div class="stage-seg-med" id="bentoClerkSegMed" style="width: 30%;"></div>
                    <div class="stage-seg-con" id="bentoClerkSegCon" style="width: 25%;"></div>
                    <div class="stage-seg-set" id="bentoClerkSegSet" style="width: 35%;"></div>
                    <div class="stage-seg-cfa" id="bentoClerkSegCfa" style="width: 10%;"></div>
                </div>
                <div class="bento-stage-pills">
                    <div class="bento-mini-pill">
                        <span><i class="bento-dot" style="background:#102b2d;"></i>Mediation</span>
                        <strong id="sysMetricMediation">0</strong>
                        <span id="bentoClerkMed" hidden aria-hidden="true">0</span>
                    </div>
                    <div class="bento-mini-pill">
                        <span><i class="bento-dot" style="background:#1b6553;"></i>Conciliation</span>
                        <strong id="sysMetricConciliation">0</strong>
                        <span id="bentoClerkCon" hidden aria-hidden="true">0</span>
                    </div>
                    <div class="bento-mini-pill">
                        <span><i class="bento-dot" style="background:#22c55e;"></i>Settled</span>
                        <strong id="sysMetricSettled">0</strong>
                        <span id="bentoClerkSet" hidden aria-hidden="true">0</span>
                    </div>
                    <div class="bento-mini-pill">
                        <span><i class="bento-dot" style="background:#f59e0b;"></i>Archived / Other</span>
                        <strong id="bentoClerkOther">0</strong>
                        <span id="sysMetricArchived" hidden aria-hidden="true">0</span>
                    </div>
                </div>
            </div>

            <div class="bento-card">
                <div class="bento-card-head">
                    <div>
                        <h3>Settlement Completion Rate</h3>
                        <span id="bentoClerkGaugeSub">Ready for 10-day MTC/DILG transmittal cycle</span>
                    </div>
                    <div class="gauge-wrap">
                        <svg width="88" height="48" viewBox="0 0 100 54" aria-hidden="true">
                            <path d="M 10 50 A 40 40 0 0 1 90 50" fill="none" stroke="#e6ecea" stroke-width="10" stroke-linecap="round"></path>
                            <path id="bentoClerkGaugeArc" d="M 10 50 A 40 40 0 0 1 90 50" fill="none" stroke="#1b6553" stroke-width="10" stroke-linecap="round" stroke-dasharray="126" stroke-dashoffset="42"></path>
                        </svg>
                        <div class="gauge-pct" id="bentoClerkGaugePct">0%</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="dash-portal-grid">
            <!-- LEFT COLUMN: Agenda Stream & Macro Dispute Analytics -->
            <div class="dash-portal-main">

                <!-- 4A. Today's Hearings & Paanyaya Agenda Stream -->
                <div class="dash-section-card" id="clerkAgendaPanel" style="margin-bottom: 22px;">
                    <div class="aside-card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h3 class="aside-card-title">Today's Hearings &amp; Paanyaya Agenda Stream</h3>
                            <p class="aside-card-sub">1-click attendance marking at outcome logging para sa mabilisang sesyon</p>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span class="dnd-queue-hint" title="I-drag ang card o handle para i-reorder ang sequence">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="5 9 2 12 5 15"></polyline>
                                    <polyline points="9 5 12 2 15 5"></polyline>
                                    <polyline points="19 9 22 12 19 15"></polyline>
                                    <polyline points="9 19 12 22 15 19"></polyline>
                                    <line x1="2" y1="12" x2="22" y2="12"></line>
                                    <line x1="12" y1="2" x2="12" y2="22"></line>
                                </svg>
                                Drag &amp; Drop Reorder Active
                            </span>
                            <button type="button" class="btn-pb-unavailable-batch" id="btnPbUnavailableBatchClerk" onclick="triggerPbUnavailableBatch()" title="Punong Barangay Unavailable: Batch reschedule all hearings for today to next Tuesday">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="8" x2="12" y2="12"></line>
                                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                </svg>
                                Punong Barangay Unavailable
                            </button>
                            <div class="agenda-filter-tabs">
                                <button type="button" class="btn-agenda-filter active" data-filter="all">
                                    Lahat (<span id="badgeFilterAll">0</span>)
                                </button>
                                <button type="button" class="btn-agenda-filter" data-filter="hearings">
                                    Hearings (<span id="badgeFilterHearings">0</span>)
                                </button>
                                <button type="button" class="btn-agenda-filter" data-filter="paanyaya">
                                    Paanyaya (<span id="badgeFilterPaanyaya">0</span>)
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="clerkAgendaStream" style="padding: 16px;" aria-live="polite">
                        <div style="text-align: center; color: #64748b; padding: 24px;">Ikinakarga ang pang-araw-araw na agenda...</div>
                    </div>
                </div>

                <!-- 4B. Macro Dispute Analytics -->
                <section aria-label="Macro Dispute Analytics">
                    <div class="dash-analytics-row">
                        <!-- Most Complained Issues -->
                        <div class="dash-analytics-card">
                            <div class="card-head">
                                <div>
                                    <h3 class="card-title">Most Complained Issues</h3>
                                    <p class="card-sub">Trending grievance categories sa komunidad</p>
                                </div>
                            </div>
                            <div id="analyticsTopCategories" class="analytics-bars-container">
                                <div class="analytics-loading">Loading dispute statistics...</div>
                            </div>
                        </div>

                        <!-- Purok Dispute Hotspots -->
                        <div class="dash-analytics-card">
                            <div class="card-head">
                                <div>
                                    <h3 class="card-title">Purok Dispute Hotspots</h3>
                                    <p class="card-sub">Area breakdown para sa peace &amp; order deployment</p>
                                </div>
                            </div>
                            <div id="analyticsPurokHotspots" class="analytics-bars-container">
                                <div class="analytics-loading">Loading area hotspots...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Participant Demographics -->
                    <div class="dash-analytics-row" style="margin-top: 18px;">
                        <!-- Gender Distribution -->
                        <div class="dash-analytics-card">
                            <div class="card-head">
                                <div>
                                    <h3 class="card-title">Gender Distribution</h3>
                                    <p class="card-sub">Distribusyon ng Complainants at Respondents ayon sa kasarian</p>
                                </div>
                            </div>
                            <div id="analyticsGenderBreakdown" class="analytics-demographics-wrap">
                                <div class="analytics-loading">Loading gender data...</div>
                            </div>
                        </div>

                        <!-- Age Group Demographics -->
                        <div class="dash-analytics-card">
                            <div class="card-head">
                                <div>
                                    <h3 class="card-title">Age Group Demographics</h3>
                                    <p class="card-sub">Mga lumahok sa pag-aayos ayon sa age bracket</p>
                                </div>
                            </div>
                            <div id="analyticsAgeDistribution" class="analytics-demographics-wrap">
                                <div class="analytics-loading">Loading age demographics...</div>
                            </div>
                        </div>
                    </div>
                </section>

            </div>

            <!-- RIGHT COLUMN: Mini-Calendar, Security & Audit Feed, Purok & Settings -->
            <aside class="dash-portal-aside">

                <!-- 1. Mini-Calendar -->
                <div class="mini-calendar-card" id="miniCalendarCard" style="margin-bottom: 20px;">
                    <div class="mini-cal-header">
                        <span class="mini-cal-title-wrap">
                            
                            <strong class="mini-cal-title">Hearing Calendar</strong>
                        </span>
                        <div class="mini-cal-nav">
                            <button type="button" id="dashCalPrevBtn" class="mini-cal-btn" title="Previous Month" aria-label="Previous Month">&lt;</button>
                            <span id="dashCalMonthDisplay" class="mini-cal-month-text">Nov 2026</span>
                            <button type="button" id="dashCalNextBtn" class="mini-cal-btn" title="Next Month" aria-label="Next Month">&gt;</button>
                        </div>
                    </div>

                    <div class="mini-cal-body">
                        <div class="mini-cal-weekdays">
                            <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                        </div>
                        <div class="mini-cal-days-grid" id="dashboardCalendarGrid" aria-label="Hearing Calendar"></div>
                    </div>

                    <div class="mini-cal-footer">
                        <a href="../hearings/calendar.php" class="mini-cal-link">Full calendar &rarr;</a>
                        <button type="button" class="mini-cal-toggle-btn" id="dashCalTodayBtn">Today</button>
                    </div>
                </div>

                <!-- 2. Security & Audit Feed -->
                <!-- 2. Barangay Tumana News & Advisories -->
                <div class="dash-aside-card" style="margin-bottom: 20px;">
                    <div class="aside-card-header" style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                        <div>
                            <h3 class="aside-card-title">Announcements &amp; News</h3>
                            <p class="aside-card-sub">Barangay Tumana bulletin</p>
                        </div>
                        <button type="button" id="btnOpenStaffNewsManager" class="staff-news-manage-btn">Manage News</button>
                    </div>
                    <div class="dash-aside-body" style="padding: 12px 14px;">
                        <div id="staffBulletinNewsList" class="dash-aside-news-feed"></div>
                    </div>
                </div>

                <!-- 3. Security & Audit Feed -->
                <div class="dash-aside-card" style="margin-bottom: 20px;">
                    <div class="aside-card-header">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            
                            <div>
                                <h3 class="aside-card-title">Security &amp; Audit Feed</h3>
                                <p class="aside-card-sub">User sign-ins, conflict overrides, at archival</p>
                            </div>
                        </div>
                    </div>
                    <div style="padding: 12px 14px;">
                        <div class="security-audit-list" id="clerkSecurityAuditFeed">
                            <div style="text-align: center; color: #64748b; padding: 16px; font-size: 0.82rem;">Loading audit feed...</div>
                        </div>
                    </div>
                </div>

                <!-- 3. Purok & Settings Management Quick-Links -->
                <div class="dash-aside-card">
                    <div class="aside-card-header">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            
                            <div>
                                <h3 class="aside-card-title">Purok &amp; Settings Management</h3>
                                <p class="aside-card-sub">Front-desk controls at schedule constraints</p>
                            </div>
                        </div>
                    </div>
                    <div style="padding: 14px 16px;">
                        <div class="settings-quick-cards-wrap">
                            <!-- Purok Management -->
                            <a href="../settings/index.php?tab=barangay" class="setting-quick-card">
                                <div class="setting-card-left">
                                    <div class="setting-card-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg></div>
                                    <div>
                                        <h4 class="setting-card-title">Purok Management</h4>
                                        <p class="setting-card-desc">Active / inactive puroks at boundaries</p>
                                    </div>
                                </div>
                                <span class="setting-card-arrow">&rarr;</span>
                            </a>

                            <!-- Schedule Constraints -->
                            <a href="../settings/index.php?tab=logistics" class="setting-quick-card">
                                <div class="setting-card-left">
                                    <div class="setting-card-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></div>
                                    <div>
                                        <h4 class="setting-card-title">Schedule Constraints</h4>
                                        <p class="setting-card-desc">Hearing hours, venue limits, at holidays</p>
                                    </div>
                                </div>
                                <span class="setting-card-arrow">&rarr;</span>
                            </a>
                        </div>
                    </div>
                </div>

            </aside>
        </div>

        </section>

    </div>

</div>

<!-- ── Quick Attendance / Check-In Modal ────────────────────────────────────── -->
<div id="quickDashboardAttendanceModal" class="report-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="quickAttendanceModalTitle">
    <div class="report-modal-container" style="max-width: 640px;">
        <header class="report-modal-header" style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
            <div class="modal-header-info">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <span class="report-modal-badge" style="background:#e0e7ff; color:#3730a3; font-weight:700;">Lupon Court Session</span>
                    <span id="quickAttCaseNumBadge" style="font-size:11.5px; font-weight:700; color:#475569; background:#f1f5f9; padding:2px 8px; border-radius:4px;">Case #</span>
                </div>
                <h2 class="report-modal-title" id="quickAttendanceModalTitle" style="font-size:1.15rem; margin:0; color:#0f172a;">Quick Attendance / Check-In</h2>
                <p class="report-modal-sub" id="quickAttendanceModalSub" style="margin:2px 0 0; font-size:0.8rem; color:#64748b;">Itala ang pagdalo ng bawat panig bago simulan ang sesyon</p>
            </div>
            <button type="button" class="btn-modal-close" id="quickAttCloseBtn" aria-label="Close modal" style="font-size:1.4rem; cursor:pointer; background:none; border:none; color:#64748b;">&times;</button>
        </header>

        <div class="report-modal-body" style="padding: 20px; max-height: 70vh; overflow-y: auto;">
            <input type="hidden" id="quickAttHearingId" value="">
            <div style="background: #f1f5f9; border-radius: 8px; padding: 10px 14px; margin-bottom: 18px; font-size: 0.83rem; color: #334155; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                <div><strong id="quickAttHearingType">Mediation Hearing</strong> · <span id="quickAttHearingTime">1:00 PM</span></div>
                <div style="color: #64748b; font-size: 0.8rem;"><span id="quickAttHearingVenue">Lupon Court Room</span></div>
            </div>
            <div id="quickAttPartiesContainer" style="display: flex; flex-direction: column; gap: 12px;"></div>
            <div style="margin-top: 12px;">
                <label for="quickAttRemarks" style="display: block; font-size: 0.82rem; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                    Mga Tala ukol sa Pagkaantala / Delay Remarks (Opsyonal)
                </label>
                <textarea id="quickAttRemarks" rows="2" placeholder="Maglagay ng dahilan kung nahuli o naantala ang pagsisimula..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.85rem; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
            </div>
        </div>

        <footer class="report-modal-footer" style="padding: 14px 20px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; background: #f8fafc;">
            <button type="button" class="btn-modal-close-footer" id="quickAttCancelBtn" style="padding: 7px 16px; font-size: 0.85rem; font-weight: 600; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; cursor: pointer; color: #475569;">Kanselahin</button>
            <button type="button" id="quickAttSaveBtn" style="padding: 7px 18px; font-size: 0.85rem; font-weight: 600; border: none; background: #0f172a; color: #fff; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                I-save ang Attendance
            </button>
        </footer>
    </div>
</div>

<?php include __DIR__ . '/../../layouts/news-manager-modal.php'; ?>
<script src="../../assets/js/news-widget.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/news-widget.js'); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.AgapNewsWidget?.initStaffBulletin();
    });
</script>
<script src="../../assets/js/clerk-dashboard.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/clerk-dashboard.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
