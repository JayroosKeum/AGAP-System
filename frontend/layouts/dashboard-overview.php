<section class="dashboard-overview" aria-label="Case management overview">
    <div class="stats-grid" aria-live="polite">
        <article class="stat-card"><p>Total cases</p><h2 id="totalCases">—</h2><span>All recorded cases</span></article>
        <article class="stat-card"><p>For review</p><h2 id="pendingReview">—</h2><span>Needs review or information</span></article>
        <article class="stat-card"><p>Hearings today</p><h2 id="hearingsToday">—</h2><span>Scheduled sessions</span></article>
        <article class="stat-card"><p>Deadlines due</p><h2 id="deadlinesDue">—</h2><span>Mediation deadlines within 7 days</span></article>
        <article class="stat-card"><p>Active mediation</p><h2 id="activeMediations">—</h2><span>Mediation or conciliation cases</span></article>
        <article class="stat-card"><p>This week's hearings</p><h2 id="hearingsThisWeek">—</h2><span>Next 7 calendar days</span></article>
        <article class="stat-card"><p>Saved drafts</p><h2 id="draftComplaints">—</h2><span>Complaints not yet filed</span></article>
        <article class="stat-card"><p>Settled cases</p><h2 id="settledCases">—</h2><span>Resolved through settlement</span></article>
    </div>

    <p id="dashboardMessage" class="dashboard-message" role="status"></p>

    <div class="dashboard-panels">
        <section class="dashboard-panel">
            <div class="panel-heading"><div><h2>Today's hearings</h2><p>Sessions that need attention today.</p></div><a href="../hearings/schedules.php">View schedule</a></div>
            <div id="todayHearings" class="dashboard-list" aria-live="polite"></div>
        </section>
        <section class="dashboard-panel">
            <div class="panel-heading"><div><h2>Upcoming mediation deadlines</h2><p>Active cases due within the next seven days.</p></div><a href="../cases/case-list.php">View cases</a></div>
            <div id="mediationDeadlines" class="dashboard-list" aria-live="polite"></div>
        </section>
        <section class="dashboard-panel dashboard-panel-wide">
            <div class="panel-heading"><div><h2>Case status overview</h2><p>Current distribution of all cases.</p></div></div>
            <div id="statusTotals" class="status-summary" aria-live="polite"></div>
        </section>
    </div>
    <span id="cfaCases" style="display:none;" aria-hidden="true"></span>
    <span id="archivedCases" style="display:none;" aria-hidden="true"></span>
</section>
