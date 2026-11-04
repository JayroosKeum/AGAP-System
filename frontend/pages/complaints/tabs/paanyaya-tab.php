<?php

declare(strict_types=1);

if (!isset($complaintId) || !$complaintId) {
    $complaintId = filter_var($_GET['id'] ?? ($_GET['complaint_id'] ?? null), FILTER_VALIDATE_INT) ?: 0;
}

require_once __DIR__ . '/../../../../backend/models/PaanyayaModel.php';
$paanyayaModelInstance = new PaanyayaModel();
$pData = $paanyayaModelInstance->getPaanyayaData($complaintId) ?? [];

$compStatus = $pData['complaint']['status'] ?? 'Pre-Docketed (Paanyaya)';
$currentStage = $pData['current_stage'] ?? 'Paanyaya 1';
$isP2 = ($currentStage === 'Paanyaya 2');
$stage1 = $pData['stage1'] ?? null;
$stage2 = $pData['stage2'] ?? null;

// Streamlined initial field values
$complainantNameVal = $pData['complainant_name'] ?? 'NAGPAANYAYA';
$respondentNameVal = $pData['respondent_name'] ?? 'INANYAYAHAN';
$tanggapanVal = 'Barangay Tumana';
$invitationDateVal = $pData['invitation_date'] ?? date('Y-m-d', strtotime('+3 weekdays'));
$invitationTimeVal = $pData['invitation_time'] ?? '09:00:00';
$petsaVal = $pData['petsa'] ?? date('j', strtotime($invitationDateVal));
$buwanVal = $pData['buwan'] ?? $paanyayaModelInstance->filipinoMonth((int)date('n', strtotime($invitationDateVal)));
$taonVal = $pData['taon'] ?? date('Y', strtotime($invitationDateVal));
$arawVal = $pData['araw'] ?? $paanyayaModelInstance->filipinoDayOfWeek((int)date('w', strtotime($invitationDateVal)));
$orasVal = $pData['oras'] ?? date('h:i A', strtotime($invitationTimeVal));
$salaysayVal = $pData['salaysay'] ?? ($pData['complaint']['narrative'] ?? 'Naisin nilang magkausap at magkasundo nang maayos ukol sa kanilang hidwaan.');
$hasESignatureVal = $pData['has_e_signature'] ?? true;

$isSettled = !empty($pData['is_settled']);
$isDismissed = !empty($pData['is_dismissed']);
$isEscalated = !empty($pData['is_escalated']);
$isConcluded = $isSettled || $isDismissed || $isEscalated;
?>

<!-- Paanyaya Workspace Styles: 50/50 Split Screen Layout -->
<style>
.paanyaya-workspace-container {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-top: 10px;
}

