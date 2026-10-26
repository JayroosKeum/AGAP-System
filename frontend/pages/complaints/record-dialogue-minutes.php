<?php
session_start();

if (
    !isset($_SESSION['role_id']) ||
    !in_array((int)$_SESSION['role_id'], [1, 2, 3, 4], true)
) {
    die('Access Denied');
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: complaint-list.php');
    exit;
}

$complaintId = (int)$_GET['id'];
$stageType = trim((string)($_GET['stage'] ?? 'Paanyaya 1'));
if (!in_array($stageType, ['Paanyaya 1', 'Paanyaya 2'], true)) {
    $stageType = 'Paanyaya 1';
}

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/controllers/ComplaintController.php';

$complaintCtrl = new ComplaintController();
$complaint = $complaintCtrl->show($complaintId);

if (!$complaint) {
    die('Complaint not found.');
}

$complainants = [];
$respondents = [];
foreach ($complaint['parties'] ?? [] as $p) {
    $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    if (($p['party_type'] ?? '') === 'Complainant') {
        $complainants[] = $name ?: 'Complainant';
    } else {
        $respondents[] = $name ?: 'Respondent';
    }
}
$compText = !empty($complainants) ? implode(', ', $complainants) : 'No complainant listed';
$respText = !empty($respondents) ? implode(', ', $respondents) : 'No respondent listed';
$caseNum = $complaint['complaint_number'] ?? ('#' . $complaintId);

include '../../layouts/header.php';
?>
<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/case-details-modern.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/case-details-modern.css'); ?>">

