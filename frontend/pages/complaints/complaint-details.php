<?php

session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array((int)$_SESSION['role_id'], [1, 2, 3], true)
) {
    if ((int)($_SESSION['role_id'] ?? 0) === 4) {
        header('Location: ../gps/proof-service.php');
        exit;
    }
    http_response_code(403);
    die('Access Denied');
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$canAuthorizePastDate = $roleId === 1;

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: complaint-list.php');
    exit;
}

$complaintId = (int)$_GET['id'];
$rawTab = trim((string)($_GET['tab'] ?? 'tab-overview'));
if (!str_starts_with($rawTab, 'tab-')) {
    $rawTab = 'tab-' . $rawTab;
}
$validTabs = ['tab-overview', 'tab-paanyaya', 'tab-mediation', 'tab-conciliation', 'tab-arbitration'];
$activeTab = in_array($rawTab, $validTabs, true) ? $rawTab : 'tab-overview';
$complaintFlash = $_SESSION['complaint_flash'] ?? null;
unset($_SESSION['complaint_flash']);

include '../../layouts/header.php';
require_once __DIR__ . '/../../../backend/models/Setting.php';
require_once __DIR__ . '/../../../backend/models/Paanyaya.php';
$paanyayaModel = new Paanyaya();
$servingOfficers = $paanyayaModel->getServingOfficers();
$eligiblePresiders = $paanyayaModel->getEligiblePresiders();
$maxPaanyayaSetting = (int) Setting::getSetting('paanyaya_max_attempts', 3);
?>
<script>
window.AGAP_SETTINGS = window.AGAP_SETTINGS || {};
window.AGAP_SETTINGS.paanyaya_max_attempts = <?php echo $maxPaanyayaSetting; ?>;
</script>
<?php

