/**
 * AGAP - Stage Workspaces (Mediation & Pangkat Conciliation)
 * File: frontend/assets/js/stage-workspace.js
 */

let stageWorkspaceState = {
    mode: 'Mediation', // 'Mediation', 'Conciliation', or 'Arbitration'
    caseId: null,
    complaintId: null,
    stageData: null,
    sessions: [],
    luponMembers: [],
    activeSessionHearingId: null,
    currentHearingId: null,
    currentMinutesRecord: null
};

/**
 * Base API URL resolver
 * Always resolves to ../../../backend/api/ from frontend/pages/cases/
 */
function getStageApiUrl(endpoint) {
    const clean = endpoint.replace(/^\/+/, '');
    return `../../../backend/api/${clean}`;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatDateReadable(str) {
    if (!str) return '—';
    try {
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
        return str;
    }
}

function formatDateTimeReadable(str) {
    if (!str) return '—';
    try {
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) +
            ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch (e) {
        return str;
    }
}

function openKPFormPreview(caseId, formCode, hearingId = null) {
    if (!caseId) {
        notifyUser('Case ID is required to preview document.', 'warning');
        return;
    }
    let url = getStageApiUrl(`documents/kp-form.php?case_id=${encodeURIComponent(caseId)}&form_code=${encodeURIComponent(formCode)}&preview=1`);
    if (hearingId) {
        url += `&hearing_id=${encodeURIComponent(hearingId)}`;
    }
    window.open(url, '_blank');
}
window.openKPFormPreview = openKPFormPreview;

function notifyUser(message, type = 'info', title = null, duration = 4000) {
    if (window.agapNotify) {
        window.agapNotify(message, type, title, duration);
    } else {
        alert(message);
    }
}
window.notifyUser = notifyUser;

/**
 * Initialize Stage Workspace
 */
async function initStageWorkspace(options) {
    stageWorkspaceState.mode = options.mode || 'Mediation';
    stageWorkspaceState.caseId = Number(options.caseId);
    stageWorkspaceState.complaintId = Number(options.complaintId || 0);

    const urlParams = new URLSearchParams(window.location.search);
    const hParam = urlParams.get('hearing_id');
    if (hParam) {
        stageWorkspaceState.activeSessionHearingId = Number(hParam);
    }

    await loadStageData();
    await loadLuponMembers();
    checkUrlHashAction();

    // Hearings routes an elevation request here so there is only one
    // Mediation-to-Conciliation workflow and one set of safeguards.
    if (urlParams.get('open_referral') === '1' && stageWorkspaceState.mode === 'Mediation') {
        openReferPangkatModal();
    }
}

/**
 * Fetch stage overview and session lists
 */
async function loadStageData() {
    const caseId = stageWorkspaceState.caseId;
    if (!caseId) return;

    try {
        const res = await fetch(getStageApiUrl(`stages/details.php?case_id=${encodeURIComponent(caseId)}&_t=${Date.now()}`), { cache: 'no-store' });
        if (!res.ok) {
            throw new Error(`Server returned HTTP ${res.status}: ${res.statusText}`);
        }
        const json = await res.json();
        if (!json.success || !json.data) {
            throw new Error(json.message || 'Unable to load stage workspace details.');
        }

        stageWorkspaceState.stageData = json.data;
        if (!stageWorkspaceState.complaintId && json.data.case && json.data.case.complaint_id) {
            stageWorkspaceState.complaintId = Number(json.data.case.complaint_id);
        }

        const mode = stageWorkspaceState.mode;
        if (mode === 'Mediation') {
            stageWorkspaceState.sessions = json.data.mediation_sessions || [];
        } else if (mode === 'Conciliation') {
            stageWorkspaceState.sessions = json.data.conciliation_sessions || [];
        } else if (mode === 'Arbitration') {
            stageWorkspaceState.sessions = json.data.arbitration_sessions || [];
        } else {
            stageWorkspaceState.sessions = json.data.mediation_sessions || [];
        }

        // Set default active session if not selected
        if (!stageWorkspaceState.activeSessionHearingId && stageWorkspaceState.sessions.length > 0) {
            stageWorkspaceState.activeSessionHearingId = Number(stageWorkspaceState.sessions[0].hearing_id);
        }

        renderWorkspaceUI();
    } catch (err) {
        console.error('loadStageData error:', err);
        const overviewContainer = document.getElementById('wsStageOverviewGrid');
        if (overviewContainer) {
            overviewContainer.innerHTML = `
                <div class="overview-stat-card" style="border-color: #fca5a5; background: #fef2f2; grid-column: 1 / -1; padding: 18px;">
                    <span class="stat-label" style="color: #991b1b; font-weight: 700;">Error Loading Stage Data</span>
                    <strong class="stat-value" style="font-size: 0.95rem; color: #991b1b; margin-top: 4px;">${escapeHtml(err.message)}</strong>
                    <div style="margin-top: 10px;">
                        <button type="button" class="btn-secondary" onclick="loadStageData()" style="font-size: 0.8rem; padding: 5px 12px; cursor: pointer;">Retry Loading</button>
                    </div>
                </div>
            `;
        }
    }
}

/**
 * Fetch Lupon Members for Pangkat nominations
 */
async function loadLuponMembers() {
    try {
        const res = await fetch(getStageApiUrl('assignments/lupon-members.php'));
        const json = await res.json();
        stageWorkspaceState.luponMembers = Array.isArray(json) ? json : (json.data || []);
        populateLuponDropdowns();
    } catch (e) {
        console.warn('Could not load lupon members:', e);
    }
}

function populateLuponDropdowns() {
    const members = stageWorkspaceState.luponMembers || [];
    const selects = ['referChairmanId', 'referSecretaryId', 'referMemberId'];

    selects.forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const currentVal = sel.value;
        let opts = '<option value="">-- Select Lupon Member --</option>';
        members.forEach(m => {
            const name = `${m.last_name}, ${m.first_name} ${m.middle_name || ''}`.trim();
            opts += `<option value="${Number(m.member_id)}">${escapeHtml(name)}</option>`;
        });
        sel.innerHTML = opts;
        if (currentVal) sel.value = currentVal;
    });
}

/**
 * Check URL Hash / Query actions (e.g. #attendance, #minutes, #outcome, #referral)
 */
function checkUrlHashAction() {
    const urlParams = new URLSearchParams(window.location.search);
    const action = urlParams.get('action') || window.location.hash.replace('#', '');
    const hearingIdParam = urlParams.get('hearing_id');

    setTimeout(() => {
        if (hearingIdParam) {
            selectInlineMinutesSession(Number(hearingIdParam));
            return;
        }

        if (action === 'schedule') {
            openScheduleModal();
        } else if (action === 'outcome') {
            openRecordOutcomeModal();
        } else if (action === 'referral') {
            openReferPangkatModal();
        } else if (action === 'minutes' || action === 'minutesSection') {
            const firstSession = stageWorkspaceState.sessions[0];
            if (firstSession) selectInlineMinutesSession(firstSession.hearing_id);
        } else if (action === 'attendance') {
            const firstSession = stageWorkspaceState.sessions[0];
            if (firstSession) openAttendanceModal(firstSession.hearing_id);
        }
    }, 250);
}

/**
 * Main Render Dispatcher
 */
function renderWorkspaceUI() {
    const data = stageWorkspaceState.stageData;
    if (!data) return;

    const mode = stageWorkspaceState.mode;
    let stage = data.mediation;
    if (mode === 'Conciliation') stage = data.conciliation;
    else if (mode === 'Arbitration') stage = data.arbitration;
    if (!stage) stage = {};

    // Gate check for locked stages
    const lockedGateEl = document.getElementById('stageLockedGate');
    const contentWrapEl = document.getElementById('stageWorkspaceContent');

    if (stage.is_locked) {
        if (lockedGateEl) lockedGateEl.style.display = 'block';
        if (contentWrapEl) contentWrapEl.style.display = 'none';
        return;
    } else {
        if (lockedGateEl) lockedGateEl.style.display = 'none';
        if (contentWrapEl) contentWrapEl.style.display = 'block';
    }

    renderHeaderAndBreadcrumbs(data, stage);
    renderStageOverview(data, stage);
    if (mode === 'Conciliation') {
        renderPangkatPanelSection(data, stage);
    }
    renderSessionsList(data, stage);
    renderMinutesSection(data, stage);
    renderOutcomeAndReferralSection(data, stage);
}

/**
 * Render Header & Breadcrumbs
 */
function renderHeaderAndBreadcrumbs(data, stage) {
    const caseData = data.case || {};
    const titleEl = document.getElementById('wsStageTitle');
    const badgeEl = document.getElementById('wsStageStatusBadge');
    const subtextEl = document.getElementById('wsCaseSubtext');
    const breadcrumbStage = document.getElementById('wsBreadcrumbStage');
    const backLinkEl = document.getElementById('wsBackToComplaintLink');

    if (titleEl) {
        if (stageWorkspaceState.mode === 'Mediation') {
            titleEl.textContent = 'Mediation';
            document.title = 'Mediation - AGAP';
        } else if (stageWorkspaceState.mode === 'Conciliation') {
            titleEl.textContent = 'Pangkat Conciliation';
            document.title = 'Pangkat Conciliation - AGAP';
        } else if (stageWorkspaceState.mode === 'Arbitration') {
            titleEl.textContent = 'Binding Arbitration';
            document.title = 'Binding Arbitration - AGAP';
        }
    }

    if (breadcrumbStage) {
        breadcrumbStage.textContent = (stageWorkspaceState.mode === 'Mediation') ? 'Mediation' : (stageWorkspaceState.mode === 'Conciliation' ? 'Pangkat Conciliation' : 'Binding Arbitration');
    }

    if (badgeEl) {
        badgeEl.textContent = stage.stage_status;
        let bg = '#f1f5f9', color = '#475569';
        const st = stage.stage_status;
        if (st === 'Completed' || st === 'Settled') { bg = '#dcfce7'; color = '#15803d'; }
        else if (st === 'In Progress') { bg = '#dbeafe'; color = '#1d4ed8'; }
        else if (st === 'Awaiting Outcome' || st === 'Ready for Referral') { bg = '#fef3c7'; color = '#b45309'; }
        else if (st === 'Referred to Pangkat') { bg = '#e0f2fe'; color = '#0369a1'; }
        badgeEl.style.background = bg;
        badgeEl.style.color = color;
    }

    if (subtextEl) {
        subtextEl.textContent = `Case #${caseData.case_number || 'N/A'} · Complaint #${caseData.complaint_number || 'N/A'} · Docketed: ${formatDateReadable(caseData.docket_date)}`;
    }

    if (backLinkEl && stageWorkspaceState.complaintId) {
        backLinkEl.href = `../complaints/complaint-details.php?id=${encodeURIComponent(stageWorkspaceState.complaintId)}#caseWorkspaceSection`;
    }

    renderWorkspaceActions(stage);
}

function renderWorkspaceActions(stage) {
    const primary = document.getElementById('wsPrimaryAction');
    const more = document.getElementById('wsMoreActionsMenu');
    if (!primary || !more) return;

    const mode = stageWorkspaceState.mode;
    const actions = stage.documents_and_actions || {};
    const sessions = stageWorkspaceState.sessions || [];
    const unfinished = sessions.find((session) => !['Finalized', 'Completed'].includes(session.minutes_status || ''));
    let label = 'Schedule hearing';
    let handler = 'openScheduleModal()';

    if (unfinished) {
        label = 'Complete session closeout';
        handler = `openSessionCloseout(${Number(unfinished.hearing_id)})`;
    } else if (mode === 'Mediation' && actions.can_proceed_to_pangkat) {
        label = 'Constitute Pangkat';
        handler = 'openReferPangkatModal()';
    } else if (mode === 'Mediation' && actions.can_book_next_mediation) {
        label = `Book ${Number(actions.next_mediation_session_number || 2) === 2 ? '2nd' : '3rd'} Mediation`;
        handler = 'openScheduleModal()';
    } else if (mode === 'Conciliation' && actions.can_issue_cfa) {
        label = 'Issue CFA (KP Form 20)';
        handler = `window.location.href='../documents/cfa.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}'`;
    } else if (mode === 'Conciliation' && !sessions.length) {
        label = 'Schedule 1st Conciliation';
        handler = 'openScheduleModal()';
    }

    primary.innerHTML = `<button type="button" class="btn-create" onclick="${handler}" style="font-size:.82rem; padding:7px 14px; display:inline-flex; align-items:center; gap:5px;">${escapeHtml(label)} &rarr;</button>`;

    const scheduleLabel = mode === 'Conciliation' ? 'Schedule Conciliation' : 'Schedule Mediation';
    const panelAction = mode === 'Conciliation'
        ? `<button type="button" class="btn-secondary" onclick="openPangkatSelectionModal()">Panel selection</button><button type="button" class="btn-secondary" onclick="openPangkatExtensionModal()">Grant extension</button>`
        : `<button type="button" class="btn-secondary" onclick="openReferPangkatModal()">Refer to Pangkat</button>`;
    const cfaAction = mode === 'Conciliation' ? `<a class="btn-secondary" href="../documents/cfa.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}">CFA workflow</a>` : '';
    more.innerHTML = `<button type="button" class="btn-secondary" onclick="openScheduleModal()">${scheduleLabel}</button><button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()">Record stage outcome</button>${panelAction}${cfaAction}`;
    more.querySelectorAll('.btn-secondary').forEach((button) => {
        button.style.cssText = 'display:block; width:100%; margin:2px 0; text-align:left; border:0; background:transparent; padding:8px 10px; cursor:pointer; text-decoration:none; color:#334155; font-size:.82rem;';
    });
}

function openSessionCloseout(hearingId) {
    selectInlineMinutesSession(hearingId);
    const session = stageWorkspaceState.sessions.find((item) => Number(item.hearing_id) === Number(hearingId)) || {};
    const attendanceRecorded = Boolean(session.attendance_recorded_at || session.complainant_attendance || session.respondent_attendance);
    if (!attendanceRecorded) {
        openAttendanceModal(hearingId);
        return;
    }
    document.getElementById('minutesSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
window.openSessionCloseout = openSessionCloseout;

/**
 * Render Stage Overview Panel
 */
function renderStageOverview(data, stage) {
    const caseData = data.case || {};
    const mode = stageWorkspaceState.mode;
    const overviewContainer = document.getElementById('wsStageOverviewGrid');
    if (!overviewContainer) return;

    let mediatorInfoHtml = '';
    if (mode === 'Mediation') {
        mediatorInfoHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">Presiding Mediator</span>
                <strong class="stat-value" style="font-size: 0.92rem;">${escapeHtml(stage.presiding_officer || 'Punong Barangay')}</strong>
                <span class="stat-sub">Barangay Captain / Mediator</span>
            </div>
        `;
    } else if (mode === 'Conciliation') {
        const pInfo = stage.pangkat_assignment || {};
        mediatorInfoHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">Pangkat Officers</span>
                <strong class="stat-value" style="font-size: 0.88rem;">${escapeHtml(pInfo.chairperson?.member_name || 'Pending')} (Chair)</strong>
                <span class="stat-sub">${escapeHtml(pInfo.secretary?.member_name || 'Pending')} (Sec) · ${escapeHtml(pInfo.member?.member_name || 'Pending')}</span>
            </div>
        `;
    } else if (mode === 'Arbitration') {
        mediatorInfoHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">Presiding Arbitrator</span>
                <strong class="stat-value" style="font-size: 0.92rem;">${escapeHtml(stage.presiding_officer || 'Punong Barangay')}</strong>
                <span class="stat-sub">Hearing Officer / Arbitrator</span>
            </div>
        `;
    }

    let deadlineBadgeHtml = '';
    if (mode === 'Mediation' && caseData.mediation_timer) {
        // 15-Day Mediation Limit badge removed
        deadlineBadgeHtml = '';
    } else if (mode === 'Conciliation' && stage.deadline) {
        const dl = stage.deadline;
        let conveningHtml = '';
        if (!dl.is_convened) {
            const isOverdue = !!dl.convening_overdue;
            const daysLeft = Number(dl.convening_days_left ?? 0);
            const badgeBg = isOverdue ? '#fee2e2' : '#fef3c7';
            const badgeColor = isOverdue ? '#991b1b' : '#92400e';
            const badgeText = isOverdue ? `Convening Overdue (${Math.abs(daysLeft)}d late)` : `Convene within ${daysLeft} day(s)`;
            conveningHtml = `
                <div class="overview-stat-card" style="border-left: 4px solid ${isOverdue ? '#ef4444' : '#f59e0b'};">
                    <span class="stat-label">3-Day Convening Requirement</span>
                    <span style="font-size: 0.8rem; padding: 3px 8px; border-radius: 4px; font-weight: 700; background: ${badgeBg}; color: ${badgeColor}; display: inline-flex; width: fit-content; margin: 3px 0;">
                        ${badgeText}
                    </span>
                    <span class="stat-sub">Deadline: ${formatDateReadable(dl.convening_deadline)} (RA 7160 §410c)</span>
                </div>
            `;
        } else {
            conveningHtml = `
                <div class="overview-stat-card" style="border-left: 4px solid #10b981;">
                    <span class="stat-label">Pangkat Convened</span>
                    <strong class="stat-value" style="font-size: 0.9rem; color: #15803d;">${formatDateReadable(dl.convened_date)}</strong>
                    <span class="stat-sub">Constituted: ${formatDateReadable(dl.formation_date)}</span>
                </div>
            `;
        }

        // 15-Day Conciliation Limit clock removed
        deadlineBadgeHtml = conveningHtml;
    } else if (mode === 'Arbitration') {
        const arbRec = stage.arbitration_record || {};
        deadlineBadgeHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">Arbitration Protocol</span>
                <strong class="stat-value" style="font-size: 0.88rem; color: #1e40af;">${arbRec.agreement_date ? 'Agreement: ' + formatDateReadable(arbRec.agreement_date) : 'KP Form 14 Executed'}</strong>
                <span class="stat-sub">${arbRec.award_date ? 'Award Rendered: ' + formatDateReadable(arbRec.award_date) : 'Decision window: 10 calendar days'}</span>
            </div>
        `;
    }

    overviewContainer.innerHTML = `
        <div class="overview-stat-card">
            <span class="stat-label">Case Number</span>
            <strong class="stat-value">Case #${escapeHtml(caseData.case_number || 'N/A')}</strong>
            <span class="stat-sub">Type: ${escapeHtml(caseData.case_type || 'Civil')} · Status: ${escapeHtml(caseData.case_status || 'Docketed')}</span>
        </div>
        <div class="overview-stat-card">
            <span class="stat-label">Complainant(s)</span>
            <strong class="stat-value" style="font-size: 0.92rem;">${escapeHtml(caseData.complainant_names || '—')}</strong>
            <span class="stat-sub">Filing Date: ${formatDateReadable(caseData.filing_date)}</span>
        </div>
        <div class="overview-stat-card">
            <span class="stat-label">Respondent(s)</span>
            <strong class="stat-value" style="font-size: 0.92rem;">${escapeHtml(caseData.respondent_names || '—')}</strong>
            <span class="stat-sub">Barangay Tumana</span>
        </div>
        ${mediatorInfoHtml}
        ${deadlineBadgeHtml}
        <div class="overview-stat-card">
            <span class="stat-label">Total Sessions</span>
            <strong class="stat-value" style="font-size: 1.25rem; color: #0f172a;">${Number(stage.sessions_count || 0)}</strong>
            <span class="stat-sub">Latest: ${stage.latest_hearing_date ? formatDateReadable(stage.latest_hearing_date) : 'None'}</span>
        </div>
        <div class="overview-stat-card">
            <span class="stat-label">Current Stage Outcome</span>
            <strong class="stat-value" style="font-size: 0.92rem; color: ${stage.outcome === 'Settled' ? '#15803d' : '#b45309'};">${escapeHtml(stage.outcome || 'Pending')}</strong>
            <span class="stat-sub">${escapeHtml(stage.outcome_remarks || 'Awaiting formal hearing outcome')}</span>
        </div>
        ${stage.next_permitted_action ? `
        <div class="overview-stat-card" style="border-left: 4px solid #2563eb; background: #f8fafc;">
            <span class="stat-label">Next Permitted Action</span>
            <strong class="stat-value" style="font-size: 0.95rem; color: #1e40af;">${escapeHtml(stage.next_permitted_action.action)}</strong>
            <span class="stat-sub">${escapeHtml(stage.next_permitted_action.description)}</span>
        </div>
        ` : ''}
    `;
}

/**
 * Render Sessions List
 */
function renderSessionsList(data, stage) {
    const listContainer = document.getElementById('wsSessionsListContainer');
    const countBadge = document.getElementById('wsSessionsCountBadge');
    if (!listContainer) return;

    const sessions = stageWorkspaceState.sessions || [];
    if (countBadge) countBadge.textContent = `${sessions.length} session${sessions.length === 1 ? '' : 's'}`;

    if (!sessions.length) {
        listContainer.innerHTML = `
            <div class="empty-detail-state" style="text-align: center; padding: 36px 20px; background: #fff; border: 1px dashed #cbd5e1; border-radius: 8px;">
                <p style="margin: 0 0 12px; font-size: 0.95rem; color: #64748b;">
                    No ${escapeHtml(stageWorkspaceState.mode.toLowerCase())} hearing sessions scheduled yet.
                </p>
                <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.85rem; padding: 7px 16px; display: inline-flex; align-items: center; gap: 6px;">
                    Schedule ${escapeHtml(stageWorkspaceState.mode)} Hearing &rarr;
                </button>
            </div>
        `;
        return;
    }

    let cardsHtml = '';
    sessions.forEach(s => {
        const isCompleted = s.status === 'Completed';
        const hasMinutes = s.minutes_id && s.minutes_status !== 'Not Recorded';
        const isFinalized = s.minutes_status === 'Finalized';

        const compIn = (s.complainant_attendance === 'Present' || s.complainant_attendance === 'Late');
        const respIn = (s.respondent_attendance === 'Present' || s.respondent_attendance === 'Late');
        const isReady = compIn && respIn;
        const qStatus = s.queue_status || (isReady ? 'Ready' : 'Waiting');
        const isInSession = (qStatus === 'In_Session');
        const isStandby = (qStatus === 'Standby');
        const isConcluded = (qStatus === 'Concluded' || isCompleted);

        let queueBadgeHtml = '';
        if (isInSession) {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">In Session (Started: ${formatTimeOnly(s.actual_start_time)})</span>`;
        } else if (isReady) {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 800; padding: 2px 8px; border-radius: 4px; background: #dcfce7; color: #15803d; border: 1px solid #86efac;">Complete &amp; Ready</span>`;
        } else if (isStandby) {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">On Standby</span>`;
        } else if (isConcluded) {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">Concluded (${s.actual_duration_minutes || s.duration_minutes || 45} mins)</span>`;
        } else {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0;">Waiting Room</span>`;
        }

        let partyAttHtml = '';
        if (s.parties && s.parties.length) {
            partyAttHtml = s.parties.map(p => {
                let badgeStyle = 'background: #f1f5f9; color: #64748b;';
                let icon = '';
                const st = p.attendance_status || 'Pending';
                if (st === 'Present') {
                    badgeStyle = 'background: #dcfce7; color: #15803d;';
                    icon = '';
                } else if (st === 'Late') {
                    badgeStyle = 'background: #dbeafe; color: #1e40af;';
                    icon = '';
                } else if (st === 'Excused') {
                    badgeStyle = 'background: #fef3c7; color: #b45309;';
                    icon = '';
                } else if (st === 'Absent') {
                    badgeStyle = 'background: #fee2e2; color: #991b1b;';
                    icon = '';
                }
                const excuseText = p.justification_reason ? ` (${escapeHtml(p.justification_reason)})` : '';
                return `<div style="font-size: 0.78rem; margin-top: 2px;">
                    <span style="font-weight: 600; color: #475569;">${escapeHtml(p.party_type)}: ${escapeHtml(p.full_name)}</span> &rarr;
                    <span style="display: inline-flex; align-items: center; gap: 3px; font-weight: 700; padding: 1px 6px; border-radius: 4px; font-size: 0.74rem; ${badgeStyle}">
                        ${escapeHtml(st)}${excuseText}
                    </span>
                </div>`;
            }).join('');
        } else {
            partyAttHtml = `
                <div style="margin-top: 3px; color: #334155;">
                    Complainant: <strong>${escapeHtml(s.complainant_attendance || 'Pending')}</strong> · Respondent: <strong>${escapeHtml(s.respondent_attendance || 'Pending')}</strong>
                </div>
            `;
        }

        cardsHtml += `
            <div class="session-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                            <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; padding: 2px 7px; border-radius: 4px; background: #e0f2fe; color: #0369a1;">
                                #${s.session_index}
                            </span>
                            <h4 style="margin: 0; font-size: 1.05rem; color: #0f172a; font-weight: 700;">
                                ${escapeHtml(s.attempt_label || s.session_number)}
                            </h4>
                            ${queueBadgeHtml}
                            <span class="status-pill status-${escapeHtml(s.status.toLowerCase())}" style="font-size: 0.74rem; padding: 2px 8px; border-radius: 4px; font-weight: 700;">
                                ${escapeHtml(s.status)}
                            </span>
                            ${s.attempt_exception_reason ? `
                                <span style="font-size: 0.72rem; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; padding: 2px 7px; border-radius: 4px; font-weight: 600;">
                                    Exception: ${escapeHtml(s.attempt_exception_reason)}
                                </span>
                            ` : ''}
                            ${s.cancellation_category ? `
                                <span style="font-size: 0.72rem; color: #dc2626; background: #fee2e2; border: 1px solid #fecaca; padding: 2px 7px; border-radius: 4px; font-weight: 600;">
                                    Emergency: ${escapeHtml(s.cancellation_category)}
                                </span>
                            ` : ''}
                        </div>
                        <div style="font-size: 0.84rem; color: #475569; display: flex; gap: 14px; flex-wrap: wrap;">
                            <span>Call Time: <strong>${formatDateTimeReadable(s.hearing_date)}</strong></span>
                            <span>Venue: <strong>${escapeHtml(s.venue || 'Lupon Court Room')}</strong></span>
                            ${s.presiding_officer_name ? `<span>Presider: <strong>${escapeHtml(s.presiding_officer_name)}</strong></span>` : ''}
                            ${s.actual_start_time ? `<span>Actual Start: <strong>${formatTimeOnly(s.actual_start_time)}</strong></span>` : ''}
                            ${s.actual_duration_minutes ? `<span>Duration: <strong>${s.actual_duration_minutes} mins</strong></span>` : ''}
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                        ${!isConcluded && !isInSession ? `
                            ${isReady ? `
                            <button type="button" class="btn-create" onclick="handleDocketStartSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 12px; background: ${isReady ? '#16a34a' : '#2563eb'}; display: inline-flex; align-items: center; gap: 4px;" title="Out-of-order session activation: Call to session immediately if ready">
                                ▶️ Call to Session / Start Hearing
                            </button>
                            ` : ''}
                            <button type="button" class="btn-secondary" onclick="handleDocketStandby(${s.hearing_id}, ${isStandby ? 'false' : 'true'})" style="font-size: 0.78rem; padding: 5px 9px; display: inline-flex; align-items: center; gap: 4px;" title="Put on standby to keep room available for other ready cases">
                                ${isStandby ? 'Resume from Standby' : 'Put on Standby'}
                            </button>
                        ` : ''}
                        ${isInSession ? `
                            <button type="button" class="btn-create" onclick="handleDocketConcludeSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 12px; background: #b91c1c; display: inline-flex; align-items: center; gap: 4px;">
                                Conclude Session
                            </button>
                        ` : ''}
                        ${Number(s.session_index || 1) === 1 ? `
                            <button type="button" class="btn-secondary" onclick="openSummonsPreviewModal(${Number(stageWorkspaceState.caseId)}, null, ${Number(s.hearing_id)})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px; border-color: #0284c7; color: #0284c7; background: #f0f9ff;" title="Preview, print, or download KP Form No. 9 summons">
                                KP Form
                            </button>
                        ` : ''}
                        <button type="button" class="btn-secondary" onclick="openAttendanceModal(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;">
                            Detailed Attendance
                        </button>
                        <button type="button" class="btn-secondary" onclick="selectInlineMinutesSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px; ${isFinalized ? 'border-color: #86efac; background: #f0fdf4;' : ''}">
                            ${isFinalized ? 'View Finalized Minutes' : (hasMinutes ? 'Edit Minutes Draft' : 'Add Minutes')}
                        </button>
                    </div>
                </div>

                ${(() => {
                    const dateObj = new Date(s.hearing_date ? s.hearing_date.replace(' ', 'T') : '');
                    const nowObj = new Date();
                    const isDelayed15 = (!isConcluded && !isInSession && !isReady && !isStandby && !isNaN(dateObj.getTime()) && (nowObj - dateObj) > 15 * 60000);
                    if (!isDelayed15) return '';
                    return `
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 14px; margin-bottom: 12px; font-size: 0.8rem; color: #991b1b; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <strong>15-Minute Grace Period Expired:</strong> Scheduled call time has passed and parties remain incomplete.
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="btn-secondary" onclick="handleDocketStandby(${s.hearing_id}, true)" style="font-size: 0.74rem; padding: 4px 8px; background: #fff;">Move to Standby</button>
                                <button type="button" class="btn-secondary" onclick="openAttendanceModal(${s.hearing_id})" style="font-size: 0.74rem; padding: 4px 8px; background: #fee2e2; border-color: #fca5a5; color: #991b1b;">Record Non-Appearance</button>
                            </div>
                        </div>
                    `;
                })()}

                <!-- Daily Docket Live Arrival / Readiness Tracking Bar -->
                <div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="font-size: 0.76rem; font-weight: 700; color: #475569; text-transform: uppercase;">Party Arrival Check-In:</span>
                        ${(s.parties && s.parties.length > 2) ? s.parties.map(p => {
                            const isPIn = (p.attendance_status === 'Present' || p.attendance_status === 'Late');
                            return `
                                <button type="button" onclick="handleDocketCheckIn(${s.hearing_id}, '${p.party_type}', '${isPIn ? 'Waiting' : 'Checked In'}', ${p.resident_id})" style="font-size: 0.76rem; padding: 3px 8px; border-radius: 6px; border: 1px solid ${isPIn ? '#86efac' : '#cbd5e1'}; background: ${isPIn ? '#dcfce7' : '#fff'}; color: ${isPIn ? '#15803d' : '#475569'}; font-weight: 600; cursor: pointer;" title="Toggle arrival for ${escapeHtml(p.full_name)}">
                                    ${escapeHtml(p.party_type)} (${escapeHtml(p.full_name)}): [ ${isPIn ? 'Checked In' : 'Waiting'} ]
                                </button>
                            `;
                        }).join('') : `
                            <button type="button" onclick="handleDocketCheckIn(${s.hearing_id}, 'Complainant', '${compIn ? 'Waiting' : 'Checked In'}')" style="font-size: 0.78rem; padding: 3px 10px; border-radius: 6px; border: 1px solid ${compIn ? '#86efac' : '#cbd5e1'}; background: ${compIn ? '#dcfce7' : '#fff'}; color: ${compIn ? '#15803d' : '#475569'}; font-weight: 600; cursor: pointer;">
                                Complainant: [ ${compIn ? 'Checked In' : 'Waiting / In Transit'} ]
                            </button>
                            <button type="button" onclick="handleDocketCheckIn(${s.hearing_id}, 'Respondent', '${respIn ? 'Waiting' : 'Checked In'}')" style="font-size: 0.78rem; padding: 3px 10px; border-radius: 6px; border: 1px solid ${respIn ? '#86efac' : '#cbd5e1'}; background: ${respIn ? '#dcfce7' : '#fff'}; color: ${respIn ? '#15803d' : '#475569'}; font-weight: 600; cursor: pointer;">
                                Respondent: [ ${respIn ? 'Checked In' : 'Waiting / In Transit'} ]
                            </button>
                        `}
                    </div>
                    <div style="font-size: 0.8rem; font-weight: 600;">
                        ${isReady ? '<span style="color: #15803d;">Complete &amp; Ready for Hearing</span>' : '<span style="color: #64748b;">Waiting for parties to arrive</span>'}
                    </div>
                </div>

                <!-- Session Sub-Grid: Proof of Service & Attendance & Minutes Status -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.82rem;">
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">PROOF OF SERVICE</span>
                        <div style="margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 0.76rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; ${s.proof_of_service_status === 'Served' ? 'background: #dcfce7; color: #15803d;' : 'background: #fef3c7; color: #b45309;'}">
                                ${s.proof_of_service_status === 'Served' ? 'Notice Served' : 'Pending / Unserved'}
                            </span>
                        </div>
                    </div>
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">ATTENDANCE RECORD</span>
                        ${partyAttHtml}
                        ${s.attendance_notes ? `<div style="font-size: 0.75rem; color: #64748b; margin-top: 4px; font-style: italic;">Notes: ${escapeHtml(s.attendance_notes)}</div>` : ''}
                    </div>
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">MINUTES OF THE MEETING</span>
                        <div style="margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 0.76rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; ${isFinalized ? 'background: #dcfce7; color: #15803d;' : (hasMinutes ? 'background: #dbeafe; color: #1e40af;' : 'background: #f1f5f9; color: #64748b;')}">
                                ${escapeHtml(s.minutes_status)}
                            </span>
                            ${s.session_outcome && s.session_outcome !== 'Pending' ? `<span style="font-size: 0.74rem; color: #475569;">(${escapeHtml(s.session_outcome)})</span>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    listContainer.innerHTML = cardsHtml;
}

function formatTimeOnly(dateStr) {
    if (!dateStr) return '—';
    try {
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch (e) {
        return dateStr;
    }
}

async function handleDocketCheckIn(hearingId, partyType, status, residentId = null) {
    try {
        const payload = {
            action: 'check_in',
            hearing_id: hearingId,
            party_type: partyType,
            status: status
        };
        if (residentId) payload.resident_id = residentId;
        const res = await fetch(getStageApiUrl('hearings/queue.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            window.agapNotify?.(data.message, 'success');
            reloadWorkspace();
        } else {
            notifyUser(data.message || 'Could not update party check-in.', 'error');
        }
    } catch (err) {
        console.error('Docket check-in error:', err);
        notifyUser('Server error updating party check-in.', 'error');
    }
}

async function handleDocketStandby(hearingId, standby) {
    try {
        const res = await fetch(getStageApiUrl('hearings/queue.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'standby',
                hearing_id: hearingId,
                standby: standby ? 1 : 0
            })
        });
        const data = await res.json();
        if (data.success) {
            window.agapNotify?.(data.message, 'info');
            reloadWorkspace();
        } else {
            notifyUser(data.message || 'Could not update standby status.', 'error');
        }
    } catch (err) {
        console.error('Docket standby error:', err);
        notifyUser('Server error updating standby status.', 'error');
    }
}

async function handleDocketStartSession(hearingId) {
    if (!confirm('Call this hearing to session? Actual start time will be recorded now.')) {
        return;
    }
    try {
        const res = await fetch(getStageApiUrl('hearings/queue.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'start_session',
                hearing_id: hearingId
            })
        });
        const data = await res.json();
        if (data.success) {
            window.agapNotify?.(data.message, 'success');
            reloadWorkspace();
        } else {
            notifyUser(data.message || 'Could not start session.', 'error');
        }
    } catch (err) {
        console.error('Docket start session error:', err);
        notifyUser('Server error starting session.', 'error');
    }
}

async function handleDocketConcludeSession(hearingId) {
    if (!confirm('Conclude this session? Actual end time and duration will be recorded.')) {
        return;
    }
    try {
        const res = await fetch(getStageApiUrl('hearings/queue.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'conclude_session',
                hearing_id: hearingId
            })
        });
        const data = await res.json();
        if (data.success) {
            window.agapNotify?.(data.message, 'success');
            reloadWorkspace();
        } else {
            notifyUser(data.message || 'Could not conclude session.', 'error');
        }
    } catch (err) {
        console.error('Docket conclude session error:', err);
        notifyUser('Server error concluding session.', 'error');
    }
}

