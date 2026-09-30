const caseWorkspaceEscape = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[c]);
const workspaceList = (items, render, empty) => items.length ? `<ul class="workspace-list">${items.map(render).join('')}</ul>` : `<p class="empty-state">${empty}</p>`;

let currentCaseData = null;

document.addEventListener('DOMContentLoaded', async () => {
    await loadWorkspace();

    const pauseForm = document.getElementById('pauseMediationForm');
    pauseForm?.addEventListener('submit', handlePauseSubmit);

    const deliveryForm = document.getElementById('summonDeliveryForm');
    deliveryForm?.addEventListener('submit', handleDeliverySubmit);

    const attendanceForm = document.getElementById('hearingAttendanceForm');
    attendanceForm?.addEventListener('submit', handleAttendanceSubmit);

    const showCauseForm = document.getElementById('showCauseForm');
    showCauseForm?.addEventListener('submit', handleShowCauseSubmit);
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
        renderKpStepper(item, data);

        const hearingLink = document.getElementById('hearingLink');
        if (hearingLink) {
            const isClosed = ['Archived', 'Settled', 'Dismissed', 'CFA Issued', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT'].includes(item.case_status);
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
        } else if (item.case_status === 'RESPONDENT_DEFAULT') {
            overviewExtra = `<div class="notice-box notice-box-danger" style="margin-top: 10px;">
                <strong>RESPONDENT IN DEFAULT:</strong> Respondent willfully failed to appear. Complainant is eligible to receive Certificate to File Action (CFA).
                <div style="margin-top: 6px;">
                    <a href="../documents/cfa.php?case_id=${encodeURIComponent(item.case_id)}" class="action-chip-btn action-chip-danger">
                        Issue Certificate to File Action (CFA) &rarr;
                    </a>
                </div>
            </div>`;
        } else if (item.case_status === 'DISMISSED_BARRED') {
            overviewExtra = `<div class="notice-box notice-box-danger" style="margin-top: 10px;">
                <strong>DISMISSED &amp; ACTION BARRED:</strong> Complainant failed to appear without justifiable ground. Barred from refiling under KP Form 21.
                <div style="margin-top: 6px;">
                    <a href="../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(item.case_id)}&form_code=KP+Form+21" target="_blank" class="action-chip-btn action-chip-secondary">
                        Print KP Form 21 &rarr;
                    </a>
                </div>
            </div>`;
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

        const renderHearingDeliveries = (h) => {
            const deliveries = h.deliveries || [];
            if (!deliveries.length) {
                return `
                    <div style="margin-top: 8px; font-size: 0.8rem; color: #64748b; background: #f8fafc; padding: 6px 10px; border-radius: 4px; border: 1px dashed #cbd5e1;">
                        ✉️ <strong>Summon / Notice Delivery:</strong> Not recorded yet.
                    </div>
                `;
            }

            return `
                <div style="margin-top: 8px; background: #f8fafc; padding: 8px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px;">
                        ✉️ Delivery Status (What-If Matrix):
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        ${deliveries.map((d) => {
                            let badgeClass = 'delivery-badge-pending';
                            let statusText = d.delivery_status;
                            if (['Served Personal', 'Served Substituted'].includes(d.delivery_status)) {
                                badgeClass = 'delivery-badge-served';
                            } else if (d.delivery_status === 'Served Refused') {
                                badgeClass = 'delivery-badge-refused';
                                statusText = 'Refused to Sign (Served)';
                            } else if (d.delivery_status === 'Unserved') {
                                badgeClass = 'delivery-badge-unserved';
                                statusText = `Unserved (${d.unserved_reason || 'Failed'})`;
                            }

                            return `
                                <div class="delivery-badge ${badgeClass}" title="${caseWorkspaceEscape(d.failure_notes || d.remarks || '')}">
                                    <strong>${caseWorkspaceEscape(d.party_type)}:</strong> ${caseWorkspaceEscape(statusText)}
                                    ${d.recipient_name ? ` · <em>${caseWorkspaceEscape(d.recipient_name)}</em>` : ''}
                                </div>
                            `;
                        }).join('')}
                    </div>
                </div>
            `;
        };

        const renderHearingActionButtons = (h) => {
            const isShowCause = h.hearing_type.includes('Show Cause');
            const deliveries = h.deliveries || [];
            const bothServed = deliveries.length >= 2 && deliveries.every((d) => ['Served Personal', 'Served Substituted', 'Served Refused'].includes(d.delivery_status));
            const hasUnserved = deliveries.some((d) => d.delivery_status === 'Unserved');

            let buttons = [];

            // 1. Deliver Summons / Notice button
            if (window.canManageDeliveries) {
                buttons.push(`
                    <button type="button" class="action-chip-btn action-chip-primary" onclick="openDeliveryModal(${Number(h.hearing_id)})">
                        ✉️ Deliver Summons / Notice
                    </button>
                `);
            }

            // 2. Attendance or Show-Cause Decision button
            if (window.canManageAttendance) {
                if (isShowCause) {
                    const party = h.hearing_type.includes('Complainant') ? 'Complainant' : 'Respondent';
                    const formCode = party === 'Complainant' ? 'KP Form 18' : 'KP Form 19';
                    buttons.push(`
                        <button type="button" class="action-chip-btn action-chip-warning" onclick="openShowCauseModal(${Number(h.hearing_id)}, '${party}')">
                            ⚖️ Evaluate Show-Cause Decision
                        </button>
                    `);
                    buttons.push(`
                        <a href="../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(item.case_id)}&hearing_id=${encodeURIComponent(h.hearing_id)}&form_code=${encodeURIComponent(formCode)}" target="_blank" class="action-chip-btn action-chip-secondary">
                            🖨️ Print ${formCode}
                        </a>
                    `);
                } else {
                    if (bothServed) {
                        buttons.push(`
                            <button type="button" class="action-chip-btn action-chip-primary" onclick="openAttendanceModal(${Number(h.hearing_id)})">
                                📋 Record Attendance
                            </button>
                        `);
                    } else if (hasUnserved) {
                        buttons.push(`
                            <button type="button" class="action-chip-btn action-chip-secondary" disabled title="Locked: Summons failed delivery. Unexcused absence cannot be recorded.">
                                🔒 Attendance Locked (Summon Unserved)
                            </button>
                        `);
                    } else {
                        buttons.push(`
                            <button type="button" class="action-chip-btn action-chip-secondary" disabled title="Please record summon delivery first.">
                                🔒 Record Attendance (Awaiting Service)
                            </button>
                        `);
                    }
                }
            }

            return `<div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">${buttons.join('')}</div>`;
        };

        const renderHearingParties = (h) => {
            if (!h.parties || !h.parties.length) return '';
            return `
                <div class="workspace-hearing-parties">
                    <div class="parties-header-label">Party Appearance &amp; Remarks:</div>
                    <ul class="parties-appearance-list">
                        ${h.parties.map((p) => {
                            let statusClass = 'att-badge-pending';
                            let statusLabel = p.attendance_status || 'Pending';
                            if (p.attendance_status === 'Present') {
                                statusClass = 'att-badge-present';
                            } else if (p.attendance_status === 'Absent') {
                                if (Number(p.is_justified) === 1) {
                                    statusClass = 'att-badge-excused';
                                    statusLabel = 'Excused / Justified';
                                } else {
                                    statusClass = 'att-badge-absent';
                                    statusLabel = 'Failure to Appear';
                                }
                            } else if (p.attendance_status === 'Not Served') {
                                statusClass = 'att-badge-not-served';
                                statusLabel = 'Not Served';
                            } else if (p.attendance_status === 'Excused') {
                                statusClass = 'att-badge-excused';
                            } else if (p.attendance_status === 'Late') {
                                statusClass = 'att-badge-late';
                                statusLabel = 'Late Appearance';
                            }

                            const partyTypeClass = p.party_type === 'Complainant'
                                ? 'party-type-complainant'
                                : (p.party_type === 'Respondent' ? 'party-type-respondent' : 'party-type-witness');

                            return `
                                <li class="party-appearance-row">
                                    <div class="party-appearance-main">
                                        <span class="party-type-pill ${partyTypeClass}">${caseWorkspaceEscape(p.party_type)}</span>
                                        <span class="party-name">${caseWorkspaceEscape(p.full_name)}</span>
                                        <span class="att-status-tag ${statusClass}">${caseWorkspaceEscape(statusLabel)}</span>
                                    </div>
                                    ${p.remarks ? `
                                        <div class="party-remarks-bubble">
                                            <span class="remarks-label">Remarks:</span> &ldquo;${caseWorkspaceEscape(p.remarks)}&rdquo;
                                        </div>
                                    ` : ''}
                                    ${Number(p.is_justified) === 1 && p.justification_reason ? `
                                        <div class="party-justification-bubble">
                                            <span class="justification-label">Justification Cause:</span> ${caseWorkspaceEscape(p.justification_reason)}
                                        </div>
                                    ` : ''}
                                </li>
                            `;
                        }).join('')}
                    </ul>
                </div>
            `;
        };

        document.getElementById('caseHearings').innerHTML = workspaceList(data.hearings, (h) => `
            <li class="workspace-hearing-item">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
                    <div>
                        <strong style="font-size:0.92rem; color:#0f172a;">${caseWorkspaceEscape(formatHearingType(h))}</strong>
                        <div style="font-size:0.82rem; color:#475569; margin-top:2px;">
                            📅 ${caseWorkspaceEscape(h.hearing_date)} · 📍 ${caseWorkspaceEscape(h.venue || 'Venue pending')}
                        </div>
                    </div>
                    <span class="badge ${h.hearing_status === 'Completed' ? 'badge-secondary' : 'badge-primary'}" style="font-size:0.75rem;">
                        ${caseWorkspaceEscape(h.hearing_status)}
                    </span>
                </div>

                ${renderHearingDeliveries(h)}
                ${renderHearingActionButtons(h)}
                ${renderHearingParties(h)}
            </li>
        `, 'No hearings are scheduled.');

        document.getElementById('caseDocuments').innerHTML = workspaceList(data.documents, (d) => `
            <li style="margin-bottom: 8px;">
                <strong>${caseWorkspaceEscape(d.template_name)}</strong><br>
                ${caseWorkspaceEscape(d.generated_at)} · ${caseWorkspaceEscape(d.service_status)}<br>
                <div style="display: flex; gap: 6px; margin-top: 4px;">
                    <a href="../../backend/api/documents/download.php?id=${encodeURIComponent(d.document_id)}" target="_blank" class="action-chip-btn action-chip-primary">
                        Download PDF
                    </a>
                    ${d.template_name.includes('KP Form') ? `
                        <a href="../../backend/api/documents/kp-form.php?document_id=${encodeURIComponent(d.document_id)}" target="_blank" class="action-chip-btn action-chip-secondary">
                            Print / View
                        </a>
                    ` : ''}
                    <a href="../gps/proof-service.php?case_id=${encodeURIComponent(item.case_id)}&document_id=${encodeURIComponent(d.document_id)}" class="action-chip-btn action-chip-secondary">
                        Record proof
                    </a>
                </div>
            </li>
        `, 'No documents have been generated.');

        document.getElementById('caseProofs').innerHTML = workspaceList(data.proofs, (p) => `
            <li>
                <strong>${caseWorkspaceEscape(p.template_name || 'Legacy service record')}</strong><br>
                ${caseWorkspaceEscape(p.served_date)} by ${caseWorkspaceEscape(p.served_by_name)}
                ${p.remarks ? `<br><em>${caseWorkspaceEscape(p.remarks)}</em>` : ''}
            </li>
        `, 'No proof of service has been recorded.');

    } catch (error) {
        if (message) {
            message.textContent = error.message;
            message.className = 'page-alert page-alert-danger';
        }
    }
}

/**
 * Renders the KP Katarungang Pambarangay Workflow Timeline / Stepper:
 * Summon Issued -> Delivery Status -> Hearing Attendance -> (If Absent: KP 18/19 Issued) -> Show-Cause Decision -> Next Action
 */
function renderKpStepper(item, data) {
    const container = document.getElementById('kpStepperContainer');
    if (!container) return;

    const hearings = data.hearings || [];
    const documents = data.documents || [];
    const latestHearing = hearings[hearings.length - 1] || null;

    // Step 1: Summon Issued
    const hasSummonDoc = documents.some((d) => d.template_name === 'KP Form 9' || d.template_name.includes('Summon') || d.template_name === 'KP Form 8');
    const step1Completed = hasSummonDoc || hearings.length > 0;
    const step1 = {
        icon: step1Completed ? '✓' : '1',
        label: 'Summons Issued',
        sub: step1Completed ? 'Notice & Summon Created' : 'Awaiting Summon',
        cls: step1Completed ? 'completed' : 'active'
    };

    // Step 2: Delivery Status
    let step2 = { icon: '2', label: 'Delivery Status', sub: 'Pending Service', cls: 'locked' };
    if (latestHearing && latestHearing.deliveries && latestHearing.deliveries.length > 0) {
        const deliveries = latestHearing.deliveries;
        const allServed = deliveries.every((d) => ['Served Personal', 'Served Substituted', 'Served Refused'].includes(d.delivery_status));
        const hasUnserved = deliveries.some((d) => d.delivery_status === 'Unserved');

        if (allServed) {
            step2 = { icon: '✓', label: 'Delivery Status', sub: 'Both Parties Served', cls: 'completed' };
        } else if (hasUnserved) {
            step2 = { icon: '!', label: 'Delivery Status', sub: 'Summon Unserved (Paused)', cls: 'failed' };
        } else {
            step2 = { icon: '2', label: 'Delivery Status', sub: 'In Transit / Pending', cls: 'active' };
        }
    } else if (step1Completed) {
        step2 = { icon: '2', label: 'Delivery Status', sub: 'Awaiting Delivery Log', cls: 'active' };
    }

    // Step 3: Hearing Attendance
    let step3 = { icon: '3', label: 'Attendance', sub: 'Awaiting Hearing', cls: 'locked' };
    let hasAbsence = false;
    let attendanceCompleted = false;

    if (latestHearing) {
        const compAtt = latestHearing.complainant_attendance;
        const respAtt = latestHearing.respondent_attendance;
        if (compAtt && compAtt !== 'Pending' && respAtt && respAtt !== 'Pending') {
            attendanceCompleted = true;
            if (compAtt === 'Present' && respAtt === 'Present') {
                step3 = { icon: '✓', label: 'Attendance', sub: 'Both Appeared', cls: 'completed' };
            } else {
                hasAbsence = true;
                const absentNames = [];
                if (compAtt === 'Absent') absentNames.push('Complainant');
                if (respAtt === 'Absent') absentNames.push('Respondent');
                step3 = { icon: '!', label: 'Attendance', sub: `${absentNames.join('/')} Absent`, cls: 'failed' };
            }
        } else if (step2.cls === 'completed') {
            step3 = { icon: '3', label: 'Attendance', sub: 'Ready for Hearing', cls: 'active' };
        }
    }

    // Step 4: KP Form 18/19 Issued
    let step4 = { icon: '4', label: 'KP 18/19 Issued', sub: 'Not Required', cls: 'locked' };
    const hasKp18Or19 = documents.some((d) => d.template_name.includes('KP Form 18') || d.template_name.includes('KP Form 19'));
    if (hasAbsence) {
        if (hasKp18Or19) {
            step4 = { icon: '✓', label: 'KP 18/19 Issued', sub: 'Show-Cause Issued', cls: 'completed' };
        } else {
            step4 = { icon: '4', label: 'KP 18/19 Issued', sub: 'Pending Notice Generation', cls: 'active' };
        }
    } else if (attendanceCompleted) {
        step4 = { icon: '✓', label: 'KP 18/19 Issued', sub: 'N/A (Both Appeared)', cls: 'completed' };
    }

    // Step 5: Show-Cause Decision
    let step5 = { icon: '5', label: 'Show-Cause Decision', sub: 'Not Required', cls: 'locked' };
    const showCauseHearings = hearings.filter((h) => h.hearing_type.includes('Show Cause'));
    if (showCauseHearings.length > 0) {
        const latestSc = showCauseHearings[showCauseHearings.length - 1];
        if (latestSc.evaluations && latestSc.evaluations.length > 0) {
            const ev = latestSc.evaluations[0];
            if (Number(ev.is_justified) === 1) {
                step5 = { icon: '✓', label: 'Show-Cause Decision', sub: 'Justified (Rescheduled)', cls: 'completed' };
            } else {
                step5 = { icon: '!', label: 'Show-Cause Decision', sub: `Unjustified (${ev.action_taken})`, cls: 'failed' };
            }
        } else {
            step5 = { icon: '5', label: 'Show-Cause Decision', sub: 'Awaiting Finding', cls: 'active' };
        }
    } else if (attendanceCompleted && !hasAbsence) {
        step5 = { icon: '✓', label: 'Show-Cause Decision', sub: 'N/A', cls: 'completed' };
    }

    // Step 6: Next Action / Legal Stage
    let step6 = { icon: '6', label: 'Next Action', sub: 'Pending', cls: 'locked' };
    if (item.case_status === 'DISMISSED_BARRED') {
        step6 = { icon: '🛑', label: 'Case Barred', sub: 'KP Form 21 Issued', cls: 'failed' };
    } else if (item.case_status === 'RESPONDENT_DEFAULT') {
        step6 = { icon: '⚡', label: 'Respondent Default', sub: 'CFA Unlocked', cls: 'completed' };
    } else if (item.case_status === 'CFA Issued') {
        step6 = { icon: '✓', label: 'CFA Issued', sub: 'Endorsed to Court', cls: 'completed' };
    } else if (item.case_status === 'Settled') {
        step6 = { icon: '✓', label: 'Settled', sub: 'Amicable Settlement', cls: 'completed' };
    } else if (item.case_status === 'Conciliation') {
        step6 = { icon: '⚖️', label: 'Conciliation', sub: 'Pangkat Formed', cls: 'active' };
    } else {
        step6 = { icon: '⚖️', label: 'Mediation', sub: 'In Progress', cls: 'active' };
    }

    const steps = [step1, step2, step3, step4, step5, step6];

    container.innerHTML = `
        <div class="kp-stepper-container">
            <div class="kp-stepper-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                Dispute Escalation Workflow (Katarungang Pambarangay)
            </div>
            <div class="kp-stepper">
                ${steps.map((s) => `
                    <div class="kp-step ${s.cls}">
                        <div class="kp-step-icon">${s.icon}</div>
                        <div class="kp-step-label">${caseWorkspaceEscape(s.label)}</div>
                        <div class="kp-step-sub">${caseWorkspaceEscape(s.sub)}</div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
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

/* Pause & Resume Modal Handlers */
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

/* Summon Delivery Modal Handlers */
let activeHearingDeliveries = null;

async function openDeliveryModal(hearingId) {
    const modal = document.getElementById('summonDeliveryModal');
    if (!modal) return;

    document.getElementById('deliveryHearingId').value = hearingId;
    document.getElementById('deliveryRecipientName').value = '';
    document.getElementById('deliveryRelationship').value = '';
    document.getElementById('deliveryFailureNotes').value = '';
    document.getElementById('deliveryRemarks').value = '';
    document.getElementById('deliveryServedAt').value = new Date().toISOString().slice(0, 16);

    try {
        const res = await fetch(`../../../backend/api/hearings/deliveries.php?hearing_id=${encodeURIComponent(hearingId)}`);
        const json = await res.json();
        if (json.success && json.deliveries) {
            activeHearingDeliveries = json.deliveries;
            handleDeliveryPartyChange();
        }
    } catch (e) {
        console.error('Failed to fetch deliveries:', e);
    }

    handleDeliveryStatusChange();
    modal.style.display = 'flex';
}

function closeDeliveryModal() {
    const modal = document.getElementById('summonDeliveryModal');
    if (modal) modal.style.display = 'none';
}

function handleDeliveryPartyChange() {
    const partyType = document.getElementById('deliveryPartyType').value;
    if (!activeHearingDeliveries) return;

    const existing = activeHearingDeliveries.find((d) => d.party_type === partyType);
    if (existing) {
        document.getElementById('deliveryStatusSelect').value = existing.delivery_status !== 'Pending' ? existing.delivery_status : 'Served Personal';
        document.getElementById('deliveryRecipientName').value = existing.recipient_name || (existing.party_profile?.resident_name || '');
        document.getElementById('deliveryRelationship').value = existing.relationship || '';
        document.getElementById('deliveryFailureNotes').value = existing.failure_notes || '';
        document.getElementById('deliveryRemarks').value = existing.remarks || '';
        if (existing.unserved_reason) {
            document.getElementById('deliveryUnservedReason').value = existing.unserved_reason;
        }
    }
    handleDeliveryStatusChange();
}

function handleDeliveryStatusChange() {
    const status = document.getElementById('deliveryStatusSelect').value;
    const servedFields = document.getElementById('servedFieldsGroup');
    const unservedFields = document.getElementById('unservedFieldsGroup');
    const relationshipGroup = document.getElementById('relationshipGroup');

    if (status === 'Unserved') {
        servedFields.style.display = 'none';
        unservedFields.style.display = 'block';
    } else {
        servedFields.style.display = 'block';
        unservedFields.style.display = 'none';
        relationshipGroup.style.display = (status === 'Served Substituted') ? 'block' : 'none';
    }
}

async function handleDeliverySubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submitBtn = document.getElementById('btnSubmitDelivery');
    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());

        const response = await fetch('../../../backend/api/hearings/deliveries.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to save summon delivery.');
        }

        closeDeliveryModal();
        window.agapNotify?.(result.message || 'Delivery record saved.', 'success', 'Delivery Updated');
        await loadWorkspace();
    } catch (err) {
        window.agapNotify?.(err.message, 'error', 'Delivery Update Failed');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

/* Hearing Attendance Modal Handlers */
let activeAttendanceHearing = null;

async function openAttendanceModal(hearingId) {
    const modal = document.getElementById('hearingAttendanceModal');
    if (!modal) return;

    document.getElementById('attendanceHearingId').value = hearingId;
    document.getElementById('attendanceNotes').value = '';

    // Check delivery status
    try {
        const res = await fetch(`../../../backend/api/hearings/deliveries.php?hearing_id=${encodeURIComponent(hearingId)}`);
        const json = await res.json();
        const banner = document.getElementById('attendanceLockBanner');
        const submitBtn = document.getElementById('btnSubmitAttendance');

        if (!json.success || !json.attendance_unlocked) {
            banner.style.display = 'block';
            banner.innerHTML = `<strong>Service Incomplete:</strong> ${caseWorkspaceEscape(json.service_notice || 'Summons unserved. Attendance locked.')}`;
            submitBtn.disabled = true;
        } else {
            banner.style.display = 'none';
            submitBtn.disabled = false;
        }

        if (json.deliveries) {
            json.deliveries.forEach((d) => {
                if (d.party_type === 'Complainant') {
                    document.getElementById('complainantNameLabel').textContent = d.party_profile?.resident_name || 'Complainant';
                    document.getElementById('complainantServiceTag').textContent = `Notice: ${d.delivery_status}`;
                    document.getElementById('complainantServiceTag').className = `delivery-badge delivery-badge-served`;
                } else if (d.party_type === 'Respondent') {
                    document.getElementById('respondentNameLabel').textContent = d.party_profile?.resident_name || 'Respondent';
                    document.getElementById('respondentServiceTag').textContent = `Summon: ${d.delivery_status}`;
                    document.getElementById('respondentServiceTag').className = `delivery-badge delivery-badge-served`;
                }
            });
        }
    } catch (e) {
        console.error(e);
    }

    // Default to both present
    setPartyAttendance('Complainant', 'Present');
    setPartyAttendance('Respondent', 'Present');

    // Default show cause dates (+3 calendar days)
    const defShowCause = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 16);
    document.getElementById('compShowCauseDate').value = defShowCause;
    document.getElementById('respShowCauseDate').value = defShowCause;

    modal.style.display = 'flex';
}