.paanyaya-stage-header-banner {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.paanyaya-stage-badges {
    display: flex;
    align-items: center;
    gap: 10px;
}

.paanyaya-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    font-size: 0.8rem;
    font-weight: 700;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.paanyaya-badge-p1 {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}

.paanyaya-badge-p2 {
    background: #fdf4ff;
    color: #a21caf;
    border: 1px solid #f0abfc;
}

.paanyaya-badge-settled {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

.paanyaya-badge-dismissed {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.paanyaya-badge-escalated {
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}

/* 50/50 Desktop Split Layout */
.paanyaya-split-view {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    align-items: start;
}

@media (max-width: 1024px) {
    .paanyaya-split-view {
        grid-template-columns: 1fr;
    }
}

/* Left Panel: Streamlined Form & Progression Cards */
.paanyaya-form-panel {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.paanyaya-card-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}

.paanyaya-card-box h3 {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.paanyaya-card-box h3 svg {
    color: #0b3b24;
}

.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.form-grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 10px;
}

.form-group-streamlined {
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-bottom: 12px;
}

.form-group-streamlined label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-group-streamlined input,
.form-group-streamlined select,
.form-group-streamlined textarea {
    padding: 8px 12px;
    font-size: 0.9rem;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    color: #0f172a;
    background: #f8fafc;
    transition: all 0.2s ease;
    font-family: inherit;
}

.form-group-streamlined input:focus,
.form-group-streamlined select:focus,
.form-group-streamlined textarea:focus {
    border-color: #0b3b24;
    background: #ffffff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(11, 59, 36, 0.12);
}

.sig-toggle-box {
    background: #f0fdf4;
    border: 1.5px solid #bbf7d0;
    border-radius: 8px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    margin-top: 6px;
    user-select: none;
}

.sig-toggle-box input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #0b3b24;
    cursor: pointer;
}

.sig-toggle-label {
    font-size: 0.88rem;
    font-weight: 600;
    color: #166534;
}

.date-guardrail-alert {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #b91c1c;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.82rem;
    display: none;
    margin-top: 4px;
}

/* Attendance & Progression Engine Card */
.progression-engine-card {
    background: #fafaf9;
    border: 1.5px solid #e7e5e4;
    border-radius: 12px;
    padding: 20px;
}

.attendance-pill-group {
    display: flex;
    gap: 10px;
    margin-top: 4px;
}

.attendance-pill-label {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 12px;
    background: #ffffff;
    border: 1.5px solid #d6d3d1;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.86rem;
    font-weight: 600;
    color: #44403c;
    transition: all 0.2s;
}

.attendance-pill-label input[type="radio"] {
    display: none;
}

.attendance-pill-label.active-present {
    background: #ecfdf5;
    border-color: #10b981;
    color: #047857;
}

.attendance-pill-label.active-absent {
    background: #fef2f2;
    border-color: #ef4444;
    color: #b91c1c;
}

.dynamic-outcome-panel {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px dashed #d6d3d1;
}

.outcome-choice-box {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 8px;
}

.outcome-radio-label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
    background: #ffffff;
    border: 1px solid #e7e5e4;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.88rem;
    color: #1c1917;
    transition: all 0.2s;
}

.outcome-radio-label input[type="radio"] {
    margin-top: 3px;
    accent-color: #0b3b24;
}

.outcome-radio-label:hover {
    border-color: #0b3b24;
    background: #fdfdfc;
}

.outcome-radio-label.selected {
    border-color: #0b3b24;
    background: #f0fdf4;
    font-weight: 600;
}

/* Right Panel: Live Document Preview */
.paanyaya-preview-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.04);
    position: sticky;
    top: 20px;
}

.preview-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f1f5f9;
}

.preview-panel-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 6px;
}

.preview-iframe-wrapper {
    width: 100%;
    height: 780px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    overflow: hidden;
    background: #525659;
}

.preview-iframe-wrapper iframe {
    width: 100%;
    height: 100%;
    border: none;
}
</style>

