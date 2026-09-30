<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) { die('Access Denied'); }
$caseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$caseId) { header('Location: case-list.php'); exit; }
$canManageHearings = in_array((int) $_SESSION['role_id'], [1, 2], true);
$canManageSuspension = in_array((int) $_SESSION['role_id'], [1, 2], true);
include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/cases.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/cases.css'); ?>">
<div class="dashboard-layout"><?php include '../../layouts/sidebar.php'; ?><div class="main-content"><?php include '../../layouts/navbar.php'; ?>
    <div class="page-header"><div><a href="case-list.php" class="back-link"><svg class="back-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg><span>Back to Cases</span></a><h1 id="workspaceTitle">Case Workspace</h1><p id="workspaceSubtitle">Loading case information…</p></div><div class="action-buttons"><a id="assignLink" class="btn-create">Manage Team</a><?php if ($canManageHearings): ?><a id="hearingLink" class="btn-create">Schedule Mediation</a><?php endif; ?></div></div>
    <p id="workspaceMessage" role="alert" style="margin: 0 30px 16px;"></p>

    <!-- Statutory Mediation Timer / Lapsed Options Banner -->
    <div id="mediationTimerContainer"></div>

    <!-- KP Katarungang Pambarangay Workflow Timeline / Stepper -->
    <div id="kpStepperContainer"></div>

    <section class="workspace-grid">
      <div class="table-container"><h3>Case overview</h3><div id="caseOverview"></div></div>
      <div class="table-container"><h3>Case team</h3><div id="caseTeam"></div></div>
      <div class="table-container"><h3>Hearings and deadlines</h3><div id="caseHearings"></div></div>
      <div class="table-container"><h3>Generated documents</h3><div id="caseDocuments"></div></div>
      <div class="table-container"><h3>Proof of service</h3><div id="caseProofs"></div></div>
    </section>
</div></div>

<!-- Modal for Recording Summon / Notice Delivery -->
<div id="summonDeliveryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="deliveryModalTitle">Record Summon Delivery</h2>
            <button type="button" class="close-btn" onclick="closeDeliveryModal()">&times;</button>
        </div>
        <p style="color: #64748b; font-size: 0.92rem; margin-top: 0;">
            Track service delivery status for Katarungang Pambarangay summons and hearing notices.
        </p>
        <form id="summonDeliveryForm">
            <input type="hidden" id="deliveryHearingId" name="hearing_id">
            <div class="form-group">
                <label for="deliveryPartyType">Party to Serve <span class="required-mark">*</span></label>
                <select id="deliveryPartyType" name="party_type" required onchange="handleDeliveryPartyChange()">
                    <option value="Respondent">Respondent (Summon)</option>
                    <option value="Complainant">Complainant (Notice of Hearing)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="deliveryStatusSelect">Delivery Status <span class="required-mark">*</span></label>
                <select id="deliveryStatusSelect" name="delivery_status" required onchange="handleDeliveryStatusChange()">
                    <option value="Served Personal">Served - Personal (Direct to party)</option>
                    <option value="Served Substituted">Served - Substituted (Family member/authorized person)</option>
                    <option value="Served Refused">Served - Refused to Sign (Treated as served under KP rules)</option>
                    <option value="Unserved">Unserved / Service Failed (Pauses mediation hearing)</option>
                </select>
            </div>

            <!-- Served Fields -->
            <div id="servedFieldsGroup">
                <div class="form-group" id="recipientNameGroup">
                    <label for="deliveryRecipientName">Receiving Party Name <span class="required-mark">*</span></label>
                    <input type="text" id="deliveryRecipientName" name="recipient_name" placeholder="Full name of person who received notice">
                </div>
                <div class="form-group" id="relationshipGroup" style="display: none;">
                    <label for="deliveryRelationship">Relationship to Party <span class="required-mark">*</span></label>
                    <input type="text" id="deliveryRelationship" name="relationship" placeholder="e.g., Spouse, Adult Child, Housemate">
                </div>
                <div class="form-group">
                    <label for="deliveryServedAt">Date &amp; Time Served <span class="required-mark">*</span></label>
                    <input type="datetime-local" id="deliveryServedAt" name="served_at">
                </div>
            </div>

            <!-- Unserved Fields -->
            <div id="unservedFieldsGroup" style="display: none;">
                <div class="notice-box notice-box-danger">
                    <strong>Critical Rule:</strong> Marking a summon unserved automatically pauses the mediation hearing and statutory clock. Hearing attendance and unexcused absences are locked until service is confirmed.
                </div>
                <div class="form-group">
                    <label for="deliveryUnservedReason">Failure Reason <span class="required-mark">*</span></label>
                    <select id="deliveryUnservedReason" name="unserved_reason">
                        <option value="Moved Out">Moved Out / No longer residing at address</option>
                        <option value="Wrong Address">Wrong Address / Address not found</option>
                        <option value="No One Home">No One Home after 3 attempts</option>
                        <option value="Other">Other Unserved Reason</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="deliveryFailureNotes">Process Server / Tanod Notes <span class="required-mark">*</span></label>
                    <textarea id="deliveryFailureNotes" name="failure_notes" rows="3" placeholder="Provide details of attempt dates, times, and circumstances."></textarea>
                </div>
            </div>

            <div class="form-group">
                <label for="deliveryRemarks">Additional Remarks</label>
                <input type="text" id="deliveryRemarks" name="remarks" placeholder="Optional notes or observations">
            </div>

            <div class="modal-actions" style="margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeDeliveryModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="btnSubmitDelivery">Save Delivery Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal for Hearing Attendance Tracking (Independent Toggles) -->
