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
                        <button type="button" class="btn-secondary" onclick="loadStageData()" style="font-size: 0.8rem; padding: 5px 12px; cursor: pointer;">🔄 Retry Loading</button>
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
            opts += `<option value="${m.member_id}">${escapeHtml(name)}</option>`;
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
            openMinutesModal(Number(hearingIdParam));
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
            if (firstSession) openMinutesModal(firstSession.hearing_id);
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
}

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
        const timer = caseData.mediation_timer;
        deadlineBadgeHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">15-Day Mediation Limit</span>
                <span class="badge-timer ${escapeHtml(timer.badge_class || '')}" style="font-size: 0.82rem; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; font-weight: 700; margin-top: 4px;">
                    ${escapeHtml(timer.badge_label || '')}
                </span>
                <span class="stat-sub">Limit: ${formatDateReadable(timer.mediation_deadline_date)}</span>
            </div>
        `;
    } else if (mode === 'Conciliation' && stage.deadline) {
        deadlineBadgeHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">15-Day Conciliation Limit</span>
                <strong class="stat-value" style="font-size: 0.9rem; color: #1e40af;">Due: ${formatDateReadable(stage.deadline.target_date)}</strong>
                <span class="stat-sub">15 calendar days from constitution</span>
            </div>
        `;
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
                    📅 Schedule ${escapeHtml(stageWorkspaceState.mode)} Hearing &rarr;
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

        cardsHtml += `
            <div class="session-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; padding: 2px 7px; border-radius: 4px; background: #e0f2fe; color: #0369a1;">
                                #${s.session_index}
                            </span>
                            <h4 style="margin: 0; font-size: 1.05rem; color: #0f172a; font-weight: 700;">
                                ${escapeHtml(s.session_number)}
                            </h4>
                            <span class="status-pill status-${escapeHtml(s.status.toLowerCase())}" style="font-size: 0.74rem; padding: 2px 8px; border-radius: 4px; font-weight: 700;">
                                ${escapeHtml(s.status)}
                            </span>
                        </div>
                        <div style="font-size: 0.84rem; color: #475569; display: flex; gap: 14px; flex-wrap: wrap;">
                            <span>📅 <strong>${formatDateTimeReadable(s.hearing_date)}</strong></span>
                            <span>📍 Venue: <strong>${escapeHtml(s.venue || 'Barangay Hall')}</strong></span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openAttendanceModal(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;">
                            👤 Record Attendance
                        </button>
                        <button type="button" class="btn-secondary" onclick="selectInlineMinutesSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px; ${isFinalized ? 'border-color: #86efac; background: #f0fdf4;' : ''}">
                            📝 ${isFinalized ? 'View Finalized Minutes' : (hasMinutes ? 'Edit Minutes Draft' : 'Add Minutes')}
                        </button>
                    </div>
                </div>

                <!-- Session Sub-Grid: Proof of Service & Attendance & Minutes Status -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.82rem;">
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">PROOF OF SERVICE</span>
                        <div style="margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 0.76rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; ${s.proof_of_service_status === 'Served' ? 'background: #dcfce7; color: #15803d;' : 'background: #fef3c7; color: #b45309;'}">
                                ${s.proof_of_service_status === 'Served' ? '✓ Notice Served' : '⏳ Pending / Unserved'}
                            </span>
                        </div>
                    </div>
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">ATTENDANCE RECORD</span>
                        <div style="margin-top: 3px; color: #334155;">
                            Complainant: <strong>${escapeHtml(s.complainant_attendance || 'Pending')}</strong> · Respondent: <strong>${escapeHtml(s.respondent_attendance || 'Pending')}</strong>
                        </div>
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

/**
 * Render Hearing Session Minutes & Settlement Progress Section
 */
function renderMinutesSection(data, stage) {
    const minContainer = document.getElementById('wsMinutesSectionContainer');
    if (!minContainer) return;

    const sessions = stageWorkspaceState.sessions || [];
    const mode = stageWorkspaceState.mode;

    // 1. Calculate Overall Settlement & Negotiation Trajectory
    let trajectoryStatus = 'Exploring Dispute Resolution';
    let trajectoryBadgeStyle = 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;';

    const hasSettled = sessions.some(s => s.session_outcome === 'Settled' || s.outcome === 'Settled');
    const hasAward = sessions.some(s => s.session_outcome === 'Arbitration Award' || s.outcome === 'Arbitration Award');
    const hasFailed = sessions.some(s => ['Failed', 'Elevate to Pangkat', 'Pending CFA', 'Unsuccessful'].includes(s.session_outcome));
    const latestProposal = [...sessions].reverse().find(s => s.new_proposal?.trim())?.new_proposal;
    const latestCounteroffer = [...sessions].reverse().find(s => s.counteroffer?.trim())?.counteroffer;
    const latestDispute = [...sessions].reverse().find(s => s.main_dispute_identified?.trim())?.main_dispute_identified;
    const anyCaucus = sessions.some(s => Boolean(s.caucus_conducted));

    if (hasSettled || stage.outcome === 'Settled') {
        trajectoryStatus = '✓ Amicable Settlement Reached';
        trajectoryBadgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;';
    } else if (hasAward || stage.outcome === 'Arbitration Award') {
        trajectoryStatus = '⚖️ Arbitration Award Rendered';
        trajectoryBadgeStyle = 'background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;';
    } else if (hasFailed || stage.outcome === 'Unsuccessful') {
        trajectoryStatus = mode === 'Mediation' ? '⚠️ Impasse · Elevated to Pangkat' : '⚠️ Impasse · Eligible for CFA';
        trajectoryBadgeStyle = 'background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;';
    } else if (latestProposal && latestCounteroffer) {
        trajectoryStatus = '🤝 Active Proposals & Counteroffers';
        trajectoryBadgeStyle = 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;';
    } else if (latestProposal) {
        trajectoryStatus = '📄 Proposal Under Party Review';
        trajectoryBadgeStyle = 'background: #eff6ff; color: #2563eb; border: 1px solid #dbeafe;';
    } else if (anyCaucus) {
        trajectoryStatus = '🔒 Private Caucus Held · Exploring Options';
        trajectoryBadgeStyle = 'background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff;';
    }

    // 2. Settlement Progress & Negotiations Tracker Panel
    let trackerHtml = `
        <div class="settlement-tracker-panel" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #e2e8f0;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 1.15rem;">📊</span>
                    <div>
                        <strong style="font-size: 0.95rem; color: #0f172a;">Settlement Progress &amp; Negotiations Tracker</strong>
                        <span style="display: block; font-size: 0.78rem; color: #64748b;">Stage-specific trajectory of proposals, counteroffers, caucus, and resolution</span>
                    </div>
                </div>
                <div>
                    <span style="font-size: 0.82rem; font-weight: 700; padding: 4px 10px; border-radius: 9999px; ${trajectoryBadgeStyle}">
                        ${escapeHtml(trajectoryStatus)}
                    </span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px;">
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                    <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; display: block; margin-bottom: 4px;">MAIN DISPUTE FOCUS</span>
                    <p style="margin: 0; font-size: 0.84rem; color: #1e293b; line-height: 1.4;">
                        ${latestDispute ? escapeHtml(latestDispute) : '<em style="color: #94a3b8;">No core dispute summary documented yet.</em>'}
                    </p>
                </div>
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                    <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; display: block; margin-bottom: 4px;">LATEST SETTLEMENT PROPOSAL / OFFER</span>
                    <p style="margin: 0; font-size: 0.84rem; color: #1e293b; line-height: 1.4;">
                        ${latestProposal ? escapeHtml(latestProposal) : '<em style="color: #94a3b8;">No proposal submitted yet.</em>'}
                    </p>
                </div>
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                    <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; display: block; margin-bottom: 4px;">COUNTEROFFER / RESPONSE</span>
                    <p style="margin: 0; font-size: 0.84rem; color: #1e293b; line-height: 1.4;">
                        ${latestCounteroffer ? escapeHtml(latestCounteroffer) : '<em style="color: #94a3b8;">No counteroffer recorded yet.</em>'}
                    </p>
                </div>
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                    <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; display: block; margin-bottom: 4px;">CAUCUS &amp; PROTOCOL STATUS</span>
                    <div style="font-size: 0.82rem; color: #334155; line-height: 1.4;">
                        <div>Private Caucus: <strong>${anyCaucus ? '✓ Conducted' : 'None Recorded'}</strong></div>
                        <div style="margin-top: 3px;">Sessions in Stage: <strong>${sessions.length}</strong> · Minutes Logged: <strong>${sessions.filter(s => s.minutes_id).length}</strong></div>
                    </div>
                </div>
            </div>
        </div>
    `;

    // 3. If no sessions scheduled yet, show empty state
    if (!sessions.length) {
        minContainer.innerHTML = trackerHtml + `
            <div style="text-align: center; padding: 36px 20px; color: #64748b; background: #fff; border: 1px dashed #cbd5e1; border-radius: 10px;">
                <p style="margin: 0 0 10px; font-size: 0.95rem; color: #475569;">No ${escapeHtml(mode.toLowerCase())} sessions scheduled yet.</p>
                <small style="display: block; margin-bottom: 14px; color: #94a3b8;">Schedule a session first to record hearing minutes, statements, and settlement progress.</small>
                <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.84rem; padding: 7px 16px;">
                    📅 Schedule ${escapeHtml(mode)} Hearing &rarr;
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

    // Stage-adapted outcome options
    let outcomeOptions = '';
    const curOutcome = activeSession.session_outcome || 'Pending';
    if (mode === 'Mediation') {
        const opts = [
            { val: 'Continue Mediation', label: 'Continue Mediation (Next Session)' },
            { val: 'Settled', label: 'Settled (Amicable Settlement Reached)' },
            { val: 'Elevate to Pangkat', label: 'Elevate to Pangkat ng Tagapagkasundo (KP Form 10)' },
            { val: 'Party Absent', label: 'Party Absent / Issue Show Cause Notice' },
            { val: 'Rescheduled', label: 'Rescheduled — Session Reset Upon Request' },
            { val: 'Failed', label: 'Failed — Mediation Unsuccessful / Impasse' },
            { val: 'Pending', label: 'Pending Evaluation' }
        ];
        outcomeOptions = opts.map(o => `<option value="${o.val}" ${curOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    } else if (mode === 'Conciliation') {
        const opts = [
            { val: 'Continue Conciliation', label: 'Continue Conciliation (Next Session)' },
            { val: 'Settled', label: 'Settled (Amicable Settlement Reached)' },
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
            <button type="button" onclick="selectInlineMinutesSession(${s.hearing_id})" style="border: 2px solid ${isSelected ? '#2563eb' : '#e2e8f0'}; background: ${isSelected ? '#eff6ff' : '#fff'}; border-radius: 8px; padding: 8px 14px; cursor: pointer; text-align: left; display: inline-flex; align-items: center; gap: 8px; font-family: inherit; transition: all 0.15s ease;">
                <div>
                    <strong style="display: block; font-size: 0.84rem; color: ${isSelected ? '#1e40af' : '#0f172a'};">#${s.session_index} ${escapeHtml(s.session_number)}</strong>
                    <small style="color: #64748b; font-size: 0.74rem;">${formatDateReadable(s.hearing_date)}</small>
                </div>
                <span style="font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; font-weight: 700; background: ${badgeBg}; color: ${badgeColor};">
                    ${escapeHtml(s.minutes_status)}
                </span>
            </button>
        `;
    });

    // 4. Build Form exactly matching Images 2 & 3
    let formHtml = `
        <!-- Session Selector Tabs Card -->
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9;">
                <div>
                    <strong style="font-size: 0.92rem; color: #0f172a;">Select Hearing Session:</strong>
                    <span style="font-size: 0.78rem; color: #64748b; margin-left: 6px;">Manage minutes, statements, and proposals for the selected session</span>
                </div>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <button type="button" class="btn-secondary" onclick="openScheduleModal()" style="font-size: 0.76rem; padding: 3px 8px;">+ New Session</button>
                </div>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                ${sessionTabsHtml}
            </div>
        </div>

        <!-- The Hearing Session Minutes & Settlement Progress Form Card -->
        <div class="inline-minutes-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
            <!-- Card Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid #e2e8f0;">
                <div>
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #0f172a;">
                        Hearing Session Minutes &amp; Settlement Progress
                    </h3>
                    <p style="margin: 3px 0 0; font-size: 0.84rem; color: #64748b;">
                        Record formal proceedings, statements, proposals, and session outcome for <strong>${escapeHtml(activeSession.session_number)}</strong> (${formatDateTimeReadable(activeSession.hearing_date)}).
                    </p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <span style="font-size: 0.78rem; padding: 4px 10px; border-radius: 9999px; font-weight: 700; ${isFinalized ? 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;' : 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;'}">
                        ${isFinalized ? '✓ FINALIZED &amp; LOCKED' : '📝 WORKING DRAFT'}
                    </span>
                    <button type="button" class="btn-secondary" onclick="openMinutesModal(${activeSession.hearing_id})" style="font-size: 0.78rem; padding: 4px 10px;" title="Open in pop-up modal">
                        ⤢ Fullscreen Modal
                    </button>
                </div>
            </div>

            <!-- Lock Notice if Finalized -->
            ${isFinalized ? `
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #166534; font-size: 0.86rem;">
                        <strong>🔒 Official Record Locked:</strong> This session minutes was finalized${activeSession.finalized_at ? ` on ${formatDateTimeReadable(activeSession.finalized_at)}` : ''}${activeSession.finalized_by_name ? ` by ${escapeHtml(activeSession.finalized_by_name)}` : ''}. To preserve legal integrity, finalized minutes cannot be modified.
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn-secondary" onclick="openPreviewMinutesModal(${activeSession.hearing_id})" style="font-size: 0.78rem; padding: 4px 10px;">🔍 Preview</button>
                        <button type="button" class="btn-create" onclick="printHearingMinutes(${activeSession.hearing_id})" style="font-size: 0.78rem; padding: 4px 12px; background: #0369a1;">🖨️ Print Letterhead</button>
                    </div>
                </div>
            ` : ''}

            <!-- Form -->
            <form id="inlineMinutesForm" onsubmit="event.preventDefault();">
                <input type="hidden" id="inlineHearingId" value="${activeSession.hearing_id}">

                <!-- Section 1: Session Opening & Identity Verification -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                    <h4 style="font-size: 0.88rem; font-weight: 700; color: #1e293b; margin: 0 0 10px; display: flex; align-items: center; gap: 6px;">
                        <span>📝</span> Session Opening &amp; Identity Verification
                    </h4>
                    <div style="display: flex; gap: 24px; flex-wrap: wrap; font-size: 0.85rem; color: #334155;">
                        <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; user-select: none;">
                            <input type="checkbox" id="inlineOpening" ${activeSession.opening_conducted ? 'checked' : ''} ${readonlyAttr} style="width: 16px; height: 16px;">
                            <span>Opening Statement Conducted</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; user-select: none;">
                            <input type="checkbox" id="inlineIdentity" ${activeSession.identity_verified ? 'checked' : ''} ${readonlyAttr} style="width: 16px; height: 16px;">
                            <span>Parties Identity Verified</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; user-select: none;">
                            <input type="checkbox" id="inlineReviewed" ${activeSession.complaint_reviewed ? 'checked' : ''} ${readonlyAttr} style="width: 16px; height: 16px;">
                            <span>Complaint Reviewed &amp; Read</span>
                        </label>
                    </div>
                </div>

                <!-- Section 2: Statements & Core Dispute -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="inlineCompStmt" style="font-weight: 600; font-size: 0.84rem; color: #1e293b; margin-bottom: 4px; display: block;">Complainant Statement Summary</label>
                        <textarea id="inlineCompStmt" rows="3" maxlength="5000" placeholder="Summary of complainant's narration of facts..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px; font-size: 0.84rem; font-family: inherit; box-sizing: border-box; line-height: 1.45;" ${readonlyAttr}>${escapeHtml(activeSession.complainant_statement || '')}</textarea>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="inlineRespStmt" style="font-weight: 600; font-size: 0.84rem; color: #1e293b; margin-bottom: 4px; display: block;">Respondent Statement Summary</label>
                        <textarea id="inlineRespStmt" rows="3" maxlength="5000" placeholder="Summary of respondent's explanation or counter-statement..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px; font-size: 0.84rem; font-family: inherit; box-sizing: border-box; line-height: 1.45;" ${readonlyAttr}>${escapeHtml(activeSession.respondent_statement || '')}</textarea>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label for="inlineDisputeSummary" style="font-weight: 600; font-size: 0.84rem; color: #1e293b; margin-bottom: 4px; display: block;">Main Dispute Identified</label>
                    <input type="text" id="inlineDisputeSummary" maxlength="1000" placeholder="Core issue (e.g., boundary encroachment, unpaid loan balance, noise disturbance)" value="${escapeHtml(activeSession.main_dispute_identified || '')}" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 0.84rem; box-sizing: border-box;" ${readonlyAttr}>
                </div>

                <!-- Section 3: Proposals & Caucus (Signature Light Blue Box) -->
                <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 16px 18px; margin-bottom: 16px;">
                    <h4 style="font-size: 0.9rem; font-weight: 700; color: #0369a1; margin: 0 0 12px; display: flex; align-items: center; gap: 6px;">
                        <span>🛡️</span> Negotiation, Proposals &amp; Caucus
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 10px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="inlineNewProposal" style="font-weight: 600; font-size: 0.82rem; color: #0c4a6e; margin-bottom: 4px; display: block;">Settlement Proposal / Offer</label>
                            <textarea id="inlineNewProposal" rows="2" maxlength="3000" placeholder="Terms proposed by offering party..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 0.84rem; font-family: inherit; box-sizing: border-box; line-height: 1.4;" ${readonlyAttr}>${escapeHtml(activeSession.new_proposal || '')}</textarea>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="inlineCounterOffer" style="font-weight: 600; font-size: 0.82rem; color: #0c4a6e; margin-bottom: 4px; display: block;">Counteroffer / Response</label>
                            <textarea id="inlineCounterOffer" rows="2" maxlength="3000" placeholder="Counter-proposal or adjustments..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 0.84rem; font-family: inherit; box-sizing: border-box; line-height: 1.4;" ${readonlyAttr}>${escapeHtml(activeSession.counteroffer || '')}</textarea>
                        </div>
                    </div>
                    <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; margin-top: 4px; color: #0369a1; font-weight: 500;">
                        <input type="checkbox" id="inlineCaucus" ${activeSession.caucus_conducted ? 'checked' : ''} ${readonlyAttr} onchange="document.getElementById('inlineCaucusNotesWrap').style.display = this.checked ? 'block' : 'none';" style="width: 16px; height: 16px;">
                        <span>Private Caucus Conducted (separate meeting held with one party)</span>
                    </label>
                    <div id="inlineCaucusNotesWrap" style="${activeSession.caucus_conducted ? '' : 'display: none;'} margin-top: 10px;">
                        <label for="inlineCaucusNotes" style="font-size: 0.78rem; font-weight: 600; color: #0369a1; display: block; margin-bottom: 3px;">CONFIDENTIAL CAUCUS NOTES</label>
                        <textarea id="inlineCaucusNotes" rows="2" placeholder="Confidential mediator insights, party reservations, or caucus concessions..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 0.84rem; font-family: inherit; box-sizing: border-box;" ${readonlyAttr}>${escapeHtml(activeSession.caucus_notes || '')}</textarea>
                    </div>
                </div>

                <!-- Section 4: Session Outcome and Timing -->
                <div style="display: grid; grid-template-columns: 1.2fr 1fr 1.2fr; gap: 14px; margin-bottom: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="inlineOutcome" style="font-weight: 600; font-size: 0.84rem; color: #1e293b; margin-bottom: 4px; display: block;">Session Outcome <span class="required-mark" style="color: #dc2626;">*</span></label>
                        <select id="inlineOutcome" ${readonlyAttr} required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px; font-size: 0.84rem; box-sizing: border-box; background: #fff;">
                            ${outcomeOptions}
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="inlineActualEndTime" style="font-weight: 600; font-size: 0.84rem; color: #1e293b; margin-bottom: 4px; display: block;">Actual Session End Time</label>
                        <input type="datetime-local" id="inlineActualEndTime" value="${activeSession.actual_end_time ? activeSession.actual_end_time.replace(' ', 'T').substring(0, 16) : ''}" ${readonlyAttr} style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 0.84rem; box-sizing: border-box;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="inlineDurationNotes" style="font-weight: 600; font-size: 0.84rem; color: #1e293b; margin-bottom: 4px; display: block;">Duration Notes / Remarks</label>
                        <input type="text" id="inlineDurationNotes" maxlength="1000" placeholder="Notes if session ran over standard duration" value="${escapeHtml(activeSession.outcome_remarks || '')}" ${readonlyAttr} style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 12px; font-size: 0.84rem; box-sizing: border-box;">
                    </div>
                </div>

                <!-- Section 5: Formal Session Minutes / Minutes Record -->
                <div class="form-group" style="margin-bottom: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 8px;">
                        <label for="inlineSessionMinutes" style="margin: 0; font-weight: 600; font-size: 0.85rem; color: #1e293b;">Formal Session Minutes / Minutes Record</label>
                        ${!isFinalized ? `
                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="btn-secondary" id="btnVoiceRecordInline" onclick="toggleStageVoiceRecording('inline')" style="font-size: 0.78rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                    <span id="voiceRecordIconInline">🎙️</span> <span id="voiceRecordLabelInline">Record Voice (STT)</span>
                                </button>
                                <button type="button" class="btn-secondary" id="btnUploadNotesInline" onclick="triggerStageNotesUpload('inline')" style="font-size: 0.78rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                    📷 Upload Notes (OCR)
                                </button>
                                <input type="file" id="inlineNotesFileInput" accept="image/*" style="display: none;" onchange="handleStageNotesFileSelected(this, 'inline')">
                            </div>
                        ` : ''}
                    </div>
                    <!-- AI Status Banner -->
                    <div id="inlineAiStatusBanner" style="display: none; padding: 8px 12px; margin-bottom: 8px; border-radius: 6px; font-size: 0.82rem; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;"></div>
                    <textarea id="inlineSessionMinutes" rows="5" placeholder="Full recorded proceedings, commitments, points of agreement, or AI-transcribed notes..." ${readonlyAttr} style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; font-size: 0.86rem; font-family: inherit; line-height: 1.5; box-sizing: border-box;">${escapeHtml(activeSession.settlement_discussion_notes || '')}</textarea>
                </div>

                <!-- Actions Footer -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 16px;">
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-secondary" onclick="openPreviewMinutesModal(${activeSession.hearing_id})" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                            🔍 Preview Letterhead
                        </button>
                        ${(isFinalized || activeSession.minutes_id) ? `
                            <button type="button" class="btn-secondary" onclick="printHearingMinutes(${activeSession.hearing_id})" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                                🖨️ Print Minutes
                            </button>
                        ` : ''}
                    </div>
                    <div style="display: flex; gap: 8px;">
                        ${!isFinalized ? `
                            <button type="button" class="btn-secondary" onclick="selectInlineMinutesSession(${activeSession.hearing_id})" style="font-size: 0.84rem; padding: 7px 14px;">
                                Cancel / Reset
                            </button>
                            <button type="button" class="btn-create" id="btnSaveInlineDraft" onclick="saveInlineMinutes('Draft')" style="font-size: 0.84rem; padding: 7px 18px; background: #0f172a; color: #fff;">
                                Save Hearing Minutes
                            </button>
                            <button type="button" class="btn-create" id="btnSaveInlineFinalize" onclick="saveInlineMinutes('Finalized')" style="font-size: 0.84rem; padding: 7px 18px; background: #16a34a; color: #fff;">
                                ✓ Finalize &amp; Lock
                            </button>
                        ` : `
                            <span style="font-size: 0.84rem; color: #15803d; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; background: #f0fdf4; border-radius: 6px; border: 1px solid #bbf7d0;">
                                🔒 Finalized Official Record
                            </span>
                        `}
                    </div>
                </div>
            </form>
        </div>
    `;

    minContainer.innerHTML = trackerHtml + formHtml;
}