window.handleDocketCheckIn = handleDocketCheckIn;
window.handleDocketStandby = handleDocketStandby;
window.handleDocketStartSession = handleDocketStartSession;
window.handleDocketConcludeSession = handleDocketConcludeSession;
window.formatTimeOnly = formatTimeOnly;

/**
 * Render Hearing Session Minutes & Settlement Progress Section
 * Streamlined Option 2: 3 Chronological Sections
 * (Preliminaries -> Proceedings & Discussion -> Agreed Terms & Outcome)
 */
function renderMinutesSection(data, stage) {
    const minContainer = document.getElementById('wsMinutesSectionContainer');
    if (!minContainer) return;

    const sessions = stageWorkspaceState.sessions || [];
    const mode = stageWorkspaceState.mode;

    // 1. Calculate Overall Settlement & Negotiation Trajectory (Compact Badge)
    let trajectoryStatus = 'Exploring Dispute Resolution';
    let trajectoryBadgeStyle = 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;';

    const hasSettled = sessions.some(s => s.session_outcome === 'Settled' || s.outcome === 'Settled');
    const hasAward = sessions.some(s => s.session_outcome === 'Arbitration Award' || s.outcome === 'Arbitration Award');
    const hasFailed = sessions.some(s => ['Failed', 'Elevate to Pangkat', 'Pending CFA', 'Unsuccessful'].includes(s.session_outcome));
    const latestProposal = [...sessions].reverse().find(s => s.new_proposal?.trim())?.new_proposal;
    const latestCounteroffer = [...sessions].reverse().find(s => s.counteroffer?.trim())?.counteroffer;
    const anyCaucus = sessions.some(s => Boolean(s.caucus_conducted));

    if (hasSettled || stage.outcome === 'Settled') {
        trajectoryStatus = 'Amicable Settlement Reached';
        trajectoryBadgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;';
    } else if (hasAward || stage.outcome === 'Arbitration Award') {
        trajectoryStatus = 'Arbitration Award Rendered';
        trajectoryBadgeStyle = 'background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;';
    } else if (hasFailed || stage.outcome === 'Unsuccessful') {
        trajectoryStatus = mode === 'Mediation' ? 'Impasse · Elevated to Pangkat' : 'Impasse · Eligible for CFA';
        trajectoryBadgeStyle = 'background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;';
    } else if (latestProposal && latestCounteroffer) {
        trajectoryStatus = 'Active Proposals & Counteroffers';
        trajectoryBadgeStyle = 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;';
    } else if (latestProposal) {
        trajectoryStatus = 'Proposal Under Party Review';
        trajectoryBadgeStyle = 'background: #eff6ff; color: #2563eb; border: 1px solid #dbeafe;';
    } else if (anyCaucus) {
        trajectoryStatus = 'Private Caucus Held · Exploring Options';
        trajectoryBadgeStyle = 'background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff;';
    }

    // 2. Empty state if no sessions scheduled yet
    if (!sessions.length) {
        minContainer.innerHTML = `
            <div style="text-align: center; padding: 36px 20px; color: #64748b; background: #fff; border: 1px dashed #cbd5e1; border-radius: 10px;">
                <p style="margin: 0 0 10px; font-size: 0.95rem; color: #475569;">No ${escapeHtml(mode.toLowerCase())} sessions scheduled yet.</p>
                <small style="display: block; margin-bottom: 14px; color: #94a3b8;">Schedule a session first to record hearing minutes, statements, and settlement progress.</small>
                <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.84rem; padding: 7px 16px;">
                    Schedule ${escapeHtml(mode)} Hearing &rarr;
                </button>
            </div>
        `;
        return;
    }

    // Determine active session
    let activeHearingId = stageWorkspaceState.activeSessionHearingId;
    let activeSession = sessions.find(s => Number(s.hearing_id) === Number(activeHearingId));
    if (!activeSession) {
        activeSession = sessions[0];
        stageWorkspaceState.activeSessionHearingId = Number(activeSession.hearing_id);
    }

    const isFinalized = (activeSession.minutes_status === 'Finalized');
    const readonlyAttr = isFinalized ? 'readonly disabled' : '';

    const sessionIndex = Number(activeSession.session_index || 1);
    const nextSessionIndex = sessionIndex + 1;
    const nextSession = sessions.find(s => Number(s.session_index) === nextSessionIndex);

    // Compute min and default next hearing date based on current session
    let minNextDate = '';
    let defaultNextDate = '';
    if (activeSession.hearing_date) {
        const curD = new Date(activeSession.hearing_date.replace(' ', 'T'));
        if (!isNaN(curD.getTime())) {
            const minD = new Date(curD);
            minD.setDate(minD.getDate() + 1);
            minNextDate = minD.toISOString().substring(0, 10);

            const defD = new Date(curD);
            defD.setDate(defD.getDate() + 3);
            if (defD.getDay() === 0) defD.setDate(defD.getDate() + 1);
            else if (defD.getDay() === 6) defD.setDate(defD.getDate() + 2);
            defaultNextDate = defD.toISOString().substring(0, 10);
        }
    }
    if (!minNextDate) {
        const today = new Date();
        today.setDate(today.getDate() + 1);
        minNextDate = today.toISOString().substring(0, 10);
        defaultNextDate = minNextDate;
    }

    // Stage-adapted outcome options
    let outcomeOptions = '';
    const curOutcome = activeSession.session_outcome || 'Pending';
    if (mode === 'Mediation') {
        const opts = [
            { val: 'Continue Mediation', label: 'No Settlement → Set Another Meeting' },
            { val: 'Settled', label: 'Settled (Amicable Settlement Reached — KP Form 16)' },
            { val: 'Elevate to Pangkat', label: 'Elevate to Pangkat ng Tagapagkasundo (KP Form 10)' },
            { val: 'Party Absent', label: 'Party Absent / Non-appearance Recorded' },
            { val: 'Rescheduled', label: 'Rescheduled — Session Reset Upon Request' },
            { val: 'Failed', label: 'Failed — Mediation Unsuccessful / Impasse' },
            { val: 'Pending', label: 'Pending Evaluation' }
        ];
        outcomeOptions = opts.map(o => `<option value="${o.val}" ${curOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    } else if (mode === 'Conciliation') {
        const opts = [
            { val: 'Continue Conciliation', label: 'Continue Conciliation (Next Session Required)' },
            { val: 'Settled', label: 'Settled (Amicable Settlement Reached — KP Form 16)' },
            { val: 'Pending CFA', label: 'Conciliation Failed — Ready for CFA (KP Form 20)' },
            { val: 'Party Absent', label: 'Party Absent / Non-appearance Recorded' },
            { val: 'Rescheduled', label: 'Rescheduled — Session Reset' },
            { val: 'Failed', label: 'Failed — Conciliation Unsuccessful / Impasse' },
            { val: 'Pending', label: 'Pending Evaluation' }
        ];
        outcomeOptions = opts.map(o => `<option value="${o.val}" ${curOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    } else if (mode === 'Arbitration') {
        const opts = [
            { val: 'Continue Arbitration', label: 'Continue Arbitration Hearing' },
            { val: 'Arbitration Award', label: 'Render Arbitration Award (KP Form 15)' },
            { val: 'Settled', label: 'Settled — Parties Reached Settlement During Hearing' },
            { val: 'Party Absent', label: 'Party Absent' },
            { val: 'Rescheduled', label: 'Rescheduled' },
            { val: 'Failed', label: 'Dismissed / Repudiated Arbitration Agreement' },
            { val: 'Pending', label: 'Pending Evaluation' }
        ];
        outcomeOptions = opts.map(o => `<option value="${o.val}" ${curOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    }

    // Build session selector tabs
    let sessionTabsHtml = '';
    sessions.forEach(s => {
        const isSelected = (Number(s.hearing_id) === Number(activeSession.hearing_id));
        const hasMin = Boolean(s.minutes_id && s.minutes_status !== 'Not Recorded');
        const isFin = (s.minutes_status === 'Finalized');
        let badgeBg = '#f1f5f9', badgeColor = '#64748b';
        if (isFin) { badgeBg = '#dcfce7'; badgeColor = '#15803d'; }
        else if (hasMin) { badgeBg = '#dbeafe'; badgeColor = '#1e40af'; }

        sessionTabsHtml += `
            <button type="button" onclick="selectInlineMinutesSession(${s.hearing_id})" style="border: 2px solid ${isSelected ? '#2563eb' : '#e2e8f0'}; background: ${isSelected ? '#eff6ff' : '#fff'}; border-radius: 8px; padding: 7px 13px; cursor: pointer; text-align: left; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; transition: all 0.15s ease;">
                <div>
                    <strong style="display: block; font-size: 0.83rem; color: ${isSelected ? '#1e40af' : '#0f172a'};">#${s.session_index} ${escapeHtml(s.session_number)}</strong>
                    <small style="color: #64748b; font-size: 0.73rem;">${formatDateReadable(s.hearing_date)}</small>
                </div>
                <span style="font-size: 0.70rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; background: ${badgeBg}; color: ${badgeColor};">
                    ${escapeHtml(s.minutes_status)}
                </span>
            </button>
        `;
    });

    // Party appearance tags
    const compAtt = activeSession.complainant_attendance || 'Pending';
    const respAtt = activeSession.respondent_attendance || 'Pending';
    const compAttBadge = compAtt === 'Present' ? 'background: #dcfce7; color: #15803d;' : (compAtt === 'Absent' ? 'background: #fee2e2; color: #991b1b;' : 'background: #f1f5f9; color: #64748b;');
    const respAttBadge = respAtt === 'Present' ? 'background: #dcfce7; color: #15803d;' : (respAtt === 'Absent' ? 'background: #fee2e2; color: #991b1b;' : 'background: #f1f5f9; color: #64748b;');

    // Presider attendance snippet
    let presiderDisplayHtml = '';
    if (activeSession.substitute_presider_name) {
        presiderDisplayHtml = `
            <div style="font-size: 0.78rem; margin-bottom: 7px; padding: 5px 9px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: #475569; font-weight: 600;">Presider:</span>
                    <span style="font-size: 0.7rem; font-weight: 700; padding: 1px 5px; border-radius: 4px; background: #fee2e2; color: #991b1b;">Absent</span>
                </div>
                <div style="color: #0f172a; font-weight: 600; font-size: 0.8rem;">
                    ${escapeHtml(activeSession.presiding_officer_name || 'Punong Barangay')}
                </div>
                <div style="margin-top: 3px; font-size: 0.75rem; color: #2563eb; font-weight: 600;">
                    ↳ Substitute: ${escapeHtml(activeSession.substitute_presider_name)} (Consent: Verified)
                </div>
                ${activeSession.substitute_reason ? `<div style="font-size: 0.71rem; color: #64748b; margin-top: 2px;">Reason: ${escapeHtml(activeSession.substitute_reason)}</div>` : ''}
            </div>
        `;
    } else {
        presiderDisplayHtml = `
            <div style="font-size: 0.78rem; margin-bottom: 7px; padding: 5px 9px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="color: #475569; font-weight: 600;">Presider:</span>
                    <span style="color: #0f172a; font-weight: 600; margin-left: 4px;">${escapeHtml(activeSession.presiding_officer_name || 'Punong Barangay / Lupon Chairman')}</span>
                </div>
                <span style="font-size: 0.7rem; font-weight: 700; padding: 1px 5px; border-radius: 4px; background: #dcfce7; color: #15803d;">Present</span>
            </div>
        `;
    }

    const isSecondOrLaterSession = (Number(activeSession.session_index || 1) >= 2 || !['Initial Hearing', 'Mediation'].includes(activeSession.hearing_type));

    let compConsequenceHtml = '';
    if (compAtt === 'Absent') {
        if (isSecondOrLaterSession) {
            compConsequenceHtml = `
                <div style="margin-top: 5px; font-size: 0.74rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 5px 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px; flex-wrap: wrap;">
                        <span style="color: #991b1b; font-weight: 700;">Complainant Non-appearance</span>
                        <button type="button" class="btn-secondary" onclick="openKPFormPreview(${stageWorkspaceState.caseId}, 'KP Form 18', ${activeSession.hearing_id})" style="font-size: 0.7rem; padding: 2px 7px; background: #fff; border-color: #fca5a5; color: #991b1b; cursor: pointer;">
                            Preview KP Form 18
                        </button>
                    </div>
                    <div style="color: #64748b; font-size: 0.71rem; margin-top: 3px;">
                        KP Form 21 (Bar Action) locked pending show-cause evaluation.
                    </div>
                </div>
            `;
        } else {
            compConsequenceHtml = `
                <div style="margin-top: 5px; font-size: 0.74rem; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 5px 8px;">
                    <span style="color: #475569; font-weight: 600;">Complainant Non-appearance (1st Mediation):</span>
                    <span style="color: #64748b; margin-left: 4px;">Proceed to book 2nd Mediation (no new summon needed). KP Form 18 notice unlocks upon non-appearance in 2nd Mediation.</span>
                </div>
            `;
        }
    }

    let respConsequenceHtml = '';
    if (respAtt === 'Absent') {
        if (isSecondOrLaterSession) {
            respConsequenceHtml = `
                <div style="margin-top: 5px; font-size: 0.74rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 5px 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px; flex-wrap: wrap;">
                        <span style="color: #991b1b; font-weight: 700;">Respondent Non-appearance</span>
                        <button type="button" class="btn-secondary" onclick="openKPFormPreview(${stageWorkspaceState.caseId}, 'KP Form 19', ${activeSession.hearing_id})" style="font-size: 0.7rem; padding: 2px 7px; background: #fff; border-color: #fca5a5; color: #991b1b; cursor: pointer;">
                            Preview KP Form 19
                        </button>
                    </div>
                    <div style="color: #64748b; font-size: 0.71rem; margin-top: 3px;">
                        KP Form 22 (Bar Counterclaim) locked pending show-cause evaluation.
                    </div>
                </div>
            `;
        } else {
            respConsequenceHtml = `
                <div style="margin-top: 5px; font-size: 0.74rem; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 5px 8px;">
                    <span style="color: #475569; font-weight: 600;">Respondent Non-appearance (1st Mediation):</span>
                    <span style="color: #64748b; margin-left: 4px;">Proceed to book 2nd Mediation (no new summon needed). KP Form 19 notice unlocks upon non-appearance in 2nd Mediation.</span>
                </div>
            `;
        }
    }

    // Check if proposals or caucus have content
    const hasProposalsOrCaucus = Boolean(activeSession.new_proposal?.trim() || activeSession.counteroffer?.trim() || activeSession.caucus_conducted || activeSession.caucus_notes?.trim());

    // 3. Build Form with 3 Streamlined Sections
    let formHtml = `
        <!-- Compact Session Selector & Trajectory Bar (Replaces bulky 4-box tracker) -->
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span style="font-size: 0.84rem; font-weight: 700; color: #0f172a;">Select Session:</span>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    ${sessionTabsHtml}
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b;">Trajectory:</span>
                <span style="font-size: 0.78rem; font-weight: 700; padding: 3px 10px; border-radius: 9999px; ${trajectoryBadgeStyle}">
                    ${escapeHtml(trajectoryStatus)}
                </span>
            </div>
        </div>

        <!-- The 3-Section Streamlined Minutes Workspace Card -->
        <div class="inline-minutes-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
            <!-- Card Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid #e2e8f0;">
                <div>
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #0f172a;">
                        Minutes of the Meeting &amp; Hearing Workspace
                    </h3>
                    <p style="margin: 3px 0 0; font-size: 0.83rem; color: #64748b;">
                        Record formal proceedings, statements, agreed terms, and disposition for <strong>${escapeHtml(activeSession.session_number)}</strong> (${formatDateTimeReadable(activeSession.hearing_date)}).
                    </p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <span style="font-size: 0.78rem; padding: 4px 10px; border-radius: 9999px; font-weight: 700; ${isFinalized ? 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;' : 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;'}">
                        ${isFinalized ? 'FINALIZED &amp; LOCKED' : 'WORKING DRAFT'}
                    </span>
                    <button type="button" class="btn-secondary" onclick="openPreviewMinutesModal(${activeSession.hearing_id})" style="font-size: 0.78rem; padding: 4px 10px;">
                        Preview Letterhead
                    </button>
                    ${(isFinalized || activeSession.minutes_id) ? `
                        <button type="button" class="btn-secondary" onclick="printHearingMinutes(${activeSession.hearing_id})" style="font-size: 0.78rem; padding: 4px 10px;">
                            Print Minutes
                        </button>
                    ` : ''}
                </div>
            </div>

            <!-- Lock Notice if Finalized -->
            ${isFinalized ? `
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #166534; font-size: 0.85rem;">
                        <strong>Official Record Locked:</strong> This session minutes was finalized${activeSession.finalized_at ? ` on ${formatDateTimeReadable(activeSession.finalized_at)}` : ''}${activeSession.finalized_by_name ? ` by ${escapeHtml(activeSession.finalized_by_name)}` : ''}. To preserve legal integrity, finalized minutes cannot be modified.
                    </div>
                </div>
            ` : ''}

            <!-- Form -->
            <form id="inlineMinutesForm" onsubmit="event.preventDefault();">
                <input type="hidden" id="minHearingId" value="${activeSession.hearing_id}">

                <!-- ============================================================== -->
                <!-- SECTION 1: PRELIMINARIES (Quorum, Attendance & Opening Checks)  -->
                <!-- ============================================================== -->
                <div class="minutes-flow-step" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; margin-bottom: 18px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: #2563eb; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.78rem;">1</span>
                            <h4 style="margin: 0; font-size: 0.96rem; font-weight: 700; color: #0f172a;">Preliminaries &amp; Procedural Opening</h4>
                        </div>
                        <button type="button" class="btn-secondary" onclick="openAttendanceModal(${activeSession.hearing_id})" style="font-size: 0.76rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                            Verify / Edit Attendance
                        </button>
                    </div>

                    <div style="display: grid; grid-template-columns: 1.15fr 1.2fr; gap: 16px; align-items: flex-start; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px;">
                        <!-- Left: Presider & Party Appearance Status -->
                        <div>
                            <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; display: block; margin-bottom: 6px;">Presider &amp; Party Appearance</span>
                            ${presiderDisplayHtml}
                            <div style="display: flex; gap: 12px; flex-wrap: wrap; font-size: 0.83rem; margin-bottom: 4px;">
                                <div style="display: inline-flex; align-items: center; gap: 6px;">
                                    <span style="color: #475569; font-weight: 600;">Complainant:</span>
                                    <span style="font-size: 0.74rem; font-weight: 700; padding: 2px 7px; border-radius: 4px; ${compAttBadge}">
                                        ${escapeHtml(compAtt)}
                                    </span>
                                </div>
                                <div style="display: inline-flex; align-items: center; gap: 6px;">
                                    <span style="color: #475569; font-weight: 600;">Respondent:</span>
                                    <span style="font-size: 0.74rem; font-weight: 700; padding: 2px 7px; border-radius: 4px; ${respAttBadge}">
                                        ${escapeHtml(respAtt)}
                                    </span>
                                </div>
                            </div>
                            ${compConsequenceHtml}
                            ${respConsequenceHtml}
                        </div>

                        <!-- Right: Procedural Checkboxes -->
                        <div>
                            <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; display: block; margin-bottom: 6px;">Procedural Verification</span>
                            <div style="display: flex; gap: 18px; flex-wrap: wrap; font-size: 0.83rem;">
                                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none;">
                                    <input type="checkbox" id="minOpening" ${activeSession.opening_conducted ? 'checked' : ''} ${readonlyAttr} style="width: 15px; height: 15px; accent-color: #2563eb;">
                                    <span style="color: #334155; font-weight: 500;">Opening Conducted</span>
                                </label>
                                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none;">
                                    <input type="checkbox" id="minIdentity" ${activeSession.identity_verified ? 'checked' : ''} ${readonlyAttr} style="width: 15px; height: 15px; accent-color: #2563eb;">
                                    <span style="color: #334155; font-weight: 500;">Identities Verified</span>
                                </label>
                                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none;">
                                    <input type="checkbox" id="minReviewed" ${activeSession.complaint_reviewed ? 'checked' : ''} ${readonlyAttr} style="width: 15px; height: 15px; accent-color: #2563eb;">
                                    <span style="color: #334155; font-weight: 500;">Complaint Read</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================== -->
                <!-- SECTION 2: PROCEEDINGS & DISCUSSION (Dispute, Statements, STT) -->
                <!-- ============================================================== -->
                <div class="minutes-flow-step" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; margin-bottom: 18px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: #2563eb; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.78rem;">2</span>
                            <div>
                                <h4 style="margin: 0; font-size: 0.96rem; font-weight: 700; color: #0f172a;">Proceedings &amp; Discussion</h4>
                                <span style="font-size: 0.76rem; color: #64748b;">Dispute focus, party statements, negotiations, and live transcript</span>
                            </div>
                        </div>
                        ${!isFinalized ? `
                            <div style="display: flex; gap: 6px; align-items: center;">
                                <button type="button" class="btn-secondary" id="btnStageRecordVoice" onclick="toggleStageVoiceRecording()" style="font-size: 0.76rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                    <span id="stageRecordVoiceText">Record Voice (STT)</span>
                                </button>
                                <button type="button" class="btn-secondary" id="btnStageUploadNotes" onclick="triggerStageNotesUpload()" style="font-size: 0.76rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                    Upload Notes (OCR)
                                </button>
                                <input type="file" id="stageNotesFileInput" accept="image/*" style="display: none;" onchange="handleStageNotesFileSelected(this)">
                            </div>
                        ` : ''}
                    </div>

                    <!-- AI Status Banner -->
                    <div id="stageAiStatusBanner" style="display: none; padding: 8px 12px; margin-bottom: 12px; border-radius: 6px; font-size: 0.82rem; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;"></div>

                    <!-- Row 1: Dispute Focus & Agenda Topics -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minDisputeSummary" style="font-weight: 600; font-size: 0.82rem; color: #1e293b; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                                <span>Main Dispute Identified</span>
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: normal;">Core conflict summary</span>
                            </label>
                            <input type="text" id="minDisputeSummary" maxlength="1000" placeholder="e.g. Boundary encroachment, unpaid debt, disturbance during rest hours..." value="${escapeHtml(activeSession.main_dispute_identified || '')}" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 0.84rem; box-sizing: border-box;" ${readonlyAttr}>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minAgenda" style="font-weight: 600; font-size: 0.82rem; color: #1e293b; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                                <span>Agenda / Key Discussion Topics</span>
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: normal;">Matters addressed</span>
                            </label>
                            <input type="text" id="minAgenda" maxlength="3000" placeholder="e.g. Survey verification, payment schedule terms, apology condition..." value="${escapeHtml(activeSession.agenda_topics || '')}" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 0.84rem; box-sizing: border-box;" ${readonlyAttr}>
                        </div>
                    </div>

                    <!-- Row 2: Complainant vs Respondent Statements -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minCompStmt" style="font-weight: 600; font-size: 0.82rem; color: #1e293b; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                                <span>Complainant Statement &amp; Claims</span>
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: normal;">Grievances &amp; demands</span>
                            </label>
                            <textarea id="minCompStmt" rows="3" maxlength="5000" placeholder="Summary of complainant's claims, grievances, and narration of facts..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 0.84rem; font-family: inherit; line-height: 1.4; box-sizing: border-box;" ${readonlyAttr}>${escapeHtml(activeSession.complainant_statement || '')}</textarea>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minRespStmt" style="font-weight: 600; font-size: 0.82rem; color: #1e293b; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                                <span>Respondent Statement &amp; Defenses</span>
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: normal;">Explanation &amp; defenses</span>
                            </label>
                            <textarea id="minRespStmt" rows="3" maxlength="5000" placeholder="Summary of respondent's explanation, defenses, and counter-statements..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 0.84rem; font-family: inherit; line-height: 1.4; box-sizing: border-box;" ${readonlyAttr}>${escapeHtml(activeSession.respondent_statement || '')}</textarea>
                        </div>
                    </div>

                    <!-- Row 3: Central Discussion & Proceedings Transcript -->
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="session_minutes" style="font-weight: 600; font-size: 0.82rem; color: #1e293b; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                            <span>Hearing Proceedings &amp; Discussion Transcript</span>
                            <span style="font-size: 0.72rem; color: #64748b; font-weight: normal;">Full recorded dialogue or audio transcription</span>
                        </label>
                        <textarea id="session_minutes" rows="4" placeholder="Detailed notes of proceedings, mediator interventions, exchanges between parties, commitments made..." ${readonlyAttr} style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px; font-size: 0.84rem; font-family: inherit; line-height: 1.45; box-sizing: border-box;">${escapeHtml(activeSession.settlement_discussion_notes || '')}</textarea>
                    </div>

                    <!-- Row 4: Settlement Proposals, Counteroffers & Caucus (Integrated Sub-Panel) -->
                    <details class="proposals-caucus-details" ${hasProposalsOrCaucus ? 'open' : ''} style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                        <summary style="cursor: pointer; font-size: 0.82rem; font-weight: 700; color: #334155; user-select: none; display: flex; align-items: center; justify-content: space-between;">
                            <span>Settlement Proposals, Counteroffers &amp; Private Caucus (Optional)</span>
                            <span style="font-size: 0.74rem; font-weight: normal; color: #64748b;">${hasProposalsOrCaucus ? 'Details recorded' : 'Click to expand if applicable'}</span>
                        </summary>
                        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e2e8f0;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 10px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="minNewProposal" style="font-weight: 600; font-size: 0.8rem; color: #334155; margin-bottom: 3px; display: block;">Settlement Proposal / Offer</label>
                                    <textarea id="minNewProposal" rows="2" maxlength="3000" placeholder="Specific terms proposed by offering party (restitution, apology, timeline)..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 7px; font-size: 0.82rem; font-family: inherit; box-sizing: border-box;" ${readonlyAttr}>${escapeHtml(activeSession.new_proposal || '')}</textarea>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="minCounterOffer" style="font-weight: 600; font-size: 0.8rem; color: #334155; margin-bottom: 3px; display: block;">Counteroffer / Response</label>
                                    <textarea id="minCounterOffer" rows="2" maxlength="3000" placeholder="Counter-proposal, condition adjustments, or alternative terms offered..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 7px; font-size: 0.82rem; font-family: inherit; box-sizing: border-box;" ${readonlyAttr}>${escapeHtml(activeSession.counteroffer || '')}</textarea>
                                </div>
                            </div>
                            <div style="margin-top: 6px;">
                                <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.82rem; color: #334155; font-weight: 600;">
                                    <input type="checkbox" id="minCaucus" ${activeSession.caucus_conducted ? 'checked' : ''} ${readonlyAttr} onchange="document.getElementById('minCaucusNotesWrap').style.display = this.checked ? 'block' : 'none';" style="width: 15px; height: 15px; accent-color: #2563eb;">
                                    <span>Private Caucus Conducted (Separate meeting held with one party)</span>
                                </label>
                                <div id="minCaucusNotesWrap" style="${activeSession.caucus_conducted ? '' : 'display: none;'} margin-top: 8px;">
                                    <label for="minCaucusNotes" style="font-size: 0.76rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 3px;">Confidential Caucus Notes</label>
                                    <textarea id="minCaucusNotes" rows="2" placeholder="Confidential mediator insights, party reservations, concessions..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 7px; font-size: 0.82rem; font-family: inherit; box-sizing: border-box;" ${readonlyAttr}>${escapeHtml(activeSession.caucus_notes || '')}</textarea>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>

                <!-- ============================================================== -->
                <!-- SECTION 3: AGREED TERMS & OUTCOME (Terms, Outcome & Action)   -->
                <!-- ============================================================== -->
                <div class="minutes-flow-step" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; margin-bottom: 18px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: #2563eb; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.78rem;">3</span>
                            <div>
                                <h4 style="margin: 0; font-size: 0.96rem; font-weight: 700; color: #0f172a;">Agreed Terms &amp; Outcome Determination</h4>
                                <span style="font-size: 0.76rem; color: #64748b;">Record agreed stipulations, session determination, and direct stage progression</span>
                            </div>
                        </div>
                    </div>

                    <!-- Row 1: Agreed Terms vs Unresolved Issues -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minAgreements" style="font-weight: 600; font-size: 0.82rem; color: #166534; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                                <span>Agreed Terms / Action Items</span>
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: normal;">Mutual stipulations &amp; payment milestones</span>
                            </label>
                            <textarea id="minAgreements" rows="3" maxlength="3000" placeholder="Stipulations mutually agreed upon, payment milestones, restitution schedule, or behavioral commitments..." style="width: 100%; border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: 6px; padding: 8px; font-size: 0.84rem; font-family: inherit; line-height: 1.4; box-sizing: border-box;" ${readonlyAttr}>${escapeHtml(activeSession.agreements_action_items || '')}</textarea>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minUnresolved" style="font-weight: 600; font-size: 0.82rem; color: #991b1b; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between;">
                                <span>Issues Remaining Unresolved</span>
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: normal;">Points of contention or deferred items</span>
                            </label>
                            <textarea id="minUnresolved" rows="3" maxlength="3000" placeholder="Unresolved contentious points or items deferred to subsequent hearing or conciliation..." style="width: 100%; border: 1px solid #fecaca; background: #fff5f5; border-radius: 6px; padding: 8px; font-size: 0.84rem; font-family: inherit; line-height: 1.4; box-sizing: border-box;" ${readonlyAttr}>${escapeHtml(activeSession.unresolved_issues || '')}</textarea>
                        </div>
                    </div>

                    <!-- Row 2: Outcome Determination, End Time & Remarks -->
                    <div style="display: grid; grid-template-columns: 1.2fr 1fr 1.2fr; gap: 14px; margin-bottom: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minOutcome" style="font-weight: 700; font-size: 0.82rem; color: #0f172a; margin-bottom: 4px; display: block;">
                                Session Outcome Determination <span style="color: #dc2626;">*</span>
                            </label>
                            <select id="minOutcome" onchange="handleMinutesOutcomeChange(this.value, true)" ${readonlyAttr} required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 0.84rem; box-sizing: border-box; background: #fff; font-weight: 600; color: #1e293b;">
                                ${outcomeOptions}
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minActualEndTime" style="font-weight: 600; font-size: 0.82rem; color: #1e293b; margin-bottom: 4px; display: block;">
                                Actual Session End Time
                            </label>
                            <input type="datetime-local" id="minActualEndTime" value="${activeSession.actual_end_time ? activeSession.actual_end_time.replace(' ', 'T').substring(0, 16) : ''}" ${readonlyAttr} style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 0.84rem; box-sizing: border-box; background: #fff;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minOutcomeRemarks" style="font-weight: 600; font-size: 0.82rem; color: #1e293b; margin-bottom: 4px; display: block;">
                                Determination Notes / Remarks
                            </label>
                            <input type="text" id="minOutcomeRemarks" maxlength="1000" placeholder="Notes on determination, duration or next hearing basis..." value="${escapeHtml(activeSession.outcome_remarks || '')}" ${readonlyAttr} style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 0.84rem; box-sizing: border-box; background: #fff;">
                        </div>
                    </div>

                    <!-- Row 3: Dynamic Next-Step Action Banner -->
                    <div id="minStageActionBanner" style="margin-bottom: 12px;"></div>
                </div>

                <!-- Actions Footer -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 16px;">
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-secondary" onclick="openPreviewMinutesModal(${activeSession.hearing_id})" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                            Preview Letterhead
                        </button>
                        ${(isFinalized || activeSession.minutes_id) ? `
                            <button type="button" class="btn-secondary" onclick="printHearingMinutes(${activeSession.hearing_id})" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                                Print Minutes
                            </button>
                        ` : ''}
                    </div>
                    <div style="display: flex; gap: 8px;">
                        ${!isFinalized ? `
                            <button type="button" class="btn-secondary" onclick="selectInlineMinutesSession(${activeSession.hearing_id})" style="font-size: 0.84rem; padding: 7px 14px;">
                                Reset
                            </button>
                            <button type="button" class="btn-create" id="btnSaveMinutesDraft" onclick="saveHearingMinutes('Draft')" style="font-size: 0.84rem; padding: 7px 18px; background: #0f172a; color: #fff;">
                                Save Minutes Draft
                            </button>
                            <button type="button" class="btn-create" id="btnSaveMinutesFinalize" onclick="saveHearingMinutes('Finalized')" style="font-size: 0.84rem; padding: 7px 18px; background: #16a34a; color: #fff;">
                                Finalize &amp; Lock
                            </button>
                        ` : `
                            <span style="font-size: 0.84rem; color: #15803d; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; background: #f0fdf4; border-radius: 6px; border: 1px solid #bbf7d0;">
                                Finalized Official Record
                            </span>
                        `}
                    </div>
                </div>
            </form>
        </div>
    `;

    minContainer.innerHTML = formHtml;

    // Trigger dynamic next-step action banner for active session's outcome
    handleMinutesOutcomeChange(curOutcome, false);
}

/**
 * Dynamic Next-Step Action Banner based on Session Outcome Determination
 */
function handleMinutesOutcomeChange(outcomeVal, isUserChange = false) {
    const bannerEl = document.getElementById('minStageActionBanner');
    if (!bannerEl) return;
    const mode = stageWorkspaceState.mode;
    const caseId = stageWorkspaceState.caseId;
    const activeHearingId = stageWorkspaceState.activeSessionHearingId;
    const activeSession = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(activeHearingId)) || {};
    const sessionIndex = Number(activeSession.session_index || 1);

    // Toggle integrated Next Steps direct booking section (if exists)
    const nextStepsEl = document.getElementById('minNextStepsSection');
    if (nextStepsEl) {
        nextStepsEl.style.display = (outcomeVal === 'Continue Mediation' && mode === 'Mediation') ? 'block' : 'none';
    }

    if (outcomeVal === 'Settled') {
        bannerEl.innerHTML = `
            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="color: #166534; font-size: 0.85rem;">
                    <strong style="font-size: 0.92rem; display: block; color: #14532d;">Amicable Settlement Reached (Kasunduan)</strong>
                    Parties have agreed on settlement terms. Finalize the minutes, then preview or print the formal Amicable Settlement (KP Form 16).
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" onclick="openKPFormPreview(${Number(caseId)}, 'KP Form 16', ${Number(activeHearingId)})" style="font-size: 0.82rem; padding: 7px 14px; background: #fff; border-color: #86efac; color: #166534; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                        Preview / Print KP Form 16
                    </button>
                    <a href="../settlements/settlements.php?case_id=${encodeURIComponent(caseId)}" class="btn-create" style="font-size: 0.82rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                        Open Amicable Settlement &rarr;
                    </a>
                </div>
            </div>
        `;
    } else if (mode === 'Mediation') {
        if (outcomeVal === 'Party Absent') {
            const isSecondOrLater = Number(sessionIndex || 1) >= 2;
            bannerEl.innerHTML = `
                <div style="background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #991b1b; font-size: 0.85rem;">
                        <strong style="font-size: 0.92rem; display: block; color: #7f1d1d;">Party Non-Appearance Recorded (${sessionIndex === 1 ? '1st Mediation' : '2nd Mediation'})</strong>
                        ${isSecondOrLater 
                            ? 'A required party was absent in the 2nd Mediation session. Preview Notice of Hearing for failure to appear (KP Form 18 for Complainant / KP Form 19 for Respondent). Barring actions (KP Form 21 / 22) remain locked pending show-cause evaluation.'
                            : 'A required party was absent in the 1st Mediation. Under KP rules, proceed directly to book the 2nd Mediation session without sending another summon. KP Form 18/19 notices unlock upon non-appearance in the 2nd Mediation session.'
                        }
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openAttendanceModal(${Number(activeHearingId)})" style="font-size: 0.82rem; padding: 7px 12px; color: #991b1b; border-color: #fca5a5; background: #fff; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            Verify Attendance &amp; Notices
                        </button>
                        <button type="button" class="btn-secondary" onclick="openScheduleModal()" style="font-size: 0.82rem; padding: 7px 12px; cursor: pointer;">
                            ${sessionIndex === 1 ? 'Book 2nd Mediation &rarr;' : 'Re-schedule Session &rarr;'}
                        </button>
                    </div>
                </div>
            `;
        } else if (sessionIndex === 1) {
            const nextOrdinal = '2nd';
            const hasNextSession = stageWorkspaceState.sessions.some(s => Number(s.session_index) === 2);
            if (hasNextSession) {
                bannerEl.innerHTML = `
                    <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 10px 14px; font-size: 0.84rem; color: #166534; display: flex; align-items: center; gap: 8px;">
                        <span>✓</span>
                        <span><strong>${nextOrdinal} Mediation hearing is already booked.</strong> Review session notes and save or finalize minutes below.</span>
                    </div>
                `;
            } else {
                bannerEl.innerHTML = `
                    <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div style="color: #166534; font-size: 0.85rem;">
                            <strong style="font-size: 0.92rem; display: block; color: #14532d;">2nd Mediation Session Required</strong>
                            No settlement reached in 1st Mediation. Schedule the 2nd mediation session under RA 7160 Sec. 410 (Summons bypassed as parties are notified in person).
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.82rem; padding: 7px 16px; background: #16a34a; border: none; border-radius: 6px; color: #fff; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                                Schedule 2nd Mediation &rarr;
                            </button>
                        </div>
                    </div>
                `;
                if (isUserChange && outcomeVal === 'Continue Mediation') {
                    openScheduleModal();
                }
            }
        } else {
            // Session 2 completed — show Pangkat referral prompt
            bannerEl.innerHTML = `
                <div style="background: #f0f9ff; border: 1px solid #7dd3fc; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #0369a1; font-size: 0.85rem;">
                        <strong style="font-size: 0.92rem; display: block; color: #0c4a6e;">Mediation Concluded Unresolved (2 of 2 Sessions Completed)</strong>
                        Both mediation sessions before the Punong Barangay have concluded without agreement. The case is now eligible to elevate to Pangkat Conciliation (KP Form 10) by explicit authorized action.
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-create" onclick="openReferPangkatModal()" style="font-size: 0.82rem; padding: 7px 16px; background: #0369a1; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            Constitute Pangkat (KP Form 10) &rarr;
                        </button>
                    </div>
                </div>
            `;
        }
    } else if (outcomeVal === 'Pending CFA' || (mode === 'Conciliation' && outcomeVal === 'Failed')) {
        bannerEl.innerHTML = `
            <div style="background: #fff7ed; border: 1px solid #fdba74; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="color: #9a3412; font-size: 0.85rem;">
                    <strong style="font-size: 0.92rem; display: block; color: #7c2d12;">Eligible for Certificate to File Action (CFA)</strong>
                    Conciliation proceedings before the Pangkat concluded without settlement. The case may now proceed for court endorsement (KP Form 20).
                </div>
                <div>
                    <a href="../documents/cfa.php?case_id=${encodeURIComponent(caseId)}" class="btn-create" style="font-size: 0.82rem; padding: 7px 14px; background: #c2410c; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                        Issue CFA (KP Form 20) &rarr;
                    </a>
                </div>
            </div>
        `;
    } else if (outcomeVal === 'Continue Conciliation' || outcomeVal === 'Continue Arbitration' || outcomeVal === 'Rescheduled') {
        bannerEl.innerHTML = `
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="color: #334155; font-size: 0.85rem;">
                    <strong style="font-size: 0.92rem; display: block; color: #0f172a;">Proceedings Ongoing — Next Session Required</strong>
                    Parties require additional discussions to resolve issues. Schedule the subsequent hearing session within the statutory period.
                </div>
                <div>
                    <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 4px;">
                        Schedule Next Session &rarr;
                    </button>
                </div>
            </div>
        `;
    } else {
        bannerEl.innerHTML = '';
    }
}
window.handleMinutesOutcomeChange = handleMinutesOutcomeChange;

/**
 * Switch Active Minutes Session
 */
function selectInlineMinutesSession(hearingId) {
    stageWorkspaceState.activeSessionHearingId = Number(hearingId);
    const stage = (stageWorkspaceState.mode === 'Conciliation') ? stageWorkspaceState.stageData?.conciliation : ((stageWorkspaceState.mode === 'Arbitration') ? stageWorkspaceState.stageData?.arbitration : stageWorkspaceState.stageData?.mediation);
    renderMinutesSection(stageWorkspaceState.stageData, stage || {});
    const minCard = document.getElementById('minutesSection');
    if (minCard) {
        minCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
window.selectInlineMinutesSession = selectInlineMinutesSession;
window.openMinutesModal = selectInlineMinutesSession;
window.closeMinutesModal = function() {};

/**
 * Unified Save Hearing Minutes (Draft or Finalized)
 */
async function saveHearingMinutes(status = 'Draft') {
    const hearingId = document.getElementById('minHearingId')?.value || stageWorkspaceState.activeSessionHearingId;
    if (!hearingId) {
        notifyUser('No hearing session selected.', 'warning');
        return;
    }

    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    if (!session) {
        notifyUser('Session record not found.', 'warning');
        return;
    }

    const outcomeVal = document.getElementById('minOutcome')?.value;
    if (!outcomeVal) {
        notifyUser('Please select a session outcome.', 'warning');
        document.getElementById('minOutcome')?.focus();
        return;
    }

    if (status === 'Finalized') {
        const confirmLock = confirm(
            'Are you sure you want to FINALIZE this session minutes?\n\n' +
            'Once finalized, this official Lupong Tagapamayapa record will be locked and protected from further modification.'
        );
        if (!confirmLock) return;
    }

    const draftBtn = document.getElementById('btnSaveMinutesDraft');
    const finalizeBtn = document.getElementById('btnSaveMinutesFinalize');
    if (draftBtn) draftBtn.disabled = true;
    if (finalizeBtn) finalizeBtn.disabled = true;

    let sessionType = '1st Mediation';
    if (stageWorkspaceState.mode === 'Mediation') {
        sessionType = session ? (session.session_index === 1 ? '1st Mediation' : '2nd Mediation') : '1st Mediation';
    } else if (stageWorkspaceState.mode === 'Conciliation') {
        sessionType = 'Conciliation';
    } else if (stageWorkspaceState.mode === 'Arbitration') {
        sessionType = 'Arbitration';
    }

    const payload = {
        hearing_id: Number(hearingId),
        session_type: sessionType,
        opening_conducted: document.getElementById('minOpening')?.checked ? 1 : 0,
        identity_verified: document.getElementById('minIdentity')?.checked ? 1 : 0,
        complaint_reviewed: document.getElementById('minReviewed')?.checked ? 1 : 0,
        complainant_statement: document.getElementById('minCompStmt')?.value || '',
        respondent_statement: document.getElementById('minRespStmt')?.value || '',
        main_dispute_identified: document.getElementById('minDisputeSummary')?.value || '',
        agenda_topics: document.getElementById('minAgenda')?.value || '',
        new_proposal: document.getElementById('minNewProposal')?.value || '',
        counteroffer: document.getElementById('minCounterOffer')?.value || '',
        caucus_conducted: document.getElementById('minCaucus')?.checked ? 1 : 0,
        caucus_notes: document.getElementById('minCaucusNotes')?.value || '',
        agreements_action_items: document.getElementById('minAgreements')?.value || '',
        unresolved_issues: document.getElementById('minUnresolved')?.value || '',
        session_outcome: outcomeVal,
        actual_end_time: document.getElementById('minActualEndTime')?.value || null,
        outcome_remarks: document.getElementById('minOutcomeRemarks')?.value || '',
        settlement_discussion_notes: document.getElementById('session_minutes')?.value || '',
        session_minutes: document.getElementById('session_minutes')?.value || '',
        status: status
    };

    const isMediation = stageWorkspaceState.mode === 'Mediation';
    const sessionIndex = Number(session.session_index || 1);
    const nextSessionIndex = sessionIndex + 1;
    const hasNextSession = stageWorkspaceState.sessions.some(s => Number(s.session_index) === nextSessionIndex);

    if (outcomeVal === 'Continue Mediation' && isMediation && !hasNextSession && sessionIndex < 3) {
        const nextDate = document.getElementById('minNextHearingDate')?.value?.trim();
        const nextTime = document.getElementById('minNextHearingTime')?.value?.trim();
        const nextVenue = document.getElementById('minNextHearingVenue')?.value?.trim() || 'Lupon Court Room';
        if (nextDate && nextTime) {
            payload.book_next_hearing = 1;
            payload.next_hearing_date = nextDate;
            payload.next_hearing_time = nextTime;
            payload.next_hearing_venue = nextVenue;
            payload.next_hearing_presider_id = session.presiding_officer_id || null;
        }
    }

    try {
        const res = await fetch(getStageApiUrl('hearings/minutes.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            await loadStageData();
            if (json.next_hearing_booked && json.next_hearing) {
                const seqLabel = json.next_hearing?.sequence === 2 ? '2nd' : (json.next_hearing?.sequence === 3 ? '3rd' : 'Next');
                if (json.next_hearing?.already_existed) {
                    notifyUser(`Session minutes ${status === 'Finalized' ? 'finalized' : 'saved'} successfully. (${seqLabel} Mediation was already scheduled).`, 'success');
                } else {
                    notifyUser(`Session minutes ${status === 'Finalized' ? 'finalized and locked' : 'saved'} successfully!\n${seqLabel} Mediation hearing booked for ${json.next_hearing.hearing_date} at ${json.next_hearing.venue || 'Barangay Hall'}.\n(Summons bypassed - parties notified in person).`, 'success', 'Session Minutes Saved', 6000);
                }
            } else if (json.next_hearing_error) {
                notifyUser(`Session minutes ${status === 'Finalized' ? 'finalized' : 'saved'}.\nNext mediation session could not be scheduled: ${json.next_hearing_error}`, 'warning', 'Scheduling Warning', 6000);
            } else {
                if (status === 'Finalized') showCloseoutFollowUp();
                else notifyUser('Session minutes draft saved successfully.', 'success');
            }
        } else {
            notifyUser(json.message || 'Unable to save session minutes.', 'error');
        }
    } catch (err) {
        console.error('saveHearingMinutes error:', err);
        notifyUser('A network error occurred while saving minutes.', 'error');
    } finally {
        if (draftBtn) draftBtn.disabled = false;
        if (finalizeBtn) finalizeBtn.disabled = false;
    }
}
window.saveHearingMinutes = saveHearingMinutes;
window.saveInlineMinutes = saveHearingMinutes;
window.saveMinutes = saveHearingMinutes;

function showCloseoutFollowUp() {
    const data = stageWorkspaceState.stageData || {};
    const stage = stageWorkspaceState.mode === 'Conciliation' ? data.conciliation : data.mediation;
    const actions = stage?.documents_and_actions || {};
    let text = 'Session minutes were finalized. Review the next required action above.';
    let action = '';
    if (stageWorkspaceState.mode === 'Mediation' && actions.can_proceed_to_pangkat) {
        text = 'Session minutes were finalized. The case is ready for an authorized Pangkat referral.';
        action = '<button type="button" class="btn-create" onclick="openReferPangkatModal()">Constitute Pangkat &rarr;</button>';
    } else if (stageWorkspaceState.mode === 'Mediation' && actions.can_book_next_mediation) {
        text = 'Session minutes were finalized. The next required step is to book the next Mediation.';
        action = '<button type="button" class="btn-create" onclick="openScheduleModal()">Book next Mediation &rarr;</button>';
    } else if (stageWorkspaceState.mode === 'Conciliation' && actions.can_issue_cfa) {
        text = 'Session minutes were finalized. Conciliation is eligible for a Certificate to File Action.';
        action = `<a class="btn-create" href="../documents/cfa.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}">Issue CFA &rarr;</a>`;
    }
    document.getElementById('wsCloseoutFollowUp')?.remove();
    const box = document.createElement('div');
    box.id = 'wsCloseoutFollowUp';
    box.style.cssText = 'position:fixed; right:24px; bottom:24px; z-index:1000; max-width:380px; padding:16px; background:#fff; border:1px solid #86efac; border-radius:10px; box-shadow:0 12px 30px rgba(15,23,42,.18); color:#14532d;';
    box.innerHTML = `<strong style="display:block; margin-bottom:6px;">Session closeout complete</strong><span style="display:block; font-size:.86rem; line-height:1.45; margin-bottom:12px;">${escapeHtml(text)}</span><div style="display:flex; gap:8px; align-items:center;">${action}<button type="button" class="btn-secondary" onclick="this.closest('#wsCloseoutFollowUp').remove()">Done</button></div>`;
    document.body.appendChild(box);
}

/**
 * Render Outcome & Progression Section
 */
function renderOutcomeAndReferralSection(data, stage) {
    const outcomeBox = document.getElementById('wsOutcomeActionBox');
    if (!outcomeBox) return;

    const mode = stageWorkspaceState.mode;
    const outcome = stage.outcome || 'Pending';
    const canRefer = stage.documents_and_actions?.can_proceed_to_pangkat;

    if (outcome === 'Settled' || stage.outcome === 'Settled' || stage.stage_status === 'Settled') {
        outcomeBox.innerHTML = `
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; color: #166534; margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 1rem; color: #14532d; font-weight: 700;">
                            Amicable Settlement Reached (Kasunduan)
                        </h4>
                        <p style="margin: 0; font-size: 0.85rem; color: #166534;">
                            The parties reached an amicable settlement. Formal settlement terms are active and enforceable.
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openKPFormPreview(${Number(stageWorkspaceState.caseId)}, 'KP Form 16')" style="font-size: 0.82rem; padding: 6px 14px; background: #fff; border-color: #86efac; color: #166534; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            Preview / Print KP Form 16
                        </button>
                        <a href="../settlements/settlements.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}" class="btn-create" style="font-size: 0.82rem; padding: 6px 14px; text-decoration: none;">
                            View Amicable Settlement &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Court & DILG Transmittal Module (RA 7160 Sec. 411) -->
            <div id="wsTransmittalCardContainer">
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; color: #64748b; font-size: 0.85rem;">
                    Loading Transmittal &amp; Repudiation status...
                </div>
            </div>
        `;

        loadTransmittalCard(stageWorkspaceState.caseId);
    } else if (mode === 'Mediation') {
        if (stage.stage_status === 'Referred to Pangkat') {
            outcomeBox.innerHTML = `
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; color: #166534;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h4 style="margin: 0 0 4px; font-size: 1rem; color: #14532d; font-weight: 700;">
                                Case Referred to Pangkat ng Tagapagkasundo
                            </h4>
                            <p style="margin: 0; font-size: 0.84rem; color: #166534;">
                                Mediation concluded. KP Form 10 issued. Case is active in Pangkat Conciliation.
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <a href="pangkat-workspace.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}" class="btn-secondary" style="font-size: 0.82rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                                Open Pangkat Conciliation &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            `;
        } else {
            const completedCount = Number(stage.completed_mediation_count || 0);
            const canBookNext = Boolean(stage.documents_and_actions?.can_book_next_mediation);
            const nextSessionIndex = Math.min(3, completedCount + 1);
            const pangkatLockReason = stage.documents_and_actions?.pangkat_lock_reason || 'Barangay Tumana policy requires 2 mediation sessions before elevation.';

            outcomeBox.innerHTML = `
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h4 style="margin: 0 0 4px; font-size: 0.95rem; color: #0f172a; font-weight: 700;">
                                Mediation Stage Actions &amp; Progression
                            </h4>
                            <p style="margin: 0; font-size: 0.82rem; color: #64748b;">
                                Progress: <strong>${escapeHtml(completedCount)} of 2 Mediation Sessions Completed</strong> · Current Outcome: <strong>${escapeHtml(outcome)}</strong>
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            ${canBookNext ? `
                                <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #2563eb; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                    Book 2nd Mediation Hearing &rarr;
                                </button>
                            ` : ''}
                            <button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()" style="font-size: 0.82rem; padding: 6px 12px; cursor: pointer;">
                                Record Mediation Outcome &rarr;
                            </button>
                            ${canRefer ? `
                                <button type="button" class="btn-create" onclick="openReferPangkatModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #0369a1; cursor: pointer;">
                                    Refer to Pangkat (KP Form 10) &rarr;
                                </button>
                            ` : `
                                <button type="button" class="btn-secondary" disabled title="${escapeHtml(pangkatLockReason)}" style="font-size: 0.82rem; padding: 6px 12px; opacity: 0.55; cursor: not-allowed;">
                                    Refer to Pangkat
                                </button>
                            `}
                            <a href="../cases/case-list.php?assign_case_id=${encodeURIComponent(stageWorkspaceState.caseId)}#caseAssignments" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                Cases &amp; Assignments &rarr;
                            </a>
                        </div>
                    </div>
                    ${!canRefer && pangkatLockReason ? `
                        <div style="font-size: 0.77rem; color: #64748b; margin-top: 10px; border-top: 1px dashed #e2e8f0; padding-top: 8px;">
                            <strong>Why Pangkat referral is locked:</strong> ${escapeHtml(pangkatLockReason)}
                        </div>
                    ` : ''}
                </div>
            `;
        }
    } else if (mode === 'Conciliation') {
        const canCfa = stage.documents_and_actions?.can_issue_cfa;
        outcomeBox.innerHTML = `
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 0.95rem; color: #0f172a; font-weight: 700;">
                            Conciliation Outcome &amp; Case Disposition
                        </h4>
                        <p style="margin: 0; font-size: 0.82rem; color: #64748b;">
                            Current Outcome: <strong>${escapeHtml(outcome)}</strong>. Record conciliation result or initiate Certificate to File Action (CFA).
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()" style="font-size: 0.82rem; padding: 6px 12px;">
                            Record Outcome &rarr;
                        </button>
                        <a href="../documents/cfa.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 12px; text-decoration: none; ${!canCfa ? 'opacity: 0.5; pointer-events: none;' : ''}">
                            Issue CFA (KP Form 20) &rarr;
                        </a>
                    </div>
                </div>
            </div>
        `;
    } else if (mode === 'Arbitration') {
        outcomeBox.innerHTML = `
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 0.95rem; color: #0f172a; font-weight: 700;">
                            Arbitration Outcome &amp; Binding Award
                        </h4>
                        <p style="margin: 0; font-size: 0.82rem; color: #64748b;">
                            Current Outcome: <strong>${escapeHtml(outcome)}</strong>. Record binding arbitration determination or render arbitration award.
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()" style="font-size: 0.82rem; padding: 6px 12px;">
                            Record Outcome &rarr;
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Schedule Session Modal
 * -----------------------------------------------------------------------------
 */
let currentWsWindowMeta = null;
let wsConflictCheckDebounceTimer = null;

async function openScheduleModal(hearingId = null) {
    const modal = document.getElementById('wsScheduleModal');
    if (!modal) {
        const urlParams = new URLSearchParams(window.location.search);
        const caseId = stageWorkspaceState.caseId || urlParams.get('case_id');
        const hId = hearingId || stageWorkspaceState.activeSessionHearingId || urlParams.get('hearing_id');
        let url = `../hearings/schedules.php?case_id=${encodeURIComponent(caseId || '')}`;
        if (hId) url += `&hearing_id=${encodeURIComponent(hId)}`;
        window.location.href = url;
        return;
    }

    modal.style.display = 'flex';

    // Title / Subtitle customization
    const titleEl = document.getElementById('wsSchedModalTitle');
    const subtitleEl = document.getElementById('wsSchedModalSubtitle');
    const dateLabelEl = document.getElementById('schedDateLabel');
    const timeLabelEl = document.getElementById('schedTimeLabel');
    const conflictAlert = document.getElementById('schedScheduleConflictAlert');
    const submitBtn = document.getElementById('submitSchedBtn');
    const dateInput = document.getElementById('schedDate');
    const timeInput = document.getElementById('schedTime');

    if (conflictAlert) {
        conflictAlert.textContent = '';
        conflictAlert.style.display = 'none';
    }
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.title = '';
    }

    const isConciliation = (stageWorkspaceState.mode === 'Conciliation');
    if (stageWorkspaceState.mode === 'Mediation') {
        const activeHearingId = stageWorkspaceState.activeSessionHearingId;
        const activeSession = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(activeHearingId)) || {};
        const curSeq = Number(activeSession.session_index || 1);
        const nextSeq = curSeq + 1;
        const nextSeqOrdinal = nextSeq === 2 ? '2nd' : (nextSeq === 3 ? '3rd' : `${nextSeq}th`);

        if (titleEl) titleEl.textContent = `Schedule ${nextSeqOrdinal} Mediation Session`;
        if (dateLabelEl) dateLabelEl.textContent = `${nextSeqOrdinal} Mediation Date`;
        if (timeLabelEl) timeLabelEl.textContent = `${nextSeqOrdinal} Mediation Time`;
        if (subtitleEl) {
            subtitleEl.innerHTML = `Enter the date and time to schedule the ${nextSeqOrdinal} mediation session immediately. <strong>RA 7160 Sec. 410 Notice Exemption:</strong> Because both parties are present in this session and notified, prior personal notice is legally recognized and no redundant summons (KP Form 9) is generated.`;
        }
        const deckTitle = document.getElementById('schedVisualDeckTitle');
        if (deckTitle) deckTitle.textContent = 'AVAILABLE MEDIATION DAYS (MAX 8 HEARINGS/DAY):';
        const schedDayTitle = document.getElementById('schedDaySchedulesTitle');
        if (schedDayTitle) schedDayTitle.textContent = 'Scheduled Mediations on this Date:';
        if (submitBtn) submitBtn.textContent = `Schedule ${nextSeqOrdinal} Mediation`;
    } else if (isConciliation) {
        const conciliationCount = stageWorkspaceState.sessions ? stageWorkspaceState.sessions.length : 0;
        const nextSeq = conciliationCount + 1;
        const nextSeqOrdinal = nextSeq === 1 ? '1st' : (nextSeq === 2 ? '2nd' : (nextSeq === 3 ? '3rd' : `${nextSeq}th`));

        if (titleEl) titleEl.textContent = `Schedule ${nextSeqOrdinal} Conciliation Session`;
        if (dateLabelEl) dateLabelEl.textContent = `${nextSeqOrdinal} Conciliation Date`;
        if (timeLabelEl) timeLabelEl.textContent = `${nextSeqOrdinal} Conciliation Time`;
        if (subtitleEl) {
            subtitleEl.innerHTML = `In accordance with KP rules, scheduling a conciliation hearing registers the session in the central calendar and generates <strong>KP Form 11 (Notice of Hearing before the Pangkat)</strong>.`;
        }
        const deckTitle = document.getElementById('schedVisualDeckTitle');
        if (deckTitle) deckTitle.textContent = 'AVAILABLE CONCILIATION DAYS (MON, TUE AM, WED, THU, FRI):';
        const schedDayTitle = document.getElementById('schedDaySchedulesTitle');
        if (schedDayTitle) schedDayTitle.textContent = 'Scheduled Conciliations on this Date:';
        const timeHint = document.getElementById('schedTimeHint');
        if (timeHint) {
            timeHint.textContent = 'Regular Conciliation hours: Mon/Wed/Thu/Fri 8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM; Tuesday 8:00 AM – 12:00 PM (Morning only). Standard duration is 1 hour.';
        }
        if (submitBtn) submitBtn.textContent = `Schedule ${nextSeqOrdinal} Conciliation`;
    }

    // Set up listeners for date/time
    if (dateInput) {
        dateInput.onchange = () => {
            if (currentWsWindowMeta) {
                renderWorkspaceVisualDatePicker(currentWsWindowMeta, dateInput.value);
            }
            checkWorkspaceScheduleConflict();
        };
    }
    if (timeInput) {
        timeInput.oninput = debouncedCheckWorkspaceScheduleConflict;
        timeInput.onchange = checkWorkspaceScheduleConflict;
    }

    // Load statutory window and available slots
    const caseId = stageWorkspaceState.caseId;
    const complaintId = document.getElementById('schedComplaintId')?.value || '';
    try {
        const hearingTypeParam = (stageWorkspaceState.mode === 'Conciliation') ? 'Conciliation' : 'Mediation';
        const res = await fetch(getStageApiUrl(`summons/check-schedule.php?case_id=${encodeURIComponent(caseId)}&complaint_id=${encodeURIComponent(complaintId)}&hearing_type=${encodeURIComponent(hearingTypeParam)}&get_window=1`));
        const json = await res.json();
        if (json.window) {
            currentWsWindowMeta = json.window;
            let targetDate = dateInput ? dateInput.value : '';
            if (!targetDate && json.window.daily_slots) {
                // Pick first valid open working day
                const firstOpen = Object.values(json.window.daily_slots).find(d => !d.is_past && !d.is_outside_window && !d.is_fully_booked && !d.is_weekend && !d.is_non_working_day);
                if (firstOpen) {
                    targetDate = firstOpen.date;
                    if (dateInput) dateInput.value = targetDate;
                }
            }
            renderWorkspaceVisualDatePicker(json.window, targetDate);
            if (targetDate) {
                checkWorkspaceScheduleConflict();
            }
        } else {
            const gridEl = document.getElementById('schedVisualDayGrid');
            if (gridEl) {
                gridEl.innerHTML = `<div style="color: #dc2626; font-size: 0.78rem; grid-column: 1 / -1; text-align: center; padding: 10px;">${escapeHtml(json.message || 'Unable to load scheduling window.')}</div>`;
            }
        }
    } catch (e) {
        console.warn('Failed to load window metadata for workspace scheduler:', e);
        const gridEl = document.getElementById('schedVisualDayGrid');
        if (gridEl) {
            gridEl.innerHTML = `<div style="color: #dc2626; font-size: 0.78rem; grid-column: 1 / -1; text-align: center; padding: 10px;">Could not connect to schedule server. Please check connection.</div>`;
        }
    }
}
window.openScheduleModal = openScheduleModal;

function closeScheduleModal() {
    const modal = document.getElementById('wsScheduleModal');
    if (modal) modal.style.display = 'none';
}
window.closeScheduleModal = closeScheduleModal;

function renderWorkspaceVisualDatePicker(windowMeta, selectedDate) {
    const gridEl = document.getElementById('schedVisualDayGrid');
    const noticeEl = document.getElementById('schedVisualDateStatusNotice');
    const badgeEl = document.getElementById('schedWindowStatutoryBadge');
    if (!gridEl || !windowMeta || !windowMeta.daily_slots) return;

    if (badgeEl) { badgeEl.style.display = 'none'; }

    const days = Object.values(windowMeta.daily_slots);
    if (!days.length) {
        gridEl.innerHTML = '<div style="color: #64748b; font-size: 0.78rem; grid-column: 1 / -1; text-align: center;">No date slots available in window.</div>';
        return;
    }

    gridEl.innerHTML = days.map(d => {
        const isSelected = (d.date === selectedDate);
        let badgeText = `${d.available} open`;
        let badgeBg = '#dcfce7';
        let badgeColor = '#15803d';
        let cardStyle = 'border: 1px solid #cbd5e1; background: #ffffff; color: #1e293b; cursor: pointer;';
        let disabledAttr = '';
        let tooltip = `${d.date}: ${d.available} of ${d.max} slots available.`;

        const isConciliationMode = (stageWorkspaceState.mode === 'Conciliation' || windowMeta.hearing_type === 'Conciliation');
        if (d.is_past) {
            badgeText = 'Past';
            badgeBg = '#f1f5f9';
            badgeColor = '#94a3b8';
            cardStyle = 'border: 1px solid #e2e8f0; background: #f8fafc; color: #94a3b8; cursor: not-allowed; opacity: 0.5;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Past date cannot be scheduled.`;
        } else if (d.is_weekend || d.is_non_working_day) {
            badgeText = d.is_non_working_day ? 'Holiday' : 'Weekend';
            badgeBg = '#f1f5f9';
            badgeColor = '#64748b';
            cardStyle = 'border: 1px dashed #cbd5e1; background: #f8fafc; color: #64748b; cursor: not-allowed; opacity: 0.55;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: ${d.is_non_working_day ? 'Official holiday' : 'Weekend'}. Closed for ${isConciliationMode ? 'conciliation' : 'mediation'}.`;
        } else if (d.is_fully_booked) {
            badgeText = `${d.booked}/${d.max} FULL`;
            badgeBg = '#fee2e2';
            badgeColor = '#b91c1c';
            cardStyle = 'border: 1px solid #fecaca; background: #fff1f2; color: #881337; cursor: not-allowed; opacity: 0.65;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Fully booked! All ${d.max} sessions occupied for this date.`;
        } else if (isConciliationMode && (d.day_name === 'Tue' || d.is_tuesday_morning_only)) {
            badgeText = `${d.available} AM`;
            badgeBg = '#e0f2fe';
            badgeColor = '#0369a1';
            cardStyle = 'border: 1px solid #7dd3fc; background: #f0f9ff; color: #0c4a6e; cursor: pointer;';
            tooltip = `${d.date}: Tuesday Morning Only (8:00 AM – 12:00 PM, ${d.available} of 4 slots open). Tuesday afternoons reserved for Formal Mediation.`;
        }

        if (isSelected) {
            cardStyle = 'border: 2px solid #2563eb; background: #eff6ff; color: #1e3a8a; box-shadow: 0 0 0 2px rgba(37,99,235,0.25); font-weight: 700; cursor: pointer;';
            badgeBg = '#2563eb';
            badgeColor = '#ffffff';
        }

        return `
            <div class="ws-day-card ${isSelected ? 'active' : ''}"
                 onclick="${disabledAttr ? '' : `selectWorkspaceVisualDate('${d.date}')`}"
                 title="${escapeHtml(tooltip)}"
                 style="border-radius: 6px; padding: 6px 4px; text-align: center; transition: all 0.15s; user-select: none; ${cardStyle}">
                <div style="font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em;">
                    ${escapeHtml(d.day_name)}
                </div>
                <div style="font-size: 0.85rem; font-weight: 800; margin: 2px 0;">
                    ${d.day_num} ${escapeHtml(d.month_name)}
                </div>
                <div style="font-size: 0.65rem; font-weight: 700; padding: 1px 4px; border-radius: 4px; background: ${badgeBg}; color: ${badgeColor}; display: inline-block;">
                    ${badgeText}
                </div>
            </div>
        `;
    }).join('');

    if (noticeEl) {
        if (selectedDate && windowMeta.daily_slots[selectedDate]) {
            const selectedDayData = windowMeta.daily_slots[selectedDate];
            if (selectedDayData.is_fully_booked) {
                noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">Selected date is fully booked (8 of 8 daily mediation sessions).</span>`;
            } else {
                noticeEl.innerHTML = `<span style="color: #15803d; font-weight: 600;">Date selected: ${selectedDayData.available} slots remaining.</span>`;
            }
        } else {
            noticeEl.textContent = 'Click any available day card above to select. Fully booked dates are disabled.';
        }
    }
}
window.renderWorkspaceVisualDatePicker = renderWorkspaceVisualDatePicker;

