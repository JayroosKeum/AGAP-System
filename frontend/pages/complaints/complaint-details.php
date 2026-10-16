<?php

session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array((int)$_SESSION['role_id'], [1, 2, 3, 4], true)
) {
    die('Access Denied');
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: complaint-list.php');
    exit;
}

$complaintId = (int)$_GET['id'];
$complaintFlash = $_SESSION['complaint_flash'] ?? null;
unset($_SESSION['complaint_flash']);

include '../../layouts/header.php';

?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<link rel="stylesheet" href="../../assets/css/case-details-modern.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/case-details-modern.css'); ?>">

<div class="dashboard-layout">

    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content case-details-main">

        <?php include '../../layouts/navbar.php'; ?>

        <!-- Modern Action Bar / Navigation Header (Picture 1) -->
        <div class="case-details-action-bar">
            <div class="case-bar-left">
                <a href="complaint-list.php" class="back-link">
                    <svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span>Back to Complaints</span>
                </a>
                <div class="case-title-badge-group">
                    <h1 id="complaintNumber" class="case-main-heading">Case #—</h1>
                    <span id="complaintStatusBadge" class="case-stage-pill status-mediation">Loading...</span>
                </div>
            </div>

            <div class="case-bar-right">
                <button type="button" class="btn-case-outline" id="issueSummonButton" onclick="handleIssueSummonClick()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    <span id="issueSummonButtonText">Issue Summon 1st Attempt</span>
                </button>
                <button type="button" class="btn-case-status" id="statusTrackerButton" onclick="toggleStatusTracker()" title="Toggle case progression tracking">
                    <span id="statusDotIndicator" class="status-dot-indicator"></span>
                    <span id="statusTrackerButtonLabel">Status: In Progress</span>
                    <span id="statusToggleArrow" class="dropdown-arrow">▼</span>
                </button>
                <a id="editComplaintBtn" href="complaint-edit.php?id=<?php echo $complaintId; ?>" class="btn-case-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    <span>Edit Case</span>
                </a>
            </div>
        </div>

        <?php if ($complaintFlash && !empty($complaintFlash['message'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    window.agapNotify && window.agapNotify(
                        <?php echo json_encode($complaintFlash['message']); ?>,
                        <?php echo json_encode(($complaintFlash['type'] ?? '') === 'success' ? 'success' : 'error'); ?>
                    );
                });
            </script>
        <?php endif; ?>

        <!-- Horizontal Tabs Navigation Bar -->
        <nav class="case-tabs-nav" aria-label="Case Sections">
            <button type="button" class="case-tab-item active" data-tab="tab-overview" onclick="switchCaseTab('tab-overview')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                <span>Overview &amp; Workflow</span>
            </button>
            <button type="button" class="case-tab-item" data-tab="tab-mediation" onclick="switchCaseTab('tab-mediation')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Mediation</span>
            </button>
            <button type="button" class="case-tab-item" data-tab="tab-conciliation" onclick="switchCaseTab('tab-conciliation')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v1"></path><path d="M18 8h4a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-4"></path><circle cx="8" cy="12" r="2"></circle><circle cx="18" cy="13" r="1.5"></circle></svg>
                <span>Conciliation</span>
            </button>
            <button type="button" class="case-tab-item" data-tab="tab-arbitration" onclick="switchCaseTab('tab-arbitration')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"></path><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                <span>Arbitration / CFA</span>
            </button>
        </nav>

        <!-- Collapsible Case Status / Progression Section -->
        <div id="statusTrackerSection" class="status-tracker-section" style="display: none; margin: 0 30px 24px;">
            <div class="status-tracker-card">
                <div class="status-tracker-card-header">
                    <div class="status-tracker-header-left">
                        <div class="status-tracker-title-wrap">
                            <span class="status-tracker-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            </span>
                            <h2>Complaint Status &amp; Progression</h2>
                        </div>
                        <span id="trackerCurrentStageBadge" class="status-pill status-filed">Loading...</span>
                    </div>
                    <button type="button" class="btn-collapse-toggle" onclick="toggleStatusTracker()" title="Collapse case status section" aria-label="Collapse case status section">
                        <span id="trackerCollapseIcon">▲</span>
                    </button>
                </div>

                <div class="status-tracker-body">
                    <!-- Horizontal Progress Tracker Stepper -->
                    <div class="status-stepper-scroll">
                        <div id="statusStepperTrack" class="status-stepper-track">
                            <div class="stepper-loading">Loading case progression…</div>
                        </div>
                    </div>

                    <!-- Current Stage Context Banner -->
                    <div id="statusStageHighlights" class="status-stage-highlights" style="display:none;">
                        <div class="highlight-info-box">
                            <div class="highlight-icon" id="highlightIcon">ℹ</div>
                            <div class="highlight-text">
                                <strong id="highlightStageName">Current Stage: —</strong>
                                <p id="highlightStageDetails" style="margin: 2px 0 0; color: #475569; font-size: 0.88rem;">—</p>
                            </div>
                        </div>
                    </div>

                    <!-- Expandable Case Event History -->
                    <div class="status-history-drawer">
                        <button type="button" class="btn-history-toggle" onclick="toggleCaseHistoryDrawer()">
                            <span id="historyDrawerIcon">▶</span>
                            <span>View Recorded Events &amp; Case History (<span id="historyEventCount">0</span>)</span>
                        </button>
                        <div id="caseHistoryContent" class="case-history-content" style="display: none;">
                            <div id="caseHistoryTimeline" class="case-history-timeline">
                                <p style="color:#64748b; font-size:0.85rem; margin:0;">Loading case events…</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================================
             TAB PANE 1: OVERVIEW & WORKFLOW (Default Active Tab)
             =================================================================== -->
        <div id="tab-overview" class="case-tab-pane active">

            <!-- Top Hero Section: Primary Case Info & Immediate Context -->
            <div class="case-hero-card">
                <div class="hero-badges-row">
                    <span id="heroCaseTypeBadge" class="hero-type-pill">CRIMINAL COMPLAINT</span>
                    <span id="heroDaysRemainingBadge" class="hero-days-pill">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span id="heroDaysRemainingText">26 days remaining</span>
                    </span>
                </div>
                <h2 id="complaintTitle" class="hero-case-title">Loading case title...</h2>
                <div class="hero-meta-columns">
                    <div class="hero-meta-item">
                        <span class="m-label">Category</span>
                        <span id="infoCategory" class="m-value">—</span>
                    </div>
                    <div class="hero-meta-item">
                        <span class="m-label">Incident Date</span>
                        <span id="infoIncidentDate" class="m-value">—</span>
                    </div>
                    <div class="hero-meta-item">
                        <span class="m-label">Date Filed</span>
                        <span id="infoDateFiled" class="m-value">—</span>
                    </div>
                    <div class="hero-meta-item">
                        <span class="m-label">Administrative Status</span>
                        <span id="infoStatus" class="m-value status-blue">Mediation Stage</span>
                    </div>
                </div>
            </div>

            <!-- Side-by-Side Context Grid: Involved Parties & Incident Location -->
            <div class="case-context-grid">
                <!-- LEFT COLUMN: Involved Parties Card -->
                <div class="context-box-card">
                    <div class="context-box-header">
                        <h3>INVOLVED PARTIES (<span id="partiesCount">0</span>)</h3>
                        <span class="context-subtitle">Complainants &amp; Respondents</span>
                    </div>
                    <div id="partiesListContainer" class="parties-list-wrap">
                        <div class="empty-detail-state">Loading parties...</div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Incident Location Card -->
                <div class="context-box-card">
                    <div class="context-box-header">
                        <h3>INCIDENT LOCATION</h3>
                        <span class="context-subtitle">Pinned GPS</span>
                    </div>
                    <div class="location-summary-rows">
                        <div class="loc-data-row">
                            <strong>Specific Location:</strong> <span id="locationAddressText">—</span>
                        </div>
                        <div class="loc-data-row">
                            <strong>Landmark &amp; Coordinates:</strong> <span id="locationLandmarkText">None</span> | <span id="locationCoordinatesText">—</span>
                        </div>
                        <div class="loc-data-row" id="locationDetailsRow" style="display:none;">
                            <strong>Location Details:</strong> <span id="locationDetailsText">—</span>
                        </div>
                    </div>
                    <div id="complaintDetailMap" class="context-map-frame" aria-label="Incident Location Map"></div>
                    <div id="emptyMapPlaceholder" class="empty-map-state-frame" style="display:none;">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        <p style="margin: 6px 0 2px; font-weight: 600; font-size: 0.88rem;">No exact GPS coordinates pinned</p>
                        <span style="font-size: 0.78rem; color: #94a3b8;">Coordinates can be set anytime via Edit Case</span>
                    </div>
                </div>
            </div>

            <!-- Full Narrative & Facts Card (Moved from Parties & Narratives) -->
            <div class="narrative-card-box">
                <div class="narrative-card-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <div>
                        <h3 style="margin: 0;">Full Narrative &amp; Facts</h3>
                        <span class="card-subtitle" style="font-size: 0.8rem; color: #64748b; font-weight: 500;">Complete statement of complaint, narrative, and relief sought</span>
                    </div>
                </div>
                <div class="narrative-text-container">
                    <span style="font-size: 0.78rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 6px;">Statement of Complaint</span>
                    <div id="complaintNarrative">Loading narrative statement...</div>
                    <div id="complaintAdditionalDetailsWrap" style="display:none; margin-top: 14px; padding-top: 12px; border-top: 1px dashed #cbd5e1;">
                        <strong style="font-size: 0.82rem; color: #64748b; text-transform: uppercase;">Additional Facts / Prior Attempts:</strong>
                        <p id="complaintAdditionalDetails" style="margin: 4px 0 0; font-size: 0.9rem; color: #1e293b;"></p>
                    </div>
                    <div id="complaintRequestedResolutionWrap" style="display:none; margin-top: 14px; padding-top: 12px; border-top: 1px dashed #cbd5e1;">
                        <strong style="font-size: 0.82rem; color: #64748b; text-transform: uppercase;">Requested Resolution / Relief:</strong>
                        <p id="complaintRequestedResolution" style="margin: 4px 0 0; font-size: 0.9rem; color: #1e293b;"></p>
                    </div>
                </div>
            </div>

            <!-- Operational Modules: Case Team & Hearings Schedule Grid -->
            <div class="operational-two-col">
                <!-- Card: Case Team Assignment -->
                <div class="operational-card">
                    <div class="operational-card-header">
                        <div>
                            <h3>Case Team Assignment</h3>
                            <span class="card-subtitle">Assigned Lupon officers for this case</span>
                        </div>
                        <a href="../cases/case-list.php#caseAssignments" id="cwManageTeamLink" class="btn-small-link">
                            Manage Team &rarr;
                        </a>
                    </div>
                    <div id="cwTeamContainer" class="parties-list-wrap">
                        <div class="empty-detail-state">Loading assigned case team...</div>
                    </div>
                </div>

                <!-- Card: Hearings & Deadlines Schedule -->
                <div class="operational-card">
                    <div class="operational-card-header">
                        <div>
                            <h3>Hearings &amp; Deadlines Schedule</h3>
                            <span class="card-subtitle">Mediation, conciliation, and session records</span>
                        </div>
                        <a id="cwOpenHearingsLink" href="../hearings/schedules.php" class="btn-small-link">
                            Open Schedule &rarr;
                        </a>
                    </div>
                    <div id="cwHearingsContainer">
                        <div class="empty-detail-state">Loading scheduled hearings...</div>
                    </div>
                </div>
            </div>

            <!-- OFFICIAL KP FORMS Card -->
            <div class="kp-forms-card-box">
                <div class="kp-forms-header">
                    <div>
                        <h3 style="margin: 0;">OFFICIAL KP FORMS</h3>
                        <span class="card-subtitle" style="font-size: 0.82rem; color: #64748b; margin-top: 2px; display: block;">Issued Katarungang Pambarangay forms and legal notices</span>
                    </div>
                    <span id="heroKpDocCountBadge" class="badge-issued-chip">0 Issued</span>
                </div>
                <div id="heroKpFormsList" class="kp-forms-list-container">
                    <div class="empty-detail-state">Loading issued KP forms...</div>
                </div>
            </div>

        </div>

        <!-- ===================================================================
             TAB PANE 2: MEDIATION
             =================================================================== -->
        <div id="tab-mediation" class="case-tab-pane">
            <!-- Punong Barangay Mediation Stage Card / Banner -->
            <div style="margin: 0 30px 20px;">
                <div id="stage1Card" class="stage-card-item active">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 1 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage1Icon" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,0.25); font-size: 0.75rem;">✓</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Punong Barangay Mediation</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage1StatusText" class="stage-sub-status" style="font-weight: 600;">In Progress</span>
                        <span style="font-size: 0.78rem; opacity: 0.9;">Presiding: Punong Barangay &bull; 15-day statutory conciliation (RA 7160 Sec. 410b)</span>
                    </div>
                </div>
            </div>

            <!-- Session #1 Schedule & Attendance Card -->
            <div class="session-schedule-box" style="margin: 0 30px 24px;">
                <div class="session-box-header">
                    <div>
                        <h4 id="sessionTitleHeading">Session #1 Schedule &amp; Attendance</h4>
                        <p id="sessionSubtitleHeading" class="session-lead-text">Next Session: Oct 19, 2026 at 10:00 AM (Barangay Hall)</p>
                    </div>
                    <a id="cwManageScheduleLink" href="../hearings/schedules.php" class="btn-manage-sched">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span>Manage Schedule</span>
                    </a>
                </div>

                <!-- 3 Metrics KPI Grid -->
                <div class="session-kpi-grid">
                    <div class="session-kpi-item">
                        <span class="kpi-label">Complainant Attendance</span>
                        <div class="kpi-val-row">
                            <span class="dot-status-green"></span>
                            <span id="complainantAttendanceText">Present (0), Absent (0)</span>
                        </div>
                    </div>
                    <div class="session-kpi-item">
                        <span class="kpi-label">Respondent Attendance</span>
                        <div class="kpi-val-row">
                            <span class="dot-status-green"></span>
                            <span id="respondentAttendanceText">Present (0), Absent (0)</span>
                        </div>
                    </div>
                    <div class="session-kpi-item">
                        <span class="kpi-label">Saved Minutes</span>
                        <div class="kpi-val-row">
                            <span id="savedMinutesIcon">⚠️</span>
                            <span id="savedMinutesText">Not Recorded Yet</span>
                        </div>
                    </div>
                </div>

                <!-- Bottom Action Buttons Row -->
                <div class="session-action-bar-row">
                    <div class="session-btn-group-left">
                        <button type="button" id="previewKp9Btn" class="btn-sess-indigo" onclick="handlePreviewKp9()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="10 8 16 12 10 16 10 8"></polygon></svg>
                            <span>Preview KP Form 9</span>
                        </button>
                        <a id="recordAttendanceBtn" href="#" class="btn-sess-white">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>
                            <span>Record Attendance</span>
                        </a>
                        <a id="addMinutesBtn" href="#" class="btn-sess-white">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                            <span>Add Minutes</span>
                        </a>
                    </div>
                    <div class="session-btn-group-right">
                        <a id="recordOutcomeBtn" href="#" class="btn-sess-green">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            <span>Record Outcome</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Mediation Hearings & Workspace Navigation Card -->
            <div class="hearings-schedule-card">
                <div class="operational-card-header">
                    <div>
                        <h3 style="font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0;">Mediation Proceedings &amp; Workspace</h3>
                        <span class="card-subtitle">Detailed session records, show-cause notices, and mediation history</span>
                    </div>
                    <span id="cwMediationStatusBadge" class="status-pill status-mediation" style="font-size:0.75rem;">In Progress</span>
                </div>
                <div id="cwMediationContainer" style="margin-top: 10px;">
                    <div class="empty-detail-state">Loading mediation proceedings...</div>
                </div>
                <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 0.82rem; color: #64748b;">
                        Under RA 7160 Sec. 410(b), mediation is conducted by the Punong Barangay for up to 3 sessions or 15 days before Pangkat referral.
                    </span>
                    <a id="cwManageMediationLink" href="#" class="btn-case-outline" style="font-size: 0.84rem; padding: 6px 14px;">
                        Open Mediation Workspace &rarr;
                    </a>
                </div>
            </div>
        </div>

        <!-- ===================================================================
             TAB PANE 3: CONCILIATION
             =================================================================== -->
        <div id="tab-conciliation" class="case-tab-pane">
            <!-- Pangkat Conciliation Stage Card / Banner -->
            <div style="margin: 0 30px 20px;">
                <div id="stage2Card" class="stage-card-item locked">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 2 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage2Icon" style="display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem;">🔒</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Pangkat Conciliation</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage2StatusText" class="stage-sub-status" style="font-weight: 600;">Locked until mediation concludes</span>
                        <span style="font-size: 0.78rem; opacity: 0.9;">Pangkat ng Tagapagkasundo &bull; 3-member Lupon panel (RA 7160 Sec. 410c)</span>
                    </div>
                </div>
            </div>

            <!-- Card: Pangkat Conciliation State -->
            <div class="hearings-schedule-card">
                <div class="operational-card-header">
                    <div>
                        <h3 style="font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0;">Pangkat Conciliation Workspace &amp; State</h3>
                        <span class="card-subtitle">Pangkat ng Tagapagkasundo &bull; Formal conciliation proceedings</span>
                    </div>
                    <span id="cwConciliationStatusBadge" class="status-pill status-locked" style="font-size:0.75rem;">Locked</span>
                </div>
                <div id="cwConciliationContainer" style="margin-top: 14px;">
                    <div class="empty-detail-state">Loading conciliation state...</div>
                </div>
                <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 0.82rem; color: #64748b;">
                        Pangkat is constituted from Lupon members chosen by the parties (KP Form 10/11) to conduct formal conciliation within 15 days.
                    </span>
                    <a id="cwManageConciliationLink" href="#" class="btn-case-outline" style="font-size: 0.84rem; padding: 6px 14px;">
                        Manage Conciliation &rarr;
                    </a>
                </div>
            </div>

            <!-- Pangkat Conciliation Guidelines Card -->
            <div class="operational-card" style="margin: 0 30px 24px;">
                <div class="operational-card-header">
                    <div>
                        <h3 style="font-size: 0.95rem; font-weight: 800; color: #0f172a; margin: 0;">Conciliation Legal Guidelines (RA 7160 Sec. 410)</h3>
                        <span class="card-subtitle">Procedural gates and requirements for Pangkat conciliation</span>
                    </div>
                </div>
                <div style="padding: 16px 20px; font-size: 0.86rem; color: #475569; line-height: 1.6;">
                    <ul style="margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 8px;">
                        <li><strong>Pangkat Constitution:</strong> The parties choose 3 members from the Lupon. If they fail to agree, the Punong Barangay draws lots.</li>
                        <li><strong>15-Day Resolution Period:</strong> The Pangkat shall meet within 3 days from constitution and has fifteen (15) days to conciliate the dispute, extendable by another 15 days in clearly meritorious cases.</li>
                        <li><strong>Outcomes:</strong> A successful session produces an <em>Amicable Settlement</em> (KP Form 16). If conciliation fails, a <em>Certificate to File Action</em> (KP Form 20) is issued.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ===================================================================
             TAB PANE 4: ARBITRATION / CFA
             =================================================================== -->
        <div id="tab-arbitration" class="case-tab-pane">
            <!-- Arbitration / Court Referral Stage Card / Banner -->
            <div style="margin: 0 30px 20px;">
                <div id="stage3Card" class="stage-card-item pending">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 3 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage3Icon" style="display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem;">🔒</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Arbitration / Court Referral</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage3StatusText" class="stage-sub-status" style="font-weight: 600;">Pending</span>
                        <span style="font-size: 0.78rem; opacity: 0.9;">Binding Arbitration (RA 7160 Sec. 413) &bull; Certification to File Action (CFA)</span>
                    </div>
                </div>
            </div>

            <!-- Card: Arbitration / Court Referral State -->
            <div class="hearings-schedule-card">
                <div class="operational-card-header">
                    <div>
                        <h3 style="font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0;">Arbitration / Court Referral Workspace</h3>
                        <span class="card-subtitle">Voluntary binding arbitration proceedings or formal elevation to court</span>
                    </div>
                    <span id="cwArbitrationStatusBadge" class="status-pill status-locked" style="font-size:0.75rem;">Pending</span>
                </div>
                <div id="cwArbitrationContainer" style="margin-top: 14px;">
                    <div class="empty-detail-state">Loading arbitration details...</div>
                </div>
                <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 0.82rem; color: #64748b;">
                        Under Sec. 413 of RA 7160, parties may agree in writing to submit their dispute to binding arbitration at any stage of the proceedings.
                    </span>
                    <a id="cwManageArbitrationLink" href="#" class="btn-case-outline" style="font-size: 0.84rem; padding: 6px 14px;">
                        Open Arbitration Workspace &rarr;
                    </a>
                </div>
            </div>

            <!-- Statutory Pathways: Arbitration vs Court Referral Card -->
            <div class="operational-two-col" style="margin: 0 30px 24px;">
                <div class="operational-card">
                    <div class="operational-card-header">
                        <div>
                            <h3 style="font-size: 0.92rem; font-weight: 800; color: #0f172a; margin: 0;">Binding Arbitration (Sec. 413)</h3>
                            <span class="card-subtitle">Voluntary submission by written agreement</span>
                        </div>
                    </div>
                    <div style="padding: 16px 20px; font-size: 0.84rem; color: #475569; line-height: 1.55;">
                        <p style="margin: 0 0 8px;">Parties may at any stage agree in writing (<strong>KP Form 14</strong>) that the Punong Barangay or Pangkat shall arbitrate the dispute.</p>
                        <p style="margin: 0 0 8px;">An agreement to arbitrate may be repudiated within five (5) days from the date thereof on valid legal grounds.</p>
                        <p style="margin: 0;">The arbitration award (<strong>KP Form 15</strong>) is binding and has the force and effect of a final judgment of a court after 10 days.</p>
                    </div>
                </div>

                <div class="operational-card">
                    <div class="operational-card-header">
                        <div>
                            <h3 style="font-size: 0.92rem; font-weight: 800; color: #0f172a; margin: 0;">Certification to File Action (CFA)</h3>
                            <span class="card-subtitle">Prerequisite condition for court litigation</span>
                        </div>
                    </div>
                    <div style="padding: 16px 20px; font-size: 0.84rem; color: #475569; line-height: 1.55;">
                        <p style="margin: 0 0 8px;">No individual may go directly to court without a valid Certificate to File Action issued under Katarungang Pambarangay rules.</p>
                        <ul style="margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 4px;">
                            <li><strong>KP Form 20:</strong> Issued after confrontation before Punong Barangay and Pangkat fails.</li>
                            <li><strong>KP Form 21:</strong> Issued if Complainant fails/refuses to appear.</li>
                            <li><strong>KP Form 22:</strong> Issued if Respondent fails/refuses to appear.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden legacy compatibility elements (preserves existing JS selectors) -->
        <div id="caseWorkspaceSection" style="display:none;"></div>
        <div id="complaintInfo" style="display:none;"></div>
        <div id="complaintReviewNotes" style="display:none;"></div>
        <table style="display:none;"><tbody id="partiesTable"></tbody></table>
        <table style="display:none;"><tbody id="attachmentsTable"></tbody></table>
        <span id="complaintCaseTypeBadge" style="display:none;">Civil</span>
        <span id="complaintCategoryBadge" style="display:none;">Category</span>
        <span id="infoComplaintTitle" style="display:none;">—</span>
        <span id="infoCaseType" style="display:none;">—</span>
        <span id="infoIncidentTime" style="display:none;">—</span>
        <span id="infoDocketCase" style="display:none;">—</span>
        <span id="progressionActiveStageBadge" style="display:none;">Active Stage</span>
        <span id="cwDocCountBadge" style="display:none;">0 forms</span>
        <span id="tabDocsCountBadge" style="display:none;">0</span>
        <div id="cwDocumentsContainer" style="display:none;"></div>
        <span id="partiesFullCount" style="display:none;">0</span>
        <div id="partiesFullContainer" style="display:none;"></div>
        <span id="tab2LocationAddress" style="display:none;"></span>
        <span id="tab2LocationLandmark" style="display:none;"></span>
        <span id="tab2LocationCoords" style="display:none;"></span>
        <div id="tab2DetailMap" style="display:none;"></div>
        <div id="tab2ComplaintNarrative" style="display:none;"></div>
        <div id="tab2AdditionalDetailsWrap" style="display:none;"><div id="tab2AdditionalDetails"></div></div>
        <span id="attachmentsCount" style="display:none;">0</span>
        <div id="attachmentsListContainer" style="display:none;"></div>
        <div id="evidenceDropzone" style="display:none;"><input type="file" id="evidenceFileInput" style="display:none;"><div id="dropzoneUploadProgress" style="display:none;"></div></div>
        <input type="hidden" id="partyComplaintId" value="<?php echo $complaintId; ?>">
        <input type="hidden" id="attachmentComplaintId" value="<?php echo $complaintId; ?>">

    </div>

</div>

<!-- Issue Summon Modal -->
<div id="issueSummonModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px; max-height: calc(100vh - 40px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden;">
        <div class="modal-header" style="padding: 18px 24px 14px; margin-bottom: 0; border-bottom: 1px solid #f1f5f9; flex-shrink: 0;">
            <h2 id="issueSummonModalTitle" style="font-size: 1.15rem;">Issue Summon 1st Attempt &amp; Schedule Mediation</h2>
            <button type="button" class="close-btn" onclick="closeIssueSummonModal()">&times;</button>
        </div>
        <form id="issueSummonForm" onsubmit="submitIssueSummon(event)" style="display: flex; flex-direction: column; overflow: hidden; margin: 0; flex: 1 1 auto;">
            <div style="overflow-y: auto; padding: 20px 24px; flex: 1 1 auto; max-height: calc(100vh - 180px);">
                <input type="hidden" id="summonComplaintId" name="complaint_id" value="<?php echo $complaintId; ?>">
                <p style="margin: 0 0 16px; font-size: 0.88rem; color: #475569; line-height: 1.5;">
                    In accordance with Katarungang Pambarangay rules, issuing a summons sets the 1st Mediation hearing and automatically generates <strong>KP Form 8 (Notice of Hearing for Complainant)</strong> and <strong>KP Form 9 (Summons for Respondent)</strong> for service.
                </p>
                <div class="form-group" style="margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
                        <label for="summonMediationDate" style="margin-bottom: 0; font-weight: 700; color: #1e293b;">
                            1st Mediation Date <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <span id="summonWindowStatutoryBadge" style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">
                            15-Working-Day Window (RA 7160 Sec. 410)
                        </span>
                    </div>

                    <input type="date" id="summonMediationDate" name="mediation_date" required style="margin-bottom: 8px;">

                    <!-- Visual 15-Working-Day Calendar & Capacity Strip -->
                    <div id="summonVisualDatePickerContainer" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.78rem; flex-wrap: wrap; gap: 6px;">
                            <span style="font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.74rem;">
                                📅 Available Mediation Days (Max 8 Hearings/Day):
                            </span>
                            <div style="display: flex; gap: 10px; font-size: 0.72rem; flex-wrap: wrap;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #166534;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></span> Available</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #991b1b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444;"></span> 8/8 Full</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #64748b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #94a3b8;"></span> Outside 15d</span>
                            </div>
                        </div>

                        <!-- Scrollable Day Cards Grid -->
                        <div id="summonVisualDayGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 6px; max-height: 155px; overflow-y: auto; padding: 4px 2px;">
                            <div style="text-align: center; color: #64748b; font-size: 0.78rem; padding: 12px; grid-column: 1 / -1;">
                                Loading statutory 15-working-day availability &amp; capacity...
                            </div>
                        </div>
                        <div id="summonVisualDateStatusNotice" style="margin-top: 6px; font-size: 0.75rem; color: #64748b; line-height: 1.4;"></div>
                    </div>
                </div>

                <!-- Existing Mediations on selected date -->
                <div id="summonDaySchedulesWrap" style="display: none; margin-bottom: 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.84rem;">
                    <div style="font-weight: 600; color: #334155; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                        <span>📅 Scheduled Mediations on this Date:</span>
                        <span id="summonDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>
                    </div>
                    <div id="summonDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">
                        No other mediations scheduled on this day.
                    </div>
                </div>

                <div class="form-group">
                    <label for="summonMediationTime">1st Mediation Time <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="time" id="summonMediationTime" name="mediation_time" value="09:00" required>
                    <small class="field-hint">Standard mediation session is 1 hour (60 minutes). Scheduled times cannot overlap.</small>
                </div>

                <!-- Conflict Alert Box -->
                <div id="summonScheduleConflictAlert" class="alert alert-danger" style="display: none; margin: 0 0 14px; padding: 10px 12px; font-size: 0.84rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; line-height: 1.4;">
                </div>

                <div class="form-group">
                    <label for="summonMediationVenue">Venue <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="text" id="summonMediationVenue" name="venue" maxlength="255" value="Barangay Hall" required>
                </div>
                <div class="form-group" style="margin-bottom: 6px;">
                    <label for="summonMediationRemarks">Remarks <span class="optional-label">Optional</span></label>
                    <textarea id="summonMediationRemarks" name="remarks" maxlength="5000" rows="2" placeholder="Optional mediation instructions or notes..."></textarea>
                </div>
            </div>
            <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; flex-shrink: 0; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closeIssueSummonModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitIssueSummonBtn">Issue Summon &amp; Schedule</button>
            </div>
            <p id="issueSummonMessage" role="status" style="margin: 0; padding: 0 24px 8px; font-size: 0.84rem;"></p>
        </form>
    </div>
</div>

<!-- Session & Process Details Modal (Mediation / Conciliation Details) -->
<div id="sessionProcessDetailsModal" class="modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="spModalTitle">
    <div class="modal-content" style="max-width: 840px; max-height: calc(100vh - 40px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
        <div class="modal-header" style="padding: 18px 24px 14px; margin-bottom: 0; border-bottom: 1px solid #f1f5f9; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; background: #ffffff;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <h2 id="spModalTitle" style="font-size: 1.2rem; margin: 0; color: #0f172a; font-weight: 800;">Session Details</h2>
                    <span id="spModalStatusBadge" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;"></span>
                    <span id="spModalStageBadge" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;"></span>
                </div>
                <div id="spModalSubtitle" style="font-size: 0.82rem; color: #64748b; margin-top: 4px;"></div>
            </div>
            <button type="button" class="close-btn" onclick="closeSessionProcessDetailsModal()" aria-label="Close modal" style="font-size: 1.5rem; border: none; background: transparent; cursor: pointer; color: #64748b; line-height: 1; padding: 4px 8px; border-radius: 6px;">&times;</button>
        </div>
        <div id="spModalBody" style="overflow-y: auto; padding: 20px 24px; flex: 1 1 auto; max-height: calc(100vh - 160px); display: flex; flex-direction: column; gap: 16px; background: #f8fafc;">
            <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                <p style="margin: 0;">Loading session and process details...</p>
            </div>
        </div>
        <div class="modal-actions" style="padding: 14px 24px; border-top: 1px solid #e2e8f0; background: #ffffff; margin-top: 0; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div id="spModalLeftActions" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn-secondary" onclick="closeSessionProcessDetailsModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Digital KP Form 7 Preview & Print -->
<div id="kp7DigitalPreviewModal" class="modal" style="display:none; padding: 12px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;" role="dialog" aria-modal="true" aria-labelledby="kp7ModalHeading">
    <div class="modal-content" style="max-width: 1350px; width: 96vw; height: calc(100vh - 24px); max-height: calc(100vh - 24px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 35px -5px rgba(0, 0, 0, 0.35); background: #ffffff;">
        <div class="modal-header" style="padding: 14px 24px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.3rem;">⚖️</span>
                <div>
                    <h2 id="kp7ModalHeading" style="font-size: 1.15rem; margin: 0; color: #0f172a; font-weight: 700;">Pormularyo ng KP Blg. 7: Reklamo (Formal Complaint)</h2>
                    <p style="margin: 2px 0 0; font-size: 0.78rem; color: #64748b;">Barangay Tumana Lupong Tagapamayapa Official Katarungang Pambarangay Form</p>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeKp7DigitalPreviewModal()" aria-label="Close modal">&times;</button>
        </div>
        <div style="flex: 1 1 auto; overflow: hidden; background: #334155; min-height: 0; height: 100%; display: flex;">
            <iframe id="kp7PreviewIframe" src="" style="width: 100%; height: 100%; border: none; background: #334155;"></iframe>
        </div>
        <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-shrink: 0;">
            <div style="font-size: 0.82rem; color: #64748b;">
                Official Katarungang Pambarangay Complaint Document
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn-create" onclick="printKp7Iframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; font-weight: 600;">
                    🖨️ Print Form
                </button>
                <button type="button" class="btn-secondary" onclick="closeKp7DigitalPreviewModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Upload / Replace Handwritten KP Form 7 Scan -->
<div id="kp7UploadScanModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="uploadScanModalTitle">
    <div class="modal-content" style="max-width: 520px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.3rem;">📤</span>
                <h2 id="uploadScanModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Upload Handwritten KP Form 7</h2>
            </div>
            <button type="button" class="close-btn" onclick="closeUploadScanModal()">&times;</button>
        </div>
        <form id="uploadScanForm" onsubmit="submitScanUpload(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <p style="font-size: 0.88rem; color: #475569; margin: 0 0 16px; line-height: 1.5;">
                Upload a scanned copy or clear photo of the physical handwritten KP Form 7. Accepted file formats: <strong>PDF, JPG, JPEG, PNG</strong> (max 25 MB).
            </p>
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="scanFileInput" style="font-weight: 600; font-size: 0.88rem;">Select Document File <span class="required-mark">*</span></label>
                <input type="file" id="scanFileInput" name="scan_file" accept=".pdf,image/jpeg,image/png" required style="padding: 8px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                <div id="scanFileDetails" style="display:none; font-size: 0.8rem; color: #059669; margin-top: 6px; font-weight: 600;"></div>
            </div>
            <div class="form-group" style="margin-bottom: 18px;">
                <label for="scanReplacementReason" style="font-weight: 600; font-size: 0.88rem;">Reason for Upload / Replacement <span class="optional-label">Optional</span></label>
                <input type="text" id="scanReplacementReason" name="replacement_reason" placeholder="e.g. Initial intake scan, corrected copy, signed document" style="padding: 8px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>
            <div id="scanUploadError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>
            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeUploadScanModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitScanUpload">Upload Document</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: KP Form 7 Scan Version History -->
<div id="kp7ScanHistoryModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
    <div class="modal-content" style="max-width: 680px; max-height: calc(100vh - 60px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.3rem;">📜</span>
                <h2 id="historyModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">KP Form 7 Scan Version History</h2>
            </div>
            <button type="button" class="close-btn" onclick="closeScanHistoryModal()">&times;</button>
        </div>
        <div id="scanHistoryBody" style="overflow-y: auto; padding: 20px 24px; flex: 1 1 auto; max-height: 60vh;">
            <div style="text-align: center; color: #64748b; padding: 20px;">Loading version history...</div>
        </div>
        <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: flex-end;">
            <button type="button" class="btn-secondary" onclick="closeScanHistoryModal()">Close</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../layouts/summons-preview-modal.php'; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/summons-preview.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/summons-preview.js'); ?>"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
