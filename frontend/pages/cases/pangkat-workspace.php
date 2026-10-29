<?php

session_start();

if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) {
    die('Access Denied');
}

require_once __DIR__ . '/../../../backend/config/database.php';
$db = (new Database())->connect();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
$canAuthorizePastDate = $roleId === 1;
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

require_once __DIR__ . '/../../../backend/models/Setting.php';
$sysSettings = Setting::all();
$rawMedDays = $sysSettings['mediation_day_of_week'] ?? 'Mon,Tue,Wed,Thu,Fri';
$allowedMedDays = [];
if (is_string($rawMedDays)) {
    $dec = json_decode($rawMedDays, true);
    $allowedMedDays = is_array($dec) ? $dec : array_filter(array_map('trim', explode(',', $rawMedDays)));
} elseif (is_array($rawMedDays)) {
    $allowedMedDays = $rawMedDays;
}
if (empty($allowedMedDays)) {
    $allowedMedDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
}
$pluralMap = [
    'Mon' => 'Mondays', 'Tue' => 'Tuesdays', 'Wed' => 'Wednesdays',
    'Thu' => 'Thursdays', 'Fri' => 'Fridays', 'Sat' => 'Saturdays', 'Sun' => 'Sundays'
];
$dayNumMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
$medDaysPlural = array_map(fn($d) => $pluralMap[$d] ?? $d, $allowedMedDays);
$medDaysLabel = implode(', ', $medDaysPlural);
$allowedMedDayNumbers = array_values(array_unique(array_filter(array_map(fn($d) => $dayNumMap[$d] ?? null, $allowedMedDays), fn($n) => $n !== null)));
$medHoursStart = $sysSettings['mediation_hours_start'] ?? '08:00';
$medHoursEnd = $sysSettings['mediation_hours_end'] ?? '17:00';
$workHoursStart = $sysSettings['working_hours_start'] ?? '08:00';
$workHoursEnd = $sysSettings['working_hours_end'] ?? '17:00';
$medVenue = $sysSettings['mediation_venue'] ?? 'Lupon Court Room';