<div id="hearingAttendanceModal" class="modal">
    <div class="modal-content" style="max-width: 640px;">
        <div class="modal-header">
            <h2 id="attendanceModalTitle">Record Hearing Attendance</h2>
            <button type="button" class="close-btn" onclick="closeAttendanceModal()">&times;</button>
        </div>
        <p id="attendanceModalSubtitle" style="color: #64748b; font-size: 0.92rem; margin-top: 0;">
            Record presence or non-appearance independently for Complainant and Respondent.
        </p>

        <!-- Unserved Warning Banner -->
        <div id="attendanceLockBanner" class="notice-box notice-box-danger" style="display: none;">
            <strong>Service Incomplete:</strong> Summons or notices are unserved. Under KP rules, you cannot record unexcused absences until service has been confirmed or marked Refused to Sign.
        </div>

        <form id="hearingAttendanceForm">
            <input type="hidden" id="attendanceHearingId" name="hearing_id">

            <!-- Complainant Attendance -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <label style="font-weight: 700; font-size: 0.95rem; margin: 0; color: #1e293b;">
                        Complainant (<span id="complainantNameLabel">Complainant</span>)
                    </label>
                    <span id="complainantServiceTag" class="delivery-badge delivery-badge-pending">Notice: Pending</span>
                </div>
                <input type="hidden" id="complainantAttendanceInput" name="complainant_attendance" value="Present">
                <div class="attendance-toggle-group">
                    <button type="button" class="att-toggle-btn btn-present selected" id="btnCompPresent" onclick="setPartyAttendance('Complainant', 'Present')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Present
                    </button>
                    <button type="button" class="att-toggle-btn btn-absent" id="btnCompAbsent" onclick="setPartyAttendance('Complainant', 'Absent')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        Absent
                    </button>
                </div>
                <!-- Complainant Absent Notice & Show Cause Schedule -->
                <div id="compAbsentNotice" style="display: none; margin-top: 10px;" class="notice-box notice-box-warning">
                    <strong>KP Form 18 Escalation:</strong> Complainant non-appearance triggers auto-issuance of <em>KP Form 18 (Notice of Hearing for Failure to Appear)</em>.
                    <div class="form-group" style="margin-top: 8px;">
                        <label for="compShowCauseDate" style="font-size: 0.82rem; font-weight: 600;">Schedule Complainant Show-Cause Hearing:</label>
                        <input type="datetime-local" id="compShowCauseDate" name="complainant_show_cause_date">
                    </div>
                </div>
            </div>

            <!-- Respondent Attendance -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <label style="font-weight: 700; font-size: 0.95rem; margin: 0; color: #1e293b;">
                        Respondent (<span id="respondentNameLabel">Respondent</span>)
                    </label>
                    <span id="respondentServiceTag" class="delivery-badge delivery-badge-pending">Summon: Pending</span>
                </div>
                <input type="hidden" id="respondentAttendanceInput" name="respondent_attendance" value="Present">
                <div class="attendance-toggle-group">
                    <button type="button" class="att-toggle-btn btn-present selected" id="btnRespPresent" onclick="setPartyAttendance('Respondent', 'Present')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Present
                    </button>
                    <button type="button" class="att-toggle-btn btn-absent" id="btnRespAbsent" onclick="setPartyAttendance('Respondent', 'Absent')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        Absent
                    </button>
                </div>
                <!-- Respondent Absent Notice & Show Cause Schedule -->
                <div id="respAbsentNotice" style="display: none; margin-top: 10px;" class="notice-box notice-box-warning">
                    <strong>KP Form 19 Escalation:</strong> Respondent non-appearance triggers auto-issuance of <em>KP Form 19 (Notice of Hearing for Failure to Appear)</em>.
                    <div class="form-group" style="margin-top: 8px;">
                        <label for="respShowCauseDate" style="font-size: 0.82rem; font-weight: 600;">Schedule Respondent Show-Cause Hearing:</label>
                        <input type="datetime-local" id="respShowCauseDate" name="respondent_show_cause_date">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="attendanceNotes">Attendance Notes &amp; Summary</label>
                <textarea id="attendanceNotes" name="attendance_notes" rows="2" placeholder="Optional notes regarding session or appearances"></textarea>
            </div>

            <div class="modal-actions" style="margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeAttendanceModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="btnSubmitAttendance">Submit Attendance Record</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal for Evaluating Show-Cause Hearing Outcome -->
