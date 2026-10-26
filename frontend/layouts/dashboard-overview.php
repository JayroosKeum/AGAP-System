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

        <?php if (in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true)): ?>
        <!-- ── Top Report & Export Action Dropdowns ────────────────── -->
        <div class="dashboard-report-actions">
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

    <div class="stats-grid" id="statsGrid" aria-live="polite">
        <article class="stat-card">
            <p id="totalCasesLabel">Total cases</p>
            <h2 id="totalCases">—</h2>
            <span id="totalCasesSub">All recorded cases</span>
        </article>
        <article class="stat-card">
            <p id="hearingsTodayLabel">Hearings</p>
            <h2 id="hearingsToday">—</h2>
            <span id="hearingsTodaySub">Scheduled sessions</span>
        </article>
        <article class="stat-card">
            <p id="archivedCasesLabel">Archived cases</p>
            <h2 id="archivedCases">—</h2>
            <span id="archivedCasesSub">Closed and archived records</span>
        </article>
        <article class="stat-card">
            <p id="activeMediationsLabel">Active mediation</p>
            <h2 id="activeMediations">—</h2>
            <span id="activeMediationsSub">Mediation or conciliation cases</span>
        </article>
        <article class="stat-card">
            <p id="hearingsThisWeekLabel">Hearings in period</p>
            <h2 id="hearingsThisWeek">—</h2>
            <span id="hearingsThisWeekSub">Next 7 calendar days</span>
        </article>
        <article class="stat-card">
            <p id="paanyayaComplaintsLabel">Paanyaya</p>
            <h2 id="paanyayaComplaints">—</h2>
            <span id="paanyayaComplaintsSub">Undocketed complaints</span>
        </article>
        <article class="stat-card">
            <p id="settledCasesLabel">Settled cases</p>
            <h2 id="settledCases">—</h2>
            <span id="settledCasesSub">Resolved through settlement</span>
        </article>
    </div>

    <p id="dashboardMessage" class="dashboard-message" role="status"></p>

    <!-- Hearings & Calendar Row (Placed Above Cases Table to match layout) -->
    <div class="dashboard-panels">
        <section class="dashboard-panel" id="dashboardHearingsPanel">
            <div class="panel-heading">
                <div>
                    <h2 id="hearingsPanelHeading">Today's hearings</h2>
                    <p id="hearingsPanelSub">Sessions scheduled for today.</p>
                </div>
                <div class="panel-heading-actions">
                    <span id="periodHearingCountBadge" class="panel-count-badge" style="display:none;">0 hearings</span>
                </div>
            </div>
            <div id="todayHearings" class="dashboard-list" aria-live="polite"></div>
            <!-- Pagination: Only show 10 at a time -->
            <div class="dashboard-table-pagination" id="hearingsTablePagination" style="display:none;">
                <div class="pagination-info" id="hearingsPaginationInfo">Showing 1 to 10 of 0 hearings</div>
                <div class="pagination-buttons" id="hearingsPaginationButtons">
                    <button type="button" class="btn-page-nav" id="hearingsPrevPageBtn" disabled>&laquo; Prev</button>
                    <div class="pagination-page-numbers" id="hearingsPageNumbers"></div>
                    <button type="button" class="btn-page-nav" id="hearingsNextPageBtn">Next &raquo;</button>
                </div>
            </div>
        </section>

        <!-- Interactive Monthly Hearings Calendar -->
        <section class="dashboard-panel dashboard-calendar-panel" id="dashboardCalendarPanel">
            <div class="panel-heading">
                <div>
                    <h2 id="calendarPanelHeading">Hearings Calendar</h2>
                    <p id="calendarPanelSub">Monthly schedule of dispute hearings</p>
                </div>
                <div class="panel-heading-actions">
                    <div class="dash-cal-nav">
                        <button type="button" id="dashCalPrevBtn" class="btn-cal-nav" title="Previous Month" aria-label="Previous Month">&#8249;</button>
                        <span id="dashCalMonthDisplay" class="dash-cal-month-title">October 2026</span>
                        <button type="button" id="dashCalNextBtn" class="btn-cal-nav" title="Next Month" aria-label="Next Month">&#8250;</button>
                        <button type="button" id="dashCalTodayBtn" class="btn-cal-today" title="Go to Current Month">Today</button>
                    </div>
                </div>
            </div>
            <div class="dashboard-calendar-wrap">
                <div class="dashboard-calendar-grid" id="dashboardCalendarGrid" aria-label="Hearing Calendar"></div>
            </div>
            <div class="dashboard-cal-legend">
                <span class="cal-legend-item"><span class="cal-legend-dot has-hearing"></span> Hearing Scheduled</span>
                <span class="cal-legend-item"><span class="cal-legend-dot is-today"></span> Today</span>
            </div>
        </section>
    </div>

    <!-- Period Cases Panel (10 at a time with pagination) -->
    <section class="dashboard-panel dashboard-panel-wide" id="dashboardCasesPanel">
        <div class="panel-heading">
            <div>
                <h2>Cases (<span id="casesPeriodTitle">All Time</span>)</h2>
                <p id="casesPeriodDescription">Docketed cases recorded for this timeframe.</p>
            </div>
            <div class="panel-heading-actions">
                <span id="periodCaseCountBadge" class="panel-count-badge">0 cases</span>
                <a href="../cases/case-list.php" class="panel-link">View all cases</a>
            </div>
        </div>
        <div class="dashboard-cases-table-wrap">
            <table class="dashboard-cases-table">
                <thead>
                    <tr>
                        <th>Case #</th>
                        <th>Complaint Title / Parties</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Docket Date</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody id="periodCasesTableBody" aria-live="polite">
                    <tr><td colspan="6" class="dashboard-empty-row"><p>Loading cases...</p></td></tr>
                </tbody>
            </table>
        </div>
        <!-- Pagination: Only show 10 at a time -->
        <div class="dashboard-table-pagination" id="casesTablePagination" style="display:none;">
            <div class="pagination-info" id="casesPaginationInfo">Showing 1 to 10 of 0 cases</div>
            <div class="pagination-buttons" id="casesPaginationButtons">
                <button type="button" class="btn-page-nav" id="casesPrevPageBtn" disabled>&laquo; Prev</button>
                <div class="pagination-page-numbers" id="casesPageNumbers"></div>
                <button type="button" class="btn-page-nav" id="casesNextPageBtn">Next &raquo;</button>
            </div>
        </div>
    </section>

    <?php if (in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true)): ?>
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
                    <!-- Key Statistics Cards -->
                    <div class="report-summary" id="modalSummaryCards"></div>

                    <!-- Case Status & Complaint Categories Grid -->
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

                    <!-- Case Details Table -->
                    <div class="report-card" style="margin-top:16px;">
                        <h3>Case details</h3>
                        <div class="table-container" style="margin:0; overflow-x:auto;">
                            <table class="dashboard-cases-table" style="width:100%; border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th>Case</th>
                                        <th>Complaint</th>
                                        <th>Category</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Docketed</th>
                                        <th>Hearings</th>
                                        <th>Resolution / Archive</th>
                                    </tr>
                                </thead>
                                <tbody id="modalReportCases"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="report-modal-footer">
                <button type="button" class="btn-modal-close-footer" id="modalCloseFooterBtn">Close</button>
            </footer>
        </div>
    </div>
    <?php endif; ?>

    <?php if (in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true)): ?>
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

                <div id="kpPrintableArea" class="kp-sheet">
                    <!-- Top Form Metadata -->
                    <div class="kp-meta-grid">
                        <div class="kp-meta-row">
                            <div class="kp-meta-field">
                                <span class="kp-meta-label">REGION:</span>
                                <input type="text" id="kpRegionInput" class="kp-input-underline" value="NCR">
                            </div>
                            <div class="kp-meta-field">
                                <span class="kp-meta-label">CITY/MUNICIPALITY:</span>
                                <input type="text" id="kpCityInput" class="kp-input-underline" value="MARIKINA">
                            </div>
                            <div class="kp-meta-field">
                                <span class="kp-meta-label">BARANGAY:</span>
                                <input type="text" id="kpBarangayInput" class="kp-input-underline" value="TUMANA">
                            </div>
                        </div>
                        <div class="kp-meta-row kp-meta-row-secondary">
                            <div class="kp-meta-field">
                                <span class="kp-meta-label">REPORTING QUARTER:</span>
                                <select id="kpQuarterInput" class="kp-input-underline kp-select-quarter">
                                    <option value="Quarter 1">Quarter 1</option>
                                    <option value="Quarter 2">Quarter 2</option>
                                    <option value="Quarter 3">Quarter 3</option>
                                    <option value="Quarter 4">Quarter 4</option>
                                </select>
                            </div>
                            <div class="kp-meta-field">
                                <span class="kp-meta-label">CY:</span>
                                <input type="number" id="kpCyInput" class="kp-input-underline kp-input-short" value="2026">
                            </div>
                            <div class="kp-meta-field">
                                <span class="kp-meta-label">DATE ACCOMPLISHED:</span>
                                <input type="text" id="kpDateAccomplishedInput" class="kp-input-underline" value="">
                            </div>
                        </div>
                    </div>

                    <!-- Center Title Block -->
                    <div class="kp-title-section">
                        <h1 id="kpDocTitle" class="kp-main-heading">LUPONG TAGAPAMAYAPA REPORT</h1>
                        <h2 class="kp-sub-heading">SUMMARY OF CASES FILED AND ACTIONS TAKEN</h2>
                        <div class="kp-period-display">
                            <span>for the month of </span>
                            <span id="kpMonthDisplay" class="kp-month-name">OCTOBER 2026</span>
                        </div>
                    </div>

                    <!-- Top Summary Matrix Table (Multi-Tier matching Image 4) -->
                    <div class="kp-table-wrapper">
                        <table class="kp-matrix-table">
                            <thead>
                                <tr>
                                    <th colspan="4" class="th-nature">NATURE OF DISPUTES</th>
                                    <th colspan="11" class="th-action">ACTION TAKEN</th>
                                    <th rowspan="3" class="th-savings">ESTIMATED GOVERNMENT SAVINGS</th>
                                </tr>
                                <tr>
                                    <th rowspan="2" class="th-col">CRIMINAL</th>
                                    <th rowspan="2" class="th-col">CIVIL</th>
                                    <th rowspan="2" class="th-col">OTHERS</th>
                                    <th rowspan="2" class="th-col th-subtotal">TOTAL</th>
                                    <th colspan="4" class="th-settled">SETTLED CASES</th>
                                    <th colspan="7" class="th-unsettled">UNSETTLED CASES</th>
                                </tr>
                                <tr>
                                    <!-- Settled -->
                                    <th class="th-col">Mediation</th>
                                    <th class="th-col">Conciliation</th>
                                    <th class="th-col">Arbitration</th>
                                    <th class="th-col th-subtotal">TOTAL</th>
                                    <!-- Unsettled -->
                                    <th class="th-col">Repudiated</th>
                                    <th class="th-col">Dropped</th>
                                    <th class="th-col">Pending</th>
                                    <th class="th-col">Dismissed</th>
                                    <th class="th-col">Certified</th>
                                    <th class="th-col">Referred</th>
                                    <th class="th-col th-subtotal">TOTAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <!-- Nature Disputes Inputs -->
                                    <td><input type="number" id="kpInCriminal" class="kp-cell-num kp-in-nature" min="0" value="0"></td>
                                    <td><input type="number" id="kpInCivil" class="kp-cell-num kp-in-nature" min="0" value="0"></td>
                                    <td><input type="number" id="kpInOthers" class="kp-cell-num kp-in-nature" min="0" value="0"></td>
                                    <td class="kp-cell-total"><strong id="kpTotalFiledDisplay">0</strong></td>

                                    <!-- Settled Inputs -->
                                    <td><input type="number" id="kpInMediation" class="kp-cell-num kp-in-settled" min="0" value="0"></td>
                                    <td><input type="number" id="kpInConciliation" class="kp-cell-num kp-in-settled" min="0" value="0"></td>
                                    <td><input type="number" id="kpInArbitration" class="kp-cell-num kp-in-settled" min="0" value="0"></td>
                                    <td class="kp-cell-total"><strong id="kpTotalSettledDisplay">0</strong></td>

                                    <!-- Unsettled Inputs -->
                                    <td><input type="number" id="kpInRepudiated" class="kp-cell-num kp-in-unsettled" min="0" value="0"></td>
                                    <td><input type="number" id="kpInDropped" class="kp-cell-num kp-in-unsettled" min="0" value="0"></td>
                                    <td><input type="number" id="kpInPending" class="kp-cell-num kp-in-unsettled" min="0" value="0"></td>
                                    <td><input type="number" id="kpInDismissed" class="kp-cell-num kp-in-unsettled" min="0" value="0"></td>
                                    <td><input type="number" id="kpInCertified" class="kp-cell-num kp-in-unsettled" min="0" value="0"></td>
                                    <td><input type="number" id="kpInReferred" class="kp-cell-num kp-in-unsettled" min="0" value="0"></td>
                                    <td class="kp-cell-total"><strong id="kpTotalUnsettledDisplay">0</strong></td>

                                    <!-- Savings Display -->
                                    <td class="kp-cell-savings">
                                        <div class="kp-savings-calc">
                                            <div class="kp-savings-rate">PHP 9,500.00 / settled case</div>
                                            <div class="kp-savings-total" id="kpSavingsDisplay">PHP 0.00</div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Signatories Section -->
                    <div class="kp-signatories-grid">
                        <div class="kp-signatory-col">
                            <p class="kp-sig-role-label">Prepared by:</p>
                            <input type="text" id="kpPreparedByInput" class="kp-sig-input-name" placeholder="Enter Lupon Member / Secretary Name" value="LUPON MEMBER / SECRETARY">
                            <p class="kp-sig-role-title">Lupon Member / Secretary</p>
                        </div>
                        <div class="kp-signatory-col">
                            <p class="kp-sig-role-label">Noted by:</p>
                            <input type="text" id="kpNotedByInput" class="kp-sig-input-name" value="HON. AKIKO LYNNE CENTENO">
                            <p class="kp-sig-role-title">Punong Barangay / Lupon Chairman</p>
                        </div>
                        <div class="kp-signatory-col">
                            <p class="kp-sig-role-label">Attested by:</p>
                            <input type="text" id="kpAttestedByInput" class="kp-sig-input-name" value="PAMELA CONSULTA">
                            <p class="kp-sig-role-title">Barangay Secretary</p>
                        </div>
                    </div>

                    <!-- Detailed Cases Section -->
                    <div class="kp-case-details-section">
                        <div class="kp-section-head">
                            <h3 class="kp-section-title">Case Register Listing (<span id="kpEntriesCount">Showing 0 entries</span>)</h3>
                        </div>
                        <div class="table-container kp-details-table-wrap">
                            <table class="kp-register-table">
                                <thead>
                                    <tr>
                                        <th>Case No.</th>
                                        <th>Complaint No.</th>
                                        <th>Case Type</th>
                                        <th>Category</th>
                                        <th>Dispute Title</th>
                                        <th>Complainant</th>
                                        <th>Respondent</th>
                                        <th>Incident Date</th>
                                        <th>Docket Date</th>
                                        <th>Summons Issued</th>
                                        <th>Summons Served</th>
                                        <th>Serving Officer</th>
                                        <th>Delivery Status</th>
                                        <th>Summons SLA</th>
                                        <th>Mediation</th>
                                        <th>Conciliation</th>
                                        <th>Settlement Date</th>
                                        <th>Settlement Compliance</th>
                                        <th>CFA Issuance</th>
                                        <th>CFA Reason</th>
                                        <th>Case Status</th>
                                    </tr>
                                </thead>
                                <tbody id="kpCasesTbody">
                                    <tr>
                                        <td colspan="21" class="dashboard-empty-row" style="text-align:center; padding:24px; color:#64748b;">Loading case register...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="report-modal-footer">
                <button type="button" class="btn-modal-close-footer" id="kpCloseFooterBtn">Close</button>
            </footer>
        </div>
    </div>
    <?php endif; ?>


    <span id="cfaCases" style="display:none;" aria-hidden="true"></span>
    <span id="deadlinesDue" style="display:none;" aria-hidden="true"></span>
    <div id="mediationDeadlines" style="display:none;" aria-hidden="true"></div>
</section>