<div class="paanyaya-workspace-container">

    <!-- Top Status Banner -->
    <div class="paanyaya-stage-header-banner">
        <div>
            <div style="font-size: 0.82rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">
                Stage 0 &bull; Pre-Docketed Informal Dialogue
            </div>
            <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin-top: 2px;">
                <?= $isP2 ? 'Ikalawang Paanyaya (2nd Preliminary Invitation)' : 'Unang Paanyaya (1st Preliminary Invitation)' ?>
            </h2>
        </div>
        <div class="paanyaya-stage-badges">
            <span class="paanyaya-badge <?= $isP2 ? 'paanyaya-badge-p2' : 'paanyaya-badge-p1' ?>">
                <?= htmlspecialchars($currentStage) ?>
            </span>
            <?php if ($isSettled): ?>
                <span class="paanyaya-badge paanyaya-badge-settled">Pribadong Kasunduan (Settled)</span>
            <?php elseif ($isDismissed): ?>
                <span class="paanyaya-badge paanyaya-badge-dismissed">Dismissed</span>
            <?php elseif ($isEscalated): ?>
                <span class="paanyaya-badge paanyaya-badge-escalated">Escalated to Mediation</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- 50/50 Desktop Split Layout -->
    <div class="paanyaya-split-view">

        <!-- ========================================== -->
        <!-- LEFT COLUMN: STREAMLINED FORM & PROGRESSION -->
        <!-- ========================================== -->
        <div class="paanyaya-form-panel">

            <!-- Card 1: Streamlined Paanyaya Inputs -->
            <div class="paanyaya-card-box">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    Paanyaya Form Fields (Streamlined)
                </h3>

                <form id="paanyayaStreamlinedForm" onsubmit="handleSavePaanyaya(event)">
                    <input type="hidden" id="pnyComplaintId" name="complaint_id" value="<?= $complaintId ?>">
                    <input type="hidden" id="pnyStageType" name="stage_type" value="<?= htmlspecialchars($currentStage) ?>">

                    <!-- 1. Complainant Name & Respondent Name -->
                    <div class="form-grid-2">
                        <div class="form-group-streamlined">
                            <label for="pnyComplainantName">Complainant Name (Nagpaanyaya)</label>
                            <input type="text" id="pnyComplainantName" name="complainant_name" value="<?= htmlspecialchars($complainantNameVal) ?>" required oninput="triggerLivePreviewUpdate()">
                        </div>
                        <div class="form-group-streamlined">
                            <label for="pnyRespondentName">Respondent Name (Inanyayahan)</label>
                            <input type="text" id="pnyRespondentName" name="respondent_name" value="<?= htmlspecialchars($respondentNameVal) ?>" required oninput="triggerLivePreviewUpdate()">
                        </div>
                    </div>

                    <!-- 2. Tanggapan (Barangay Tumana) -->
                    <div class="form-group-streamlined">
                        <label for="pnyTanggapan">Tanggapan</label>
                        <input type="text" id="pnyTanggapan" name="tanggapan" value="<?= htmlspecialchars($tanggapanVal) ?>" required oninput="triggerLivePreviewUpdate()">
                    </div>

                    <!-- 3. Master Date Picker with Past Date Guardrail -->
                    <div class="form-grid-2">
                        <div class="form-group-streamlined">
                            <label for="pnyFullDate">Petsa ng Paghaharap (Date)</label>
                            <input type="date"
                                   id="pnyFullDate"
                                   name="invitation_date"
                                   value="<?= htmlspecialchars($invitationDateVal) ?>"
                                   min="<?= date('Y-m-d') ?>"
                                   required
                                   onchange="syncDateComponents(this.value); triggerLivePreviewUpdate();">
                            <div id="pastDateError" class="date-guardrail-alert">
                                &times; Bawal pumili ng nakaraang petsa. Pakiusap pumili ng petsa simula ngayong araw pataas.
                            </div>
                        </div>

                        <div class="form-group-streamlined">
                            <label for="pnyFullTime">Oras (Time)</label>
                            <input type="time"
                                   id="pnyFullTime"
                                   name="invitation_time"
                                   value="<?= htmlspecialchars($invitationTimeVal) ?>"
                                   required
                                   onchange="syncTimeComponents(this.value); triggerLivePreviewUpdate();">
                        </div>
                    </div>

                    <!-- Tagalog Breakdowns: petsa, buwan, taon, araw, oras -->
                    <div class="form-grid-3">
                        <div class="form-group-streamlined">
                            <label for="pnyPetsa">Petsa (Araw Blg.)</label>
                            <input type="text" id="pnyPetsa" name="petsa" value="<?= htmlspecialchars((string)$petsaVal) ?>" required oninput="triggerLivePreviewUpdate()">
                        </div>
                        <div class="form-group-streamlined">
                            <label for="pnyBuwan">Buwan</label>
                            <input type="text" id="pnyBuwan" name="buwan" value="<?= htmlspecialchars((string)$buwanVal) ?>" required oninput="triggerLivePreviewUpdate()">
                        </div>
                        <div class="form-group-streamlined">
                            <label for="pnyTaon">Taon</label>
                            <input type="text" id="pnyTaon" name="taon" value="<?= htmlspecialchars((string)$taonVal) ?>" required oninput="triggerLivePreviewUpdate()">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group-streamlined">
                            <label for="pnyAraw">Araw (Day of Week)</label>
                            <input type="text" id="pnyAraw" name="araw" value="<?= htmlspecialchars((string)$arawVal) ?>" required oninput="triggerLivePreviewUpdate()">
                        </div>
                        <div class="form-group-streamlined">
                            <label for="pnyOras">Oras (Format: 09:00 AM)</label>
                            <input type="text" id="pnyOras" name="oras" value="<?= htmlspecialchars((string)$orasVal) ?>" required oninput="triggerLivePreviewUpdate()">
                        </div>
                    </div>

                    <!-- 4. Salaysay -->
                    <div class="form-group-streamlined">
                        <label for="pnySalaysay">Salaysay (Statement / Matter of Dialogue)</label>
                        <textarea id="pnySalaysay" name="salaysay" rows="4" required oninput="triggerLivePreviewUpdate()"><?= htmlspecialchars($salaysayVal) ?></textarea>
                    </div>

                    <!-- 5. Punong Barangay Signature Toggle -->
                    <label class="sig-toggle-box" for="pnyPrintSig">
                        <input type="checkbox" id="pnyPrintSig" name="has_e_signature" value="1" <?= $hasESignatureVal ? 'checked' : '' ?> onchange="triggerLivePreviewUpdate()">
                        <span class="sig-toggle-label">
                            [✓] Print Punong Barangay Signature (Hon. Akiko Lynne Centeno)
                        </span>
                    </label>
                    <small style="color: #64748b; font-size: 0.78rem; display: block; margin-top: 4px; margin-left: 28px;">
                        Kapag walang tsek, maglalagay ng blankong linya para sa wet-ink manual signing.
                    </small>

                    <!-- Save / Update Button -->
                    <div style="margin-top: 18px; display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="submit" class="btn-create" id="btnSavePaanyayaForm" style="padding: 10px 20px; font-weight: 700;">
                            Save &amp; Update Paanyaya
                        </button>
                    </div>
                </form>
            </div>

            <!-- Card 2: Attendance & Progression Engine (Exact Outcome Tree) -->
            <div class="paanyaya-card-box progression-engine-card">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>
                    Attendance &amp; Progression Engine (<?= htmlspecialchars($currentStage) ?>)
                </h3>

                <form id="progressionEngineForm" onsubmit="handleProgressionSubmit(event)">
                    <input type="hidden" name="complaint_id" value="<?= $complaintId ?>">
                    <input type="hidden" name="stage_type" value="<?= htmlspecialchars($currentStage) ?>">

                    <!-- Attendance Recording -->
                    <div class="form-grid-2">
                        <!-- Complainant Attendance -->
                        <div class="form-group-streamlined">
                            <label>Nagpaanyaya (Complainant)</label>
                            <div class="attendance-pill-group">
                                <label class="attendance-pill-label active-present" id="lblCompPresent">
                                    <input type="radio" name="complainant_attended" value="Present" checked onchange="updateAttendanceState()">
                                    <span>&#10003; Present</span>
                                </label>
                                <label class="attendance-pill-label" id="lblCompAbsent">
                                    <input type="radio" name="complainant_attended" value="Absent" onchange="updateAttendanceState()">
                                    <span>&times; Absent</span>
                                </label>
                            </div>
                        </div>

                        <!-- Respondent Attendance -->
                        <div class="form-group-streamlined">
                            <label>Inanyayahan (Respondent)</label>
                            <div class="attendance-pill-group">
                                <label class="attendance-pill-label active-present" id="lblRespPresent">
                                    <input type="radio" name="respondent_attended" value="Present" checked onchange="updateAttendanceState()">
                                    <span>&#10003; Present</span>
                                </label>
                                <label class="attendance-pill-label" id="lblRespAbsent">
                                    <input type="radio" name="respondent_attended" value="Absent" onchange="updateAttendanceState()">
                                    <span>&times; Absent</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Dynamic Outcome Tree Choices Based on Attendance -->
                    <div class="dynamic-outcome-panel" id="outcomePanel">
                        <!-- Dynamic Content Inserted by JavaScript -->
                    </div>

                    <!-- Submit Progression Button -->
                    <div style="margin-top: 18px; display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn-create" id="btnConfirmProgression" style="background: #0f172a; border-color: #0f172a; padding: 10px 22px; font-weight: 700;">
                            Confirm Attendance &amp; Advance Outcome
                        </button>
                    </div>
                </form>
            </div>

        </div>

        <!-- ========================================== -->
        <!-- RIGHT COLUMN: LIVE A4 / LEGAL IFRAME PREVIEW -->
        <!-- ========================================== -->
        <div class="paanyaya-preview-panel">
            <div class="preview-panel-header">
                <span class="preview-panel-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    Live Document Preview (A4 / Legal)
                </span>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="btn-case-outline" style="font-size: 0.8rem; padding: 4px 10px;" onclick="printPreviewIframe()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print Document
                    </button>
                </div>
            </div>

            <!-- Iframe Embedding paanyaya-preview.php -->
            <div class="preview-iframe-wrapper">
                <iframe id="paanyayaIframePreview"
                        src="../../components/documents/paanyaya-preview.php?complaint_id=<?= $complaintId ?>&stage_type=<?= urlencode($currentStage) ?>&complainant_name=<?= urlencode($complainantNameVal) ?>&respondent_name=<?= urlencode($respondentNameVal) ?>&tanggapan=<?= urlencode($tanggapanVal) ?>&petsa=<?= urlencode((string)$petsaVal) ?>&buwan=<?= urlencode((string)$buwanVal) ?>&taon=<?= urlencode((string)$taonVal) ?>&araw=<?= urlencode((string)$arawVal) ?>&oras=<?= urlencode((string)$orasVal) ?>&salaysay=<?= urlencode($salaysayVal) ?>&has_e_signature=<?= $hasESignatureVal ? '1' : '0' ?>"
                        title="Live Paanyaya Preview">
                </iframe>
            </div>
        </div>

    </div>