<div id="showCauseModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 id="showCauseModalTitle">Evaluate Show-Cause Hearing</h2>
            <button type="button" class="close-btn" onclick="closeShowCauseModal()">&times;</button>
        </div>
        <p id="showCauseSubtitle" style="color: #64748b; font-size: 0.92rem; margin-top: 0;">
            Determine whether the party provided a justifiable ground for their prior non-appearance.
        </p>
        <form id="showCauseForm">
            <input type="hidden" id="showCauseHearingId" name="hearing_id">
            <input type="hidden" id="showCausePartyType" name="party_type">

            <div class="form-group">
                <label style="font-weight: 700; margin-bottom: 6px;">Evaluation Outcome <span class="required-mark">*</span></label>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc;">
                        <input type="radio" name="is_justified" value="1" checked onchange="handleJustifiedOutcomeChange(true)" style="margin-top: 3px;">
                        <div>
                            <strong>Outcome 1: Justifiable Reason</strong>
                            <div style="font-size: 0.82rem; color: #64748b;">Medical emergency, disaster, or official duty. Mediation hearing will be rescheduled.</div>
                        </div>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; padding: 10px; border: 1px solid #fecaca; border-radius: 6px; background: #fef2f2;">
                        <input type="radio" name="is_justified" value="0" onchange="handleJustifiedOutcomeChange(false)" style="margin-top: 3px;">
                        <div>
                            <strong style="color: #991b1b;">Outcome 2: Unjustifiable / Willful Refusal / Ignored Notice</strong>
                            <div id="unjustifiedConsequenceText" style="font-size: 0.82rem; color: #991b1b;">
                                Legal sanctions will apply. Complainant will be barred or Respondent declared in default.
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="scJustificationCategory">Category <span class="required-mark">*</span></label>
                <select id="scJustificationCategory" name="justification_category" required>
                    <option value="Medical Emergency">Medical Emergency / Illness</option>
                    <option value="Force Majeure">Force Majeure / Calamity / Disaster</option>
                    <option value="Official Duty">Official Duty / Court Subpoena</option>
                    <option value="Unjustified Absence">Unjustified Absence / Failure to Appear</option>
                    <option value="Willful Refusal">Willful Refusal / Ignored Show-Cause</option>
                    <option value="Other">Other Ground</option>
                </select>
            </div>

            <div class="form-group">
                <label for="scJustificationNotes">Evaluation Details &amp; Officer Finding <span class="required-mark">*</span></label>
                <textarea id="scJustificationNotes" name="justification_notes" rows="3" required placeholder="State officer findings, proof submitted, or willful refusal details."></textarea>
            </div>

            <!-- Fields for Rescheduling (if justified) -->
            <div id="rescheduleFieldsGroup">
                <div class="form-group">
                    <label for="scRescheduleDate">Rescheduled Mediation Hearing Date &amp; Time <span class="required-mark">*</span></label>
                    <input type="datetime-local" id="scRescheduleDate" name="reschedule_date">
                </div>
                <div class="form-group">
                    <label for="scRescheduleVenue">Venue</label>
                    <input type="text" id="scRescheduleVenue" name="reschedule_venue" value="Tanggapan ng Lupong Tagapamayapa, Barangay Hall">
                </div>
            </div>

            <div class="modal-actions" style="margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closeShowCauseModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="btnSubmitShowCause">Save Decision &amp; Apply Sanction</button>
            </div>
        </form>
    </div>
