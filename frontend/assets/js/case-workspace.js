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
        document.getElementById('hearingLink').href = `../hearings/schedules.php?case_id=${encodeURIComponent(item.case_id)}`;
        document.getElementById('caseOverview').innerHTML = `<p><strong>Complaint:</strong> ${caseWorkspaceEscape(item.complaint_number)} — ${caseWorkspaceEscape(item.complaint_title)}</p><p><strong>Type:</strong> ${caseWorkspaceEscape(item.case_type)}</p><p><strong>Status:</strong> ${caseWorkspaceEscape(item.case_status)}</p><p><strong>Docketed:</strong> ${caseWorkspaceEscape(item.docket_date)}</p>`;
        document.getElementById('caseTeam').innerHTML = workspaceList(data.assignments, (a) => `<li><strong>${caseWorkspaceEscape(a.assignment_role)}:</strong> ${caseWorkspaceEscape(a.member_name)}</li>`, 'No team has been assigned.');
        document.getElementById('caseHearings').innerHTML = workspaceList(data.hearings, (h) => `<li><strong>${caseWorkspaceEscape(h.hearing_type)}</strong> — ${caseWorkspaceEscape(h.hearing_date)}<br>${caseWorkspaceEscape(h.venue || 'Venue pending')} · ${caseWorkspaceEscape(h.hearing_status)}</li>`, 'No hearings are scheduled.');
        document.getElementById('caseDocuments').innerHTML = workspaceList(data.documents, (d) => `<li><strong>${caseWorkspaceEscape(d.template_name)}</strong><br>${caseWorkspaceEscape(d.generated_at)} · ${caseWorkspaceEscape(d.service_status)}<br><a href="../gps/proof-service.php?case_id=${encodeURIComponent(item.case_id)}&document_id=${encodeURIComponent(d.document_id)}">Record proof of service</a></li>`, 'No documents have been generated.');
        document.getElementById('caseProofs').innerHTML = workspaceList(data.proofs, (p) => `<li><strong>${caseWorkspaceEscape(p.template_name || 'Legacy service record')}</strong><br>${caseWorkspaceEscape(p.served_date)} by ${caseWorkspaceEscape(p.served_by_name)}${p.remarks ? `<br>${caseWorkspaceEscape(p.remarks)}` : ''}</li>`, 'No proof of service has been recorded.');
    } catch (error) { message.textContent = error.message; }
});
