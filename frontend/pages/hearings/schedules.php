<?php
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);

if (!in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    die('Access Denied');
}

$canManageHearings = in_array($roleId, [1, 2], true);

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/hearings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/hearings.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <h1>Hearings and Deadlines</h1>
                <p>Manage hearings, deadlines, and scheduled case activities.</p>
            </div>
            <?php if ($canManageHearings): ?>
            <button
                type="button"
                class="btn-create"
                onclick="openAddHearingModal()"
            >
                Schedule Hearing
            </button>
        <?php endif; ?>
        </div>

        <div id="hearingMessage" role="alert"></div>

        <section class="hearing-calendar-section">
            <div class="calendar-toolbar"><button type="button" id="previousMonth" class="btn-secondary" aria-label="Previous month">&larr;</button><h2 id="calendarMonth"></h2><button type="button" id="nextMonth" class="btn-secondary" aria-label="Next month">&rarr;</button></div>
            <p class="calendar-help"><?php echo $canManageHearings ? 'Select a date to start a new hearing, or select an existing hearing to edit it.' : 'Select a hearing to view its details.'; ?></p>
            <div class="hearing-calendar" id="hearingCalendar" aria-label="Hearing calendar"></div>
        </section>

        <section class="hearings-search-card" aria-label="Search and filter hearings and deadlines">
            <div class="search-card-header">
                <h2>Search / Filters</h2>
            </div>
            <form id="hearingsSearchForm" class="hearings-search-form">
                <div class="search-form-row">
                    <div class="search-field keyword-field">
                        <label for="searchKeyword">Keyword</label>
                        <div class="search-box-wrap">
                            <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="search" id="searchKeyword" name="q" class="search-input-field" placeholder="Case no., complaint no., or venue" autocomplete="off">
                            <button type="button" id="clearSearchInput" class="search-clear-btn" title="Clear keyword">&times;</button>
                        </div>
                    </div>

                    <div class="search-field status-field">
                        <label for="searchStatus">Status</label>
                        <select id="searchStatus" name="status" class="toolbar-select">
                            <option value="">All statuses</option>
                            <option value="Scheduled">Scheduled</option>
                            <option value="Pending">Pending</option>
                            <option value="Completed">Completed</option>
                            <option value="Overdue">Overdue</option>
                        </select>
                    </div>
                </div>

                <div class="search-form-row">
                    <div class="search-field hearing-type-field">
                        <label for="searchHearingType">Hearing Type</label>
                        <select id="searchHearingType" name="hearing_type" class="toolbar-select">
                            <option value="">All types</option>
                            <option value="Mediation">Mediation</option>
                            <option value="Conciliation">Conciliation</option>
                            <option value="Initial Hearing">Initial Hearing</option>
                            <option value="Arbitration">Arbitration</option>
                            <option value="Mediation Period">Mediation Period</option>
                            <option value="Conciliation Period">Conciliation Period</option>
                            <option value="Conciliation Extension">Conciliation Extension</option>
                        </select>
                    </div>

                    <div class="search-field date-field">
                        <label for="searchDateFrom">Date From</label>
                        <input type="date" id="searchDateFrom" name="date_from" class="search-input-field search-date-input">
                    </div>

                    <div class="search-field date-field">
                        <label for="searchDateTo">Date To</label>
                        <input type="date" id="searchDateTo" name="date_to" class="search-input-field search-date-input">
                    </div>
                </div>

                <div class="search-actions-row">
                    <button type="submit" class="btn-create" id="btnSearch">Search</button>
                    <button type="button" class="btn-secondary" id="btnClearSearch">Clear</button>
                </div>
            </form>
        </section>

        <!-- Attendance Monitoring Summary Cards -->
        <section class="attendance-monitor-summary-card" aria-label="Hearing Attendance Summary">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #e0f2fe; color: #0284c7;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </span>
                    <h2 style="font-size: 1.05rem; font-weight: 700; color: #0f172a; margin: 0;">Attendance Monitor (Mediation &amp; Conciliation)</h2>
                </div>
                <div class="attendance-filter-pills" style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button" class="btn-att-filter active" data-att-filter="all" onclick="filterAttendanceView('all', this)">All</button>
                    <button type="button" class="btn-att-filter" data-att-filter="both_present" onclick="filterAttendanceView('both_present', this)">Both Present</button>
                    <button type="button" class="btn-att-filter" data-att-filter="unjustified" onclick="filterAttendanceView('unjustified', this)">Unjustified Absences</button>
                    <button type="button" class="btn-att-filter" data-att-filter="excused" onclick="filterAttendanceView('excused', this)">Excused / Justified</button>
                    <button type="button" class="btn-att-filter" data-att-filter="pending" onclick="filterAttendanceView('pending', this)">Pending Intake</button>
                </div>
            </div>
            <div class="attendance-kpi-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px;">
                <div class="att-kpi-item" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; border-left: 4px solid #3b82f6;">
                    <div style="font-size: 0.76rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Sessions</div>
                    <div id="attKpiTotal" style="font-size: 1.4rem; font-weight: 700; color: #1e293b; margin-top: 2px;">0</div>
                </div>
                <div class="att-kpi-item" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; border-left: 4px solid #10b981;">
                    <div style="font-size: 0.76rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Both Present</div>
                    <div id="attKpiPresent" style="font-size: 1.4rem; font-weight: 700; color: #047857; margin-top: 2px;">0</div>
                </div>
                <div class="att-kpi-item" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; border-left: 4px solid #ef4444;">
                    <div style="font-size: 0.76rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Unjustified Absences</div>
                    <div id="attKpiUnjustified" style="font-size: 1.4rem; font-weight: 700; color: #b91c1c; margin-top: 2px;">0</div>
                </div>
                <div class="att-kpi-item" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; border-left: 4px solid #f59e0b;">
                    <div style="font-size: 0.76rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Excused / Justified</div>
                    <div id="attKpiExcused" style="font-size: 1.4rem; font-weight: 700; color: #d97706; margin-top: 2px;">0</div>
                </div>
                <div class="att-kpi-item" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; border-left: 4px solid #94a3b8;">
                    <div style="font-size: 0.76rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Pending Intake</div>
                    <div id="attKpiPending" style="font-size: 1.4rem; font-weight: 700; color: #475569; margin-top: 2px;">0</div>
                </div>
            </div>
        </section>

        <div class="table-section-header">
            <h2>Hearings and Deadlines</h2>
        </div>
        <div class="table-container">
            <table class="combined-records-table">
                <thead>
                    <tr>
                        <th>Case No.</th>
                        <th>Complaint</th>
                        <th>Hearing Type</th>
                        <th>Date and Time</th>
                        <th>Status</th>
                        <th>Attendance</th>
                        <th>Venue</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="combinedTable">
                    <tr>
                        <td colspan="8" class="empty-state">Loading hearings and deadlines...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="hearingPagination" class="complaints-pagination" aria-label="Hearings and deadlines pagination" style="display: none;">
            <span id="hearingPaginationSummary" class="complaints-pagination-summary" aria-live="polite">Showing 0 records</span>
            <div id="hearingPaginationControls" class="complaints-pagination-controls"></div>
        </div>
    </div>
