<?php

session_start();

if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    die('Access Denied');
}

require_once __DIR__ . '/../../../backend/config/database.php';
$db = (new Database())->connect();

$caseId = filter_var($_GET['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$complaintId = filter_var($_GET['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$caseId && $complaintId) {
    $cStmt = $db->prepare('SELECT case_id FROM cases WHERE complaint_id = ? LIMIT 1');
    $cStmt->execute([$complaintId]);
    $caseId = (int) $cStmt->fetchColumn();
} elseif ($caseId && !$complaintId) {
    $cStmt = $db->prepare('SELECT complaint_id FROM cases WHERE case_id = ? LIMIT 1');
    $cStmt->execute([$caseId]);
    $complaintId = (int) $cStmt->fetchColumn();
}

if (!$caseId) {
    header('Location: ../cases/case-list.php');
    exit;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/hearings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/hearings.css'); ?>">

<style>
    .stage-workspace-container {
        padding: 24px 30px;
        max-width: 1400px;
        margin: 0 auto;
    }
    .stage-breadcrumbs {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.84rem;
        color: #64748b;
        margin-bottom: 16px;
    }
    .stage-breadcrumbs a {
        color: #2563eb;
        text-decoration: none;
    }
    .overview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }
    .overview-stat-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #64748b;
    }
    .stat-value {
        font-size: 1.05rem;
        font-weight: 700;
        color: #0f172a;
    }
    .stat-sub {
        font-size: 0.76rem;
        color: #64748b;
    }
    .stage-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .stage-section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid #f1f5f9;
    }
    .stage-section-header h3 {
        margin: 0;
        font-size: 1.12rem;
        color: #0f172a;
        font-weight: 700;
    }
    .stage-section-header p {
        margin: 3px 0 0;
        font-size: 0.82rem;
        color: #64748b;
    }
    .locked-gate-banner {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 48px 30px;
        text-align: center;
        max-width: 640px;
        margin: 40px auto;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
</style>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="stage-workspace-container">
            <!-- Breadcrumbs -->
            <div class="stage-breadcrumbs">
                <a href="../cases/case-list.php">Cases</a>
                <span>&rsaquo;</span>
                <a id="wsBackToComplaintLink" href="../complaints/complaint-details.php?id=<?php echo $complaintId; ?>">Complaint Details</a>
                <span>&rsaquo;</span>
                <span id="wsBreadcrumbStage" style="color: #0f172a; font-weight: 600;">Binding Arbitration</span>
            </div>

            <!-- Page Header -->
            <div class="page-header" style="padding: 0 0 20px 0; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                        <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; background: #e0e7ff; color: #3730a3; padding: 3px 8px; border-radius: 4px;">
                            BINDING ARBITRATION
                        </span>
                        <h1 id="wsStageTitle" style="margin: 0; font-size: 1.45rem; color: #0f172a; font-weight: 800;">
                            Binding Arbitration
                        </h1>
                        <span id="wsStageStatusBadge" class="badge-stage" style="font-size: 0.78rem; padding: 3px 9px; border-radius: 9999px; font-weight: 700;">
                            Loading...
                        </span>
                    </div>
                    <p id="wsCaseSubtext" style="margin: 0; font-size: 0.86rem; color: #64748b;">
                        Case Loading...
                    </p>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                        📅 Schedule Arbitration Hearing
                    </button>
                    <button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()" style="font-size: 0.82rem; padding: 7px 12px; display: inline-flex; align-items: center; gap: 5px;">
                        ⚖️ Record Arbitration Outcome
                    </button>
                    <a href="../cases/case-list.php?assign_case_id=<?php echo $caseId; ?>#caseAssignments" class="btn-secondary" style="font-size: 0.82rem; padding: 7px 12px; display: inline-flex; align-items: center; gap: 5px; text-decoration: none;" title="Manage Lupon Team">
                        👥 Cases &amp; Assignments &rarr;
                    </a>
                    <a id="wsSchedulesBackLink" href="../hearings/schedules.php" class="btn-secondary" style="font-size: 0.82rem; padding: 7px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                        📅 Central Schedule &rarr;
                    </a>
                </div>
            </div>

            <!-- LOCKED GATE STATE (If Arbitration stage is locked) -->
            <div id="stageLockedGate" style="display: none;">
                <div class="locked-gate-banner">
                    <div style="font-size: 3rem; margin-bottom: 12px;">🔒</div>
                    <h2 style="margin: 0 0 10px; font-size: 1.25rem; color: #0f172a; font-weight: 700;">
                        Arbitration Stage is Locked
                    </h2>
                    <p style="margin: 0 0 20px; font-size: 0.92rem; color: #64748b; line-height: 1.6;">
                        Arbitration is locked. Under Katarungang Pambarangay rules (Sec 413, RA 7160), parties must first execute <strong>KP Form 14 (Agreement for Arbitration)</strong> to enter binding arbitration.
                    </p>
                    <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                        <a href="mediation-workspace.php?case_id=<?php echo $caseId; ?>&complaint_id=<?php echo $complaintId; ?>" class="btn-create" style="font-size: 0.85rem; padding: 8px 18px; text-decoration: none;">
                            Go to Mediation Workspace &rarr;
                        </a>
                        <a href="../complaints/complaint-details.php?id=<?php echo $complaintId; ?>" class="btn-secondary" style="font-size: 0.85rem; padding: 8px 18px; text-decoration: none;">
                            Back to Complaint Details
                        </a>
                    </div>
                </div>
            </div>

            <!-- UNLOCKED WORKSPACE CONTENT -->
            <div id="stageWorkspaceContent">
                <!-- Section 1: Stage Overview -->
                <div class="overview-grid" id="wsStageOverviewGrid">
                    <div class="overview-stat-card"><span class="stat-label">Loading Overview...</span></div>
                </div>

                <!-- Section 2: Arbitration Hearing Sessions List -->
                <div class="stage-section-card" id="sessionsSection">
                    <div class="stage-section-header">
                        <div>
                            <h3>Arbitration Hearing Sessions</h3>
                            <p>Formal arbitration hearings conducted to evaluate evidence and render a binding award</p>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span id="wsSessionsCountBadge" style="font-size: 0.78rem; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">0 sessions</span>
                            <button type="button" class="btn-secondary" onclick="openScheduleModal()" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                + Schedule Session
                            </button>
                        </div>
                    </div>
                    <div id="wsSessionsListContainer">
                        <div class="empty-detail-state">Loading arbitration sessions...</div>
                    </div>
                </div>

                <!-- Section 3: Hearing Session Minutes & Settlement Progress -->
                <div class="stage-section-card" id="minutesSection">
                    <div class="stage-section-header">
                        <div>
                            <h3>Hearing Session Minutes &amp; Settlement Progress</h3>
                            <p>Official records of arbitration hearings, evidence submissions, party claims, and award progress</p>
                        </div>
                    </div>
                    <div id="wsMinutesSectionContainer">
                        <div class="empty-detail-state">Loading arbitration session minutes &amp; progress...</div>
                    </div>
                </div>

                <!-- Section 4: Stage Outcome & Case Disposition -->
                <div class="stage-section-card" id="outcomeSection">
                    <div class="stage-section-header">
                        <div>
                            <h3>Arbitration Outcome &amp; Binding Award</h3>
                            <p>Record arbitration determination or render binding Arbitration Award (KP Form 15)</p>
                        </div>
                    </div>
                    <div id="wsOutcomeActionBox">
                        <div class="empty-detail-state">Loading stage disposition...</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 1: Schedule Arbitration Hearing      -->
<!-- ========================================== -->
<div id="wsScheduleModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2>Schedule Arbitration Session</h2>
            <button type="button" class="close-btn" onclick="closeScheduleModal()">&times;</button>
        </div>
        <form id="wsScheduleForm" onsubmit="submitSchedule(event)">
            <input type="hidden" id="schedHearingType" value="Arbitration">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                In accordance with KP rules, scheduling an arbitration hearing registers the session in the central schedule and issues notices to all parties.
            </p>
            <div class="form-group">
                <label for="schedDate">Hearing Date <span class="required-mark">*</span></label>
                <input type="date" id="schedDate" required>
            </div>
            <div class="form-group">
                <label for="schedTime">Hearing Time <span class="required-mark">*</span></label>
                <input type="time" id="schedTime" value="09:00" required>
            </div>
            <div class="form-group">
                <label for="schedVenue">Venue <span class="required-mark">*</span></label>
                <input type="text" id="schedVenue" value="Barangay Hall" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="schedRemarks">Session Instructions / Remarks <span class="optional-label">Optional</span></label>
                <textarea id="schedRemarks" rows="2" placeholder="Optional arbitration instructions or evidence reminders..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeScheduleModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitSchedBtn">Schedule Arbitration Hearing</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 2: Record Attendance                -->
<!-- ========================================== -->
<div id="wsAttendanceModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h2 id="wsAttModalTitle">Record Arbitration Attendance</h2>
            <button type="button" class="close-btn" onclick="closeAttendanceModal()">&times;</button>
        </div>
        <div id="wsAttModalBody">
            <div class="empty-detail-state">Loading party records...</div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 3: Hearing Session Minutes & Progress -->
<!-- ========================================== -->
<div id="wsMinutesModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header">
            <h2 id="wsMinModalTitle">Hearing Session Minutes &amp; Settlement Progress</h2>
            <button type="button" class="close-btn" onclick="closeMinutesModal()">&times;</button>
        </div>
        <div id="wsMinutesForm">
            <div class="empty-detail-state">Loading meeting form...</div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 4: Record Arbitration Outcome       -->
<!-- ========================================== -->
<div id="wsRecordOutcomeModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2>Record Arbitration Outcome</h2>
            <button type="button" class="close-btn" onclick="closeRecordOutcomeModal()">&times;</button>
        </div>
        <form onsubmit="submitRecordOutcome(event)">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Select the formal procedural determination for this arbitration stage.
            </p>
            <div class="form-group">
                <label for="stageOutcomeSelect">Procedural Outcome <span class="required-mark">*</span></label>
                <select id="stageOutcomeSelect" required style="padding: 9px;">
                    <option value="Arbitration Award">Arbitration Award — Binding Award Rendered (KP Form 15)</option>
                    <option value="Settled">Settled — Parties Reached Settlement During Arbitration</option>
                    <option value="Repudiated">Repudiated — Arbitration Agreement Repudiated by Party</option>
                    <option value="Dismissed">Dismissed — Complainant Wilfully Failed to Appear</option>
                    <option value="Unsuccessful">Unsuccessful — Arbitration Impasse</option>
                </select>
            </div>
            <div class="form-group">
                <label for="stageOutcomeRemarks">Determination Notes / Award Details <span class="optional-label">Optional</span></label>
                <textarea id="stageOutcomeRemarks" rows="3" placeholder="Summary of award terms, basis of determination, or arbitration notes..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeRecordOutcomeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitOutcomeBtn">Confirm &amp; Record Outcome</button>
            </div>
        </form>
    </div>
</div>

<script src="../../assets/js/stage-workspace.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/stage-workspace.js'); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        initStageWorkspace({
            mode: 'Arbitration',
            caseId: <?php echo json_encode($caseId); ?>,
            complaintId: <?php echo json_encode($complaintId); ?>
        });
    });
</script>

<?php include '../../layouts/footer.php'; ?>