?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<link rel="stylesheet" href="../../assets/css/case-details-modern.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/case-details-modern.css'); ?>">
<link rel="stylesheet" href="../../assets/css/hearings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/hearings.css'); ?>">

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
                <!-- Reopen for CFA button: shown only when case is Settled (via JS class toggle) -->
                <button type="button" class="btn-case-outline" id="btnReopenForCfa"
                    onclick="openReopenCfaModal()"
                    title="Reopen case for CFA issuance due to non-compliance (RA 7160 Sec. 417)"
                    style="display:none; border-color:#dc2626; color:#dc2626;"
                >
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>Reopen for CFA</span>
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
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-overview' ? 'active' : ''; ?>" data-tab="tab-overview" onclick="switchCaseTab('tab-overview')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon></svg>
                <span>Overview &amp; Workflow</span>
            </button>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-paanyaya' ? 'active' : ''; ?>" data-tab="tab-paanyaya" onclick="switchCaseTab('tab-paanyaya')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                <span>Paanyaya</span>
            </button>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-mediation' ? 'active' : ''; ?>" data-tab="tab-mediation" onclick="switchCaseTab('tab-mediation')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Mediation</span>
                <span class="case-tab-lock-icon" id="tabLockMediation" style="display: none;" title="Locked stage">🔒</span>
            </button>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-conciliation' ? 'active' : ''; ?>" data-tab="tab-conciliation" onclick="switchCaseTab('tab-conciliation')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v1"></path><path d="M18 8h4a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-4"></path><circle cx="8" cy="12" r="2"></circle><circle cx="18" cy="13" r="1.5"></circle></svg>
                <span>Conciliation</span>
                <span class="case-tab-lock-icon" id="tabLockConciliation" style="display: none;" title="Locked stage">🔒</span>
            </button>
            <button type="button" class="case-tab-item <?php echo $activeTab === 'tab-arbitration' ? 'active' : ''; ?>" data-tab="tab-arbitration" id="caseTabItemOutcomes" onclick="switchCaseTab('tab-arbitration')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"></path><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                <span id="tabOutcomesLabel">Outcomes</span>
                <span class="case-tab-lock-icon" id="tabLockArbitration" style="display: none;" title="Locked stage">🔒</span>
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
        <div id="tab-overview" class="case-tab-pane <?php echo $activeTab === 'tab-overview' ? 'active' : ''; ?>">

            <!-- Celebratory Statutory Countdown Completion Alert Banner -->
            <div id="statutoryTransmittalAlertBanner" style="display: none; margin-bottom: 16px; background: #ecfdf5; border: 1px solid #6ee7b7; border-left: 5px solid #10b981; border-radius: 8px; padding: 14px 18px; color: #065f46; font-size: 0.9rem; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.4rem; line-height: 1;">ℹ️</span>
                    <div>
                        <strong style="display: block; font-size: 0.95rem; color: #064e3b; margin-bottom: 2px;">
                            10-Business-Day Statutory Period Completed: Court Transmittal Letter is now ready to print.
                        </strong>
                        <span style="font-size: 0.82rem; color: #047857;">
                            The mandatory repudiation window under RA 7160 Sec. 416 has elapsed. You may now print and transmit the official endorsement to the Metropolitan Trial Court (MTCC Marikina City).
                        </span>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; flex-shrink: 0;">
                    <button type="button" class="btn-create" onclick="openPrintTransmittalLetter()" style="background: #059669; font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; color: #fff;">
                        🖨️ Print Transmittal Letter
                    </button>
                </div>
            </div>">

            <!-- Top Hero Section: Primary Case Info & Immediate Context -->
            <div class="case-hero-card">
                <div class="hero-badges-row">
                    <span id="heroCaseTypeBadge" class="hero-type-pill">CRIMINAL COMPLAINT</span>
                    <span id="heroDaysRemainingBadge" class="hero-days-pill" style="display: none;">
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
                        <a id="cwOpenHearingsLink" href="#" class="btn-small-link" style="display: none;">
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
             TAB PANE: PAANYAYA (STAGE 0: PRE-DOCKETING)
             =================================================================== -->
        <div id="tab-paanyaya" class="case-tab-pane <?php echo $activeTab === 'tab-paanyaya' ? 'active' : ''; ?>">

            <!-- TWO PAANYAYA STAGES: SIDE BY SIDE SUMMARY CARDS -->
            <div class="paanyaya-stages-grid" style="margin-top: 6px;">
                <!-- CARD 1: PAANYAYA 1 (First Preliminary Invitation) -->
                <div class="paanyaya-card" id="paanyaya1Card">
                    <div>
                        <div class="paanyaya-card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div class="paanyaya-stage-title-wrap">
                                <h3 class="paanyaya-card-title">Paanyaya 1 &ndash; Preliminary Invitation</h3>
                            </div>
                            <span id="p1StatusBadge" class="paanyaya-stage-tag" style="display: none;"></span>
                        </div>

                        <!-- Schedule & Officer Meta -->
                        <div id="p1ScheduleMeta" style="display: none;"></div>

                        <!-- Inline Dialogue Minutes & Settlement Result (Paanyaya 1) -->
                        <div id="p1DialogueMinutesInline" class="paanyaya-minutes-inline-container" style="margin-top: 10px;">
                            <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; padding: 18px; text-align: center;">
                                <p style="margin: 0 0 6px 0; font-size: 0.88rem; color: #475569; font-weight: 600;">
                                    Dialogue Minutes not yet recorded.
                                </p>
                                <span style="font-size: 0.8rem; color: #64748b;">
                                    Click "Record Dialogue Minutes" below to proceed.
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="paanyaya-card-actions">
                        <button type="button" class="btn-create" id="btnIssueP1" onclick="openIssuePaanyayaModal('Paanyaya 1')">
                            Issue Paanyaya 1
                        </button>
                        <button type="button" class="btn-create" id="btnRecordReturnP1" onclick="openRecordReturnModal('Paanyaya 1')" style="background: #0284c7; border-color: #0284c7; display: none;">
                            Record Officer's Return
                        </button>
                        <a href="record-dialogue-minutes.php?id=<?= $complaintId ?>&stage=Paanyaya+1" class="btn-create" id="btnRecordMinutesP1" style="background: #2563eb; border-color: #2563eb; text-decoration: none; display: none;">
                            Record Dialogue Minutes
                        </a>
                        <a href="record-dialogue-minutes.php?id=<?= $complaintId ?>&stage=Paanyaya+1" class="btn-secondary" id="btnViewMinutesP1" style="display: none; text-decoration: none;">
                            View Minutes
                        </a>
                        <button type="button" class="btn-sess-indigo" id="btnPreviewP1" onclick="previewPaanyayaStageLetter('Paanyaya 1')" style="display: none;">
                            Preview Letter
                        </button>
                        <button type="button" class="btn-create" id="btnReviewP1" onclick="openPaanyayaReviewModal('Paanyaya 1')" style="background: #dc2626; border-color: #dc2626; display: none;">
                            Review Case
                        </button>
                    </div>
                </div>

                <!-- CARD 2: PAANYAYA 2 (Second Preliminary Invitation) -->
                <div class="paanyaya-card" id="paanyaya2Card">
                    <div>
                        <div class="paanyaya-card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div class="paanyaya-stage-title-wrap">
                                <h3 class="paanyaya-card-title">Paanyaya 2 &ndash; Second Preliminary Invitation</h3>
                            </div>
                            <span id="p2StatusBadge" class="paanyaya-stage-tag" style="display: none;"></span>
                        </div>

                        <!-- Schedule & Officer Meta -->
                        <div id="p2ScheduleMeta" style="display: none;"></div>

                        <!-- Locked State Alert Banner (when Paanyaya 1 is still incomplete) -->
                        <div id="p2LockedNotice" style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 16px; font-size: 0.85rem; color: #64748b; display: flex; align-items: center; gap: 10px;">
                            <span>Paanyaya 2 is locked. It unlocks ONLY if Paanyaya 1 concludes with 'No Agreement' and the complainant requests a second invitation.</span>
                        </div>

                        <!-- Inline Dialogue Minutes & Settlement Result (Paanyaya 2) -->
                        <div id="p2DialogueMinutesInline" class="paanyaya-minutes-inline-container" style="margin-top: 10px; display: none;">
                            <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; padding: 18px; text-align: center;">
                                <p style="margin: 0 0 6px 0; font-size: 0.88rem; color: #475569; font-weight: 600;">
                                    Dialogue Minutes not yet recorded.
                                </p>
                                <span style="font-size: 0.8rem; color: #64748b;">
                                    Click "Record Dialogue Minutes" below to proceed.
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="paanyaya-card-actions">
                        <button type="button" class="btn-create" id="btnIssueP2" onclick="openIssuePaanyayaModal('Paanyaya 2')" disabled style="opacity: 0.55; cursor: not-allowed; display: none;">
                            Issue Paanyaya 2
                        </button>
                        <button type="button" class="btn-create" id="btnRecordReturnP2" onclick="openRecordReturnModal('Paanyaya 2')" style="background: #0284c7; border-color: #0284c7; display: none;">
                            Record Officer's Return
                        </button>
                        <a href="record-dialogue-minutes.php?id=<?= $complaintId ?>&stage=Paanyaya+2" class="btn-create" id="btnRecordMinutesP2" style="background: #2563eb; border-color: #2563eb; text-decoration: none; display: none;">
                            Record Dialogue Minutes
                        </a>
                        <a href="record-dialogue-minutes.php?id=<?= $complaintId ?>&stage=Paanyaya+2" class="btn-secondary" id="btnViewMinutesP2" style="display: none; text-decoration: none;">
                            View Minutes
                        </a>
                        <button type="button" class="btn-sess-indigo" id="btnPreviewP2" onclick="previewPaanyayaStageLetter('Paanyaya 2')" style="display: none;">
                            Preview Letter
                        </button>
                        <button type="button" class="btn-create" id="btnReviewP2" onclick="openPaanyayaReviewModal('Paanyaya 2')" style="background: #dc2626; border-color: #dc2626; display: none;">
                            Review Case
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- ===================================================================
             TAB PANE 2: MEDIATION
             =================================================================== -->
        <div id="tab-mediation" class="case-tab-pane <?php echo $activeTab === 'tab-mediation' ? 'active' : ''; ?>">
            <!-- Punong Barangay Mediation Stage Card / Banner -->
            <div style="margin: 0 30px 20px;">
                <div id="stage1Card" class="stage-card-item active">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 1 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage1Icon" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,0.25); font-size: 0.75rem;">1</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Punong Barangay Mediation</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage1StatusText" class="stage-sub-status" style="font-weight: 600;">In Progress</span>
                        <span style="font-size: 0.78rem; opacity: 0.9;">Presiding: Punong Barangay</span>
                    </div>
                </div>
            </div>

                        <!-- Embedded Mediation Hearing Sessions Management -->
            <div class="stage-section-card" id="mediationSessionsSection" style="margin: 0 30px 24px;">
                <div class="stage-section-header">
                    <div>
                        <h3>Mediation Hearing Sessions</h3>
                        <p>Scheduled sessions, party appearances, service verification, and minutes status</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span id="mediationSessionsCountBadge" style="font-size: 0.78rem; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">0 sessions</span>
                        <button type="button" class="btn-create" onclick="openEmbeddedScheduleModal('Mediation')" style="font-size: 0.78rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 4px;">
                            + Schedule Session
                        </button>
                    </div>
                </div>
                <div id="mediationSessionsListContainer" style="padding: 18px 20px;">
                    <div class="empty-detail-state">Loading mediation hearing sessions...</div>
                </div>
                
            </div>
        </div>

        <!-- ===================================================================
             TAB PANE 3: CONCILIATION
             =================================================================== -->
        <div id="tab-conciliation" class="case-tab-pane <?php echo $activeTab === 'tab-conciliation' ? 'active' : ''; ?>">
            <!-- Pangkat Conciliation Stage Card / Banner -->
            <div style="margin: 0 30px 20px;">
                <div id="stage2Card" class="stage-card-item locked">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 2 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage2Icon" style="display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem;">2</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Pangkat Conciliation</h3>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <span id="stage2StatusText" class="stage-sub-status" style="font-weight: 600;">Locked until mediation concludes</span>
                        <span style="font-size: 0.78rem; opacity: 0.9;">Pangkat ng Tagapagkasundo &bull; 3-member Lupon panel (RA 7160 Sec. 410c)</span>
                    </div>
                </div>
            </div>

                        <!-- Embedded Pangkat Conciliation Hearing Sessions Management -->
            <div class="stage-section-card" id="conciliationSessionsSection" style="margin: 0 30px 24px;">
                <div class="stage-section-header">
                    <div>
                        <h3>Pangkat Conciliation Hearing Sessions</h3>
                        <p>Pangkat ng Tagapagkasundo &bull; Scheduled conciliation sessions, party appearances, and amicable settlement records</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span id="conciliationSessionsCountBadge" style="font-size: 0.78rem; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">0 sessions</span>
                        <button type="button" class="btn-create" id="btnSchedConciliation" onclick="openEmbeddedScheduleModal('Conciliation')" style="font-size: 0.78rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 4px;">
                            + Schedule Conciliation Session
                        </button>
                    </div>
                </div>
                <div id="conciliationSessionsListContainer" style="padding: 18px 20px;">
                    <div class="empty-detail-state">Loading conciliation hearing sessions...</div>
                </div>
                <div style="padding: 14px 20px; border-top: 1px solid #f1f5f9; background: #fafafa; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 0.82rem; color: #64748b;">
                        Pangkat is constituted from Lupon members chosen by the parties (KP Form 10/11) to conduct formal conciliation within 15 days.
                    </span>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openEmbeddedRecordOutcomeModal('Conciliation')" style="font-size: 0.82rem; padding: 6px 12px;">
                            Record Conciliation Outcome
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===================================================================
             TAB PANE 4: ARBITRATION / OUTCOMES / AMICABLE SETTLEMENT
             =================================================================== -->
        <div id="tab-arbitration" class="case-tab-pane <?php echo $activeTab === 'tab-arbitration' ? 'active' : ''; ?>">
            
            <!-- A. AMICABLE SETTLEMENT DUAL PREVIEW WORKSPACE -->
            <div id="amicableSettlementWorkspace" style="display: none;">
                <!-- Header Banner -->
                <div style="margin: 0 30px 20px;">
                    <div id="stageSettledCard" class="stage-card-item active" style="background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1.5px solid #86efac; border-radius: 12px; padding: 18px 22px; box-shadow: 0 2px 8px rgba(22, 163, 74, 0.08);">
                        <div class="stage-card-top" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <h3 id="stageSettledTitle" style="font-size: 1.2rem; margin: 0; color: #064e3b; font-weight: 800;">
                                    Amicable Settlement (Kasunduan)
                                </h3>
                                <span id="stageSettledBadge" class="status-pill" style="background: #dcfce7; color: #166534; font-weight: 800; font-size: 0.78rem; padding: 3px 10px; border-radius: 9999px;">
                                    ✓ Amicably Settled
                                </span>
                            </div>
                        </div>

                        <!-- Statutory Metric Cards -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; margin-top: 10px;">
                            <div style="background: #ffffff; padding: 10px 14px; border-radius: 8px; border: 1px solid #bbf7d0;">
                                <span style="font-size: 0.72rem; color: #15803d; text-transform: uppercase; font-weight: 700; display: block;">Execution Date</span>
                                <strong id="metricSettlementDate" style="font-size: 0.95rem; color: #0f172a;">—</strong>
                            </div>
                            <div style="background: #ffffff; padding: 10px 14px; border-radius: 8px; border: 1px solid #bbf7d0;">
                                <span style="font-size: 0.72rem; color: #15803d; text-transform: uppercase; font-weight: 700; display: block;">10-Working-Day Expiry</span>
                                <strong id="metricRepudiationDeadline" style="font-size: 0.95rem; color: #0f172a;">—</strong>
                            </div>
                            <div style="background: #ffffff; padding: 10px 14px; border-radius: 8px; border: 1px solid #bbf7d0;">
                                <span style="font-size: 0.72rem; color: #15803d; text-transform: uppercase; font-weight: 700; display: block;">Repudiation Status</span>
                                <div id="metricCountdownBadge" style="margin-top: 2px;">
                                    <span class="status-pill status-active" style="font-size: 0.8rem;">Calculating...</span>
                                </div>
                            </div>
                        </div>

                        <!-- Repudiation Alert Callout (Shown if case is repudiated) -->
                        <div id="calloutRepudiatedAlert" style="display: none; margin-top: 14px; background: #fef2f2; border: 1.5px solid #f87171; border-radius: 8px; padding: 12px 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <strong style="color: #991b1b; font-size: 0.92rem; display: flex; align-items: center; gap: 6px;">
                                        <span>⚠️</span> Sworn Statement of Repudiation Filed (KP Form 17)
                                    </strong>
                                    <p id="calloutRepudiatedNotes" style="margin: 4px 0 0; font-size: 0.82rem; color: #7f1d1d; line-height: 1.4;">
                                        Consent was vitiated. The settlement agreement is null and void.
                                    </p>
                                </div>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <button type="button" class="btn-secondary" onclick="openKpFormModal(window.currentCaseId || window.complaintData?.case_id, 'KP Form 17')" style="font-size: 0.8rem; padding: 5px 12px; border-color: #fca5a5; color: #991b1b;">
                                        View KP Form 17 &rarr;
                                    </button>
                                    <button type="button" class="btn-create" onclick="openKpFormModal(window.currentCaseId || window.complaintData?.case_id, 'KP Form 20')" style="font-size: 0.8rem; padding: 5px 14px; background: #dc2626; border-color: #dc2626;">
                                        Issue KP Form 20 (CFA) &rarr;
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Top Action Buttons -->
                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #bbf7d0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <button type="button" class="btn-secondary" onclick="openEditSettlementTermsModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #ffffff; border-color: #86efac; color: #166534; font-weight: 600;">
                                    ✏️ Edit Settlement Terms
                                </button>
                                <button type="button" id="btnActionFileRepudiation" class="btn-secondary" onclick="openFileRepudiationModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #ffffff; border-color: #fca5a5; color: #991b1b; font-weight: 600;">
                                    ⚠️ File Repudiation (KP Form 17)
                                </button>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <button type="button" id="btnActionIssueExecution" class="btn-secondary" onclick="openIssueExecutionModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #ffffff; border-color: #cbd5e1; color: #334155; font-weight: 600; display: none;">
                                    📜 Issue KP Form 23 (Motion for Execution)
                                </button>
                                <button type="button" id="btnActionRecordTransmittal" class="btn-create" onclick="openRecordTransmittalModal(window.currentCaseId || window.complaintData?.case_id)" style="font-size: 0.82rem; padding: 6px 14px; display: none;">
                                    📋 Record Court &amp; DILG Transmittal
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dual Document Preview Panels -->
                <div class="dual-doc-preview-grid" style="margin: 0 30px 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    
                    <!-- Card 1: KP Form No. 16 (Amicable Settlement) -->
                    <div class="dual-doc-card" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
                        <!-- Header -->
                        <div style="padding: 12px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong style="color: #0f172a; font-size: 0.95rem;">KP Form No. 16</strong>
                                    <span class="status-pill" style="background: #dcfce7; color: #166534; font-size: 0.72rem; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                                        ✓ Unlocked &amp; Printable
                                    </span>
                                </div>
                                <span style="font-size: 0.76rem; color: #64748b; display: block; margin-top: 1px;">
                                    Kasunduang Pag-aayos &bull; Executed Agreement
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <button type="button" class="btn-create" onclick="printKp16PreviewIframe()" style="font-size: 0.78rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 4px; background: #16a34a; border-color: #16a34a;">
                                    🖨️ Print Form 16
                                </button>
                                <a id="btnDownloadDualKp16Pdf" href="#" class="btn-secondary" download style="font-size: 0.78rem; padding: 5px 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;">
                                    📥 PDF
                                </a>
                                <button type="button" class="btn-secondary" onclick="openKpFormModal(window.currentCaseId || window.complaintData?.case_id, 'KP Form 16')" title="Fullscreen / Scale View" style="font-size: 0.78rem; padding: 5px 8px;">
                                    🔍
                                </button>
                            </div>
                        </div>

                        <!-- Document Canvas Container -->
                        <div style="background: #334155; padding: 16px 12px; height: 680px; overflow: hidden; display: flex; justify-content: center; align-items: stretch; position: relative;">
                            <iframe id="dualKp16PreviewIframe" src="about:blank" style="width: 100%; height: 100%; border: none; background: #ffffff; border-radius: 4px; box-shadow: 0 8px 30px rgba(0,0,0,0.35);" title="KP Form 16 Preview"></iframe>
                        </div>
                    </div>

                    <!-- Card 2: Court Transmittal Letter (MTC / MeTC & DILG) -->
                    <div class="dual-doc-card" style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
                        <!-- Header -->
                        <div style="padding: 12px 18px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <strong style="color: #0f172a; font-size: 0.95rem;">Court Transmittal Letter</strong>
                                    <span id="dualTransmittalStatusBadge" class="status-pill status-amber" style="background: #fef3c7; color: #b45309; font-size: 0.72rem; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                                        🔒 Locked for Printing
                                    </span>
                                </div>
                                <span style="font-size: 0.76rem; color: #64748b; display: block; margin-top: 1px;">
                                    RA 7160 Sec. 411 / Sec. 416 &bull; MTC &amp; DILG Transmittal
                                </span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <button type="button" id="btnDualPrintTransmittal" class="btn-create" onclick="printDualTransmittalIframe()" disabled style="font-size: 0.78rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 4px; opacity: 0.5; cursor: not-allowed;" title="Locked pursuant to RA 7160 Sec. 416 until repudiation period expires">
                                    🔒 Print Transmittal
                                </button>
                                <a id="btnDownloadDualTransmittalPdf" href="#" class="btn-secondary" style="font-size: 0.78rem; padding: 5px 10px; text-decoration: none; display: inline-flex; align-items: center; gap: 3px; opacity: 0.5; pointer-events: none;" title="Download restricted until 10 business days elapse">
                                    📥 PDF
                                </a>
                                <button type="button" class="btn-secondary" onclick="openPreviewTransmittalModal(window.currentCaseId || window.complaintData?.case_id)" title="Fullscreen / Scale View" style="font-size: 0.78rem; padding: 5px 8px;">
                                    🔍
                                </button>
                            </div>
                        </div>

                        <!-- Document Canvas Container -->
                        <div style="background: #334155; padding: 16px 12px; height: 680px; overflow: hidden; display: flex; justify-content: center; align-items: stretch; position: relative;">
                            <iframe id="dualTransmittalPreviewIframe" src="about:blank" style="width: 100%; height: 100%; border: none; background: #ffffff; border-radius: 4px; box-shadow: 0 8px 30px rgba(0,0,0,0.35);" title="Transmittal Letter Preview"></iframe>
                        </div>
                    </div>

                </div>
            </div>

            <!-- B. VOLUNTARY ARBITRATION 4-STEP PROGRESSIVE WORKSPACE -->
            <div id="arbitrationProgressiveWorkspace" style="display: none;"></div>

            <!-- C. ARBITRATION / CFA WORKSPACE (Existing fallback when outcome != Settled and != Arbitration) -->
            <div id="arbitrationCfaWorkspace">
                <!-- Arbitration / Court Referral Stage Card / Banner -->
                <div style="margin: 0 30px 20px;">
                <div id="stage3Card" class="stage-card-item pending">
                    <div class="stage-card-top">
                        <span class="stage-label-num">STAGE 3 &bull; KATARUNGANG PAMBARANGAY</span>
                        <span id="stage3Icon" style="display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem;">3</span>
                    </div>
                    <h3 class="stage-card-title" style="font-size: 1.15rem; margin: 4px 0 4px;">Dispute Resolution Outcomes</h3>
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
                        <h3 style="font-size: 1rem; font-weight: 800; color: #0f172a; margin: 0;">Dispute Resolution &amp; Court Referral Outcomes</h3>
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
            </div> <!-- close #arbitrationCfaWorkspace -->
        </div> <!-- close #tab-arbitration -->

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
                                Available Mediation Days (Max 8 Hearings/Day):
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
                        <span>Scheduled Mediations on this Date:</span>
                        <span id="summonDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>
                    </div>
                    <div id="summonDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">
                        No other mediations scheduled on this day.
                    </div>
                </div>

                <div class="form-group">
                    <label for="summonMediationTime">1st Mediation Time <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="time" id="summonMediationTime" name="mediation_time" value="09:00" min="08:00" max="17:00" step="1800" required>
                    <small class="field-hint">Regular Lupon hearing hours are 8:00 AM to 5:00 PM (Monday to Friday). Standard duration is 1 hour.</small>
                </div>

                <!-- Conflict Alert Box -->
                <div id="summonScheduleConflictAlert" class="alert alert-danger" style="display: none; margin: 0 0 14px; padding: 10px 12px; font-size: 0.84rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; line-height: 1.4;">
                </div>

                <div class="form-group">
                    <label for="summonMediationVenue">Venue <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="text" id="summonMediationVenue" name="venue" maxlength="255" value="Lupon Court Room" readonly required style="background: #f8fafc; cursor: not-allowed;">
                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Mediation sessions are conducted exclusively in the Lupon Court Room.</small>
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
                    Print Form
                </button>
                <button type="button" class="btn-secondary" onclick="closeKp7DigitalPreviewModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Official KP Form Document Preview & Print -->
