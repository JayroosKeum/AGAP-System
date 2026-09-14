(() => {
    const assignmentTable = document.getElementById('assignmentTable');
    const assignmentMessage = document.getElementById('assignmentMessage');
    const caseSelect = document.getElementById('caseId');
    const memberSelect = document.getElementById('memberId');
    const form = document.getElementById('assignmentForm');
    if (!assignmentTable || !assignmentMessage || !caseSelect || !memberSelect || !form) return;

    const api = (url, options) => fetch(url, options).then(async (response) => {
        const data = await response.json().catch(() => ({ message: 'Invalid server response.' }));
        if (!response.ok || data.success === false) throw new Error(data.message || 'Request failed.');
        return data;
    });
    const escapeHtml = (value) => {
        const node = document.createElement('div');
        node.textContent = value ?? '';
        return node.innerHTML;
    };
    const showMessage = (message, success = false) => {
        assignmentMessage.textContent = message;
        assignmentMessage.className = message ? (success ? 'assignment-message success' : 'assignment-message error') : '';
    };

    async function loadCases() {
        const cases = await api('../../../backend/api/cases/list.php');
        const active = cases.filter((item) => item.case_status !== 'Archived');
        caseSelect.replaceChildren(new Option('Select a case', ''));
        active.forEach((item) => caseSelect.add(new Option(`${item.case_number} - ${item.complaint_title}`, item.case_id)));
    }

    async function loadLuponMembers() {
        const members = await api('../../../backend/api/assignments/lupon-members.php');
        memberSelect.replaceChildren(new Option('Select a Lupon Member', ''));
        members.forEach((member) => memberSelect.add(new Option(`${member.last_name}, ${member.first_name}`, member.member_id)));
    }

    async function loadAssignments() {
        const caseId = caseSelect.value;
        if (!caseId) {
            assignmentTable.innerHTML = '<tr><td colspan="3" class="empty-state">Select a case to view assignments.</td></tr>';
            return;
        }
        try {
            const assignments = await api('../../../backend/api/assignments/list.php?case_id=' + encodeURIComponent(caseId));
            assignmentTable.innerHTML = assignments.length ? assignments.map((item) => `<tr><td>${escapeHtml(item.last_name)}, ${escapeHtml(item.first_name)}</td><td>${escapeHtml(item.assignment_role)}</td><td>${escapeHtml(item.assigned_date)}</td></tr>`).join('') : '<tr><td colspan="3" class="empty-state">No Lupon Members have been assigned to this case.</td></tr>';
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
        const selectedCaseId = caseSelect.value;
        try {
            const result = await api('../../../backend/api/assignments/assign.php', { method: 'POST', body: new FormData(form) });
            showMessage(result.message, true);
            form.reset();
            caseSelect.value = selectedCaseId;
            await loadAssignments();
        } catch (error) {
            showMessage(error.message);
        }
    });

    Promise.all([loadCases(), loadLuponMembers()]).catch((error) => showMessage(error.message));
})();
