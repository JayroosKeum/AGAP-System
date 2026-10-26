<?php

session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3], true)) {
    die('Access Denied');
}
$canAuthorizePastDate = $roleId === 1;

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
                <div>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                        <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; background: #dbeafe; color: #1e40af; padding: 3px 8px; border-radius: 4px;">
                            STAGE 1 OF 2
                        </span>
                        <h1 id="wsStageTitle" style="margin: 0; font-size: 1.45rem; color: #0f172a; font-weight: 800;">
                            Mediation
                        </h1>
                        <span id="wsStageStatusBadge" class="badge-stage" style="font-size: 0.78rem; padding: 3px 9px; border-radius: 9999px; font-weight: 700;">
                            Loading...
                        </span>
                    </div>
                    <p id="wsCaseSubtext" style="margin: 0; font-size: 0.86rem; color: #64748b;">
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
                <!-- Section 1: Stage Overview -->
                <div class="overview-grid" id="wsStageOverviewGrid">
                    <div class="overview-stat-card"><span class="stat-label">Loading Overview...</span></div>
                </div>

                <!-- Section 2: Mediation Session List -->
                <div class="stage-section-card" id="sessionsSection">
                    <div class="stage-section-header">
                        <div>
                            <h3>Mediation Hearing Sessions</h3>
                            <p>Scheduled sessions, party appearances, service verification, and minutes status</p>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span id="wsSessionsCountBadge" style="font-size: 0.78rem; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 9999px; font-weight: 600;">0 sessions</span>
                            <button type="button" class="btn-secondary" onclick="openScheduleModal()" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                + Schedule Session
                            </button>
                        </div>
                    </div>
                    <div id="wsSessionsListContainer">
                        <div class="empty-detail-state">Loading mediation sessions...</div>
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

<!-- Section 3: Hearing Session Minutes & Proceedings -->
                <div class="stage-section-card" id="minutesSection">
                    <div class="stage-section-header">
                        <div>
                            <h3>Hearing Session Minutes &amp; Proceedings</h3>
                            <p>Official records of preliminaries, discussion &amp; statements, agreed terms, and procedural outcome</p>
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
                    <label for="schedVenue">Venue <span class="required-mark" aria-hidden="true">*</span></label>
                    <input type="text" id="schedVenue" name="venue" maxlength="255" value="Lupon Court Room" readonly required style="background: #f8fafc; cursor: not-allowed; width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.88rem; box-sizing: border-box;">
                    <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 4px;">Mediation sessions are conducted exclusively in the Lupon Court Room.</small>
                </div>

                <div class="form-group" style="margin-bottom: 6px;">
                    <label for="schedRemarks">Remarks <span class="optional-label">Optional</span></label>
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
                <select id="stageOutcomeSelect" required style="padding: 9px;">
                    <option value="Settled">Settled — Parties Reached Amicable Settlement</option>
                    <option value="Unsuccessful">Unsuccessful — Impasse / Referred to Pangkat</option>
                    <option value="Dismissed">Dismissed — Complainant Wilfully Failed to Appear</option>
                    <option value="Repudiated">Repudiated — Settlement Repudiated by Party</option>
                </select>
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
    <div class="modal-content" style="max-width: 620px;">
        <div class="modal-header">
            <h2>Refer Case to Pangkat ng Tagapagkasundo</h2>
            <button type="button" class="close-btn" onclick="closeReferPangkatModal()">&times;</button>
        </div>
        <form onsubmit="submitReferPangkat(event)">
            <p style="margin: 0 0 16px; font-size: 0.86rem; color: #475569; line-height: 1.5;">
                In accordance with <strong>RA 7160 Section 410(b)</strong>, when mediation before the Punong Barangay fails or the 15-day limit expires, the case is elevated to formal conciliation by constituting the 3-member <strong>Pangkat ng Tagapagkasundo</strong>. This automatically generates <strong>KP Form 10</strong>.
            </p>

            <div class="referral-step" data-referral-step="1">
            <div class="form-group">
                <label for="referDate">Referral / Formation Date <span class="required-mark">*</span></label>
                <input type="date" id="referDate" required>
            </div>

            <div class="form-group">
                <label for="referReason">Referral Justification / Impasse Notes <span class="required-mark">*</span></label>
                <textarea id="referReason" rows="2" required placeholder="Mediation before the Punong Barangay failed to produce an amicable settlement. Referred for formal conciliation before the Pangkat."></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeReferPangkatModal()">Cancel</button>
                <button type="button" class="btn-create" onclick="goReferralStep(2)">Continue to panel selection &rarr;</button>
            </div>
            </div>

            <div class="referral-step" data-referral-step="2" style="display:none;">
            <div class="form-group">
                <label for="referSelectionMethod">Pangkat Selection Method</label>
                <select id="referSelectionMethod" style="padding: 8px;">
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
                    <label for="referChairmanId" style="font-size: 0.82rem;">1. Chairperson / Head <span class="required-mark">*</span></label>
                    <select id="referChairmanId" required style="padding: 7px;"></select>
                </div>
                <div class="form-group" style="margin-bottom: 10px;">
                    <label for="referSecretaryId" style="font-size: 0.82rem;">2. Pangkat Secretary <span class="required-mark">*</span></label>
                    <select id="referSecretaryId" required style="padding: 7px;"></select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="referMemberId" style="font-size: 0.82rem;">3. Regular Member <span class="required-mark">*</span></label>
                    <select id="referMemberId" required style="padding: 7px;"></select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="goReferralStep(1)">&larr; Back</button>
                <button type="button" class="btn-create" onclick="goReferralStep(3)">Review referral &rarr;</button>
            </div>
            </div>

            <div class="referral-step" data-referral-step="3" style="display:none;">
                <div id="referReviewSummary" style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px; margin-bottom:16px; font-size:.85rem; color:#1e3a8a;"></div>
                <p style="margin:0 0 16px; font-size:.84rem; color:#475569; line-height:1.5;">Confirming creates the Pangkat panel, records the 3-day convening deadline, and generates KP Form 10. You will then continue directly to the Conciliation workspace to schedule its first hearing.</p>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="goReferralStep(2)">&larr; Back</button>
                <button type="button" class="btn-secondary" onclick="closeReferPangkatModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitReferBtn" style="background: #0369a1;">Execute Referral &amp; Generate KP Form 10</button>
            </div>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../layouts/summons-preview-modal.php'; ?>
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
