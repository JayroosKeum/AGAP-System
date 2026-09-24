const caseWorkspaceEscape = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[c]);
const workspaceList = (items, render, empty) => items.length ? `<ul class="workspace-list">${items.map(render).join('')}</ul>` : `<p class="empty-state">${empty}</p>`;
document.addEventListener('DOMContentLoaded', async () => {
    const message = document.getElementById('workspaceMessage');
    try {
        const response = await fetch(`../../../backend/api/cases/workspace.php?id=${encodeURIComponent(window.caseWorkspaceId)}`);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load the case workspace.');
        const data = result.data; const item = data.case;
        document.getElementById('workspaceTitle').textContent = item.case_number || 'Case Workspace';
        document.getElementById('workspaceSubtitle').textContent = item.complaint_title;
        document.getElementById('assignLink').href = 'case-list.php#caseAssignments';
        const hearingLink = document.getElementById('hearingLink');
        if (hearingLink) {
            const isClosed = ['Archived', 'Settled', 'Dismissed', 'CFA Issued'].includes(item.case_status);
            const mediationHearings = (data.hearings || []).filter((h) => h.hearing_type === 'Mediation');
            const conciliationHearings = (data.hearings || []).filter((h) => h.hearing_type === 'Conciliation');
            const mCount = mediationHearings.length;
            const cCount = conciliationHearings.length;

            if (isClosed) {
                hearingLink.hidden = true;
            } else if (mCount < 3) {
                const nextSeq = mCount + 1;
                const label = nextSeq === 1 ? '1st' : (nextSeq === 2 ? '2nd' : '3rd');
                hearingLink.textContent = `Schedule ${label} Mediation`;
                hearingLink.href = `../hearings/schedules.php?case_id=${encodeURIComponent(item.case_id)}`;
                hearingLink.hidden = false;
                hearingLink.classList.remove('is-disabled');
                hearingLink.removeAttribute('aria-disabled');
            } else if (cCount < 3) {
                const nextSeq = cCount + 1;
                const label = nextSeq === 1 ? '1st' : (nextSeq === 2 ? '2nd' : '3rd');
                hearingLink.textContent = `Schedule ${label} Conciliation`;
                hearingLink.href = `../hearings/schedules.php?case_id=${encodeURIComponent(item.case_id)}`;
                hearingLink.hidden = false;
                hearingLink.classList.remove('is-disabled');
                hearingLink.removeAttribute('aria-disabled');
            } else {
                hearingLink.textContent = 'All schedules completed';
                hearingLink.removeAttribute('href');
                hearingLink.hidden = false;
                hearingLink.classList.add('is-disabled');
                hearingLink.setAttribute('aria-disabled', 'true');
            }
        }
        document.getElementById('caseOverview').innerHTML = `<p><strong>Complaint:</strong> ${caseWorkspaceEscape(item.complaint_number)} — ${caseWorkspaceEscape(item.complaint_title)}</p><p><strong>Type:</strong> ${caseWorkspaceEscape(item.case_type)}</p><p><strong>Status:</strong> ${caseWorkspaceEscape(item.case_status)}</p><p><strong>Docketed:</strong> ${caseWorkspaceEscape(item.docket_date)}</p>`;
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
        document.getElementById('caseHearings').innerHTML = workspaceList(data.hearings, (h) => `<li><strong>${caseWorkspaceEscape(formatHearingType(h))}</strong> — ${caseWorkspaceEscape(h.hearing_date)}<br>${caseWorkspaceEscape(h.venue || 'Venue pending')} · ${caseWorkspaceEscape(h.hearing_status)}</li>`, 'No hearings are scheduled.');
        document.getElementById('caseDocuments').innerHTML = workspaceList(data.documents, (d) => `<li><strong>${caseWorkspaceEscape(d.template_name)}</strong><br>${caseWorkspaceEscape(d.generated_at)} · ${caseWorkspaceEscape(d.service_status)}<br><a href="../gps/proof-service.php?case_id=${encodeURIComponent(item.case_id)}&document_id=${encodeURIComponent(d.document_id)}">Record proof of service</a></li>`, 'No documents have been generated.');
        document.getElementById('caseProofs').innerHTML = workspaceList(data.proofs, (p) => `<li><strong>${caseWorkspaceEscape(p.template_name || 'Legacy service record')}</strong><br>${caseWorkspaceEscape(p.served_date)} by ${caseWorkspaceEscape(p.served_by_name)}${p.remarks ? `<br>${caseWorkspaceEscape(p.remarks)}` : ''}</li>`, 'No proof of service has been recorded.');
    } catch (error) { message.textContent = error.message; }
});