function selectWorkspaceVisualDate(dateStr) {
    const dateInput = document.getElementById('schedDate');
    if (!dateInput) return;
    dateInput.value = dateStr;

    // Apply Tuesday morning time restrictions if in Conciliation mode
    const isConciliationMode = (stageWorkspaceState.mode === 'Conciliation' || currentWsWindowMeta?.hearing_type === 'Conciliation');
    const timeInput = document.getElementById('schedTime');
    const timeHint = document.getElementById('schedTimeHint');
    if (isConciliationMode && dateStr) {
        const dObj = new Date(dateStr + 'T00:00:00');
        const isTue = (dObj.getDay() === 2);
        if (isTue) {
            if (timeInput) {
                timeInput.min = '08:00';
                timeInput.max = '11:30';
                if (timeInput.value >= '12:00') {
                    timeInput.value = '09:00';
                }
            }
            if (timeHint) {
                timeHint.innerHTML = '<span style="color: #0369a1; font-weight: 600;">ℹ️ Tuesday Conciliation is held in the MORNING ONLY (8:00 AM – 12:00 PM). Tuesday afternoons are exclusively reserved for Formal Mediation.</span>';
            }
        } else {
            if (timeInput) {
                timeInput.min = '08:00';
                timeInput.max = '16:30';
            }
            if (timeHint) {
                timeHint.textContent = 'Regular Conciliation hours: Mon/Wed/Thu/Fri 8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM (12–1 PM lunch break closed). Standard duration is 1 hour.';
            }
        }
    }

    if (currentWsWindowMeta) {
        renderWorkspaceVisualDatePicker(currentWsWindowMeta, dateStr);
    }
    checkWorkspaceScheduleConflict();
}
window.selectWorkspaceVisualDate = selectWorkspaceVisualDate;