<div id="kpDocumentPreviewModal" class="modal" style="display:none; padding: 12px; box-sizing: border-box; align-items: center; justify-content: center; z-index: 9999;" role="dialog" aria-modal="true" aria-labelledby="kpDocModalHeading">
    <div class="modal-content" style="max-width: 1350px; width: 96vw; height: calc(100vh - 24px); max-height: calc(100vh - 24px); display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 35px -5px rgba(0, 0, 0, 0.35); background: #ffffff;">
        <div class="modal-header" style="padding: 14px 24px; margin-bottom: 0; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span id="kpDocModalIcon" style="font-size: 1.3rem;"></span>
                <div>
                    <h2 id="kpDocModalHeading" style="font-size: 1.15rem; margin: 0; color: #0f172a; font-weight: 700;">KP Form Document Preview</h2>
                    <p id="kpDocModalSub" style="margin: 2px 0 0; font-size: 0.78rem; color: #64748b;">Barangay Tumana Lupong Tagapamayapa Official Katarungang Pambarangay Form</p>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="btn-create" onclick="printKpDocIframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; font-weight: 600; font-size: 0.85rem;">
                    Print Form
                </button>
                <a id="btnDownloadKpDocPdf" href="#" class="btn-pdf-chip" download style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; text-decoration: none; font-size: 0.85rem;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <span>PDF</span>
                </a>
                <button type="button" class="close-btn" onclick="closeKpDocumentPreviewModal()" aria-label="Close modal">&times;</button>
            </div>
        </div>
        <div style="flex: 1 1 auto; overflow: hidden; background: #334155; min-height: 0; height: 100%; display: flex; position: relative;">
            <iframe id="kpDocPreviewIframe" src="" style="width: 100%; height: 100%; border: none; background: #334155;"></iframe>
        </div>
        <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-shrink: 0;">
            <div id="kpDocModalFooterInfo" style="font-size: 0.82rem; color: #64748b;">
                Official Katarungang Pambarangay Legal Document
            </div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" class="btn-create" onclick="printKpDocIframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; font-weight: 600;">
                    Print Form
                </button>
                <button type="button" class="btn-secondary" onclick="closeKpDocumentPreviewModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Upload / Replace Handwritten KP Form 7 Scan -->
<div id="kp7UploadScanModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="uploadScanModalTitle">
    <div class="modal-content" style="max-width: 520px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
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

<!-- Modal 1: Paanyaya Document Preview Modal -->
<div id="paanyayaPreviewModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="paanyayaModalTitle">
    <div class="modal-content" style="max-width: 900px; width: 95%; height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div class="modal-header" style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #ffffff;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="paanyayaModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Official Paanyaya (Invitation Letter) Preview</h2>

                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="btn-sm btn-outline-sm" onclick="printPaanyayaIframe()" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px;">
                    Print
                </button>
                <button type="button" class="close-btn" onclick="closePaanyayaPreviewModal()">&times;</button>
            </div>
        </div>
        <div style="flex: 1 1 auto; background: #475569; position: relative;">
            <iframe id="paanyayaPreviewIframe" src="about:blank" style="width: 100%; height: 100%; border: none; background: #475569;"></iframe>
        </div>
        <div class="modal-actions" style="padding: 12px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; display: flex; justify-content: flex-end; align-items: center; gap: 10px;">
                <button type="button" class="btn-create" id="btnFooterToOfficersReturn" onclick="proceedToOfficersReturnFromPreview()" style="background: #2563eb; border-color: #2563eb; padding: 8px 18px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(37,99,235,0.25);">
                    Proceed to Officer's Return &rarr;
                </button>
        </div>
    </div>
