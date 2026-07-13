const complaintApi = async (url, options = {}) => {
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok) throw new Error(data.message || 'Request failed.');
    return data;
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
})[character]);

document.addEventListener('DOMContentLoaded', () => {

    // Handle complaint list
    const table = document.getElementById('complaintTable');
    if (table) {
        loadComplaintList();
    }

    // Handle complaint details page
    if (document.getElementById('complaintNumber')) {
        loadComplaintDetails();
    }

    // Handle add party form
    const addPartyForm = document.getElementById('addPartyForm');
    if (addPartyForm) {
        addPartyForm.addEventListener('submit', handleAddParty);
    }

    // Handle add attachment form
    const addAttachmentForm = document.getElementById('addAttachmentForm');
    if (addAttachmentForm) {
        addAttachmentForm.addEventListener('submit', handleAddAttachment);
    }
});

function loadComplaintList() {
    fetch('../../../backend/api/complaints/list.php')
    .then(response => response.json())
    .then(data => {
        const table = document.getElementById('complaintTable');
        let rows = '';
        data.forEach(complaint => {
            rows += `
                <tr>
                    <td>${Number(complaint.complaint_id)}</td>
                    <td>${escapeHtml(complaint.complaint_number)}</td>
                    <td>${escapeHtml(complaint.complaint_title)}</td>
                    <td><span class="status status-${String(complaint.status).toLowerCase()}">${escapeHtml(complaint.status)}</span></td>
                    <td class="action-buttons">
                        <button type="button" onclick="window.location.href='complaint-details.php?id=${complaint.complaint_id}'">View</button>
                        <button type="button" onclick="editComplaint(${complaint.complaint_id})">Edit</button>
                        <button type="button" class="delete-button" onclick="deleteComplaint(${complaint.complaint_id})">Delete</button>
                    </td>
                </tr>
            `;
        });
        table.innerHTML = rows;
    });
}

function loadComplaintDetails() {
    // Get complaint ID from URL
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id');
    if (!complaintId) return;

    // Load complaint info
    fetch('../../../backend/api/complaints/view.php?id=' + complaintId)
    .then(response => response.json())
    .then(data => {
        if (!data) {
            window.location.href = 'complaint-list.php';
            return;
        }
        document.getElementById('complaintNumber').textContent = 'Complaint #' + data.complaint_number;
        document.getElementById('complaintTitle').textContent = data.complaint_title;

        document.getElementById('complaintInfo').innerHTML = `
            <p><strong>Category:</strong> ${escapeHtml(data.category_id)}</p>
            <p><strong>Incident Date:</strong> ${escapeHtml(data.incident_date || 'N/A')}</p>
            <p><strong>Status:</strong> <span class="status status-${escapeHtml(String(data.status).toLowerCase())}">${escapeHtml(data.status)}</span></p>
            <p><strong>Narrative:</strong></p>
            <p style="white-space: pre-wrap;">${escapeHtml(data.narrative)}</p>
        `;

        // Load parties
        renderParties(data.parties || []);
        // Load attachments
        renderAttachments(data.attachments || []);
    });

    // Load residents for add party modal
    loadResidents();

    // Set complaint ID in modals
    document.getElementById('partyComplaintId').value = complaintId;
    document.getElementById('attachmentComplaintId').value = complaintId;
}

function loadResidents() {
    fetch('../../../backend/api/residents/list.php')
    .then(response => response.json())
    .then(data => {
        const select = document.getElementById('partyResidentId');
        if (!select) return;
        let options = '<option value="">Select Resident</option>';
        data.forEach(resident => {
            const name = [resident.first_name, resident.middle_name, resident.last_name].filter(Boolean).join(' ');
            options += `<option value="${resident.resident_id}">${escapeHtml(name)}</option>`;
        });
        select.innerHTML = options;
    });
}

function renderParties(parties) {
    const tbody = document.getElementById('partiesTable');
    if (!tbody) return;
    if (parties.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; padding: 16px;">No parties added yet</td></tr>';
        return;
    }
    let rows = '';
    parties.forEach(party => {
        rows += `
            <tr>
                <td>${escapeHtml(party.resident_name)}</td>
                <td>${escapeHtml(party.party_type)}</td>
                <td class="action-buttons"><button type="button" class="delete-button" onclick="deleteParty(${Number(party.party_id)})">Remove</button></td>
            </tr>
        `;
    });
    tbody.innerHTML = rows;
}

