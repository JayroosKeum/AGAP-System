<?php
/**
 * 3-Step Hearing Session Recording Wizard Modal (Option A)
 * Used for Mediation and Pangkat Conciliation sessions with sectioned Minutes of the Meeting.
 */
?>
<style>
.hsw-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.58);
    backdrop-filter: blur(2px);
    z-index: 9994;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
}
.hsw-overlay.is-open {
    display: flex;
}
.hsw-dialog {
    background: #ffffff;
    width: 100%;
    max-width: 1060px;
    height: 90vh;
    max-height: 820px;
    border-radius: 14px;
    border: 1px solid #cbd5e1;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.28);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    font-family: inherit;
}
.hsw-topbar {
    padding: 13px 22px;
    border-bottom: 1px solid #e2e8f0;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-shrink: 0;
}
.hsw-topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    flex-wrap: wrap;
}
.hsw-kp-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 6px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #2563eb;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
}
.hsw-topbar-title {
    margin: 0;
    font-size: 0.96rem;
    font-weight: 700;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.hsw-close-btn {
    background: transparent;
    border: none;
    font-size: 1.45rem;
    line-height: 1;
    color: #64748b;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
}
.hsw-close-btn:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.hsw-body {
    display: grid;
    grid-template-columns: 265px 1fr;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
    background: #f8fafc;
}
.hsw-sidebar {
    background: #ffffff;
    border-right: 1px solid #e2e8f0;
    padding: 20px 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    overflow-y: auto;
}
.hsw-progress-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
}
.hsw-progress-title {
    font-size: 0.86rem;
    font-weight: 800;
    color: #0f172a;
}
.hsw-progress-counter {
    font-size: 0.8rem;
    font-weight: 600;
    color: #64748b;
}
.hsw-progress-track {
    height: 4px;
    background: #e2e8f0;
    border-radius: 9999px;
    overflow: hidden;
}
.hsw-progress-fill {
    height: 100%;
    width: 33.33%;
    background: #2563eb;
    border-radius: 9999px;
    transition: width 0.2s ease;
}
.hsw-step-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 4px;
}
.hsw-step-btn {
    display: flex;
    align-items: center;
    gap: 11px;
    width: 100%;
    text-align: left;
    padding: 11px 12px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.15s ease;
}
.hsw-step-btn:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.hsw-step-btn.is-active {
    background: #eff6ff;
    border: 1.5px solid #93c5fd;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.08);
}
.hsw-step-btn.is-done {
    background: #f0fdf4;
    border-color: #86efac;
}
.hsw-step-num {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 800;
    flex-shrink: 0;
    background: #f1f5f9;
    color: #475569;
}
.hsw-step-btn.is-active .hsw-step-num {
    background: #2563eb;
    color: #ffffff;
}
.hsw-step-btn.is-done .hsw-step-num {
    background: #16a34a;
    color: #ffffff;
}
.hsw-step-label {
    display: block;
    font-size: 0.84rem;
    font-weight: 700;
    color: #0f172a;
}
.hsw-step-btn.is-active .hsw-step-label {
    color: #1d4ed8;
}
.hsw-step-btn.is-done .hsw-step-label {
    color: #15803d;
}
.hsw-step-sub {
    display: block;
    font-size: 0.74rem;
    color: #64748b;
    margin-top: 1px;
}
.hsw-sidebar-meta {
    margin-top: auto;
    padding: 11px 12px;
    border-radius: 9px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    font-size: 0.76rem;
    color: #475569;
    line-height: 1.45;
}
.hsw-main {
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    padding: 18px 22px;
    box-sizing: border-box;
}
.hsw-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}
.hsw-card-scroll {
    padding: 22px 24px;
    overflow-y: auto;
    flex: 1 1 auto;
    min-height: 0;
}
.hsw-step-heading {
    margin: 0 0 4px 0;
    font-size: 1.25rem;
    font-weight: 800;
    color: #0f172a;
}
.hsw-step-desc {
    margin: 0 0 18px 0;
    font-size: 0.84rem;
    color: #64748b;
}
.hsw-party-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 14px;
}
.hsw-party-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
}
.hsw-party-role {
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    display: block;
    margin-bottom: 2px;
}
.hsw-party-role.is-comp { color: #2563eb; }
.hsw-party-role.is-resp { color: #dc2626; }
.hsw-party-name {
    font-size: 0.98rem;
    font-weight: 800;
    color: #0f172a;
}
.hsw-party-pill {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 6px;
    white-space: nowrap;
}
.hsw-party-pill.is-comp { background: #eff6ff; color: #1d4ed8; }
.hsw-party-pill.is-resp { background: #fef2f2; color: #b91c1c; }
.hsw-att-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}
.hsw-att-opt {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 9px 8px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    background: #ffffff;
    text-align: center;
    transition: all 0.12s ease;
}
.hsw-att-opt:has(input:checked) {
    border-color: #2563eb;
    background: #eff6ff;
    box-shadow: 0 0 0 1px #93c5fd;
}
.hsw-att-opt.opt-present { color: #0f172a; }
.hsw-att-opt.opt-absent { color: #991b1b; }
.hsw-att-opt.opt-late { color: #b45309; }
.hsw-att-opt.opt-excused { color: #1d4ed8; }
.hsw-input, .hsw-select, .hsw-textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.86rem;
    font-family: inherit;
    color: #0f172a;
    background: #ffffff;
}
.hsw-input:focus, .hsw-select:focus, .hsw-textarea:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}
.hsw-quickcite-box {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 10px;
    padding: 13px 15px;
    margin-bottom: 16px;
}
.hsw-quickcite-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}
.hsw-quickcite-row {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 8px;
}
.hsw-min-sec {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 13px 15px;
    margin-bottom: 12px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.hsw-min-sec.is-focused-target {
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.12);
}
.hsw-min-sec-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 6px;
    flex-wrap: wrap;
}
.hsw-min-sec-label {
    font-size: 0.84rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 6px;
}
.hsw-sec-tag {
    font-size: 0.68rem;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 9999px;
    background: #f1f5f9;
    color: #475569;
    text-transform: uppercase;
}
.hsw-target-pill {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 9999px;
    background: #dbeafe;
    color: #1d4ed8;
    display: none;
}
.hsw-min-sec.is-focused-target .hsw-target-pill {
    display: inline-flex;
}
.hsw-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
}
.hsw-ai-btn {
    font-size: 0.76rem;
    padding: 5px 11px;
    border-radius: 9999px;
    border: 1px solid #93c5fd;
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.hsw-ai-btn:hover { background: #dbeafe; }
.hsw-ai-btn.is-recording { background: #ef4444; border-color: #dc2626; color: #ffffff; }
.hsw-ai-btn.is-purple { background: #f5f3ff; border-color: #c4b5fd; color: #6d28d9; }
.hsw-dropzone {
    border: 1.5px dashed #94a3b8;
    border-radius: 10px;
    padding: 16px;
    text-align: center;
    background: #f8fafc;
    cursor: pointer;
}
.hsw-dropzone:hover { border-color: #2563eb; background: #eff6ff; }
.hsw-outcome-card {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border-radius: 10px;
    padding: 12px 14px;
    border: 1.5px solid #e2e8f0;
    background: #ffffff;
    cursor: pointer;
    margin-bottom: 10px;
    transition: all 0.14s ease;
}
.hsw-outcome-card.tone-green { background: #f0fdf4; border-color: #86efac; color: #166534; }
.hsw-outcome-card.tone-blue { background: #eff6ff; border-color: #93c5fd; color: #1e40af; }
.hsw-outcome-card.tone-red { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
.hsw-outcome-card.tone-amber { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.hsw-outcome-card.tone-slate { background: #f8fafc; border-color: #cbd5e1; color: #334155; }
.hsw-outcome-card:has(input:checked) {
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.25);
    border-color: #2563eb;
}
.hsw-footer {
    padding: 13px 24px;
    border-top: 1px solid #e2e8f0;
    background: #ffffff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.hsw-alert {
    padding: 10px 13px;
    border-radius: 8px;
    font-size: 0.82rem;
    margin-bottom: 14px;
    line-height: 1.45;
}
.hsw-alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.hsw-alert-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.hsw-alert-err  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
@media (max-width: 820px) {
    .hsw-body { grid-template-columns: 1fr; }
    .hsw-sidebar { border-right: none; border-bottom: 1px solid #e2e8f0; padding: 12px 14px; }
    .hsw-step-list { flex-direction: row; overflow-x: auto; }
    .hsw-att-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>

<div id="hearingSessionWizardModal" class="hsw-overlay" role="dialog" aria-modal="true" aria-labelledby="hswTopbarTitle">
    <div class="hsw-dialog">
        <!-- Top Header -->
        <div class="hsw-topbar">
            <div class="hsw-topbar-left">
                <span class="hsw-kp-badge">Katarungang Pambarangay</span>
                <h2 id="hswTopbarTitle" class="hsw-topbar-title">Case #— · Hearing Session Recording</h2>
            </div>
            <button type="button" class="hsw-close-btn" onclick="closeHearingSessionWizard()" aria-label="Close session wizard">&times;</button>
        </div>

        <!-- Body: Sidebar + Active Step Card -->
        <div class="hsw-body">
            <aside class="hsw-sidebar">
                <div class="hsw-progress-head">
                    <span class="hsw-progress-title">Session Progress</span>
                    <span id="hswStepCounter" class="hsw-progress-counter">Step 1 of 3</span>
                </div>
                <div class="hsw-progress-track">
                    <div id="hswProgressFill" class="hsw-progress-fill"></div>
                </div>

                <div class="hsw-step-list">
                    <button type="button" id="hswNavStep1" class="hsw-step-btn is-active" onclick="hswGoStep(1)">
                        <span class="hsw-step-num" id="hswNavNum1">1</span>
                        <span>
                            <span class="hsw-step-label">People involved</span>
                            <span class="hsw-step-sub">Complainants &amp; respondents</span>
                        </span>
                    </button>
                    <button type="button" id="hswNavStep2" class="hsw-step-btn" onclick="hswGoStep(2)">
                        <span class="hsw-step-num" id="hswNavNum2">2</span>
                        <span>
                            <span class="hsw-step-label">Narrative</span>
                            <span class="hsw-step-sub">Sectioned minutes &amp; statements</span>
                        </span>
                    </button>
                    <button type="button" id="hswNavStep3" class="hsw-step-btn" onclick="hswGoStep(3)">
                        <span class="hsw-step-num" id="hswNavNum3">3</span>
                        <span>
                            <span class="hsw-step-label">Outcome &amp; evidence</span>
                            <span class="hsw-step-sub">Relief sought &amp; resolution</span>
                        </span>
                    </button>
                </div>

                <div id="hswSidebarMeta" class="hsw-sidebar-meta">
                    Loading session details...
                </div>
            </aside>

            <div class="hsw-main">
                <div class="hsw-card">
                    <div class="hsw-card-scroll" id="hswScrollArea">
                        <div id="hswGlobalBanner" class="hsw-alert hsw-alert-err" style="display: none;"></div>

                        <!-- STEP 1: PEOPLE INVOLVED -->
                        <div id="hswStep1Panel">
                            <h3 class="hsw-step-heading">People involved</h3>
                            <p class="hsw-step-desc">Record complainant, respondent, and presiding officer attendance for this hearing session.</p>
                            <div id="hswServiceGateBanner" class="hsw-alert hsw-alert-warn" style="display: none;"></div>
                            <div id="hswPartiesContainer"></div>

                            <div class="hsw-party-card" style="background: #f8fafc;">
                                <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 12px; align-items: end;">
                                    <div>
                                        <label for="hswPresiderDisplay" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 5px;">
                                            Tagapamagitan / Presider ng Pagdinig:
                                        </label>
                                        <input type="text" id="hswPresiderDisplay" class="hsw-input" readonly style="background: #ffffff; font-weight: 600;">
                                    </div>
                                    <div>
                                        <label for="hswActualStartTime" style="display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 5px;">
                                            Aktwal na Oras ng Simula:
                                        </label>
                                        <input type="time" id="hswActualStartTime" class="hsw-input">
                                    </div>
                                </div>
                                <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" id="hswPresiderAbsentToggle" onchange="hswToggleSubstitutePresider()">
                                        <span>Presider Absent (Assign Substitute)</span>
                                    </label>
                                </div>
                                <div id="hswSubstituteWrap" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                                        <div>
                                            <label style="display: block; font-size: 0.76rem; font-weight: 700; color: #334155; margin-bottom: 4px;">Substitute Lupon Officer</label>
                                            <select id="hswSubstitutePresiderId" class="hsw-select"></select>
                                        </div>
                                        <div>
                                            <label style="display: block; font-size: 0.76rem; font-weight: 700; color: #334155; margin-bottom: 4px;">Reason for Substitution</label>
                                            <input type="text" id="hswSubstituteReason" class="hsw-input" placeholder="e.g., Official leave / conflict...">
                                        </div>
                                    </div>
                                    <label style="font-size: 0.76rem; color: #1e40af; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" id="hswSubstituteConsent">
                                        <span>Both parties consented to the substitute presider</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 2: NARRATIVE (SECTIONED MINUTES OF THE MEETING) -->
                        <div id="hswStep2Panel" style="display: none;">
                            <h3 class="hsw-step-heading">Narrative</h3>
                            <p class="hsw-step-desc">Record each part of the Minutes of the Meeting in structured sections and cite prior Salaysay or Paanyaya dialogues.</p>

                            <!-- Quick Cite Tool -->
                            <div class="hsw-quickcite-box">
                                <div class="hsw-quickcite-head">
                                    <strong style="font-size: 0.85rem; color: #15803d;">Gabay sa Salaysay at Quick Cite Tool</strong>
                                    <span style="font-size: 0.72rem; font-weight: 700; color: #15803d;">ACCOC / KP Standard</span>
                                </div>
                                <div style="font-size: 0.78rem; color: #166534;">
                                    Maaaring sumipi mula sa orihinal na salaysay o nakaraang tala upang ilagay sa napiling seksyon ng katitikan.
                                    <span id="hswQuickCiteTargetLabel" style="font-weight: 700; margin-left: 4px;">(Target: Pahayag ng May Sumbong)</span>
                                </div>
                                <div class="hsw-quickcite-row">
                                    <select id="hswQuickCiteSelect" class="hsw-select" style="flex: 1; font-size: 0.82rem;">
                                        <option value="">— Pumili ng Sisipiing Salaysay / Tala —</option>
                                    </select>
                                    <button type="button" class="btn-create" onclick="hswInsertQuickCite()" style="background: #166534; border-color: #166534; padding: 8px 13px; font-size: 0.8rem; white-space: nowrap;">
                                        + Sipiin sa Buod (Insert)
                                    </button>
                                </div>
                            </div>

                            <!-- Minutes Header + Voice/OCR/AI Toolbar -->
                            <div class="hsw-toolbar">
                                <div>
                                    <strong style="font-size: 0.9rem; color: #0f172a;">Katitikan ng Pag-uusap at Buod ng Sesyon (Minutes of the Meeting) <span style="color:#dc2626;">*</span></strong>
                                    <div style="font-size: 0.74rem; color: #64748b;">Split into structured sections for Complainant's Statement, Respondent's Statement, Discussion, and Agreements.</div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 7px; flex-wrap: wrap;">
                                    <label style="font-size: 0.73rem; color: #475569; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                        <input type="checkbox" id="hswAiConsent" checked style="accent-color: #2563eb;">
                                        <span>Gemini AI Consent</span>
                                    </label>
                                    <button type="button" id="hswBtnVoice" class="hsw-ai-btn" onclick="hswToggleVoiceRecording()">
                                        <span id="hswVoiceLabel">🎙️ Magsalita (Speech-to-Text)</span>
                                    </button>
                                    <button type="button" id="hswBtnOcr" class="hsw-ai-btn" onclick="document.getElementById('hswOcrFileInput').click()">
                                        📷 OCR Notes
                                    </button>
                                    <button type="button" id="hswBtnAiDraft" class="hsw-ai-btn is-purple" onclick="hswGenerateAiDraft()">
                                        ✨ AI Draft Sections
                                    </button>
                                    <input type="file" id="hswOcrFileInput" accept="image/*,.pdf" style="display: none;" onchange="hswHandleOcrScan(this)">
                                </div>
                            </div>

                            <div id="hswAiStatusBanner" class="hsw-alert hsw-alert-info" style="display: none;"></div>
                            <div id="hswAiDraftBox" style="display: none; margin-bottom: 12px;"></div>

                            <!-- Section 1: Main Dispute -->
                            <div class="hsw-min-sec" id="hswWrapMainDispute">
                                <div class="hsw-min-sec-head">
                                    <label for="hswSecMainDispute" class="hsw-min-sec-label">
                                        <span class="hsw-sec-tag">Seksyon 1</span>
                                        <span>Pangunahing Usapin (Main Dispute / Agenda)</span>
                                    </label>
                                </div>
                                <input type="text" id="hswSecMainDispute" class="hsw-input" maxlength="1000" placeholder="Halimbawa: Paniningil ng utang, alitan sa boundary, o pagbabayad ng danyos...">
                            </div>

                            <!-- Section 2: Complainant's Statement -->
                            <div class="hsw-min-sec is-focused-target" id="hswWrapCompStmt">
                                <div class="hsw-min-sec-head">
                                    <label for="hswSecCompStmt" class="hsw-min-sec-label">
                                        <span class="hsw-sec-tag" style="background:#eff6ff; color:#1d4ed8;">Seksyon 2</span>
                                        <span>Pahayag ng May Sumbong (Complainant's Statement)</span>
                                    </label>
                                    <span class="hsw-target-pill">Active Quick Cite / Voice Target</span>
                                </div>
                                <textarea id="hswSecCompStmt" class="hsw-textarea" rows="3" maxlength="5000"
                                    onfocus="hswSetActiveSection('hswSecCompStmt', 'Pahayag ng May Sumbong')"
                                    placeholder="Itala ang direktang pahayag, reklamo, at hinihinging solusyon ng may sumbong sa sesyong ito..."></textarea>
                            </div>

                            <!-- Section 3: Respondent's Statement -->
                            <div class="hsw-min-sec" id="hswWrapRespStmt">
                                <div class="hsw-min-sec-head">
                                    <label for="hswSecRespStmt" class="hsw-min-sec-label">
                                        <span class="hsw-sec-tag" style="background:#fef2f2; color:#b91c1c;">Seksyon 3</span>
                                        <span>Tugon / Pahayag ng Ipinagsusumbong (Respondent's Statement)</span>
                                    </label>
                                    <span class="hsw-target-pill">Active Quick Cite / Voice Target</span>
                                </div>
                                <textarea id="hswSecRespStmt" class="hsw-textarea" rows="3" maxlength="5000"
                                    onfocus="hswSetActiveSection('hswSecRespStmt', 'Tugon ng Ipinagsusumbong')"
                                    placeholder="Itala ang paliwanag, sagot, o counter-statement ng ipinagsusumbong (o dahilan kung hindi sumipot)..."></textarea>
                            </div>

                            <!-- Section 4: Discussion Summary & Caucus -->
                            <div class="hsw-min-sec" id="hswWrapDiscussion">
                                <div class="hsw-min-sec-head">
                                    <label for="hswSecDiscussion" class="hsw-min-sec-label">
                                        <span class="hsw-sec-tag" style="background:#f0fdf4; color:#166534;">Seksyon 4</span>
                                        <span>Buod ng Talakayan at Pamamagitan (Discussion Summary)</span>
                                    </label>
                                    <span class="hsw-target-pill">Active Quick Cite / Voice Target</span>
                                </div>
                                <textarea id="hswSecDiscussion" class="hsw-textarea" rows="4" maxlength="5000"
                                    onfocus="hswSetActiveSection('hswSecDiscussion', 'Buod ng Talakayan')"
                                    placeholder="Ibuod ang naging daloy ng pag-uusap, paglilinaw ng Tagapamagitan / Pangkat, at mga puntong tinalakay..."></textarea>

                                <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #e2e8f0;">
                                    <label style="font-size: 0.78rem; font-weight: 600; color: #475569; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                                        <input type="checkbox" id="hswSecCaucusConducted" onchange="document.getElementById('hswCaucusProposalsWrap').style.display = this.checked ? 'grid' : 'none';">
                                        <span>May isinagawang Private Caucus / Hiwalay na Pag-uusap o Palitan ng Alok (Proposals)</span>
                                    </label>
                                    <div id="hswCaucusProposalsWrap" style="display: none; grid-template-columns: 1fr; gap: 8px; margin-top: 8px;">
                                        <textarea id="hswSecCaucusNotes" class="hsw-textarea" rows="2" maxlength="3000"
                                            onfocus="hswSetActiveSection('hswSecCaucusNotes', 'Private Caucus Notes')"
                                            placeholder="Mga tala sa private caucus (kung mayroon)..."></textarea>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                            <input type="text" id="hswSecNewProposal" class="hsw-input" maxlength="3000" placeholder="Bagong Mungkahi / Offer...">
                                            <input type="text" id="hswSecCounteroffer" class="hsw-input" maxlength="3000" placeholder="Kontra-Mungkahi / Counteroffer...">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 5: Agreements & Unresolved Issues -->
                            <div class="hsw-min-sec" id="hswWrapAgreements">
                                <div class="hsw-min-sec-head">
                                    <span class="hsw-min-sec-label">
                                        <span class="hsw-sec-tag" style="background:#fffbeb; color:#92400e;">Seksyon 5</span>
                                        <span>Mga Napagkasunduang Hakbang at Natitirang Usapin (Agreements &amp; Unresolved Issues)</span>
                                    </span>
                                    <span class="hsw-target-pill">Active Quick Cite / Voice Target</span>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <div>
                                        <label for="hswSecAgreements" style="display: block; font-size: 0.76rem; font-weight: 700; color: #166534; margin-bottom: 4px;">Mga Napagkasunduan / Action Items</label>
                                        <textarea id="hswSecAgreements" class="hsw-textarea" rows="2" maxlength="3000"
                                            onfocus="hswSetActiveSection('hswSecAgreements', 'Mga Napagkasunduan')"
                                            placeholder="Mga pansamantala o pinal na napagkasunduan..."></textarea>
                                    </div>
                                    <div>
                                        <label for="hswSecUnresolved" style="display: block; font-size: 0.76rem; font-weight: 700; color: #991b1b; margin-bottom: 4px;">Mga Hindi Pa Nalutas (Carried Over)</label>
                                        <textarea id="hswSecUnresolved" class="hsw-textarea" rows="2" maxlength="3000"
                                            onfocus="hswSetActiveSection('hswSecUnresolved', 'Mga Hindi Pa Nalutas')"
                                            placeholder="Mga usaping ipagpapatuloy sa susunod na pagdinig..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Handwritten / Scanned Minutes Upload -->
                            <div style="margin-top: 14px; padding-top: 14px; border-top: 1px dashed #cbd5e1;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; gap: 8px;">
                                    <strong style="font-size: 0.84rem; color: #0f172a;">Kopya ng Isinulat-Kamay o Nilagdaang Katitikan (Scanned / Handwritten Minutes)</strong>
                                    <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 9999px;">Opsiyonal na Patunay</span>
                                </div>
                                <p style="margin: 0 0 8px; font-size: 0.76rem; color: #64748b;">
                                    Maaaring mag-attach ng litrato o scan ng sulat-kamay na katitikan na may lagda ng mga partido (JPG, PNG, PDF, WEBP hanggang 10MB).
                                </p>
                                <div class="hsw-dropzone" onclick="document.getElementById('hswMinutesFileInput').click()">
                                    <div style="font-size: 1.25rem; margin-bottom: 4px;">⬆️</div>
                                    <div style="font-size: 0.84rem; font-weight: 700; color: #1e293b;">I-click dito upang mag-upload ng scanned handwritten minutes o ebidensya</div>
                                    <div style="font-size: 0.74rem; color: #64748b; margin-top: 2px;">JPG, PNG, PDF, o WEBP (Hanggang 10MB)</div>
                                    <input type="file" id="hswMinutesFileInput" accept=".jpg,.jpeg,.png,.pdf,.webp" multiple style="display: none;" onchange="hswUploadFiles(this)">
                                </div>
                                <div id="hswUploadedDocsList" style="margin-top: 8px;"></div>
                            </div>
                        </div>

                        <!-- STEP 3: OUTCOME & EVIDENCE -->
                        <div id="hswStep3Panel" style="display: none;">
                            <h3 class="hsw-step-heading">Outcome &amp; evidence</h3>
                            <p class="hsw-step-desc">Relief sought, session conclusion, and next steps under Katarungang Pambarangay.</p>

                            <div id="hswRecommendationBanner" class="hsw-alert hsw-alert-info" style="display: none;"></div>
                            <div id="hswOutcomeCardsContainer"></div>

                            <!-- Conditional Next Session Schedule Box -->
                            <div id="hswNextScheduleBox" style="display: none; background: #eff6ff; border: 1.5px solid #93c5fd; border-radius: 10px; padding: 15px 16px; margin-top: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
                                    <strong style="font-size: 0.88rem; color: #1e40af;">📅 Iskedyul ng Susunod na Pagpupulong / Pagdinig (Next Session Schedule)</strong>
                                    <span id="hswNextSchedulePill" style="font-size: 0.72rem; font-weight: 700; padding: 2px 9px; border-radius: 9999px; background: #dbeafe; color: #1d4ed8;">Next Session</span>
                                </div>
                                <p id="hswNextScheduleHint" style="margin: 0 0 12px; font-size: 0.78rem; color: #1d4ed8; line-height: 1.45;">
                                    Itakda ang petsa, oras, at bulwagan para sa susunod na sesyon. Ayon sa Sec. 410 ng RA 7160, kung naroroon ang mga partido, sila ay personal na inaabisuhan sa loob ng sesyon (summons KP Form 9 is suppressed).
                                </p>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                                    <div>
                                        <label for="hswNextDate" style="display: block; font-size: 0.78rem; font-weight: 700; color: #1e3a8a; margin-bottom: 4px;">
                                            Petsa ng Susunod na Sesyon <span style="color:#dc2626;">*</span>
                                        </label>
                                        <input type="date" id="hswNextDate" class="hsw-input">
                                        <small id="hswNextDateHelp" style="display: block; font-size: 0.72rem; color: #475569; margin-top: 3px;">Pumili ng regular na araw ng trabaho.</small>
                                    </div>
                                    <div>
                                        <label for="hswNextTime" style="display: block; font-size: 0.78rem; font-weight: 700; color: #1e3a8a; margin-bottom: 4px;">
                                            Oras ng Sesyon <span style="color:#dc2626;">*</span>
                                        </label>
                                        <input type="time" id="hswNextTime" class="hsw-input" value="14:00" step="900">
                                    </div>
                                </div>
                                <div>
                                    <label for="hswNextVenue" style="display: block; font-size: 0.78rem; font-weight: 700; color: #1e3a8a; margin-bottom: 4px;">Lugar / Bulwagan (Venue)</label>
                                    <input type="text" id="hswNextVenue" class="hsw-input" value="Lupon Court Room">
                                </div>
                            </div>

                            <!-- Session End Time & Outcome Remarks -->
                            <div style="display: grid; grid-template-columns: 1fr 1.6fr; gap: 12px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                                <div>
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <label for="hswActualEndTime" style="font-size: 0.78rem; font-weight: 700; color: #334155;">Aktwal na Oras ng Pagtatapos</label>
                                        <button type="button" onclick="hswSetEndTimeNow()" style="background:none; border:none; color:#2563eb; font-size:0.72rem; font-weight:700; cursor:pointer; padding:0;">Set Now</button>
                                    </div>
                                    <input type="time" id="hswActualEndTime" class="hsw-input">
                                </div>
                                <div>
                                    <label for="hswOutcomeRemarks" style="display: block; font-size: 0.78rem; font-weight: 700; color: #334155; margin-bottom: 4px;">Opisyal na Tala / Remarks (Opsyonal)</label>
                                    <input type="text" id="hswOutcomeRemarks" class="hsw-input" maxlength="1000" placeholder="Mga tagubilin, referral notes, o buod ng kinalabasan...">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sticky Card Footer -->
                    <div class="hsw-footer">
                        <div>
                            <button type="button" id="hswBtnBack" class="btn-secondary" onclick="hswPrevStep()" style="display: none; font-size: 0.84rem; padding: 8px 14px;">
                                &larr; Bumalik
                            </button>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="btn-secondary" onclick="closeHearingSessionWizard()" style="font-size: 0.84rem; padding: 8px 14px;">
                                Kanselahin
                            </button>
                            <button type="button" id="hswBtnSaveDraft" class="btn-secondary" onclick="hswSaveMinutes('Draft')" style="display: none; font-size: 0.84rem; padding: 8px 14px;">
                                Save Draft
                            </button>
                            <button type="button" id="hswBtnNext" class="btn-create" onclick="hswNextStep()" style="background: #2563eb; border-color: #2563eb; font-size: 0.84rem; padding: 8px 18px; font-weight: 700;">
                                Susunod &rarr;
                            </button>
                            <button type="button" id="hswBtnFinalize" class="btn-create" onclick="hswSaveMinutes('Finalized')" style="display: none; background: #16a34a; border-color: #16a34a; font-size: 0.84rem; padding: 8px 18px; font-weight: 700;">
                                Save &amp; Finalize
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
