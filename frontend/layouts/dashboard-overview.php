<section class="dashboard-overview" aria-label="Case management overview">
    <!-- Period Filter Tabs & Navigation -->
    <div class="dashboard-filter-bar">
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

        <?php if (in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 5], true)): ?>
        <!-- ── Top Report & Export Action Dropdowns ────────────────── -->
        <div class="dashboard-report-actions">
            <!-- Dedicated 1-Click Monthly Council Meeting Brief Button -->
            <button type="button" class="btn-report-dropdown" id="btnOneClickCouncilBrief" style="background: #0f172a; color: #ffffff; border-color: #0f172a;" title="Generate 1-Page Printable Executive Summary for the Monthly Barangay Council Assembly">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                <span>1-Click Council Brief</span>
            </button>

            <!-- Generate Report Dropdown -->
            <div class="dash-dropdown" id="dropdownGenerateReport">
                <button type="button" class="btn-report-dropdown" id="btnGenerateReportToggle" aria-haspopup="true" aria-expanded="false" title="Generate reports for the selected period">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    <span>Generate Report</span>
                    <svg class="dropdown-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
                <div class="dash-dropdown-menu" id="menuGenerateReport" role="menu">
                    <button type="button" class="dash-dropdown-item" id="itemPreviewReport" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <span>Preview Report</span>
                    </button>
                    <button type="button" class="dash-dropdown-item" id="itemPrintReport" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        <span>Print Report</span>
                    </button>
                    <button type="button" class="dash-dropdown-item" id="itemKpMonitoring" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                        <span>KP Monitoring Form</span>
                    </button>
                    <button type="button" class="dash-dropdown-item" id="itemDilgReport" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M16 13H8"></path><path d="M16 17H8"></path></svg>
                        <span>DILG Report</span>
                    </button>
                    <button type="button" class="dash-dropdown-item" id="itemCouncilBrief" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        <span>Monthly Council Meeting Brief (1-Page)</span>
                    </button>
                </div>
            </div>

            <!-- Export Dropdown -->
            <div class="dash-dropdown" id="dropdownExport">
                <button type="button" class="btn-report-dropdown btn-export-dropdown" id="btnExportToggle" aria-haspopup="true" aria-expanded="false" title="Export records for the selected period">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>Export</span>
                    <svg class="dropdown-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
                <div class="dash-dropdown-menu" id="menuExport" role="menu">
                    <button type="button" class="dash-dropdown-item" id="itemExportRegister" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="8" y1="13" x2="16" y2="13"></line><line x1="8" y1="17" x2="16" y2="17"></line></svg>
                        <span>Export Case Register (Excel)</span>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Top KPI Highlights Grid -->
    <div class="stats-grid" id="statsGrid" aria-live="polite">
        <article class="stat-card" data-target-stat="all-cases" role="button" tabindex="0" title="Click to view all cases in Case Register">
            <p id="totalCasesLabel">Total cases</p>
            <h2 id="totalCases">—</h2>
            <span id="totalCasesSub">All recorded cases</span>
        </article>
        <article class="stat-card" data-target-stat="hearings-today" role="button" tabindex="0" title="Click to view today's scheduled hearings">
            <p id="hearingsTodayLabel">Hearings</p>
            <h2 id="hearingsToday">—</h2>
            <span id="hearingsTodaySub">Scheduled sessions</span>
        </article>
        <article class="stat-card" data-target-stat="active-mediation" role="button" tabindex="0" title="Click to filter active mediation and conciliation cases">
            <p id="activeMediationsLabel">Active mediation</p>
            <h2 id="activeMediations">—</h2>
            <span id="activeMediationsSub">Mediation or conciliation cases</span>
        </article>
        <article class="stat-card" data-target-stat="hearings-week" role="button" tabindex="0" title="Click to view scheduled hearings for this week">
            <p id="hearingsThisWeekLabel">Hearings in period</p>
            <h2 id="hearingsThisWeek">—</h2>
            <span id="hearingsThisWeekSub">Next 7 calendar days</span>
        </article>
        <article class="stat-card" data-target-stat="paanyaya" role="button" tabindex="0" title="Click to view pre-docketed Paanyaya invitations">
            <p id="paanyayaComplaintsLabel">Paanyaya</p>
            <h2 id="paanyayaComplaints">—</h2>
            <span id="paanyayaComplaintsSub">Undocketed complaints</span>
        </article>
        <article class="stat-card" data-target-stat="settled-cases" role="button" tabindex="0" title="Click to filter cases resolved through settlement">
            <p id="settledCasesLabel">Settled cases</p>
            <h2 id="settledCases">—</h2>
            <span id="settledCasesSub">Resolved through settlement</span>
        </article>
    </div>

    <p id="dashboardMessage" class="dashboard-message" role="status"></p>

    <!-- Main Two-Column Layout (Following layout and arrangement in A.png) -->
    <div class="dash-portal-grid">
        <!-- LEFT COLUMN: Analytics Insights, Today's Hearings, Paanyaya Invitations -->
        <div class="dash-portal-main">
            <!-- 1. Today's Hearings Card (Wide layout in main column per A.png) -->
            <div class="dash-section-card" id="dashboardHearingsPanel">
                <div class="aside-card-header">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="display:inline-flex;color:#1e293b;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="M7 21h10"></path><path d="M12 3v18"></path><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"></path></svg></span>
                        <div>
                            <h3 class="aside-card-title" id="hearingsPanelHeading">Today's Hearings</h3>
                            <p class="aside-card-sub" id="hearingsPanelSub">Scheduled formal sessions</p>
                        </div>
                    </div>
                    <span id="periodHearingCountBadge" class="panel-count-badge" style="display:none;">0</span>
                </div>
                <div id="todayHearings" class="dash-hearings-stream" aria-live="polite">
                    <div style="text-align: center; color: #64748b; padding: 18px; font-size: 0.85rem;">No hearings scheduled for this date.</div>
                </div>
                <!-- Pagination -->
                <div class="dashboard-table-pagination" id="hearingsTablePagination" style="display:none; padding: 8px 16px;">
                    <div class="pagination-info" id="hearingsPaginationInfo" style="font-size: 11px;">Showing 1-10</div>
                    <div class="pagination-buttons" id="hearingsPaginationButtons">
                        <button type="button" class="btn-page-nav" id="hearingsPrevPageBtn" disabled>&laquo;</button>
                        <button type="button" class="btn-page-nav" id="hearingsNextPageBtn">&raquo;</button>
                    </div>
                </div>
            </div>

            <!-- ANALYTICS CARDS (Top Issues, Purok Hotspots, Demographics) -->
            <div class="dash-analytics-row" style="margin-top: 20px;">
                <!-- Most Complained Issues -->
                <div class="dash-analytics-card">
                    <div class="card-head">
                        <div>
                            <h3 class="card-title">Most Complained Issues</h3>
                            <p class="card-sub">Top recurring dispute categories in the barangay</p>
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
                            <p class="card-sub">Areas with highest grievance volume</p>
                        </div>
                    </div>
                    <div id="analyticsPurokHotspots" class="analytics-bars-container">
                        <div class="analytics-loading">Loading area hotspots...</div>
                    </div>
                </div>
            </div>

            <!-- DEMOGRAPHICS ROW (Gender & Age Groups) -->
            <div class="dash-analytics-row" style="margin-top: 18px;">
                <!-- Gender Breakdown -->
                <div class="dash-analytics-card">
                    <div class="card-head">
                        <div>
                            <h3 class="card-title">Gender Distribution</h3>
                            <p class="card-sub">Complainants and Respondents by gender</p>
                        </div>
                    </div>
                    <div id="analyticsGenderBreakdown" class="analytics-demographics-wrap">
                        <div class="analytics-loading">Loading gender data...</div>
                    </div>
                </div>

                <!-- Age Distribution -->
                <div class="dash-analytics-card">
                    <div class="card-head">
                        <div>
                            <h3 class="card-title">Age Group Demographics</h3>
                            <p class="card-sub">Dispute participants by age bracket</p>
                        </div>
                    </div>
                    <div id="analyticsAgeDistribution" class="analytics-demographics-wrap">
                        <div class="analytics-loading">Loading age demographics...</div>
                    </div>
                </div>
            </div>

            <!-- 2. Dedicated Paanyaya Invitations Card (Wide layout in main column per A.png) -->
            <div class="dash-section-card" id="dashboardPaanyayaPanel" style="margin-top: 20px;">
                <div class="aside-card-header">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="display:inline-flex;color:#1e293b;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg></span>
                        <div>
                            <h3 class="aside-card-title">Paanyaya Invitations</h3>
                            <p class="aside-card-sub">Pre-docketed informal invitations</p>
                        </div>
                    </div>
                    <a href="../paanyaya/index.php" class="mini-cal-link" style="font-size: 0.82rem; font-weight: 700;">View all &rarr;</a>
                </div>
                <div id="todayPaanyayas" class="dash-hearings-stream" aria-live="polite">
                    <div style="text-align: center; color: #64748b; padding: 18px; font-size: 0.85rem;">Loading Paanyaya invitations...</div>
                </div>
            </div>


        </div>

        <!-- RIGHT COLUMN: Compact Mini-Calendar & Announcements (per A.png) -->
        <aside class="dash-portal-aside">
            <!-- 1. Compact Mini Calendar -->
            <div class="mini-calendar-card" id="miniCalendarCard">
                <div class="mini-cal-header">
                    <span class="mini-cal-title-wrap">
                        <span class="mini-cal-icon" style="display:inline-flex;color:#1e293b;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></span>
                        <strong class="mini-cal-title">Calendar</strong>
                    </span>
                    <div class="mini-cal-nav">
                        <button type="button" id="dashCalPrevBtn" class="mini-cal-btn" title="Previous Month" aria-label="Previous Month">&lt;</button>
                        <span id="dashCalMonthDisplay" class="mini-cal-month-text">Nov 2026</span>
                        <button type="button" id="dashCalNextBtn" class="mini-cal-btn" title="Next Month" aria-label="Next Month">&gt;</button>
                    </div>
                </div>

                <!-- Weekday Headers & Grid -->
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

            <!-- 2. Announcements & Advisories Card -->
            <div class="dash-aside-card">
                <div class="aside-card-header">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="display:inline-flex;color:#1e293b;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg></span>
                        <div>
                            <h3 class="aside-card-title">Announcements</h3>
                            <p class="aside-card-sub">Barangay Tumana bulletin</p>
                        </div>
                    </div>
                </div>
                <div class="dash-aside-body" style="padding: 14px 16px;">
                    <div class="dash-aside-bulletin">
                        <strong>Monthly Council Meeting</strong>
                        <p>Katarungang Pambarangay monthly case presentation takes place on the 1st Monday of every month.</p>
                        <button type="button" id="btnBulletinCouncilBrief" style="margin-top: 8px; width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 12px; border-radius: 7px; border: 1px solid #cbd5e1; background: #f8fafc; color: #0f172a; font-size: 0.78rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                            Print 1-Page Council Meeting Brief
                        </button>
                    </div>
                    <div class="dash-aside-bulletin" style="margin-top: 12px; border-top: 1px dashed #e2e8f0; padding-top: 12px;">
                        <strong>Lupon Duty Attendance</strong>
                        <p>Lupon members are required to report in-person on their assigned weekly duty days in Settings.</p>
                    </div>
                </div>
            </div>
        </aside>
    </div>



    <?php if (in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2, 5], true)): ?>
    <!-- ── Report Preview Modal Overlay ───────────────────────────── -->
    <div id="reportPreviewModal" class="report-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="modalReportTitle">
        <div class="report-modal-container">
            <header class="report-modal-header">
                <div class="modal-header-info">
                    <span class="report-modal-badge" id="modalPeriodBadge">Monthly</span>
                    <h2 id="modalReportTitle" class="report-modal-title">Report Preview</h2>
                    <p id="modalReportSubtitle" class="report-modal-sub">Period details</p>
                </div>
                <div class="report-modal-actions">
                    <button type="button" id="modalPrintBtn" class="btn-modal-action btn-modal-print" title="Print this report">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        <span>Print</span>
                    </button>
                    <button type="button" id="modalCloseBtn" class="btn-modal-close" aria-label="Close modal">&times;</button>
                </div>
            </header>

            <div class="report-modal-body" id="modalReportBody">
                <div id="modalLoading" class="report-modal-loading" style="display:none;">
                    <div class="dash-spinner"></div>
                    <span>Generating report preview...</span>
                </div>
                <div id="modalContent">
                    <div class="report-summary" id="modalSummaryCards"></div>
                    <div class="report-grid">
                        <div class="report-card">
                            <h3>Case status</h3>
                            <div id="modalStatusTotals"></div>
                        </div>
                        <div class="report-card">
                            <h3>Complaint categories</h3>
                            <div id="modalCategoryTotals"></div>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="report-modal-footer">
                <button type="button" class="btn-modal-close-footer" id="modalCloseFooterBtn">Close</button>
            </footer>
        </div>
    </div>

    <!-- ── KP Monitoring Form Modal Overlay ───────────────────────── -->
    <div id="kpMonitoringModal" class="report-modal-overlay kp-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="kpDocTitle">
        <div class="report-modal-container kp-modal-container">
            <header class="report-modal-header kp-modal-header-actions">
                <div class="modal-header-info">
                    <span class="report-modal-badge">Official Form</span>
                    <h2 class="report-modal-title">Lupong Tagapamayapa Monitoring Form</h2>
                    <p class="report-modal-sub">Summary of Cases Filed and Actions Taken (Editable &amp; Printable)</p>
                </div>
                <div class="report-modal-actions">
                    <button type="button" id="kpExportExcelBtn" class="btn-modal-action btn-modal-export" title="Export this KP Monitoring Form to Excel (.xlsx)">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="8" y1="13" x2="16" y2="13"></line><line x1="8" y1="17" x2="16" y2="17"></line></svg>
                        <span>Export to Excel (.xlsx)</span>
                    </button>
                    <button type="button" id="kpPrintBtn" class="btn-modal-action btn-modal-print" title="Print this official form">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        <span>Print Form</span>
                    </button>
                    <button type="button" id="kpCloseBtn" class="btn-modal-close" aria-label="Close modal">&times;</button>
                </div>
            </header>

            <div class="report-modal-body kp-modal-body">
                <div id="kpLoading" class="report-modal-loading" style="display:none;">
                    <div class="dash-spinner"></div>
                    <span>Loading KP Monitoring Form data...</span>
                </div>
                <div id="kpPrintableArea" class="kp-sheet"></div>
            </div>

            <footer class="report-modal-footer">
                <button type="button" class="btn-modal-close-footer" id="kpCloseFooterBtn">Close</button>
            </footer>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Quick Attendance / Check-In Modal ───────────────────────── -->
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
                <div id="quickCompAbsentNotice" style="display: none; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 8px 12px; margin-top: 10px; font-size: 0.82rem; color: #991b1b;">
                    <strong>Paalala: May Complainant na Hindi Dumalo</strong> &bull; Maaaring maging batayan ito para sa pag-dismiss ng reklamo alinsunod sa KP rules sa katitikan ng pagdinig.
                </div>
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

    <span id="cfaCases" style="display:none;" aria-hidden="true"></span>
    <span id="deadlinesDue" style="display:none;" aria-hidden="true"></span>
    <div id="mediationDeadlines" style="display:none;" aria-hidden="true"></div>
</section>
