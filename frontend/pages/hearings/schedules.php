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
                <p>Manage hearings, deadlines, service verification, and scheduled case activities.</p>
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

        <!-- Collapsible Search & Filter Controls -->
        <details class="hearings-search-card" id="hearingsSearchCard" aria-label="Search and filter hearings and deadlines">
            <summary class="search-card-header" id="searchSummaryToggle">
                <div class="search-card-title-wrap">
                    <span class="search-header-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </span>
                    <h2>Search / Filters</h2>
                    <span id="activeFilterBadge" class="filter-count-badge" style="display: none;">0 active</span>
                </div>
                <div class="search-toggle-action">
                    <span id="searchToggleText" class="search-toggle-text">Show Filters</span>
                    <svg class="search-toggle-chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </div>
            </summary>
            <div class="search-card-body">
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
            </div>
        </details>

        <!-- Responsive Side-by-Side Main Grid: Calendar Left (1.35fr), Hearings & Today's Deadlines Table Right (1fr) -->
        <div class="hearings-main-grid">
            <section class="hearing-calendar-section">
                <div class="calendar-toolbar"><button type="button" id="previousMonth" class="btn-secondary" aria-label="Previous month">&larr;</button><h2 id="calendarMonth"></h2><button type="button" id="nextMonth" class="btn-secondary" aria-label="Next month">&rarr;</button></div>
                <p class="calendar-help"><?php echo $canManageHearings ? 'Select a date to start a new hearing, or select an existing hearing to edit it.' : 'Select a hearing to view its details.'; ?></p>
                <div class="hearing-calendar" id="hearingCalendar" aria-label="Hearing calendar"></div>
            </section>

            <section class="hearing-table-section">
                <div class="table-section-header">
                    <h2>Hearings &amp; Deadlines</h2>
                    <span id="scheduleDateBadge" class="badge-today-deadline" title="Showing schedule for this date (click to reset to today)" style="cursor: pointer;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span id="scheduleDateBadgeLabel">Deadlines Due Today: <?php echo date('F j, Y'); ?> (Current day only)</span>
                    </span>
                </div>
                <div class="table-container">
                    <table class="combined-records-table minimized-schedule-table">
                        <colgroup>
                            <col style="width: 26%;">
                            <col style="width: 28%;">
                            <col style="width: 22%;">
                            <col style="width: 24%;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Case No.</th>
                                <th>Hearing Type</th>
                                <th>Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="combinedTable">
                            <tr>
                                <td colspan="4" class="empty-state">Loading hearings and deadlines...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div id="hearingPagination" class="complaints-pagination" aria-label="Hearings and deadlines pagination" style="display: none;">
                    <span id="hearingPaginationSummary" class="complaints-pagination-summary" aria-live="polite">Showing 0 records</span>
                    <div id="hearingPaginationControls" class="complaints-pagination-controls"></div>
                </div>
            </section>
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
                <select id="hearingType" name="hearing_type" required onchange="handleHearingTypeChange(this.value)">
                    <option value="">Select a case first</option>
                </select>
                <small id="hearingProgressionHelp">Select a case to see its next permitted schedule.</small>
            </div>
            
            <!-- Approved Hearing Blocks (Section A.2) -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px;">
                <span style="font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase;">Approved Hearing Blocks:</span>
                <div style="display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" style="padding: 4px 10px; font-size: 0.78rem;" onclick="setApprovedBlock('morning')">Morning: 9:00 AM – 11:30 AM</button>
                    <button type="button" class="btn-secondary" style="padding: 4px 10px; font-size: 0.78rem;" onclick="setApprovedBlock('afternoon')">Afternoon: 1:30 PM – 4:00 PM</button>
                </div>
                <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Rejected: 8:00–8:59 AM, 11:31 AM–1:29 PM (lunch block 12–1 PM), and after 4:00 PM.</small>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label for="hearingDate">Start Date &amp; Time <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="datetime-local" id="hearingDate" name="hearing_date" required onchange="handleHearingDateOrDurationChange()">
                    <small id="hearingDateStaticHint" style="color: #64748b; font-size: 0.8rem; display: block; margin-top: 4px;">Office hours: Monday to Friday, 8:00 AM – 5:00 PM.</small>
                    <div class="date-validation-hint" data-for="hearingDate" role="alert" aria-live="polite">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span class="hint-msg"></span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="hearingDuration">Duration (Minutes) <span class="required-mark">*</span></label>
                    <select id="hearingDuration" name="duration_minutes" onchange="handleHearingDateOrDurationChange()">
                        <option value="30">30 minutes</option>
                        <option value="45" selected>45 minutes (Default)</option>
                        <option value="60">60 minutes</option>
                        <option value="75">75 minutes</option>
                        <option value="90">90 minutes</option>
                    </select>
                    <small id="calculatedEndTimeText" style="color: #1e3a8a; font-size: 0.8rem; display: block; margin-top: 4px;">End: —</small>
                </div>
            </div>

            <div class="form-group" id="durationExceedGroup" style="display: none;">
                <label for="durationExceedReason" style="color: #b45309;">Duration Exceedance Justification <span class="required-mark">*</span></label>
                <input type="text" id="durationExceedReason" name="duration_exceed_reason" placeholder="Log valid reason for exceeding standard duration (e.g., complex multi-claim settlement drafting)">
            </div>

            <div class="form-group">
                <label for="hearingPresidingOfficer">Presiding Officer</label>
                <select id="hearingPresidingOfficer" name="presiding_officer_id">
                    <option value="">Current Presider / Assigned Mediator</option>
                </select>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <label for="hearingVenue" style="margin-bottom: 0;">Venue <span class="required-mark" aria-hidden="true">*</span></label>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn-secondary" style="padding: 2px 8px; font-size: 0.74rem;" onclick="setVenuePreset('Mediation')">PB Office</button>
                        <button type="button" class="btn-secondary" style="padding: 2px 8px; font-size: 0.74rem;" onclick="setVenuePreset('Conciliation')">Lupon Office</button>
                    </div>
                </div>
                <input type="text" id="hearingVenue" name="venue" maxlength="255" placeholder="e.g., Punong Barangay / Barangay Captain's Office" required>
            </div>

            <div class="form-group">
                <label for="hearingRemarks">Remarks</label>
                <textarea id="hearingRemarks" name="remarks" rows="2" placeholder="Optional notes or instructions"></textarea>
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
<div id="reviewHearingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Review Hearing Schedule</h2>
            <button type="button" class="close-btn" onclick="closeReviewHearingModal()">&times;</button>
        </div>
        <p>Confirm these details before the hearing is scheduled. This will notify the case team.</p>
        <dl id="reviewHearingDetails" class="hearing-details"></dl>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="closeReviewHearingModal()">Back to Edit</button>
            <button type="button" class="btn-create" id="confirmHearingSchedule">Confirm Schedule</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Comprehensive Hearing Session, Service Verification & Attendance Modal -->