/**
 * Switch Active Inline Minutes Session
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

/**
 * Save Inline Minutes (Draft or Finalized)
 */
async function saveInlineMinutes(status = 'Draft') {
    const hearingId = document.getElementById('inlineHearingId')?.value || stageWorkspaceState.activeSessionHearingId;
    if (!hearingId) {
        alert('No hearing session selected.');
        return;
    }

    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    if (!session) {
        alert('Session record not found.');
        return;
    }

    const outcomeVal = document.getElementById('inlineOutcome')?.value;
    if (!outcomeVal) {
        alert('Please select a session outcome.');
        document.getElementById('inlineOutcome')?.focus();
        return;
    }

    if (status === 'Finalized') {
        const confirmLock = confirm(
            'Are you sure you want to FINALIZE this session minutes?\n\n' +
            'Once finalized, this official Lupong Tagapamayapa record will be locked and protected from further modification.'
        );
        if (!confirmLock) return;
    }

    const btn = (status === 'Finalized') ? document.getElementById('btnSaveInlineFinalize') : document.getElementById('btnSaveInlineDraft');
    if (btn) btn.disabled = true;

    let sessionType = '1st Mediation';
    if (stageWorkspaceState.mode === 'Mediation') {
        sessionType = session ? (session.session_index === 1 ? '1st Mediation' : (session.session_index === 2 ? '2nd Mediation' : '3rd Mediation')) : '1st Mediation';
    } else if (stageWorkspaceState.mode === 'Conciliation') {
        sessionType = 'Conciliation';
    } else if (stageWorkspaceState.mode === 'Arbitration') {
        sessionType = 'Arbitration';
    }

    const payload = {
        hearing_id: Number(hearingId),
        session_type: sessionType,
        opening_conducted: document.getElementById('inlineOpening')?.checked ? 1 : 0,
        identity_verified: document.getElementById('inlineIdentity')?.checked ? 1 : 0,
        complaint_reviewed: document.getElementById('inlineReviewed')?.checked ? 1 : 0,
        complainant_statement: document.getElementById('inlineCompStmt')?.value || '',
        respondent_statement: document.getElementById('inlineRespStmt')?.value || '',
        main_dispute_identified: document.getElementById('inlineDisputeSummary')?.value || '',
        new_proposal: document.getElementById('inlineNewProposal')?.value || '',
        counteroffer: document.getElementById('inlineCounterOffer')?.value || '',
        caucus_conducted: document.getElementById('inlineCaucus')?.checked ? 1 : 0,
        caucus_notes: document.getElementById('inlineCaucusNotes')?.value || '',
        session_outcome: outcomeVal,
        actual_end_time: document.getElementById('inlineActualEndTime')?.value || null,
        outcome_remarks: document.getElementById('inlineDurationNotes')?.value || '',
        session_minutes: document.getElementById('inlineSessionMinutes')?.value || '',
        status: status
    };

    try {
        const res = await fetch(getStageApiUrl('hearings/minutes.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(status === 'Finalized' ? 'Session minutes finalized and locked successfully!' : 'Session minutes draft saved successfully.');
            await loadStageData();
        } else {
            alert(json.message || 'Unable to save session minutes.');
        }
    } catch (err) {
        console.error('saveInlineMinutes error:', err);
        alert('A network error occurred while saving minutes.');
    } finally {
        if (btn) btn.disabled = false;
    }
}
window.saveInlineMinutes = saveInlineMinutes;

/**
 * Render Outcome & Progression Section
 */
function renderOutcomeAndReferralSection(data, stage) {
    const outcomeBox = document.getElementById('wsOutcomeActionBox');
    if (!outcomeBox) return;

    const mode = stageWorkspaceState.mode;
    const outcome = stage.outcome || 'Pending';
    const canRefer = stage.documents_and_actions?.can_proceed_to_pangkat;

    if (outcome === 'Settled') {
        outcomeBox.innerHTML = `
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; color: #166534;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 1rem; color: #14532d; font-weight: 700;">
                            ✓ Amicable Settlement Reached
                        </h4>
                        <p style="margin: 0; font-size: 0.85rem; color: #166534;">
                            The parties reached an amicable settlement. Formal settlement terms are active and enforceable.
                        </p>
                    </div>
                    <a href="../settlements/settlements.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}" class="btn-create" style="font-size: 0.82rem; padding: 6px 14px; text-decoration: none;">
                        View Amicable Settlement &rarr;
                    </a>
                </div>
            </div>
        `;
    } else if (mode === 'Mediation') {
        if (stage.stage_status === 'Referred to Pangkat') {
            outcomeBox.innerHTML = `
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; color: #166534;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h4 style="margin: 0 0 4px; font-size: 1rem; color: #14532d; font-weight: 700;">
                                ✓ Case Referred to Pangkat ng Tagapagkasundo
                            </h4>
                            <p style="margin: 0; font-size: 0.84rem; color: #166534;">
                                Mediation concluded. KP Form 10 issued. Case is active in Pangkat Conciliation.
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <a href="../cases/case-list.php?assign_case_id=${encodeURIComponent(stageWorkspaceState.caseId)}#caseAssignments" class="btn-create" style="font-size: 0.82rem; padding: 7px 14px; background: #0369a1; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                                👥 Manage Pangkat Team in Cases &amp; Assignments &rarr;
                            </a>
                            <a href="pangkat-workspace.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}" class="btn-secondary" style="font-size: 0.82rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                                ⚖️ Open Pangkat Conciliation &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            `;
        } else {
            outcomeBox.innerHTML = `
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h4 style="margin: 0 0 4px; font-size: 0.95rem; color: #0f172a; font-weight: 700;">
                                Mediation Stage Actions &amp; Progression
                            </h4>
                            <p style="margin: 0; font-size: 0.82rem; color: #64748b;">
                                Current Outcome: <strong>${escapeHtml(outcome)}</strong>. Record official mediation resolution or escalate to Pangkat when ready.
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()" style="font-size: 0.82rem; padding: 6px 12px;">
                                Record Mediation Outcome &rarr;
                            </button>
                            ${canRefer ? `
                                <button type="button" class="btn-create" onclick="openReferPangkatModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #0369a1;">
                                    Refer to Pangkat (KP Form 10) &rarr;
                                </button>
                            ` : `
                                <button type="button" class="btn-secondary" disabled title="Complete sessions or record unsuccessful mediation to refer" style="font-size: 0.82rem; padding: 6px 12px; opacity: 0.5; cursor: not-allowed;">
                                    Refer to Pangkat 🔒
                                </button>
                            `}
                            <a href="../cases/case-list.php?assign_case_id=${encodeURIComponent(stageWorkspaceState.caseId)}#caseAssignments" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                👥 Cases &amp; Assignments &rarr;
                            </a>
                        </div>
                    </div>
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
function openScheduleModal() {
    const modal = document.getElementById('wsScheduleModal');
    if (!modal) return;
    const typeInput = document.getElementById('schedHearingType');
    if (typeInput) {
        typeInput.value = (stageWorkspaceState.mode === 'Mediation') ? 'Mediation' : 'Conciliation';
    }
    const venueInput = document.getElementById('schedVenue');
    if (venueInput && !venueInput.value) venueInput.value = 'Barangay Hall';
    modal.style.display = 'flex';
}

function closeScheduleModal() {
    const modal = document.getElementById('wsScheduleModal');
    if (modal) modal.style.display = 'none';
}

async function submitSchedule(e) {
    e.preventDefault();
    const btn = document.getElementById('submitSchedBtn');
    if (btn) btn.disabled = true;

    const dateVal = document.getElementById('schedDate').value;
    const timeVal = document.getElementById('schedTime').value;
    const venueVal = document.getElementById('schedVenue').value;
    const remarksVal = document.getElementById('schedRemarks').value;
    const typeVal = document.getElementById('schedHearingType').value || (stageWorkspaceState.mode === 'Mediation' ? 'Mediation' : 'Conciliation');

    if (!dateVal || !timeVal) {
        alert('Please specify the hearing date and time.');
        if (btn) btn.disabled = false;
        return;
    }

    const payload = new FormData();
    payload.append('case_id', stageWorkspaceState.caseId);
    payload.append('hearing_type', typeVal);
    payload.append('hearing_date', `${dateVal} ${timeVal}:00`);
    payload.append('venue', venueVal || 'Barangay Hall');
    payload.append('remarks', remarksVal);

    try {
        const res = await fetch(getStageApiUrl('hearings/create.php'), {
            method: 'POST',
            body: payload
        });
        const json = await res.json();
        if (json.success) {
            alert('Hearing scheduled successfully! KP Form 8 and KP Form 9 generated.');
            closeScheduleModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to schedule hearing session.');
        }
    } catch (err) {
        console.error('submitSchedule error:', err);
        alert('A network error occurred while scheduling hearing.');
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

    const parties = data.parties || [];
    if (!parties.length) {
        bodyEl.innerHTML = '<div class="empty-detail-state">No parties registered for this case.</div>';
        return;
    }

    let rowsHtml = '';
    parties.forEach(p => {
        const isWitness = p.party_type === 'Witness';
        const serviceStatus = p.summon_delivery_status || (p.successful_service_count > 0 ? 'Served' : 'Pending');
        const isServiceConfirmed = (serviceStatus && serviceStatus.toLowerCase().includes('served'));

        rowsHtml += `
            <div class="att-party-row" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                <input type="hidden" name="resident_id[]" value="${p.resident_id}">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <div>
                        <strong style="font-size: 0.92rem; color: #0f172a;">${escapeHtml(p.resident_name)}</strong>
                        <span style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-left: 6px; ${p.party_type === 'Complainant' ? 'background: #dbeafe; color: #1e40af;' : 'background: #fef3c7; color: #b45309;'}">
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
                    <div class="att-justification-wrap" style="${p.attendance_status === 'Absent' || p.attendance_status === 'Excused' ? '' : 'display: none;'}">
                        <label style="font-size: 0.74rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">ABSENCE EXPLANATION / JUSTIFICATION</label>
                        <input type="text" name="justification_reason[]" value="${escapeHtml(p.justification_reason || '')}" placeholder="Reason for non-appearance..." style="width: 100%; padding: 6px; font-size: 0.84rem; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                </div>
            </div>
        `;
    });

    bodyEl.innerHTML = `
        <form id="wsAttendanceForm" onsubmit="submitAttendance(event)">
            ${rowsHtml}
            <div class="modal-actions" style="margin-top: 14px;">
                <button type="button" class="btn-secondary" onclick="closeAttendanceModal()">Cancel</button>
                <button type="submit" class="btn-create" id="submitAttBtn">Save Attendance Record</button>
            </div>
        </form>
    `;
}

function toggleAttJustification(selectEl) {
    const row = selectEl.closest('.att-party-row');
    if (!row) return;
    const justWrap = row.querySelector('.att-justification-wrap');
    if (justWrap) {
        justWrap.style.display = (selectEl.value === 'Absent' || selectEl.value === 'Excused') ? 'block' : 'none';
    }
}

async function submitAttendance(e) {
    e.preventDefault();
    const btn = document.getElementById('submitAttBtn');
    if (btn) btn.disabled = true;

    const form = document.getElementById('wsAttendanceForm');
    const residentIds = Array.from(form.querySelectorAll('input[name="resident_id[]"]')).map(el => Number(el.value));
    const statuses = Array.from(form.querySelectorAll('select[name="attendance_status[]"]')).map(el => el.value);
    const justifications = Array.from(form.querySelectorAll('input[name="justification_reason[]"]')).map(el => el.value);

    const records = residentIds.map((rid, idx) => ({
        resident_id: rid,
        attendance_status: statuses[idx],
        is_justified: (statuses[idx] === 'Excused' || justifications[idx] !== '') ? 1 : 0,
        justification_reason: justifications[idx] || null,
        verification_status: statuses[idx] === 'Present' ? 'Verified Served' : 'Pending'
    }));

    try {
        const res = await fetch(getStageApiUrl('hearings/attendance.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                hearing_id: stageWorkspaceState.currentHearingId,
                attendance: records
            })
        });
        const json = await res.json();
        if (json.success) {
            alert('Attendance records saved successfully.');
            closeAttendanceModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to save attendance.');
        }
    } catch (err) {
        console.error('submitAttendance error:', err);
        alert('Network error saving attendance.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Minutes of the Meeting (Draft / Finalize / Print)
 * -----------------------------------------------------------------------------
 */
async function openMinutesModal(hearingId) {
    const modal = document.getElementById('wsMinutesModal');
    if (!modal) return;
    stageWorkspaceState.currentHearingId = hearingId;

    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    const titleEl = document.getElementById('wsMinModalTitle');
    if (titleEl) {
        titleEl.textContent = `Minutes of Meeting: ${session ? session.session_number : `Hearing #${hearingId}`}`;
    }

    const formEl = document.getElementById('wsMinutesForm');
    if (!formEl) return;
    formEl.innerHTML = '<div style="text-align: center; padding: 20px;">Loading meeting minutes...</div>';
    modal.style.display = 'flex';

    try {
        const res = await fetch(getStageApiUrl(`hearings/minutes.php?hearing_id=${encodeURIComponent(hearingId)}`));
        const json = await res.json();
        const minData = (json.success && json.data) ? json.data : null;
        stageWorkspaceState.currentMinutesRecord = minData;
        renderMinutesModalForm(minData, session);
    } catch (err) {
        console.error('openMinutesModal error:', err);
        formEl.innerHTML = '<div class="alert alert-danger">Error loading session minutes.</div>';
    }
}

function closeMinutesModal() {
    const modal = document.getElementById('wsMinutesModal');
    if (modal) modal.style.display = 'none';
}

function renderMinutesModalForm(min, session) {
    const formEl = document.getElementById('wsMinutesForm');
    if (!formEl) return;

    const mode = stageWorkspaceState.mode;
    const isFinalized = min && min.status === 'Finalized';
    const readonlyAttr = isFinalized ? 'readonly disabled' : '';

    // Stage-adapted outcome options
    let outcomeOptions = '';
    const currentOutcome = min?.session_outcome || '';

    if (mode === 'Mediation') {
        const opts = [
            { val: 'Continue Mediation', label: 'Continue Mediation / Schedule Next Session' },
            { val: 'Settled', label: 'Settled — Parties Reached Amicable Settlement' },
            { val: 'Elevate to Pangkat', label: 'Elevate to Pangkat ng Tagapagkasundo (KP Form 10)' },
            { val: 'Failed', label: 'Failed — Mediation Unsuccessful / Impasse' },
            { val: 'Party Absent', label: 'Party Absent / Issue Show Cause Notice' },
            { val: 'Rescheduled', label: 'Rescheduled — Session Reset Upon Request' }
        ];
        outcomeOptions = opts.map(o => `<option value="${o.val}" ${currentOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    } else if (mode === 'Conciliation') {
        const opts = [
            { val: 'Continue Conciliation', label: 'Continue Conciliation / Schedule Next Session' },
            { val: 'Settled', label: 'Settled — Parties Reached Amicable Settlement' },
            { val: 'Pending CFA', label: 'Conciliation Failed — Ready for CFA (KP Form 20)' },
            { val: 'Failed', label: 'Failed — Conciliation Unsuccessful / Impasse' },
            { val: 'Party Absent', label: 'Party Absent / Non-appearance Recorded' },
            { val: 'Rescheduled', label: 'Rescheduled — Session Reset' }
        ];
        outcomeOptions = opts.map(o => `<option value="${o.val}" ${currentOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    } else if (mode === 'Arbitration') {
        const opts = [
            { val: 'Continue Arbitration', label: 'Continue Arbitration Hearing' },
            { val: 'Arbitration Award', label: 'Render Arbitration Award (KP Form 15)' },
            { val: 'Settled', label: 'Settled — Parties Reached Settlement During Hearing' },
            { val: 'Failed', label: 'Dismissed / Repudiated Arbitration Agreement' },
            { val: 'Party Absent', label: 'Party Absent' },
            { val: 'Rescheduled', label: 'Rescheduled' }
        ];
        outcomeOptions = opts.map(o => `<option value="${o.val}" ${currentOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    }

    formEl.innerHTML = `
        <!-- Lock & Status Banner -->
        <div style="margin-bottom: 14px; padding: 12px 14px; background: ${isFinalized ? '#f0fdf4' : '#eff6ff'}; border: 1px solid ${isFinalized ? '#bbf7d0' : '#bfdbfe'}; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <div>
                <span style="font-size: 0.76rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: ${isFinalized ? '#15803d' : '#1d4ed8'};">
                    RECORD STATUS:
                </span>
                <strong style="font-size: 0.92rem; color: ${isFinalized ? '#15803d' : '#1d4ed8'}; margin-left: 6px;">
                    ${isFinalized ? '✓ FINALIZED &amp; LOCKED' : '📝 WORKING DRAFT'}
                </strong>
                ${min && min.finalized_at ? `
                    <span style="font-size: 0.78rem; color: #475569; margin-left: 10px;">
                        Finalized on ${formatDateTimeReadable(min.finalized_at)} by ${escapeHtml(min.finalized_by_name || 'Admin')}
                    </span>
                ` : '<span style="font-size: 0.78rem; color: #64748b; margin-left: 10px;">Editable draft. Lock when verified.</span>'}
            </div>
            <div style="display: flex; gap: 6px;">
                <button type="button" class="btn-secondary" onclick="openPreviewMinutesModal(${stageWorkspaceState.currentHearingId})" style="font-size: 0.78rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                    🔍 Preview Minutes
                </button>
                ${isFinalized ? `
                    <button type="button" class="btn-secondary" onclick="printHearingMinutes(${stageWorkspaceState.currentHearingId})" style="font-size: 0.78rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                        🖨️ Print Minutes
                    </button>
                ` : ''}
            </div>
        </div>

        <!-- Session Meta Summary Row -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.82rem;">
            <div>
                <span style="color: #64748b; font-size: 0.72rem; font-weight: 700; text-transform: uppercase;">PRESIDING OFFICER</span>
                <div style="color: #0f172a; font-weight: 600; margin-top: 2px;">${escapeHtml(session?.presider_name || 'Punong Barangay')}</div>
            </div>
            <div>
                <span style="color: #64748b; font-size: 0.72rem; font-weight: 700; text-transform: uppercase;">SESSION SCHEDULE</span>
                <div style="color: #0f172a; font-weight: 600; margin-top: 2px;">${formatDateTimeReadable(session?.hearing_date)}</div>
            </div>
            <div>
                <span style="color: #64748b; font-size: 0.72rem; font-weight: 700; text-transform: uppercase;">VENUE</span>
                <div style="color: #0f172a; font-weight: 600; margin-top: 2px;">${escapeHtml(session?.venue || 'Barangay Hall')}</div>
            </div>
            <div>
                <span style="color: #64748b; font-size: 0.72rem; font-weight: 700; text-transform: uppercase;">ATTENDANCE</span>
                <div style="color: #0f172a; font-weight: 600; margin-top: 2px;">C: ${escapeHtml(session?.complainant_attendance || 'Pending')} · R: ${escapeHtml(session?.respondent_attendance || 'Pending')}</div>
            </div>
        </div>

        <!-- ================= PANEL 1: Session Opening & Identity Verification ================= -->
        <div class="minutes-panel-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
            <div style="font-weight: 700; font-size: 0.88rem; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                <span>1.</span> Session Opening &amp; Identity Verification
            </div>
            <div style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 0.82rem;">
                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="checkbox" id="minOpening" ${min && min.opening_conducted ? 'checked' : ''} ${readonlyAttr}>
                    <span>Opening Statement Conducted</span>
                </label>
                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="checkbox" id="minIdentity" ${min && min.identity_verified ? 'checked' : ''} ${readonlyAttr}>
                    <span>Parties Identity Verified</span>
                </label>
                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="checkbox" id="minReviewed" ${min && min.complaint_reviewed ? 'checked' : ''} ${readonlyAttr}>
                    <span>Complaint Reviewed &amp; Read</span>
                </label>
            </div>
        </div>

        <!-- ================= PANEL 2: Statements & Dispute Information ================= -->
        <div class="minutes-panel-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
            <div style="font-weight: 700; font-size: 0.88rem; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                <span>2.</span> Statements and Dispute Information
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                <div class="form-group" style="margin: 0;">
                    <label for="minCompStmt" style="font-size: 0.8rem; font-weight: 600;">Complainant Statement Summary</label>
                    <textarea id="minCompStmt" rows="3" placeholder="Summary of complainant's claims, grievances, and statements..." ${readonlyAttr}>${escapeHtml(min?.complainant_statement || '')}</textarea>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="minRespStmt" style="font-size: 0.8rem; font-weight: 600;">Respondent Statement Summary</label>
                    <textarea id="minRespStmt" rows="3" placeholder="Summary of respondent's explanation, defenses, and counter-statements..." ${readonlyAttr}>${escapeHtml(min?.respondent_statement || '')}</textarea>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group" style="margin: 0;">
                    <label for="minDisputeSummary" style="font-size: 0.8rem; font-weight: 600;">Main Dispute Identified</label>
                    <textarea id="minDisputeSummary" rows="2" placeholder="Core conflict, disputed property, boundaries, monetary obligations, or relational issues..." ${readonlyAttr}>${escapeHtml(min?.main_dispute_identified || '')}</textarea>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="minAgenda" style="font-size: 0.8rem; font-weight: 600;">Agenda Topics / Matters Discussed</label>
                    <textarea id="minAgenda" rows="2" placeholder="Key agenda items, questions addressed, or procedural topics..." ${readonlyAttr}>${escapeHtml(min?.agenda_topics || '')}</textarea>
                </div>
            </div>
        </div>

        <!-- ================= PANEL 3: Negotiation, Proposals & Caucus ================= -->
        <div class="minutes-panel-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
            <div style="font-weight: 700; font-size: 0.88rem; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                <span>3.</span> Negotiation, Proposals &amp; Caucus (Settlement Progress)
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                <div class="form-group" style="margin: 0;">
                    <label for="minNewProposal" style="font-size: 0.8rem; font-weight: 600;">Settlement Proposal / Offer</label>
                    <textarea id="minNewProposal" rows="3" placeholder="Specific proposal, restitution amount, installment plan, apology, or terms offered..." ${readonlyAttr}>${escapeHtml(min?.new_proposal || '')}</textarea>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="minCounterOffer" style="font-size: 0.8rem; font-weight: 600;">Counteroffer / Response</label>
                    <textarea id="minCounterOffer" rows="3" placeholder="Party response, counterproposal, condition adjustments, or alternative terms..." ${readonlyAttr}>${escapeHtml(min?.counteroffer || '')}</textarea>
                </div>
            </div>

            <!-- Caucus Checkbox & Notes -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-bottom: 10px;">
                <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.82rem; font-weight: 600; color: #334155; margin-bottom: 6px;">
                    <input type="checkbox" id="minCaucus" ${min && min.caucus_conducted ? 'checked' : ''} ${readonlyAttr} onchange="toggleCaucusNotesVisibility(this)">
                    <span>Private Caucus Conducted (Separate meeting held with party)</span>
                </label>
                <div id="minCaucusNotesWrap" style="${(min && min.caucus_conducted) ? '' : 'display: none;'} margin-top: 6px;">
                    <label for="minCaucusNotes" style="font-size: 0.76rem; font-weight: 600; color: #64748b; display: block; margin-bottom: 3px;">CAUCUS NOTES &amp; CONFIDENTIAL OBSERVATIONS</label>
                    <textarea id="minCaucusNotes" rows="2" placeholder="Confidential mediator insights, party reservations, or caucus outcomes..." ${readonlyAttr}>${escapeHtml(min?.caucus_notes || '')}</textarea>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group" style="margin: 0;">
                    <label for="minAgreements" style="font-size: 0.8rem; font-weight: 600;">Agreements / Action Items / Settlement Terms</label>
                    <textarea id="minAgreements" rows="2" placeholder="Agreed stipulations, payment schedule, survey dates, or party obligations..." ${readonlyAttr}>${escapeHtml(min?.agreements_action_items || '')}</textarea>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="minUnresolved" style="font-size: 0.8rem; font-weight: 600;">Issues Remaining Unresolved</label>
                    <textarea id="minUnresolved" rows="2" placeholder="Unresolved contentious points or issues left for subsequent hearing..." ${readonlyAttr}>${escapeHtml(min?.unresolved_issues || '')}</textarea>
                </div>
            </div>
        </div>

        <!-- ================= PANEL 4: Session Outcome and Timing ================= -->
        <div class="minutes-panel-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
            <div style="font-weight: 700; font-size: 0.88rem; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                <span>4.</span> Session Outcome and Timing
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                <div class="form-group" style="margin: 0;">
                    <label for="minOutcome" style="font-size: 0.8rem; font-weight: 600;">Session Outcome <span class="required-mark">*</span></label>
                    <select id="minOutcome" ${readonlyAttr} required style="padding: 8px; width: 100%;">
                        ${outcomeOptions}
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <label for="minActualEndTime" style="font-size: 0.8rem; font-weight: 600;">Actual Session End Time <span class="optional-label">Optional</span></label>
                    <input type="datetime-local" id="minActualEndTime" value="${min?.actual_end_time ? min.actual_end_time.replace(' ', 'T').substring(0, 16) : ''}" ${readonlyAttr} style="padding: 8px; width: 100%;">
                </div>
            </div>
            <div class="form-group" style="margin: 0;">
                <label for="minOutcomeRemarks" style="font-size: 0.8rem; font-weight: 600;">Duration Notes / Remarks / Exceed Reason <span class="optional-label">Optional</span></label>
                <textarea id="minOutcomeRemarks" rows="2" placeholder="Basis of determination, reason if session exceeded standard duration, or next hearing target..." ${readonlyAttr}>${escapeHtml(min?.outcome_remarks || '')}</textarea>
            </div>
        </div>

        <!-- ================= PANEL 5: Formal Session Minutes / Minutes Record ================= -->
        <div class="minutes-panel-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                <div style="font-weight: 700; font-size: 0.88rem; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                    <span>5.</span> Formal Session Minutes / Proceedings Record
                </div>
                ${!isFinalized ? `
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button type="button" class="btn-secondary" id="btnStageRecordVoice" onclick="toggleStageVoiceRecording()" style="font-size: 0.78rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 5px;">
                            <span id="stageRecordVoiceText">🎙️ Record Voice (STT)</span>
                        </button>
                        <button type="button" class="btn-secondary" id="btnStageUploadNotes" onclick="triggerStageNotesUpload()" style="font-size: 0.78rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 5px;">
                            <span>📄 Upload Notes (OCR)</span>
                        </button>
                        <input type="file" id="stageNotesFileInput" accept="image/*" style="display: none;" onchange="handleStageNotesFileSelected(this)">
                    </div>
                ` : ''}
            </div>

            <!-- AI Status Banner -->
            <div id="stageAiStatusBanner" style="display: none; padding: 8px 12px; margin-bottom: 10px; border-radius: 6px; font-size: 0.82rem; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;"></div>

            <div class="form-group" style="margin: 0;">
                <textarea id="session_minutes" rows="8" placeholder="Detailed chronological transcript or comprehensive minutes of the hearing session..." ${readonlyAttr} style="font-family: inherit; font-size: 0.86rem; line-height: 1.5;">${escapeHtml(min?.settlement_discussion_notes || '')}</textarea>
                <small style="display: block; margin-top: 4px; color: #64748b;">
                    Full proceedings record. You can use <strong>Record Voice (STT)</strong> to capture speech or <strong>Upload Notes (OCR)</strong> to convert handwritten notes via Gemini 1.5 Flash.
                </small>
            </div>
        </div>

        <!-- Modal Actions Footer -->
        <div class="modal-actions" style="margin-top: 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <button type="button" class="btn-secondary" onclick="closeMinutesModal()">Close</button>
                <button type="button" class="btn-secondary" onclick="openPreviewMinutesModal(${stageWorkspaceState.currentHearingId})" style="margin-left: 6px;">
                    🔍 Preview Minutes
                </button>
            </div>
            <div>
                ${!isFinalized ? `
                    <button type="button" class="btn-secondary" onclick="saveMinutes('Draft')" id="btnSaveMinutesDraft" style="margin-right: 6px;">
                        💾 Save Draft
                    </button>
                    <button type="button" class="btn-create" onclick="saveMinutes('Finalized')" id="btnFinalizeMinutes" style="background: #15803d;">
                        🔒 Finalize &amp; Lock Minutes
                    </button>
                ` : `
                    <button type="button" class="btn-create" onclick="printHearingMinutes(${stageWorkspaceState.currentHearingId})" style="background: #0369a1;">
                        🖨️ Print Finalized Minutes
                    </button>
                `}
            </div>
        </div>
    `;
}

function toggleCaucusNotesVisibility(checkbox) {
    const wrap = document.getElementById('minCaucusNotesWrap');
    if (wrap) {
        wrap.style.display = checkbox.checked ? 'block' : 'none';
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

let currentAiTarget = 'inline';

async function toggleStageVoiceRecording(target = 'inline') {
    currentAiTarget = target;
    const isInline = target === 'inline';
    const btn = isInline ? document.getElementById('btnVoiceRecordInline') : document.getElementById('btnStageRecordVoice');
    const textSpan = isInline ? document.getElementById('voiceRecordLabelInline') : document.getElementById('stageRecordVoiceText');
    const statusBanner = isInline ? document.getElementById('inlineAiStatusBanner') : document.getElementById('stageAiStatusBanner');

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
                await sendStageAudioToGemini(audioBlob, target);
            };

            stageMediaRecorder.start();
            isStageVoiceRecording = true;
            if (btn) {
                btn.classList.add('recording');
                btn.style.background = '#ef4444';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#dc2626';
            }
            if (textSpan) textSpan.textContent = '⏹️ Stop Recording (00:00)';
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '🔴 <strong>Recording audio from microphone...</strong> Speak clearly. Click "Stop Recording" when finished.';
            }

            stageRecordingTimerInterval = setInterval(() => {
                stageRecordingSeconds++;
                const mins = String(Math.floor(stageRecordingSeconds / 60)).padStart(2, '0');
                const secs = String(stageRecordingSeconds % 60).padStart(2, '0');
                if (textSpan) textSpan.textContent = `⏹️ Stop Recording (${mins}:${secs})`;
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
        if (textSpan) textSpan.textContent = '⏳ Transcribing Voice...';
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = '⏳ <strong>Transcribing voice with Gemini 1.5 Flash...</strong> Please wait.';
        }
    }
}

async function sendStageAudioToGemini(audioBlob, target = 'inline') {
    const isInline = target === 'inline';
    const btn = isInline ? document.getElementById('btnVoiceRecordInline') : document.getElementById('btnStageRecordVoice');
    const textSpan = isInline ? document.getElementById('voiceRecordLabelInline') : document.getElementById('stageRecordVoiceText');
    const statusBanner = isInline ? document.getElementById('inlineAiStatusBanner') : document.getElementById('stageAiStatusBanner');

    try {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'stage_session_recording.webm');

        const response = await fetch(getStageApiUrl('ai/transcribe-audio.php'), {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success && result.text) {
            appendGeminiTextToMinutes(result.text, '🎙️ Voice Transcription', target);
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '✅ <strong>Voice transcription appended successfully!</strong>';
                setTimeout(() => { if (statusBanner) statusBanner.style.display = 'none'; }, 4000);
            }
        } else {
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = `<span style="color:#b91c1c;">⚠️ Transcription error: ${escapeHtml(result.message || 'Failed to process audio.')}</span>`;
            }
        }
    } catch (error) {
        console.error('Audio transcription error:', error);
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = `<span style="color:#b91c1c;">⚠️ Network error during transcription: ${escapeHtml(error.message)}</span>`;
        }
    } finally {
        if (btn) btn.disabled = false;
        if (textSpan) textSpan.textContent = isInline ? 'Record Voice (STT)' : '🎙️ Record Voice (STT)';
    }
}

function triggerStageNotesUpload(target = 'inline') {
    const fileInput = (target === 'inline') ? document.getElementById('inlineNotesFileInput') : document.getElementById('stageNotesFileInput');
    if (fileInput) fileInput.click();
}

async function handleStageNotesFileSelected(input, target = 'inline') {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const isInline = target === 'inline';
    const statusBanner = isInline ? document.getElementById('inlineAiStatusBanner') : document.getElementById('stageAiStatusBanner');
    const uploadBtn = isInline ? document.getElementById('btnUploadNotesInline') : document.getElementById('btnStageUploadNotes');

    if (uploadBtn) uploadBtn.disabled = true;
    if (statusBanner) {
        statusBanner.style.display = 'block';
        statusBanner.innerHTML = '⏳ <strong>Extracting handwritten/printed notes with Gemini 1.5 Flash OCR...</strong> Please wait.';
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
            appendGeminiTextToMinutes(result.text, '📄 OCR Notes Extract', target);
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '✅ <strong>Notes extracted and appended successfully!</strong>';
                setTimeout(() => { if (statusBanner) statusBanner.style.display = 'none'; }, 4000);
            }
        } else {
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = `<span style="color:#b91c1c;">⚠️ OCR error: ${escapeHtml(result.message || 'Failed to extract text from image.')}</span>`;
            }
        }
    } catch (error) {
        console.error('OCR error:', error);
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = `<span style="color:#b91c1c;">⚠️ Network error during OCR: ${escapeHtml(error.message)}</span>`;
        }
    } finally {
        if (uploadBtn) uploadBtn.disabled = false;
        input.value = '';
    }
}