include '../../layouts/header.php';
?>
<script>
window.AGAP_SETTINGS = window.AGAP_SETTINGS || {};
window.AGAP_SETTINGS.mediation_days = <?= json_encode($allowedMedDays) ?>;
window.AGAP_SETTINGS.mediation_days_label = <?= json_encode($medDaysLabel) ?>;
window.AGAP_SETTINGS.mediation_day_numbers = <?= json_encode($allowedMedDayNumbers) ?>;
window.AGAP_SETTINGS.mediation_hours_start = <?= json_encode($medHoursStart) ?>;
window.AGAP_SETTINGS.mediation_hours_end = <?= json_encode($medHoursEnd) ?>;
window.AGAP_SETTINGS.working_hours_start = <?= json_encode($workHoursStart) ?>;
window.AGAP_SETTINGS.working_hours_end = <?= json_encode($workHoursEnd) ?>;
window.AGAP_SETTINGS.mediation_venue = <?= json_encode($medVenue) ?>;
</script>
<?php
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
        grid-auto-flow: column;
        grid-auto-columns: minmax(0, 1fr);
        gap: 10px;
        margin-bottom: 20px;
        width: 100%;
    }
    @media (max-width: 1100px) {
        .overview-grid {
            overflow-x: auto;
            grid-auto-columns: minmax(150px, 1fr);
            padding-bottom: 6px;
        }
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
                <span id="wsBreadcrumbStage" style="color: #0f172a; font-weight: 600;">Pangkat Conciliation</span>
            </div>

            <!-- Page Header -->
            <div class="page-header" style="padding: 0 0 20px 0; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0;">
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; background: #f3e8ff; color: #7e22ce; padding: 3px 8px; border-radius: 4px;">
                        STAGE 2 OF 2
                    </span>
                    <h1 id="wsStageTitle" style="margin: 0; font-size: 1.45rem; color: #0f172a; font-weight: 800;">
                        Pangkat Conciliation
                    </h1>
                    <span id="wsStageStatusBadge" class="badge-stage" style="font-size: 0.78rem; padding: 3px 9px; border-radius: 9999px; font-weight: 700;">
                        Loading...
                    </span>
                    <span id="wsDraftIndicator" class="agap-draft-indicator state-idle">✓ Saved</span>
                    <span style="color: #cbd5e1; margin: 0 2px;">•</span>
                    <p id="wsCaseSubtext" style="margin: 0; font-size: 0.86rem; color: #64748b; display: inline-flex; align-items: center; gap: 4px;">
                        Case Loading...
                    </p>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <div id="wsPrimaryAction"></div>
                    <details id="wsMoreActions" style="position: relative;">
                        <summary class="btn-secondary" style="font-size: 0.82rem; padding: 7px 12px; cursor: pointer; list-style: none;">More actions</summary>
                        <div id="wsMoreActionsMenu" style="position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; min-width: 220px; padding: 8px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 8px 18px rgba(15,23,42,.14);"></div>
                    </details>
                </div>
            </div>

            <!-- Content Container -->
            <div id="stageWorkspaceContent">
                <!-- Section 1: Stage Overview & Pangkat Assignment -->
                <div class="overview-grid" id="wsStageOverviewGrid">
                    <div class="overview-stat-card"><span class="stat-label">Loading Overview...</span></div>
                </div>

                <!-- Section: Pangkat ng Tagapagkasundo Governance & Members -->
                <div class="stage-section-card" id="pangkatPanelSection">
                    <div id="wsPangkatPanelContainer">
                        <div class="empty-detail-state">Loading Pangkat panel governance...</div>
                    </div>
                </div>
                    <!-- Section 2.5: Hearing Session Attendance Card -->
                <div class="stage-section-card" id="attendanceSection">
                    <div class="stage-section-header">
                        <div>
                            <h3 id="wsAttCardTitle">Hearing Session Attendance</h3>
                            <p id="wsAttCardSubtitle">Record presider availability, party appearance verification, and statutory attendance rules</p>
                        </div>
                    </div>
                    <div id="wsAttCardBody" style="padding: 18px 24px;">
                        <div class="empty-detail-state">Loading attendance records...</div>
                    </div>
                </div>

                <!-- Section 2.6: Pangkat Show-Cause & Non-Appearance Proceedings Card -->
                <div class="stage-section-card" id="pangkatShowCauseSection" style="margin-top: 20px;">
                    <div class="stage-section-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="background: #ef4444; color: #fff; font-size: 0.72rem; font-weight: 800; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;">KP Forms 18 &amp; 19</span>
                                <h3 id="wsScCardTitle" style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #0f172a;">Pangkat Show-Cause &amp; Non-Appearance Proceedings</h3>
                            </div>
                            <p id="wsScCardSubtitle" style="margin: 4px 0 0; font-size: 0.85rem; color: #64748b;">
                                Statutory show-cause hearings before the Pangkat, justification evaluations, and consequence enforcement under RA 7160 Sec. 410 / Sec. 412
                            </p>
                        </div>
                        <div id="wsScHeaderActions" style="display: flex; gap: 8px; align-items: center;"></div>
                    </div>
                    <div id="wsScCardBody" style="padding: 18px 24px;">
                        <div class="empty-detail-state" style="text-align: center; padding: 20px 16px; color: #64748b;">
                            No non-appearance records or show-cause hearings for this conciliation stage.
                        </div>
                    </div>
                </div>

                <!-- Section 3: Hearing Session Minutes & Proceedings -->
                <div class="stage-section-card" id="minutesSection">
                    <div class="stage-section-header">
                        <div>
                            <h3>Hearing Session Minutes &amp; Proceedings</h3>
                            <p>Separated 1st, 2nd &amp; 3rd Conciliation Minutes &amp; Proceedings · Official records of panel deliberations, statements, agreed terms, and procedural outcome</p>
                        </div>
                    </div>
                    <div id="wsMinutesSectionContainer">
                        <div class="empty-detail-state">Loading conciliation session minutes &amp; proceedings...</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 1: Schedule Conciliation Hearing    -->
<!-- ========================================== -->
<div id="wsScheduleModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 640px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; padding: 0;">
        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; margin-bottom: 0;">
            <h2 id="wsSchedModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Schedule Conciliation Session</h2>
            <button type="button" class="close-btn" onclick="closeScheduleModal()">&times;</button>
        </div>
        <form id="wsScheduleForm" onsubmit="submitSchedule(event)" style="display: flex; flex-direction: column; flex: 1; overflow-y: auto;">
            <input type="hidden" id="schedHearingType" value="Conciliation">
            <input type="hidden" id="schedCaseId" value="<?php echo htmlspecialchars($caseId); ?>">
            <input type="hidden" id="schedComplaintId" value="<?php echo htmlspecialchars($complaintId); ?>">

            <div style="padding: 16px 24px 20px; flex: 1;">
                <p id="wsSchedModalSubtitle" style="margin: 0 0 12px; font-size: 0.85rem; color: #475569; line-height: 1.5;">
                    In accordance with KP rules, scheduling a conciliation hearing registers the session in the central calendar and generates <strong>KP Form 12 (Notice of Hearing before the Pangkat)</strong>.
                </p>

                <div id="schedStageRuleHint" style="padding: 10px 14px; border-radius: 6px; font-size: 0.82rem; margin-bottom: 14px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; display: flex; align-items: flex-start; gap: 8px;">
                    
                    <div>
                        <strong>Barangay Tumana Rule:</strong> Conciliation hearings before the Pangkat are held on Mondays, Wednesdays, Thursdays, and Fridays (8:00 AM – 12:00 PM &amp; 1:00 PM – 5:00 PM) and Tuesday Mornings (8:00 AM – 12:00 PM). (Tuesday afternoons are exclusively reserved for Formal Mediation).
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label for="schedDate" style="margin: 0; font-weight: 600; font-size: 0.86rem;">
                            <span id="schedDateLabel">Conciliation Date</span> <span class="required-mark" aria-hidden="true">*</span>
                        </label>
                        <span id="schedWindowStatutoryBadge" style="display: none; font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 600;"></span>
                    </div>

                    <input type="date" id="schedDate" name="hearing_date" required style="margin-bottom: 8px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box; background: #fff;">

                    <!-- Visual Calendar & Capacity Strip -->
                    <div id="schedVisualDatePickerContainer" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.78rem; flex-wrap: wrap; gap: 6px;">
                            <span id="schedVisualDeckTitle" style="font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.74rem;">
                                AVAILABLE CONCILIATION DAYS (MON, TUE AM, WED, THU, FRI):
                            </span>
                            <div style="display: flex; gap: 10px; font-size: 0.72rem; flex-wrap: wrap;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #166534;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></span> Available</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #991b1b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444;"></span> Full</span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #64748b;"><span style="width: 8px; height: 8px; border-radius: 50%; background: #94a3b8;"></span> Closed</span>
                            </div>
                        </div>

                        <!-- Scrollable Day Cards Grid -->
                        <div id="schedVisualDayGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 6px; max-height: 155px; overflow-y: auto; padding: 4px 2px;">
                            <div style="text-align: center; color: #64748b; font-size: 0.78rem; padding: 12px; grid-column: 1 / -1;">
                                Loading conciliation availability &amp; capacity...
                            </div>
                        </div>
                        <div id="schedVisualDateStatusNotice" style="margin-top: 6px; font-size: 0.75rem; color: #64748b; line-height: 1.4;"></div>
                    </div>
                </div>

                <!-- Existing Hearings on selected date -->
                <div id="schedDaySchedulesWrap" style="display: none; margin-bottom: 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.84rem;">
                    <div style="font-weight: 600; color: #334155; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                        <span id="schedDaySchedulesTitle">Scheduled Conciliations on this Date:</span>
                        <span id="schedDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>
                    </div>
                    <div id="schedDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">
                        No other hearings scheduled on this day.
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="schedTime"><span id="schedTimeLabel">Conciliation Time</span> <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="time" id="schedTime" name="hearing_time" value="09:00" min="08:00" max="17:00" step="1800" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box; background: #fff;">
                    <small id="schedTimeHint" class="field-hint" style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Regular Conciliation hours: Mon/Wed/Thu/Fri 8:00 AM – 12:00 PM &amp; 1:00 PM – 5:00 PM; Tuesday 8:00 AM – 12:00 PM (Morning only). Standard duration is 1 hour.</small>
                </div>

                <!-- Conflict Alert Box -->
                <div id="schedScheduleConflictAlert" class="alert alert-danger" style="display: none; margin: 0 0 14px; padding: 10px 12px; font-size: 0.84rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; line-height: 1.4;">
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="schedVenue"><span id="schedVenueLabel">Venue</span> <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="text" id="schedVenue" name="venue" maxlength="255" value="Lupon Court Room" readonly required style="background: #f8fafc; cursor: not-allowed; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">
                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Conciliation sessions share the Lupon Court Room during operating windows.</small>
                </div>

                <div class="form-group" id="schedPresidingOfficerGroup" style="display: none; margin-bottom: 14px;">
                    <label for="schedPresidingOfficer"><span id="schedPresidingOfficerLabel">Presiding Officer</span></label>
                    <input type="text" id="schedPresidingOfficer" readonly value="Punong Barangay / Pangkat Chairman" style="background: #f8fafc; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">
                </div>

                <div class="form-group" style="margin-bottom: 6px;">
                    <label for="schedRemarks"><span id="schedRemarksLabel">Session Instructions / Remarks</span> <span class="optional-label">Optional</span></label>
                    <textarea id="schedRemarks" name="remarks" maxlength="5000" rows="2" placeholder="Optional conciliation session instructions or notes..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
                </div>

                <?php if (!empty($canAuthorizePastDate)): ?>
                <div class="form-group" style="margin-top: 10px;">
                    <label for="schedPastDateExceptionReason">Administrator past-date exception reason</label>
                    <textarea id="schedPastDateExceptionReason" rows="2" maxlength="2000" placeholder="Required only when scheduling a past date or time (10–2,000 characters)." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>
                    <small style="color: #64748b; font-size: 0.75rem;">The reason is saved with the hearing and audit history.</small>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-actions" style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; margin-top: 0; flex-shrink: 0; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closeScheduleModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitSchedBtn">Schedule Conciliation Hearing</button>
            </div>
            <p id="schedMessage" role="status" style="margin: 0; padding: 0 24px 8px; font-size: 0.84rem;"></p>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 2: Record Attendance                -->
<!-- ========================================== -->
<div id="wsAttendanceModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h2 id="wsAttModalTitle">Record Conciliation Attendance</h2>
            <button type="button" class="close-btn" onclick="closeAttendanceModal()">&times;</button>
        </div>
        <div id="wsAttModalBody">
            <div class="empty-detail-state">Loading party records...</div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 3: Record Conciliation Outcome      -->
<!-- ========================================== -->
<div id="wsRecordOutcomeModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2>Record Conciliation Outcome</h2>
            <button type="button" class="close-btn" onclick="closeRecordOutcomeModal()">&times;</button>
        </div>
        <form onsubmit="submitRecordOutcome(event)">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Select the formal procedural outcome of the conciliation stage.
            </p>
            <div class="form-group">
                <label for="stageOutcomeSelect">Procedural Outcome <span class="required-mark">*</span></label>
                <select id="stageOutcomeSelect" required onchange="handleEmbeddedStageOutcomeChange(this.value)" style="padding: 9px;">
                    <option value="Settled">Settled — Parties Reached Amicable Settlement</option>
                    <option value="Unsuccessful">Unsuccessful — Conciliation Failed (Eligible for CFA)</option>
                    <option value="Arbitration">Submit to Voluntary Arbitration (KP Form 14)</option>
                    <option value="Dismissed">Dismissed — Complainant Wilfully Failed to Appear</option>
                </select>
            </div>
            <div id="stageOutcomeArbitrationBanner" style="display: none; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin: 0 0 16px;">
                <strong style="color: #92400e; font-size: 0.95rem; display: block;">Parties Agreed to Voluntary Arbitration (KP Form 14)</strong>
                <p style="margin: 4px 0 10px; font-size: 0.84rem; color: #78350f;">Both parties have voluntarily agreed in writing to submit their dispute to binding arbitration under RA 7160 Sec. 413.</p>
                <button type="button" class="btn-create" onclick="issueArbitrationOutcome()" style="background: #d97706; border-color: #d97706;">Issue Arbitration &rarr;</button>
            </div>
            <div class="form-group">
                <label for="stageOutcomeRemarks">Determination Notes / Remarks <span class="optional-label">Optional</span></label>
                <textarea id="stageOutcomeRemarks" rows="3" placeholder="Summary of terms, reasons for failure, or basis of determination..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeRecordOutcomeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitOutcomeBtn">Confirm &amp; Record Outcome</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 4: Pangkat Extension                -->
<!-- ========================================== -->
<div id="wsPangkatExtensionModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 540px;">
        <div class="modal-header">
            <h2>Pangkat Conciliation Extension</h2>
            <button type="button" class="close-btn" onclick="closePangkatExtensionModal()">&times;</button>
        </div>
        <form onsubmit="submitPangkatExtension(event)">
            <p style="margin: 0 0 14px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Pursuant to <strong>RA 7160 Sec. 410(c)</strong>, the Pangkat may extend the 15-day conciliation period for a discretionary period not exceeding 15 days, or for a clearly meritorious exception.
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                <div style="font-size: 0.82rem; color: #64748b; margin-bottom: 4px;">CURRENT CONCILIATION DEADLINE</div>
                <div id="extCurrentDeadline" style="font-size: 1rem; font-weight: 700; color: #0f172a;">Loading...</div>
            </div>
            <div class="form-group">
                <label style="font-weight: 700; display: block; margin-bottom: 8px;">Extension Type <span class="required-mark">*</span></label>
                <div style="display: flex; gap: 16px;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.88rem;">
                        <input type="radio" name="ext_type" value="Discretionary" checked onchange="toggleExtensionType(this.value)">
                        <span>Discretionary (Up to 15 days)</span>
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.88rem;">
                        <input type="radio" name="ext_type" value="Clearly Meritorious" onchange="toggleExtensionType(this.value)">
                        <span>Clearly Meritorious Exception</span>
                    </label>
                </div>
                <small id="extTypeNote" style="display: block; margin-top: 6px; font-size: 0.78rem; color: #64748b;">
                    Under RA 7160 Sec. 410(c), discretionary extension by the Pangkat cannot exceed fifteen (15) calendar days.
                </small>
            </div>
            <div class="form-group">
                <label for="extDays">Days to Extend <span class="required-mark">*</span></label>
                <input type="number" id="extDays" min="1" max="90" value="15" required oninput="updateProjectedDeadline()">
                <small style="display: block; margin-top: 4px; font-size: 0.78rem; color: #64748b;">
                    Projected New Deadline: <strong id="extProjectedDeadline" style="color: #0369a1;">Calculating...</strong>
                </small>
            </div>
            <div class="form-group" id="extMeritoriousWrap" style="display: none;">
                <label for="extMeritoriousBasis">Clearly Meritorious Basis <span class="required-mark">*</span></label>
                <textarea id="extMeritoriousBasis" rows="2" placeholder="Specify statutory or procedural basis (e.g. pending land survey, illness of principal, formal request for court transmittal)..."></textarea>
            </div>
            <div class="form-group">
                <label for="extJustification">Extension Justification / Panel Notes <span class="required-mark">*</span></label>
                <textarea id="extJustification" rows="3" required placeholder="Detailed reason for extending the conciliation period..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closePangkatExtensionModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitExtBtn">Grant Statutory Extension</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 5: Challenge Member                 -->
<!-- ========================================== -->
<div id="wsChallengeMemberModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2>Challenge Pangkat Member</h2>
            <button type="button" class="close-btn" onclick="closeChallengeMemberModal()">&times;</button>
        </div>
        <form onsubmit="submitChallengeMember(event)">
            <p style="margin: 0 0 14px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Under <strong>RA 7160 Sec. 404(d)</strong>, any party may challenge a Pangkat member on grounds of relationship, bias, interest, or other reasons discovered after constitution.
            </p>
            <div class="form-group">
                <label for="chalMemberId">Pangkat Member to Challenge <span class="required-mark">*</span></label>
                <select id="chalMemberId" required style="padding: 9px;"></select>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label for="chalParty">Challenger Party <span class="required-mark">*</span></label>
                    <select id="chalParty" required style="padding: 9px;">
                        <option value="Complainant">Complainant</option>
                        <option value="Respondent">Respondent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="chalDate">Challenge Date <span class="required-mark">*</span></label>
                    <input type="date" id="chalDate" required>
                </div>
            </div>
            <div class="form-group">
                <label for="chalPartyName">Challenger Representative / Name <span class="optional-label">Optional</span></label>
                <input type="text" id="chalPartyName" placeholder="Full name of person raising objection...">
            </div>
            <div class="form-group">
                <label for="chalGrounds">Grounds for Disqualification <span class="required-mark">*</span></label>
                <select id="chalGrounds" required style="padding: 9px;">
                    <option value="Bias or Partiality">Bias or Partiality</option>
                    <option value="Relationship within 4th Degree of Consanguinity or Affinity">Relationship within 4th Degree of Consanguinity or Affinity</option>
                    <option value="Financial or Material Interest">Financial or Material Interest</option>
                    <option value="Other Meritorious Grounds">Other Meritorious Grounds</option>
                </select>
            </div>
            <div class="form-group">
                <label for="chalDetails">Particulars / Factual Basis <span class="required-mark">*</span></label>
                <textarea id="chalDetails" rows="3" required placeholder="Specify facts, incidents, or relationship details giving rise to the challenge..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeChallengeMemberModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitChalBtn" style="background: #dc2626;">File Member Challenge</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 6: Resolve Challenge                -->
<!-- ========================================== -->
<div id="wsResolveChallengeModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 560px;">
        <div class="modal-header">
            <h2>Resolve Pangkat Challenge</h2>
            <button type="button" class="close-btn" onclick="closeResolveChallengeModal()">&times;</button>
        </div>
        <form onsubmit="submitResolveChallenge(event)">
            <input type="hidden" id="resChallengeId">
            <p style="margin: 0 0 12px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Under <strong>RA 7160 Sec. 404(d)</strong>, challenges are resolved by <em>majority vote</em> of the Pangkat members. If sustained, the vacancy is filled immediately from the Lupon roster.
            </p>
            <div id="resChalSummary" style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 0.84rem; color: #991b1b; line-height: 1.5;">
                Loading challenge details...
            </div>
            <div class="form-group">
                <label style="font-weight: 700; display: block; margin-bottom: 8px;">Pangkat Majority Decision <span class="required-mark">*</span></label>
                <div style="display: flex; gap: 16px;">
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.88rem;">
                        <input type="radio" name="res_decision" value="Disqualify and Replace" checked onchange="toggleDecisionReplacement(this.value)">
                        <span style="font-weight: 600; color: #dc2626;">Sustain &amp; Disqualify (Replace Member)</span>
                    </label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.88rem;">
                        <input type="radio" name="res_decision" value="Dismiss Challenge" onchange="toggleDecisionReplacement(this.value)">
                        <span style="font-weight: 600; color: #166534;">Dismiss Challenge (Retain Member)</span>
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label for="resVoting">Panel Voting Record <span class="required-mark">*</span></label>
                <input type="text" id="resVoting" value="2-0 Majority Vote (Remaining Members Concur)" required>
            </div>
            <div class="form-group">
                <label for="resNotes">Deliberation Findings &amp; Resolution Notes <span class="optional-label">Optional</span></label>
                <textarea id="resNotes" rows="2" placeholder="Summary of Pangkat deliberations and ruling rationale..."></textarea>
            </div>
            <div id="resReplacementWrap" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <h4 style="font-size: 0.86rem; color: #166534; margin: 0 0 10px; font-weight: 700;">
                    Designate Replacement Member (RA 7160 §404d)
                </h4>
                <div class="form-group" style="margin-bottom: 10px;">
                    <label for="resReplacementId">Replacement Lupon Member <span class="required-mark">*</span></label>
                    <select id="resReplacementId" style="padding: 9px;"></select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="resRepMethod">Replacement Method <span class="required-mark">*</span></label>
                    <select id="resRepMethod" style="padding: 9px;">
                        <option value="Drawing of Lots">Drawing of Lots (Raffle by Punong Barangay)</option>
                        <option value="PB Assignment">Punong Barangay Direct Appointment</option>
                        <option value="Party Agreement">Agreement by the Parties</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeResolveChallengeModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitResBtn">Record Majority Resolution</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 7: Pangkat Panel Selection & Impasse -->
<!-- ========================================== -->
<div id="wsPangkatSelectionModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 580px;">
        <div class="modal-header">
            <h2>Pangkat Selection &amp; Constitution</h2>
            <button type="button" class="close-btn" onclick="closePangkatSelectionModal()">&times;</button>
        </div>
        <form onsubmit="submitPangkatSelection(event)">
            <p style="margin: 0 0 14px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                Under <strong>RA 7160 Sec. 404</strong>, parties choose 3 panel members from the Lupon roster. If parties fail to agree, the Punong Barangay fills the panel by drawing of lots or appointment.
            </p>
            <div class="form-group">
                <label for="selChairmanId">Pangkat Chairperson <span class="required-mark">*</span></label>
                <select id="selChairmanId" required style="padding: 9px;"></select>
            </div>
            <div class="form-group">
                <label for="selSecretaryId">Pangkat Secretary <span class="required-mark">*</span></label>
                <select id="selSecretaryId" required style="padding: 9px;"></select>
            </div>
            <div class="form-group">
                <label for="selMemberId">Pangkat Member <span class="required-mark">*</span></label>
                <select id="selMemberId" required style="padding: 9px;"></select>
            </div>
            <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">
                <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.86rem; font-weight: 700; color: #92400e;">
                    <input type="checkbox" id="selAgreementFailed" onchange="togglePartyAgreementFailed(this)" style="width: 16px; height: 16px;">
                    <span>Parties Failed to Reach Agreement on Panel Selection</span>
                </label>
                <div id="selFailureWrap" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #fde68a;">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="selPbMethod" style="font-size: 0.82rem;">Punong Barangay Selection Method <span class="required-mark">*</span></label>
                        <select id="selPbMethod" style="padding: 8px;">
                            <option value="Drawing of Lots">Drawing of Lots (Raffle pursuant to RA 7160 §404b)</option>
                            <option value="PB Appointment">Direct Appointment by Punong Barangay</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="selFailureReason" style="font-size: 0.82rem;">Impasse / Disagreement Notes</label>
                        <input type="text" id="selFailureReason" placeholder="e.g. Parties struck out mutually acceptable members; deadlock on 3rd panelist...">
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="selNotes">Constitution Notes / Remarks <span class="optional-label">Optional</span></label>
                <textarea id="selNotes" rows="2" placeholder="Administrative notes regarding panel constitution or party presence..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closePangkatSelectionModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitSelBtn">Save Panel Constitution</button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================= -->
<!-- MODAL 8: Evaluate Show-Cause Hearing (Pangkat / KP Rules) -->
<!-- ======================================================= -->
<div id="wsEvaluateShowCauseModal" class="modal" style="display: none; align-items: center; justify-content: center;">
    <div class="modal-content" style="max-width: 620px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; padding: 0;">
        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; margin-bottom: 0;">
            <div>
                <h2 id="wsScEvalModalTitle" style="font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0;">
                    Evaluate Show-Cause Hearing before the Pangkat
                </h2>
                <p id="wsScEvalModalSub" style="margin: 2px 0 0; font-size: 0.8rem; color: #64748b;">
                    Determine whether party non-appearance was legally justified or unjustified under RA 7160
                </p>
            </div>
            <button type="button" class="close-btn" onclick="closeEvaluateShowCauseModal()">&times;</button>
        </div>
        <form id="wsEvaluateShowCauseForm" onsubmit="submitShowCauseEvaluation(event)" style="display: flex; flex-direction: column; flex: 1; overflow-y: auto; padding: 20px 24px; gap: 16px;">
            <input type="hidden" id="scEvalHearingId" name="hearing_id" value="">
            <input type="hidden" id="scEvalCaseId" name="case_id" value="<?php echo htmlspecialchars($caseId); ?>">

            <!-- Hearing Info Banner -->
            <div id="scEvalHearingSummary" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 14px; font-size: 0.84rem; color: #334155;">
                Loading hearing details...
            </div>

            <!-- Party Selection -->
            <div class="form-group" style="margin-bottom: 0;">
                <label for="scEvalPartyType" style="display: block; font-weight: 700; font-size: 0.85rem; color: #1e293b; margin-bottom: 6px;">
                    Party Being Evaluated <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <select id="scEvalPartyType" name="party_type" required onchange="handleScPartyTypeChange(this.value)" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem;">
                    <option value="Complainant">Complainant (Nagrereklamo — KP Form 18)</option>
                    <option value="Respondent">Respondent (Ipinagsusumbong — KP Form 19)</option>
                    <option value="Both">Both Parties (Dual Non-Appearance / Joint Default)</option>
                </select>
            </div>

            <!-- Justification Finding: Radio Group -->
            <div class="form-group" style="margin-bottom: 0;">
                <label style="display: block; font-weight: 700; font-size: 0.85rem; color: #1e293b; margin-bottom: 6px;">
                    Pangkat Finding / Justification Ruling <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <label id="scRulingJustifiedLabel" style="border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 12px; cursor: pointer; display: flex; flex-direction: column; gap: 4px; background: #fff;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="is_justified" id="scRulingJustified" value="1" onchange="handleScRulingChange(1)" required>
                            <span style="font-weight: 700; color: #15803d; font-size: 0.88rem;">Justified Absence</span>
                        </div>
                        <span style="font-size: 0.76rem; color: #64748b; margin-left: 22px;">Valid excuse accepted. Reschedule conciliation session without statutory sanctions.</span>
                    </label>
                    <label id="scRulingUnjustifiedLabel" style="border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 12px; cursor: pointer; display: flex; flex-direction: column; gap: 4px; background: #fff;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="is_justified" id="scRulingUnjustified" value="0" onchange="handleScRulingChange(0)">
                            <span style="font-weight: 700; color: #b91c1c; font-size: 0.88rem;">Unjustified Absence</span>
                        </div>
                        <span style="font-size: 0.76rem; color: #64748b; margin-left: 22px;">Willful refusal or unexcused default. Enforce statutory bar / issue CFA immediately.</span>
                    </label>
                </div>
            </div>

            <!-- Justification Category Dropdown -->
            <div class="form-group" style="margin-bottom: 0;">
                <label for="scEvalCategory" style="display: block; font-weight: 700; font-size: 0.85rem; color: #1e293b; margin-bottom: 6px;">
                    Justification Category <span class="required-mark" style="color: #dc2626;">*</span>
                </label>
                <select id="scEvalCategory" name="justification_category" required onchange="updateScConsequencePreview()" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem;">
                    <option value="Unjustified Absence">Unjustified Absence / Failure to Provide Valid Reason</option>
                    <option value="Willful Refusal">Willful Refusal / Deliberate Non-Appearance</option>
                    <option value="Medical Emergency">Medical Emergency / Valid Illness</option>
                    <option value="Force Majeure">Force Majeure / Calamity / Act of God</option>
                    <option value="Official Duty">Official Duty / Subpoenaed in Court</option>
                    <option value="Other">Other Category</option>
                </select>
            </div>

            <!-- Reschedule Fields (shown when Justified is selected) -->
            <div id="scRescheduleGroup" style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 14px;">
                <div style="font-weight: 700; font-size: 0.82rem; color: #166534; margin-bottom: 8px;">
                    Reschedule Conciliation Session (Required for Justified Finding)
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div>
                        <label for="scReschedDate" style="display: block; font-size: 0.78rem; font-weight: 600; color: #374151; margin-bottom: 4px;">Hearing Date &amp; Time *</label>
                        <input type="datetime-local" id="scReschedDate" name="reschedule_date" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;">
                    </div>
                    <div>
                        <label for="scReschedVenue" style="display: block; font-size: 0.78rem; font-weight: 600; color: #374151; margin-bottom: 4px;">Venue</label>
                        <input type="text" id="scReschedVenue" name="reschedule_venue" value="Barangay Hall / Lupon Room" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;">
                    </div>
                </div>
            </div>

            <!-- Dynamic Consequence Preview Box -->
            <div id="scDynamicConsequencePreview" style="background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 12px 14px; font-size: 0.82rem;"></div>

            <!-- Deliberation Notes -->
            <div class="form-group" style="margin-bottom: 0;">
                <label for="scEvalNotes" style="display: block; font-weight: 700; font-size: 0.85rem; color: #1e293b; margin-bottom: 6px;">
                    Pangkat Deliberation Notes &amp; Findings
                </label>
                <textarea id="scEvalNotes" name="justification_notes" rows="3" placeholder="Enter findings of the Pangkat ng Tagapagkasundo regarding party explanation, proof submitted, or lack thereof..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; resize: vertical;"></textarea>
            </div>

            <!-- Modal Actions -->
            <div class="modal-actions" style="margin-top: 0; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn-secondary" onclick="closeEvaluateShowCauseModal()">Cancel</button>
                <button type="submit" id="btnSubmitScEvaluation" class="btn-create" style="background: #dc2626; border-color: #dc2626; color: #fff; font-weight: 700;">
                    Confirm &amp; Enforce Ruling
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../layouts/transmittal-modal.php'; ?>
<?php include __DIR__ . '/../../layouts/kp-form-preview-modal.php'; ?>

<script src="../../assets/js/stage-workspace.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/stage-workspace.js'); ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        initStageWorkspace({
            mode: 'Conciliation',
            caseId: <?php echo json_encode($caseId); ?>,
            complaintId: <?php echo json_encode($complaintId); ?>
        });
    });
</script>

<?php include '../../layouts/footer.php'; ?>