async function checkWorkspaceScheduleConflict() {
    const dateInput = document.getElementById('schedDate');
    const timeInput = document.getElementById('schedTime');
    const venueInput = document.getElementById('schedVenue');
    const caseIdInput = document.getElementById('schedCaseId');
    const conflictAlert = document.getElementById('schedScheduleConflictAlert');
    const submitBtn = document.getElementById('submitSchedBtn');
    const schedulesWrap = document.getElementById('schedDaySchedulesWrap');
    const slotsCount = document.getElementById('schedDaySlotsCount');
    const slotsList = document.getElementById('schedDaySlotsList');

    if (!dateInput) return;

    const dateVal = dateInput.value;
    const timeVal = timeInput ? timeInput.value : '';
    const venueVal = venueInput ? venueInput.value : 'Lupon Court Room';
    const caseIdVal = caseIdInput ? caseIdInput.value : (stageWorkspaceState.caseId || '');

    if (!dateVal) {
        if (schedulesWrap) schedulesWrap.style.display = 'none';
        return;
    }

    const typeVal = document.getElementById('schedHearingType')?.value || (stageWorkspaceState.mode === 'Conciliation' ? 'Conciliation' : 'Mediation');
    const params = new URLSearchParams();
    params.set('date', dateVal);
    if (timeVal) params.set('time', timeVal);
    if (venueVal) params.set('venue', venueVal);
    if (caseIdVal) params.set('case_id', caseIdVal);
    params.set('hearing_type', typeVal);
    params.set('get_window', '1');

    try {
        const response = await fetch(getStageApiUrl(`summons/check-schedule.php?${params.toString()}`));
        const result = await response.json();

        if (result.window) {
            currentWsWindowMeta = result.window;
            renderWorkspaceVisualDatePicker(result.window, dateVal);
        }

        if (!response.ok || !result.success) {
            if (conflictAlert && result.message) {
                conflictAlert.textContent = `Notice: ${result.message}`;
                conflictAlert.style.display = 'block';
                if (submitBtn) submitBtn.disabled = true;
            }
            return;
        }

        // 15-working-day statutory window check removed

        // 2. Capacity limit check: Max 8 sessions per day
        const bookedCount = result.total_booked ?? (result.scheduled_mediations ? result.scheduled_mediations.length : 0);
        const maxSlots = result.max_slots || 8;
        const availSlots = result.available_slots ?? Math.max(0, maxSlots - bookedCount);

        if (result.is_fully_booked || bookedCount >= maxSlots) {
            if (conflictAlert) {
                conflictAlert.textContent = `Date Fully Booked: All ${maxSlots} daily mediation slots on this date are occupied. Please select another date.`;
                conflictAlert.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = 'Cannot submit: Maximum daily capacity of 8 mediation sessions reached for this date.';
            }
            return;
        }

        // 3. Render day's capacity & scheduled mediations breakdown
        if (schedulesWrap && slotsList && slotsCount) {
            schedulesWrap.style.display = 'block';
            slotsCount.textContent = `${availSlots} of ${maxSlots} slots available`;
            slotsCount.style.background = availSlots > 2 ? '#dcfce7' : (availSlots > 0 ? '#fef3c7' : '#fee2e2');
            slotsCount.style.color = availSlots > 2 ? '#15803d' : (availSlots > 0 ? '#92400e' : '#b91c1c');

            const scheduled = result.scheduled_mediations || [];
            if (scheduled.length === 0) {
                slotsList.innerHTML = `<span style="color: #166534; font-weight: 500;">All 8 session slots available on this date (${dateVal}).</span>`;
            } else {
                slotsList.innerHTML = `
                    <div style="margin-bottom: 6px; font-weight: 600; color: #475569;">${availSlots} of ${maxSlots} slots available for this date:</div>
                ` + scheduled.map(s => `
                    <div style="margin-top: 4px; padding: 4px 8px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <div>
                            <strong style="color: #1e293b;">${escapeHtml(s.start_time || '')} – ${escapeHtml(s.end_time || '')}</strong>
                            <span style="color: #64748b; font-size: 0.8rem; margin-left: 6px;">Case ${escapeHtml(s.case_number || 'N/A')}</span>
                        </div>
                        <span style="font-size: 0.72rem; color: #475569; background: #f1f5f9; padding: 1px 6px; border-radius: 4px;">${escapeHtml(s.venue || 'Barangay Hall')}</span>
                    </div>
                `).join('');
            }
        }

        // 4. Time conflict check
        if (result.has_conflict && result.conflict) {
            const conflictMsg = result.conflict.message || 'The selected time overlaps with an existing scheduled mediation.';
            if (conflictAlert) {
                conflictAlert.textContent = `Schedule Conflict: ${conflictMsg}`;
                conflictAlert.style.display = 'block';
            }
            if (timeInput) {
                timeInput.setCustomValidity('Selected time overlaps with another scheduled mediation on this day.');
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = 'Cannot submit: Schedule conflict with an existing mediation on this day.';
            }
        } else {
            if (conflictAlert) {
                conflictAlert.textContent = '';
                conflictAlert.style.display = 'none';
            }
            if (timeInput) {
                timeInput.setCustomValidity('');
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.title = '';
            }
        }
    } catch (err) {
        console.warn('Failed to check workspace mediation schedule:', err);
    }
}
window.checkWorkspaceScheduleConflict = checkWorkspaceScheduleConflict;

