<?php



session_start();



$roleId = (int) ($_SESSION['role_id'] ?? 0);

if (!in_array($roleId, [1, 2, 3], true)) {

    die('Access Denied');

}

$canAuthorizePastDate = $roleId === 1;



require_once __DIR__ . '/../../../backend/config/database.php';

$db = (new Database())->connect();



$caseId = filter_var($_GET['case_id'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

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

                <span id="wsBreadcrumbStage" style="color: #0f172a; font-weight: 600;">Mediation</span>

            </div>



            <!-- Page Header -->

            <div class="page-header" style="padding: 0 0 20px 0; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0;">

                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">

                    <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 4px;">

                        STAGE 1 OF 2

                    </span>

                    <h1 id="wsStageTitle" style="margin: 0; font-size: 1.45rem; color: #0f172a; font-weight: 800;">

                        Mediation

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

                </div>

            </div>



            <!-- Content Container -->

            <div id="stageWorkspaceContent">

                <!-- Section 1: Stage Overview -->

                <div class="overview-grid" id="wsStageOverviewGrid">

                    <div class="overview-stat-card"><span class="stat-label">Loading Overview...</span></div>

                </div>



                <!-- Session Switcher: 1st Mediation vs 2nd Mediation (Distinct Page Views) -->
                <div id="wsSessionNavContainer" style="margin-bottom: 20px;"></div>

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



<!-- Section 3: Hearing Session Minutes & Proceedings -->

                <div class="stage-section-card" id="minutesSection">

                    <div class="stage-section-header">

                        <div>

                            <h3>Hearing Session Minutes &amp; Proceedings</h3>

                        </div>

                    </div>

                    <div id="wsMinutesSectionContainer">

                        <div class="empty-detail-state">Loading session minutes &amp; proceedings...</div>

                    </div>

                </div>

            </div>



        </div>

    </div>

</div>



<!-- ========================================== -->

<!-- MODAL 1: Schedule Mediation Hearing       -->

<!-- ========================================== -->

<div id="wsScheduleModal" class="modal" style="display: none;">

    <div class="modal-content" style="max-width: 580px; max-height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 10px; overflow: hidden;">

        <div class="modal-header" style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0; background: #fff; margin-bottom: 0; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;">

            <h2 id="wsSchedModalTitle" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin: 0;">Schedule 2nd Mediation Session</h2>

            <button type="button" class="close-btn" onclick="closeScheduleModal()">&times;</button>

        </div>

        <form id="wsScheduleForm" onsubmit="submitSchedule(event)" style="display: flex; flex-direction: column; overflow: hidden; margin: 0;">

            <input type="hidden" id="schedHearingType" value="Mediation">

            <input type="hidden" id="schedComplaintId" value="<?php echo (int) $complaintId; ?>">

            <input type="hidden" id="schedCaseId" value="<?php echo (int) $caseId; ?>">

            <div class="modal-body" style="padding: 18px 24px; overflow-y: auto; flex: 1;">

                <p id="wsSchedModalSubtitle" style="color: #64748b; font-size: 0.85rem; margin-top: 0; margin-bottom: 14px; line-height: 1.45;">

                    Enter the date and time to schedule the 2nd mediation session immediately. <strong>RA 7160 Sec. 410 Notice Exemption:</strong> Because both parties are present in this session and notified, prior personal notice is legally recognized and no redundant summons (KP Form 9) is generated.

                </p>



                <div class="form-group" style="margin-bottom: 14px;">

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">

                        <label for="schedDate" style="margin-bottom: 0; font-weight: 700; color: #1e293b;">

                            <span id="schedDateLabel">2nd Mediation Date</span> <span class="required-mark" aria-hidden="true">*</span>

                        </label>

                        <span id="schedWindowStatutoryBadge" style="font-size: 0.72rem; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">

                            15-Working-Day Window (RA 7160 Sec. 410)

                        </span>

                    </div>



                    <input type="date" id="schedDate" name="hearing_date" required style="margin-bottom: 8px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box; background: #fff;">



                    <!-- Visual 15-Working-Day Calendar & Capacity Strip -->

                    <div id="schedVisualDatePickerContainer" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">

                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 0.78rem; flex-wrap: wrap; gap: 6px;">

                            <span style="font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em; font-size: 0.74rem;">

                                AVAILABLE MEDIATION DAYS (MAX 8 HEARINGS/DAY):

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



                <!-- Existing Mediations on selected date -->

                <div id="schedDaySchedulesWrap" style="display: none; margin-bottom: 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.84rem;">

                    <div style="font-weight: 600; color: #334155; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">

                        <span>Scheduled Mediations on this Date:</span>

                        <span id="schedDaySlotsCount" style="font-size: 0.72rem; background: #e2e8f0; color: #475569; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">0</span>

                    </div>

                    <div id="schedDaySlotsList" style="color: #64748b; font-size: 0.82rem; line-height: 1.5;">

                        No other mediations scheduled on this day.

                    </div>

                </div>



                <div class="form-group" style="margin-bottom: 14px;">

                    <label for="schedTime"><span id="schedTimeLabel">2nd Mediation Time</span> <span class="required-mark" aria-hidden="true">*</span></label>

                    <input type="time" id="schedTime" name="hearing_time" value="09:00" min="08:00" max="17:00" step="1800" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box; background: #fff;">

                    <small class="field-hint" style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Regular Lupon hearing hours are 8:00 AM to 5:00 PM (Monday to Friday). Standard duration is 1 hour.</small>

                </div>



                <!-- Conflict Alert Box -->

                <div id="schedScheduleConflictAlert" class="alert alert-danger" style="display: none; margin: 0 0 14px; padding: 10px 12px; font-size: 0.84rem; border-radius: 6px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; line-height: 1.4;">

                </div>



                <div class="form-group" style="margin-bottom: 14px;">

                    <label for="schedVenue"><span id="schedVenueLabel">Venue</span> <span class="required-mark" aria-hidden="true">*</span></label>

                    <input type="text" id="schedVenue" name="venue" maxlength="255" value="Lupon Court Room" readonly required style="background: #f8fafc; cursor: not-allowed; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">

                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Mediation sessions are conducted exclusively in the Lupon Court Room.</small>

                </div>



                <div class="form-group" id="schedPresidingOfficerGroup" style="display: none; margin-bottom: 14px;">

                    <label for="schedPresidingOfficer"><span id="schedPresidingOfficerLabel">Presiding Officer</span></label>

                    <input type="text" id="schedPresidingOfficer" readonly value="Punong Barangay / Pangkat Chairman" style="background: #f8fafc; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">

                </div>



                <div class="form-group" style="margin-bottom: 6px;">

                    <label for="schedRemarks"><span id="schedRemarksLabel">Remarks</span> <span class="optional-label">Optional</span></label>

                    <textarea id="schedRemarks" name="remarks" maxlength="5000" rows="2" placeholder="Optional mediation instructions or notes..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.84rem; box-sizing: border-box;"></textarea>

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

                <button type="button" class="btn-secondary" onclick="closeScheduleModal()">Cancel</button>

                <button type="submit" class="btn-create" id="submitSchedBtn">Schedule 2nd Mediation</button>

            </div>

            <p id="schedMessage" role="status" style="margin: 0; padding: 0 24px 8px; font-size: 0.84rem;"></p>

        </form>

    </div>

</div>



<!-- ========================================== -->

<!-- MODAL 3: Record Stage Outcome             -->

<!-- ========================================== -->

<div id="wsRecordOutcomeModal" class="modal" style="display: none;">

    <div class="modal-content" style="max-width: 520px;">

        <div class="modal-header">

            <h2>Record Mediation Outcome</h2>

            <button type="button" class="close-btn" onclick="closeRecordOutcomeModal()">&times;</button>

        </div>

        <form onsubmit="submitRecordOutcome(event)">

            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">

                Select the formal procedural determination for this mediation stage.

            </p>

            <div class="form-group">

                <label for="stageOutcomeSelect">Procedural Outcome <span class="required-mark">*</span></label>

                <select id="stageOutcomeSelect" required onchange="handleEmbeddedStageOutcomeChange(this.value)" style="padding: 9px;">

                    <option value="Settled">Settled — Parties Reached Amicable Settlement</option>

                    <option value="Unsuccessful">Unsuccessful — Impasse / Referred to Pangkat</option>

                    <option value="Arbitration">Submit to Voluntary Arbitration (KP Form 14)</option>

                    <option value="Dismissed">Dismissed — Complainant Wilfully Failed to Appear</option>

                    <option value="Repudiated">Repudiated — Settlement Repudiated by Party</option>

                </select>

            </div>

            <div id="stageOutcomeArbitrationBanner" style="display: none; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin: 0 0 16px;">

                <strong style="color: #92400e; font-size: 0.95rem; display: block;">Parties Agreed to Voluntary Arbitration (KP Form 14)</strong>

                <p style="margin: 4px 0 10px; font-size: 0.84rem; color: #78350f;">Both parties have voluntarily agreed in writing to submit their dispute to binding arbitration under RA 7160 Sec. 413.</p>

                <button type="button" class="btn-create" onclick="issueArbitrationOutcome()" style="background: #d97706; border-color: #d97706;">Issue Arbitration &rarr;</button>

            </div>

            <div class="form-group">

                <label for="stageOutcomeRemarks">Determination Notes / Remarks <span class="optional-label">Optional</span></label>

                <textarea id="stageOutcomeRemarks" rows="3" placeholder="Summary of agreements reached, reasons for failure, or basis of determination..."></textarea>

            </div>

            <div class="modal-actions">

                <button type="button" class="btn-secondary" onclick="closeRecordOutcomeModal()">Cancel</button>

                <button type="submit" class="btn-create" id="submitOutcomeBtn">Confirm &amp; Record Outcome</button>

            </div>

        </form>

    </div>

</div>



<!-- ========================================== -->

<!-- MODAL 5: Refer to Pangkat Tagapagkasundo  -->

<!-- ========================================== -->

<div id="wsReferPangkatModal" class="modal" style="display: none;">

    <div class="modal-content" style="max-width: 720px; width: 95vw;">

        <div class="modal-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 14px;">

            <div>

                <h2 style="margin: 0; font-size: 1.25rem; font-weight: 800; color: #0f172a;">Team Assignment for Selected Case (Refer to Pangkat ng Tagapagkasundo &amp; Book 1st Conciliation)</h2>

                <p style="margin: 3px 0 0; font-size: 0.82rem; color: #64748b;">

                    Elevation to 3-member Pangkat panel &amp; simultaneous 1st Conciliation scheduling under RA 7160 Sec. 410(b)

                </p>

            </div>

            <button type="button" class="close-btn" onclick="closeReferPangkatModal()" style="font-size: 26px; line-height: 1; border: none; background: transparent; cursor: pointer; color: #64748b;">&times;</button>

        </div>



        <!-- Wizard Step Indicators -->

        <div style="display: flex; gap: 8px; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">

            <div id="referStepPill1" class="refer-step-pill active" style="flex: 1; padding: 6px 10px; border-radius: 6px; font-size: 0.76rem; font-weight: 700; background: #e0f2fe; color: #0369a1; text-align: center;">

                1. Book 1st Conciliation

            </div>

            <div id="referStepPill2" class="refer-step-pill" style="flex: 1; padding: 6px 10px; border-radius: 6px; font-size: 0.76rem; font-weight: 700; background: #f1f5f9; color: #64748b; text-align: center;">

                2. Pangkat Panel Nomination

            </div>

            <div id="referStepPill3" class="refer-step-pill" style="flex: 1; padding: 6px 10px; border-radius: 6px; font-size: 0.76rem; font-weight: 700; background: #f1f5f9; color: #64748b; text-align: center;">

                3. Review &amp; Confirm

            </div>

        </div>



        <form id="referPangkatForm" onsubmit="submitReferPangkat(event)">

            

            <!-- STEP 1: Book 1st Conciliation Hearing Schedule -->

            <div class="referral-step" data-referral-step="1">



                <!-- 1. TOP SECTION: Selected Conciliation Date & Hearing Venue (MOVED ABOVE) -->

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">

                    <div class="form-group" style="margin-bottom: 0;">

                        <label for="referConciliationDate" style="font-size: 0.82rem; font-weight: 700; color: #0f172a;">

                            Selected Conciliation Date <span class="required-mark" style="color: #dc2626;">*</span>

                        </label>

                        <input type="date" 

                               id="referConciliationDate" 

                               required 

                               style="padding: 9px 12px; width: 100%; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 0.88rem; box-sizing: border-box;" 

                               onchange="handleReferDateInputChange()">

                    </div>

                    <div class="form-group" style="margin-bottom: 0;">

                        <label for="referConciliationVenue" style="font-size: 0.82rem; font-weight: 700; color: #0f172a;">

                            Hearing Venue <span class="required-mark" style="color: #dc2626;">*</span>

                        </label>

                        <input type="text" 

                               id="referConciliationVenue" 

                               value="Lupon Court Room" 

                               required 

                               style="padding: 9px 12px; width: 100%; border: 1.5px solid #cbd5e1; border-radius: 7px; font-size: 0.88rem; box-sizing: border-box;" 

                               onchange="checkReferScheduleConflict()">

                    </div>

                </div>



                <!-- 2. MIDDLE SECTION: Visual Day Picker Cards & Month Slots Header -->

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;">

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">

                        <div style="display: flex; align-items: center; gap: 8px;">

                            <span style="font-size: 0.78rem; font-weight: 800; text-transform: uppercase; color: #1e293b; letter-spacing: 0.04em;">

                                Select Conciliation Date (Mon, Wed, Thu, Fri only)

                            </span>

                            <span id="referCalendarMonthLabel" style="font-size: 0.76rem; font-weight: 700; color: #0284c7;"></span>

                        </div>

                        <span id="referCourtroomCapacityBadge" style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: #dcfce7; color: #15803d;">

                            4 of 4 slots available

                        </span>

                    </div>



                    <!-- Visual Day Picker Grid -->

                    <div id="referVisualDayGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(78px, 1fr)); gap: 6px; margin-bottom: 8px;">

                        <div style="color: #64748b; font-size: 0.78rem; grid-column: 1 / -1; text-align: center; padding: 12px;">Loading available conciliation days...</div>

                    </div>

                    <div id="referVisualDateStatusNotice" style="font-size: 0.76rem; color: #475569;">

                        Click an open date card above. Tuesdays are exclusively reserved for Punong Barangay Mediation and are strictly disabled.

                    </div>

                </div>



                <!-- 3. BOTTOM SECTION: Conciliation Time Slot Selector -->

                <div class="form-group" style="margin-bottom: 14px;">

                    <label style="font-size: 0.82rem; font-weight: 700; display: block; margin-bottom: 6px;">

                        Conciliation Time Slot <span class="required-mark" style="color: #dc2626;">*</span>

                    </label>

                    <div id="referSlotBtnGroup" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">

                        <button type="button" class="refer-slot-btn active" data-time="09:00" onclick="selectReferConciliationSlot('09:00')" style="padding: 8px 6px; border: 1.5px solid #0284c7; background: #e0f2fe; color: #0369a1; border-radius: 6px; font-size: 0.78rem; font-weight: 700; cursor: pointer; text-align: center;">09:00 - 10:00 AM</button>

                        <button type="button" class="refer-slot-btn" data-time="10:00" onclick="selectReferConciliationSlot('10:00')" style="padding: 8px 6px; border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 6px; font-size: 0.78rem; font-weight: 700; cursor: pointer; text-align: center;">10:00 - 11:00 AM</button>

                        <button type="button" class="refer-slot-btn" data-time="14:00" onclick="selectReferConciliationSlot('14:00')" style="padding: 8px 6px; border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 6px; font-size: 0.78rem; font-weight: 700; cursor: pointer; text-align: center;">02:00 - 03:00 PM</button>

                        <button type="button" class="refer-slot-btn" data-time="15:00" onclick="selectReferConciliationSlot('15:00')" style="padding: 8px 6px; border: 1px solid #cbd5e1; background: #fff; color: #334155; border-radius: 6px; font-size: 0.78rem; font-weight: 700; cursor: pointer; text-align: center;">03:00 - 04:00 PM</button>

                    </div>

                    <input type="hidden" id="referConciliationTime" value="09:00" required>

                </div>



                <!-- Real-time Conflict Alert Banner -->

                <div id="referScheduleConflictAlert" style="display: none; background: #fee2e2; border: 1px solid #fecaca; border-left: 4px solid #dc2626; border-radius: 6px; padding: 10px 14px; margin-bottom: 12px; font-size: 0.82rem; color: #991b1b;"></div>



                <!-- Referral date & impasse notes -->

                <div style="display: grid; grid-template-columns: 180px 1fr; gap: 12px; margin-bottom: 14px;">

                    <div class="form-group" style="margin-bottom: 0;">

                        <label for="referDate" style="font-size: 0.8rem; font-weight: 600;">Referral Date</label>

                        <input type="date" id="referDate" required style="padding: 7px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;">

                    </div>

                    <div class="form-group" style="margin-bottom: 0;">

                        <label for="referReason" style="font-size: 0.8rem; font-weight: 600;">Referral Reason / Impasse Notes</label>

                        <input type="text" id="referReason" value="Mediation before the Punong Barangay failed. Elevated to Pangkat Conciliation." required style="padding: 7px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;">

                    </div>

                </div>



                <div class="modal-actions" style="margin-top: 10px; display: flex; justify-content: flex-end; gap: 8px;">

                    <button type="button" class="btn-secondary" onclick="closeReferPangkatModal()">Cancel</button>

                    <button type="button" class="btn-create" onclick="goReferralStep(2)" style="background: #0284c7;">Continue to Panel Nomination &rarr;</button>

                </div>

            </div>



            <!-- STEP 2: Team Assignment for Selected Case (Pangkat Nomination) -->

            <div class="referral-step" data-referral-step="2" style="display: none;">

                <div class="form-group" style="margin-bottom: 12px;">

                    <label for="referSelectionMethod" style="font-size: 0.84rem; font-weight: 700;">Pangkat Selection Method</label>

                    <select id="referSelectionMethod" style="padding: 8px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem;">

                        <option value="Party Agreement">Party Agreement (Parties chose from Lupon pool)</option>

                        <option value="PB Assignment">Punong Barangay Assignment</option>

                        <option value="Raffle Draw">Raffle Draw (Lots drawn by PB)</option>

                    </select>

                </div>



                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">

                    <span style="font-size: 0.82rem; font-weight: 700; color: #1e293b; text-transform: uppercase; display: block; margin-bottom: 10px;">

                        Nominate 3 Active Lupon Members (Excluding Administrator / PB)

                    </span>

                    <div class="form-group" style="margin-bottom: 10px;">

                        <label for="referChairmanId" style="font-size: 0.82rem;">1. Chairperson / Head <span class="required-mark">*</span></label>

                        <select id="referChairmanId" required style="padding: 7px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem;"></select>

                    </div>

                    <div class="form-group" style="margin-bottom: 10px;">

                        <label for="referSecretaryId" style="font-size: 0.82rem;">2. Pangkat Secretary <span class="required-mark">*</span></label>

                        <select id="referSecretaryId" required style="padding: 7px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem;"></select>

                    </div>

                    <div class="form-group" style="margin-bottom: 10px;">

                        <label for="referMemberId" style="font-size: 0.82rem;">3. Regular Member <span class="required-mark">*</span></label>

                        <select id="referMemberId" required style="padding: 7px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.86rem;"></select>

                    </div>

                    <div id="referPanelError" style="display: none; color: #dc2626; font-size: 0.78rem; font-weight: 600; margin-bottom: 8px;"></div>

                    <div class="form-group" style="margin-bottom: 10px;">

                        <label for="referSelectionNotes" style="font-size: 0.82rem;">Selection Notes / Remarks</label>

                        <input type="text" id="referSelectionNotes" placeholder="e.g. Parties selected from Lupon pool; or PB lot drawing record" style="padding: 7px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;">

                    </div>

                    <div class="form-group" style="margin-bottom: 0;">

                        <label for="referQuorumSize" style="font-size: 0.82rem;">Quorum Size</label>

                        <select id="referQuorumSize" onchange="document.getElementById('referQuorumReasonGroup').style.display = this.value === '2' ? 'block' : 'none';" style="padding: 7px; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.84rem;">

                            <option value="3" selected>3 Members (Standard Pangkat)</option>

                            <option value="2">2 Members (Permitted Exception Quorum)</option>

                        </select>

                    </div>

                    <div class="form-group" id="referQuorumReasonGroup" style="display: none; margin-top: 8px; margin-bottom: 0;">

                        <label for="referQuorumReason" style="font-size: 0.82rem; color: #b45309;">Quorum Exception Justification <span class="required-mark">*</span></label>

                        <input type="text" id="referQuorumReason" placeholder="State valid reason / parties consent to proceed with 2 members" style="padding: 7px; width: 100%; border: 1px solid #fde68a; border-radius: 6px; font-size: 0.84rem; background: #fffbeb;">

                    </div>

                </div>



                <div class="modal-actions" style="display: flex; justify-content: space-between;">

                    <button type="button" class="btn-secondary" onclick="goReferralStep(1)">&larr; Back to Schedule</button>

                    <button type="button" class="btn-create" onclick="goReferralStep(3)" style="background: #0284c7;">Review Referral &rarr;</button>

                </div>

            </div>



            <!-- STEP 3: Review & Confirmation -->

            <div class="referral-step" data-referral-step="3" style="display: none;">

                <div id="referReviewSummary" style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 16px; font-size: 0.85rem; color: #1e3a8a; line-height: 1.6;"></div>



                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 0.82rem; color: #166534;">

                    <div style="font-weight: 700; margin-bottom: 4px;">⚡ Simultaneous Action on Confirmation:</div>

                    <ul style="margin: 0; padding-left: 18px; line-height: 1.5;">

                        <li>Status advances to <strong>Conciliation</strong> and 1st Conciliation hearing is recorded.</li>

                        <li><strong>KP Forms 10, 11, and 12</strong> are automatically generated and stamped with the conciliation schedule.</li>

                        <li><strong>Officer's Return records</strong> are registered for KP Form 12 for both Complainant &amp; Respondent (Attempt #1).</li>

                        <li>Official Tri-Panel Preview opens side-by-side with 1-click dispatch to Officer's Return.</li>

                    </ul>

                </div>



                <div class="modal-actions" style="display: flex; justify-content: space-between;">

                    <button type="button" class="btn-secondary" onclick="goReferralStep(2)">&larr; Back to Nomination</button>

                    <div style="display: flex; gap: 8px;">

                        <button type="button" class="btn-secondary" onclick="closeReferPangkatModal()">Cancel</button>

                        <button type="submit" class="btn-create" id="submitReferBtn" style="background: #0369a1;">

                            Confirm Panel, Book Conciliation &amp; Generate KP Forms 10, 11, 12

                        </button>

                    </div>

                </div>

            </div>



        </form>

    </div>

</div>

<?php include __DIR__ . '/../../layouts/summons-preview-modal.php'; ?>

<?php include __DIR__ . '/../../layouts/kp-form-preview-modal.php'; ?>

<?php include __DIR__ . '/../../layouts/transmittal-modal.php'; ?>



<script src="../../assets/js/summons-preview.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/summons-preview.js'); ?>"></script>

<script src="../../assets/js/stage-workspace.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/stage-workspace.js'); ?>"></script>

<script>

    document.addEventListener('DOMContentLoaded', function() {

        initStageWorkspace({

            mode: 'Mediation',

            caseId: <?php echo json_encode($caseId); ?>,

            complaintId: <?php echo json_encode($complaintId); ?>

        });

    });

</script>



<?php include '../../layouts/footer.php'; ?>

