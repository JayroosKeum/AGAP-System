let pangkats = [];

document.addEventListener('DOMContentLoaded', () => {
    loadPangkats().then(loadCases);
    loadLuponMembers();
    document.getElementById('createPangkatForm').addEventListener('submit', createPangkat);
    document.getElementById('addMemberForm').addEventListener('submit', addMember);
});

const api = (url, options) => fetch(url, options).then(r => r.ok ? r.json() : Promise.reject());

function esc(v) {
    const d = document.createElement('div');
    d.textContent = v ?? '';
    return d.innerHTML;
}

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function loadPangkats() {
    return api('../../../backend/api/pangkat/list.php').then(data => {
        pangkats = data;
        const table = document.getElementById('pangkatTable');
        table.innerHTML = data.length 
            ? data.map(p => `
                <tr>
                    <td>${esc(p.case_number)}</td>
                    <td>${esc(p.complaint_number)} - ${esc(p.complaint_title)}</td>
                    <td>${esc(p.formation_date)}</td>
                    <td><span class="member-count">${esc(p.member_count)} member(s)</span></td>
                    <td class="action-buttons">
                        <button type="button" onclick="manageMembers(${Number(p.pangkat_id)})">Members</button>
                    </td>
                </tr>
            `).join('') 
            : '<tr><td colspan="5" class="empty-state">No Pangkat groups have been formed yet.</td></tr>';
    });
}

function loadCases() {
    api('../../../backend/api/cases/list.php').then(cases => {
        const used = new Set(pangkats.map(p => String(p.case_id)));
        document.getElementById('pangkatCaseId').innerHTML = '<option value="">Select a case</option>' + 
            cases.filter(c => c.case_status !== 'Archived' && !used.has(String(c.case_id)))
                 .map(c => `<option value="${Number(c.case_id)}">${esc(c.case_number)} - ${esc(c.complaint_title)}</option>`)
                 .join('');
    });
}

function loadLuponMembers() {
    api('../../../backend/api/pangkat/lupon-members.php').then(users => {
        document.getElementById('luponMemberId').innerHTML = users.map(u => 
            `<option value="${Number(u.user_id)}">${esc(u.last_name)}, ${esc(u.first_name)}</option>`
        ).join('');
    });
}

function createPangkat(e) {
    e.preventDefault();
    fetch('../../../backend/api/pangkat/create.php', {
        method: 'POST',
        body: new FormData(e.target)
    })
    .then(r => r.json())
    .then(data => {
        if (!data.pangkat_id) {
            alert('This case already has a Pangkat.');
            return;
        }
        closeModal('createPangkatModal');
        e.target.reset();
        loadPangkats().then(loadCases);
    });
}

function manageMembers(id) {
    document.getElementById('memberPangkatId').value = id;
    loadMembers(id);
    openModal('membersModal');
}

function loadMembers(id) {
    api('../../../backend/api/pangkat/members.php?pangkat_id=' + id).then(data => {
        document.getElementById('membersList').innerHTML = data.length 
            ? data.map(m => `<p><strong>${esc(m.position)}:</strong> ${esc(m.first_name)} ${esc(m.last_name)}</p>`).join('') 
            : '<p>No members assigned yet.</p>';
    });
}

function addMember(e) {
    e.preventDefault();
    const id = document.getElementById('memberPangkatId').value;
    fetch('../../../backend/api/pangkat/update.php', {
        method: 'POST',
        body: new FormData(e.target)
    })
    .then(r => r.json())
    .then(() => {
        e.target.reset();
        document.getElementById('memberPangkatId').value = id;
        loadMembers(id);
        loadPangkats();
    });
}