</div>

<?php if ($canManageHearings): ?>
<div id="addHearingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Schedule Hearing</h2>
            <button type="button" class="close-btn" onclick="closeAddHearingModal()">&times;</button>
        </div>
        <form id="addHearingForm" action="../../../backend/api/hearings/create.php" method="POST">
            <div class="form-group">
                <label for="hearingCaseId">Case <span class="required-mark" aria-hidden="true">*</span></label>
                <select id="hearingCaseId" name="case_id" required></select>
            </div>
            <div class="form-group">
                <label for="hearingType">Next Schedule</label>
                <select id="hearingType" name="hearing_type" required>
                    <option value="">Select a case first</option>
                </select>
                <small id="hearingProgressionHelp">Select a case to see its next permitted schedule.</small>
            </div>
            <div class="form-group">
                <label for="hearingDate">Date &amp; Time <span class="required-mark" aria-hidden="true">*</span></label>
                <input type="datetime-local" id="hearingDate" name="hearing_date" required>
            </div>
            <div class="form-group">
                <label for="hearingVenue">Venue <span class="required-mark" aria-hidden="true">*</span></label>
                <input type="text" id="hearingVenue" name="venue" maxlength="255" placeholder="e.g., Barangay Hall - Hearing Room 1" required>
            </div>
            <div class="form-group">
                <label for="hearingRemarks">Remarks</label>
                <textarea id="hearingRemarks" name="remarks" rows="3" placeholder="Optional notes or instructions"></textarea>
            </div>
            <button type="submit" class="btn-create">Review Schedule</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="viewHearingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Hearing Details</h2>
            <button type="button" class="close-btn" onclick="closeViewHearingModal()">&times;</button>
        </div>
        <div id="hearingDetails"></div>
    </div>
</div>

<?php if ($canManageHearings): ?>
<div id="nonappearanceModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2>Record Unjustified Non-Appearance</h2><button type="button" class="close-btn" onclick="closeNonappearanceModal()">&times;</button></div>
        <form id="nonappearanceForm">
            <input type="hidden" name="hearing_id" id="nonappearanceHearingId">
            <div class="form-group"><label for="nonappearanceResident">Absent party <span class="required-mark" aria-hidden="true">*</span></label><select id="nonappearanceResident" name="resident_id" required></select></div>
            <div class="form-group"><label for="nonappearanceRemarks">Remarks <span class="required-mark" aria-hidden="true">*</span></label><textarea id="nonappearanceRemarks" name="remarks" rows="3" maxlength="2000" required placeholder="State the facts supporting the finding."></textarea></div>
            <button type="submit" class="btn-create">Record Non-Appearance</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageHearings): ?>
