/**
 * 3-Step Hearing Session Recording Wizard (Option A)
 * Supports Mediation and Pangkat Conciliation with sectioned Minutes of the Meeting.
 */
(function () {
    'use strict';

    const state = {
        hearingId: 0,
        caseId: 0,
        complaintId: 0,
        mode: 'Mediation',
        sessionIndex: 1,
        currentStep: 1,
        readOnly: false,
        stageData: null,
        session: null,
        attendanceData: null,
        luponMembers: [],
        activeSectionId: 'hswSecCompStmt',
        activeSectionLabel: 'Pahayag ng May Sumbong',
        aiDraft: null,
        mediaRecorder: null,
        audioChunks: [],
        isRecording: false,
        recordingTimer: null,
        recordingSecs: 0,
        isSaving: false,
    };

    const CLOSED_STATUSES = ['Settled', 'Resolved', 'Completed', 'Dismissed', 'DISMISSED_BARRED', 'Archived', 'CFA Issued', 'For CFA', 'Repudiated', 'Arbitration Award', 'Closed', 'Endorsed to Court', 'Certified'];

    function esc(val) {
        return String(val ?? '').replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[ch] || ch));
    }

    function apiUrl(endpoint) {
        if (typeof window.getStageApiUrl === 'function') {
            return window.getStageApiUrl(endpoint);
        }
        return `../../../backend/api/${String(endpoint).replace(/^\/+/, '')}`;
    }

    function csrfToken() {
        return window.agapCsrfToken
            || document.querySelector('meta[name="agap-csrf-token"]')?.getAttribute('content')
            || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            || '';
    }

    function notify(msg, type = 'info', title = '') {
        if (typeof window.agapNotify === 'function') {
            window.agapNotify(msg, type, title);
        } else if (typeof window.notifyUser === 'function') {
            window.notifyUser(msg, type, title);
        } else {
            alert(msg);
        }
    }

    function setBanner(msgHtml, tone = 'err') {
        const el = document.getElementById('hswGlobalBanner');
        if (!el) return;
        if (!msgHtml) {
            el.style.display = 'none';
            el.innerHTML = '';
            return;
        }
        el.className = `hsw-alert hsw-alert-${tone}`;
        el.innerHTML = msgHtml;
        el.style.display = 'block';
        document.getElementById('hswScrollArea')?.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function fmtTimeInput(val) {
        if (!val) return '';
        const s = String(val);
        const part = s.includes(' ') ? s.split(' ')[1] : (s.includes('T') ? s.split('T')[1] : s);
        const m = part.match(/(\d{1,2}):(\d{2})/);
        if (!m) return '';
        return `${String(parseInt(m[1], 10)).padStart(2, '0')}:${m[2]}`;
    }

    function fmtReadableDate(dt) {
        if (!dt) return 'Pending schedule';
        const d = new Date(String(dt).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(dt);
        return d.toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
        });
    }

    function ordinalLabel(mode, idx) {
        const n = Number(idx || 1);
        const ord = n === 1 ? '1st' : (n === 2 ? '2nd' : (n === 3 ? '3rd' : `${n}th`));
        return `${ord} ${mode}`;
    }

    async function refreshParentView(targetHearingId) {
        try {
            if (typeof window.loadStageData === 'function') {
                await window.loadStageData();
                if (targetHearingId && typeof window.selectInlineMinutesSession === 'function') {
                    window.selectInlineMinutesSession(Number(targetHearingId));
                }
            } else if (typeof window.loadComplaintDetails === 'function') {
                await window.loadComplaintDetails();
                if (typeof window.loadHearingSessions === 'function') {
                    await window.loadHearingSessions(state.mode);
                }
            }
        } catch (err) {
            console.warn('Wizard parent refresh notice:', err);
        }
    }

    async function openHearingSessionWizard(hearingId, stageType = null, caseId = null) {
        const hId = Number(hearingId || 0);
        if (!hId) {
            notify('Please select or schedule a hearing session first.', 'warning', 'Session Required');
            return;
        }

        const resolvedCaseId = Number(
            caseId
            || window.stageWorkspaceState?.caseId
            || window.complaintData?.case_id
            || window.complaintData?.complaint?.case_id
            || new URLSearchParams(window.location.search).get('case_id')
            || 0
        );
        if (!resolvedCaseId) {
            notify('Case ID could not be resolved for this session.', 'error', 'Missing Case');
            return;
        }

        const modal = document.getElementById('hearingSessionWizardModal');
        if (!modal) {
            notify('Session recording wizard modal is not available on this page.', 'error');
            return;
        }

        state.hearingId = hId;
        state.caseId = resolvedCaseId;
        state.currentStep = 1;
        state.aiDraft = null;
        setBanner('');

        modal.classList.add('is-open');
        document.getElementById('hswTopbarTitle').textContent = 'Loading session details...';
        document.getElementById('hswPartiesContainer').innerHTML = '<div style="padding:20px; text-align:center; color:#64748b;">Loading parties &amp; session record...</div>';

        try {
            const [detailsRes, attRes] = await Promise.all([
                fetch(`${apiUrl('stages/details.php')}?case_id=${encodeURIComponent(resolvedCaseId)}&_t=${Date.now()}`, { cache: 'no-store' }),
                fetch(`${apiUrl('hearings/attendance.php')}?hearing_id=${encodeURIComponent(hId)}&_t=${Date.now()}`, { cache: 'no-store' })
            ]);
            const detailsJson = await detailsRes.json();
            const attJson = await attRes.json();

            if (!detailsJson.success || !detailsJson.data) {
                throw new Error(detailsJson.message || 'Unable to load stage session details.');
            }
            if (!attJson.success || !attJson.data) {
                throw new Error(attJson.message || 'Unable to load hearing attendance.');
            }

            state.stageData = detailsJson.data;
            state.attendanceData = attJson.data;
            state.luponMembers = attJson.lupon_members || attJson.data.lupon_members || [];

            const medSessions = state.stageData.mediation_sessions || [];
            const conSessions = state.stageData.conciliation_sessions || [];
            let found = medSessions.find((s) => Number(s.hearing_id) === hId);
            let detectedMode = 'Mediation';
            if (!found) {
                found = conSessions.find((s) => Number(s.hearing_id) === hId);
                if (found) detectedMode = 'Conciliation';
            }
            if (!found && stageType) {
                detectedMode = stageType;
            }
            if (!found) {
                found = attJson.hearing || attJson.data || { hearing_id: hId };
            }

            state.mode = (stageType === 'Conciliation' || detectedMode === 'Conciliation' || String(found.hearing_type || '') === 'Conciliation')
                ? 'Conciliation'
                : 'Mediation';
            state.session = found;
            state.sessionIndex = Number(found.mediation_attempt || found.session_index || 1);
            state.complaintId = Number(state.stageData.case?.complaint_id || 0);

            const caseStatus = String(state.stageData.case?.case_status || '');
            const isCaseClosed = CLOSED_STATUSES.includes(caseStatus);
            const isFinalized = String(found.minutes_status || '') === 'Finalized';
            state.readOnly = Boolean(window.stageWorkspaceState?.readOnly || isCaseClosed || isFinalized);

            populateWizardHeaderAndMeta();
            renderStep1Parties();
            renderStep2Narrative();
            renderStep3Outcome();
            await hswLoadDocuments();

            const defaultStep = (found.attendance_recorded_at && !state.readOnly) ? 2 : 1;
            hswGoStep(defaultStep, { skipSave: true });
        } catch (err) {
            console.error('openHearingSessionWizard error:', err);
            setBanner(esc(err.message || 'Unable to load hearing session wizard.'), 'err');
        }
    }
    window.openHearingSessionWizard = openHearingSessionWizard;

    function closeHearingSessionWizard() {
        if (state.isRecording && state.mediaRecorder && state.mediaRecorder.state === 'recording') {
            try { state.mediaRecorder.stop(); } catch (_) {}
        }
        const modal = document.getElementById('hearingSessionWizardModal');
        if (modal) modal.classList.remove('is-open');
    }
    window.closeHearingSessionWizard = closeHearingSessionWizard;

    function populateWizardHeaderAndMeta() {
        const c = state.stageData?.case || {};
        const s = state.session || {};
        const caseNum = c.case_number || c.complaint_number || `#${state.caseId}`;
        const ord = ordinalLabel(state.mode, state.sessionIndex);

        document.getElementById('hswTopbarTitle').textContent =
            `Case #${caseNum} · ${ord} Hearing Session Recording`;

        document.getElementById('hswSidebarMeta').innerHTML = `
            <div style="font-weight:700; color:#0f172a; margin-bottom:4px;">${esc(ord)}</div>
            <div><strong>Schedule:</strong> ${esc(fmtReadableDate(s.hearing_date))}</div>
            <div><strong>Venue:</strong> ${esc(s.venue || 'Lupon Court Room')}</div>
            <div><strong>Status:</strong> ${esc(s.minutes_status || 'Not Recorded')}</div>
            ${state.readOnly ? '<div style="margin-top:6px; color:#b45309; font-weight:700;">🔒 Read-only record</div>' : ''}
        `;
    }

    function renderStep1Parties() {
        const att = state.attendanceData || {};
        const gate = att.service_gate || state.session?.attendance_gate || { allowed: true };
        const gateBanner = document.getElementById('hswServiceGateBanner');
        if (gateBanner) {
            if (gate.allowed === false) {
                const proofLink = `../gps/proof-service.php?case_id=${encodeURIComponent(state.caseId)}&hearing_id=${encodeURIComponent(state.hearingId)}&open_return=1`;
                gateBanner.innerHTML = `
                    <strong>Summons Service Verification Required:</strong> ${esc(gate.message || 'Record summons delivery before checking in parties.')}
                    <a href="${proofLink}" style="margin-left:8px; font-weight:700; color:#1d4ed8; text-decoration:underline;">Open Proof of Service &rarr;</a>
                `;
                gateBanner.style.display = 'block';
            } else {
                gateBanner.style.display = 'none';
            }
        }

        const parties = att.parties || state.session?.parties || [];
        const container = document.getElementById('hswPartiesContainer');
        if (!parties.length) {
            container.innerHTML = '<div class="hsw-alert hsw-alert-warn">No complaint parties found for this case.</div>';
        } else {
            container.innerHTML = parties.map((p) => {
                const rid = Number(p.resident_id);
                const isComp = String(p.party_type || '').toLowerCase() === 'complainant';
                const curStatus = ['Present', 'Absent', 'Late', 'Excused'].includes(p.attendance_status)
                    ? p.attendance_status
                    : 'Present';
                const noteVal = p.justification_reason || p.remarks || p.party_remarks || '';
                const disabledAttr = state.readOnly ? 'disabled' : '';

                const opts = [
                    { val: 'Present', label: 'Dumalo (Present)', cls: 'opt-present' },
                    { val: 'Absent', label: 'Hindi Dumalo (Absent)', cls: 'opt-absent' },
                    { val: 'Late', label: 'Huli (Late)', cls: 'opt-late' },
                    { val: 'Excused', label: 'Kinatawan (Excused)', cls: 'opt-excused' }
                ];

                return `
                    <div class="hsw-party-card" data-resident-id="${rid}" data-party-type="${esc(p.party_type)}">
                        <div class="hsw-party-head">
                            <div>
                                <span class="hsw-party-role ${isComp ? 'is-comp' : 'is-resp'}">
                                    ${isComp ? 'MAY SUMBONG (COMPLAINANT)' : 'IPINAGSUSUMBONG (RESPONDENT)'}
                                </span>
                                <div class="hsw-party-name">${esc(p.full_name || 'Party')}</div>
                            </div>
                            <span class="hsw-party-pill ${isComp ? 'is-comp' : 'is-resp'}">
                                ${isComp ? 'Partidong Naghain' : 'Partidong Inirereklamo'}
                            </span>
                        </div>
                        <div class="hsw-att-grid">
                            ${opts.map((o) => `
                                <label class="hsw-att-opt ${o.cls}">
                                    <input type="radio" name="hsw_att_${rid}" value="${o.val}" ${curStatus === o.val ? 'checked' : ''} ${disabledAttr} onchange="hswRefreshRecommendation()">
                                    <span>${o.label}</span>
                                </label>
                            `).join('')}
                        </div>
                        <input type="text" class="hsw-input hsw-party-note" data-resident-id="${rid}" value="${esc(noteVal)}" ${disabledAttr}
                            placeholder="Karagdagang tala sa pagdalo (opsyonal)...">
                    </div>
                `;
            }).join('');
        }

        const presiderName = state.session?.substitute_presider_name
            || state.session?.presiding_officer_name
            || att.presiding_officer_name
            || (state.mode === 'Conciliation' ? 'Pangkat ng Tagapagkasundo' : 'Punong Barangay / Lupon Chairman');
        document.getElementById('hswPresiderDisplay').value = presiderName;
        document.getElementById('hswActualStartTime').value =
            fmtTimeInput(state.session?.actual_start_time) || fmtTimeInput(state.session?.hearing_date) || '';
        document.getElementById('hswActualStartTime').disabled = state.readOnly;

        const subSelect = document.getElementById('hswSubstitutePresiderId');
        if (subSelect) {
            subSelect.innerHTML = '<option value="">-- Select Substitute Lupon Officer --</option>'
                + (state.luponMembers || []).map((m) => `<option value="${Number(m.user_id)}">${esc(m.full_name || m.username || 'Lupon Member')}</option>`).join('');
            if (state.session?.substitute_presider_id) {
                subSelect.value = String(state.session.substitute_presider_id);
            }
            subSelect.disabled = state.readOnly;
        }
        const subToggle = document.getElementById('hswPresiderAbsentToggle');
        const hasSub = Boolean(state.session?.substitute_presider_id);
        subToggle.checked = hasSub;
        subToggle.disabled = state.readOnly;
        document.getElementById('hswSubstituteReason').value = state.session?.substitute_reason || '';
        document.getElementById('hswSubstituteReason').disabled = state.readOnly;
        document.getElementById('hswSubstituteConsent').checked = Boolean(state.session?.parties_consent_to_substitute);
        document.getElementById('hswSubstituteConsent').disabled = state.readOnly;
        hswToggleSubstitutePresider();
    }

    function hswToggleSubstitutePresider() {
        const toggle = document.getElementById('hswPresiderAbsentToggle');
        const wrap = document.getElementById('hswSubstituteWrap');
        if (wrap) wrap.style.display = toggle?.checked ? 'block' : 'none';
    }
    window.hswToggleSubstitutePresider = hswToggleSubstitutePresider;

    function collectStep1PartyAttendance() {
        const cards = document.querySelectorAll('#hswPartiesContainer .hsw-party-card');
        const list = [];
        cards.forEach((card) => {
            const rid = Number(card.getAttribute('data-resident-id'));
            const pType = card.getAttribute('data-party-type') || '';
            const checked = card.querySelector(`input[name="hsw_att_${rid}"]:checked`);
            const noteEl = card.querySelector(`.hsw-party-note[data-resident-id="${rid}"]`);
            list.push({
                resident_id: rid,
                party_type: pType,
                attendance_status: checked ? checked.value : 'Present',
                justification_reason: noteEl ? noteEl.value.trim() : '',
            });
        });
        return list;
    }

    function summarizeAttendance(partyList) {
        const summarize = (type) => {
            const statuses = partyList.filter((p) => String(p.party_type).toLowerCase() === type).map((p) => p.attendance_status);
            if (!statuses.length) return 'Present';
            if (statuses.includes('Present')) return 'Present';
            if (statuses.includes('Late')) return 'Late';
            if (statuses.includes('Excused')) return 'Excused';
            return 'Absent';
        };
        return { comp: summarize('complainant'), resp: summarize('respondent') };
    }

    async function saveStep1Attendance() {
        if (state.readOnly) return true;
        const gate = state.attendanceData?.service_gate || state.session?.attendance_gate || { allowed: true };
        if (gate.allowed === false) {
            return true; // Allow moving to narrative/notes preview without blocking if unserved; banner already warns
        }

        const partyList = collectStep1PartyAttendance();
        const summary = summarizeAttendance(partyList);
        const subToggle = document.getElementById('hswPresiderAbsentToggle');
        const payload = {
            hearing_id: state.hearingId,
            parties: partyList,
            complainant_attendance: summary.comp,
            respondent_attendance: summary.resp,
            actual_start_time: document.getElementById('hswActualStartTime')?.value || '',
            presider_attendance: subToggle?.checked ? 'Absent' : 'Present',
        };
        if (subToggle?.checked) {
            payload.substitute_presider_id = document.getElementById('hswSubstitutePresiderId')?.value || '';
            payload.substitute_reason = document.getElementById('hswSubstituteReason')?.value.trim() || '';
            payload.parties_consent_to_substitute = document.getElementById('hswSubstituteConsent')?.checked ? 1 : 0;
        }

        const res = await fetch(apiUrl('hearings/attendance.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            setBanner(esc(json.message || 'Could not save attendance.'), 'err');
            return false;
        }
        if (state.session) {
            state.session.attendance_recorded_at = new Date().toISOString();
            state.session.complainant_attendance = summary.comp;
            state.session.respondent_attendance = summary.resp;
        }
        return true;
    }

    function buildQuickCiteOptions() {
        const select = document.getElementById('hswQuickCiteSelect');
        if (!select) return;

        const c = state.stageData?.case || {};
        const options = [];
        if (c.narrative) {
            options.push({ label: 'Orihinal na Salaysay (Complaint Intake)', text: `[Mula sa Orihinal na Salaysay]: "${String(c.narrative).trim()}"` });
        }
        if (c.requested_resolution) {
            options.push({ label: 'Hinihinging Solusyon (Requested Relief)', text: `[Hinihinging Solusyon]: "${String(c.requested_resolution).trim()}"` });
        }

        (state.stageData?.paanyaya_minutes || []).forEach((pm) => {
            if (pm.discussion_summary) {
                options.push({ label: `${pm.stage_type} · Buod ng Usapan`, text: `[Mula sa ${pm.stage_type}]: "${String(pm.discussion_summary).trim()}"` });
            }
            if (pm.agreed_terms) {
                options.push({ label: `${pm.stage_type} · Napagkasunduan`, text: `[${pm.stage_type} Kondisyon]: "${String(pm.agreed_terms).trim()}"` });
            }
        });

        const allPrior = [
            ...(state.stageData?.mediation_sessions || []),
            ...(state.stageData?.conciliation_sessions || [])
        ].filter((sess) => Number(sess.hearing_id) !== state.hearingId && sess.minutes_id);

        allPrior.forEach((ps) => {
            const lbl = ps.session_number || `Session #${ps.session_index || ''}`;
            if (ps.complainant_statement) {
                options.push({ label: `${lbl} · Pahayag ng May Sumbong`, text: `[${lbl} - Pahayag ng May Sumbong]: "${String(ps.complainant_statement).trim()}"` });
            }
            if (ps.respondent_statement) {
                options.push({ label: `${lbl} · Tugon ng Ipinagsusumbong`, text: `[${lbl} - Tugon ng Ipinagsusumbong]: "${String(ps.respondent_statement).trim()}"` });
            }
            if (ps.settlement_discussion_notes) {
                options.push({ label: `${lbl} · Buod ng Talakayan`, text: `[${lbl} - Buod]: "${String(ps.settlement_discussion_notes).trim()}"` });
            }
            if (ps.unresolved_issues) {
                options.push({ label: `${lbl} · Natitirang Usapin`, text: `[${lbl} - Natitirang Usapin]: "${String(ps.unresolved_issues).trim()}"` });
            }
        });

        select.innerHTML = '<option value="">— Pumili ng Sisipiing Salaysay / Tala —</option>'
            + options.map((o, idx) => `<option value="${idx}">${esc(o.label)}</option>`).join('');
        select._citeItems = options;
    }

    function hswSetActiveSection(fieldId, label) {
        state.activeSectionId = fieldId;
        state.activeSectionLabel = label;
        document.querySelectorAll('#hswStep2Panel .hsw-min-sec').forEach((wrap) => wrap.classList.remove('is-focused-target'));
        const field = document.getElementById(fieldId);
        field?.closest('.hsw-min-sec')?.classList.add('is-focused-target');
        const lbl = document.getElementById('hswQuickCiteTargetLabel');
        if (lbl) lbl.textContent = `(Target: ${label})`;
    }
    window.hswSetActiveSection = hswSetActiveSection;

    function appendToSection(fieldId, text) {
        const el = document.getElementById(fieldId);
        if (!el || !text) return;
        const cur = el.value.trim();
        el.value = cur ? `${cur}\n\n${text.trim()}` : text.trim();
        el.focus();
    }

    function hswInsertQuickCite() {
        const select = document.getElementById('hswQuickCiteSelect');
        const idx = select?.value;
        if (idx === '' || idx === null || !select?._citeItems?.[idx]) {
            notify('Pumili muna ng sisipiing salaysay o tala mula sa dropdown.', 'warning', 'Quick Cite');
            return;
        }
        appendToSection(state.activeSectionId || 'hswSecCompStmt', select._citeItems[idx].text);
    }
    window.hswInsertQuickCite = hswInsertQuickCite;

    function renderStep2Narrative() {
        const s = state.session || {};
        const c = state.stageData?.case || {};

        buildQuickCiteOptions();

        const setField = (id, val) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = val || '';
            el.disabled = state.readOnly;
        };

        setField('hswSecMainDispute', s.main_dispute_identified || c.complaint_title || '');
        setField('hswSecCompStmt', s.complainant_statement || '');
        setField('hswSecRespStmt', s.respondent_statement || '');
        setField('hswSecDiscussion', s.settlement_discussion_notes || '');
        setField('hswSecCaucusNotes', s.caucus_notes || '');
        setField('hswSecNewProposal', s.new_proposal || '');
        setField('hswSecCounteroffer', s.counteroffer || '');
        setField('hswSecAgreements', s.agreements_action_items || '');
        setField('hswSecUnresolved', s.unresolved_issues || '');

        const caucusCheck = document.getElementById('hswSecCaucusConducted');
        const hasCaucus = Boolean(Number(s.caucus_conducted || 0) || s.caucus_notes || s.new_proposal || s.counteroffer);
        caucusCheck.checked = hasCaucus;
        caucusCheck.disabled = state.readOnly;
        document.getElementById('hswCaucusProposalsWrap').style.display = hasCaucus ? 'grid' : 'none';

        document.getElementById('hswBtnVoice').disabled = state.readOnly;
        document.getElementById('hswBtnOcr').disabled = state.readOnly;
        document.getElementById('hswBtnAiDraft').disabled = state.readOnly;
        document.getElementById('hswAiStatusBanner').style.display = 'none';
        document.getElementById('hswAiDraftBox').style.display = 'none';

        hswSetActiveSection('hswSecCompStmt', 'Pahayag ng May Sumbong');
    }

    async function hswToggleVoiceRecording() {
        if (state.readOnly) return;
        const consent = document.getElementById('hswAiConsent');
        if (!consent?.checked) {
            notify('Please check Gemini AI Consent before recording audio.', 'warning', 'Consent Required');
            return;
        }
        const btn = document.getElementById('hswBtnVoice');
        const lbl = document.getElementById('hswVoiceLabel');
        const statusEl = document.getElementById('hswAiStatusBanner');

        if (state.isRecording && state.mediaRecorder) {
            state.mediaRecorder.stop();
            state.isRecording = false;
            clearInterval(state.recordingTimer);
            btn?.classList.remove('is-recording');
            if (lbl) lbl.textContent = '⏳ Transcribing...';
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            notify('Microphone recording is not supported in this browser.', 'error');
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            state.audioChunks = [];
            state.recordingSecs = 0;
            state.mediaRecorder = new MediaRecorder(stream);
            state.mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) state.audioChunks.push(e.data);
            };
            state.mediaRecorder.onstop = async () => {
                stream.getTracks().forEach((t) => t.stop());
                clearInterval(state.recordingTimer);
                const blob = new Blob(state.audioChunks, { type: 'audio/webm' });
                if (statusEl) {
                    statusEl.className = 'hsw-alert hsw-alert-info';
                    statusEl.innerHTML = `<strong>Transcribing audio into ${esc(state.activeSectionLabel)}...</strong>`;
                    statusEl.style.display = 'block';
                }
                try {
                    const fd = new FormData();
                    fd.append('audio', blob, 'session_recording.webm');
                    fd.append('external_ai_consent', '1');
                    const res = await fetch(apiUrl('ai/transcribe-audio.php'), {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': csrfToken() },
                        body: fd,
                    });
                    const json = await res.json();
                    if (json.success && json.text) {
                        appendToSection(state.activeSectionId || 'hswSecDiscussion', `[Voice STT]: ${json.text.trim()}`);
                        if (statusEl) {
                            statusEl.innerHTML = `<strong>Transcribed and added to ${esc(state.activeSectionLabel)}.</strong>`;
                        }
                    } else {
                        if (statusEl) {
                            statusEl.className = 'hsw-alert hsw-alert-err';
                            statusEl.textContent = json.message || 'Unable to transcribe audio.';
                        }
                    }
                } catch (_) {
                    if (statusEl) {
                        statusEl.className = 'hsw-alert hsw-alert-err';
                        statusEl.textContent = 'Audio transcription failed.';
                    }
                } finally {
                    if (lbl) lbl.textContent = '🎙️ Magsalita (Speech-to-Text)';
                }
            };

            state.mediaRecorder.start();
            state.isRecording = true;
            btn?.classList.add('is-recording');
            if (lbl) lbl.textContent = '⏹ Stop (00:00)';
            state.recordingTimer = setInterval(() => {
                state.recordingSecs++;
                const mm = String(Math.floor(state.recordingSecs / 60)).padStart(2, '0');
                const ss = String(state.recordingSecs % 60).padStart(2, '0');
                if (lbl) lbl.textContent = `⏹ Stop (${mm}:${ss})`;
            }, 1000);
        } catch (_) {
            notify('Microphone access is unavailable or blocked.', 'error');
        }
    }
    window.hswToggleVoiceRecording = hswToggleVoiceRecording;

    async function hswHandleOcrScan(input) {
        const file = input?.files?.[0];
        if (!file) return;
        const consent = document.getElementById('hswAiConsent');
        const statusEl = document.getElementById('hswAiStatusBanner');
        if (!consent?.checked) {
            notify('Please check Gemini AI Consent before running OCR.', 'warning', 'Consent Required');
            input.value = '';
            return;
        }
        if (statusEl) {
            statusEl.className = 'hsw-alert hsw-alert-info';
            statusEl.innerHTML = `<strong>Reading handwritten notes via OCR into ${esc(state.activeSectionLabel)}...</strong>`;
            statusEl.style.display = 'block';
        }
        try {
            const fd = new FormData();
            fd.append('image', file);
            fd.append('external_ai_consent', '1');
            const res = await fetch(apiUrl('ai/ocr-notes.php'), {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken() },
                body: fd,
            });
            const json = await res.json();
            if (json.success && json.text) {
                appendToSection(state.activeSectionId || 'hswSecDiscussion', `[OCR Notes]: ${json.text.trim()}`);
                statusEl.innerHTML = `<strong>OCR text inserted into ${esc(state.activeSectionLabel)}.</strong>`;
            } else {
                statusEl.className = 'hsw-alert hsw-alert-err';
                statusEl.textContent = json.message || 'OCR extraction failed.';
            }
        } catch (_) {
            if (statusEl) {
                statusEl.className = 'hsw-alert hsw-alert-err';
                statusEl.textContent = 'OCR request failed.';
            }
        } finally {
            input.value = '';
        }
    }
    window.hswHandleOcrScan = hswHandleOcrScan;

    async function hswGenerateAiDraft() {
        if (state.readOnly) return;
        const consent = document.getElementById('hswAiConsent');
        if (!consent?.checked) {
            notify('Please check Gemini AI Consent first.', 'warning', 'Consent Required');
            return;
        }
        const combined = [
            document.getElementById('hswSecCompStmt')?.value.trim(),
            document.getElementById('hswSecRespStmt')?.value.trim(),
            document.getElementById('hswSecDiscussion')?.value.trim(),
            document.getElementById('hswSecAgreements')?.value.trim()
        ].filter(Boolean).join('\n\n');

        if (combined.length < 20) {
            notify('Maglagay muna ng kahit 20 characters ng tala o transcript bago gumamit ng AI Draft.', 'warning', 'AI Draft');
            return;
        }

        const statusEl = document.getElementById('hswAiStatusBanner');
        const box = document.getElementById('hswAiDraftBox');
        if (statusEl) {
            statusEl.className = 'hsw-alert hsw-alert-info';
            statusEl.innerHTML = '<strong>Gemini AI is structuring the minutes into sections...</strong>';
            statusEl.style.display = 'block';
        }

        try {
            const res = await fetch(apiUrl('ai/draft-hearing-minutes.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({
                    hearing_id: state.hearingId,
                    source_text: combined,
                    external_ai_consent: true,
                }),
            });
            const json = await res.json();
            if (!res.ok || !json.success || !json.draft) {
                throw new Error(json.message || 'Unable to generate AI draft.');
            }
            state.aiDraft = json.draft;
            const d = json.draft;
            if (box) {
                box.style.display = 'block';
                box.innerHTML = `
                    <div style="padding:12px 14px; border:1px solid #c4b5fd; border-radius:10px; background:#faf5ff; color:#3b0764; font-size:0.8rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:8px;">
                            <strong>✨ AI Sectioned Draft — Review Required</strong>
                            <div style="display:flex; gap:6px;">
                                <button type="button" class="btn-secondary" onclick="hswApplyAiDraft()" style="font-size:0.75rem; padding:4px 10px; border-color:#8b5cf6; color:#6d28d9; background:#fff;">Apply to All Sections</button>
                                <button type="button" class="btn-secondary" onclick="document.getElementById('hswAiDraftBox').style.display='none'" style="font-size:0.75rem; padding:4px 10px;">Dismiss</button>
                            </div>
                        </div>
                        <div><strong>Main Issue:</strong> ${esc(d.main_dispute_identified || '—')}</div>
                        <div style="margin-top:3px;"><strong>Complainant's Statement:</strong> ${esc(d.complainant_statement || '—')}</div>
                        <div style="margin-top:3px;"><strong>Respondent's Statement:</strong> ${esc(d.respondent_statement || '—')}</div>
                        <div style="margin-top:3px;"><strong>Discussion Summary:</strong> ${esc(d.discussion_summary || '—')}</div>
                        <div style="margin-top:5px; font-size:0.74rem; color:#6b21a8;">${esc(d.review_note || '')}</div>
                    </div>
                `;
            }
            if (statusEl) statusEl.style.display = 'none';
        } catch (err) {
            if (statusEl) {
                statusEl.className = 'hsw-alert hsw-alert-err';
                statusEl.textContent = err.message || 'AI drafting failed.';
            }
        }
    }
    window.hswGenerateAiDraft = hswGenerateAiDraft;

    function hswApplyAiDraft() {
        const d = state.aiDraft;
        if (!d) return;
        const setIf = (id, val) => {
            const el = document.getElementById(id);
            if (el && val) el.value = val;
        };
        setIf('hswSecMainDispute', d.main_dispute_identified);
        setIf('hswSecCompStmt', d.complainant_statement);
        setIf('hswSecRespStmt', d.respondent_statement);
        setIf('hswSecDiscussion', d.discussion_summary);
        setIf('hswSecNewProposal', d.new_proposal);
        setIf('hswSecCounteroffer', d.counteroffer);
        setIf('hswSecAgreements', d.agreements_action_items);
        setIf('hswSecUnresolved', d.unresolved_issues);
        setIf('hswOutcomeRemarks', d.outcome_remarks);
        if (d.new_proposal || d.counteroffer) {
            const cBox = document.getElementById('hswSecCaucusConducted');
            if (cBox) {
                cBox.checked = true;
                document.getElementById('hswCaucusProposalsWrap').style.display = 'grid';
            }
        }
        document.getElementById('hswAiDraftBox').style.display = 'none';
        notify('AI draft applied to sectioned minutes. Review carefully before finalizing.', 'success', 'Draft Applied');
    }
    window.hswApplyAiDraft = hswApplyAiDraft;

    async function hswLoadDocuments() {
        const listEl = document.getElementById('hswUploadedDocsList');
        if (!listEl || !state.hearingId) return;
        try {
            const res = await fetch(`${apiUrl('hearings/upload-minutes-evidence.php')}?hearing_id=${encodeURIComponent(state.hearingId)}&_t=${Date.now()}`, { cache: 'no-store' });
            const json = await res.json();
            const docs = json.documents || json.data || [];
            if (!docs.length) {
                listEl.innerHTML = '<span style="font-size:0.76rem; color:#94a3b8; font-style:italic;">Walang naka-upload na katitikan o ebidensya sa ngayon.</span>';
                return;
            }
            listEl.innerHTML = docs.map((d) => `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 10px; border:1px solid #e2e8f0; border-radius:7px; background:#f8fafc; margin-bottom:5px; font-size:0.78rem;">
                    <a href="${apiUrl('hearings/document-preview.php')}?id=${Number(d.id)}" target="_blank" style="color:#2563eb; font-weight:600; text-decoration:none;">
                        📄 ${esc(d.file_name)} <span style="color:#64748b; font-weight:400;">(${esc(d.document_type)})</span>
                    </a>
                    ${!state.readOnly ? `<button type="button" onclick="hswDeleteDocument(${Number(d.id)})" style="background:none; border:none; color:#dc2626; font-weight:700; cursor:pointer; font-size:0.74rem;">Remove</button>` : ''}
                </div>
            `).join('');
        } catch (_) {
            listEl.innerHTML = '';
        }
    }

    async function hswUploadFiles(input) {
        const files = input?.files;
        if (!files || !files.length || state.readOnly) return;
        const fd = new FormData();
        fd.append('hearing_id', String(state.hearingId));
        fd.append('case_id', String(state.caseId));
        fd.append('document_type', 'handwritten_minutes');
        Array.from(files).forEach((f) => fd.append('file[]', f));
        try {
            const res = await fetch(apiUrl('hearings/upload-minutes-evidence.php'), {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken() },
                body: fd,
            });
            const json = await res.json();
            if (!res.ok || !json.success) {
                notify(json.message || 'Unable to upload file.', 'error');
            } else {
                notify('Document uploaded.', 'success');
                await hswLoadDocuments();
            }
        } catch (_) {
            notify('Upload failed.', 'error');
        } finally {
            input.value = '';
        }
    }
    window.hswUploadFiles = hswUploadFiles;

    async function hswDeleteDocument(docId) {
        if (state.readOnly) return;
        try {
            const res = await fetch(`${apiUrl('hearings/upload-minutes-evidence.php')}?id=${Number(docId)}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-Token': csrfToken() },
            });
            const json = await res.json();
            if (json.success) await hswLoadDocuments();
            else notify(json.message || 'Cannot delete file.', 'error');
        } catch (_) {
            notify('Failed to remove attachment.', 'error');
        }
    }
    window.hswDeleteDocument = hswDeleteDocument;

    function buildOutcomeOptionsForStep3() {
        const idx = state.sessionIndex;
        const mode = state.mode;
        const opts = [
            {
                val: 'Settled',
                tone: 'tone-green',
                title: 'Nagkasundo ang mga Partido (Amicable Settlement - KP Form 16)',
                desc: 'Nakamit ang mapayapang kasunduan. Magpapatuloy sa pag-draft at paglagda ng KP Form 16.'
            }
        ];

        if (mode === 'Mediation') {
            if (idx === 1) {
                opts.push({
                    val: 'Continue Mediation',
                    tone: 'tone-blue',
                    badge: '2nd Session',
                    title: 'Magtakda ng Susunod na Pagdinig (Reset / Next Hearing Session)',
                    desc: 'Kailangan pa ng dagdag na paghaharap sa loob ng 15-araw na takdang panahon ng pamamagitan.'
                });
            } else {
                opts.push({
                    val: 'Elevate to Pangkat',
                    tone: 'tone-red',
                    title: 'I-refer sa Pangkat Tagapagkasundo (Stage 2 Conciliation)',
                    desc: 'Nabigo ang pamamagitan ng Punong Barangay. I-akyat sa 3-kasaping Pangkat (KP Form 10).'
                });
            }
        } else {
            if (idx <= 2) {
                opts.push({
                    val: 'Continue Conciliation',
                    tone: 'tone-blue',
                    badge: idx === 1 ? '2nd Conciliation' : '3rd Conciliation',
                    title: `Magtakda ng Susunod na Conciliation (${idx === 1 ? '2nd' : '3rd'} Session)`,
                    desc: 'Magtakda ng kasunod na pagdinig ng Pangkat ng Tagapagkasundo.'
                });
            }
            if (idx >= 2) {
                opts.push({
                    val: 'Pending CFA',
                    tone: 'tone-amber',
                    title: 'Walang Pagkakasundo / Ready for CFA (KP Form 20)',
                    desc: 'Nabigo ang Pangkat Conciliation; maghanda ng Katibayan Upang Makadulog sa Hukuman (CFA).'
                });
            }
        }

        opts.push({
            val: 'Arbitration',
            tone: 'tone-amber',
            title: 'Isumite sa Boluntaryong Arbitrasyon (KP Form 14)',
            desc: 'Sumang-ayon ang magkabilang panig sa nakasulat na kasunduan ng arbitrasyon (RA 7160 Sec. 413).'
        });
        opts.push({
            val: 'Party Absent',
            tone: 'tone-slate',
            title: 'Hindi Sumipot ang Partido (Party Absent / Show-Cause)',
            desc: 'Itala ang hindi pagdalo ng partido para sa kaukulang abiso o show-cause evaluation.'
        });
        opts.push({
            val: 'Rescheduled',
            tone: 'tone-slate',
            title: 'I-reset / I-reschedule ang Sesyon',
            desc: 'Ilipat sa bagong petsa at oras ang kasalukuyang pagdinig.'
        });
        opts.push({
            val: 'Dismissed',
            tone: 'tone-red',
            title: 'I-dismiss ang Reklamo (Complainant Absent / KP Rules)',
            desc: 'Hindi dumalo ang nagsusumbong nang walang balidong dahilan.'
        });

        return opts;
    }

    function hswRefreshRecommendation() {
        const att = summarizeAttendance(collectStep1PartyAttendance());
        const banner = document.getElementById('hswRecommendationBanner');
        if (!banner) return;

        let recTitle = '';
        let recDesc = '';
        let recVal = '';

        if (att.comp === 'Absent') {
            recTitle = 'Awtomatikong Mungkahi: Itala ang Hindi Pagdalo ng Nagsusumbong';
            recDesc = state.mode === 'Conciliation'
                ? 'Hindi sumipot ang may sumbong. Maaaring i-record bilang Party Absent (KP Form 18 Show Cause) o Dismissed.'
                : 'Hindi sumipot ang may sumbong sa pagdinig. Maaaring i-dismiss o i-reschedule alinsunod sa KP Rules.';
            recVal = state.mode === 'Conciliation' ? 'Party Absent' : 'Dismissed';
        } else if (att.resp === 'Absent') {
            if (state.mode === 'Mediation' && state.sessionIndex === 1) {
                recTitle = 'Awtomatikong Mungkahi: Magtakda ng Ikalawang Pamamagitan (2nd Mediation)';
                recDesc = 'Hindi sumipot ang ipinagsusumbong sa 1st Mediation. Inirerekomendang mag-iskedyul ng 2nd Mediation sa ibaba.';
                recVal = 'Continue Mediation';
            } else if (state.mode === 'Mediation' && state.sessionIndex >= 2) {
                recTitle = 'Awtomatikong Mungkahi: I-refer sa Pangkat Tagapagkasundo';
                recDesc = 'Hindi sumipot o walang kasunduan sa 2nd Mediation. Handa nang i-akyat sa Pangkat Conciliation.';
                recVal = 'Elevate to Pangkat';
            } else if (state.mode === 'Conciliation' && state.sessionIndex === 1) {
                recTitle = 'Awtomatikong Mungkahi: Magtakda ng Ikalawang Conciliation';
                recDesc = 'Hindi sumipot ang ipinagsusumbong sa 1st Conciliation. Maaaring magtakda ng 2nd Conciliation.';
                recVal = 'Continue Conciliation';
            } else {
                recTitle = 'Awtomatikong Mungkahi: Show Cause (KP Form 19) o CFA Evaluation';
                recDesc = 'Hindi sumipot ang ipinagsusumbong sa Pangkat Conciliation.';
                recVal = 'Party Absent';
            }
        }

        if (recTitle) {
            banner.innerHTML = `<strong>ℹ️ ${esc(recTitle)}</strong><div style="margin-top:2px;">${esc(recDesc)}</div>`;
            banner.style.display = 'block';
        } else {
            banner.style.display = 'none';
        }

        const curChecked = document.querySelector('input[name="hsw_outcome"]:checked');
        if ((!curChecked || curChecked.value === 'Pending') && recVal && !state.readOnly) {
            const target = document.querySelector(`input[name="hsw_outcome"][value="${recVal}"]`);
            if (target) {
                target.checked = true;
                hswOnOutcomeChange();
            }
        }
    }
    window.hswRefreshRecommendation = hswRefreshRecommendation;

    function renderStep3Outcome() {
        const s = state.session || {};
        const curOutcome = (s.session_outcome && s.session_outcome !== 'Pending') ? s.session_outcome : '';
        const options = buildOutcomeOptionsForStep3();
        const container = document.getElementById('hswOutcomeCardsContainer');
        const disabledAttr = state.readOnly ? 'disabled' : '';

        const fallbackVal = curOutcome || options[1]?.val || options[0].val;
        container.innerHTML = options.map((o) => `
            <label class="hsw-outcome-card ${o.tone}">
                <input type="radio" name="hsw_outcome" value="${esc(o.val)}" ${(fallbackVal === o.val) ? 'checked' : ''} ${disabledAttr}
                    onchange="hswOnOutcomeChange()" style="margin-top:3px;">
                <div style="flex:1;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
                        <strong style="font-size:0.9rem;">${esc(o.title)}</strong>
                        ${o.badge ? `<span style="font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:9999px; background:#ffffff; border:1px solid currentColor;">${esc(o.badge)}</span>` : ''}
                    </div>
                    <div style="font-size:0.78rem; margin-top:2px; opacity:0.92;">${esc(o.desc)}</div>
                </div>
            </label>
        `).join('');

        document.getElementById('hswActualEndTime').value = fmtTimeInput(s.actual_end_time) || '';
        document.getElementById('hswActualEndTime').disabled = state.readOnly;
        document.getElementById('hswOutcomeRemarks').value = s.outcome_remarks || '';
        document.getElementById('hswOutcomeRemarks').disabled = state.readOnly;

        // Default next hearing date (next weekday / Tuesday for Mediation)
        const nextDateInput = document.getElementById('hswNextDate');
        const nextTimeInput = document.getElementById('hswNextTime');
        const nextVenueInput = document.getElementById('hswNextVenue');
        const cfg = window.AGAP_SETTINGS || {};
        const probe = new Date();
        const allowedDays = state.mode === 'Mediation' ? (cfg.mediation_day_numbers || [2]) : [1, 3, 4, 5];
        for (let i = 1; i <= 30; i++) {
            const d = new Date(probe.getFullYear(), probe.getMonth(), probe.getDate() + i);
            if (allowedDays.includes(d.getDay())) {
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                nextDateInput.min = `${y}-${m}-${day}`;
                if (!nextDateInput.value) nextDateInput.value = `${y}-${m}-${day}`;
                break;
            }
        }
        nextTimeInput.value = state.mode === 'Mediation' ? (cfg.mediation_hours_start || '14:00').slice(0, 5) : '09:00';
        nextVenueInput.value = s.venue || cfg.mediation_venue || 'Lupon Court Room';
        nextDateInput.disabled = state.readOnly;
        nextTimeInput.disabled = state.readOnly;
        nextVenueInput.disabled = state.readOnly;

        hswRefreshRecommendation();
        hswOnOutcomeChange();
    }

    function hswOnOutcomeChange() {
        const val = document.querySelector('input[name="hsw_outcome"]:checked')?.value || '';
        const box = document.getElementById('hswNextScheduleBox');
        const pill = document.getElementById('hswNextSchedulePill');
        const hint = document.getElementById('hswNextScheduleHint');
        const dateHelp = document.getElementById('hswNextDateHelp');
        if (!box) return;

        const sessions = (state.mode === 'Conciliation' ? state.stageData?.conciliation_sessions : state.stageData?.mediation_sessions) || [];
        const hasNext = sessions.some((s) => Number(s.session_index) === state.sessionIndex + 1 && !['Cancelled', 'Office Cancelled'].includes(s.status));

        const showForNext = (val === 'Continue Mediation' || val === 'Continue Conciliation') && !hasNext;
        const showForResched = (val === 'Rescheduled');
        box.style.display = (showForNext || showForResched) ? 'block' : 'none';

        if (showForResched) {
            if (pill) pill.textContent = 'Replacement Schedule';
            if (hint) hint.textContent = 'Ililipat ang pagdinig sa bagong petsa at oras. Awtomatikong maglalabas ng KP Form 8 / KP Form 9.';
        } else if (showForNext) {
            const ord = state.sessionIndex === 1 ? '2nd' : '3rd';
            if (pill) pill.textContent = `${ord} ${state.mode}`;
            if (hint) hint.textContent = 'Itakda ang petsa, oras, at bulwagan para sa susunod na sesyon. Ayon sa Sec. 410 ng RA 7160, kung naroroon ang mga partido, sila ay personal na inaabisuhan sa loob ng sesyon (summons KP Form 9 is suppressed).';
        }
        if (dateHelp) {
            dateHelp.textContent = state.mode === 'Mediation'
                ? `Pumili ng araw ng Pamamagitan (${window.AGAP_SETTINGS?.mediation_days_label || 'Tuesdays'}).`
                : 'Pumili ng regular na araw ng trabaho (Lunes, Miyerkules, Huwebes, o Biyernes).';
        }
    }
    window.hswOnOutcomeChange = hswOnOutcomeChange;

    function hswSetEndTimeNow() {
        if (state.readOnly) return;
        const now = new Date();
        document.getElementById('hswActualEndTime').value =
            `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
    }
    window.hswSetEndTimeNow = hswSetEndTimeNow;

    async function hswGoStep(step, opts = {}) {
        const target = Math.max(1, Math.min(3, Number(step || 1)));
        setBanner('');

        if (!opts.skipSave && state.currentStep === 1 && target > 1 && !state.readOnly) {
            const nextBtn = document.getElementById('hswBtnNext');
            if (nextBtn) { nextBtn.disabled = true; nextBtn.textContent = 'Saving attendance...'; }
            const ok = await saveStep1Attendance();
            if (nextBtn) { nextBtn.disabled = false; nextBtn.textContent = 'Susunod →'; }
            if (!ok) return;
        }

        state.currentStep = target;
        [1, 2, 3].forEach((n) => {
            const panel = document.getElementById(`hswStep${n}Panel`);
            const navBtn = document.getElementById(`hswNavStep${n}`);
            const numEl = document.getElementById(`hswNavNum${n}`);
            if (panel) panel.style.display = (n === target) ? 'block' : 'none';
            if (navBtn) {
                navBtn.classList.toggle('is-active', n === target);
                const isDone = (n < target) || (n === 1 && Boolean(state.session?.attendance_recorded_at));
                navBtn.classList.toggle('is-done', n !== target && isDone);
                if (numEl) numEl.textContent = (n !== target && isDone) ? '✓' : String(n);
            }
        });

        document.getElementById('hswStepCounter').textContent = `Step ${target} of 3`;
        document.getElementById('hswProgressFill').style.width = `${(target / 3) * 100}%`;

        document.getElementById('hswBtnBack').style.display = target > 1 ? 'inline-flex' : 'none';
        document.getElementById('hswBtnNext').style.display = target < 3 ? 'inline-flex' : 'none';
        document.getElementById('hswBtnSaveDraft').style.display = (target >= 2 && !state.readOnly) ? 'inline-flex' : 'none';
        document.getElementById('hswBtnFinalize').style.display = (target === 3 && !state.readOnly) ? 'inline-flex' : 'none';

        if (target === 3) hswRefreshRecommendation();
        document.getElementById('hswScrollArea')?.scrollTo({ top: 0, behavior: 'smooth' });
    }
    window.hswGoStep = hswGoStep;

    window.hswNextStep = function () {
        if (state.currentStep < 3) hswGoStep(state.currentStep + 1);
    };
    window.hswPrevStep = function () {
        if (state.currentStep > 1) hswGoStep(state.currentStep - 1, { skipSave: true });
    };

    async function hswSaveMinutes(status = 'Draft') {
        if (state.readOnly || state.isSaving) return;
        setBanner('');

        const mainDispute = document.getElementById('hswSecMainDispute')?.value.trim() || '';
        let compStmt = document.getElementById('hswSecCompStmt')?.value.trim() || '';
        let respStmt = document.getElementById('hswSecRespStmt')?.value.trim() || '';
        let discussion = document.getElementById('hswSecDiscussion')?.value.trim() || '';
        const caucusConducted = document.getElementById('hswSecCaucusConducted')?.checked ? 1 : 0;
        const caucusNotes = document.getElementById('hswSecCaucusNotes')?.value.trim() || '';
        const newProposal = document.getElementById('hswSecNewProposal')?.value.trim() || '';
        const counteroffer = document.getElementById('hswSecCounteroffer')?.value.trim() || '';
        const agreements = document.getElementById('hswSecAgreements')?.value.trim() || '';
        const unresolved = document.getElementById('hswSecUnresolved')?.value.trim() || '';
        const outcomeVal = document.querySelector('input[name="hsw_outcome"]:checked')?.value || 'Pending';
        const endTimeVal = document.getElementById('hswActualEndTime')?.value || '';
        const outcomeRemarks = document.getElementById('hswOutcomeRemarks')?.value.trim() || '';

        const att = summarizeAttendance(collectStep1PartyAttendance());
        if (status === 'Finalized' && !compStmt && !respStmt && !discussion) {
            if (att.comp === 'Absent' || att.resp === 'Absent') {
                discussion = 'Walang naganap na pag-uusap dahil hindi sumipot ang partido sa takdang oras ng pagdinig.';
                document.getElementById('hswSecDiscussion').value = discussion;
            } else {
                setBanner('Paki-lagay ang Pahayag ng May Sumbong, Tugon ng Ipinagsusumbong, o Buod ng Talakayan bago i-finalize.', 'err');
                await hswGoStep(2, { skipSave: true });
                document.getElementById('hswSecCompStmt')?.focus();
                return;
            }
        }

        // Synthesize fallback discussion_summary if user wrote statements only so legacy views never look blank
        if (!discussion && (compStmt || respStmt)) {
            const parts = [];
            if (compStmt) parts.push(`Pahayag ng May Sumbong:\n${compStmt}`);
            if (respStmt) parts.push(`Tugon ng Ipinagsusumbong:\n${respStmt}`);
            discussion = parts.join('\n\n');
        }

        const sessList = (state.mode === 'Conciliation' ? state.stageData?.conciliation_sessions : state.stageData?.mediation_sessions) || [];
        const hasNext = sessList.some((s) => Number(s.session_index) === state.sessionIndex + 1 && !['Cancelled', 'Office Cancelled'].includes(s.status));
        const needsNextSched = ((outcomeVal === 'Continue Mediation' || outcomeVal === 'Continue Conciliation') && !hasNext) || outcomeVal === 'Rescheduled';

        const nextDate = document.getElementById('hswNextDate')?.value.trim() || '';
        const nextTime = document.getElementById('hswNextTime')?.value.trim() || '';
        const nextVenue = document.getElementById('hswNextVenue')?.value.trim() || 'Lupon Court Room';

        if (status === 'Finalized' && needsNextSched && (!nextDate || !nextTime)) {
            setBanner('Paki-pili ang Petsa at Oras ng Susunod na Sesyon bago mag-finalize.', 'err');
            await hswGoStep(3, { skipSave: true });
            document.getElementById('hswNextDate')?.focus();
            return;
        }

        const idx = state.sessionIndex;
        const sessionType = state.mode === 'Conciliation'
            ? (idx === 1 ? '1st Conciliation' : (idx === 2 ? '2nd Conciliation' : '3rd Conciliation'))
            : (idx === 1 ? '1st Mediation' : (idx === 2 ? '2nd Mediation' : '3rd Mediation'));

        const payload = {
            hearing_id: state.hearingId,
            session_type: sessionType,
            opening_conducted: 1,
            identity_verified: 1,
            complaint_reviewed: 1,
            main_dispute_identified: mainDispute,
            complainant_statement: compStmt,
            respondent_statement: respStmt,
            settlement_discussion_notes: discussion,
            session_minutes: discussion,
            caucus_conducted: caucusConducted,
            caucus_notes: caucusNotes,
            new_proposal: newProposal,
            counteroffer: counteroffer,
            agreements_action_items: agreements,
            unresolved_issues: unresolved,
            session_outcome: outcomeVal,
            actual_end_time: endTimeVal || null,
            outcome_remarks: outcomeRemarks,
            status: status === 'Finalized' ? 'Finalized' : 'Draft',
            force_edit: 1,
        };

        if (needsNextSched && nextDate && nextTime) {
            payload.next_hearing_date = nextDate;
            payload.next_hearing_time = nextTime;
            payload.next_hearing_venue = nextVenue;
            if (outcomeVal === 'Continue Mediation' || outcomeVal === 'Continue Conciliation') {
                payload.book_next_hearing = 1;
                payload.next_hearing_presider_id = state.session?.presiding_officer_id || null;
            }
        }

        const draftBtn = document.getElementById('hswBtnSaveDraft');
        const finBtn = document.getElementById('hswBtnFinalize');
        state.isSaving = true;
        if (draftBtn) draftBtn.disabled = true;
        if (finBtn) { finBtn.disabled = true; finBtn.textContent = status === 'Finalized' ? 'Finalizing...' : 'Saving...'; }

        try {
            const res = await fetch(apiUrl('hearings/minutes.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!res.ok || !json.success) {
                throw new Error(json.message || 'Unable to save session minutes.');
            }

            closeHearingSessionWizard();
            await refreshParentView(state.hearingId);

            if (status === 'Finalized') {
                if (outcomeVal === 'Settled' && typeof window.openInlineSettlementModal === 'function') {
                    notify('Minutes finalized! Complete KP Form 16 settlement attestation.', 'success', 'Record & Attest KP Form 16');
                    window.openInlineSettlementModal(state.hearingId);
                } else if (outcomeVal === 'Settled' && typeof window.openKpFormModal === 'function') {
                    notify('Minutes finalized! Opening KP Form 16.', 'success');
                    window.openKpFormModal(state.caseId, 'KP Form 16', state.hearingId, state.complaintId);
                } else if (outcomeVal === 'Elevate to Pangkat' && typeof window.openKpFormModal === 'function') {
                    notify('Minutes finalized! Opening KP Form 10.', 'success');
                    window.openKpFormModal(state.caseId, 'KP Form 10', state.hearingId, state.complaintId);
                } else if (outcomeVal === 'Pending CFA' && typeof window.openKpFormModal === 'function') {
                    notify('Minutes finalized! Opening KP Form 20 (CFA).', 'success');
                    window.openKpFormModal(state.caseId, 'KP Form 20', state.hearingId, state.complaintId);
                } else if (outcomeVal === 'Arbitration' && typeof window.openKpFormModal === 'function') {
                    notify('Minutes finalized! Opening KP Form 14.', 'success');
                    window.openKpFormModal(state.caseId, 'KP Form 14', state.hearingId, state.complaintId);
                } else if (json.next_hearing_booked && json.next_hearing) {
                    notify(`Minutes finalized and next session booked for ${json.next_hearing.hearing_date}.`, 'success', 'Session Recorded');
                } else {
                    notify('Hearing session minutes finalized successfully.', 'success', 'Session Finalized');
                }
            } else {
                notify('Draft minutes saved.', 'success', 'Draft Saved');
            }
        } catch (err) {
            setBanner(esc(err.message || 'Failed to save session minutes.'), 'err');
        } finally {
            state.isSaving = false;
            if (draftBtn) draftBtn.disabled = false;
            if (finBtn) { finBtn.disabled = false; finBtn.textContent = 'Save & Finalize'; }
        }
    }
    window.hswSaveMinutes = hswSaveMinutes;
})();