function closeAttendanceModal() {
    const modal = document.getElementById('hearingAttendanceModal');
    if (modal) modal.style.display = 'none';
}

function setPartyAttendance(partyType, status) {
    const isComp = partyType === 'Complainant';
    const input = document.getElementById(isComp ? 'complainantAttendanceInput' : 'respondentAttendanceInput');
    const btnPresent = document.getElementById(isComp ? 'btnCompPresent' : 'btnRespPresent');
    const btnAbsent = document.getElementById(isComp ? 'btnCompAbsent' : 'btnRespAbsent');
    const notice = document.getElementById(isComp ? 'compAbsentNotice' : 'respAbsentNotice');

    input.value = status;
    if (status === 'Present') {
        btnPresent.classList.add('selected');
        btnAbsent.classList.remove('selected');
        notice.style.display = 'none';
    } else {
        btnPresent.classList.remove('selected');
        btnAbsent.classList.add('selected');
        notice.style.display = 'block';
    }
}

async function handleAttendanceSubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submitBtn = document.getElementById('btnSubmitAttendance');
    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());

        const response = await fetch('../../../backend/api/hearings/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to record attendance.');
        }

        closeAttendanceModal();
        window.agapNotify?.(result.message || 'Attendance successfully saved.', 'success', 'Attendance Recorded');
        await loadWorkspace();
    } catch (err) {
        window.agapNotify?.(err.message, 'error', 'Attendance Failed');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

/* Show-Cause Evaluation Modal Handlers */
function openShowCauseModal(hearingId, partyType) {
    const modal = document.getElementById('showCauseModal');
    if (!modal) return;

    document.getElementById('showCauseHearingId').value = hearingId;
    document.getElementById('showCausePartyType').value = partyType;
    document.getElementById('showCauseSubtitle').textContent = `Determine justification for ${partyType}'s failure to appear under KP Form ${partyType === 'Complainant' ? '18' : '19'}.`;
    document.getElementById('scJustificationNotes').value = '';

    const defReschedule = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 16);
    document.getElementById('scRescheduleDate').value = defReschedule;

    const consequenceText = partyType === 'Complainant'
        ? 'Case status becomes DISMISSED_BARRED. KP Form 21 (Bar Action) will be issued. Complainant will be locked from refiling or taking matter to court.'
        : 'Case status becomes RESPONDENT_DEFAULT. Complainant is authorized to receive Certificate to File Action (CFA). KP Form 22 (Bar Counterclaim) issued.';
    document.getElementById('unjustifiedConsequenceText').textContent = consequenceText;

    handleJustifiedOutcomeChange(true);
    modal.style.display = 'flex';
}

function closeShowCauseModal() {
    const modal = document.getElementById('showCauseModal');
    if (modal) modal.style.display = 'none';
}

function handleJustifiedOutcomeChange(isJustified) {
    const group = document.getElementById('rescheduleFieldsGroup');
    const category = document.getElementById('scJustificationCategory');
    if (isJustified) {
        group.style.display = 'block';
        category.value = 'Medical Emergency';
    } else {
        group.style.display = 'none';
        category.value = 'Unjustified Absence';
    }
}

async function handleShowCauseSubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submitBtn = document.getElementById('btnSubmitShowCause');
    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());

        const response = await fetch('../../../backend/api/hearings/show-cause.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to record show-cause decision.');
        }

        closeShowCauseModal();
        window.agapNotify?.(result.message || 'Show-cause decision applied.', 'success', 'Decision Recorded');
        await loadWorkspace();
    } catch (err) {
        window.agapNotify?.(err.message, 'error', 'Show-Cause Evaluation Failed');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}
