const caseWorkspaceEscape = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[c]);
const workspaceList = (items, render, empty) => items.length ? `<ul class="workspace-list">${items.map(render).join('')}</ul>` : `<p class="empty-state">${empty}</p>`;

let currentCaseData = null;

document.addEventListener('DOMContentLoaded', async () => {
    await loadWorkspace();

    const pauseForm = document.getElementById('pauseMediationForm');
    pauseForm?.addEventListener('submit', handlePauseSubmit);
});

async function loadWorkspace() {
    const message = document.getElementById('workspaceMessage');
    try {
        const response = await fetch(`../../../backend/api/cases/workspace.php?id=${encodeURIComponent(window.caseWorkspaceId)}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load the case workspace.');
        const data = result.data;
        const item = data.case;
        currentCaseData = data;

        document.getElementById('workspaceTitle').textContent = item.case_number || 'Case Workspace';
        document.getElementById('workspaceSubtitle').textContent = item.complaint_title;
        document.getElementById('assignLink').href = 'case-list.php#caseAssignments';

        const timer = item.mediation_timer || {};
        renderMediationTimer(item, timer);

        const hearingLink = document.getElementById('hearingLink');
        if (hearingLink) {
            const isClosed = ['Archived', 'Settled', 'Dismissed', 'CFA Issued'].includes(item.case_status);
            const mediationHearings = (data.hearings || []).filter((h) => h.hearing_type === 'Mediation');
            const conciliationHearings = (data.hearings || []).filter((h) => h.hearing_type === 'Conciliation');
            const mCount = mediationHearings.length;
            const cCount = conciliationHearings.length;

            if (isClosed) {
                hearingLink.hidden = true;
            } else if (item.case_status === 'Mediation' && timer.is_lapsed) {
                // Safeguard: Restrict scheduling further Punong Barangay Mediation hearings once lapsed!
                hearingLink.textContent = 'Mediation Period Lapsed';
                hearingLink.removeAttribute('href');
                hearingLink.hidden = false;
                hearingLink.classList.add('is-disabled');
                hearingLink.setAttribute('aria-disabled', 'true');
                hearingLink.title = 'Mediation period has lapsed. Please elevate to Pangkat Tagapagkasundo or issue a CFA.';
            } else if (item.case_status === 'Mediation' && timer.is_paused) {
                // Safeguard: Pause active
                hearingLink.textContent = 'Mediation Paused';
                hearingLink.removeAttribute('href');
                hearingLink.hidden = false;
                hearingLink.classList.add('is-disabled');
                hearingLink.setAttribute('aria-disabled', 'true');
                hearingLink.title = 'Mediation clock is paused. Resume before scheduling.';
            } else if (mCount < 3 && item.case_status === 'Mediation') {
                const nextSeq = mCount + 1;
                const label = nextSeq === 1 ? '1st' : (nextSeq === 2 ? '2nd' : '3rd');
                hearingLink.textContent = `Schedule ${label} Mediation`;
                hearingLink.href = `../hearings/schedules.php?case_id=${encodeURIComponent(item.case_id)}`;
                hearingLink.hidden = false;
                hearingLink.classList.remove('is-disabled');
                hearingLink.removeAttribute('aria-disabled');
                hearingLink.title = '';
            } else if (cCount < 3 && (item.case_status === 'Conciliation' || mCount >= 3)) {
                const nextSeq = cCount + 1;
                const label = nextSeq === 1 ? '1st' : (nextSeq === 2 ? '2nd' : '3rd');
                hearingLink.textContent = `Schedule ${label} Conciliation`;
                hearingLink.href = `../hearings/schedules.php?case_id=${encodeURIComponent(item.case_id)}`;
                hearingLink.hidden = false;
                hearingLink.classList.remove('is-disabled');
                hearingLink.removeAttribute('aria-disabled');
                hearingLink.title = '';
            } else {
                hearingLink.textContent = 'All schedules completed';
                hearingLink.removeAttribute('href');
                hearingLink.hidden = false;
                hearingLink.classList.add('is-disabled');
                hearingLink.setAttribute('aria-disabled', 'true');
            }
        }

        // Case overview details
        let overviewExtra = '';
        if (item.case_status === 'Mediation' && timer.timer_status) {
            overviewExtra = `<p><strong>Mediation Timer:</strong> <span class="badge ${caseWorkspaceEscape(timer.badge_class)}">${caseWorkspaceEscape(timer.badge_label)}</span></p>`;
            if (timer.mediation_deadline_date) {
                overviewExtra += `<p><strong>Statutory Deadline:</strong> ${caseWorkspaceEscape(timer.mediation_deadline_date)} (Day 15)</p>`;
            }
        }

        document.getElementById('caseOverview').innerHTML = `
            <p><strong>Complaint:</strong> ${caseWorkspaceEscape(item.complaint_number)} — ${caseWorkspaceEscape(item.complaint_title)}</p>
            <p><strong>Type:</strong> ${caseWorkspaceEscape(item.case_type)}</p>
            <p><strong>Status:</strong> ${caseWorkspaceEscape(item.case_status)}</p>
            <p><strong>Docketed:</strong> ${caseWorkspaceEscape(item.docket_date)}</p>
            ${overviewExtra}
        `;

        document.getElementById('caseTeam').innerHTML = workspaceList(data.assignments, (a) => `<li><strong>${caseWorkspaceEscape(a.assignment_role)}:</strong> ${caseWorkspaceEscape(a.member_name)}</li>`, 'No team has been assigned.');

        const formatHearingType = (h) => {
            if (!['Mediation', 'Conciliation'].includes(h.hearing_type)) return h.hearing_type;
            const sameType = (data.hearings || [])
                .filter((entry) => entry.hearing_type === h.hearing_type)
                .sort((a, b) => Number(a.hearing_id) - Number(b.hearing_id));
            const idx = sameType.findIndex((entry) => String(entry.hearing_id) === String(h.hearing_id));
            const seq = idx >= 0 ? idx + 1 : 1;
            const ord = seq === 1 ? '1st' : (seq === 2 ? '2nd' : (seq === 3 ? '3rd' : `${seq}th`));
            return `${ord} ${h.hearing_type}`;
        };

        const formatAttendanceSummary = (h) => {
            const count = Number(h.attendance_count) || 0;
            const unjustified = Number(h.unjustified_absent_count) || 0;
            const excused = Number(h.excused_count) || 0;
            const present = Number(h.present_count) || 0;
            if (count === 0) {
                return '<span class="badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;padding:2px 6px;border-radius:4px;font-size:0.75rem;">Attendance: Pending Intake</span>';
            }
            if (unjustified > 0) {
                return `<span class="badge" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;padding:2px 6px;border-radius:4px;font-size:0.75rem;">Attendance: ${unjustified} Unjustified Absent</span>`;
            }
            if (excused > 0) {
                return `<span class="badge" style="background:#fef3c7;color:#b45309;border:1px solid #fde68a;padding:2px 6px;border-radius:4px;font-size:0.75rem;">Attendance: ${excused} Excused</span>`;
            }
            return `<span class="badge" style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;padding:2px 6px;border-radius:4px;font-size:0.75rem;">Attendance: ${present} Present</span>`;
        };

        document.getElementById('caseHearings').innerHTML = workspaceList(data.hearings, (h) => `
            <li style="margin-bottom:12px;">
                <strong>${caseWorkspaceEscape(formatHearingType(h))}</strong> — ${caseWorkspaceEscape(h.hearing_date)}<br>
                ${caseWorkspaceEscape(h.venue || 'Venue pending')} · ${caseWorkspaceEscape(h.hearing_status)}
                <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    ${formatAttendanceSummary(h)}
                    <a href="../hearings/schedules.php?hearing_id=${encodeURIComponent(h.hearing_id)}&open_attendance=1" style="font-size:0.76rem; font-weight:600; text-decoration:none; color:#0284c7; background:#f0f9ff; border:1px solid #bae6fd; padding:2px 8px; border-radius:4px; display:inline-flex; align-items:center; gap:4px;">
                        Monitor Attendance &rarr;
                    </a>
                </div>
            </li>
        `, 'No hearings are scheduled.');
        document.getElementById('caseDocuments').innerHTML = workspaceList(data.documents, (d) => `<li><strong>${caseWorkspaceEscape(d.template_name)}</strong><br>${caseWorkspaceEscape(d.generated_at)} · ${caseWorkspaceEscape(d.service_status)}<br><a href="../gps/proof-service.php?case_id=${encodeURIComponent(item.case_id)}&document_id=${encodeURIComponent(d.document_id)}">Record proof of service</a></li>`, 'No documents have been generated.');
        document.getElementById('caseProofs').innerHTML = workspaceList(data.proofs, (p) => `<li><strong>${caseWorkspaceEscape(p.template_name || 'Legacy service record')}</strong><br>${caseWorkspaceEscape(p.served_date)} by ${caseWorkspaceEscape(p.served_by_name)}${p.remarks ? `<br>${caseWorkspaceEscape(p.remarks)}` : ''}</li>`, 'No proof of service has been recorded.');
    } catch (error) {
        if (message) {
            message.textContent = error.message;
            message.className = 'page-alert page-alert-danger';
        }
    }
}