</div>

<!-- Modal 2: Schedule / Update Paanyaya Modal -->
<div id="paanyayaScheduleModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="scheduleModalTitle">
    <div class="modal-content" style="max-width: 560px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <h2 id="scheduleModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Schedule Paanyaya Session</h2>
            </div>
            <button type="button" class="close-btn" onclick="closePaanyayaScheduleModal()">&times;</button>
        </div>
        <form id="paanyayaScheduleForm" onsubmit="submitSchedulePaanyaya(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <p style="font-size: 0.88rem; color: #475569; margin: 0 0 16px; line-height: 1.5;">
                Issue an official <strong>Paanyaya (Informal Invitation)</strong> for the parties to appear at the Barangay Hall for an informal dialogue before formal case docketing.
            </p>
            <div class="form-row-2" style="margin-bottom: 12px;">
                <div class="form-group">
                    <label for="modalPaanyayaDate" style="font-weight: 600; font-size: 0.85rem;">Dialogue Date <span class="required-mark">*</span></label>
                    <input type="date" id="modalPaanyayaDate" name="invitation_date" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
                <div class="form-group">
                    <label for="modalPaanyayaTime" style="font-weight: 600; font-size: 0.85rem;">Dialogue Time <span class="required-mark">*</span></label>
                    <input type="time" id="modalPaanyayaTime" name="invitation_time" value="14:00" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
            </div>
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="modalPaanyayaVenue" style="font-weight: 600; font-size: 0.85rem;">Venue</label>
                <input type="text" id="modalPaanyayaVenue" name="venue" value="Tanggapan ng Barangay Tumana" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="modalPaanyayaSalaysay" style="font-weight: 600; font-size: 0.85rem;">Salaysay / Reason for Invitation (Optional override)</label>
                <textarea id="modalPaanyayaSalaysay" name="salaysay" rows="2" placeholder="Leave blank to use complaint narrative..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>
            <input type="hidden" id="modalPaanyayaHasSig" name="has_e_signature" value="1">
            <div id="paanyayaScheduleError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>
            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closePaanyayaScheduleModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitPaanyayaSchedule" style="background: #16a34a; border-color: #16a34a;">Issue Paanyaya</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: Paanyaya Hearing Outcome Modal -->
<div id="paanyayaOutcomeModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="outcomeModalTitle">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span id="outcomeModalIcon" style="font-size: 1.3rem;"></span>
                <h2 id="outcomeModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Record Paanyaya Outcome</h2>
            </div>
            <button type="button" class="close-btn" onclick="closePaanyayaOutcomeModal()">&times;</button>
        </div>
        <form id="paanyayaOutcomeForm" onsubmit="submitPaanyayaOutcome(event)">
            <input type="hidden" id="outcomePaanyayaId" name="paanyaya_id" value="">
            <input type="hidden" id="outcomeComplaintId" name="complaint_id" value="">
            <input type="hidden" id="outcomeActionType" name="outcome" value="Resolved">

            <div id="outcomeResolvedNotice" style="display:none; margin-bottom: 16px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 14px; font-size: 0.88rem; color: #166534;">
                <strong>Informal Amicable Resolution:</strong>
                <p style="margin: 4px 0 0;">Parties reached an understanding during informal dialogue. The complaint will be closed without starting formal statutory court deadlines.</p>
            </div>

            <div id="outcomeEscalateNotice" style="display:none; margin-bottom: 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; font-size: 0.88rem; color: #991b1b;">
                <strong>Escalate to Formal Docketing (KP Form 7 &amp; 9):</strong>
                <p style="margin: 4px 0 0;">Dialogue failed or respondent failed to attend. The system will assign an official Case Number, set stage to Mediation, and generate KP Form 7 Complaint and KP Form 9 Summons.</p>
            </div>

            <!-- Escalation 1st Mediation Hearing Picker (Visible ONLY on Escalate) -->
            <div id="escalationScheduleSection" style="display: none; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 6px;">
                    <h4 style="margin: 0; font-size: 0.92rem; color: #0f172a; font-weight: 700;">1st Formal Mediation Hearing Schedule (Tumana Rules)</h4>
                    <span style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">
                        Tuesdays ONLY · Court Room (Max 8/Day)
                    </span>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="escalationMedDate" style="font-weight: 600; font-size: 0.82rem; margin: 0;">Mediation Date (Tuesdays ONLY) <span class="required-mark">*</span></label>
                            <span id="escalationCapacityBadge" class="badge-capacity" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: #e2e8f0; color: #475569;">
                                Checking...
                            </span>
                        </div>
                        <input type="date" id="escalationMedDate" name="mediation_date" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    </div>
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <label for="escalationMedTime" style="font-weight: 600; font-size: 0.82rem; margin: 0;">Target Call Time (1:00 PM - 4:30 PM) <span class="required-mark">*</span></label>
                            <span style="font-size: 0.72rem; color: #64748b;">15-min intervals</span>
                        </div>
                        <input type="time" id="escalationMedTime" name="mediation_time" min="13:00" max="16:30" step="900" value="13:00" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px;">
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='13:00'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:00 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='13:15'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:15 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='13:30'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:30 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='13:45'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">1:45 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='14:00'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:00 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='14:30'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">2:30 PM</button>
                            <button type="button" class="btn-slot-chip" onclick="document.getElementById('escalationMedTime').value='15:00'; document.getElementById('escalationMedTime').dispatchEvent(new Event('change'));" style="font-size: 0.7rem; padding: 2px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; cursor: pointer;">3:00 PM</button>
                        </div>
                    </div>
                </div>
                <div class="form-group" style="margin-top: 8px;">
                    <label for="escalationMedVenue" style="font-weight: 600; font-size: 0.82rem;">Hearing Venue</label>
                    <input type="text" id="escalationMedVenue" name="venue" value="Lupon Court Room" readonly style="padding: 8px 10px; font-size: 0.88rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>

                <!-- Live Day Docket Peek -->
                <div id="escalationDocketPeek" style="margin-top: 10px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                    <div style="font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                        <span>📋 Tuesday Mediation Docket Call (Lupon Court Room)</span>
                        <span id="escalationDocketCount" style="font-size: 0.72rem; color: #64748b;">0 sessions</span>
                    </div>
                    <div id="escalationDocketList" style="font-size: 0.8rem; color: #64748b; line-height: 1.4;">
                        Select a Tuesday to peek existing docket calls.
                    </div>
                </div>

                <!-- Inline Overlap Conflict Alert -->
                <div id="escalationConflictAlert" class="alert alert-danger" style="display: none; margin-top: 10px; padding: 8px 12px; font-size: 0.82rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;"></div>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="outcomeNotes" style="font-weight: 600; font-size: 0.85rem;">Dialogue &amp; Proceedings Notes <span class="required-mark">*</span></label>
                <textarea id="outcomeNotes" name="resolution_notes" rows="3" required placeholder="Describe dialogue outcome, reasons for escalation or terms of informal settlement..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <div id="paanyayaOutcomeError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closePaanyayaOutcomeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitPaanyayaOutcome">Confirm Outcome</button>
            </div>
        </form>
    </div>
</div>



<!-- Modal: View Paanyaya Dialogue Minutes -->
<div id="paanyayaMinutesViewModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="viewMinutesModalHeading">
    <div class="modal-content" style="max-width: 600px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15); max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="viewMinutesModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Recorded Dialogue Minutes</h2>
                    <span id="viewMinutesModalSub" style="font-size: 0.8rem; color: #64748b;">Katitikan ng Impormal na Pag-uusap</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closePaanyayaMinutesViewModal()">&times;</button>
        </div>
        <div id="viewMinutesBody" style="font-size: 0.88rem; line-height: 1.5; color: #334155;">
            <!-- Injected by JavaScript -->
        </div>
        <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px;">
            <button type="button" class="btn-secondary" onclick="closePaanyayaMinutesViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- ===================================================================
     MODALS FOR PAANYAYA 1 & 2 WORKFLOW & OFFICER'S RETURN TRACKING
     =================================================================== -->

