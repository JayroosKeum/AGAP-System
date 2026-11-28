/**
 * 3-Step Hearing Session Recording Wizard
 * Supports Mediation and Pangkat Conciliation sessions cleanly.
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
        caseReadOnly: false,
        minutesLocked: false,
        isFinalized: false,
        stageData: null,
        session: null,
        attendanceData: null,
        mediaRecorder: null,
        audioChunks: [],
        isRecording: false,
        recordingTimer: null,
        recordingSecs: 0,
        sttConsentGranted: false,
        isSaving: false,
    };

    const NO_SESSION_DEFAULT_TEXT = 'Walang naganap na pag-uusap dahil hindi sumipot ang partido / hindi natuloy ang sesyon.';
    const CLOSED_STATUSES = [
        'Settled', 'Resolved', 'Completed', 'Dismissed', 'DISMISSED_BARRED',
        'Archived', 'CFA Issued', 'For CFA', 'Repudiated', 'Arbitration Award',
        'Closed', 'Endorsed to Court', 'Certified'
    ];

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
            state.caseReadOnly = Boolean(window.stageWorkspaceState?.readOnly || isCaseClosed);
            state.isFinalized = String(found.minutes_status || '') === 'Finalized';
            state.minutesLocked = Boolean(state.caseReadOnly || state.isFinalized);

            populateWizardHeader();
            renderStep1Parties();
            renderStep2Minutes();
            renderStep3Outcome();
            await hswLoadDocuments();

            const defaultStep = (found.attendance_recorded_at && !state.minutesLocked) ? 2 : 1;
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

    function populateWizardHeader() {
        const c = state.stageData?.case || {};
        const caseNum = c.case_number || c.complaint_number || `#${state.caseId}`;
        const stageLabel = state.mode === 'Conciliation' ? 'Conciliation' : 'Mediation';
        document.getElementById('hswTopbarTitle').textContent =
            `Case #${caseNum} · ${stageLabel} Hearing Session Recording`;
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
                const disabledAttr = state.caseReadOnly ? 'disabled' : '';

                const opts = [
                    { val: 'Present', label: 'Present', cls: 'opt-present' },
                    { val: 'Absent', label: 'Absent', cls: 'opt-absent' },
                    { val: 'Late', label: 'Late', cls: 'opt-late' },
                    { val: 'Excused', label: 'Excused', cls: 'opt-excused' }
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
                            <span class="hsw-party-tag ${isComp ? 'is-comp' : 'is-resp'}">
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
                            placeholder="Attendance note (optional)...">
                    </div>
                `;
            }).join('');
        }

        const presiderName = state.session?.substitute_presider_name
            || state.session?.presiding_officer_name
            || att.presiding_officer_name
            || (state.mode === 'Conciliation' ? 'Pangkat ng Tagapagkasundo (Conciliation Panel)' : 'Punong Barangay (Punong Tagapamayapa)');
        document.getElementById('hswPresiderDisplay').value = presiderName;
        document.getElementById('hswActualStartTime').value =
            fmtTimeInput(state.session?.actual_start_time) || fmtTimeInput(state.session?.hearing_date) || '';
    }

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
            if (statuses.includes('Absent')) return 'Absent';
            if (statuses.includes('Present')) return 'Present';
            if (statuses.includes('Late')) return 'Late';
            if (statuses.includes('Excused')) return 'Excused';
            return 'Present';
        };
        return { comp: summarize('complainant'), resp: summarize('respondent') };
    }

    async function saveStep1Attendance() {
        if (state.caseReadOnly) return true;
        const gate = state.attendanceData?.service_gate || state.session?.attendance_gate || { allowed: true };
        if (gate.allowed === false) {
            return true;
        }

        const partyList = collectStep1PartyAttendance();
        const summary = summarizeAttendance(partyList);
        const payload = {
            hearing_id: state.hearingId,
            parties: partyList,
            complainant_attendance: summary.comp,
            respondent_attendance: summary.resp,
            actual_start_time: document.getElementById('hswActualStartTime')?.value || '',
            presider_attendance: 'Present',
        };

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
            const txt = ps.settlement_discussion_notes || ps.complainant_statement || '';
            if (txt) {
                options.push({ label: `${lbl} · Katitikan`, text: `[${lbl}]: "${String(txt).trim()}"` });
            }
        });

        select.innerHTML = '<option value="">— Quick Cite: Pumili ng Salaysay / Tala —</option>'
            + options.map((o, idx) => `<option value="${idx}">${esc(o.label)}</option>`).join('');
        select._citeItems = options;
    }

    function appendToMinutes(text) {
        const el = document.getElementById('hswMinutesText');
        if (!el || !text || state.minutesLocked) return;
        const cur = el.value.trim();
        el.value = cur ? `${cur}\n\n${text.trim()}` : text.trim();
        el.focus();
    }

    function hswInsertQuickCite() {
        if (state.minutesLocked) return;
        const select = document.getElementById('hswQuickCiteSelect');
        const idx = select?.value;
        if (idx === '' || idx === null || !select?._citeItems?.[idx]) {
            notify('Pumili muna ng sisipiing salaysay o tala mula sa dropdown.', 'warning', 'Quick Cite');
            return;
        }
        appendToMinutes(select._citeItems[idx].text);
    }
    window.hswInsertQuickCite = hswInsertQuickCite;

    function hswToggleNoSessionHeld() {
        if (state.minutesLocked) return;
        const cb = document.getElementById('hswNoSessionHeld');
        const ta = document.getElementById('hswMinutesText');
        if (!cb || !ta) return;
        if (cb.checked) {
            if (!ta.value.trim()) {
                ta.value = NO_SESSION_DEFAULT_TEXT;
            }
        } else if (ta.value.trim() === NO_SESSION_DEFAULT_TEXT) {
            ta.value = '';
        }
    }
    window.hswToggleNoSessionHeld = hswToggleNoSessionHeld;

    function renderStep2Minutes() {
        const s = state.session || {};
        buildQuickCiteOptions();

        let textVal = String(s.settlement_discussion_notes || '').trim();
        if (!textVal) {
            const legacyParts = [s.complainant_statement, s.respondent_statement, s.agreements_action_items]
                .map((x) => String(x || '').trim())
                .filter(Boolean);
            textVal = legacyParts.join('\n\n');
        }

        const minutesEl = document.getElementById('hswMinutesText');
        const citeEl = document.getElementById('hswQuickCiteSelect');
        const citeBtn = document.getElementById('hswBtnQuickCite');
        const voiceBtn = document.getElementById('hswBtnVoice');
        const noSessEl = document.getElementById('hswNoSessionHeld');
        const browseBtn = document.getElementById('hswBtnBrowse');
        const fileEl = document.getElementById('hswMinutesFileInput');
        const lockBadge = document.getElementById('hswStep2LockBadge');

        if (minutesEl) {
            minutesEl.value = textVal;
            minutesEl.disabled = state.minutesLocked;
        }
        if (noSessEl) {
            noSessEl.checked = textVal === NO_SESSION_DEFAULT_TEXT;
            noSessEl.disabled = state.minutesLocked;
        }
        if (citeEl) citeEl.disabled = state.minutesLocked;
        if (citeBtn) citeBtn.disabled = state.minutesLocked;
        if (voiceBtn) voiceBtn.disabled = state.minutesLocked;
        if (browseBtn) browseBtn.disabled = state.minutesLocked;
        if (fileEl) fileEl.disabled = state.minutesLocked;
        if (lockBadge) lockBadge.style.display = state.isFinalized ? 'inline-flex' : 'none';

        const statusEl = document.getElementById('hswAiStatusBanner');
        if (statusEl) statusEl.style.display = 'none';
    }

    async function hswToggleVoiceRecording() {
        if (state.minutesLocked) return;
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

        if (!state.sttConsentGranted) {
            const ok = window.confirm('Ipadala ang audio recording sa Google Gemini AI para ma-transcribe sa Katitikan?');
            if (!ok) return;
            state.sttConsentGranted = true;
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
                    statusEl.innerHTML = '<strong>Transcribing speech into Katitikan...</strong>';
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
                        appendToMinutes(json.text.trim());
                        if (statusEl) statusEl.style.display = 'none';
                    } else if (statusEl) {
                        statusEl.className = 'hsw-alert hsw-alert-err';
                        statusEl.textContent = json.message || 'Unable to transcribe audio.';
                    }
                } catch (_) {
                    if (statusEl) {
                        statusEl.className = 'hsw-alert hsw-alert-err';
                        statusEl.textContent = 'Audio transcription failed.';
                    }
                } finally {
                    if (lbl) lbl.textContent = '🎤 Speech-to-Text';
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

    async function hswLoadDocuments() {
        const listEl = document.getElementById('hswUploadedDocsList');
        if (!listEl || !state.hearingId) return;
        try {
            const res = await fetch(`${apiUrl('hearings/upload-minutes-evidence.php')}?hearing_id=${encodeURIComponent(state.hearingId)}&_t=${Date.now()}`, { cache: 'no-store' });
            const json = await res.json();
            const docs = json.documents || json.data || [];
            if (!docs.length) {
                listEl.innerHTML = '';
                return;
            }
            listEl.innerHTML = docs.map((d) => `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 10px; border:1px solid #e2e8f0; border-radius:7px; background:#f8fafc; margin-bottom:5px; font-size:0.78rem;">
                    <a href="${apiUrl('hearings/document-preview.php')}?id=${Number(d.id)}" target="_blank" style="color:#2563eb; font-weight:600; text-decoration:none;">
                        📄 ${esc(d.file_name)} <span style="color:#64748b; font-weight:400;">(${esc(d.document_type)})</span>
                    </a>
                    ${!state.minutesLocked ? `<button type="button" onclick="hswDeleteDocument(${Number(d.id)})" style="background:none; border:none; color:#dc2626; font-weight:700; cursor:pointer; font-size:0.74rem;">Remove</button>` : ''}
                </div>
            `).join('');
        } catch (_) {
            listEl.innerHTML = '';
        }
    }

    async function hswUploadFiles(input) {
        const files = input?.files;
        if (!files || !files.length || state.minutesLocked) return;
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
        if (state.minutesLocked) return;
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

    function getSuggestedOutcomeVal() {
        const att = summarizeAttendance(collectStep1PartyAttendance());
        if (state.mode === 'Mediation') {
            if (state.sessionIndex >= 2) {
                return 'Elevate to Pangkat';
            }
            return 'Continue Mediation';
        }
        // Conciliation
        if (att.comp === 'Absent' || att.resp === 'Absent') {
            return 'Party Absent';
        }
        if (state.sessionIndex <= 2) {
            return 'Continue Conciliation';
        }
        return 'Pending CFA';
    }

    function buildOutcomeCardsData() {
        const sessList = (state.mode === 'Conciliation' ? state.stageData?.conciliation_sessions : state.stageData?.mediation_sessions) || [];
        const hasNext = sessList.some((s) => Number(s.session_index) === state.sessionIndex + 1 && !['Cancelled', 'Office Cancelled'].includes(s.status));

        if (state.mode === 'Conciliation') {
            const resetVal = (!hasNext && state.sessionIndex <= 2) ? 'Continue Conciliation' : 'Rescheduled';
            return {
                primary: [
                    { val: 'Settled', tone: 'tone-green', title: 'Amicable Settlement (KP Form 16)' },
                    { val: resetVal, tone: 'tone-blue', title: 'Reset / Next Hearing', isReset: true },
                    { val: 'Party Absent', tone: 'tone-red', title: 'Party Absent / Show-Cause' },
                    { val: 'Pending CFA', tone: 'tone-amber', title: 'Issue CFA (KP Form 20)' }
                ],
                secondary: [
                    { val: 'Arbitration', tone: 'tone-slate', title: 'Voluntary Arbitration (KP Form 14)' },
                    { val: 'Dismissed', tone: 'tone-red', title: 'Dismissed (Complainant Absent / KP Rules)' }
                ]
            };
        }

        const resetVal = (!hasNext && state.sessionIndex === 1) ? 'Continue Mediation' : 'Rescheduled';
        return {
            primary: [
                { val: 'Settled', tone: 'tone-green', title: 'Amicable Settlement (KP Form 16)' },
                { val: resetVal, tone: 'tone-blue', title: 'Reset / Next Hearing', isReset: true },
                { val: 'Elevate to Pangkat', tone: 'tone-red', title: 'Refer to Pangkat (Stage 2)' },
                { val: 'Pending CFA', tone: 'tone-amber', title: 'Issue CFA (KP Form 20)' }
            ],
            secondary: [
                { val: 'Arbitration', tone: 'tone-slate', title: 'Voluntary Arbitration (KP Form 14)' },
                { val: 'Party Absent', tone: 'tone-slate', title: 'Party Absent / Non-Appearance' },
                { val: 'Dismissed', tone: 'tone-red', title: 'Dismissed (Complainant Absent / KP Rules)' }
            ]
        };
    }

    function renderOutcomeCardHtml(o, selectedVal, suggestedVal, disabledAttr) {
        const isChecked = selectedVal === o.val;
        const isSuggested = (suggestedVal === o.val) || (o.isReset && (suggestedVal === 'Continue Mediation' || suggestedVal === 'Continue Conciliation' || suggestedVal === 'Rescheduled'));
        return `
            <label class="hsw-outcome-card ${o.tone}">
                <span class="hsw-outcome-left">
                    <input type="radio" name="hsw_outcome" value="${esc(o.val)}" ${isChecked ? 'checked' : ''} ${disabledAttr}
                        onchange="hswOnOutcomeChange()">
                    <span class="hsw-outcome-title">${esc(o.title)}</span>
                </span>
                ${isSuggested ? '<span class="hsw-suggested-badge">Suggested</span>' : ''}
            </label>
        `;
    }

    function hswRefreshRecommendation() {
        const container = document.getElementById('hswOutcomeCardsContainer');
        const moreContainer = document.getElementById('hswMoreOutcomeCards');
        if (!container) return;

        const suggestedVal = getSuggestedOutcomeVal();
        const currentlySelected = document.querySelector('input[name="hsw_outcome"]:checked')?.value
            || (state.session?.session_outcome && state.session.session_outcome !== 'Pending' ? state.session.session_outcome : '')
            || suggestedVal;

        const { primary, secondary } = buildOutcomeCardsData();
        const disabledAttr = state.caseReadOnly ? 'disabled' : '';

        container.innerHTML = primary.map((o) => renderOutcomeCardHtml(o, currentlySelected, suggestedVal, disabledAttr)).join('');
        if (moreContainer) {
            moreContainer.innerHTML = secondary.map((o) => renderOutcomeCardHtml(o, currentlySelected, '', disabledAttr)).join('');
            const isSecondarySelected = secondary.some((o) => o.val === currentlySelected);
            const wrap = document.getElementById('hswMoreOutcomesWrap');
            if (wrap && isSecondarySelected) {
                wrap.style.display = 'block';
            }
        }
        hswOnOutcomeChange();
    }
    window.hswRefreshRecommendation = hswRefreshRecommendation;

    function hswToggleMoreOutcomes() {
        const wrap = document.getElementById('hswMoreOutcomesWrap');
        const btn = document.getElementById('hswBtnToggleMoreOutcomes');
        if (!wrap) return;
        const nextOpen = wrap.style.display === 'none';
        wrap.style.display = nextOpen ? 'block' : 'none';
        if (btn) btn.textContent = nextOpen ? '− Hide other outcomes' : '+ Other outcomes (Arbitration / Dismissed)';
    }
    window.hswToggleMoreOutcomes = hswToggleMoreOutcomes;

    function renderStep3Outcome() {
        const s = state.session || {};
        const cfg = window.AGAP_SETTINGS || {};

        const nextDateInput = document.getElementById('hswNextDate');
        const nextTimeSelect = document.getElementById('hswNextTime');
        const nextVenueInput = document.getElementById('hswNextVenue');

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
        const defaultTime = state.mode === 'Mediation'
            ? (cfg.mediation_hours_start || '14:00').slice(0, 5)
            : '09:00';
        if (nextTimeSelect) {
            const hasOption = Array.from(nextTimeSelect.options).some((opt) => opt.value === defaultTime);
            nextTimeSelect.value = hasOption ? defaultTime : '14:00';
            nextTimeSelect.disabled = state.caseReadOnly;
        }
        nextVenueInput.value = s.venue || cfg.mediation_venue || 'Bulwagan ng Lupon';
        nextDateInput.disabled = state.caseReadOnly;
        nextVenueInput.disabled = state.caseReadOnly;

        hswRefreshRecommendation();
    }

    function hswOnOutcomeChange() {
        const val = document.querySelector('input[name="hsw_outcome"]:checked')?.value || '';
        const box = document.getElementById('hswNextScheduleBox');
        const headLbl = document.getElementById('hswNextScheduleHeadLabel');
        if (!box) return;

        const sessList = (state.mode === 'Conciliation' ? state.stageData?.conciliation_sessions : state.stageData?.mediation_sessions) || [];
        const hasNext = sessList.some((s) => Number(s.session_index) === state.sessionIndex + 1 && !['Cancelled', 'Office Cancelled'].includes(s.status));

        const showForNext = (val === 'Continue Mediation' || val === 'Continue Conciliation') && !hasNext;
        const showForResched = (val === 'Rescheduled');
        box.style.display = (showForNext || showForResched) ? 'block' : 'none';
        if (headLbl) {
            headLbl.textContent = showForResched ? 'Next: Set Replacement Hearing Schedule' : 'Next: Set Next Hearing Schedule';
        }
    }
    window.hswOnOutcomeChange = hswOnOutcomeChange;

    async function hswGoStep(step, opts = {}) {
        const target = Math.max(1, Math.min(3, Number(step || 1)));
        setBanner('');

        if (!opts.skipSave && state.currentStep === 1 && target > 1 && !state.caseReadOnly) {
            const nextBtn = document.getElementById('hswBtnNext');
            if (nextBtn) { nextBtn.disabled = true; nextBtn.textContent = 'Saving...'; }
            const ok = await saveStep1Attendance();
            if (nextBtn) { nextBtn.disabled = false; nextBtn.textContent = 'Susunod →'; }
            if (!ok) return;
        }

        if (!opts.skipSave && state.currentStep === 2 && target > 2 && !state.minutesLocked) {
            const minutesVal = document.getElementById('hswMinutesText')?.value.trim() || '';
            const noSess = Boolean(document.getElementById('hswNoSessionHeld')?.checked);
            if (!minutesVal && !noSess) {
                setBanner('Paki-lagay ang Katitikan ng Pag-uusap o i-check ang "No Session Held" bago magpatuloy.', 'err');
                document.getElementById('hswMinutesText')?.focus();
                return;
            }
        }

        state.currentStep = target;
        [1, 2, 3].forEach((n) => {
            const panel = document.getElementById(`hswStep${n}Panel`);
            const navBtn = document.getElementById(`hswNavStep${n}`);
            const numEl = document.getElementById(`hswNavNum${n}`);
            if (panel) panel.style.display = (n === target) ? 'block' : 'none';
            if (navBtn) {
                navBtn.classList.toggle('is-active', n === target);
                const isDone = (n < target) || (n === 1 && target > 1) || (n === 2 && state.isFinalized && target !== 2);
                navBtn.classList.toggle('is-done', n !== target && isDone);
                if (numEl) numEl.textContent = (n !== target && isDone) ? '✓' : String(n);
            }
        });

        document.getElementById('hswStepCounter').textContent = `Step ${target} of 3`;
        const sideCounter = document.getElementById('hswSidebarCounter');
        if (sideCounter) sideCounter.textContent = `${target} / 3`;
        document.getElementById('hswProgressFill').style.width = `${(target / 3) * 100}%`;

        document.getElementById('hswBtnBack').style.display = target > 1 ? 'inline-flex' : 'none';
        document.getElementById('hswBtnNext').style.display = target < 3 ? 'inline-flex' : 'none';
        document.getElementById('hswBtnFinalize').style.display = (target === 3 && !state.caseReadOnly) ? 'inline-flex' : 'none';

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

    async function hswSaveMinutes(status = 'Finalized') {
        if (state.caseReadOnly || state.isSaving) return;
        setBanner('');

        const outcomeVal = document.querySelector('input[name="hsw_outcome"]:checked')?.value || 'Pending';
        const sessList = (state.mode === 'Conciliation' ? state.stageData?.conciliation_sessions : state.stageData?.mediation_sessions) || [];
        const hasNext = sessList.some((s) => Number(s.session_index) === state.sessionIndex + 1 && !['Cancelled', 'Office Cancelled'].includes(s.status));
        const needsNextSched = ((outcomeVal === 'Continue Mediation' || outcomeVal === 'Continue Conciliation') && !hasNext) || outcomeVal === 'Rescheduled';

        const nextDate = document.getElementById('hswNextDate')?.value.trim() || '';
        const nextTime = document.getElementById('hswNextTime')?.value.trim() || '';
        const nextVenue = document.getElementById('hswNextVenue')?.value.trim() || 'Bulwagan ng Lupon';

        if (needsNextSched && (!nextDate || !nextTime)) {
            setBanner('Paki-pili ang Petsa at Oras ng Susunod na Pagdinig.', 'err');
            document.getElementById('hswNextDate')?.focus();
            return;
        }

        const idx = state.sessionIndex;
        const sessionType = state.mode === 'Conciliation'
            ? (idx === 1 ? '1st Conciliation' : (idx === 2 ? '2nd Conciliation' : '3rd Conciliation'))
            : (idx === 1 ? '1st Mediation' : (idx === 2 ? '2nd Mediation' : '3rd Mediation'));

        let payload;
        if (state.isFinalized) {
            // Step 2 is locked; update Step 3 outcome only
            payload = {
                action: 'update_outcome',
                hearing_id: state.hearingId,
                case_id: state.caseId,
                session_outcome: outcomeVal,
                outcome_remarks: state.session?.outcome_remarks || '',
            };
        } else {
            let discussion = document.getElementById('hswMinutesText')?.value.trim() || '';
            const noSess = Boolean(document.getElementById('hswNoSessionHeld')?.checked);
            if (!discussion && noSess) {
                discussion = NO_SESSION_DEFAULT_TEXT;
            }
            if (!discussion) {
                setBanner('Paki-lagay ang Katitikan ng Pag-uusap bago i-save.', 'err');
                await hswGoStep(2, { skipSave: true });
                document.getElementById('hswMinutesText')?.focus();
                return;
            }

            const c = state.stageData?.case || {};
            payload = {
                hearing_id: state.hearingId,
                case_id: state.caseId,
                session_type: sessionType,
                opening_conducted: 1,
                identity_verified: 1,
                complaint_reviewed: 1,
                main_dispute_identified: state.session?.main_dispute_identified || c.complaint_title || '',
                settlement_discussion_notes: discussion,
                session_minutes: discussion,
                session_outcome: outcomeVal,
                status: status === 'Finalized' ? 'Finalized' : 'Draft',
                force_edit: 1,
            };
        }

        if (needsNextSched && nextDate && nextTime) {
            payload.next_hearing_date = nextDate;
            payload.next_hearing_time = nextTime;
            payload.next_hearing_venue = nextVenue;
            if (outcomeVal === 'Continue Mediation' || outcomeVal === 'Continue Conciliation') {
                payload.book_next_hearing = 1;
                payload.next_hearing_presider_id = state.session?.presiding_officer_id || null;
            }
        }

        const finBtn = document.getElementById('hswBtnFinalize');
        state.isSaving = true;
        if (finBtn) {
            finBtn.disabled = true;
            finBtn.textContent = 'Saving...';
        }

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

            if (outcomeVal === 'Settled' && typeof window.openInlineSettlementModal === 'function') {
                notify('Nai-save ang katitikan! Kumpletuhin ang KP Form 16 settlement attestation.', 'success', 'Record & Attest KP Form 16');
                window.openInlineSettlementModal(state.hearingId);
            } else if (outcomeVal === 'Settled' && typeof window.openKpFormModal === 'function') {
                notify('Nai-save ang katitikan! Binubuksan ang KP Form 16.', 'success');
                window.openKpFormModal(state.caseId, 'KP Form 16', state.hearingId, state.complaintId);
            } else if (outcomeVal === 'Elevate to Pangkat' && typeof window.openKpFormModal === 'function') {
                notify('Nai-save ang katitikan! Binubuksan ang KP Form 10.', 'success');
                window.openKpFormModal(state.caseId, 'KP Form 10', state.hearingId, state.complaintId);
            } else if (outcomeVal === 'Pending CFA' && typeof window.openKpFormModal === 'function') {
                notify('Nai-save ang katitikan! Binubuksan ang KP Form 20 (CFA).', 'success');
                window.openKpFormModal(state.caseId, 'KP Form 20', state.hearingId, state.complaintId);
            } else if (outcomeVal === 'Arbitration' && typeof window.openKpFormModal === 'function') {
                notify('Nai-save ang katitikan! Binubuksan ang KP Form 14.', 'success');
                window.openKpFormModal(state.caseId, 'KP Form 14', state.hearingId, state.complaintId);
            } else if (json.next_hearing_booked && json.next_hearing) {
                notify(`Nai-save ang katitikan at naitakda ang susunod na pagdinig (${json.next_hearing.hearing_date}).`, 'success', 'Katitikan Saved');
            } else {
                notify('Nai-save nang matagumpay ang Katitikan ng Pagdinig.', 'success', 'Katitikan Saved');
            }
        } catch (err) {
            setBanner(esc(err.message || 'Failed to save session minutes.'), 'err');
        } finally {
            state.isSaving = false;
            if (finBtn) {
                finBtn.disabled = false;
                finBtn.innerHTML = '&check; I-save ang Katitikan';
            }
        }
    }
    window.hswSaveMinutes = hswSaveMinutes;
})();
