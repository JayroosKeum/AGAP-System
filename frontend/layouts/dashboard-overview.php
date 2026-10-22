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

    <span id="cfaCases" style="display:none;" aria-hidden="true"></span>
    <span id="deadlinesDue" style="display:none;" aria-hidden="true"></span>
    <div id="mediationDeadlines" style="display:none;" aria-hidden="true"></div>
</section>