<!-- Modal 1: Issue Paanyaya Stage Modal (Paanyaya 1 or Paanyaya 2) -->
<div id="issuePaanyayaStageModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="issueStageModalHeading">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="issueStageModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Issue Paanyaya 1</h2>
                    <span id="issueStageModalSub" style="font-size: 0.8rem; color: #64748b;">First Preliminary Invitation (Informal Dialogue)</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeIssuePaanyayaStageModal()">&times;</button>
        </div>
        <form id="issuePaanyayaStageForm" onsubmit="submitIssuePaanyayaStage(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" id="issueStageType" name="stage_type" value="Paanyaya 1">

            <p style="font-size: 0.86rem; color: #475569; margin: 0 0 16px; line-height: 1.5;">
                Prepare and issue an official preliminary invitation letter to invite the respondent to an informal dialogue before formal case docketing.
            </p>

            <div class="form-row-2" style="margin-bottom: 12px;">
                <div class="form-group">
                    <label for="issueStageDate" style="font-weight: 600; font-size: 0.84rem;">Dialogue Date <span class="required-mark">*</span></label>
                    <input type="date" id="issueStageDate" name="invitation_date" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
                <div class="form-group">
                    <label for="issueStageTime" style="font-weight: 600; font-size: 0.84rem;">Dialogue Time <span class="required-mark">*</span></label>
                    <input type="time" id="issueStageTime" name="invitation_time" value="14:00" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="issueStageOfficer" style="font-weight: 600; font-size: 0.84rem;">Assigned Serving Officer <span class="required-mark">*</span></label>
                <select id="issueStageOfficer" name="assigned_officer_id" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    <option value="">-- Select Serving Officer --</option>
                    <?php foreach ($servingOfficers as $idx => $off): ?>
                        <option value="<?= (int)$off['user_id'] ?>" <?= $idx === 0 ? 'selected' : '' ?>>
                            <?= htmlspecialchars($off['full_name']) ?> (<?= htmlspecialchars($off['role_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="issueStageVenue" style="font-weight: 600; font-size: 0.84rem;">Venue</label>
                <input type="text" id="issueStageVenue" name="venue" value="Tanggapan ng Barangay Tumana" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="issueStageSalaysay" style="font-weight: 600; font-size: 0.84rem;">Salaysay / Purpose of Dialogue (Optional override)</label>
                <textarea id="issueStageSalaysay" name="salaysay" rows="2" placeholder="Leave blank to use complaint narrative..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <input type="hidden" id="issueStageHasSig" name="has_e_signature" value="1">

            <div id="issueStageError" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeIssuePaanyayaStageModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitIssueStage" style="background: #16a34a; border-color: #16a34a;">Issue Invitation &amp; Generate Letter</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Record Officer's Return Modal (Attempts 1 to 6) -->
<div id="recordOfficersReturnModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="rorModalHeading">
    <div class="modal-content" style="max-width: 620px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15); max-height: calc(100vh - 40px); overflow-y: auto;">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="rorModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Record Officer's Return</h2>
                    <div style="display: flex; gap: 6px; align-items: center; margin-top: 3px;">
                        <span id="rorStageBadge" class="paanyaya-stage-tag">Paanyaya 1</span>
                        <span id="rorAttemptBadge" style="font-size: 0.75rem; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px;">Attempt 1 of 6</span>
                    </div>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeRecordOfficersReturnModal()">&times;</button>
        </div>

        <form id="recordOfficersReturnForm" onsubmit="submitRecordOfficersReturn(event)" enctype="multipart/form-data">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" id="rorStageType" name="stage_type" value="Paanyaya 1">

            <!-- 1. Date and Time of Service Attempt -->
            <div class="form-row-2" style="margin-bottom: 12px;">
                <div class="form-group">
                    <label for="rorServiceDatetime" style="font-weight: 600; font-size: 0.84rem;">Date &amp; Time Visited <span class="required-mark">*</span></label>
                    <input type="datetime-local" id="rorServiceDatetime" name="service_datetime" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                </div>
                <div class="form-group">
                    <label for="rorServingOfficer" style="font-weight: 600; font-size: 0.84rem;">Assigned Serving Officer <span class="required-mark">*</span></label>
                    <select id="rorServingOfficer" name="serving_officer_id" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                        <option value="">-- Select Serving Officer --</option>
                        <?php foreach ($servingOfficers as $idx => $off): ?>
                            <option value="<?= (int)$off['user_id'] ?>" <?= $idx === 0 ? 'selected' : '' ?>>
                                <?= htmlspecialchars($off['full_name']) ?> (<?= htmlspecialchars($off['role_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 2. Respondent / Intended Recipient -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="rorRecipientName" style="font-weight: 600; font-size: 0.84rem;">Respondent / Intended Recipient Visited <span class="required-mark">*</span></label>
                <input type="text" id="rorRecipientName" name="recipient_name" required placeholder="Name of respondent or authorized recipient..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <!-- 3. Address Visited -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="rorServiceAddress" style="font-weight: 600; font-size: 0.84rem;">Address Visited <span class="required-mark">*</span></label>
                <input type="text" id="rorServiceAddress" name="service_address" required placeholder="Complete address visited in Barangay Tumana..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <!-- 4. Delivery Outcome -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="rorDeliveryOutcome" style="font-weight: 600; font-size: 0.84rem;">Delivery Outcome <span class="required-mark">*</span></label>
                <select id="rorDeliveryOutcome" name="delivery_outcome" required onchange="handleRorOutcomeChange()" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    <option value="">-- Select Actual Delivery Outcome --</option>
                    <option value="Successfully delivered">Successfully delivered</option>
                    <option value="Recipient unavailable">Recipient unavailable</option>
                    <option value="Recipient refused to receive">Recipient refused to receive</option>
                    <option value="Respondent not found at the address">Respondent not found at the address</option>
                    <option value="Incorrect or incomplete address">Incorrect or incomplete address</option>
                    <option value="Other reason">Other reason</option>
                </select>
            </div>

            <!-- 5. Reason for Unsuccessful Delivery (Conditional) -->
            <div class="form-group" id="rorUnsuccessfulReasonWrap" style="display: none; margin-bottom: 12px;">
                <label for="rorUnsuccessfulReason" style="font-weight: 600; font-size: 0.84rem; color: #b91c1c;">Reason for Unsuccessful Delivery <span class="required-mark">*</span></label>
                <input type="text" id="rorUnsuccessfulReason" name="unsuccessful_reason" placeholder="Explain why the invitation could not be served..." style="padding: 8px 10px; font-size: 0.88rem; border: 1.5px solid #fca5a5; border-radius: 6px; width: 100%;">
            </div>

            <!-- 6. Remarks or Explanation -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label for="rorRemarks" style="font-weight: 600; font-size: 0.84rem;">Remarks / Detailed Explanation</label>
                <textarea id="rorRemarks" name="remarks" rows="2" placeholder="Describe interaction, who was present, verbal statements, or serving notes..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <!-- 7. Proof of Service (Attachment) -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="rorProofFile" style="font-weight: 600; font-size: 0.84rem;">Proof of Service Attachment (Photo / Document)</label>
                <input type="file" id="rorProofFile" name="proof_file" accept="image/jpeg,image/png,image/webp,application/pdf" style="padding: 6px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%; background: #f8fafc;">
                <span style="font-size: 0.74rem; color: #64748b;">Accepted formats: JPG, PNG, WEBP, PDF (Max 10MB). Photos of the residence or received letter.</span>
            </div>

            <!-- 8. GPS Coordinates & Recorded Service Location -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 0.8rem; font-weight: 700; color: #0f172a;">GPS Location Coordinates</span>
                    <button type="button" class="btn-sm btn-case-outline" onclick="captureReturnGpsLocation()" style="padding: 3px 10px; font-size: 0.76rem;">
                        Capture Current Location
                    </button>
                </div>
                <div class="form-row-2">
                    <div class="form-group" style="margin-bottom: 6px;">
                        <input type="text" id="rorLatitude" name="latitude" placeholder="Latitude (e.g. 14.6521)" style="padding: 6px 8px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    </div>
                    <div class="form-group" style="margin-bottom: 6px;">
                        <input type="text" id="rorLongitude" name="longitude" placeholder="Longitude (e.g. 121.0854)" style="padding: 6px 8px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    </div>
                </div>
                <div id="rorGpsStatus" style="font-size: 0.74rem; color: #64748b; margin-top: 2px;"></div>
            </div>

            <!-- 9. Recorded By Display -->
            <div style="font-size: 0.78rem; color: #64748b; margin-bottom: 16px; padding: 8px 12px; background: #f1f5f9; border-radius: 6px;">
                <strong>Recorded by:</strong> <?php echo htmlspecialchars($_SESSION['username'] ?? 'Authorized User'); ?> &bull; 
                <strong>Submission Time:</strong> <?php echo date('M j, Y g:i A'); ?>
            </div>

            <div id="rorErrorBox" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeRecordOfficersReturnModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitRecordReturn" style="background: #2563eb; border-color: #2563eb;">Save Officer's Return</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 3: View Officer's Return Details Modal -->
<div id="viewOfficersReturnModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="vorModalHeading">
    <div class="modal-content" style="max-width: 660px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15); max-height: calc(100vh - 40px); overflow-y: auto;">
        <div class="modal-header" style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="vorModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Officer's Return Details</h2>
                    <span id="vorModalSub" style="font-size: 0.8rem; color: #64748b;">Official Service Attempt Record</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closeViewOfficersReturnModal()">&times;</button>
        </div>

        <div id="vorContentBody" style="display: flex; flex-direction: column; gap: 14px;">
            <!-- Populated dynamically by complaints.js -->
        </div>

        <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
            <button type="button" class="btn-case-outline" id="btnVorEditCorrection" style="display: none;" onclick="openEditFromViewReturn()">
                Edit Correction
            </button>
            <button type="button" class="btn-secondary" onclick="closeViewOfficersReturnModal()">Close</button>
        </div>
    </div>
</div>

<!-- Modal 4: Edit Officer's Return Correction Modal -->
<div id="editOfficersReturnModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="eorModalHeading">
    <div class="modal-content" style="max-width: 560px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <h2 id="eorModalHeading" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Edit Officer's Return (Permitted Correction)</h2>
            </div>
            <button type="button" class="close-btn" onclick="closeEditOfficersReturnModal()">&times;</button>
        </div>
        <form id="editOfficersReturnForm" onsubmit="submitEditOfficersReturn(event)">
            <input type="hidden" id="eorReturnId" name="return_id" value="">

            <p style="font-size: 0.84rem; color: #475569; margin: 0 0 14px; line-height: 1.45;">
                Permitted correction to remarks, address, or explanation. The original record is preserved and all corrections are recorded in the audit trail.
            </p>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="eorServiceAddress" style="font-weight: 600; font-size: 0.84rem;">Service Address Visited</label>
                <input type="text" id="eorServiceAddress" name="service_address" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="eorUnsuccessfulReason" style="font-weight: 600; font-size: 0.84rem;">Reason for Unsuccessful Delivery</label>
                <input type="text" id="eorUnsuccessfulReason" name="unsuccessful_reason" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="eorRemarks" style="font-weight: 600; font-size: 0.84rem;">Remarks / Explanation</label>
                <textarea id="eorRemarks" name="remarks" rows="2" style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="eorCorrectionNotes" style="font-weight: 600; font-size: 0.84rem; color: #b91c1c;">Correction Justification / Reason <span class="required-mark">*</span></label>
                <input type="text" id="eorCorrectionNotes" name="correction_notes" required placeholder="State reason for this correction (required for audit trail)..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
            </div>

            <div id="eorErrorBox" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeEditOfficersReturnModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitEditReturn">Save Correction</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 5: Authorized Lupon Review Modal (Exhausted Attempts / Transition Override) -->
<div id="reviewPaanyayaModal" class="modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="rpmModalHeading">
    <div class="modal-content" style="max-width: 580px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);">
        <div class="modal-header" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: none; padding-bottom: 0;">
            <div style="display: flex; align-items: center; gap: 10px;">
                
                <div>
                    <h2 id="rpmModalHeading" style="font-size: 1.18rem; font-weight: 800; color: #0f172a; margin: 0;">Authorized Lupon Review Decision</h2>
                    <span id="rpmModalSub" style="font-size: 0.8rem; color: #64748b;">Review for Paanyaya 1</span>
                </div>
            </div>
            <button type="button" class="close-btn" onclick="closePaanyayaReviewModal()">&times;</button>
        </div>

        <form id="reviewPaanyayaForm" onsubmit="submitPaanyayaReview(event)">
            <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
            <input type="hidden" id="rpmStageType" name="stage_type" value="Paanyaya 1">

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 0.86rem; color: #991b1b; line-height: 1.45;">
                <strong>Safeguard Notice:</strong> All six (6) delivery attempts have been exhausted without successful service. The system does not automatically advance or issue summons. An authorized Lupon officer must review and decide the next procedural action.
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="rpmDecision" style="font-weight: 600; font-size: 0.84rem;">Authorized Review Determination <span class="required-mark">*</span></label>
                <select id="rpmDecision" name="decision" required style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;">
                    <option value="">-- Select Procedural Decision --</option>
                    <option value="Approved for Paanyaya 2">Authorize Paanyaya 2 (Proceed to Second Preliminary Invitation)</option>
                    <option value="Approved for Summons">Authorize Formal Summons (Escalate to 1st Mediation Summons)</option>
                    <option value="Case Closed">Close Case (Administratively Dismissed / Dropped)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label for="rpmReviewNotes" style="font-weight: 600; font-size: 0.84rem;">Review Notes &amp; Official Justification <span class="required-mark">*</span></label>
                <textarea id="rpmReviewNotes" name="review_notes" rows="3" required placeholder="State official reasons, review findings, and Lupon instructions for this decision..." style="padding: 8px 10px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 6px; width: 100%;"></textarea>
            </div>

            <div id="rpmErrorBox" class="alert alert-danger" style="display:none; margin-bottom: 14px; font-size: 0.84rem; padding: 8px 12px;"></div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closePaanyayaReviewModal()">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitReview" style="background: #dc2626; border-color: #dc2626;">Confirm Decision</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 6: Proof of Service Lightbox Viewer -->
<div id="paanyayaProofLightboxModal" class="modal" style="display:none; z-index: 10000;" role="dialog" aria-modal="true" aria-labelledby="plbTitle">
    <div class="modal-content" style="max-width: 800px; padding: 20px; border-radius: 12px; background: #ffffff;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 0; border: none;">
            <h3 id="plbTitle" style="margin: 0; font-size: 1.1rem; color: #0f172a; font-weight: 700;">Proof of Service Attachment</h3>
            <button type="button" class="close-btn" onclick="closePaanyayaProofLightbox()">&times;</button>
        </div>
        <div style="text-align: center; max-height: calc(85vh - 120px); overflow: auto; background: #0f172a; border-radius: 8px; padding: 10px;">
            <img id="plbImage" src="" alt="Proof of Service" style="max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: 4px;">
            <div id="plbPdfContainer" style="display: none; padding: 20px; color: #ffffff;">
                <p>This proof is a PDF document.</p>
                <a id="plbPdfLink" href="#" target="_blank" class="btn-create" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;">
                    Open PDF in New Window
                </a>
            </div>
        </div>
        <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
            <a id="plbDownloadBtn" href="#" download class="btn-case-outline" style="text-decoration: none;">Download File</a>
            <button type="button" class="btn-secondary" onclick="closePaanyayaProofLightbox()">Close</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../layouts/summons-preview-modal.php'; ?>

<!-- ========================================== -->
<!-- MODAL: Schedule Hearing Session (Mediation / Conciliation) -->
<!-- ========================================== -->
<div id="wsScheduleModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 580px; max-height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 10px; overflow: hidden;">
        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; background: #fff; margin-bottom: 0; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;">
            <h2 id="wsSchedModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Schedule Hearing Session</h2>
            <button type="button" class="close-btn" onclick="closeEmbeddedScheduleModal()">&times;</button>
        </div>
        <form id="wsScheduleForm" onsubmit="submitEmbeddedSchedule(event)" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">
            <input type="hidden" id="schedHearingType" value="Mediation">
            <input type="hidden" id="schedComplaintId" value="<?php echo (int) $complaintId; ?>">
            <input type="hidden" id="schedCaseId" value="">
            <div class="modal-body" style="padding: 18px 24px; overflow-y: auto; flex: 1;">
                <p id="wsSchedModalSubtitle" style="color: #64748b; font-size: 0.85rem; margin-top: 0; margin-bottom: 14px; line-height: 1.45;">
                    Enter the date and time to schedule the hearing session. Regular Lupon hearing hours are 8:00 AM to 5:00 PM (Monday to Friday).
                </p>

                <div class="form-group" style="margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
                        <label for="schedDate" style="margin-bottom: 0; font-weight: 700; color: #1e293b;">
                            <span id="schedDateLabel">Hearing Date</span> <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <span id="schedWindowStatutoryBadge" style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">
                            15-Working-Day Window (RA 7160 Sec. 410)
                        </span>
                    </div>

                    <input type="date" id="schedDate" name="hearing_date" required onchange="handleSchedDateChange(this.value)" style="margin-bottom: 8px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box; background: #fff;">

                    <!-- Visual 15-Working-Day Calendar & Capacity Strip -->
                    <div id="schedVisualDatePickerContainer" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.78rem; flex-wrap: wrap; gap: 6px;">
                            <span style="font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.74rem;">
                                AVAILABLE HEARING DAYS (MAX 8 HEARINGS/DAY):
                            </span>
                            <div style="display: flex; gap: 10px; font-size: 0.72rem; flex-wrap: wrap;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #166534;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></span> Available</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #991b1b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444;"></span> 8/8 Full</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #64748b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #94a3b8;"></span> Outside 15d</span>
                            </div>
                        </div>

                        <!-- Scrollable Day Cards Grid -->
                        <div id="schedVisualDayGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 6px; max-height: 155px; overflow-y: auto; padding: 4px 2px;">
                            <div style="text-align: center; color: #64748b; font-size: 0.78rem; padding: 12px; grid-column: 1 / -1;">
                                Loading statutory 15-working-day availability &amp; capacity...
                            </div>
                        </div>
                        <div id="schedVisualDateStatusNotice" style="margin-top: 6px; font-size: 0.75rem; color: #64748b; line-height: 1.4;"></div>
                    </div>
                </div>

                <!-- Existing Sessions on selected date -->
                <div id="schedDaySchedulesWrap" style="display: none; margin-bottom: 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.84rem;">
                    <div style="font-weight: 600; color: #334155; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                        <span>Scheduled Hearings on this Date:</span>
                        <span id="schedDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>
                    </div>
                    <div id="schedDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">
                        No other hearings scheduled on this day.
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="schedTime"><span id="schedTimeLabel">Hearing Time</span> <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="time" id="schedTime" name="hearing_time" value="09:00" min="08:00" max="17:00" step="1800" required onchange="handleSchedTimeChange()" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box; background: #fff;">
                    <small class="field-hint" style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Regular Lupon hearing hours are 8:00 AM to 5:00 PM (Monday to Friday). Standard duration is 1 hour.</small>
                </div>

                <!-- Conflict Alert Box -->
                <div id="schedScheduleConflictAlert" class="alert alert-danger" style="display: none; margin: 0 0 14px; padding: 10px 12px; font-size: 0.84rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; line-height: 1.4;">
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="schedVenue">Venue <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="text" id="schedVenue" name="venue" maxlength="255" value="Lupon Court Room" readonly required style="background: #f8fafc; cursor: not-allowed; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">
                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Hearing sessions are conducted in the Lupon Court Room.</small>
                </div>

                <div class="form-group" style="margin-bottom: 6px;">
                    <label for="schedRemarks">Remarks <span class="optional-label">Optional</span></label>
                    <textarea id="schedRemarks" name="remarks" maxlength="5000" rows="2" placeholder="Optional hearing instructions or notes..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
                </div>

                <?php if ($canAuthorizePastDate): ?>
                <div class="form-group" style="margin-top: 10px;">
                    <label for="schedPastDateExceptionReason">Administrator past-date exception reason</label>
                    <textarea id="schedPastDateExceptionReason" rows="2" maxlength="2000" placeholder="Required only when scheduling a past date or time (10–2,000 characters)." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
                    <small style="color: #64748b; font-size: 0.75rem;">The reason is saved with the hearing and audit history.</small>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; flex-shrink: 0; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closeEmbeddedScheduleModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitSchedBtn">Schedule Session</button>
            </div>
            <p id="schedMessage" role="status" style="margin: 0; padding: 0 24px 8px; font-size: 0.84rem;"></p>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Detailed Hearing Session Attendance -->
<!-- ========================================== -->
<div id="wsAttendanceModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 650px; max-height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 10px; overflow: hidden;">
        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; background: #fff; margin-bottom: 0; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;">
            <h2 id="wsAttModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Record Hearing Attendance</h2>
            <button type="button" class="close-btn" onclick="closeEmbeddedAttendanceModal()">&times;</button>
        </div>
        <div id="wsAttModalBody" style="padding: 18px 24px; overflow-y: auto; flex: 1;">
            <div class="empty-detail-state">Loading attendance records...</div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Record Stage Procedural Outcome    -->
<!-- ========================================== -->
<div id="wsRecordOutcomeModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px; border-radius: 10px; padding: 24px;">
        <div class="modal-header" style="margin-bottom: 16px;">
            <h2 id="wsOutcomeModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Record Stage Outcome</h2>
            <button type="button" class="close-btn" onclick="closeEmbeddedRecordOutcomeModal()">&times;</button>
        </div>
        <form onsubmit="submitEmbeddedRecordOutcome(event)">
            <input type="hidden" id="stageOutcomeType" value="Mediation">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Select the formal procedural determination for this stage.
            </p>
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="stageOutcomeSelect" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Procedural Outcome <span class="required-mark" style="color: #dc2626;">*</span></label>
                <select id="stageOutcomeSelect" required onchange="handleEmbeddedStageOutcomeChange(this.value)" style="width: 100%; padding: 9px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem;">
                    <option value="Settled">Settled — Parties Reached Amicable Settlement (KP Form 16)</option>
 <option value="Arbitration">Arbitration — Parties Agreed to Binding Arbitration (KP Form 14)</option>
                    <option value="Unsuccessful">Unsuccessful — Impasse / Referred to Next Stage</option>
                    <option value="Dismissed">Dismissed — Complainant Wilfully Failed to Appear</option>
                    <option value="Repudiated">Repudiated — Settlement Repudiated by Party</option>
                </select>
            </div>
                        <div id="stageOutcomeArbitrationBanner" style="display: none; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                <strong style="color: #92400e; font-size: 0.88rem; display: block;">Parties Agreed to Voluntary Arbitration (KP Form 14)</strong>
                <p style="margin: 4px 0 10px; font-size: 0.82rem; color: #78350f;">
                    Both parties have voluntarily agreed in writing to submit their dispute to binding arbitration under RA 7160 Sec. 413.
                </p>
                <button type="button" class="btn-create" onclick="submitEmbeddedRecordOutcome(event)" style="background: #d97706; border-color: #d97706; font-size: 0.82rem; padding: 6px 14px;">Issue Arbitration &rarr;</button>
            </div>
<div class="form-group" style="margin-bottom: 18px;">
                <label for="stageOutcomeRemarks" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Determination Notes / Remarks <span class="optional-label" style="color: #64748b; font-weight: 400;">Optional</span></label>
                <textarea id="stageOutcomeRemarks" rows="3" placeholder="Summary of agreements reached, reasons for failure, or basis of determination..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
            </div>
            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closeEmbeddedRecordOutcomeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitOutcomeBtn">Confirm &amp; Record Outcome</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Refer to Pangkat Tagapagkasundo    -->
<!-- ========================================== -->
<div id="wsReferPangkatModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 620px; border-radius: 10px; padding: 24px;">
        <div class="modal-header" style="margin-bottom: 16px;">
            <h2 style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Refer Case to Pangkat ng Tagapagkasundo</h2>
            <button type="button" class="close-btn" onclick="closeEmbeddedReferPangkatModal()">&times;</button>
        </div>
        <form onsubmit="submitEmbeddedReferPangkat(event)">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                In accordance with <strong>RA 7160 Section 410(b)</strong>, when mediation before the Punong Barangay fails or the 15-day limit expires, the case is elevated to formal conciliation by constituting the 3-member <strong>Pangkat ng Tagapagkasundo</strong>. This automatically generates <strong>KP Form 10</strong>.
            </p>

            <div class="embedded-referral-step" id="refStep1">
                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="referDate" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Referral / Formation Date <span class="required-mark" style="color: #dc2626;">*</span></label>
                    <input type="date" id="referDate" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label for="referReason" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Referral Justification / Impasse Notes <span class="required-mark" style="color: #dc2626;">*</span></label>
                    <textarea id="referReason" rows="2" required placeholder="Mediation before the Punong Barangay failed to produce an amicable settlement. Referred for formal conciliation before the Pangkat." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
                </div>

                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeEmbeddedReferPangkatModal()">Cancel</button>
                    <button type="button" class="btn-create" onclick="goEmbeddedReferralStep(2)">Continue to panel selection &rarr;</button>
                </div>
            </div>

            <div class="embedded-referral-step" id="refStep2" style="display:none;">
                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="referSelectionMethod" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 6px;">Pangkat Selection Method</label>
                    <select id="referSelectionMethod" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem;">
                        <option value="Party Agreement">Party Agreement (Parties chose from Lupon)</option>
                        <option value="PB Assignment">Punong Barangay Assignment</option>
                        <option value="Raffle Draw">Raffle Draw (Lots drawn by PB)</option>
                    </select>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                    <span style="font-size: 0.82rem; font-weight: 700; color: #1e293b; text-transform: uppercase; display: block; margin-bottom: 10px;">
                        Nominate 3 Active Lupon Members
                    </span>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="referChairmanId" style="font-size: 0.82rem; font-weight: 600; display: block; margin-bottom: 4px;">1. Chairperson / Head <span class="required-mark" style="color: #dc2626;">*</span></label>
                        <select id="referChairmanId" required style="width: 100%; padding: 7px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;"></select>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="referSecretaryId" style="font-size: 0.82rem; font-weight: 600; display: block; margin-bottom: 4px;">2. Pangkat Secretary <span class="required-mark" style="color: #dc2626;">*</span></label>
                        <select id="referSecretaryId" required style="width: 100%; padding: 7px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;"></select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="referMemberId" style="font-size: 0.82rem; font-weight: 600; display: block; margin-bottom: 4px;">3. Regular Member <span class="required-mark" style="color: #dc2626;">*</span></label>
                        <select id="referMemberId" required style="width: 100%; padding: 7px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;"></select>
                    </div>
                </div>
                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="goEmbeddedReferralStep(1)">&larr; Back</button>
                    <button type="button" class="btn-create" onclick="goEmbeddedReferralStep(3)">Review referral &rarr;</button>
                </div>
            </div>

            <div class="embedded-referral-step" id="refStep3" style="display:none;">
                <div id="referReviewSummary" style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px; margin-bottom:16px; font-size:.85rem; color:#1e3a8a;"></div>
                <p style="margin:0 0 16px; font-size:.84rem; color:#475569; line-height:1.5;">Confirming creates the Pangkat panel, records the 3-day convening deadline, and generates KP Form 10. You will then continue directly in the Conciliation tab to schedule its first hearing.</p>
                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-secondary" onclick="goEmbeddedReferralStep(2)">&larr; Back</button>
                    <button type="button" class="btn-secondary" onclick="closeEmbeddedReferPangkatModal()">Cancel</button>
                    <button type="submit" class="btn-create" id="submitReferBtn" style="background: #0369a1; border-color: #0369a1;">Execute Referral &amp; Generate KP Form 10</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../layouts/transmittal-modal.php'; ?>
<?php include __DIR__ . '/../../layouts/kp-form-preview-modal.php'; ?>

<!-- MODAL: Sworn Statement of Repudiation (KP Form 17 - RA 7160 Sec. 416) -->
<div id="modalFileRepudiation" class="modal" role="dialog" aria-modal="true" style="display: none; padding: 12px; z-index: 9999;">
    <div class="modal-content" style="max-width: 580px; width: 95vw; border-radius: 12px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.25);">
        <div class="modal-header" style="margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="font-size: 1.2rem; font-weight: 800; color: #991b1b; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <span>⚠️</span> File Repudiation (KP Form 17)
                </h2>
                <p style="margin: 3px 0 0; font-size: 0.8rem; color: #64748b;">
                    Pagtatwa ng Kasunduan &bull; RA 7160 Section 416 (Vitiated Consent)
                </p>
            </div>
            <button type="button" class="close-btn" onclick="closeFileRepudiationModal()" style="font-size: 1.5rem; border: none; background: transparent; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <form onsubmit="handleRepudiationSubmit(event)">
            <input type="hidden" id="repudiationSettlementId" name="settlement_id">

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.82rem; color: #991b1b; line-height: 1.45;">
                <strong>Statutory Notice (RA 7160 Sec. 416):</strong> Any party may, within ten (10) days from execution, repudiate the settlement by filing a sworn statement alleging that consent was vitiated by fraud, violence, or intimidation. Submitting this will invalidate the settlement and route the case to issue <strong>KP Form 20 (Certificate to File Action)</strong>.
            </div>

            <!-- Filing Party -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="repudiatingPartySelect" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Repudiating Party (Naghahain ng Pagtatwa) <span style="color: #dc2626;">*</span>
                </label>
                <select id="repudiatingPartySelect" name="repudiated_by" required style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                    <option value="">— Select Party Filing Repudiation —</option>
                </select>
            </div>

            <!-- Statutory Grounds -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 6px;">
                    Statutory Legal Grounds (RA 7160 Sec. 416) <span style="color: #dc2626;">*</span>
                </label>
                <div style="display: flex; flex-direction: column; gap: 6px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; font-size: 0.85rem;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="ground" value="Fraud (Panlilinlang)" onchange="updateRepudiationGroundsText()">
                        <span><strong>Panlilinlang (Fraud)</strong> &mdash; Misrepresentation of terms or deceit</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="ground" value="Violence (Karahasan)" onchange="updateRepudiationGroundsText()">
                        <span><strong>Karahasan (Violence)</strong> &mdash; Physical force, coercion, or assault</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="ground" value="Intimidation (Pananakot)" onchange="updateRepudiationGroundsText()">
                        <span><strong>Pananakot (Intimidation)</strong> &mdash; Duress, threats, or grave harassment</span>
                    </label>
                </div>
            </div>

            <!-- Sworn Narrative -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="repudiationReasonText" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Sworn Statement / Narrative Details (Salaysay ng Pagtatwa) <span style="color: #dc2626;">*</span>
                </label>
                <textarea id="repudiationReasonText" name="repudiation_reason" rows="3" required placeholder="State specific acts of fraud, violence, or intimidation that vitiated consent..." style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; box-sizing: border-box;"></textarea>
            </div>

            <!-- Date of Sworn Statement -->
            <div class="form-group" style="margin-bottom: 18px;">
                <label for="repudiationDateInput" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Date of Sworn Statement (Petsa ng Sinumpaang Salaysay) <span style="color: #dc2626;">*</span>
                </label>
                <input type="date" id="repudiationDateInput" name="repudiation_date" required style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeFileRepudiationModal()">Cancel</button>
                <button type="submit" id="btnSubmitRepudiation" class="btn-create" style="background: #dc2626; border-color: #dc2626;">
                    Submit Sworn Repudiation &rarr;
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit Settlement Terms (KP Form 16) -->
<div id="modalEditSettlementTerms" class="modal" role="dialog" aria-modal="true" style="display: none; padding: 12px; z-index: 9999;">
    <div class="modal-content" style="max-width: 580px; width: 95vw; border-radius: 12px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.25);">
        <div class="modal-header" style="margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0;">
                    Edit Settlement Agreement Terms
                </h2>
                <p style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">
                    KP Form No. 16 (Kasunduang Pag-aayos)
                </p>
            </div>
            <button type="button" class="close-btn" onclick="closeEditSettlementTermsModal()" style="font-size: 1.5rem; border: none; background: transparent; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <form onsubmit="handleEditSettlementSubmit(event)">
            <input type="hidden" id="editSettleCaseId" name="case_id">
            <input type="hidden" id="editSettleId" name="settlement_id">

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="editSettleDate" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Date of Execution (Petsa ng Kasunduan) <span style="color: #dc2626;">*</span>
                </label>
                <input type="date" id="editSettleDate" name="settlement_date" required style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="editSettleTermsText" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Detailed Terms of Settlement (Mga Napagkasunduan) <span style="color: #dc2626;">*</span>
                </label>
                <textarea id="editSettleTermsText" name="agreement_details" rows="5" required placeholder="Specify obligations, amounts, installment dates, or conditions..." style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;"></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="editSettleAmount" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Settlement Amount (PHP) <span style="font-weight: 400; color: #64748b;">Optional</span>
                    </label>
                    <input type="number" step="0.01" min="0" id="editSettleAmount" name="total_amount" placeholder="0.00" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="editSettleResponsible" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Responsible Party
                    </label>
                    <select id="editSettleResponsible" name="responsible_party" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                        <option value="Respondent" selected>Respondent</option>
                        <option value="Complainant">Complainant</option>
                        <option value="Both">Both Parties</option>
                    </select>
                </div>
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeEditSettlementTermsModal()">Cancel</button>
                <button type="submit" id="btnSubmitEditSettlement" class="btn-create">Save Changes &amp; Refresh Preview</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Issue Motion for Execution (KP Form 23 - RA 7160 Sec. 417) -->
<div id="modalIssueExecutionKp23" class="modal" role="dialog" aria-modal="true" style="display: none; padding: 12px; z-index: 9999;">
    <div class="modal-content" style="max-width: 580px; width: 95vw; border-radius: 12px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.25);">
        <div class="modal-header" style="margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <span>📜</span> Issue Motion for Execution (KP Form 23)
                </h2>
                <p style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">
                    Mosyon sa Pagpapatupad &bull; RA 7160 Sec. 417 (Enforcement of Final Settlement)
                </p>
            </div>
            <button type="button" class="close-btn" onclick="closeIssueExecutionModal()" style="font-size: 1.5rem; border: none; background: transparent; cursor: pointer; color: #64748b;">&times;</button>
        </div>
        <form onsubmit="handleIssueExecutionSubmit(event)">
            <input type="hidden" id="execSettlementId" name="settlement_id">

            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.82rem; color: #166534; line-height: 1.45;">
                <strong>Statutory Finality (RA 7160 Sec. 417):</strong> The 10-business-day repudiation window has elapsed without sworn repudiation. The amicable settlement now has the force and effect of a final court judgment and may be executed by the Lupon within six (6) months.
            </div>

            <!-- Moving Party -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="execMovingPartySelect" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Moving Party (Naghain ng Mosyon) <span style="color: #dc2626;">*</span>
                </label>
                <select id="execMovingPartySelect" name="motion_filed_by" required style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                    <option value="">— Select Moving Party —</option>
                </select>
            </div>

            <!-- Obligation Violated -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label for="execObligationText" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                    Obligation Violated / Unsatisfied Settlement Terms <span style="color: #dc2626;">*</span>
                </label>
                <textarea id="execObligationText" name="obligation_violated" rows="3" required placeholder="Specify what the respondent failed or refused to comply with..." style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; box-sizing: border-box;"></textarea>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="execAmountReq" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Amount / Requirement Demanded <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="execAmountReq" name="amount_or_requirement" required placeholder="e.g. PHP 25,000 or Return of Property" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="execMotionDate" style="font-weight: 700; font-size: 0.84rem; display: block; margin-bottom: 4px;">
                        Motion Filing Date <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="date" id="execMotionDate" name="motion_date" required style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem; box-sizing: border-box;">
                </div>
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 0;">
                <button type="button" class="btn-secondary" onclick="closeIssueExecutionModal()">Cancel</button>
                <button type="submit" id="btnSubmitExecution" class="btn-create">
                    Generate KP Form 23 &rarr;
                </button>
            </div>
        </form>
    </div>
</div>


<!-- =====================================================================
     MODAL: Reopen Settled Case for CFA (RA 7160 Sec. 417 Non-Compliance)
     ===================================================================== -->
<div id="modalReopenForCfa" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.55); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; max-width:520px; width:92%; padding:28px 28px 22px; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:16px;">
            
            <div>
                <h3 style="margin:0; font-size:1rem; color:#0f172a; font-weight:800;">Reopen Case for CFA Issuance</h3>
                <p style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">RA 7160 Sec. 417 — Non-compliance with Amicable Settlement</p>
            </div>
        </div>
        <p style="font-size:0.84rem; color:#475569; margin:0 0 14px; line-height:1.55;">
            This action marks the Amicable Settlement as <strong>Violated</strong> and opens the case for
            Certificate to File Action (KP Form 20) issuance. This is authorised only when the respondent
            fails or refuses to comply with the terms of the settlement.
        </p>
        <div style="margin-bottom:16px;">
            <label for="reopenCfaReason" style="display:block; font-size:0.84rem; font-weight:600; color:#334155; margin-bottom:6px;">
                Non-Compliance Description <span style="color:#dc2626;">*</span>
            </label>
            <textarea id="reopenCfaReason" rows="4" placeholder="Describe specifically how the respondent failed to comply with the settlement terms (e.g., failed to pay agreed amount by due date, refused to vacate property, etc.)"
                style="width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #e2e8f0; border-radius:7px; font-size:0.84rem; color:#0f172a; resize:vertical; font-family:inherit;"></textarea>
        </div>
        <p style="font-size:0.78rem; color:#94a3b8; margin:0 0 18px;">
            This action is <strong>irreversible</strong>. Ensure all facts of non-compliance are accurately documented before proceeding.
        </p>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn-secondary" onclick="closeReopenCfaModal()" id="btnReopenCfaCancel">Cancel</button>
            <button type="button" class="btn-case-primary" id="btnReopenCfaConfirm"
                onclick="submitReopenForCfa()"
                style="background:#dc2626; border-color:#dc2626;"
            >
                Reopen &amp; Issue CFA
            </button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../../assets/js/summons-preview.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/summons-preview.js'); ?>"></script>
<script src="../../assets/js/complaints.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/complaints.js'); ?>"></script>


<!-- ========================================== -->
<!-- MODAL: Issue KP Form 15 (Arbitration Award)-->
<!-- ========================================== -->
<div id="issueArbitrationAwardModal" class="modal" style="display: none; z-index: 1050; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center; padding: 20px;">
    <div class="modal-content" style="max-width: 620px; width: 100%; max-height: 90vh; overflow-y: auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        <div class="modal-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 14px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <h2 style="font-size: 1.22rem; font-weight: 800; color: #0f172a; margin: 0;">Issue KP Form 15 (Arbitration Award)</h2>
                <span style="font-size: 0.8rem; color: #64748b;">Gawad ng Paghahatol &bull; Binding Arbitral Ruling under RA 7160 Sec. 413</span>
            </div>
            <button type="button" class="close-btn" onclick="closeIssueArbitrationAwardModal()" style="background: none; border: none; font-size: 1.6rem; color: #64748b; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <form id="issueArbitrationAwardForm" onsubmit="submitArbitrationAward(event)">
            <input type="hidden" id="arbAwardCaseId" value="">
            <input type="hidden" id="arbAwardAgreementDate" value="">

            <!-- Statutory Window Notice -->
            <div id="arbStatutoryNoticeBanner" style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 1.15rem;">⚖️</span>
                    <strong style="color: #1e40af; font-size: 0.88rem;">RA 7160 Sec. 413 Mandatory Statutory Timeline</strong>
                </div>
                <p id="arbStatutoryNoticeText" style="margin: 4px 0 0; font-size: 0.81rem; color: #1e3a8a; line-height: 1.5;">
                    The arbitration award cannot be issued earlier than <strong>Day 6</strong> (after the 5-day repudiation window expires) and must be rendered no later than <strong>15 calendar days</strong> from signing KP Form 14.
                </p>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="arbAwardDate" style="font-weight: 700; font-size: 0.84rem; color: #0f172a; display: block; margin-bottom: 4px;">
                    Date of Arbitral Award <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <input type="date" id="arbAwardDate" required onchange="validateArbitrationAwardDateInput()" style="width: 100%; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 0.88rem; box-sizing: border-box;">
                <small id="arbAwardDateHelper" style="color: #64748b; font-size: 0.77rem; display: block; margin-top: 4px;">
                    Allowed statutory range: Day 6 to Day 15.
                </small>
                <div id="arbAwardDateError" style="display: none; color: #dc2626; font-size: 0.8rem; font-weight: 600; margin-top: 5px; padding: 6px 10px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px;"></div>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label for="arbAwardTerms" style="font-weight: 700; font-size: 0.84rem; color: #0f172a; display: block; margin-bottom: 4px;">
                    Specific Terms of Award &amp; Resolution Findings <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <textarea id="arbAwardTerms" rows="5" required placeholder="Isaad ang buong hatol ng Lupon, mga ebidensyang isinaalang-alang, at ang mga pinal na obligasyon o kabayaran na dapat tuparin ng mga panig..." style="width: 100%; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 0.84rem; line-height: 1.5; box-sizing: border-box;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 18px;">
                <label for="arbArbitratorPresider" style="font-weight: 700; font-size: 0.84rem; color: #0f172a; display: block; margin-bottom: 4px;">
                    Signatures / Presiding Arbitrator Details
                </label>
                <input type="text" id="arbArbitratorPresider" placeholder="HON. AKIKO LYNNE CENTENO (Punong Barangay / Tagapangulo)" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 0.84rem; box-sizing: border-box;">
            </div>

            <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                <button type="button" class="btn-secondary" onclick="closeIssueArbitrationAwardModal()" style="font-size: 0.84rem; padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn-create" id="btnSubmitArbAward" style="font-size: 0.84rem; padding: 8px 20px; background: #2563eb; border-color: #2563eb; font-weight: 700;">Confirm &amp; Render Award (KP Form 15)</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../layouts/footer.php'; ?>