<div id="editHearingModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="attModalTitle">
    <div class="modal-content modal-content-lg">
        <div class="modal-header">
            <div>
                <h2 id="attModalTitle" style="margin-bottom: 2px;">Hearing Session &amp; Service Verification</h2>
                <p id="attModalSubtitle" style="font-size: 0.84rem; color: #64748b; margin: 0;">Review summons service status, record party attendance, and verify notice delivery.</p>
            </div>
            <button type="button" class="close-btn" onclick="closeHearingAttendanceModal()" aria-label="Close dialog">&times;</button>
        </div>

        <div id="attModalAlert" style="display: none; margin-bottom: 14px;" class="alert" role="alert"></div>

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

        <!-- Session & Case Management Action Bar -->
        <div class="hearing-session-actions-bar" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
            <button type="button" class="btn-secondary" style="font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px;" onclick="goToStageMinutesCurrent()">
                📝 Manage Stage Minutes &amp; Settlement &rarr;
            </button>
            <button type="button" class="btn-secondary" id="btnElevateToPangkatQuick" style="font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px; color: #b91c1c;" onclick="openFailMediationModalCurrent()">
                ⚖️ Elevate to Pangkat (KP 10)
            </button>
            <button type="button" class="btn-secondary" style="font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px; color: #1e40af;" onclick="viewCaseTransferPackageCurrent()">
                📦 Case Transfer Package
            </button>
            <button type="button" class="btn-secondary" style="font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px; color: #b45309;" onclick="openOfficeCancelModalCurrent()">
                ⚠️ Cancel Session (Emergency)
            </button>
        </div>

        <!-- Attendance & Service Verification Form -->
        <form id="hearingAttendanceForm" onsubmit="event.preventDefault(); handleSaveAttendance();" novalidate>
            <input type="hidden" id="attHearingId" name="hearing_id">

            <div style="margin-bottom: 10px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0;">Parties, Service Verification &amp; Attendance Records</h3>
                <span style="font-size: 0.78rem; color: #64748b;">Verify service status &amp; mark attendance</span>
            </div>

            <div id="attPartiesContainer" class="att-parties-list">
                <!-- Dynamically populated party cards with service verification & attendance -->
                <div style="text-align: center; padding: 20px; color: #64748b;">Loading parties...</div>
            </div>

            <!-- Dynamic Live KP Situation Box -->
            <div id="attSituationCard" class="att-situation-box sit-neutral" style="margin-top: 14px;">
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

            <div id="attModalBottomAlert" style="display: none; margin-top: 14px; margin-bottom: 8px;" class="alert" role="alert"></div>

            <div class="modal-actions" style="margin-top: 18px; margin-bottom: 6px;">
                <button type="button" class="btn-secondary" onclick="closeHearingAttendanceModal()">Close</button>
                <?php if ($canManageHearings): ?>
                <button type="button" class="btn-create" id="btnSaveAttendance" onclick="handleSaveAttendance()">
                    Save Attendance &amp; Apply Findings
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: Office Cancellation (Emergency Rescheduling)      -->
<!-- ======================================================== -->
<div id="officeCancelModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Office Cancellation (Emergency Reschedule)</h2>
            <button type="button" class="close-btn" onclick="closeModal('officeCancelModal')">&times;</button>
        </div>
        <div id="officeCancelAlert" class="alert" style="display: none; margin-bottom: 12px;"></div>
        <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 0.84rem; color: #1e40af;">
            <strong>Section D Policy:</strong> When the Barangay Hall cancels the hearing (e.g. Punong Barangay called to emergency LGU duty, typhoon closure), the session is marked <strong>Office Cancelled</strong>. <em>Neither party is penalized nor marked absent.</em>
        </div>
        <form id="officeCancelForm" onsubmit="handleOfficeCancelSubmit(event)">
            <input type="hidden" id="officeCancelHearingId" name="hearing_id">
            <div class="form-group">
                <label for="officeCancelReason">Official Reason for Office Cancellation <span class="required-mark">*</span></label>
                <textarea id="officeCancelReason" name="cancellation_reason" rows="3" required placeholder="e.g. Punong Barangay called to emergency municipal coordination meeting; Hall closed due to storm signal"></textarea>
            </div>
            <div class="form-group">
                <label for="officeCancelRescheduleDate">Optional New Schedule Date &amp; Time</label>
                <input type="datetime-local" id="officeCancelRescheduleDate" name="reschedule_date">
                <small style="color: #64748b; font-size: 0.78rem;">Leave blank if parties will be notified of reset date later.</small>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('officeCancelModal')">Cancel</button>
                <button type="submit" class="btn-danger" style="background: #dc2626; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer;">Confirm Office Cancellation</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: Assign Substitute Presider                        -->