function renderAttachments(attachments) {
    const tbody = document.getElementById('attachmentsTable');
    if (!tbody) return;
    if (attachments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 16px;">No attachments added yet</td></tr>';
        return;
    }
    let rows = '';
    attachments.forEach(attachment => {
        rows += `
            <tr>
                <td><a href="../../../backend/api/complaints/download-attachment.php?id=${Number(attachment.attachment_id)}">${escapeHtml(attachment.file_name)}</a></td>
                <td>${escapeHtml(attachment.file_type)}</td>
                <td>${new Date(attachment.uploaded_at).toLocaleString()}</td>
                <td class="action-buttons">
                    <button type="button" class="delete-button" onclick="deleteAttachment(${attachment.attachment_id})">Delete</button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = rows;
}

function openAddPartyModal() {
    document.getElementById('addPartyModal').style.display = 'flex';
}

function closeAddPartyModal() {
    document.getElementById('addPartyModal').style.display = 'none';
}

function openAddAttachmentModal() {
    document.getElementById('addAttachmentModal').style.display = 'flex';
}

function closeAddAttachmentModal() {
    document.getElementById('addAttachmentModal').style.display = 'none';
}

function handleAddParty(e) {
    e.preventDefault();
    const formData = new FormData();
    formData.append('complaint_id', document.getElementById('partyComplaintId').value);
    formData.append('resident_id', document.getElementById('partyResidentId').value);
    formData.append('party_type', document.getElementById('partyType').value);

    complaintApi('../../../backend/api/complaints/parties.php', { method: 'POST', body: formData })
        .then(() => {
            closeAddPartyModal();
            document.getElementById('addPartyForm').reset();
            loadComplaintDetails();
        })
        .catch(error => alert(error.message));
}

function deleteParty(partyId) {
    if (!confirm('Remove this party from the complaint?')) return;
    complaintApi('../../../backend/api/complaints/parties.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'party_id=' + encodeURIComponent(partyId)
    }).then(loadComplaintDetails).catch(error => alert(error.message));
}
function handleAddAttachment(e) {
    e.preventDefault();
    const file = document.getElementById('attachmentFile').files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('complaint_id', document.getElementById('attachmentComplaintId').value);
    formData.append('attachment', file);

    complaintApi('../../../backend/api/complaints/attachments.php', { method: 'POST', body: formData })
        .then(() => {
            closeAddAttachmentModal();
            document.getElementById('addAttachmentForm').reset();
            loadComplaintDetails();
        })
        .catch(error => alert(error.message));
}
function deleteAttachment(attachmentId) {
    if (!confirm('Are you sure you want to delete this attachment?')) return;
    complaintApi('../../../backend/api/complaints/attachments.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'attachment_id=' + encodeURIComponent(attachmentId)
    }).then(loadComplaintDetails).catch(error => alert(error.message));
}

// Keep existing functions for backward compatibility
function openAddComplaintModal() {
    document.getElementById('addComplaintModal').style.display = 'flex';
}

function closeAddComplaintModal() {
    document.getElementById('addComplaintModal').style.display = 'none';
}

function viewComplaint(id) {
    window.location.href = 'complaint-details.php?id=' + id;
}

function closeViewComplaintModal() {
    document.getElementById('viewComplaintModal').style.display = 'none';
}

function editComplaint(id) {
    fetch('../../../backend/api/complaints/view.php?id=' + id)
    .then(response => response.json())
    .then(data => {
        document.getElementById('editComplaintId').value = data.complaint_id;
        document.getElementById('editCategoryId').value = data.category_id;
        document.getElementById('editComplaintTitle').value = data.complaint_title;
        document.getElementById('editIncidentDate').value = data.incident_date;
        document.getElementById('editNarrative').value = data.narrative;
        document.getElementById('editStatus').value = data.status;
        document.getElementById('editComplaintModal').style.display = 'flex';
    });
}

function closeEditComplaintModal() {
    document.getElementById('editComplaintModal').style.display = 'none';
}

function deleteComplaint(id) {
    document.getElementById('deleteComplaintId').value = id;
    document.getElementById('deleteComplaintModal').style.display = 'flex';
}

function closeDeleteComplaintModal() {
    document.getElementById('deleteComplaintModal').style.display = 'none';
}

function confirmDeleteComplaint() {
    const id = document.getElementById('deleteComplaintId').value;
    window.location.href = '../../../backend/api/complaints/delete.php?id=' + id;
}
