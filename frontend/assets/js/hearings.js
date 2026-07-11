document.addEventListener('DOMContentLoaded', () => {
    loadHearings();
    loadCases();
});

function loadHearings() {
    const table = document.getElementById('hearingTable');

    fetch('../../../backend/api/hearings/calendar.php')
        .then(response => response.ok ? response.json() : Promise.reject())
        .then(hearings => {
            if (!hearings.length) {
                table.innerHTML = '<tr><td colspan="6" class="empty-state">No hearings have been scheduled yet.</td></tr>';
                return;
            }

            table.innerHTML = hearings.map(item => `
                <tr>
                    <td>${escapeHtml(item.case_number)}</td>
                    <td>${escapeHtml(item.complaint_number)} - ${escapeHtml(item.complaint_title)}</td>
                    <td><span class="hearing-type">${escapeHtml(item.hearing_type)}</span></td>
                    <td>${formatDateTime(item.hearing_date)}</td>
                    <td>${escapeHtml(item.venue)}</td>
                    <td class="action-buttons">
                        <button type="button" onclick="viewHearing(${Number(item.hearing_id)})">View</button>
                        <button type="button" onclick="editHearing(${Number(item.hearing_id)})">Edit</button>
                    </td>
                </tr>`).join('');
        })
        .catch(() => {
            table.innerHTML = '<tr><td colspan="6" class="empty-state">Unable to load hearing schedules. Please refresh and try again.</td></tr>';
        });
}

function loadCases() {
    const select = document.getElementById('hearingCaseId');

    fetch('../../../backend/api/cases/list.php')
        .then(response => response.ok ? response.json() : Promise.reject())
        .then(cases => {
            const activeCases = cases.filter(item => item.case_status !== 'Archived');
            select.innerHTML = '<option value="">Select a case</option>' + activeCases.map(item =>
                `<option value="${Number(item.case_id)}">${escapeHtml(item.case_number)} - ${escapeHtml(item.complaint_title)}</option>`
            ).join('');
        })
        .catch(() => { select.innerHTML = '<option value="">Unable to load cases</option>'; });
}

function getHearing(id) {
    return fetch('../../../backend/api/hearings/view.php?id=' + encodeURIComponent(id))
        .then(response => response.ok ? response.json() : Promise.reject());
}

function escapeHtml(value) {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
}

function formatDateTime(value) {
    if (!value) return '';
    return new Date(value.replace(' ', 'T')).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

function toDateTimeLocal(value) {
    return value ? value.replace(' ', 'T').slice(0, 16) : '';
}

function showModal(id) { document.getElementById(id).style.display = 'flex'; }
function hideModal(id) { document.getElementById(id).style.display = 'none'; }
function openAddHearingModal() { showModal('addHearingModal'); }
function closeAddHearingModal() { hideModal('addHearingModal'); }
function closeViewHearingModal() { hideModal('viewHearingModal'); }
function closeEditHearingModal() { hideModal('editHearingModal'); }

function viewHearing(id) {
    getHearing(id).then(item => {
        document.getElementById('hearingDetails').innerHTML = `
            <dl class="hearing-details">
                <dt>Case</dt><dd>${escapeHtml(item.case_number)}</dd>
                <dt>Complaint</dt><dd>${escapeHtml(item.complaint_number)} - ${escapeHtml(item.complaint_title)}</dd>
                <dt>Type</dt><dd>${escapeHtml(item.hearing_type)}</dd>
                <dt>Date & Time</dt><dd>${formatDateTime(item.hearing_date)}</dd>
                <dt>Venue</dt><dd>${escapeHtml(item.venue)}</dd>
                <dt>Remarks</dt><dd>${escapeHtml(item.remarks) || 'None'}</dd>
            </dl>`;
        showModal('viewHearingModal');
    });
}

function editHearing(id) {
    getHearing(id).then(item => {
        document.getElementById('editHearingId').value = item.hearing_id;
        document.getElementById('editHearingType').value = item.hearing_type;
        document.getElementById('editHearingDate').value = toDateTimeLocal(item.hearing_date);
        document.getElementById('editHearingVenue').value = item.venue ?? '';
        document.getElementById('editHearingRemarks').value = item.remarks ?? '';
        showModal('editHearingModal');
    });
}