<style>
.record-minutes-wrapper {
    max-width: 960px;
    margin: 0 auto;
    padding: 24px 20px 48px;
}
.record-minutes-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
    padding: 28px 32px;
}
.rm-section-title {
    font-size: 0.96rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 12px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.rm-party-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 20px;
}
.rm-delay-tag {
    font-size: 0.76rem;
    padding: 4px 10px;
    border-radius: 9999px;
    border: 1px solid #cbd5e1;
    background: #eff6ff;
    color: #1e40af;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.15s ease;
}
.rm-delay-tag:hover {
    background: #dbeafe;
    border-color: #93c5fd;
}
.rm-outcome-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border-radius: 8px;
    padding: 14px 16px;
    cursor: pointer;
    border: 1.5px solid transparent;
    transition: all 0.15s ease;
}
.rm-outcome-card.settled-card {
    background: #f0fdf4;
    border-color: #86efac;
}
.rm-outcome-card.settled-card:hover {
    border-color: #22c55e;
}
.rm-outcome-card.no-agree-card {
    background: #fef2f2;
    border-color: #fecaca;
}
.rm-outcome-card.no-agree-card:hover {
    border-color: #ef4444;
}
.rm-choice-box {
    background: #ffffff;
    border: 1px solid #fdba74;
    border-radius: 6px;
    padding: 10px 12px;
    cursor: pointer;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
</style>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="record-minutes-wrapper">
            <!-- Header Navigation & Title -->
            <div style="margin-bottom: 20px;">
                <a href="complaint-details.php?id=<?= $complaintId ?>&tab=paanyaya" class="back-link" style="display: inline-flex; align-items: center; gap: 6px; color: #475569; text-decoration: none; font-size: 0.88rem; font-weight: 600; margin-bottom: 12px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    <span>Back to Complaint Details</span>
                </a>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h1 style="font-size: 1.6rem; font-weight: 800; color: #0f172a; margin: 0 0 4px 0; letter-spacing: -0.02em;">
                            Record Dialogue Minutes &amp; Result (<?= htmlspecialchars($stageType) ?>)
                        </h1>
                        <p style="margin: 0; color: #64748b; font-size: 0.92rem;">
                            Salaysay at Buod ng Usapan sa Harap ng Barangay &mdash; <strong>Case #<?= htmlspecialchars($caseNum) ?></strong>
                        </p>
                    </div>
                    <span class="paanyaya-stage-tag" style="font-size: 0.82rem; padding: 4px 12px; border-radius: 9999px; background: #e0f2fe; color: #0369a1; font-weight: 700;">
                        <?= htmlspecialchars($stageType) ?>
                    </span>
                    <span id="paanyayaDraftIndicator" class="agap-draft-indicator state-idle">✓ Saved</span>
                </div>
            </div>

            <!-- Case Summary Strip -->
            <div class="rm-party-box">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; font-size: 0.86rem;">
                    <div>
                        <span style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: #16a34a; letter-spacing: 0.05em; display: block; margin-bottom: 2px;">Nagpaanyaya (Complainant)</span>
                        <strong style="color: #0f172a;"><?= htmlspecialchars($compText) ?></strong>
                    </div>
                    <div>
                        <span style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: #2563eb; letter-spacing: 0.05em; display: block; margin-bottom: 2px;">Inanyayahan (Respondent)</span>
                        <strong style="color: #0f172a;"><?= htmlspecialchars($respText) ?></strong>
                    </div>
                    <div>
                        <span style="font-size: 0.74rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; display: block; margin-bottom: 2px;">Case Number</span>
                        <strong style="color: #0f172a;"><?= htmlspecialchars($caseNum) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Standalone Form Card -->
            <div class="record-minutes-card">
                <form id="recordDialogueMinutesForm" onsubmit="handleDialogueMinutesSubmit(event)" enctype="multipart/form-data">
                    <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                    <input type="hidden" id="stageTypeInput" name="stage_type" value="<?= htmlspecialchars($stageType) ?>">

                    <!-- 1. Dialogue Date & Time and Actual Start Time -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minutesDialogueDate" style="font-weight: 600; font-size: 0.86rem; display: block; margin-bottom: 5px; color: #0f172a;">
                                Petsa at Takdang Oras (Scheduled Date &amp; Time) <span class="required-mark" style="color: #dc2626;">*</span>
                            </label>
                            <input type="datetime-local" id="minutesDialogueDate" name="dialogue_date" required style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                <label for="minutesActualStartTime" style="font-weight: 600; font-size: 0.86rem; margin: 0; color: #0f172a;">
                                    Aktwal na Oras ng Simula (Actual Start Time)
                                </label>
                                <button type="button" onclick="setMinutesActualStartNow()" style="background: none; border: none; color: #2563eb; font-size: 0.78rem; font-weight: 700; cursor: pointer; padding: 0;">
                                    Oras Ngayon (Now)
                                </button>
                            </div>
                            <input type="time" id="minutesActualStartTime" name="actual_start_time" style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                        </div>
                    </div>

                    <!-- 2. Session Delay Remarks -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 5px;">
                            <label for="minutesSessionRemarks" style="font-weight: 600; font-size: 0.86rem; margin: 0; color: #0f172a;">
                                Puna sa Oras ng Simula / Dahilan ng Pagkaantala (Session Delay Remarks)
                            </label>
                            <span style="font-size: 0.76rem; color: #64748b;">(Kung sakaling late nag-umpisa ang session)</span>
                        </div>
                        <textarea id="minutesSessionRemarks" name="session_remarks" rows="2" placeholder="Halimbawa: Naantala ang simula dahil may naunang kaso sa Lupon Court Room. Present ang mga partido." style="padding: 9px 12px; font-size: 0.86rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;"></textarea>
                        <!-- Quick Tag Pills -->
                        <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                            <span style="font-size: 0.76rem; color: #64748b; font-weight: 600;">I-click para ilagay:</span>
                            <button type="button" class="rm-delay-tag" onclick="applyDelayRemark('Naantala ang simula dahil may naunang kaso sa Lupon Court Room. Present ang mga partido.')">
                                &bull; Naunang kaso sa Court Room
                            </button>
                            <button type="button" class="rm-delay-tag" style="background: #fff7ed; color: #c2410c;" onclick="applyDelayRemark('Naantala dahil nahuli sa pagdating ang isa o magkabilang panig.')">
                                &bull; Nahuli ang panig
                            </button>
                            <button type="button" class="rm-delay-tag" style="background: #f0fdf4; color: #166534;" onclick="applyDelayRemark('Nagsimula sa takdang oras nang walang pagkaantala.')">
                                &bull; Nasa oras
                            </button>
                        </div>
                    </div>

                    <!-- 3. Party Attendance -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px;">
                        <div class="rm-section-title">
                            Pagdalo ng Magkabilang Panig (Party Attendance) <span class="required-mark" style="color: #dc2626;">*</span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div>
                                <span style="font-size: 0.82rem; color: #475569; font-weight: 600; display: block; margin-bottom: 6px;">Nagpaanyaya (Complainant):</span>
                                <div style="display: flex; gap: 16px; font-size: 0.88rem;">
                                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="radio" name="complainant_attended" value="Present" checked>
                                        <span style="color: #166534; font-weight: 600;">Dumalo (Present)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="radio" name="complainant_attended" value="Absent">
                                        <span style="color: #991b1b; font-weight: 600;">Hindi Dumalo (Absent)</span>
                                    </label>
                                </div>
                            </div>
                            <div>
                                <span style="font-size: 0.82rem; color: #475569; font-weight: 600; display: block; margin-bottom: 6px;">Inanyayahan (Respondent):</span>
                                <div style="display: flex; gap: 16px; font-size: 0.88rem;">
                                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="radio" name="respondent_attended" value="Present" checked onchange="handleMinutesAttendanceChange()">
                                        <span style="color: #166534; font-weight: 600;">Dumalo (Present)</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="radio" name="respondent_attended" value="Absent" onchange="handleMinutesAttendanceChange()">
                                        <span style="color: #991b1b; font-weight: 600;">Hindi Dumalo (Absent)</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Salaysay at Buod ng Napag-usapan -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label for="minutesDiscussionSummary" style="font-weight: 600; font-size: 0.86rem; color: #0f172a; display: block; margin-bottom: 5px;">
                            Salaysay at Buod ng Napag-usapan (Summary of Statements &amp; Discussion) <span class="required-mark" style="color: #dc2626;">*</span>
                        </label>
                        <textarea id="minutesDiscussionSummary" name="discussion_summary" rows="4" required placeholder="Ibuod ang mga pahayag, sagot, at mga puntong napag-usapan ng magkabilang panig habang isinasagawa ang dialogue..." style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;"></textarea>
                    </div>

                    <!-- 5. Mga Mungkahi o Napagkasunduang Kondisyon -->
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label for="minutesAgreedTerms" style="font-weight: 600; font-size: 0.86rem; color: #0f172a; display: block; margin-bottom: 5px;">
                            Mga Mungkahi o Napagkasunduang Kondisyon (Proposals / Agreed Terms)
                        </label>
                        <textarea id="minutesAgreedTerms" name="agreed_terms" rows="3" placeholder="Kondisyon ng bayaran, pagsasaayos, o pangako ng magkabilang panig (kung mayroon)..." style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;"></textarea>
                    </div>

                    <!-- 6. Kopya ng Isinulat-Kamay na Katitikan -->
                    <div class="form-group" style="margin-bottom: 22px;">
                        <label for="minutesScannedFile" style="font-weight: 600; font-size: 0.86rem; color: #0f172a; display: block; margin-bottom: 5px;">
                            Kopya ng Isinulat-Kamay na Katitikan / Minutes (Optional Photo or Scan)
                        </label>
                        <input type="file" id="minutesScannedFile" name="scanned_minutes" accept=".jpg,.jpeg,.png,.pdf,.webp" style="padding: 8px 10px; font-size: 0.84rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                        <span style="font-size: 0.76rem; color: #64748b; margin-top: 4px; display: block;">Maaaring mag-attach ng litrato ng sulat-kamay na katitikan na may lagda ng mga panig (JPG, PNG, PDF hanggang 10MB).</span>
                    </div>

                    <!-- 7. Dialogue Procedural Result (Outcome Selection) -->
                    <div style="margin-bottom: 20px;">
                        <label style="font-weight: 700; font-size: 0.9rem; color: #0f172a; display: block; margin-bottom: 8px;">
                            Kinalabasan ng Pag-uusap (Dialogue Procedural Result) <span class="required-mark" style="color: #dc2626;">*</span>
                        </label>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <label id="optMinutesSettledWrap" class="rm-outcome-card settled-card">
                                <input type="radio" name="outcome" value="Settled" checked onchange="toggleMinutesOutcomeViews()" style="margin-top: 4px;">
                                <div>
                                    <strong style="color: #166534; font-size: 0.92rem;">Nagkasundo / Amicably Settled (Informal Settlement)</strong>
                                    <p style="margin: 3px 0 0; font-size: 0.8rem; color: #166534;">Nalutas ang usapin sa impormal na pag-uusap. Isasara ang complaint nang hindi na kailangang i-docket sa pormal na korte (LTIA performance win).</p>
                                </div>
                            </label>

                            <label id="optMinutesNoAgreementWrap" class="rm-outcome-card no-agree-card">
                                <input type="radio" name="outcome" value="No_Agreement" onchange="toggleMinutesOutcomeViews()" style="margin-top: 4px;">
                                <div>
                                    <strong style="color: #991b1b; font-size: 0.92rem;">Walang Kasunduan / No Agreement Reached (or Respondent No-Show)</strong>
                                    <p style="margin: 3px 0 0; font-size: 0.8rem; color: #991b1b;">Hindi nagkasundo ang mga panig, tumanggi ang inanyayahan, o hindi sumipot. Piliin ang desisyon ng nagrereklamo sa ibaba.</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Complainant Decision (Visible on No_Agreement) -->
                    <div id="minutesComplainantChoiceSection" style="display: none; background: #fff7ed; border: 1.5px solid #fed7aa; border-radius: 8px; padding: 14px 16px; margin-bottom: 20px;">
                        <label style="font-weight: 700; font-size: 0.88rem; color: #9a3412; display: block; margin-bottom: 8px;">
                            Ano ang nais ng nagrereklamo? (Complainant's Decision) <span class="required-mark" style="color: #dc2626;">*</span>
                        </label>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <label id="optChoiceP2Wrap" class="rm-choice-box">
                                <input type="radio" name="complainant_choice" value="Request_Paanyaya_2" checked onchange="toggleComplainantChoiceViews()" style="margin-top: 3px;">
                                <div>
                                    <strong style="color: #c2410c; font-size: 0.86rem;">Choice A: Humiling ng Ikalawang Paanyaya (Give another chance / Request 2nd Paanyaya)</strong>
                                    <p style="margin: 2px 0 0; font-size: 0.78rem; color: #7c2d12;">I-unlock ang Paanyaya 2 upang makapag-isyu ng pangalawang paanyaya sa inanyayahan.</p>
                                </div>
                            </label>
                            <label id="optChoiceEscalateWrap" class="rm-choice-box">
                                <input type="radio" name="complainant_choice" value="Escalate_Mediation" onchange="toggleComplainantChoiceViews()" style="margin-top: 3px;">
                                <div>
                                    <strong style="color: #dc2626; font-size: 0.86rem;">Choice B: I-akyat na sa Pormal na Mediation / Punong Barangay (Escalate immediately)</strong>
                                    <p style="margin: 2px 0 0; font-size: 0.78rem; color: #991b1b;">Laktawan ang Paanyaya 2. Magtatalaga ng official Case Docket Number, KP Form 7 (Complaint), at KP Form 9 (Summons).</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Escalation 1st Mediation Hearing Schedule (Visible ONLY when Choice B is active) -->
                    <div id="minutesEscalationScheduleSection" style="display: none; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 12px; font-size: 0.94rem; color: #0f172a; font-weight: 700;">1st Formal Mediation Hearing Schedule (Tumana Rules)</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="minutesMedDate" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 5px;">Mediation Date (Tuesdays ONLY) <span class="required-mark" style="color: #dc2626;">*</span></label>
                                <input type="date" id="minutesMedDate" name="mediation_date" style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="minutesMedTime" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 5px;">Mediation Slot (1:00 PM - 5:00 PM) <span class="required-mark" style="color: #dc2626;">*</span></label>
                                <select id="minutesMedTime" name="mediation_time" style="padding: 9px 12px; font-size: 0.88rem; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                                    <option value="13:00">1:00 PM - 2:00 PM</option>
                                    <option value="14:00">2:00 PM - 3:00 PM</option>
                                    <option value="15:00">3:00 PM - 4:00 PM</option>
                                    <option value="16:00">4:00 PM - 5:00 PM</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 12px; margin-bottom: 0;">
                            <label for="minutesMedVenue" style="font-weight: 600; font-size: 0.84rem; display: block; margin-bottom: 5px;">Hearing Venue</label>
                            <input type="text" id="minutesMedVenue" name="venue" value="Lupon Court Room" readonly style="padding: 9px 12px; font-size: 0.88rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 7px; width: 100%; box-sizing: border-box;">
                        </div>
                    </div>

                    <div id="minutesErrorMessage" class="alert alert-danger" style="display: none; margin-bottom: 18px; padding: 10px 14px; border-radius: 7px; font-size: 0.86rem; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;"></div>

                    <!-- Form Actions -->
                    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 12px; margin-top: 24px; padding-top: 18px; border-top: 1px solid #f1f5f9;">
                        <a href="complaint-details.php?id=<?= $complaintId ?>&tab=paanyaya" class="btn-secondary" style="padding: 9px 18px; font-size: 0.88rem; text-decoration: none; display: inline-flex; align-items: center; border-radius: 7px;">
                            Cancel
                        </a>
                        <button type="submit" id="btnSubmitMinutes" class="btn-create" style="padding: 9px 22px; font-size: 0.9rem; background: #16a34a; border-color: #16a34a; color: #ffffff; border-radius: 7px; font-weight: 700; cursor: pointer;">
                            Save Minutes &amp; Close Complaint (Nagkasundo)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const complaintId = <?= (int)$complaintId ?>;
const currentStage = <?= json_encode($stageType) ?>;

document.addEventListener('DOMContentLoaded', () => {
    // Set default dialogue date/time to now (local timezone)
    const dtInput = document.getElementById('minutesDialogueDate');
    if (dtInput) {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        dtInput.value = now.toISOString().slice(0, 16);
    }

    // Set default actual start time to now
    setMinutesActualStartNow();

    // Configure next Tuesday date for mediation
    const medDateInput = document.getElementById('minutesMedDate');
    if (medDateInput) {
        const d = new Date();
        d.setDate(d.getDate() + 3);
        while (d.getDay() !== 2) {
            d.setDate(d.getDate() + 1);
        }
        medDateInput.value = d.toISOString().slice(0, 10);
        medDateInput.min = new Date().toISOString().slice(0, 10);
    }

    // For Paanyaya 2, Choice A is not applicable ("Dalawang Beses Lang" rule)
    const optChoiceP2Wrap = document.getElementById('optChoiceP2Wrap');
    if (optChoiceP2Wrap && currentStage === 'Paanyaya 2') {
        optChoiceP2Wrap.style.display = 'none';
        const escChoice = document.querySelector('input[name="complainant_choice"][value="Escalate_Mediation"]');
        if (escChoice) escChoice.checked = true;
    }

    toggleMinutesOutcomeViews();

    if (window.AgapDraftManager) {
        window.minutesDraftManager = window.AgapDraftManager.init({
            formId: 'recordDialogueMinutesForm',
            storageKey: `agap_draft_paanyaya_${currentStage}_comp_${complaintId}`,
            indicatorId: 'paanyayaDraftIndicator',
            onRestore: () => {
                handleMinutesAttendanceChange();
                toggleMinutesOutcomeViews();
            },
            onClear: () => {
                toggleMinutesOutcomeViews();
            }
        });
    }
});

function setMinutesActualStartNow() {
    const actualStart = document.getElementById('minutesActualStartTime');
    if (actualStart) {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        actualStart.value = `${hh}:${mm}`;
    }
}

function applyDelayRemark(text) {
    const ta = document.getElementById('minutesSessionRemarks');
    if (ta) {
        ta.value = text;
        ta.focus();
    }
}

function handleMinutesAttendanceChange() {
    const form = document.getElementById('recordDialogueMinutesForm');
    if (!form) return;
    const respAttended = form.querySelector('input[name="respondent_attended"]:checked')?.value;
    const optSettledWrap = document.getElementById('optMinutesSettledWrap');

    if (respAttended === 'Absent') {
        const noAgreeRadio = form.querySelector('input[name="outcome"][value="No_Agreement"]');
        if (noAgreeRadio) noAgreeRadio.checked = true;
        if (optSettledWrap) {
            optSettledWrap.style.opacity = '0.5';
            optSettledWrap.style.pointerEvents = 'none';
        }
    } else {
        if (optSettledWrap) {
            optSettledWrap.style.opacity = '1';
            optSettledWrap.style.pointerEvents = '';
        }
    }
    toggleMinutesOutcomeViews();
}

function toggleMinutesOutcomeViews() {
    const form = document.getElementById('recordDialogueMinutesForm');
    if (!form) return;
    const outcome = form.querySelector('input[name="outcome"]:checked')?.value || 'Settled';
    const choiceSection = document.getElementById('minutesComplainantChoiceSection');
    const submitBtn = document.getElementById('btnSubmitMinutes');

    if (outcome === 'Settled') {
        if (choiceSection) choiceSection.style.display = 'none';
        const escSec = document.getElementById('minutesEscalationScheduleSection');
        if (escSec) escSec.style.display = 'none';
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Close Complaint (Nagkasundo)';
            submitBtn.style.background = '#16a34a';
            submitBtn.style.borderColor = '#16a34a';
        }
    } else {
        if (choiceSection) choiceSection.style.display = 'block';
        toggleComplainantChoiceViews();
    }
}

function toggleComplainantChoiceViews() {
    const form = document.getElementById('recordDialogueMinutesForm');
    if (!form) return;
    const outcome = form.querySelector('input[name="outcome"]:checked')?.value;
    const choice = form.querySelector('input[name="complainant_choice"]:checked')?.value || 'Request_Paanyaya_2';
    const escSec = document.getElementById('minutesEscalationScheduleSection');
    const submitBtn = document.getElementById('btnSubmitMinutes');

    if (outcome !== 'Settled' && choice === 'Escalate_Mediation') {
        if (escSec) escSec.style.display = 'block';
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Escalate to Mediation';
            submitBtn.style.background = '#dc2626';
            submitBtn.style.borderColor = '#dc2626';
        }
    } else {
        if (escSec) escSec.style.display = 'none';
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Unlock Paanyaya 2';
            submitBtn.style.background = '#2563eb';
            submitBtn.style.borderColor = '#2563eb';
        }
    }
}

async function handleDialogueMinutesSubmit(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitMinutes');
    const errBox = document.getElementById('minutesErrorMessage');

    const formData = new FormData(form);

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving dialogue minutes...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await fetch('../../../backend/api/paanyaya/record-minutes.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            if (window.AgapDraftManager) {
                window.AgapDraftManager.markSubmitted(`agap_draft_paanyaya_${currentStage}_comp_${complaintId}`);
                window.AgapDraftManager.setIndicator('paanyayaDraftIndicator', 'synced', '✓ All changes submitted');
            }
            window.minutesDraftManager?.markSubmitted();
            let targetUrl = `complaint-details.php?id=${encodeURIComponent(formData.get('complaint_id'))}&tab=paanyaya`;
            if (data.action === 'escalate_mediation') {
                targetUrl = `complaint-details.php?id=${encodeURIComponent(formData.get('complaint_id'))}&tab=mediation`;
            }
            window.location.href = targetUrl;
        } else {
            throw new Error(data.message || 'Failed to record dialogue minutes.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error recording dialogue minutes.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error recording dialogue minutes.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            toggleMinutesOutcomeViews();
        }
    }
}
</script>

<?php include '../../layouts/footer.php'; ?>