function debouncedCheckWorkspaceScheduleConflict() {
    clearTimeout(wsConflictCheckDebounceTimer);
    wsConflictCheckDebounceTimer = setTimeout(checkWorkspaceScheduleConflict, 200);
}
window.debouncedCheckWorkspaceScheduleConflict = debouncedCheckWorkspaceScheduleConflict;

async function submitSchedule(e) {
    e.preventDefault();
    const btn = document.getElementById('submitSchedBtn');
    if (btn) btn.disabled = true;

    const dateVal = document.getElementById('schedDate').value;
    const timeVal = document.getElementById('schedTime').value;
    const venueVal = document.getElementById('schedVenue').value;
    const remarksVal = document.getElementById('schedRemarks').value;
    const pastDateExceptionReason = document.getElementById('schedPastDateExceptionReason')?.value.trim() || '';
    const typeVal = document.getElementById('schedHearingType').value || (stageWorkspaceState.mode === 'Mediation' ? 'Mediation' : 'Conciliation');

    if (!dateVal || !timeVal) {
        notifyUser('Please specify the hearing date and select an available time slot.', 'warning');
        if (btn) btn.disabled = false;
        return;
    }

    const d = new Date(dateVal + 'T' + timeVal + ':00');
    const day = d.getDay();
    const hour = d.getHours();

    if (typeVal === 'Mediation') {
        if (day === 0 || day === 6) {
            notifyUser('Mediation hearings can only be scheduled on working days (Monday to Friday).', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
        if ((hour < 8 || hour >= 17) || (hour >= 12 && hour < 13)) {
            notifyUser('Mediation hearings must be during regular office hours (8:00 AM – 5:00 PM, excluding 12–1 PM lunch break).', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
    } else if (typeVal === 'Conciliation') {
        if (day === 0 || day === 6) {
            notifyUser('Conciliation hearings cannot be scheduled on weekends.', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
        if (day === 2 && hour >= 12) {
            notifyUser('Conciliation on Tuesdays is permitted only in the morning (8:00 AM – 12:00 PM). Tuesday afternoons are reserved for Formal Mediation.', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
        if ((hour < 8 || hour >= 17) || (hour >= 12 && hour < 13)) {
            notifyUser('Conciliation hearings must be during regular office hours (excluding 12–1 PM lunch break).', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
    }

    const payload = new FormData();
    payload.append('case_id', stageWorkspaceState.caseId);
    payload.append('hearing_type', typeVal);
    payload.append('hearing_date', `${dateVal} ${timeVal}:00`);
    payload.append('duration_minutes', '60');
    payload.append('venue', venueVal || 'Lupon Court Room');
    payload.append('remarks', remarksVal);
    payload.append('schedule_reviewed', '1');
    if (pastDateExceptionReason) {
        payload.append('past_date_exception_reason', pastDateExceptionReason);
    }

    try {
        const res = await fetch(getStageApiUrl('hearings/create.php'), {
            method: 'POST',
            body: payload
        });
        const json = await res.json();
        if (json.success) {
            closeScheduleModal();
            await loadStageData();
            const schedSeq = Number(json.sequence || 0);
            if (typeof openSummonsPreviewModal === 'function' && stageWorkspaceState.mode === 'Mediation' && schedSeq <= 1) {
                openSummonsPreviewModal(stageWorkspaceState.caseId, null, json.hearing_id, true);
            } else {
                notifyUser(json.message || 'Hearing scheduled successfully! Subsequent mediation booked without re-issuing summons.', 'success');
            }
        } else if (json.requires_convening_justification) {
            const justification = prompt(
                `${json.message}\n\nPlease enter the administrative justification for scheduling past the statutory 3-day convening deadline (e.g., notice lead time, parties requested schedule, non-working days):`
            );
            if (justification && justification.trim()) {
                payload.append('convening_delay_justification', justification.trim());
                payload.append('force_schedule', '1');
                const retryRes = await fetch(getStageApiUrl('hearings/create.php'), {
                    method: 'POST',
                    body: payload
                });
                const retryJson = await retryRes.json();
                if (retryJson.success) {
                    closeScheduleModal();
                    await loadStageData();
                    const retrySeq = Number(retryJson.sequence || 0);
                    if (typeof openSummonsPreviewModal === 'function' && stageWorkspaceState.mode === 'Mediation' && retrySeq <= 1) {
                        openSummonsPreviewModal(stageWorkspaceState.caseId, null, retryJson.hearing_id, true);
                    } else {
                        notifyUser(retryJson.message || 'Hearing scheduled successfully with recorded administrative justification.', 'success');
                    }
                    return;
                } else {
                    notifyUser(retryJson.message || 'Unable to schedule hearing.', 'error');
                }
            }
        } else if (json.alternative_slots && json.alternative_slots.length) {
            let altMsg = `${json.message}\n\nSuggested Available Alternative Slots:\n`;
            json.alternative_slots.forEach((slot, idx) => {
                altMsg += `${idx + 1}. ${slot.formatted_date} (${slot.start_time} - ${slot.end_time})\n`;
            });
            altMsg += `\nWould you like to schedule slot #1 (${json.alternative_slots[0].formatted_date} ${json.alternative_slots[0].start_time})?`;
            if (confirm(altMsg)) {
                const chosen = json.alternative_slots[0];
                const dt = chosen.datetime.split(' ');
                document.getElementById('schedDate').value = dt[0];
                document.getElementById('schedTime').value = dt[1].substring(0, 5);
                submitSchedule(e);
                return;
            }
        } else if (json.message && (json.message.includes('Maximum 2 mediation attempts') || json.message.includes('Two mediation attempts') || json.message.includes('more than 2 mediation') || json.message.includes('Maximum 3 mediation attempts') || json.message.includes('more than 3 mediation'))) {
            const excReason = prompt(`${json.message}\n\nIf an authorized rule or exception explicitly permits an additional mediation session, please enter the exception justification:`);
            if (excReason && excReason.trim()) {
                payload.append('authorized_exception', '1');
                payload.append('attempt_exception_reason', excReason.trim());
                const retryRes = await fetch(getStageApiUrl('hearings/create.php'), {
                    method: 'POST',
                    body: payload
                });
                const retryJson = await retryRes.json();
                if (retryJson.success) {
                    closeScheduleModal();
                    await loadStageData();
                    notifyUser(retryJson.message || 'Hearing scheduled successfully with recorded exception.', 'success');
                    return;
                } else {
                    notifyUser(retryJson.message || 'Unable to schedule hearing.', 'error');
                }
            }
        } else {
            notifyUser(json.message || 'Unable to schedule hearing session.', 'error');
        }
    } catch (err) {
        console.error('submitSchedule error:', err);
        notifyUser('A network error occurred while scheduling hearing.', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Attendance Recording & Proof of Service Verification
 * -----------------------------------------------------------------------------
 */
async function openAttendanceModal(hearingId) {
    const modal = document.getElementById('wsAttendanceModal');
    if (!modal) return;
    stageWorkspaceState.currentHearingId = hearingId;

    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    const titleEl = document.getElementById('wsAttModalTitle');
    if (titleEl) {
        titleEl.textContent = `Record Attendance: ${session ? session.session_number : `Hearing #${hearingId}`}`;
    }

    const bodyEl = document.getElementById('wsAttModalBody');
    if (!bodyEl) return;
    bodyEl.innerHTML = '<div style="text-align: center; padding: 20px;">Loading parties and service records...</div>';
    modal.style.display = 'flex';

    try {
        const res = await fetch(getStageApiUrl(`hearings/attendance.php?hearing_id=${encodeURIComponent(hearingId)}`));
        const json = await res.json();
        if (!json.success || !json.data) {
            bodyEl.innerHTML = `<div class="alert alert-danger">${escapeHtml(json.message || 'Could not load attendance details.')}</div>`;
            return;
        }

        renderAttendanceModalForm(json.data);
    } catch (err) {
        console.error('openAttendanceModal error:', err);
        bodyEl.innerHTML = '<div class="alert alert-danger">Network error loading attendance.</div>';
    }
}

function closeAttendanceModal() {
    const modal = document.getElementById('wsAttendanceModal');
    if (modal) modal.style.display = 'none';
}

function renderAttendanceModalForm(data) {
    const bodyEl = document.getElementById('wsAttModalBody');
    if (!bodyEl) return;

    const hearing = data.hearing || data;
    const parties = data.parties || [];
    const luponMembers = data.lupon_members || stageWorkspaceState.luponMembers || [];
    const partyFormRules = hearing.party_form_rules || data.party_form_rules || { complainant: {}, respondent: {} };
    const caseId = Number(stageWorkspaceState.caseId);
    const hearingId = Number(hearing.hearing_id || stageWorkspaceState.currentHearingId);

    if (!parties.length) {
        bodyEl.innerHTML = '<div class="empty-detail-state">No parties registered for this case.</div>';
        return;
    }

    // 1. Presider Attendance & Verification Box
    const presiderName = hearing.presiding_officer_name || 'Punong Barangay / Lupon Chairman';
    const presiderRole = hearing.presiding_officer_role || 'Punong Barangay';
    const substituteId = hearing.substitute_presider_id;
    const substituteReason = hearing.substitute_reason || '';
    const hasConsent = Boolean(hearing.parties_consent_to_substitute);
    const presiderStatus = (substituteId || hearing.presider_attendance_status === 'Absent') ? 'Absent' : 'Present';

    let luponOpts = luponMembers.map(m => {
        const mId = Number(m.user_id || m.member_id);
        const mName = m.full_name || `${m.last_name || ''}, ${m.first_name || ''} ${m.middle_name || ''}`.trim();
        const isSelected = substituteId && Number(substituteId) === mId;
        return `<option value="${mId}" ${isSelected ? 'selected' : ''}>${escapeHtml(mName)} (${escapeHtml(m.role_name || 'Lupon Member')})</option>`;
    }).join('');

    const presiderHtml = `
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                <div>
                    <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.04em; display: block;">Presiding Officer (Hearing Presider)</span>
                    <strong style="font-size: 0.95rem; color: #0f172a;">${escapeHtml(presiderName)}</strong>
                    <span style="font-size: 0.74rem; color: #64748b; margin-left: 6px;">(${escapeHtml(presiderRole)})</span>
                </div>
                <div>
                    <select id="wsPresiderStatus" onchange="togglePresiderSubstituteSection(this.value)" style="padding: 6px 10px; font-size: 0.84rem; font-weight: 600; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff;">
                        <option value="Present" ${presiderStatus !== 'Absent' ? 'selected' : ''}>Present</option>
                        <option value="Absent" ${presiderStatus === 'Absent' ? 'selected' : ''}>Absent / Unavailable</option>
                    </select>
                </div>
            </div>

            <!-- Upfront 2-Choice Decision Section (Required when Presider is Absent) -->
            <div id="wsPresiderAbsenceDecisionBox" style="${presiderStatus === 'Absent' ? '' : 'display: none;'} margin-top: 12px; background: #fff; border: 1px solid #fed7aa; border-left: 4px solid #f97316; border-radius: 8px; padding: 14px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; flex-wrap: wrap; gap: 6px;">
                    <span style="font-size: 0.85rem; font-weight: 700; color: #9a3412; display: inline-flex; align-items: center; gap: 6px;">
                        Presider Absence Workflow (RA 7160 Sec. 410)
                    </span>
                    <span style="font-size: 0.72rem; background: #ffedd5; color: #9a3412; font-weight: 600; padding: 2px 8px; border-radius: 4px;">
                        2 Available Remedies
                    </span>
                </div>
                <p style="font-size: 0.78rem; color: #475569; margin: 0 0 12px; line-height: 1.45;">
                    The scheduled Presiding Officer is absent or unavailable. Under Katarungang Pambarangay rules, select either <strong>Option A (Designate Substitute Presider)</strong> with mutual party consent to proceed today, or <strong>Option B (Emergency Reschedule)</strong> to set a new hearing date and auto-issue KP Forms 8 &amp; 9.
                </p>

                <!-- 2-Choice Card Selectors -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <label id="wsChoiceCardSubstitute" style="display: block; border: 2px solid #2563eb; background: #eff6ff; border-radius: 6px; padding: 10px; cursor: pointer; transition: all 0.2s;">
                        <div style="display: flex; align-items: flex-start; gap: 8px;">
                            <input type="radio" name="presider_absence_choice" id="wsAbsenceChoiceSubstitute" value="substitute" checked onchange="handlePresiderAbsenceChoiceChange('substitute')" style="margin-top: 2px; accent-color: #2563eb;">
                            <div>
                                <strong style="display: block; font-size: 0.82rem; color: #1e3a8a;">Option A: Substitute Presider</strong>
                                <span style="font-size: 0.72rem; color: #64748b; display: block; margin-top: 2px;">Both parties agree to proceed today under an authorized Lupon substitute.</span>
                            </div>
                        </div>
                    </label>
                    <label id="wsChoiceCardReschedule" style="display: block; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; padding: 10px; cursor: pointer; transition: all 0.2s;">
                        <div style="display: flex; align-items: flex-start; gap: 8px;">
                            <input type="radio" name="presider_absence_choice" id="wsAbsenceChoiceReschedule" value="reschedule" onchange="handlePresiderAbsenceChoiceChange('reschedule')" style="margin-top: 2px; accent-color: #dc2626;">
                            <div>
                                <strong style="display: block; font-size: 0.82rem; color: #0f172a;">Option B: Emergency Reschedule</strong>
                                <span style="font-size: 0.72rem; color: #64748b; display: block; margin-top: 2px;">Reschedule hearing &amp; auto-issue KP Form 8 (Complainant) and KP Form 9 (Respondent).</span>
                            </div>
                        </div>
                    </label>
                </div>

                <!-- Option A Sub-panel: Designated Substitute Presider -->
                <div id="wsSubstitutePresiderWrap" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; margin-bottom: 6px;">
                    <div style="font-size: 0.78rem; font-weight: 700; color: #1e293b; margin-bottom: 8px;">
                        Designate Authorized Substitute Presider (Proceed Today)
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 600; color: #334155; display: block; margin-bottom: 4px;">
                                SELECT SUBSTITUTE PRESIDER <span style="color: #dc2626;">*</span>
                            </label>
                            <select id="wsSubstitutePresiderId" style="width: 100%; padding: 6px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff;">
                                <option value="">-- Choose Active Lupon Member --</option>
                                ${luponOpts}
                            </select>
                        </div>
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 600; color: #334155; display: block; margin-bottom: 4px;">
                                REASON FOR SUBSTITUTION <span style="color: #dc2626;">*</span>
                            </label>
                            <input type="text" id="wsSubstituteReason" value="${escapeHtml(substituteReason)}" placeholder="e.g. Official business, emergency leave..." style="width: 100%; padding: 6px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff;">
                        </div>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <label style="display: inline-flex; align-items: center; gap: 7px; font-size: 0.8rem; color: #1e293b; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" id="wsPartiesConsentCheck" ${hasConsent ? 'checked' : ''} style="width: 15px; height: 15px; accent-color: #2563eb;">
                            <span>Both parties have been informed and mutually consent to the designated substitute presider</span>
                        </label>
                    </div>
                    <div style="font-size: 0.74rem; color: #15803d; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 6px 10px; border-radius: 4px;">
                        Proceed below to verify party appearances and record minutes for today's session under this designated officer.
                    </div>
                </div>

                <!-- Option B Sub-panel: Emergency Reschedule -->
                <div id="wsEmergencyRescheduleWrap" style="display: none; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; margin-bottom: 6px;">
                    <div style="font-size: 0.78rem; font-weight: 700; color: #991b1b; margin-bottom: 6px;">
                        Emergency Reschedule Hearing (Presider Unavailability)
                    </div>
                    <p style="font-size: 0.76rem; color: #7f1d1d; margin: 0 0 10px; line-height: 1.4;">
                        Under KP rules (RA 7160), neither party is penalized. Today's hearing will be marked <strong>Office Cancelled</strong>, a new hearing will be scheduled, and legal notices will be auto-generated.
                    </p>

                    <div style="background: #fff; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px; margin-bottom: 10px;">
                        <div style="font-size: 0.74rem; font-weight: 700; color: #991b1b; text-transform: uppercase; margin-bottom: 4px;">
                            Automatic Document Issuance:
                        </div>
                        <ul style="margin: 0; padding-left: 18px; font-size: 0.76rem; color: #334155; line-height: 1.5;">
                            <li><strong>KP Form 8 (Notice of Hearing)</strong>: Issued to the <strong>Complainant</strong> with the new hearing date and reason.</li>
                            <li><strong>KP Form 9 (Summons for Rescheduled Hearing)</strong>: Issued to the <strong>Respondent</strong> for service.</li>
                        </ul>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 600; color: #334155; display: block; margin-bottom: 4px;">
                                NEW RESCHEDULED HEARING DATE &amp; TIME <span style="color: #dc2626;">*</span>
                            </label>
                            <input type="datetime-local" id="wsEmergencyRescheduleDate" value="${getSuggestedRescheduleDateTime(hearing.hearing_date)}" style="width: 100%; padding: 6px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff;">
                        </div>
                        <div>
                            <label style="font-size: 0.74rem; font-weight: 600; color: #334155; display: block; margin-bottom: 4px;">
                                OFFICIAL REASON / JUSTIFICATION <span style="color: #dc2626;">*</span>
                            </label>
                            <input type="text" id="wsEmergencyRescheduleReason" value="Presider Unavailability / Official Absence" placeholder="Enter reason..." style="width: 100%; padding: 6px; font-size: 0.82rem; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff;">
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" class="btn-primary" id="btnExecuteReschedule" onclick="executeEmergencyReschedule(${hearingId}, ${caseId})" style="background: #dc2626; border-color: #dc2626; color: #fff; font-size: 0.82rem; padding: 7px 14px; border-radius: 6px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            Confirm Emergency Reschedule &amp; Issue KP Forms 8 &amp; 9
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    // 2. Party Appearance Rows
    let rowsHtml = '';
    parties.forEach(p => {
        const isWitness = p.party_type === 'Witness';
        const isComplainant = p.party_type === 'Complainant';
        const isRespondent = p.party_type === 'Respondent';
        const serviceStatus = p.summon_delivery_status || (p.successful_service_count > 0 ? 'Served' : 'Pending');
        const isServiceConfirmed = (serviceStatus && serviceStatus.toLowerCase().includes('served'));
        const isAbsent = (p.attendance_status === 'Absent');
        const isAbsentOrExcused = (p.attendance_status === 'Absent' || p.attendance_status === 'Excused');

        let formRulesHtml = '';
        const isSecondOrLater = Boolean(partyFormRules.is_second_or_later);
        if (isComplainant) {
            const kp21Unlocked = partyFormRules.complainant?.kp21_unlocked;
            const kp21Reason = partyFormRules.complainant?.kp21_reason || 'Requires show-cause hearing evaluation ruling non-appearance as unjustified.';
            formRulesHtml = `
                <div class="att-legal-notices-wrap" style="${isAbsent ? '' : 'display: none;'} margin-top: 10px; padding: 10px 12px; background: #fff5f5; border: 1px solid #fecaca; border-radius: 6px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
                        <span style="font-size: 0.78rem; font-weight: 700; color: #991b1b;">Notice of Hearing (Complainant Non-appearance):</span>
                        ${isSecondOrLater ? `
                            <button type="button" class="btn-secondary" onclick="openKPFormPreview(${caseId}, 'KP Form 18', ${hearingId})" style="font-size: 0.76rem; padding: 4px 10px; background: #fff; border-color: #fca5a5; color: #991b1b; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                Preview / Print KP Form 18 Notice
                            </button>
                        ` : `
                            <span style="font-size: 0.74rem; color: #64748b; font-style: italic; background: #fff; border: 1px solid #cbd5e1; padding: 3px 8px; border-radius: 4px;">
                                ℹ️ 1st Mediation: Proceed to book 2nd Mediation (no new summon needed). KP Form 18 unlocks if absent in 2nd Mediation.
                            </span>
                        `}
                    </div>
                    ${isSecondOrLater ? `
                        <div style="font-size: 0.76rem; border-top: 1px dashed #fca5a5; padding-top: 6px; margin-top: 4px;">
                            ${kp21Unlocked ? `
                                <span style="color: #15803d; font-weight: 700;">KP Form 21 (Certificate to Bar Action) Eligible:</span> Non-appearance was ruled unjustified.
                                <button type="button" class="btn-secondary" onclick="openKPFormPreview(${caseId}, 'KP Form 21', ${hearingId})" style="font-size: 0.72rem; padding: 2px 8px; margin-left: 6px;">Preview KP Form 21</button>
                            ` : `
                                <span style="color: #991b1b; font-weight: 700;">KP Form 21 (Certificate to Bar Action) Locked:</span>
                                <span style="color: #475569;">${escapeHtml(kp21Reason)}</span>
                            `}
                        </div>
                    ` : ''}
                </div>
            `;
        } else if (isRespondent) {
            const kp22Unlocked = partyFormRules.respondent?.kp22_unlocked;
            const kp22Reason = partyFormRules.respondent?.kp22_reason || 'Requires show-cause hearing evaluation ruling non-appearance as unjustified.';
            formRulesHtml = `
                <div class="att-legal-notices-wrap" style="${isAbsent ? '' : 'display: none;'} margin-top: 10px; padding: 10px 12px; background: #fff5f5; border: 1px solid #fecaca; border-radius: 6px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
                        <span style="font-size: 0.78rem; font-weight: 700; color: #991b1b;">Notice of Hearing (Respondent Non-appearance):</span>
                        ${isSecondOrLater ? `
                            <button type="button" class="btn-secondary" onclick="openKPFormPreview(${caseId}, 'KP Form 19', ${hearingId})" style="font-size: 0.76rem; padding: 4px 10px; background: #fff; border-color: #fca5a5; color: #991b1b; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                Preview / Print KP Form 19 Notice
                            </button>
                        ` : `
                            <span style="font-size: 0.74rem; color: #64748b; font-style: italic; background: #fff; border: 1px solid #cbd5e1; padding: 3px 8px; border-radius: 4px;">
                                ℹ️ 1st Mediation: Proceed to book 2nd Mediation (no new summon needed). KP Form 19 unlocks if absent in 2nd Mediation.
                            </span>
                        `}
                    </div>
                    ${isSecondOrLater ? `
                        <div style="font-size: 0.76rem; border-top: 1px dashed #fca5a5; padding-top: 6px; margin-top: 4px;">
                            ${kp22Unlocked ? `
                                <span style="color: #15803d; font-weight: 700;">KP Form 22 (Certificate to Bar Counterclaim) Eligible:</span> Non-appearance was ruled unjustified.
                                <button type="button" class="btn-secondary" onclick="openKPFormPreview(${caseId}, 'KP Form 22', ${hearingId})" style="font-size: 0.72rem; padding: 2px 8px; margin-left: 6px;">Preview KP Form 22</button>
                            ` : `
                                <span style="color: #991b1b; font-weight: 700;">KP Form 22 (Certificate to Bar Counterclaim) Locked:</span>
                                <span style="color: #475569;">${escapeHtml(kp22Reason)}</span>
                            `}
                        </div>
                    ` : ''}
                </div>
            `;
        }

        rowsHtml += `
            <div class="att-party-row" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                <input type="hidden" name="resident_id[]" value="${Number(p.resident_id)}">
                <input type="hidden" name="party_type[]" value="${escapeHtml(p.party_type)}">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <div>
                        <strong style="font-size: 0.92rem; color: #0f172a;">${escapeHtml(p.resident_name || p.full_name)}</strong>
                        <span style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-left: 6px; ${isComplainant ? 'background: #dbeafe; color: #1e40af;' : (isRespondent ? 'background: #fef3c7; color: #b45309;' : 'background: #f1f5f9; color: #475569;')}">
                            ${escapeHtml(p.party_type)}
                        </span>
                    </div>
                    <div style="font-size: 0.76rem;">
                        <span style="padding: 2px 6px; border-radius: 4px; font-weight: 700; ${isServiceConfirmed ? 'background: #dcfce7; color: #15803d;' : 'background: #fee2e2; color: #991b1b;'}">
                            ${escapeHtml(serviceStatus)}
                        </span>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px;">
                    <div>
                        <label style="font-size: 0.74rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">ATTENDANCE STATUS</label>
                        <select name="attendance_status[]" class="att-status-select" onchange="toggleAttJustification(this)" style="width: 100%; padding: 6px; font-size: 0.84rem; border: 1px solid #cbd5e1; border-radius: 4px;">
                            <option value="Present" ${p.attendance_status === 'Present' ? 'selected' : ''}>Present</option>
                            <option value="Absent" ${p.attendance_status === 'Absent' ? 'selected' : ''}>Absent / Non-appearance</option>
                            <option value="Excused" ${p.attendance_status === 'Excused' ? 'selected' : ''}>Excused</option>
                            <option value="Pending Verification" ${p.attendance_status === 'Pending Verification' ? 'selected' : ''}>Pending Verification</option>
                            <option value="Not Served" ${p.attendance_status === 'Not Served' ? 'selected' : ''}>Not Served</option>
                        </select>
                    </div>
                    <div class="att-justification-wrap" style="${isAbsentOrExcused ? '' : 'display: none;'}">
                        <label style="font-size: 0.74rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">
                            ABSENCE EXPLANATION / JUSTIFICATION ${isAbsent ? '<span style="color: #dc2626;">*</span>' : ''}
                        </label>
                        <input type="text" name="justification_reason[]" value="${escapeHtml(p.justification_reason || '')}" placeholder="Enter party's reason for non-appearance..." style="width: 100%; padding: 6px; font-size: 0.84rem; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                </div>
                ${formRulesHtml}
            </div>
        `;
    });

    bodyEl.innerHTML = `
        <form id="wsAttendanceForm" onsubmit="submitAttendance(event)">
            ${presiderHtml}
            <div id="wsPartyAppearanceWrap">
                <div style="font-size: 0.76rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; margin-bottom: 8px;">
                    Party Appearance &amp; Summons Verification
                </div>
                ${rowsHtml}
                <div class="modal-actions" style="margin-top: 16px; border-top: 1px solid #e2e8f0; padding-top: 14px; display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn-secondary" onclick="closeAttendanceModal()">Cancel</button>
                    <button type="submit" class="btn-create" id="submitAttBtn" style="padding: 7px 18px;">Save Attendance Record</button>
                </div>
            </div>
        </form>
    `;
}

function getSuggestedRescheduleDateTime(baseDateStr) {
    let d = baseDateStr ? new Date(baseDateStr.replace(' ', 'T')) : new Date();
    if (isNaN(d.getTime())) d = new Date();
    // Advance by 3 days
    d.setDate(d.getDate() + 3);
    // Skip weekends: if Sunday (0) add 1 day to Monday; if Saturday (6) add 2 days to Monday
    if (d.getDay() === 0) d.setDate(d.getDate() + 1);
    else if (d.getDay() === 6) d.setDate(d.getDate() + 2);
    // Default working hour: 09:00 AM
    d.setHours(9, 0, 0, 0);

    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
window.getSuggestedRescheduleDateTime = getSuggestedRescheduleDateTime;

function togglePresiderSubstituteSection(val) {
    const decisionBox = document.getElementById('wsPresiderAbsenceDecisionBox');
    if (decisionBox) {
        decisionBox.style.display = (val === 'Absent') ? 'block' : 'none';
    }
    if (val === 'Absent') {
        const currentChoice = document.querySelector('input[name="presider_absence_choice"]:checked')?.value || 'substitute';
        handlePresiderAbsenceChoiceChange(currentChoice);
    } else {
        const partyWrap = document.getElementById('wsPartyAppearanceWrap');
        if (partyWrap) partyWrap.style.display = 'block';
        const submitBtn = document.getElementById('submitAttBtn');
        if (submitBtn) {
            submitBtn.style.display = 'inline-block';
            submitBtn.disabled = false;
        }
    }
}
window.togglePresiderSubstituteSection = togglePresiderSubstituteSection;

function handlePresiderAbsenceChoiceChange(choice) {
    const wrapSub = document.getElementById('wsSubstitutePresiderWrap');
    const wrapResched = document.getElementById('wsEmergencyRescheduleWrap');
    const cardSub = document.getElementById('wsChoiceCardSubstitute');
    const cardResched = document.getElementById('wsChoiceCardReschedule');
    const partyWrap = document.getElementById('wsPartyAppearanceWrap');
    const submitBtn = document.getElementById('submitAttBtn');

    if (choice === 'reschedule') {
        if (wrapSub) wrapSub.style.display = 'none';
        if (wrapResched) wrapResched.style.display = 'block';
        if (cardResched) {
            cardResched.style.border = '2px solid #dc2626';
            cardResched.style.background = '#fef2f2';
        }
        if (cardSub) {
            cardSub.style.border = '1px solid #cbd5e1';
            cardSub.style.background = '#fff';
        }
        if (partyWrap) {
            partyWrap.style.display = 'none';
        }
        if (submitBtn) {
            submitBtn.style.display = 'none';
        }
    } else {
        // Option A: Substitute
        if (wrapSub) wrapSub.style.display = 'block';
        if (wrapResched) wrapResched.style.display = 'none';
        if (cardSub) {
            cardSub.style.border = '2px solid #2563eb';
            cardSub.style.background = '#eff6ff';
        }
        if (cardResched) {
            cardResched.style.border = '1px solid #cbd5e1';
            cardResched.style.background = '#fff';
        }
        if (partyWrap) {
            partyWrap.style.display = 'block';
        }
        if (submitBtn) {
            submitBtn.style.display = 'inline-block';
            submitBtn.disabled = false;
        }
    }
}
window.handlePresiderAbsenceChoiceChange = handlePresiderAbsenceChoiceChange;

async function executeEmergencyReschedule(hearingId, caseId) {
    const dateInput = document.getElementById('wsEmergencyRescheduleDate');
    const reasonInput = document.getElementById('wsEmergencyRescheduleReason');
    const btn = document.getElementById('btnExecuteReschedule');

    const rescheduleDate = dateInput ? dateInput.value : '';
    const reason = reasonInput ? reasonInput.value.trim() : '';

    if (!rescheduleDate) {
        alert('Please select a valid new hearing date and time for the rescheduled hearing.');
        dateInput?.focus();
        return;
    }

    const readableDate = formatDateTimeReadable(rescheduleDate);
    const confirmMsg = `Confirm Emergency Hearing Reschedule?\n\n` +
        `• New Hearing Date: ${readableDate}\n` +
        `• Reason: ${reason || 'Presider Unavailability / Official Absence'}\n` +
        `• Legal Remedy: Neither party is penalized with an unexcused absence.\n` +
        `• Documents: KP Form 8 (Complainant) and KP Form 9 (Respondent) will be automatically generated.\n\n` +
        `Do you want to proceed?`;

    if (!confirm(confirmMsg)) {
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Rescheduling &amp; Generating Forms...';
    }

    try {
        const payload = {
            action: 'reschedule',
            hearing_id: Number(hearingId || stageWorkspaceState.currentHearingId),
            case_id: Number(caseId || stageWorkspaceState.caseId),
            reschedule_date: rescheduleDate.replace('T', ' '),
            reason: reason || 'Presider Unavailability / Official Absence'
        };

        const res = await fetch(getStageApiUrl('hearings/presider-absence.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await res.json();
        if (json.success) {
            const bodyEl = document.getElementById('wsAttModalBody');
            const newHearingId = json.new_hearing_id || hearingId;

            if (bodyEl) {
                bodyEl.innerHTML = `
                    <div style="text-align: center; padding: 24px 16px;">
                        <div style="font-size: 2.4rem; color: #16a34a; margin-bottom: 8px;">✓</div>
                        <h3 style="margin: 0 0 8px; color: #0f172a; font-size: 1.15rem; font-weight: 700;">Hearing Rescheduled Successfully</h3>
                        <p style="color: #475569; font-size: 0.85rem; max-width: 520px; margin: 0 auto 16px; line-height: 1.5;">
                            The hearing has been officially cancelled due to presider unavailability and rescheduled to:
                            <br><strong style="color: #0f172a; font-size: 0.95rem;">${escapeHtml(json.formatted_date || readableDate)}</strong>
                        </p>
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px; max-width: 520px; margin: 0 auto 18px; text-align: left;">
                            <div style="font-size: 0.78rem; font-weight: 700; color: #166534; margin-bottom: 6px;">
                                Auto-Generated Legal Documents (Ready for Service):
                            </div>
                            <ul style="margin: 0; padding-left: 18px; font-size: 0.76rem; color: #14532d; line-height: 1.5;">
                                <li><strong>KP Form 8 (Notice of Hearing)</strong>: Issued to Complainant with new hearing date and reason.</li>
                                <li><strong>KP Form 9 (Summons for Rescheduled Hearing)</strong>: Issued to Respondent with new hearing date and reason.</li>
                            </ul>
                        </div>
                        <div style="display: flex; justify-content: center; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                            <button type="button" class="btn-secondary" onclick="openKPFormPreview(${Number(caseId)}, 'KP Form 8', ${newHearingId})" style="padding: 7px 14px; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                                Preview KP Form 8 (Complainant)
                            </button>
                            <button type="button" class="btn-secondary" onclick="openKPFormPreview(${Number(caseId)}, 'KP Form 9', ${newHearingId})" style="padding: 7px 14px; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                                Preview KP Form 9 (Respondent)
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn-create" onclick="closeAttendanceModal(); loadStageData();" style="padding: 8px 22px; font-size: 0.85rem; font-weight: 700; cursor: pointer;">
                                Return to Workspace &rarr;
                            </button>
                        </div>
                    </div>
                `;
            }
        } else {
            alert(json.message || 'Unable to reschedule hearing.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Confirm Emergency Reschedule &amp; Issue KP Forms 8 &amp; 9';
            }
        }
    } catch (err) {
        console.error('executeEmergencyReschedule error:', err);
        alert('Network error while executing emergency reschedule.');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'Confirm Emergency Reschedule &amp; Issue KP Forms 8 &amp; 9';
        }
    }
}
window.executeEmergencyReschedule = executeEmergencyReschedule;

function toggleAttJustification(selectEl) {
    const row = selectEl.closest('.att-party-row');
    if (!row) return;
    const isAbsentOrExcused = (selectEl.value === 'Absent' || selectEl.value === 'Excused');
    const justWrap = row.querySelector('.att-justification-wrap');
    if (justWrap) {
        justWrap.style.display = isAbsentOrExcused ? 'block' : 'none';
    }
    const legalNoticeWrap = row.querySelector('.att-legal-notices-wrap');
    if (legalNoticeWrap) {
        legalNoticeWrap.style.display = (selectEl.value === 'Absent') ? 'block' : 'none';
    }
}
window.toggleAttJustification = toggleAttJustification;

async function submitAttendance(e) {
    e.preventDefault();
    const btn = document.getElementById('submitAttBtn');
    if (btn) btn.disabled = true;

    const presiderStatus = document.getElementById('wsPresiderStatus')?.value || 'Present';
    let substituteId = null;
    let substituteReason = '';
    let consentVal = 0;

    if (presiderStatus === 'Absent') {
        const absenceChoice = document.querySelector('input[name="presider_absence_choice"]:checked')?.value || 'substitute';
        if (absenceChoice === 'reschedule') {
            alert('Emergency Reschedule is selected. Please click "Confirm Emergency Reschedule & Issue KP Forms 8 & 9" in the Presider Absence section to finalize.');
            if (btn) btn.disabled = false;
            return;
        }

        const subSelect = document.getElementById('wsSubstitutePresiderId');
        const reasonInput = document.getElementById('wsSubstituteReason');
        const consentCheck = document.getElementById('wsPartiesConsentCheck');

        substituteId = subSelect ? subSelect.value : '';
        substituteReason = reasonInput ? reasonInput.value.trim() : '';
        consentVal = consentCheck && consentCheck.checked ? 1 : 0;

        if (!substituteId) {
            alert('Presider Attendance Validation: When the presiding officer is absent, selecting a designated substitute presider from the Lupong Tagapamayapa is required before this session can proceed.');
            if (btn) btn.disabled = false;
            subSelect?.focus();
            return;
        }
        if (!substituteReason) {
            alert('Presider Attendance Validation: Please enter the official reason for the presider absence and substitution.');
            if (btn) btn.disabled = false;
            reasonInput?.focus();
            return;
        }
        if (!consentVal) {
            alert('Presider Attendance Validation: Mutual consent of both parties to the substitute presider must be confirmed before proceeding.');
            if (btn) btn.disabled = false;
            consentCheck?.focus();
            return;
        }
    }

    const form = document.getElementById('wsAttendanceForm');
    const residentIds = Array.from(form.querySelectorAll('input[name="resident_id[]"]')).map(el => Number(el.value));
    const partyTypes = Array.from(form.querySelectorAll('input[name="party_type[]"]')).map(el => el.value);
    const statuses = Array.from(form.querySelectorAll('select[name="attendance_status[]"]')).map(el => el.value);
    const justifications = Array.from(form.querySelectorAll('input[name="justification_reason[]"]')).map(el => el.value.trim());

    let compAtt = 'Present';
    let respAtt = 'Present';
    let compReason = '';
    let respReason = '';

    const records = residentIds.map((rid, idx) => {
        const pType = partyTypes[idx];
        const st = statuses[idx];
        const jReason = justifications[idx] || '';

        if (pType === 'Complainant') {
            compAtt = (st === 'Absent' || st === 'Excused') ? 'Absent' : 'Present';
            compReason = jReason;
        } else if (pType === 'Respondent') {
            respAtt = (st === 'Absent' || st === 'Excused') ? 'Absent' : 'Present';
            respReason = jReason;
        }

        return {
            resident_id: rid,
            party_type: pType,
            attendance_status: st,
            is_justified: (st === 'Excused' || jReason !== '') ? 1 : 0,
            justification_reason: jReason || null,
            verification_status: st === 'Present' ? 'Verified Served' : 'Pending'
        };
    });

    const payload = {
        hearing_id: stageWorkspaceState.currentHearingId,
        presider_attendance: presiderStatus,
        substitute_presider_id: presiderStatus === 'Absent' ? Number(substituteId) : null,
        substitute_reason: presiderStatus === 'Absent' ? substituteReason : null,
        parties_consent_to_substitute: presiderStatus === 'Absent' && consentVal ? 1 : 0,
        complainant_attendance: compAtt,
        complainant_explanation: compReason,
        respondent_attendance: respAtt,
        respondent_explanation: respReason,
        parties: records
    };

    try {
        const res = await fetch(getStageApiUrl('hearings/attendance.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            notifyUser('Attendance records saved successfully.', 'success');
            closeAttendanceModal();
            await loadStageData();
        } else {
            notifyUser(json.message || 'Unable to save attendance.', 'error');
        }
    } catch (err) {
        console.error('submitAttendance error:', err);
        notifyUser('Network error saving attendance.', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * GEMINI 1.5 FLASH: SPEECH-TO-TEXT (STT) & IMAGE OCR
 * -----------------------------------------------------------------------------
 */
let stageMediaRecorder = null;
let stageAudioChunks = [];
let isStageVoiceRecording = false;
let stageRecordingTimerInterval = null;
let stageRecordingSeconds = 0;

function toggleCaucusNotesVisibility(checkbox) {
    const wrap = document.getElementById('minCaucusNotesWrap');
    if (wrap) {
        wrap.style.display = checkbox.checked ? 'block' : 'none';
    }
}

async function toggleStageVoiceRecording() {
    const btn = document.getElementById('btnStageRecordVoice');
    const textSpan = document.getElementById('stageRecordVoiceText');
    const statusBanner = document.getElementById('stageAiStatusBanner');

    if (!isStageVoiceRecording) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Microphone recording is not supported in this browser environment.');
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            stageAudioChunks = [];
            stageRecordingSeconds = 0;
            stageMediaRecorder = new MediaRecorder(stream);

            stageMediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    stageAudioChunks.push(event.data);
                }
            };

            stageMediaRecorder.onstop = async () => {
                stream.getTracks().forEach(track => track.stop());
                clearInterval(stageRecordingTimerInterval);
                const audioBlob = new Blob(stageAudioChunks, { type: 'audio/webm' });
                await sendStageAudioToGemini(audioBlob);
            };

            stageMediaRecorder.start();
            isStageVoiceRecording = true;
            if (btn) {
                btn.classList.add('recording');
                btn.style.background = '#ef4444';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#dc2626';
            }
            if (textSpan) textSpan.textContent = 'Stop Recording (00:00)';
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '<strong>Recording audio from microphone...</strong> Speak clearly. Click "Stop Recording" when finished.';
            }

            stageRecordingTimerInterval = setInterval(() => {
                stageRecordingSeconds++;
                const mins = String(Math.floor(stageRecordingSeconds / 60)).padStart(2, '0');
                const secs = String(stageRecordingSeconds % 60).padStart(2, '0');
                if (textSpan) textSpan.textContent = `Stop Recording (${mins}:${secs})`;
            }, 1000);
        } catch (err) {
            console.error('Microphone access denied:', err);
            alert('Unable to access microphone: ' + err.message);
        }
    } else {
        if (stageMediaRecorder && stageMediaRecorder.state !== 'inactive') {
            stageMediaRecorder.stop();
        }
        isStageVoiceRecording = false;
        clearInterval(stageRecordingTimerInterval);
        if (btn) {
            btn.classList.remove('recording');
            btn.style.background = '';
            btn.style.color = '';
            btn.style.borderColor = '';
            btn.disabled = true;
        }
        if (textSpan) textSpan.textContent = 'Transcribing Voice...';
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = '<strong>Transcribing voice with Gemini 1.5 Flash...</strong> Please wait.';
        }
    }
}

async function sendStageAudioToGemini(audioBlob) {
    const btn = document.getElementById('btnStageRecordVoice');
    const textSpan = document.getElementById('stageRecordVoiceText');
    const statusBanner = document.getElementById('stageAiStatusBanner');

    try {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'stage_session_recording.webm');

        const response = await fetch(getStageApiUrl('ai/transcribe-audio.php'), {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success && result.text) {
            appendGeminiTextToMinutes(result.text, 'Voice Transcription');
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '<strong>Voice transcription appended successfully.</strong>';
                setTimeout(() => { if (statusBanner) statusBanner.style.display = 'none'; }, 4000);
            }
        } else {
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = `<span style="color:#b91c1c;">Transcription error: ${escapeHtml(result.message || 'Failed to process audio.')}</span>`;
            }
        }
    } catch (error) {
        console.error('Audio transcription error:', error);
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = `<span style="color:#b91c1c;">Network error during transcription: ${escapeHtml(error.message)}</span>`;
        }
    } finally {
        if (btn) btn.disabled = false;
        if (textSpan) textSpan.textContent = 'Record Voice (STT)';
    }
}

function triggerStageNotesUpload() {
    const fileInput = document.getElementById('stageNotesFileInput');
    if (fileInput) fileInput.click();
}

async function handleStageNotesFileSelected(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const statusBanner = document.getElementById('stageAiStatusBanner');
    const uploadBtn = document.getElementById('btnStageUploadNotes');

    if (uploadBtn) uploadBtn.disabled = true;
    if (statusBanner) {
        statusBanner.style.display = 'block';
        statusBanner.innerHTML = '<strong>Extracting handwritten/printed notes with Gemini 1.5 Flash OCR...</strong> Please wait.';
    }

    try {
        const formData = new FormData();
        formData.append('image', file);

        const response = await fetch(getStageApiUrl('ai/ocr-notes.php'), {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success && result.text) {
            appendGeminiTextToMinutes(result.text, 'OCR Notes Extract');
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '<strong>Notes extracted and appended successfully.</strong>';
                setTimeout(() => { if (statusBanner) statusBanner.style.display = 'none'; }, 4000);
            }
        } else {
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = `<span style="color:#b91c1c;">OCR error: ${escapeHtml(result.message || 'Failed to extract text from image.')}</span>`;
            }
        }
    } catch (error) {
        console.error('OCR error:', error);
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = `<span style="color:#b91c1c;">Network error during OCR: ${escapeHtml(error.message)}</span>`;
        }
    } finally {
        if (uploadBtn) uploadBtn.disabled = false;
        input.value = '';
    }
}

function appendGeminiTextToMinutes(newText, sourceLabel) {
    const textarea = document.getElementById('session_minutes');
    if (!textarea) return;

    const trimmed = newText.trim();
    if (!trimmed) return;

    const formattedSnippet = `\n\n[${sourceLabel} - ${new Date().toLocaleTimeString()}]:\n${trimmed}`;
    if (textarea.value.trim() === '') {
        textarea.value = trimmed;
    } else {
        textarea.value = textarea.value.trim() + formattedSnippet;
    }

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
    textarea.scrollTop = textarea.scrollHeight;
}

/**
 * Retrieves the current minutes data for a hearing (falling back to live form if active)
 */
function getActiveMinutesSnapshot(hearingId) {
    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId)) || {};
    const isActive = Number(document.getElementById('minHearingId')?.value) === Number(hearingId);

    if (isActive) {
        return {
            ...session,
            opening_conducted: document.getElementById('minOpening')?.checked ? 1 : 0,
            identity_verified: document.getElementById('minIdentity')?.checked ? 1 : 0,
            complaint_reviewed: document.getElementById('minReviewed')?.checked ? 1 : 0,
            complainant_statement: document.getElementById('minCompStmt')?.value || session.complainant_statement || '',
            respondent_statement: document.getElementById('minRespStmt')?.value || session.respondent_statement || '',
            main_dispute_identified: document.getElementById('minDisputeSummary')?.value || session.main_dispute_identified || '',
            agenda_topics: document.getElementById('minAgenda')?.value || session.agenda_topics || '',
            new_proposal: document.getElementById('minNewProposal')?.value || session.new_proposal || '',
            counteroffer: document.getElementById('minCounterOffer')?.value || session.counteroffer || '',
            caucus_conducted: document.getElementById('minCaucus')?.checked ? 1 : 0,
            caucus_notes: document.getElementById('minCaucusNotes')?.value || session.caucus_notes || '',
            agreements_action_items: document.getElementById('minAgreements')?.value || session.agreements_action_items || '',
            unresolved_issues: document.getElementById('minUnresolved')?.value || session.unresolved_issues || '',
            session_outcome: document.getElementById('minOutcome')?.value || session.session_outcome || 'Pending',
            actual_end_time: document.getElementById('minActualEndTime')?.value || session.actual_end_time || null,
            outcome_remarks: document.getElementById('minOutcomeRemarks')?.value || session.outcome_remarks || '',
            settlement_discussion_notes: document.getElementById('session_minutes')?.value || session.settlement_discussion_notes || ''
        };
    }
    return session;
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Preview Formatted Minutes
 * -----------------------------------------------------------------------------
 */
function openPreviewMinutesModal(hearingId) {
    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    const caseData = stageWorkspaceState.stageData?.case || {};
    const min = getActiveMinutesSnapshot(hearingId);

    let previewModal = document.getElementById('wsPreviewMinutesModal');
    if (!previewModal) {
        previewModal = document.createElement('div');
        previewModal.id = 'wsPreviewMinutesModal';
        previewModal.className = 'modal';
        previewModal.style.display = 'none';
        document.body.appendChild(previewModal);
    }

    previewModal.innerHTML = `
        <div class="modal-content" style="max-width: 840px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h2>Official Minutes Preview: ${escapeHtml(session?.session_number || `Hearing #${hearingId}`)}</h2>
                <button type="button" class="close-btn" onclick="closePreviewMinutesModal()">&times;</button>
            </div>
            <div style="padding: 16px 20px; font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.5; color: #1e293b;">
                <div style="text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px;">
                    <h3 style="margin: 0; font-size: 1.15rem; text-transform: uppercase;">Republic of the Philippines · Barangay Tumana</h3>
                    <h4 style="margin: 3px 0 0; font-size: 0.95rem; color: #475569;">Lupong Tagapamayapa · Formal Session Minutes of the Meeting</h4>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.85rem; margin-bottom: 16px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <div><strong>Case Number:</strong> Case #${escapeHtml(caseData.case_number || 'N/A')}</div>
                    <div><strong>Hearing Schedule:</strong> ${formatDateTimeReadable(session?.hearing_date)}</div>
                    <div><strong>Complainant(s):</strong> ${escapeHtml(caseData.complainant_names || '—')}</div>
                    <div><strong>Venue:</strong> ${escapeHtml(session?.venue || 'Barangay Hall')}</div>
                    <div><strong>Respondent(s):</strong> ${escapeHtml(caseData.respondent_names || '—')}</div>
                    <div><strong>Presiding Officer:</strong> ${escapeHtml(session?.presider_name || 'Punong Barangay')}</div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">1. Procedural Opening &amp; Identity Verification</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px;">
                        Opening Statement: <strong>${min.opening_conducted ? 'Conducted' : 'Not Recorded'}</strong> · 
                        Parties Identity: <strong>${min.identity_verified ? 'Verified' : 'Not Recorded'}</strong> · 
                        Complaint Read &amp; Reviewed: <strong>${min.complaint_reviewed ? 'Reviewed' : 'Not Recorded'}</strong>
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">2. Main Dispute &amp; Statements</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px;"><strong>Core Dispute:</strong> ${escapeHtml(min.main_dispute_identified || 'None recorded')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Complainant Summary:</strong> ${escapeHtml(min.complainant_statement || 'None recorded')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Respondent Summary:</strong> ${escapeHtml(min.respondent_statement || 'None recorded')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Agenda Topics:</strong> ${escapeHtml(min.agenda_topics || 'None recorded')}</div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">3. Negotiation, Proposals &amp; Caucus</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px;"><strong>Proposal / Offer:</strong> ${escapeHtml(min.new_proposal || 'None submitted')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Counteroffer / Response:</strong> ${escapeHtml(min.counteroffer || 'None recorded')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Private Caucus:</strong> ${min.caucus_conducted ? `Conducted — ${escapeHtml(min.caucus_notes || 'Confidential')}` : 'None held'}</div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">4. Formal Proceedings / Minutes Transcript</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px; white-space: pre-wrap; background: #fff; padding: 10px; border: 1px solid #e2e8f0; border-radius: 4px;">${escapeHtml(min.settlement_discussion_notes || 'None recorded')}</div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">5. Agreed Terms &amp; Procedural Determination</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px;"><strong>Agreed Terms:</strong> ${escapeHtml(min.agreements_action_items || 'None')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Unresolved Issues:</strong> ${escapeHtml(min.unresolved_issues || 'None')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Session Outcome:</strong> <strong style="color: #15803d;">${escapeHtml(min.session_outcome || 'Pending')}</strong></div>
                    ${min.actual_end_time ? `<div style="font-size: 0.84rem; margin-top: 4px;"><strong>Actual End Time:</strong> ${formatDateTimeReadable(min.actual_end_time)}</div>` : ''}
                    ${min.outcome_remarks ? `<div style="font-size: 0.84rem; margin-top: 4px;"><strong>Remarks:</strong> ${escapeHtml(min.outcome_remarks)}</div>` : ''}
                </div>
            </div>
            <div class="modal-actions" style="margin-top: 10px;">
                <button type="button" class="btn-secondary" onclick="closePreviewMinutesModal()">Close</button>
                <button type="button" class="btn-create" onclick="printHearingMinutes(${hearingId})" style="background: #0369a1;">Print Official Minutes</button>
            </div>
        </div>
    `;
    previewModal.style.display = 'flex';
}

function closePreviewMinutesModal() {
    const modal = document.getElementById('wsPreviewMinutesModal');
    if (modal) modal.style.display = 'none';
}

/**
 * -----------------------------------------------------------------------------
 * PRINT: Official Hearing Minutes Document
 * -----------------------------------------------------------------------------
 */
function printHearingMinutes(hearingId) {
    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    const caseData = stageWorkspaceState.stageData?.case || {};
    const min = getActiveMinutesSnapshot(hearingId);

    const printWindow = window.open('', '_blank', 'width=850,height=950');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Minutes of Meeting - Case #${escapeHtml(caseData.case_number)}</title>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; padding: 40px; color: #1e293b; line-height: 1.6; }
                .header { text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 16px; margin-bottom: 24px; }
                .header h2 { margin: 0; font-size: 1.3rem; text-transform: uppercase; }
                .header h3 { margin: 4px 0; font-size: 1.1rem; color: #475569; }
                .meta-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
                .meta-table td { padding: 6px 0; font-size: 0.92rem; }
                .section-title { font-size: 0.96rem; font-weight: bold; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-top: 18px; color: #0f172a; }
                .content-box { margin-top: 6px; font-size: 0.9rem; white-space: pre-wrap; line-height: 1.5; }
                .footer { margin-top: 40px; display: flex; justify-content: space-between; font-size: 0.88rem; }
                @media print { body { padding: 0; } }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Republic of the Philippines · Barangay Tumana</h2>
                <h3>Lupong Tagapamayapa · Official Session Minutes</h3>
            </div>
            <table class="meta-table">
                <tr>
                    <td><strong>Case Number:</strong> Case #${escapeHtml(caseData.case_number || 'N/A')}</td>
                    <td><strong>Hearing Date &amp; Time:</strong> ${formatDateTimeReadable(session?.hearing_date)}</td>
                </tr>
                <tr>
                    <td><strong>Complainant(s):</strong> ${escapeHtml(caseData.complainant_names || '—')}</td>
                    <td><strong>Venue:</strong> ${escapeHtml(session?.venue || 'Barangay Hall')}</td>
                </tr>
                <tr>
                    <td><strong>Respondent(s):</strong> ${escapeHtml(caseData.respondent_names || '—')}</td>
                    <td><strong>Session:</strong> ${escapeHtml(session?.session_number || 'Hearing Session')}</td>
                </tr>
            </table>

            <div class="section-title">1. Procedural Opening &amp; Identity Verification</div>
            <div class="content-box">Opening Conducted: ${min.opening_conducted ? 'Yes' : 'No'} | Parties Verified: ${min.identity_verified ? 'Yes' : 'No'} | Complaint Reviewed: ${min.complaint_reviewed ? 'Yes' : 'No'}</div>

            <div class="section-title">2. Agenda Topics &amp; Core Dispute</div>
            <div class="content-box"><strong>Main Dispute:</strong> ${escapeHtml(min.main_dispute_identified || 'None recorded')}\n<strong>Agenda Discussed:</strong> ${escapeHtml(min.agenda_topics || 'None recorded')}</div>

            <div class="section-title">3. Statements of Parties</div>
            <div class="content-box"><strong>Complainant:</strong> ${escapeHtml(min.complainant_statement || 'None recorded')}\n\n<strong>Respondent:</strong> ${escapeHtml(min.respondent_statement || 'None recorded')}</div>

            <div class="section-title">4. Settlement Proposals, Offers &amp; Caucus</div>
            <div class="content-box"><strong>Proposal / Offer:</strong> ${escapeHtml(min.new_proposal || 'None submitted')}\n<strong>Counteroffer / Response:</strong> ${escapeHtml(min.counteroffer || 'None recorded')}\n<strong>Caucus Held:</strong> ${min.caucus_conducted ? 'Yes - ' + escapeHtml(min.caucus_notes || 'Confidential') : 'No'}</div>

            <div class="section-title">5. Formal Proceedings / Session Record</div>
            <div class="content-box">${escapeHtml(min.settlement_discussion_notes || 'None recorded')}</div>

            <div class="section-title">6. Agreed Terms &amp; Procedural Outcome</div>
            <div class="content-box"><strong>Agreements / Action Items:</strong> ${escapeHtml(min.agreements_action_items || 'None')}\n<strong>Unresolved Issues:</strong> ${escapeHtml(min.unresolved_issues || 'None')}\n<strong>Session Outcome:</strong> ${escapeHtml(min.session_outcome || 'Pending')}${min.outcome_remarks ? ' (' + escapeHtml(min.outcome_remarks) + ')' : ''}</div>

            <div class="footer">
                <div>
                    <br><br>
                    ___________________________<br>
                    <strong>Prepared By:</strong><br>
                    ${escapeHtml(min.recorded_by_name || 'Lupon Clerk')}
                </div>
                <div>
                    <br><br>
                    ___________________________<br>
                    <strong>Approved / Presiding Officer:</strong><br>
                    ${escapeHtml(session?.presider_name || 'Punong Barangay')}
                </div>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => { printWindow.print(); }, 500);
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Record Stage Outcome (Settled, Unsuccessful, Repudiated, Dismissed)
 * -----------------------------------------------------------------------------
 */
function openRecordOutcomeModal() {
    const modal = document.getElementById('wsRecordOutcomeModal');
    if (modal) modal.style.display = 'flex';
}

function closeRecordOutcomeModal() {
    const modal = document.getElementById('wsRecordOutcomeModal');
    if (modal) modal.style.display = 'none';
}

async function submitRecordOutcome(e) {
    e.preventDefault();
    const btn = document.getElementById('submitOutcomeBtn');
    if (btn) btn.disabled = true;

    const outcomeVal = document.getElementById('stageOutcomeSelect').value;
    const remarksVal = document.getElementById('stageOutcomeRemarks').value;

    const payload = {
        case_id: stageWorkspaceState.caseId,
        stage_type: stageWorkspaceState.mode,
        outcome: outcomeVal,
        remarks: remarksVal
    };

    try {
        const res = await fetch(getStageApiUrl('stages/outcome.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closeRecordOutcomeModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to record outcome.');
        }
    } catch (err) {
        console.error('submitRecordOutcome error:', err);
        alert('Network error recording outcome.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Refer to Pangkat ng Tagapagkasundo (KP Form 10)
 * -----------------------------------------------------------------------------
 */
function openReferPangkatModal() {
    const modal = document.getElementById('wsReferPangkatModal');
    if (!modal) return;
    const dateInput = document.getElementById('referDate');
    if (dateInput && !dateInput.value) {
        dateInput.value = new Date().toISOString().split('T')[0];
    }
    populateLuponDropdowns();
    goReferralStep(1);
    modal.style.display = 'flex';
}

function closeReferPangkatModal() {
    const modal = document.getElementById('wsReferPangkatModal');
    if (modal) modal.style.display = 'none';
}

function goReferralStep(step) {
    if (step === 2) {
        const reason = document.getElementById('referReason')?.value.trim();
        const date = document.getElementById('referDate')?.value;
        if (!date || !reason) { alert('Enter the referral date and authorized referral justification before continuing.'); return; }
    }
    if (step === 3) {
        const ids = ['referChairmanId', 'referSecretaryId', 'referMemberId'].map((id) => document.getElementById(id)?.value);
        if (ids.some((id) => !id) || new Set(ids).size !== 3) { alert('Choose three different active Lupon Members before reviewing the referral.'); return; }
        const nameFor = (id) => document.getElementById(id)?.selectedOptions?.[0]?.textContent || 'Not selected';
        const summary = document.getElementById('referReviewSummary');
        if (summary) summary.innerHTML = `<strong>Step 3 of 3: Review and confirm</strong><br><br><strong>Referral date:</strong> ${escapeHtml(document.getElementById('referDate')?.value || '')}<br><strong>Head:</strong> ${escapeHtml(nameFor('referChairmanId'))}<br><strong>Secretary:</strong> ${escapeHtml(nameFor('referSecretaryId'))}<br><strong>Member:</strong> ${escapeHtml(nameFor('referMemberId'))}<br><br>The system will record the statutory Pangkat convening deadline and generate KP Form 10.`;
    }
    document.querySelectorAll('.referral-step').forEach((element) => { element.style.display = Number(element.dataset.referralStep) === Number(step) ? 'block' : 'none'; });
}
window.goReferralStep = goReferralStep;

async function submitReferPangkat(e) {
    e.preventDefault();
    const btn = document.getElementById('submitReferBtn');
    if (btn) btn.disabled = true;

    const chairmanId = document.getElementById('referChairmanId')?.value;
    const secretaryId = document.getElementById('referSecretaryId')?.value;
    const memberId = document.getElementById('referMemberId')?.value;

    if (!chairmanId || !secretaryId || !memberId) {
        alert('Please nominate all 3 distinct active Lupon Members for Chairperson, Secretary, and Member.');
        if (btn) btn.disabled = false;
        return;
    }

    if (new Set([chairmanId, secretaryId, memberId]).size !== 3) {
        alert('Chairperson, Secretary, and Member must be different Lupon members.');
        if (btn) btn.disabled = false;
        return;
    }

    const payload = {
        case_id: stageWorkspaceState.caseId,
        referral_date: document.getElementById('referDate')?.value || new Date().toISOString().split('T')[0],
        referral_reason: document.getElementById('referReason')?.value || 'Mediation before the Punong Barangay failed. Elevated to Pangkat Conciliation.',
        selection_method: document.getElementById('referSelectionMethod')?.value || 'Party Agreement',
        selection_notes: document.getElementById('referSelectionNotes')?.value || '',
        quorum_size: 3,
        chairman_id: Number(chairmanId),
        secretary_id: Number(secretaryId),
        member_id: Number(memberId)
    };

    try {
        const res = await fetch(getStageApiUrl('stages/refer-pangkat.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            notifyUser('Case successfully referred to Pangkat ng Tagapagkasundo! KP Form 10 generated.', 'success');
            closeReferPangkatModal();
            setTimeout(() => {
                window.location.href = `pangkat-workspace.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}`;
            }, 600);
        } else {
            notifyUser(json.message || 'Unable to refer case to Pangkat.', 'error');
        }
    } catch (err) {
        console.error('submitReferPangkat error:', err);
        notifyUser('Network error during referral.', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * SECTION: Pangkat ng Tagapagkasundo Governance & Statutory Panel
 * -----------------------------------------------------------------------------
 */
function renderPangkatPanelSection(data, stage) {
    const container = document.getElementById('wsPangkatPanelContainer');
    if (!container) return;

    const pInfo = stage.pangkat_assignment || {};
    const members = pInfo.members || [];
    const challenges = pInfo.challenges || [];
    const extensions = pInfo.extensions || [];
    const dl = stage.deadline || {};

    const selectionMethod = pInfo.selection_method || 'Party Agreement';
    const isAgreementFailed = !!pInfo.party_agreement_failed;
    const failureReason = pInfo.party_agreement_failure_reason || '';

    // Selection method badge
    let selectionBadge = `<span style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 0.78rem;">Chosen by Parties</span>`;
    if (isAgreementFailed || selectionMethod === 'PB Assignment' || selectionMethod === 'Raffle Draw') {
        const methodLabel = selectionMethod === 'Raffle Draw' ? 'PB Drawing of Lots (Raffle)' : 'Punong Barangay Appointment';
        selectionBadge = `<span style="background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 0.78rem;">${escapeHtml(methodLabel)}</span>`;
    }

    // Pending challenges banner
    const pendingChallenges = challenges.filter(c => c.status === 'Pending');
    let pendingChalBanner = '';
    if (pendingChallenges.length > 0) {
        const pch = pendingChallenges[0];
        pendingChalBanner = `
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-left: 5px solid #d97706; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <strong style="color: #92400e; font-size: 0.92rem; display: flex; align-items: center; gap: 6px;">
                            Active Challenge Against Pangkat Member Pending
                        </strong>
                        <p style="margin: 4px 0 0; font-size: 0.84rem; color: #78350f;">
                            <strong>${escapeHtml(pch.challenged_name || 'Member')}</strong> was challenged by ${escapeHtml(pch.challenger_party || 'Party')} on grounds of <strong>${escapeHtml(pch.grounds)}</strong>.
                            Under RA 7160 Sec. 404(d), this challenge must be decided by majority vote of the Pangkat panel.
                        </p>
                    </div>
                    <button type="button" class="btn-create" onclick="openResolveChallengeModal(${Number(pch.challenge_id)})" style="font-size: 0.8rem; padding: 7px 14px; background: #d97706;">
                        Deliberate &amp; Resolve Challenge &rarr;
                    </button>
                </div>
            </div>
        `;
    }

    // Render Members Cards
    let membersHtml = '';
    if (members.length) {
        membersHtml = members.map(m => {
            const isChallenged = m.status === 'Challenged';
            const isDisqualified = m.status === 'Disqualified';
            let statusBadge = `<span style="background: #dcfce7; color: #15803d; padding: 2px 7px; border-radius: 4px; font-size: 0.72rem; font-weight: 700;">Active</span>`;
            if (isChallenged) {
                statusBadge = `<span style="background: #fef3c7; color: #b45309; padding: 2px 7px; border-radius: 4px; font-size: 0.72rem; font-weight: 700;">Challenged</span>`;
            } else if (isDisqualified) {
                statusBadge = `<span style="background: #fee2e2; color: #991b1b; padding: 2px 7px; border-radius: 4px; font-size: 0.72rem; font-weight: 700;">Disqualified</span>`;
            }

            return `
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; flex: 1; min-width: 220px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;">
                                ${escapeHtml(m.position)}
                            </span>
                            ${statusBadge}
                        </div>
                        <strong style="font-size: 0.96rem; color: #0f172a; display: block;">${escapeHtml(m.member_name)}</strong>
                        <span style="font-size: 0.78rem; color: #64748b;">${escapeHtml(m.contact_number || m.email || 'Lupon Tagapagkasundo')}</span>
                    </div>
                    <div style="margin-top: 12px; padding-top: 8px; border-top: 1px dashed #e2e8f0; display: flex; justify-content: flex-end;">
                        ${!isDisqualified ? `
                            <button type="button" class="btn-secondary" onclick="openChallengeMemberModal(${Number(m.member_id)}, '${escapeHtml(m.member_name)}')" style="font-size: 0.74rem; padding: 4px 10px; color: #64748b;" title="Challenge this member for bias, interest, or relationship">
                                Challenge Member
                            </button>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');
    } else {
        membersHtml = '<div style="color: #64748b; font-size: 0.86rem; padding: 10px 0;">No panel members assigned yet.</div>';
    }

    // Render Extensions List (if any)
    let extensionsHtml = '';
    if (extensions.length) {
        extensionsHtml = `
            <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9;">
                <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; display: block; margin-bottom: 8px;">
                    Controlled Extension Records (RA 7160 §410c)
                </span>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    ${extensions.map(e => `
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; font-size: 0.82rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <span style="font-weight: 700; color: #0369a1;">+${e.days_extended} Days (${escapeHtml(e.extension_type)})</span>
                                &middot; Extended from <strong>${formatDateReadable(e.previous_deadline)}</strong> to <strong style="color: #0f172a;">${formatDateReadable(e.new_deadline)}</strong>
                                <div style="margin-top: 3px; color: #475569;">
                                    <em>Justification:</em> "${escapeHtml(e.justification)}"
                                    ${e.meritorious_basis ? `<br><em>Statutory Basis:</em> "${escapeHtml(e.meritorious_basis)}"` : ''}
                                </div>
                            </div>
                            <span style="font-size: 0.76rem; color: #64748b;">Approved by ${escapeHtml(e.approver_name || 'Lupon')} on ${formatDateReadable(e.granted_at)}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    container.innerHTML = `
        <div class="stage-section-header">
            <div>
                <h3>Pangkat ng Tagapagkasundo &amp; Panel Governance</h3>
                <p>3-member panel chosen from the Lupon roster pursuant to RA 7160 Sections 404 &amp; 410</p>
            </div>
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                ${selectionBadge}
                <button type="button" class="btn-secondary" onclick="openPangkatSelectionModal()" style="font-size: 0.78rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 4px;">
                    Selection &amp; Replacement
                </button>
                <button type="button" class="btn-create" onclick="openPangkatExtensionModal()" style="font-size: 0.78rem; padding: 5px 12px; background: #0369a1; display: inline-flex; align-items: center; gap: 4px;">
                    Grant Extension
                </button>
            </div>
        </div>

        ${pendingChalBanner}

        ${isAgreementFailed ? `
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #f59e0b; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.82rem;">
                <strong style="color: #b45309;">Party Agreement Impasse:</strong> Parties failed to reach agreement on Pangkat membership. The Punong Barangay determined membership via <strong>${escapeHtml(selectionMethod)}</strong> pursuant to RA 7160 Sec. 404(a).
                ${failureReason ? `<br><span style="color: #64748b;">Record: ${escapeHtml(failureReason)}</span>` : ''}
            </div>
        ` : ''}

        <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 4px;">
            ${membersHtml}
        </div>

        ${extensionsHtml}
    `;
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Pangkat Extension (Discretionary vs Clearly Meritorious)
 * -----------------------------------------------------------------------------
 */
function openPangkatExtensionModal() {
    const modal = document.getElementById('wsPangkatExtensionModal');
    if (!modal) return;

    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const dl = stage.deadline || {};
    const currDlEl = document.getElementById('extCurrentDeadline');
    if (currDlEl) {
        currDlEl.textContent = dl.target_date ? formatDateReadable(dl.target_date) : 'Pending Convening';
    }

    const daysInput = document.getElementById('extDays');
    if (daysInput) daysInput.value = '15';

    toggleExtensionType('Discretionary');
    updateProjectedDeadline();
    modal.style.display = 'flex';
}

function closePangkatExtensionModal() {
    const modal = document.getElementById('wsPangkatExtensionModal');
    if (modal) modal.style.display = 'none';
}

function toggleExtensionType(type) {
    const basisWrap = document.getElementById('extMeritoriousWrap');
    const noteEl = document.getElementById('extTypeNote');
    const daysInput = document.getElementById('extDays');

    if (type === 'Clearly Meritorious') {
        if (basisWrap) basisWrap.style.display = 'block';
        if (noteEl) noteEl.textContent = 'Under RA 7160 Sec. 410(c), extensions beyond 15 days require explicit clearly meritorious grounds (e.g. active boundary survey, pending court transmittal, extraordinary difficulty).';
    } else {
        if (basisWrap) basisWrap.style.display = 'none';
        if (noteEl) noteEl.textContent = 'Under RA 7160 Sec. 410(c), discretionary extension by the Pangkat cannot exceed fifteen (15) calendar days.';
        if (daysInput && Number(daysInput.value) > 15) daysInput.value = '15';
    }
    updateProjectedDeadline();
}

function updateProjectedDeadline() {
    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const dl = stage.deadline || {};
    const daysInput = document.getElementById('extDays');
    const projEl = document.getElementById('extProjectedDeadline');
    if (!daysInput || !projEl) return;

    const days = parseInt(daysInput.value, 10) || 0;
    const baseDateStr = dl.target_date || new Date().toISOString().split('T')[0];
    const baseDate = new Date(baseDateStr);
    baseDate.setDate(baseDate.getDate() + days);
    projEl.textContent = baseDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

async function submitPangkatExtension(e) {
    e.preventDefault();
    const btn = document.getElementById('submitExtBtn');
    if (btn) btn.disabled = true;

    const extType = document.querySelector('input[name="ext_type"]:checked')?.value || 'Discretionary';
    const days = parseInt(document.getElementById('extDays')?.value, 10) || 15;
    const justification = document.getElementById('extJustification')?.value || '';
    const meritoriousBasis = document.getElementById('extMeritoriousBasis')?.value || '';

    const payload = {
        case_id: stageWorkspaceState.caseId,
        extension_type: extType,
        days_extended: days,
        justification: justification,
        meritorious_basis: meritoriousBasis
    };

    try {
        const res = await fetch(getStageApiUrl('pangkat/extend.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closePangkatExtensionModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to grant extension.');
        }
    } catch (err) {
        console.error('submitPangkatExtension error:', err);
        alert('Network error while processing extension.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: File Challenge Against Member (RA 7160 §404d)
 * -----------------------------------------------------------------------------
 */
function openChallengeMemberModal(memberId, memberName) {
    const modal = document.getElementById('wsChallengeMemberModal');
    if (!modal) return;

    const select = document.getElementById('chalMemberId');
    if (select) {
        const stage = stageWorkspaceState.stageData?.conciliation || {};
        const members = stage.pangkat_assignment?.members || [];
        select.innerHTML = members.map(m => `
            <option value="${Number(m.member_id)}" ${memberId && Number(m.member_id) === Number(memberId) ? 'selected' : ''}>
                ${escapeHtml(m.member_name)} (${escapeHtml(m.position)})
            </option>
        `).join('');
    }

    const dateInput = document.getElementById('chalDate');
    if (dateInput && !dateInput.value) {
        dateInput.value = new Date().toISOString().split('T')[0];
    }

    modal.style.display = 'flex';
}

function closeChallengeMemberModal() {
    const modal = document.getElementById('wsChallengeMemberModal');
    if (modal) modal.style.display = 'none';
}

async function submitChallengeMember(e) {
    e.preventDefault();
    const btn = document.getElementById('submitChalBtn');
    if (btn) btn.disabled = true;

    const memberId = document.getElementById('chalMemberId')?.value;
    const party = document.getElementById('chalParty')?.value || 'Complainant';
    const name = document.getElementById('chalPartyName')?.value || '';
    const grounds = document.getElementById('chalGrounds')?.value || 'Bias or Partiality';
    const details = document.getElementById('chalDetails')?.value || '';
    const date = document.getElementById('chalDate')?.value || new Date().toISOString().split('T')[0];

    const payload = {
        case_id: stageWorkspaceState.caseId,
        challenged_member_id: Number(memberId),
        challenger_party: party,
        challenger_name: name,
        grounds: grounds,
        ground_details: details,
        challenge_date: date
    };

    try {
        const res = await fetch(getStageApiUrl('pangkat/challenge.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closeChallengeMemberModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to record challenge.');
        }
    } catch (err) {
        console.error('submitChallengeMember error:', err);
        alert('Network error while filing challenge.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Resolve Member Challenge (Pangkat Majority Vote)
 * -----------------------------------------------------------------------------
 */
function openResolveChallengeModal(challengeId) {
    const modal = document.getElementById('wsResolveChallengeModal');
    if (!modal) return;

    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const challenges = stage.pangkat_assignment?.challenges || [];
    const chal = challenges.find(c => Number(c.challenge_id) === Number(challengeId));

    if (!chal) {
        alert('Challenge record not found.');
        return;
    }

    document.getElementById('resChallengeId').value = challengeId;
    const summaryEl = document.getElementById('resChalSummary');
    if (summaryEl) {
        summaryEl.innerHTML = `
            <strong>Challenged Member:</strong> ${escapeHtml(chal.challenged_name)}<br>
            <strong>Challenger:</strong> ${escapeHtml(chal.challenger_party)} ${chal.challenger_name ? `(${escapeHtml(chal.challenger_name)})` : ''}<br>
            <strong>Grounds:</strong> ${escapeHtml(chal.grounds)}<br>
            <strong>Particulars:</strong> ${escapeHtml(chal.ground_details)}
        `;
    }

    // Populate replacement dropdown with Lupon Members excluding existing active members
    const repSelect = document.getElementById('resReplacementId');
    if (repSelect) {
        const activeIds = new Set((stage.pangkat_assignment?.members || []).map(m => Number(m.member_id)));
        const availableLupon = (stageWorkspaceState.luponMembers || []).filter(u => !activeIds.has(Number(u.user_id || u.member_id)));
        repSelect.innerHTML = '<option value="">-- Choose Replacement Lupon Member --</option>' +
            availableLupon.map(u => `
                <option value="${Number(u.user_id || u.member_id)}">
                    ${escapeHtml(u.last_name || '')}, ${escapeHtml(u.first_name || '')}
                </option>
            `).join('');
    }

    toggleDecisionReplacement('Disqualify and Replace');
    modal.style.display = 'flex';
}

function closeResolveChallengeModal() {
    const modal = document.getElementById('wsResolveChallengeModal');
    if (modal) modal.style.display = 'none';
}

function toggleDecisionReplacement(decision) {
    const wrap = document.getElementById('resReplacementWrap');
    if (wrap) {
        wrap.style.display = (decision === 'Disqualify and Replace') ? 'block' : 'none';
    }
}

async function submitResolveChallenge(e) {
    e.preventDefault();
    const btn = document.getElementById('submitResBtn');
    if (btn) btn.disabled = true;

    const challengeId = document.getElementById('resChallengeId')?.value;
    const decision = document.querySelector('input[name="res_decision"]:checked')?.value || 'Disqualify and Replace';
    const notes = document.getElementById('resNotes')?.value || '';
    const voting = document.getElementById('resVoting')?.value || 'Panel Majority Decision';
    const repId = document.getElementById('resReplacementId')?.value;
    const repMethod = document.getElementById('resRepMethod')?.value || 'Drawing of Lots';

    if (decision === 'Disqualify and Replace' && !repId) {
        alert('Please designate a replacement Lupon Member when sustaining a disqualification challenge.');
        if (btn) btn.disabled = false;
        return;
    }

    const payload = {
        challenge_id: Number(challengeId),
        majority_decision: decision,
        majority_decision_notes: notes,
        voting_record: voting,
        replacement_member_id: repId ? Number(repId) : null,
        replacement_method: repMethod,
        resolution_date: new Date().toISOString().split('T')[0]
    };

    try {
        const res = await fetch(getStageApiUrl('pangkat/resolve-challenge.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closeResolveChallengeModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to resolve challenge.');
        }
    } catch (err) {
        console.error('submitResolveChallenge error:', err);
        alert('Network error while resolving challenge.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Pangkat Selection & Impasse Modeling (RA 7160 §404)
 * -----------------------------------------------------------------------------
 */
function openPangkatSelectionModal() {
    const modal = document.getElementById('wsPangkatSelectionModal');
    if (!modal) return;

    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const pInfo = stage.pangkat_assignment || {};

    populateSelectionDropdowns();

    const failedCheck = document.getElementById('selAgreementFailed');
    if (failedCheck) {
        failedCheck.checked = !!pInfo.party_agreement_failed;
        togglePartyAgreementFailed(failedCheck);
    }

    const reasonEl = document.getElementById('selFailureReason');
    if (reasonEl) reasonEl.value = pInfo.party_agreement_failure_reason || '';

    const notesEl = document.getElementById('selNotes');
    if (notesEl) notesEl.value = pInfo.selection_notes || '';

    modal.style.display = 'flex';
}

function closePangkatSelectionModal() {
    const modal = document.getElementById('wsPangkatSelectionModal');
    if (modal) modal.style.display = 'none';
}

function togglePartyAgreementFailed(checkbox) {
    const wrap = document.getElementById('selFailureWrap');
    if (wrap) {
        wrap.style.display = checkbox.checked ? 'block' : 'none';
    }
}

function populateSelectionDropdowns() {
    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const pInfo = stage.pangkat_assignment || {};
    const lupon = stageWorkspaceState.luponMembers || [];

    const selects = [
        { id: 'selChairmanId', current: pInfo.chairperson?.member_id },
        { id: 'selSecretaryId', current: pInfo.secretary?.member_id },
        { id: 'selMemberId', current: pInfo.member?.member_id }
    ];

    selects.forEach(({ id, current }) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = '<option value="">-- Select Lupon Member --</option>' +
            lupon.map(m => `
                <option value="${Number(m.user_id || m.member_id)}" ${current && Number(m.user_id || m.member_id) === Number(current) ? 'selected' : ''}>
                    ${escapeHtml(m.last_name || '')}, ${escapeHtml(m.first_name || '')}
                </option>
            `).join('');
    });
}

async function submitPangkatSelection(e) {
    e.preventDefault();
    const btn = document.getElementById('submitSelBtn');
    if (btn) btn.disabled = true;

    const chairmanId = document.getElementById('selChairmanId')?.value;
    const secretaryId = document.getElementById('selSecretaryId')?.value;
    const memberId = document.getElementById('selMemberId')?.value;

    if (!chairmanId || !secretaryId || !memberId) {
        alert('Please select all 3 panel positions (Chairman, Secretary, and Member).');
        if (btn) btn.disabled = false;
        return;
    }

    if (new Set([chairmanId, secretaryId, memberId]).size !== 3) {
        alert('The 3 panel members must all be distinct Lupon members.');
        if (btn) btn.disabled = false;
        return;
    }

    const failedAgreement = document.getElementById('selAgreementFailed')?.checked ? 1 : 0;
    const pbMethod = document.getElementById('selPbMethod')?.value || 'Drawing of Lots';
    const method = failedAgreement ? (pbMethod === 'Drawing of Lots' ? 'Raffle Draw' : 'PB Assignment') : 'Party Agreement';

    const payload = {
        case_id: stageWorkspaceState.caseId,
        selection_method: method,
        selection_stage: failedAgreement ? (pbMethod === 'Drawing of Lots' ? 'Failed Agreement - PB Lots' : 'Failed Agreement - PB Appointment') : 'Party Choice',
        party_agreement_failed: failedAgreement,
        party_agreement_failure_reason: document.getElementById('selFailureReason')?.value || '',
        selection_notes: document.getElementById('selNotes')?.value || '',
        chairman_id: Number(chairmanId),
        secretary_id: Number(secretaryId),
        member_id: Number(memberId),
        formation_date: new Date().toISOString().split('T')[0]
    };

    try {
        const res = await fetch(getStageApiUrl('pangkat/selection.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closePangkatSelectionModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to save Pangkat selection.');
        }
    } catch (err) {
        console.error('submitPangkatSelection error:', err);
        alert('Network error while saving selection.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// Global window exposure for inline and modal DOM events
window.toggleStageVoiceRecording = toggleStageVoiceRecording;
window.triggerStageNotesUpload = triggerStageNotesUpload;
window.handleStageNotesFileSelected = handleStageNotesFileSelected;
window.openPreviewMinutesModal = openPreviewMinutesModal;
window.closePreviewMinutesModal = closePreviewMinutesModal;
window.printHearingMinutes = printHearingMinutes;
window.openScheduleModal = openScheduleModal;
window.closeScheduleModal = closeScheduleModal;
window.initWorkspaceScheduleModal = initWorkspaceScheduleModal;
window.loadStageSlots = loadStageSlots;
window.openAttendanceModal = openAttendanceModal;
window.closeAttendanceModal = closeAttendanceModal;
window.openMinutesModal = openMinutesModal;
window.closeMinutesModal = closeMinutesModal;
window.saveMinutes = saveMinutes;
window.saveHearingMinutes = saveHearingMinutes;
window.saveInlineMinutes = saveHearingMinutes;
window.openRecordOutcomeModal = openRecordOutcomeModal;
window.closeRecordOutcomeModal = closeRecordOutcomeModal;
window.openReferPangkatModal = openReferPangkatModal;
window.closeReferPangkatModal = closeReferPangkatModal;

// Pangkat statutory extensions and challenges exposure
window.openPangkatExtensionModal = openPangkatExtensionModal;
window.closePangkatExtensionModal = closePangkatExtensionModal;
window.toggleExtensionType = toggleExtensionType;
window.updateProjectedDeadline = updateProjectedDeadline;
window.submitPangkatExtension = submitPangkatExtension;
window.openChallengeMemberModal = openChallengeMemberModal;
window.closeChallengeMemberModal = closeChallengeMemberModal;
window.submitChallengeMember = submitChallengeMember;
window.openResolveChallengeModal = openResolveChallengeModal;
window.closeResolveChallengeModal = closeResolveChallengeModal;
window.toggleDecisionReplacement = toggleDecisionReplacement;
window.submitResolveChallenge = submitResolveChallenge;
window.openPangkatSelectionModal = openPangkatSelectionModal;
window.closePangkatSelectionModal = closePangkatSelectionModal;
window.togglePartyAgreementFailed = togglePartyAgreementFailed;
window.submitPangkatSelection = submitPangkatSelection;

// Attendance & Form preview exposure
window.openKPFormPreview = openKPFormPreview;
window.togglePresiderSubstituteSection = togglePresiderSubstituteSection;
window.handlePresiderAbsenceChoiceChange = handlePresiderAbsenceChoiceChange;
window.executeEmergencyReschedule = executeEmergencyReschedule;
window.getSuggestedRescheduleDateTime = getSuggestedRescheduleDateTime;
window.submitAttendance = submitAttendance;

// ========================================================
// COURT & DILG TRANSMITTAL MODULE (RA 7160 SEC. 411)
// ========================================================

async function loadTransmittalCard(caseId) {
    const container = document.getElementById('wsTransmittalCardContainer');
    if (!container) return;

    try {
        const res = await fetch(`../../../backend/api/transmittals/status.php?case_id=${encodeURIComponent(caseId)}`);
        const json = await res.json();
        if (!res.ok || !json.success) {
            container.innerHTML = `
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; font-size: 0.84rem; color: #64748b;">
                    Unable to load Court Transmittal status.
                </div>
            `;
            return;
        }

        const data = json.data || {};
        stageWorkspaceState.transmittalData = data;

        let badgeStyle = 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;';
        if (data.status === 'Transmitted') {
            badgeStyle = 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;';
        } else if (data.status === 'Acknowledged') {
            badgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;';
        } else if (data.is_repudiation_window_open) {
            badgeStyle = 'background: #fef3c7; color: #92400e; border: 1px solid #fde68a;';
        } else {
            badgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;';
        }

        const receivingCopyHtml = data.receiving_copy_path ? `
            <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #cbd5e1; font-size: 0.82rem; color: #1e40af;">
                <strong>Stamped Receiving Copy on File:</strong> 
                <a href="../../../${escapeHtml(data.receiving_copy_path)}" target="_blank" style="color: #0369a1; text-decoration: underline; font-weight: 600;">
                    View Stamped Court / DILG Copy &rarr;
                </a>
            </div>
        ` : '';

        const receiverDetailsHtml = (data.status === 'Transmitted' || data.status === 'Acknowledged') ? `
            <div style="margin-top: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; font-size: 0.82rem; color: #334155; line-height: 1.55;">
                <div style="margin-bottom: 2px;">
                    <strong>Transmitted on:</strong> ${escapeHtml(data.transmittal_date || 'Logged')} &bull; 
                    <strong>Target:</strong> ${escapeHtml(data.target_agency_label || 'Both MTC & DILG')}
                </div>
                ${data.mtc_receiver_name ? `<div><strong>MTCC Receiver:</strong> ${escapeHtml(data.mtc_receiver_name)} ${data.mtc_received_date ? `(Received: ${escapeHtml(data.mtc_received_date)})` : ''}</div>` : ''}
                ${data.dilg_receiver_name ? `<div><strong>DILG Receiver:</strong> ${escapeHtml(data.dilg_receiver_name)} ${data.dilg_received_date ? `(Received: ${escapeHtml(data.dilg_received_date)})` : ''}</div>` : ''}
                ${data.remarks ? `<div style="margin-top: 3px; color: #64748b; font-style: italic;">"${escapeHtml(data.remarks)}"</div>` : ''}
            </div>
        ` : '';

        container.innerHTML = `
            <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 18px; margin-top: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <h4 style="margin: 0; font-size: 0.98rem; color: #0f172a; font-weight: 700;">
                                MTC &amp; DILG Court Transmittal Module
                            </h4>
                            <span style="font-size: 0.76rem; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; font-weight: 600;">
                                Ref: ${escapeHtml(data.transmittal_number || 'TR')}
                            </span>
                        </div>
                        <p style="margin: 3px 0 0; font-size: 0.8rem; color: #64748b;">
                            Statutory transmittal to Clerk of Court (MTCC Marikina) and DILG Field Office pursuant to RA 7160 Sec. 411.
                        </p>
                    </div>
                    <div>
                        <span style="display: inline-block; padding: 4px 10px; border-radius: 9999px; font-weight: 700; font-size: 0.78rem; ${badgeStyle}">
                            ${escapeHtml(data.badge_label || data.status)}
                        </span>
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; font-size: 0.84rem; color: #334155; line-height: 1.55;">
                    <div>
                        Settlement signed on <strong>${escapeHtml(data.settlement_date_formatted || data.settlement_date)}</strong>. 
                        10-day working period ends on <strong>${escapeHtml(data.ten_day_expiry_date_formatted || data.ten_day_expiry_date)}</strong> 
                        <span style="color: #64748b;">(excluding weekends &amp; statutory Philippine holidays)</span>.
                    </div>
                    ${data.is_repudiation_window_open ? `
                        <div style="margin-top: 5px; color: #b45309; font-weight: 600;">
                            Repudiation countdown: ${data.working_days_remaining} working day${data.working_days_remaining === 1 ? '' : 's'} remaining before formal transmittal is prescribed.
                        </div>
                    ` : `
                        <div style="margin-top: 5px; color: #15803d; font-weight: 600;">
                            10 working days elapsed with no repudiation filed. Amicable settlement now carries the force and effect of a final court judgment under Section 411.
                        </div>
                    `}
                </div>

                ${receiverDetailsHtml}
                ${receivingCopyHtml}

                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" onclick="openPreviewTransmittalModal(${Number(caseId)})" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                        Preview / Print Transmittal Letter
                    </button>
                    <button type="button" class="btn-create" onclick="openRecordTransmittalModal(${Number(caseId)})" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; background: #0f766e;">
                        Record Court / DILG Submission
                    </button>
                </div>
            </div>
        `;
    } catch (err) {
        console.error('Failed to load transmittal card:', err);
    }
}

function openPreviewTransmittalModal(caseId) {
    const modal = document.getElementById('previewTransmittalModal');
    const iframe = document.getElementById('transmittalLetterPreviewIframe');
    const downloadBtn = document.getElementById('btnDownloadTransmittalPdf');
    if (!modal) return;

    if (iframe) {
        iframe.src = `../../../backend/api/transmittals/preview.php?case_id=${encodeURIComponent(caseId)}&html=1`;
    }
    if (downloadBtn) {
        downloadBtn.href = `../../../backend/api/transmittals/preview.php?case_id=${encodeURIComponent(caseId)}&download=1`;
    }

    modal.style.display = 'flex';
}

function closePreviewTransmittalModal() {
    const modal = document.getElementById('previewTransmittalModal');
    if (modal) modal.style.display = 'none';
    const iframe = document.getElementById('transmittalLetterPreviewIframe');
    if (iframe) iframe.src = 'about:blank';
}

function printTransmittalIframe() {
    const iframe = document.getElementById('transmittalLetterPreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}

function openRecordTransmittalModal(caseId) {
    const modal = document.getElementById('recordTransmittalModal');
    if (!modal) return;

    const caseIdInput = document.getElementById('transmittalCaseId');
    if (caseIdInput) caseIdInput.value = caseId;

    const data = stageWorkspaceState.transmittalData || {};
    const alertBox = document.getElementById('transmittalModalRepudiationAlert');
    if (alertBox) {
        if (data.is_repudiation_window_open) {
            alertBox.innerHTML = `
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 12px; font-size: 0.82rem; color: #92400e; line-height: 1.45;">
                    <strong>Notice (RA 7160 Sec. 411):</strong> The 10-working-day repudiation window is currently active 
                    (<strong>${data.working_days_remaining} working day${data.working_days_remaining === 1 ? '' : 's'} remaining</strong> until ${data.ten_day_expiry_date_formatted}). 
                    Formal transmittal is legally prescribed upon lapse of this period without repudiation.
                </div>
            `;
            alertBox.style.display = 'block';
        } else {
            alertBox.innerHTML = `
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 12px; font-size: 0.82rem; color: #166534; line-height: 1.45;">
                    <strong>Statutory Repudiation Window Elapsed:</strong> No repudiation has been recorded. This settlement carries the force and effect of a final court judgment and is ready for court &amp; DILG transmittal.
                </div>
            `;
            alertBox.style.display = 'block';
        }
    }

    const dateInput = document.getElementById('transmittalDate');
    if (dateInput) dateInput.value = data.transmittal_date || new Date().toISOString().slice(0, 10);

    const agencySelect = document.getElementById('transmittalTargetAgency');
    if (agencySelect) agencySelect.value = data.target_agency || 'Both';

    const mtcReceiver = document.getElementById('transmittalMtcReceiver');
    if (mtcReceiver) mtcReceiver.value = data.mtc_receiver_name || '';

    const mtcDate = document.getElementById('transmittalMtcDate');
    if (mtcDate) mtcDate.value = data.mtc_received_date || (data.transmittal_date || new Date().toISOString().slice(0, 10));

    const dilgReceiver = document.getElementById('transmittalDilgReceiver');
    if (dilgReceiver) dilgReceiver.value = data.dilg_receiver_name || '';

    const dilgDate = document.getElementById('transmittalDilgDate');
    if (dilgDate) dilgDate.value = data.dilg_received_date || (data.transmittal_date || new Date().toISOString().slice(0, 10));

    const remarksText = document.getElementById('transmittalRemarks');
    if (remarksText) remarksText.value = data.remarks || '';

    const badge = document.getElementById('transmittalCurrentFileBadge');
    if (badge) {
        if (data.receiving_copy_path) {
            badge.innerHTML = `Current Stamped File: <a href="../../../${escapeHtml(data.receiving_copy_path)}" target="_blank" style="text-decoration: underline;">View Uploaded Copy</a>`;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    }

    modal.style.display = 'flex';
}

function closeRecordTransmittalModal() {
    const modal = document.getElementById('recordTransmittalModal');
    if (modal) modal.style.display = 'none';
}

async function handleRecordTransmittalSubmit(event) {
    event.preventDefault();
    const form = document.getElementById('recordTransmittalForm');
    const submitBtn = document.getElementById('btnSubmitTransmittal');
    if (!form) return;

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
    }

    const formData = new FormData(form);

    try {
        const res = await fetch('../../../backend/api/transmittals/submit.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Failed to record transmittal.');
        }

        notifyUser(json.message || 'Court & DILG transmittal recorded successfully!', 'success');
        closeRecordTransmittalModal();
        if (stageWorkspaceState.caseId) {
            loadTransmittalCard(stageWorkspaceState.caseId);
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Transmittal Record';
        }
    }
}

window.loadTransmittalCard = loadTransmittalCard;
window.openPreviewTransmittalModal = openPreviewTransmittalModal;
window.closePreviewTransmittalModal = closePreviewTransmittalModal;
window.printTransmittalIframe = printTransmittalIframe;
window.openRecordTransmittalModal = openRecordTransmittalModal;
window.closeRecordTransmittalModal = closeRecordTransmittalModal;
window.handleRecordTransmittalSubmit = handleRecordTransmittalSubmit;