</div>

<?php if ($canManageSuspension): ?>
<!-- Modal for Logging Justified Suspension -->
<div id="pauseMediationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Log Justified Suspension</h2>
            <button type="button" class="close-btn" onclick="closePauseModal()">&times;</button>
        </div>
        <p style="color: #64748b; font-size: 0.92rem; margin-top: 0;">
            Pausing the 15-day statutory mediation clock temporarily suspends the calendar day countdown until resumed.
        </p>
        <form id="pauseMediationForm">
            <div class="form-group">
                <label for="pauseReasonSelect">Reason for Suspension <span class="required-mark" aria-hidden="true">*</span></label>
                <select id="pauseReasonSelect" name="reason" required>
                    <option value="">Select a verified reason</option>
                    <option value="Justified Suspension - Verified Medical Emergency">Verified Medical Emergency</option>
                    <option value="Justified Suspension - Natural Disaster / Calamity">Natural Disaster / Calamity</option>
                    <option value="Justified Suspension - Excused Non-Appearance">Excused Non-Appearance (Official Notice)</option>
                    <option value="Summon Unserved">Summon Unserved</option>
                    <option value="Other Justified Reason">Other Exigent Ground</option>
                </select>
            </div>
            <div class="form-group">
                <label for="pauseNotesInput">Detailed Notes &amp; Verification <span class="required-mark" aria-hidden="true">*</span></label>
                <textarea id="pauseNotesInput" name="notes" rows="3" maxlength="1000" placeholder="State facts, dates, and medical/official proof details justifying the suspension." required></textarea>
            </div>
            <div class="modal-actions" style="margin-top: 1.25rem;">
                <button type="button" class="btn-secondary" onclick="closePauseModal()">Cancel</button>
                <button type="submit" class="btn-danger" id="btnSubmitPause">Pause Mediation Clock</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
window.caseWorkspaceId = <?php echo (int) $caseId; ?>;
window.canManageSuspension = <?php echo $canManageSuspension ? 'true' : 'false'; ?>;
window.canManageDeliveries = <?php echo (in_array((int) $_SESSION['role_id'], [1, 2, 4], true)) ? 'true' : 'false'; ?>;
window.canManageAttendance = <?php echo (in_array((int) $_SESSION['role_id'], [1, 2], true)) ? 'true' : 'false'; ?>;
</script>
<script src="../../assets/js/case-workspace.js?v=<?php echo filemtime(__DIR__ . '/../../layouts/header.php'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>