<!-- ======================================================== -->
<div id="substitutePresiderModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Designate Substitute Presider</h2>
            <button type="button" class="close-btn" onclick="closeModal('substitutePresiderModal')">&times;</button>
        </div>
        <div id="substituteAlert" class="alert" style="display: none; margin-bottom: 12px;"></div>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 0.84rem; color: #334155;">
            If the Punong Barangay / original presider is unavailable, an active Lupon mediator may proceed <strong>only if both parties mutually consent</strong>.
        </div>
        <form id="substitutePresiderForm" onsubmit="handleSubstituteSubmit(event)">
            <input type="hidden" id="substituteHearingId" name="hearing_id">
            <div class="form-group">
                <label for="substitutePresiderId">Designated Substitute Mediator <span class="required-mark">*</span></label>
                <select id="substitutePresiderId" name="substitute_presider_id" required>
                    <option value="">Select an active Lupon Member</option>
                </select>
            </div>
            <div class="form-group">
                <label for="substituteReason">Reason for Presider Substitution <span class="required-mark">*</span></label>
                <textarea id="substituteReason" name="substitute_reason" rows="2" required placeholder="e.g. Punong Barangay on emergency mission; designated Lupon mediator stepping in"></textarea>
            </div>
            <div class="form-group" style="margin-top: 10px;">
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 0.86rem; color: #1e293b;">
                    <input type="checkbox" id="partiesConsentCheckbox" name="parties_consent_to_substitute" value="1" required style="margin-top: 3px;">
                    <span><strong>Mandatory Mutual Consent:</strong> Both Complainant and Respondent were consulted and have explicitly consented to proceed with this designated substitute presider.</span>
                </label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('substitutePresiderModal')">Cancel</button>
                <button type="submit" class="btn-create">Designate Substitute Presider</button>
            </div>
        </form>
    </div>