function appendGeminiTextToMinutes(newText, sourceLabel, target = null) {
    let textarea = null;
    if (target === 'inline') {
        textarea = document.getElementById('inlineSessionMinutes');
    } else if (target === 'modal') {
        textarea = document.getElementById('session_minutes');
    } else {
        textarea = document.getElementById('inlineSessionMinutes') || document.getElementById('session_minutes');
    }
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
 * -----------------------------------------------------------------------------
 * MODAL: Save Session Minutes
 * -----------------------------------------------------------------------------
 */
async function saveMinutes(status = 'Draft') {
    const hearingId = stageWorkspaceState.currentHearingId;
    if (!hearingId) return;

    if (status === 'Finalized') {
        const conf = confirm('Finalizing minutes will lock this record and prevent silent overwriting. Are you sure you want to finalize and lock the minutes?');
        if (!conf) return;
    }

    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    let sessionType = '1st Mediation';
    if (stageWorkspaceState.mode === 'Mediation') {
        sessionType = session ? (session.session_index === 1 ? '1st Mediation' : (session.session_index === 2 ? '2nd Mediation' : '3rd Mediation')) : '1st Mediation';
    } else if (stageWorkspaceState.mode === 'Conciliation') {
        sessionType = 'Conciliation';
    } else if (stageWorkspaceState.mode === 'Arbitration') {
        sessionType = 'Arbitration';
    }

    const payload = {
        hearing_id: hearingId,
        session_type: sessionType,
        opening_conducted: document.getElementById('minOpening')?.checked ? 1 : 0,
        identity_verified: document.getElementById('minIdentity')?.checked ? 1 : 0,
        complaint_reviewed: document.getElementById('minReviewed')?.checked ? 1 : 0,
        caucus_conducted: document.getElementById('minCaucus')?.checked ? 1 : 0,
        caucus_notes: document.getElementById('minCaucusNotes')?.value || '',
        complainant_statement: document.getElementById('minCompStmt')?.value || '',
        respondent_statement: document.getElementById('minRespStmt')?.value || '',
        main_dispute_identified: document.getElementById('minDisputeSummary')?.value || '',
        agenda_topics: document.getElementById('minAgenda')?.value || '',
        new_proposal: document.getElementById('minNewProposal')?.value || '',
        counteroffer: document.getElementById('minCounterOffer')?.value || '',
        settlement_discussion_notes: document.getElementById('session_minutes')?.value || '',
        agreements_action_items: document.getElementById('minAgreements')?.value || '',
        unresolved_issues: document.getElementById('minUnresolved')?.value || '',
        session_outcome: document.getElementById('minOutcome')?.value || 'Pending',
        actual_end_time: document.getElementById('minActualEndTime')?.value || null,
        outcome_remarks: document.getElementById('minOutcomeRemarks')?.value || '',
        status: status
    };

    try {
        const res = await fetch(getStageApiUrl('hearings/minutes.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(status === 'Finalized' ? 'Minutes finalized and locked successfully!' : 'Minutes draft saved successfully.');
            closeMinutesModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to save session minutes.');
        }
    } catch (err) {
        console.error('saveMinutes error:', err);
        alert('Network error saving minutes.');
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Preview Formatted Minutes
 * -----------------------------------------------------------------------------
 */
function openPreviewMinutesModal(hearingId) {
    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    const caseData = stageWorkspaceState.stageData?.case || {};
    const min = stageWorkspaceState.currentMinutesRecord || session || {};

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
                        Opening Statement: <strong>${min.opening_conducted ? '✓ Conducted' : 'Not Recorded'}</strong> · 
                        Parties Identity: <strong>${min.identity_verified ? '✓ Verified' : 'Not Recorded'}</strong> · 
                        Complaint Read &amp; Reviewed: <strong>${min.complaint_reviewed ? '✓ Reviewed' : 'Not Recorded'}</strong>
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
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Private Caucus:</strong> ${min.caucus_conducted ? `✓ Conducted — ${escapeHtml(min.caucus_notes || 'Confidential')}` : 'None held'}</div>
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
                <button type="button" class="btn-create" onclick="printHearingMinutes(${hearingId})" style="background: #0369a1;">🖨️ Print Official Minutes</button>
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
    const min = stageWorkspaceState.currentMinutesRecord || session || {};

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
    modal.style.display = 'flex';
}

function closeReferPangkatModal() {
    const modal = document.getElementById('wsReferPangkatModal');
    if (modal) modal.style.display = 'none';
}

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
            alert('Case successfully referred to Pangkat ng Tagapagkasundo! KP Form 10 generated.');
            closeReferPangkatModal();
            // Redirect to Cases and assignments page to manage and finalize the Lupon team
            window.location.href = `case-list.php?assign_case_id=${encodeURIComponent(stageWorkspaceState.caseId)}#caseAssignments`;
        } else {
            alert(json.message || 'Unable to refer case to Pangkat.');
        }
    } catch (err) {
        console.error('submitReferPangkat error:', err);
        alert('Network error during referral.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// Global window exposure for inline and modal DOM events
window.toggleStageVoiceRecording = toggleStageVoiceRecording;
window.triggerStageNotesUpload = triggerStageNotesUpload;
window.handleStageNotesFileSelected = handleStageNotesFileSelected;
window.openPreviewMinutesModal = openPreviewMinutesModal;
window.printHearingMinutes = printHearingMinutes;
window.openScheduleModal = openScheduleModal;
window.closeScheduleModal = closeScheduleModal;
window.openAttendanceModal = openAttendanceModal;
window.closeAttendanceModal = closeAttendanceModal;
window.openMinutesModal = openMinutesModal;
window.closeMinutesModal = closeMinutesModal;
window.saveMinutes = saveMinutes;
window.openRecordOutcomeModal = openRecordOutcomeModal;
window.closeRecordOutcomeModal = closeRecordOutcomeModal;
window.openReferPangkatModal = openReferPangkatModal;
window.closeReferPangkatModal = closeReferPangkatModal;