function renderMediationTimer(item, timer) {
    const container = document.getElementById('mediationTimerContainer');
    if (!container) return;

    if (item.case_status !== 'Mediation' || !timer || !timer.timer_status) {
        container.innerHTML = '';
        return;
    }

    const canManage = window.canManageSuspension === true;

    // 1. If Mediation Lapsed: Show prominent safeguard banner with Option A & Option B
    if (timer.is_lapsed) {
        container.innerHTML = `
            <div class="lapsed-options-card" role="region" aria-label="Mediation Period Lapsed Actions">
                <h3>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span>15-Day Mediation Period Lapsed (Statutory Limit Reached)</span>
                    <span class="badge ${caseWorkspaceEscape(timer.badge_class)}">Mediation Period Lapsed</span>
                </h3>
                <p>
                    Under <strong>RA 7160 Section 410(b)</strong> (Katarungang Pambarangay), the Punong Barangay's 15-day statutory mediation period has concluded without a mediated settlement. Further mediation sessions before the Punong Barangay are now restricted. Proceed with one of the two legal next steps below:
                </p>
                <div class="lapsed-options-grid">
                    <div class="lapsed-option-box">
                        <div>
                            <strong>Option A: Constitute Pangkat Tagapagkasundo</strong>
                            <span>Elevate the dispute to a 3-member conciliation panel chosen by the parties, granting a fresh 15-day conciliation period.</span>
                        </div>
                        <a href="case-list.php#caseAssignments" class="btn-option-a">
                            Constitute Pangkat Tagapagkasundo &rarr;
                        </a>
                    </div>
                    <div class="lapsed-option-box">
                        <div>
                            <strong>Option B: Issue Certificate to File Action (CFA)</strong>
                            <span>Certify that mediation proceedings have been exhausted without settlement, allowing the complainant to file the action in court.</span>
                        </div>
                        <a href="../documents/cfa.php?case_id=${encodeURIComponent(item.case_id)}" class="btn-option-b">
                            Issue Certificate to File Action (CFA) &rarr;
                        </a>
                    </div>
                </div>
            </div>
        `;
        return;
    }

    // 2. If Paused: Show Paused Banner with reason and Resume button
    if (timer.is_paused) {
        const resumeBtn = canManage ? `
            <button type="button" class="btn-create" onclick="resumeMediationClock()">
                Resume Mediation Clock
            </button>
        ` : '';

        container.innerHTML = `
            <div class="mediation-timer-banner paused">
                <div class="mediation-timer-info">
                    <h3>
                        <span>Mediation Clock Paused</span>
                        <span class="badge ${caseWorkspaceEscape(timer.badge_class)}">${caseWorkspaceEscape(timer.badge_label)}</span>
                    </h3>
                    <p>
                        <strong>Suspension Reason:</strong> ${caseWorkspaceEscape(timer.pause_reason || 'Verified suspension')}
                        ${timer.pause_notes ? ` · <em>${caseWorkspaceEscape(timer.pause_notes)}</em>` : ''}
                        <br>
                        <small>Paused at: ${caseWorkspaceEscape(timer.paused_at || 'Recently')}. Days remaining when paused: ${caseWorkspaceEscape(timer.days_remaining ?? 'N/A')} days.</small>
                    </p>
                </div>
                <div class="mediation-timer-actions">
                    ${resumeBtn}
                </div>
            </div>
        `;
        return;
    }

    // 3. Active Clock: Within Period or Expiring Soon
    const isExpiring = timer.timer_status === 'Expiring Soon';
    const pauseBtn = canManage ? `
        <button type="button" class="btn-secondary" onclick="openPauseModal()">
            Log Justified Suspension
        </button>
    ` : '';

    container.innerHTML = `
        <div class="mediation-timer-banner ${isExpiring ? 'expiring' : ''}">
            <div class="mediation-timer-info">
                <h3>
                    <span>15-Day Statutory Mediation Clock</span>
                    <span class="badge ${caseWorkspaceEscape(timer.badge_class)}">${caseWorkspaceEscape(timer.badge_label)}</span>
                </h3>
                <p>
                    <strong>Day 0 (Start):</strong> ${caseWorkspaceEscape(timer.mediation_start_date || item.docket_date)} ·
                    <strong>Statutory Deadline:</strong> ${caseWorkspaceEscape(timer.mediation_deadline_date || 'In progress')}
                    <br>
                    <small>Clock duration is 15 calendar days with automatic weekend and public holiday rollover.</small>
                </p>
            </div>
            <div class="mediation-timer-actions">
                ${pauseBtn}
            </div>
        </div>
    `;
}