</div>


<!-- ======================================================== -->
<!-- MODAL: Declare Failed Mediation & Elevate to Pangkat     -->
<!-- ======================================================== -->
<div id="failMediationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Elevate Case to Pangkat Tagapagkasundo</h2>
            <button type="button" class="close-btn" onclick="closeModal('failMediationModal')">&times;</button>
        </div>
        <div id="failMediationAlert" class="alert" style="display: none; margin-bottom: 12px;"></div>
        <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 0.84rem; color: #991b1b;">
            <strong>Mediation Termination (Section L):</strong> When mediation fails after 3 sessions (or when parties cannot settle before the Punong Barangay), mediation is declared failed. The system will automatically:
            <ul style="margin: 6px 0 0 16px; padding: 0;">
                <li>Transition the case to <strong>Conciliation</strong></li>
                <li>Generate <strong>KP Form 10</strong> (Notice to Constitute the Pangkat Tagapagkasundo)</li>
                <li>Assemble the <strong>Case Transfer Package</strong></li>
            </ul>
        </div>
        <form id="failMediationForm" onsubmit="handleFailMediationSubmit(event)">
            <input type="hidden" id="failMediationHearingId" name="hearing_id">
            
            <div class="form-group">
                <label for="failSelectionMethod">Pangkat Selection Method <span class="required-mark">*</span></label>
                <select id="failSelectionMethod" name="selection_method" required>
                    <option value="Party Agreement">Agreement by the Parties</option>
                    <option value="PB Assignment">Punong Barangay Assignment (Parties Failed to Agree)</option>
                    <option value="Raffle Draw">Raffle / Drawing of Lots</option>
                </select>
                <small style="color: #64748b; font-size: 0.78rem;">Records how the 3 Pangkat members were chosen per Sec. 404, LGC.</small>
            </div>

            <div class="form-group">
                <label for="failSelectionNotes">Selection Notes / Remarks</label>
                <textarea id="failSelectionNotes" name="selection_notes" rows="2" placeholder="e.g. Parties could not agree on 3rd member; Punong Barangay drew from remaining Lupon list"></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('failMediationModal')">Cancel</button>
                <button type="submit" class="btn-danger" style="background: #dc2626; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer;">Declare Failed &amp; Generate KP Form 10</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================== -->
<!-- MODAL: Case Transfer Package (Section L)                 -->
<!-- ======================================================== -->
<div id="transferPackageModal" class="modal">
    <div class="modal-content modal-content-lg">
        <div class="modal-header">
            <h2>Pangkat Case Transfer Package (Section L)</h2>
            <button type="button" class="close-btn" onclick="closeModal('transferPackageModal')">&times;</button>
        </div>
        <div id="transferPackageContent" style="padding: 10px 0;"></div>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="closeModal('transferPackageModal')">Close</button>
        </div>
    </div>
</div>

<script>
window.AGAP_HEARINGS = Object.freeze({
    canManage: <?php echo $canManageHearings ? 'true' : 'false'; ?>
});
</script>
<script src="../../assets/js/hearings.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/hearings.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>