<div id="editHearingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Update Hearing</h2>
            <button type="button" class="close-btn" onclick="closeEditHearingModal()">&times;</button>
        </div>
        <form id="editHearingForm" action="../../../backend/api/hearings/update.php" method="POST">
            <input type="hidden" name="hearing_id" id="editHearingId">
            <div class="form-group">
                <label for="editHearingType">Hearing Type</label>
                <input type="text" id="editHearingType" readonly>
            </div>
            <div class="form-group">
                <label for="editHearingDate">Date &amp; Time</label>
                <input type="datetime-local" id="editHearingDate" name="hearing_date" required>
            </div>
            <div class="form-group">
                <label for="editHearingVenue">Venue</label>
                <input type="text" id="editHearingVenue" name="venue" required>
            </div>
            <div class="form-group">
                <label for="editHearingRemarks">Remarks</label>
                <textarea id="editHearingRemarks" name="remarks" rows="3"></textarea>
            </div>
            <button type="submit" class="btn-create">Review Changes</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageHearings): ?>
<div id="reviewHearingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2>Review Hearing Schedule</h2><button type="button" class="close-btn" onclick="closeReviewHearingModal()">&times;</button></div>
        <p>Confirm these details before the hearing is scheduled. This will notify the case team.</p>
        <dl id="reviewHearingDetails" class="hearing-details"></dl>
        <div class="modal-actions"><button type="button" class="btn-secondary" onclick="closeReviewHearingModal()">Back to Edit</button><button type="button" class="btn-create" id="confirmHearingSchedule">Confirm Schedule</button></div>
    </div>
</div>
<?php endif; ?>

<div id="hearingAttendanceModal" class="modal">
    <div class="modal-content modal-content-lg">
        <div class="modal-header">
            <div>
                <h2 id="attModalTitle" style="margin-bottom: 2px;">Hearing Attendance &amp; KP Compliance Monitor</h2>
                <p id="attModalSubtitle" style="font-size: 0.84rem; color: #64748b; margin: 0;">Record Complainant &amp; Respondent appearance with automated KP Law (R.A. 7160 Sec. 415) legal evaluation</p>
            </div>
            <button type="button" class="close-btn" onclick="closeHearingAttendanceModal()">&times;</button>
        </div>

        <div id="attModalAlert" style="display: none; margin-bottom: 14px;" class="alert"></div>

        <div id="attHearingMetaCard" class="att-meta-grid">
            <div class="att-meta-item">
                <strong>Case Docket</strong>
                <span id="attMetaCaseNumber">—</span>
            </div>
            <div class="att-meta-item">
                <strong>Complaint Title</strong>
                <span id="attMetaComplaintTitle">—</span>
            </div>
            <div class="att-meta-item">
                <strong>Hearing Stage</strong>
                <span id="attMetaHearingType">—</span>
            </div>
            <div class="att-meta-item">
                <strong>Date &amp; Time</strong>
                <span id="attMetaDateTime">—</span>
            </div>
            <div class="att-meta-item">
                <strong>Hearing Venue</strong>
                <span id="attMetaVenue">—</span>
            </div>
            <div class="att-meta-item">
                <strong>Summons Issued</strong>
                <span id="attMetaSummons">—</span>
            </div>
        </div>

        <form id="hearingAttendanceForm">
            <input type="hidden" id="attHearingId" name="hearing_id">

            <div style="margin-bottom: 8px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0;">Party Attendance Records</h3>
                <span style="font-size: 0.78rem; color: #64748b;">Click an appearance status for each party</span>
            </div>

            <div id="attPartiesContainer" class="att-parties-list">
                <!-- Dynamically populated party cards -->
                <div style="text-align: center; padding: 20px; color: #64748b;">Loading parties...</div>
            </div>

            <!-- Dynamic Live KP Situation Box -->
            <div id="attSituationCard" class="att-situation-box sit-neutral">
                <div class="att-situation-header">
                    <div class="att-situation-title">
                        <span id="attSituationIcon">⚖️</span>
                        <span id="attSituationBadgeText">Pending Attendance Evaluation</span>
                    </div>
                    <span id="attSituationRef" class="att-situation-ref">R.A. 7160 Sec. 415</span>
                </div>
                <div class="att-consequences-section">
                    <div class="att-consequences-title">Legal Consequences &amp; Procedural Status</div>
                    <ul id="attConsequencesList" class="att-consequences-list">
                        <li>Record attendance for Complainant and Respondent to determine statutory proceedings.</li>
                    </ul>
                </div>
                <div id="attRecommendationBox" class="att-recommendation-box">
                    <div class="att-consequences-title" style="margin-bottom: 2px;">Recommended Legal Next Steps</div>
                    <div id="attRecommendationText" class="att-recommendation-text">Awaiting appearance intake.</div>
                    <div id="attActionShortcuts" class="att-action-shortcuts"></div>
                </div>
            </div>

            <div class="modal-actions" style="margin-top: 20px;">
                <button type="button" class="btn-secondary" onclick="closeHearingAttendanceModal()">Close</button>
                <?php if ($canManageHearings): ?>
                <button type="submit" class="btn-create" id="btnSaveAttendance">Save Attendance &amp; Apply Findings</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
window.AGAP_HEARINGS = Object.freeze({
    canManage: <?php echo $canManageHearings ? 'true' : 'false'; ?>
});
</script>
<script src="../../assets/js/hearings.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/hearings.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