function openPauseModal() {
    const modal = document.getElementById('pauseMediationModal');
    if (modal) modal.style.display = 'flex';
}

function closePauseModal() {
    const modal = document.getElementById('pauseMediationModal');
    if (modal) modal.style.display = 'none';
}

async function handlePauseSubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submitBtn = document.getElementById('btnSubmitPause');
    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(form);
        formData.set('case_id', window.caseWorkspaceId);

        const response = await fetch('../../../backend/api/cases/pause-mediation.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to pause mediation clock.');
        }

        closePauseModal();
        form.reset();
        window.agapNotify?.(result.message || 'Mediation clock paused.', 'success', 'Clock Suspended');
        await loadWorkspace();
    } catch (err) {
        window.agapNotify?.(err.message, 'error', 'Suspension Failed');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

async function resumeMediationClock() {
    if (!window.confirm('Resume the 15-day statutory mediation clock for this case? The deadline will be extended by the calendar days the clock was paused.')) {
        return;
    }

    try {
        const formData = new FormData();
        formData.set('case_id', window.caseWorkspaceId);

        const response = await fetch('../../../backend/api/cases/resume-mediation.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to resume mediation clock.');
        }

        window.agapNotify?.(result.message || 'Mediation clock resumed.', 'success', 'Clock Resumed');
        await loadWorkspace();
    } catch (err) {
        window.agapNotify?.(err.message, 'error', 'Resume Failed');
    }
}
