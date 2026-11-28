<?php
/**
 * 3-Step Hearing Session Recording Wizard Modal
 * Used for Mediation and Pangkat Conciliation sessions.
 */
?>
<style>
.hsw-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.56);
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
    max-width: 1020px;
    height: 88vh;
    max-height: 760px;
    border-radius: 14px;
    border: 1px solid #cbd5e1;
    box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.25);
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
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.hsw-top-step-counter {
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
    white-space: nowrap;
}
.hsw-body {
    display: grid;
    grid-template-columns: 245px 1fr;
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
    gap: 12px;
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
    font-weight: 700;
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
    margin-bottom: 6px;
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
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
}
.hsw-step-btn.is-done {
    background: #ffffff;
    border-color: #e2e8f0;
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
    color: #64748b;
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
.hsw-step-sub {
    display: block;
    font-size: 0.74rem;
    color: #64748b;
    margin-top: 1px;
}
.hsw-main {
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
    padding: 18px 22px;
    box-sizing: border-box;
    background: #ffffff;
}
.hsw-card {
    background: #ffffff;
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
}
.hsw-card-scroll {
    padding: 6px 6px 14px;
    overflow-y: auto;
    flex: 1 1 auto;
    min-height: 0;
}
.hsw-step-head-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.hsw-step-heading {
    margin: 0 0 16px 0;
    font-size: 1.2rem;
    font-weight: 800;
    color: #0f172a;
}
.hsw-step-head-row .hsw-step-heading {
    margin: 0;
}
.hsw-nosession-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    font-size: 0.8rem;
    font-weight: 700;
    color: #b45309;
    cursor: pointer;
}
.hsw-lock-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 9999px;
    background: #fef3c7;
    border: 1px solid #fcd34d;
    color: #92400e;
    font-size: 0.74rem;
    font-weight: 700;
}
.hsw-party-card,
.hsw-section-card {
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
    font-size: 0.7rem;
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
.hsw-party-tag {
    font-size: 0.75rem;
    font-weight: 700;
    white-space: nowrap;
}
.hsw-party-tag.is-comp { color: #2563eb; }
.hsw-party-tag.is-resp { color: #dc2626; }
.hsw-att-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}
.hsw-att-opt {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 8px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 9px 12px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    background: #ffffff;
    transition: all 0.12s ease;
}
.hsw-att-opt:has(input:checked) {
    border-color: #93c5fd;
    background: #f8fbff;
    box-shadow: 0 0 0 1px #bfdbfe;
}
.hsw-att-opt.opt-present { color: #0f172a; }
.hsw-att-opt.opt-absent { color: #dc2626; }
.hsw-att-opt.opt-late { color: #d97706; }
.hsw-att-opt.opt-excused { color: #2563eb; }
.hsw-input, .hsw-select, .hsw-textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 9px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.85rem;
    font-family: inherit;
    color: #0f172a;
    background: #ffffff;
}
.hsw-input:focus, .hsw-select:focus, .hsw-textarea:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}
.hsw-input:disabled, .hsw-select:disabled, .hsw-textarea:disabled {
    background: #f8fafc;
    color: #64748b;
    cursor: not-allowed;
}
.hsw-minutes-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    flex-wrap: nowrap;
}
.hsw-btn-blue {
    background: #2563eb;
    color: #ffffff;
    border: 1px solid #2563eb;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.83rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.hsw-btn-blue:hover:not(:disabled) {
    background: #1d4ed8;
    border-color: #1d4ed8;
}
.hsw-btn-blue:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.hsw-btn-outline {
    background: #ffffff;
    color: #1e293b;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.83rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.hsw-btn-outline:hover:not(:disabled) {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.hsw-btn-outline:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.hsw-btn-voice {
    color: #2563eb;
}
.hsw-btn-voice.is-recording {
    background: #fef2f2;
    border-color: #ef4444;
    color: #dc2626;
}
.hsw-attach-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 16px;
}
.hsw-attach-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.hsw-outcome-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 14px;
}
.hsw-outcome-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border-radius: 10px;
    padding: 13px 14px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.14s ease;
}
.hsw-outcome-card:hover {
    border-color: #cbd5e1;
}
.hsw-outcome-card:has(input:checked) {
    border-color: #93c5fd;
    background: #f8fbff;
    box-shadow: 0 0 0 1px #bfdbfe;
}
.hsw-outcome-left {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
}
.hsw-outcome-title {
    font-size: 0.85rem;
    font-weight: 700;
}
.hsw-outcome-card.tone-green .hsw-outcome-title { color: #16a34a; }
.hsw-outcome-card.tone-blue .hsw-outcome-title { color: #2563eb; }
.hsw-outcome-card.tone-red .hsw-outcome-title { color: #dc2626; }
.hsw-outcome-card.tone-amber .hsw-outcome-title { color: #d97706; }
.hsw-outcome-card.tone-slate .hsw-outcome-title { color: #334155; }
.hsw-suggested-badge {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #2563eb;
    white-space: nowrap;
}
.hsw-sched-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 12px;
}
.hsw-sched-head {
    font-size: 0.85rem;
    font-weight: 700;
    color: #2563eb;
    display: flex;
    align-items: center;
    gap: 7px;
    padding-bottom: 10px;
    margin-bottom: 10px;
    border-bottom: 1px solid #e2e8f0;
}
.hsw-sched-sub {
    font-size: 0.83rem;
    font-weight: 700;
    color: #2563eb;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 10px;
}
.hsw-sched-grid {
    display: grid;
    grid-template-columns: 1fr 1.15fr 1fr;
    gap: 10px;
}
.hsw-field-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 5px;
}
.hsw-footer {
    padding: 14px 6px 2px;
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
    .hsw-outcome-grid { grid-template-columns: 1fr; }
    .hsw-sched-grid { grid-template-columns: 1fr; }
    .hsw-minutes-toolbar { flex-wrap: wrap; }
}
</style>

<div id="hearingSessionWizardModal" class="hsw-overlay" role="dialog" aria-modal="true" aria-labelledby="hswTopbarTitle" onclick="if (event.target === this) closeHearingSessionWizard();">
    <div class="hsw-dialog">
        <!-- Top Header -->
        <div class="hsw-topbar">
            <div class="hsw-topbar-left">
                <span class="hsw-kp-badge">Katarungang Pambarangay</span>
                <h2 id="hswTopbarTitle" class="hsw-topbar-title">Case #— · Mediation Hearing Session Recording</h2>
            </div>
            <span id="hswStepCounter" class="hsw-top-step-counter">Step 1 of 3</span>
        </div>

        <!-- Body: Sidebar + Active Step Card -->
        <div class="hsw-body">
            <aside class="hsw-sidebar">
                <div class="hsw-progress-head">
                    <span class="hsw-progress-title">Filing Progress</span>
                    <span id="hswSidebarCounter" class="hsw-progress-counter">1 / 3</span>
                </div>
                <div class="hsw-progress-track">
                    <div id="hswProgressFill" class="hsw-progress-fill"></div>
                </div>

                <div class="hsw-step-list">
                    <button type="button" id="hswNavStep1" class="hsw-step-btn is-active" onclick="hswGoStep(1)">
                        <span class="hsw-step-num" id="hswNavNum1">1</span>
                        <span>
                            <span class="hsw-step-label">Attendance</span>
                            <span class="hsw-step-sub">Parties &amp; presider</span>
                        </span>
                    </button>
                    <button type="button" id="hswNavStep2" class="hsw-step-btn" onclick="hswGoStep(2)">
                        <span class="hsw-step-num" id="hswNavNum2">2</span>
                        <span>
                            <span class="hsw-step-label">Minutes</span>
                            <span class="hsw-step-sub">Session dialogue</span>
                        </span>
                    </button>
                    <button type="button" id="hswNavStep3" class="hsw-step-btn" onclick="hswGoStep(3)">
                        <span class="hsw-step-num" id="hswNavNum3">3</span>
                        <span>
                            <span class="hsw-step-label">Outcome &amp; KP Form</span>
                            <span class="hsw-step-sub">Resolution &amp; next step</span>
                        </span>
                    </button>
                </div>
            </aside>

            <div class="hsw-main">
                <div class="hsw-card">
                    <div class="hsw-card-scroll" id="hswScrollArea">
                        <div id="hswGlobalBanner" class="hsw-alert hsw-alert-err" style="display: none;"></div>

                        <!-- STEP 1: PARTY ATTENDANCE & PRESIDER -->
                        <div id="hswStep1Panel">
                            <h3 class="hsw-step-heading">Party Attendance &amp; Presider</h3>
                            <div id="hswServiceGateBanner" class="hsw-alert hsw-alert-warn" style="display: none;"></div>
                            <div id="hswPartiesContainer"></div>

                            <div class="hsw-party-card" style="margin-bottom: 0;">
                                <label for="hswPresiderDisplay" class="hsw-field-label" style="font-size: 0.84rem; margin-bottom: 8px;">
                                    Tagapamagitan / Presider ng Pagdinig
                                </label>
                                <input type="text" id="hswPresiderDisplay" class="hsw-input" readonly style="font-weight: 500; color: #334155;">
                                <input type="hidden" id="hswActualStartTime" value="">
                            </div>
                        </div>

                        <!-- STEP 2: MINUTES OF THE MEETING -->
                        <div id="hswStep2Panel" style="display: none;">
                            <div class="hsw-step-head-row">
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                    <h3 class="hsw-step-heading">Minutes of the Meeting</h3>
                                    <span id="hswStep2LockBadge" class="hsw-lock-pill" style="display: none;">🔒 Step 2 Locked (Finalized)</span>
                                </div>
                                <label class="hsw-nosession-pill">
                                    <input type="checkbox" id="hswNoSessionHeld" onchange="hswToggleNoSessionHeld()" style="accent-color: #b45309;">
                                    <span>No Session Held</span>
                                </label>
                            </div>

                            <div class="hsw-section-card">
                                <div class="hsw-minutes-toolbar">
                                    <select id="hswQuickCiteSelect" class="hsw-select" style="flex: 1;">
                                        <option value="">— Quick Cite: Pumili ng Salaysay / Tala —</option>
                                    </select>
                                    <button type="button" id="hswBtnQuickCite" class="hsw-btn-blue" onclick="hswInsertQuickCite()">
                                        + Insert
                                    </button>
                                    <button type="button" id="hswBtnVoice" class="hsw-btn-outline hsw-btn-voice" onclick="hswToggleVoiceRecording()">
                                        <span id="hswVoiceLabel">🎤 Speech-to-Text</span>
                                    </button>
                                </div>

                                <div id="hswAiStatusBanner" class="hsw-alert hsw-alert-info" style="display: none;"></div>

                                <label for="hswMinutesText" class="hsw-field-label" style="font-size: 0.84rem;">
                                    Katitikan ng Pag-uusap (Minutes) <span style="color: #dc2626;">*</span>
                                </label>
                                <textarea id="hswMinutesText" class="hsw-textarea" rows="6" maxlength="10000"
                                    placeholder="Itala rito ang buod ng pagdinig..."></textarea>
                            </div>

                            <div class="hsw-attach-bar">
                                <div class="hsw-attach-left">
                                    <span style="font-size: 1.05rem; color: #64748b;" aria-hidden="true">📎</span>
                                    <div>
                                        <div style="font-size: 0.84rem; font-weight: 700; color: #0f172a;">Attach Scanned / Handwritten Minutes (Optional)</div>
                                        <div style="font-size: 0.74rem; color: #64748b;">JPG, PNG, PDF, WEBP (Max 10MB)</div>
                                    </div>
                                </div>
                                <button type="button" id="hswBtnBrowse" class="hsw-btn-outline" onclick="document.getElementById('hswMinutesFileInput').click()">
                                    Browse
                                </button>
                                <input type="file" id="hswMinutesFileInput" accept=".jpg,.jpeg,.png,.pdf,.webp" multiple style="display: none;" onchange="hswUploadFiles(this)">
                            </div>
                            <div id="hswUploadedDocsList" style="margin-top: 8px;"></div>
                        </div>

                        <!-- STEP 3: OUTCOME & NEXT ACTION -->
                        <div id="hswStep3Panel" style="display: none;">
                            <h3 class="hsw-step-heading">Outcome &amp; Next Action</h3>

                            <div id="hswOutcomeCardsContainer" class="hsw-outcome-grid"></div>

                            <!-- Optional secondary outcomes if existing record uses Arbitration / Dismissed / Rescheduled -->
                            <div id="hswMoreOutcomesWrap" style="margin-bottom: 12px; display: none;">
                                <div id="hswMoreOutcomeCards" class="hsw-outcome-grid" style="margin-bottom: 0;"></div>
                            </div>

                            <!-- Conditional Next Hearing Schedule Box -->
                            <div id="hswNextScheduleBox" class="hsw-sched-card" style="display: none;">
                                <div class="hsw-sched-head">
                                    <span>📅</span>
                                    <span id="hswNextScheduleHeadLabel">Next: Set Next Hearing Schedule</span>
                                </div>
                                <div class="hsw-sched-sub">
                                    <span>📅</span>
                                    <span>Iskedyul ng Susunod na Pagdinig</span>
                                </div>
                                <div class="hsw-sched-grid">
                                    <div>
                                        <label for="hswNextDate" class="hsw-field-label">Petsa <span style="color:#dc2626;">*</span></label>
                                        <input type="date" id="hswNextDate" class="hsw-input">
                                    </div>
                                    <div>
                                        <label for="hswNextTime" class="hsw-field-label">Oras <span style="color:#dc2626;">*</span></label>
                                        <select id="hswNextTime" class="hsw-select">
                                            <option value="08:00">08:00 AM (Umaga)</option>
                                            <option value="09:00">09:00 AM (Umaga)</option>
                                            <option value="10:00">10:00 AM (Umaga)</option>
                                            <option value="11:00">11:00 AM (Umaga)</option>
                                            <option value="13:00">01:00 PM (Panghapon)</option>
                                            <option value="14:00" selected>02:00 PM (Panghapon)</option>
                                            <option value="15:00">03:00 PM (Panghapon)</option>
                                            <option value="16:00">04:00 PM (Panghapon)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="hswNextVenue" class="hsw-field-label">Bulwagan (Venue)</label>
                                        <input type="text" id="hswNextVenue" class="hsw-input" value="Bulwagan ng Lupon">
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; justify-content: flex-end; margin-top: 4px;">
                                <button type="button" id="hswBtnToggleMoreOutcomes" onclick="hswToggleMoreOutcomes()" style="background: none; border: none; color: #64748b; font-size: 0.76rem; font-weight: 600; cursor: pointer; padding: 2px 4px;">
                                    + Other outcomes (Arbitration / Dismissed)
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Sticky Card Footer -->
                    <div class="hsw-footer">
                        <div>
                            <button type="button" id="hswBtnBack" class="hsw-btn-outline" onclick="hswPrevStep()" style="display: none;">
                                &larr; Bumalik
                            </button>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="hsw-btn-outline" onclick="closeHearingSessionWizard()">
                                Kanselahin
                            </button>
                            <button type="button" id="hswBtnNext" class="hsw-btn-blue" onclick="hswNextStep()">
                                Susunod &rarr;
                            </button>
                            <button type="button" id="hswBtnFinalize" class="hsw-btn-blue" onclick="hswSaveMinutes('Finalized')" style="display: none;">
                                &check; I-save ang Katitikan
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
