const api = (url, options) => fetch(url, options).then(async (response) => {
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Request failed.');
    return data;
});

const escapeHtml = (value) => {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
};

const assignmentTable = document.getElementById('assignmentTable');
const assignmentMessage = document.getElementById('assignmentMessage');

async function loadCases() {
    const cases = await api('../../../backend/api/cases/list.php');
    const active = cases.filter((item) => item.case_status !== 'Archived');
    document.getElementById('caseId').innerHTML = '<option value="">Select a case</option>' + active.map((item) =>
        `<option value="${Number(item.case_id)}">${escapeHtml(item.case_number)} - ${escapeHtml(item.complaint_title)}</option>`
    ).join('');
}

async function loadLuponMembers() {
    const members = await api('../../../backend/api/assignments/lupon-members.php');
    document.getElementById('memberId').innerHTML = '<option value="">Select a Lupon member</option>' + members.map((member) =>
        `<option value="${Number(member.member_id)}">${escapeHtml(member.last_name)}, ${escapeHtml(member.first_name)}${member.designation ? ` (${escapeHtml(member.designation)})` : ''}</option>`
    ).join('');
}

async function loadAssignments() {
    const caseId = document.getElementById('caseId').value;
    if (!caseId) {
        assignmentTable.innerHTML = '<tr><td colspan="3" class="empty-state">Select a case to view assignments.</td></tr>';
        return;
    }

    try {
        const assignments = await api('../../../backend/api/assignments/list.php?case_id=' + encodeURIComponent(caseId));
        assignmentTable.innerHTML = assignments.length ? assignments.map((item) => `<tr>
            <td>${escapeHtml(item.last_name)}, ${escapeHtml(item.first_name)}</td>
            <td>${escapeHtml(item.assignment_role)}</td>
            <td>${escapeHtml(item.assigned_date)}</td>
        </tr>`).join('') : '<tr><td colspan="3" class="empty-state">No Lupon members have been assigned to this case.</td></tr>';
    } catch (error) {
        assignmentTable.innerHTML = `<tr><td colspan="3" class="empty-state">${escapeHtml(error.message)}</td></tr>`;
    }
}

document.getElementById('caseId').addEventListener('change', loadAssignments);
document.getElementById('assignmentForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    assignmentMessage.textContent = '';
    const selectedCaseId = document.getElementById('caseId').value;
    try {
        const result = await api('../../../backend/api/assignments/assign.php', { method: 'POST', body: new FormData(event.currentTarget) });
        assignmentMessage.textContent = result.message;
        event.currentTarget.reset();
        document.getElementById('caseId').value = selectedCaseId;
        await loadAssignments();
    } catch (error) {
        assignmentMessage.textContent = error.message;
    }
});

Promise.all([loadCases(), loadLuponMembers()]).catch((error) => {
    assignmentMessage.textContent = error.message;
});