</div>

<!-- JavaScript Integration: Live Sync, Date Guardrails, Outcome Progression -->
<script>
const FILIPINO_MONTHS = [
    'Enero', 'Pebrero', 'Marso', 'Abril', 'Mayo', 'Hunyo',
    'Hulyo', 'Agosto', 'Setyembre', 'Oktubre', 'Nobyembre', 'Disyembre'
];

const FILIPINO_DAYS = [
    'Linggo', 'Lunes', 'Martes', 'Miyerkules', 'Huwebes', 'Biyernes', 'Sabado'
];

// Past Date Guardrail & Synchronized Deconstruction
function syncDateComponents(dateVal) {
    const errorBox = document.getElementById('pastDateError');
    if (!dateVal) return;

    const selectedDate = new Date(dateVal + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    // Guardrail: Disallow past dates
    if (selectedDate < today) {
        if (errorBox) errorBox.style.display = 'block';
        document.getElementById('pnyFullDate').value = today.toISOString().split('T')[0];
        syncDateComponents(today.toISOString().split('T')[0]);
        return;
    } else {
        if (errorBox) errorBox.style.display = 'none';
    }

    // Auto-decompose to Tagalog fields
    const dayNumber = selectedDate.getDate();
    const monthName = FILIPINO_MONTHS[selectedDate.getMonth()];
    const yearNumber = selectedDate.getFullYear();
    const dayOfWeek = FILIPINO_DAYS[selectedDate.getDay()];

    document.getElementById('pnyPetsa').value = dayNumber;
    document.getElementById('pnyBuwan').value = monthName;
    document.getElementById('pnyTaon').value = yearNumber;
    document.getElementById('pnyAraw').value = dayOfWeek;
}

function syncTimeComponents(timeVal) {
    if (!timeVal) return;
    const parts = timeVal.split(':');
    let hours = parseInt(parts[0], 10);
    const minutes = parts[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const strTime = (hours < 10 ? '0' + hours : hours) + ':' + minutes + ' ' + ampm;
    document.getElementById('pnyOras').value = strTime;
}

// Live PostMessage Trigger to Iframe Preview
function triggerLivePreviewUpdate() {
    const iframe = document.getElementById('paanyayaIframePreview');
    if (!iframe || !iframe.contentWindow) return;

    const payload = {
        type: 'UPDATE_PAANYAYA_PREVIEW',
        stage_type: document.getElementById('pnyStageType').value,
        complainant_name: document.getElementById('pnyComplainantName').value,
        respondent_name: document.getElementById('pnyRespondentName').value,
        tanggapan: document.getElementById('pnyTanggapan').value,
        petsa: document.getElementById('pnyPetsa').value,
        buwan: document.getElementById('pnyBuwan').value,
        taon: document.getElementById('pnyTaon').value,
        araw: document.getElementById('pnyAraw').value,
        oras: document.getElementById('pnyOras').value,
        salaysay: document.getElementById('pnySalaysay').value,
        has_e_signature: document.getElementById('pnyPrintSig').checked
    };

    iframe.contentWindow.postMessage(payload, '*');
}

// Print Iframe
function printPreviewIframe() {
    const iframe = document.getElementById('paanyayaIframePreview');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}

// Attendance & Dynamic Outcome Tree Logic
function updateAttendanceState() {
    const compPresent = document.querySelector('input[name="complainant_attended"]:checked').value === 'Present';
    const respPresent = document.querySelector('input[name="respondent_attended"]:checked').value === 'Present';

    // Update Pill Styles
    const lblCompPres = document.getElementById('lblCompPresent');
    const lblCompAbs = document.getElementById('lblCompAbsent');
    const lblRespPres = document.getElementById('lblRespPresent');
    const lblRespAbs = document.getElementById('lblRespAbsent');

    lblCompPres.className = compPresent ? 'attendance-pill-label active-present' : 'attendance-pill-label';
    lblCompAbs.className = !compPresent ? 'attendance-pill-label active-absent' : 'attendance-pill-label';
    lblRespPres.className = respPresent ? 'attendance-pill-label active-present' : 'attendance-pill-label';
    lblRespAbs.className = !respPresent ? 'attendance-pill-label active-absent' : 'attendance-pill-label';

    const currentStage = <?= json_encode($currentStage) ?>;
    const isP1 = (currentStage === 'Paanyaya 1');
    const outcomePanel = document.getElementById('outcomePanel');

    let html = '';

    if (isP1) {
        // ==========================================
        // 1ST PAANYAYA OUTCOME TREE
        // ==========================================
        if (compPresent && respPresent) {
            // Both Attended -> Options: Settled Privately OR No Agreement (issue 2nd Paanyaya)
            html = `
                <div style="font-size: 0.85rem; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                    Result of Dialogue (Parehong Dumalo):
                </div>
                <div class="outcome-choice-box">
                    <label class="outcome-radio-label selected" onclick="highlightOutcomeLabel(this)">
                        <input type="radio" name="agreement_status" value="settled_privately" checked>
                        <div>
                            <strong>Settled Privately (Kasulatan sa Pribadong Kasunduan)</strong>
                            <div style="font-size: 0.78rem; color: #475569;">Nagkasundo ang mga partido nang pribado at maayos. Magtatapos ang reklamo nang mapayapa.</div>
                        </div>
                    </label>
                    <label class="outcome-radio-label" onclick="highlightOutcomeLabel(this)">
                        <input type="radio" name="agreement_status" value="no_agreement">
                        <div>
                            <strong>No Agreement &rarr; Issue 2nd Paanyaya</strong>
                            <div style="font-size: 0.78rem; color: #475569;">Walang naabot na kasunduan. Awtorisado upang magtakda ng Ikalawang Paanyaya.</div>
                        </div>
                    </label>
                </div>
            `;
        } else if (!compPresent) {
            // Complainant Absent -> Dismissed
            html = `
                <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 8px; padding: 12px; color: #991b1b;">
                    <strong>&times; Consequence: Dismissed</strong>
                    <p style="font-size: 0.82rem; margin-top: 4px; line-height: 1.4;">
                        Hindi sumipot ang Nagpaanyaya (Complainant) sa itinakdang 1st Paanyaya. Ayon sa patakaran, ang kaso ay ididismis.
                    </p>
                    <input type="hidden" name="agreement_status" value="dismissed">
                </div>
            `;
        } else {
            // Respondent Absent -> Issue 2nd Paanyaya
            html = `
                <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 12px; color: #1e40af;">
                    <strong>&rarr; Next Step: Issue 2nd Paanyaya</strong>
                    <p style="font-size: 0.82rem; margin-top: 4px; line-height: 1.4;">
                        Dumalo ang Nagpaanyaya ngunit hindi sumipot ang Inanyayahan (Respondent). Mag-iisyu ng Ikalawang Paanyaya para sa panibagong pagkakataon.
                    </p>
                    <input type="hidden" name="agreement_status" value="no_agreement">
                </div>
            `;
        }
    } else {
        // ==========================================
        // 2ND PAANYAYA OUTCOME TREE
        // ==========================================
        if (compPresent && respPresent) {
            // Both Attended -> Options: Settled Privately OR No Agreement -> Escalate to Formal Mediation
            html = `
                <div style="font-size: 0.85rem; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                    Result of 2nd Dialogue (Parehong Dumalo):
                </div>
                <div class="outcome-choice-box">
                    <label class="outcome-radio-label selected" onclick="highlightOutcomeLabel(this)">
                        <input type="radio" name="agreement_status" value="settled_privately" checked>
                        <div>
                            <strong>Settled Privately (Kasulatan sa Pribadong Kasunduan)</strong>
                            <div style="font-size: 0.78rem; color: #475569;">Nagkasundo ang mga partido sa Ikalawang Paanyaya. Matatapos ang kaso.</div>
                        </div>
                    </label>
                    <label class="outcome-radio-label" onclick="highlightOutcomeLabel(this)">
                        <input type="radio" name="agreement_status" value="no_agreement">
                        <div>
                            <strong>No Agreement &rarr; Escalate to Formal Mediation</strong>
                            <div style="font-size: 0.78rem; color: #475569;">Walang pagkakasundo matapos ang 2 Paanyaya. Pormal na idodoket sa Mediation para sa pormal na patawag.</div>
                        </div>
                    </label>
                </div>
            `;
        } else if (!compPresent) {
            // Complainant Absent -> Dismissed
            html = `
                <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 8px; padding: 12px; color: #991b1b;">
                    <strong>&times; Consequence: Dismissed</strong>
                    <p style="font-size: 0.82rem; margin-top: 4px; line-height: 1.4;">
                        Hindi sumipot ang Nagpaanyaya (Complainant) sa 2nd Paanyaya. Ang reklamo ay ididismis.
                    </p>
                    <input type="hidden" name="agreement_status" value="dismissed">
                </div>
            `;
        } else {
            // Respondent Absent -> Escalate to Formal Mediation
            html = `
                <div style="background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 8px; padding: 12px; color: #92400e;">
                    <strong>&rarr; Progression: Escalate to Formal Mediation</strong>
                    <p style="font-size: 0.82rem; margin-top: 4px; line-height: 1.4;">
                        Hindi sumipot ang Inanyayahan (Respondent) matapos ang 2 Paanyaya. Pormal nang idodoket ang kaso at ililipat sa Punong Barangay Mediation upang mai-isyu ang Pormal na Summons (KP Form 9).
                    </p>
                    <input type="hidden" name="agreement_status" value="no_agreement">
                </div>
            `;
        }
    }

    outcomePanel.innerHTML = html;
}

function highlightOutcomeLabel(el) {
    document.querySelectorAll('.outcome-radio-label').forEach(label => label.classList.remove('selected'));
    el.classList.add('selected');
}

// Handle Form Save via AJAX
async function handleSavePaanyaya(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSavePaanyayaForm');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const form = document.getElementById('paanyayaStreamlinedForm');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.action = 'save';
    payload.has_e_signature = document.getElementById('pnyPrintSig').checked ? 1 : 0;

    try {
        const res = await fetch('../../../backend/api/complaints/paanyaya-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || 'Matagumpay na na-save ang Paanyaya.');
            triggerLivePreviewUpdate();
        } else {
            alert(data.message || 'Nagkaroon ng aberya sa pag-save.');
        }
    } catch (err) {
        alert('Server connection error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save & Update Paanyaya';
    }
}

// Handle Progression Engine Submit via AJAX
async function handleProgressionSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmProgression');
    btn.disabled = true;
    btn.textContent = 'Processing Outcome...';

    const form = document.getElementById('progressionEngineForm');
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.action = 'progress';

    try {
        const res = await fetch('../../../backend/api/complaints/paanyaya-actions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || 'Naipatupad ang desisyon sa Paanyaya.');
            window.location.reload();
        } else {
            alert(data.message || 'Nabigo ang pagsusumite ng desisyon.');
        }
    } catch (err) {
        alert('Server connection error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Confirm Attendance & Advance Outcome';
    }
}

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', function() {
    updateAttendanceState();
    triggerLivePreviewUpdate();
});
</script>
