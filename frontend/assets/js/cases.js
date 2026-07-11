let complaintIds = new Set();
let docketedComplaintIds = new Set();
let complaintsForDocket = [];

document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('caseTable');
    const complaintInput = document.getElementById('complaintId');
    const complaintSelect = document.getElementById('availableComplaints');
    const docketForm = document.getElementById('docketCaseForm');
    if (!table) return;

    fetch('../../../backend/api/cases/list.php')
        .then(response => response.ok ? response.json() : Promise.reject(response.statusText))
        .then(cases => {
            docketedComplaintIds = new Set(cases.map(item => String(item.complaint_id)));
            renderComplaintOptions();
            if (!cases.length) {
                table.innerHTML = '<tr><td colspan="6" class="empty-state">No cases have been docketed yet.</td></tr>';
                return;
            }

            table.innerHTML = cases.map(item => `
                <tr>
                    <td>${escapeHtml(item.case_number)}</td>
                    <td>${escapeHtml(item.complaint_number)} - ${escapeHtml(item.complaint_title)}</td>
                    <td>${escapeHtml(item.case_type)}</td>
                    <td>${escapeHtml(item.docket_date)}</td>
                    <td><span class="status status-${String(item.case_status).toLowerCase()}">${escapeHtml(item.case_status)}</span></td>
                    <td class="action-buttons">
                        <button type="button" onclick="viewCase(${Number(item.case_id)})">View</button>
                        <button type="button" onclick="editCase(${Number(item.case_id)})">Edit</button>
                        ${item.case_status !== 'Archived' ? `<button type="button" class="archive-button" onclick="archiveCase(${Number(item.case_id)})">Archive</button>` : ''}
                    </td>
                </tr>
            `).join('');
        })
        .catch(() => {
            table.innerHTML = '<tr><td colspan="6" class="empty-state">Unable to load cases. Please refresh and try again.</td></tr>';
        });

    fetch('../../../backend/api/complaints/list.php')
        .then(response => response.ok ? response.json() : Promise.reject(response.statusText))
        .then(complaints => {
            complaintsForDocket = complaints;
            complaintIds = new Set(complaints.map(item => String(item.complaint_id)));
            renderComplaintOptions();
        });

    complaintInput.addEventListener('input', validateComplaintId);
    complaintSelect.addEventListener('change', () => {
        complaintInput.value = complaintSelect.value;
        validateComplaintId();
    });
    docketForm.addEventListener('submit', event => {
        if (!validateComplaintId()) event.preventDefault();
    });
});

function renderComplaintOptions() {
    const select = document.getElementById('availableComplaints');
    if (!select || !complaintsForDocket.length) return;

    select.innerHTML = complaintsForDocket.map(item => {
        const docketed = docketedComplaintIds.has(String(item.complaint_id));
        return `<option value="${Number(item.complaint_id)}" ${docketed ? 'disabled' : ''}>${escapeHtml(item.complaint_id)} - ${escapeHtml(item.complaint_number)} - ${escapeHtml(item.complaint_title)}${docketed ? ' (Already docketed)' : ''}</option>`;
    }).join('');
}

function validateComplaintId() {
    const input = document.getElementById('complaintId');
    const warning = document.getElementById('complaintIdWarning');
    const complaintId = input.value.trim();

    warning.hidden = true;
    input.setCustomValidity('');
    if (!complaintId) return false;

    if (!complaintIds.has(complaintId)) {
        warning.textContent = 'This Complaint ID does not exist. Please select a complaint from the list.';
    } else if (docketedComplaintIds.has(complaintId)) {
        warning.textContent = 'This complaint has already been docketed.';
    } else {
        return true;
    }

    warning.hidden = false;
    input.setCustomValidity(warning.textContent);
    return false;
}

function escapeHtml(value) {
    const node = document.createElement('div');
    node.textContent = value ?? '';
    return node.innerHTML;
}

function showModal(id) { document.getElementById(id).style.display = 'flex'; }
function hideModal(id) { document.getElementById(id).style.display = 'none'; }
function openAddCaseModal() { showModal('addCaseModal'); }
function closeAddCaseModal() { hideModal('addCaseModal'); }
function closeViewCaseModal() { hideModal('viewCaseModal'); }
function closeEditCaseModal() { hideModal('editCaseModal'); }
function closeArchiveCaseModal() { hideModal('archiveCaseModal'); }

function getCase(id) {
    return fetch('../../../backend/api/cases/view.php?id=' + encodeURIComponent(id))
        .then(response => response.ok ? response.json() : Promise.reject(response.statusText));
}

function viewCase(id) {
    getCase(id).then(item => {
        document.getElementById('caseDetails').innerHTML = `
            <dl class="case-details">
                <dt>Case Number</dt><dd>${escapeHtml(item.case_number)}</dd>
                <dt>Complaint</dt><dd>${escapeHtml(item.complaint_number)} - ${escapeHtml(item.complaint_title)}</dd>
                <dt>Case Type</dt><dd>${escapeHtml(item.case_type)}</dd>
                <dt>Status</dt><dd>${escapeHtml(item.case_status)}</dd>
                <dt>Docket Date</dt><dd>${escapeHtml(item.docket_date)}</dd>
                <dt>Incident Date</dt><dd>${escapeHtml(item.incident_date)}</dd>
                <dt>Narrative</dt><dd>${escapeHtml(item.narrative)}</dd>
            </dl>`;
        showModal('viewCaseModal');
    });
}

function editCase(id) {
    getCase(id).then(item => {
        document.getElementById('editCaseId').value = item.case_id;
        document.getElementById('editCaseType').value = item.case_type;
        document.getElementById('editCaseStatus').value = item.case_status;
        showModal('editCaseModal');
    });
}

function archiveCase(id) {
    document.getElementById('archiveCaseId').value = id;
    showModal('archiveCaseModal');
}
