(() => {
    const assignmentTable = document.getElementById('assignmentTable');
    const caseSelect = document.getElementById('caseId');
    const form = document.getElementById('teamForm');
    const message = document.getElementById('teamMessage');
    const selects = ['headId', 'secretaryId', 'teamMemberId'].map((id) => document.getElementById(id));
    if (!assignmentTable || !caseSelect || !form || selects.some((select) => !select)) return;

    const api = (url, options = {}) => fetch(url, options).then(async (response) => {
        const data = await response.json().catch(() => ({ message: 'Invalid server response.' }));
        if (!response.ok || data.success === false) throw new Error(data.message || 'Request failed.');
        return data;
    });
    const escapeHtml = (value) => { const node = document.createElement('div'); node.textContent = value ?? ''; return node.innerHTML; };
    const showMessage = (text, success = false) => { message.textContent = text; message.className = text ? `assignment-message ${success ? 'success' : 'error'}` : ''; };

    async function loadCases() {
        const cases = await api('../../../backend/api/cases/list.php');
        caseSelect.replaceChildren(new Option('Select a case', ''));
        cases.filter((item) => item.case_status !== 'Archived').forEach((item) => caseSelect.add(new Option(`${item.case_number} - ${item.complaint_title}`, item.case_id)));
    }

    async function loadMembers() {
        const members = await api('../../../backend/api/assignments/lupon-members.php');
        selects.forEach((select) => {
            select.replaceChildren(new Option('Select a Lupon Member', ''));
            members.forEach((member) => select.add(new Option(`${member.last_name}, ${member.first_name}`, member.member_id)));
        });
    }

    async function loadAssignments() {
        if (!caseSelect.value) {
            assignmentTable.innerHTML = '<tr><td colspan="3" class="empty-state">Select a case to view its team.</td></tr>';
            return;
        }
        try {
            const assignments = await api('../../../backend/api/assignments/list.php?case_id=' + encodeURIComponent(caseSelect.value));
            assignmentTable.innerHTML = assignments.length
                ? assignments.map((item) => `<tr><td>${escapeHtml(item.last_name)}, ${escapeHtml(item.first_name)}</td><td>${escapeHtml(item.assignment_role)}</td><td>${escapeHtml(item.assigned_date)}</td></tr>`).join('')
                : '<tr><td colspan="3" class="empty-state">No case team has been assigned.</td></tr>';
            const byRole = Object.fromEntries(assignments.map((item) => [item.assignment_role, String(item.member_id)]));
            document.getElementById('headId').value = byRole.Head || '';
            document.getElementById('secretaryId').value = byRole.Secretary || '';
            document.getElementById('teamMemberId').value = byRole.Member || '';
        } catch (error) {
            assignmentTable.innerHTML = `<tr><td colspan="3" class="empty-state">${escapeHtml(error.message)}</td></tr>`;
        }
    }

    window.openCaseAssignments = (caseId) => {
        caseSelect.value = String(caseId);
        document.getElementById('caseAssignments').scrollIntoView({ behavior: 'smooth', block: 'start' });
        loadAssignments();
    };

    caseSelect.addEventListener('change', loadAssignments);
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        showMessage('');
        const ids = selects.map((select) => select.value).filter(Boolean);
        if (!caseSelect.value || ids.length !== 3) { showMessage('Select a case and all three team members before saving.'); return; }
        if (new Set(ids).size !== 3) { showMessage('Head, Secretary, and Member must be different Lupon Members.'); return; }
        try {
            const result = await api('../../../backend/api/assignments/team.php', { method: 'POST', body: new FormData(form) });
            showMessage(result.message, true);
            await loadAssignments();
        } catch (error) { showMessage(error.message); }
    });

    Promise.all([loadCases(), loadMembers()]).catch((error) => showMessage(error.message));
})();
