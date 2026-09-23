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

    const mediationForm = document.getElementById('scheduleMediationForm');
    if (mediationForm) {
        mediationForm.addEventListener('submit', reviewMediationSchedule);
        const dateInput = document.getElementById('mediationDate');
        if (dateInput) dateInput.min = new Date().toISOString().slice(0, 10);
    }
    document.getElementById('confirmMediationButton')?.addEventListener('click', submitMediationSchedule);

    initialiseComplaintMaps();

    const flash = window.agapComplaintFlash;
    if (flash?.message) {
        window.agapNotify?.(flash.message, flash.type || 'error');

        if (flash.open_modal === 'add') {
            openAddComplaintModal();
            restoreComplaintForm('addComplaintModal', flash.old);
        } else if (flash.open_modal === 'edit') {
            populateEditComplaintForm(flash.old);
        }
    }
});

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

        const scheduleButton = document.getElementById('scheduleMediationButton');
        if (scheduleButton) {
            configureMediationScheduleButton(scheduleButton, data);
        }

        document.getElementById('complaintInfo').innerHTML = `
            <p><strong>Category:</strong> ${escapeHtml(data.category_id)}</p>
            <p><strong>Incident Date:</strong> ${escapeHtml(data.incident_date || 'N/A')}</p>
            <p><strong>Incident Time:</strong> ${escapeHtml(data.incident_time || 'Not recorded')}</p>
            <p><strong>Specific Location:</strong> ${escapeHtml(data.incident_location || 'Not recorded')}</p>
            <p><strong>Landmark:</strong> ${escapeHtml(data.incident_landmark || 'Not recorded')}</p>
            <p><strong>Status:</strong> <span class="status status-${escapeHtml(String(data.status).toLowerCase())}">${escapeHtml(data.status)}</span></p>
            <p><strong>Narrative:</strong></p>
            <p style="white-space: pre-wrap;">${escapeHtml(data.narrative)}</p>
            <p><strong>Additional Details:</strong></p>
            <p style="white-space: pre-wrap;">${escapeHtml(data.additional_details || 'None provided.')}</p>
            <p><strong>Review Notes:</strong></p>
            <p style="white-space: pre-wrap;">${escapeHtml(data.review_notes || 'No review notes yet.')}</p>
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
    const mediationComplaintId = document.getElementById('mediationComplaintId');
    if (mediationComplaintId) mediationComplaintId.value = complaintId;
}

async function configureMediationScheduleButton(button, complaint) {
    button.disabled = true;
    delete button.dataset.caseId;
    if (!complaint.case_id) {
        const canSchedule = ['Filed', 'Under Review', 'Needs Information', 'Accepted'].includes(complaint.status);
        button.textContent = 'Schedule 1st Mediation';
        button.disabled = !canSchedule;
        button.title = canSchedule ? '' : '1st Mediation cannot be scheduled for this complaint.';
        return;
    }

    try {
        const response = await fetch('../../../backend/api/hearings/calendar.php');
        const result = await response.json();
        if (!response.ok || result.success === false) throw new Error(result.message || 'Unable to load hearing progression.');
        const hearings = (result.data || []).filter((hearing) => String(hearing.case_id) === String(complaint.case_id));
        const mediationCount = hearings.filter((hearing) => hearing.hearing_type === 'Mediation').length;
        const conciliationCount = hearings.filter((hearing) => hearing.hearing_type === 'Conciliation').length;
        let label = '';
        if (mediationCount < 3) label = `Schedule ${ordinalLabel(mediationCount + 1)} Mediation`;
        else if (conciliationCount < 3) label = `Schedule ${ordinalLabel(conciliationCount + 1)} Conciliation`;

        if (!label) {
            button.textContent = 'All schedules completed';
            button.title = 'This case already has three mediation and three conciliation schedules.';
            return;
        }

        button.textContent = label;
        button.title = `Open the hearing scheduler for ${label.replace('Schedule ', '')}.`;
        button.dataset.caseId = String(complaint.case_id);
        button.disabled = false;
    } catch (error) {
        button.textContent = 'Schedule next hearing';
        button.title = error.message;
    }
}

function ordinalLabel(number) {
    return number === 1 ? '1st' : (number === 2 ? '2nd' : (number === 3 ? '3rd' : `${number}th`));
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

function openAddAttachmentModal(type = 'image') {
    const image = type === 'image';
    const video = type === 'video';
    document.getElementById('attachmentModalTitle').textContent = image ? 'Upload Picture Evidence' : (video ? 'Upload Video Evidence' : 'Upload Document Evidence');
    document.getElementById('attachmentFileLabel').textContent = image ? 'Picture *' : (video ? 'Video *' : 'Document *');
    document.getElementById('attachmentFileHelp').textContent = image ? 'JPG or PNG up to 25 MB.' : (video ? 'MP4 or WebM up to 25 MB.' : 'PDF up to 25 MB.');
    document.getElementById('attachmentFile').accept = image ? 'image/jpeg,image/png' : (video ? 'video/mp4,video/webm' : 'application/pdf');
    document.getElementById('addAttachmentModal').style.display = 'flex';
}

function closeAddAttachmentModal() {
    document.getElementById('addAttachmentModal').style.display = 'none';
}

function openScheduleMediationModal() {
    const button = document.getElementById('scheduleMediationButton');
    if (button?.dataset.caseId) {
        window.location.href = `../hearings/schedules.php?case_id=${encodeURIComponent(button.dataset.caseId)}`;
        return;
    }
    const form = document.getElementById('scheduleMediationForm');
    if (!form) return;
    document.getElementById('mediationMessage').textContent = '';
    document.getElementById('scheduleMediationModal').style.display = 'flex';
}

function closeScheduleMediationModal() {
    document.getElementById('scheduleMediationModal').style.display = 'none';
}

function reviewMediationSchedule(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = document.getElementById('mediationMessage');
    message.textContent = '';
    if (!form.reportValidity()) return;
    const values = Object.fromEntries(new FormData(form));
    if (!values.mediation_date || !values.mediation_time) {
        message.textContent = '1st Mediation date and time are required.';
        return;
    }
    const selected = new Date(`${values.mediation_date}T${values.mediation_time}`);
    if (Number.isNaN(selected.getTime()) || selected <= new Date()) {
        message.textContent = 'Choose a future 1st Mediation date and time.';
        return;
    }
    const details = document.getElementById('mediationConfirmationDetails');
    details.replaceChildren();
    [['Date and time', selected.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })], ['Venue', values.venue], ['Remarks', values.remarks || 'None']].forEach(([label, value]) => {
        const term = document.createElement('dt'); term.textContent = label;
        const description = document.createElement('dd'); description.textContent = value;
        details.append(term, description);
    });
    document.getElementById('confirmMediationModal').style.display = 'flex';
}

function closeConfirmMediationModal() {
    document.getElementById('confirmMediationModal').style.display = 'none';
}

function submitMediationSchedule() {
    const form = document.getElementById('scheduleMediationForm');
    const message = document.getElementById('mediationMessage');
    const button = document.getElementById('confirmMediationButton');
    if (!form || !button) return;
    const data = new FormData(form);
    data.set('schedule_confirmed', '1');
    button.disabled = true;
    complaintApi('../../../backend/api/complaints/schedule-mediation.php', { method: 'POST', body: data })
        .then((result) => {
            closeConfirmMediationModal();
            closeScheduleMediationModal();
            window.agapNotify?.(result.message, 'success');
            loadComplaintDetails();
        })
        .catch((error) => { closeConfirmMediationModal(); message.textContent = error.message; window.agapNotify?.(error.message, 'error'); })
        .finally(() => { button.disabled = false; });
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
    refreshComplaintMap('addComplaintMap');
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
        populateEditComplaintForm(data);
    });
}

function populateEditComplaintForm(data = {}) {
    const editForm = document.querySelector('#editComplaintModal form');
    editForm.querySelector('[data-map-state]').value = 'unchanged';
    editForm.querySelector('[data-map-latitude]').value = '';
    editForm.querySelector('[data-map-longitude]').value = '';
    complaintMaps.editComplaintMap?.clear('unchanged');
    document.getElementById('editComplaintId').value = data.complaint_id || '';
    document.getElementById('editCategoryId').value = data.category_id || '';
    document.getElementById('editComplaintTitle').value = data.complaint_title || '';
    document.getElementById('editIncidentDate').value = data.incident_date || '';
    document.getElementById('editIncidentTime').value = data.incident_time || '';
    document.getElementById('editIncidentLocation').value = data.incident_location || '';
    document.getElementById('editIncidentLandmark').value = data.incident_landmark || '';
    document.getElementById('editNarrative').value = data.narrative || '';
    document.getElementById('editAdditionalDetails').value = data.additional_details || '';
    document.getElementById('editComplaintModal').style.display = 'flex';
    restoreComplaintForm('editComplaintModal', data);
    const map = complaintMaps.editComplaintMap;
    if (data.location && data.location.latitude !== null && data.location.longitude !== null) {
        map?.showExistingPoint(data.location.latitude, data.location.longitude);
    } else {
        syncComplaintMapFromForm('editComplaintMap');
    }
    refreshComplaintMap('editComplaintMap');
}

function restoreComplaintForm(modalId, values = {}) {
    const form = document.querySelector(`#${modalId} form`);
    if (!form || !values) return;

    Object.entries(values).forEach(([name, value]) => {
        const field = form.elements.namedItem(name);
        if (field && typeof field.value !== 'undefined') field.value = value ?? '';
    });
    syncComplaintMapFromForm(modalId === 'addComplaintModal' ? 'addComplaintMap' : 'editComplaintMap');
}

const complaintMaps = {};

function initialiseComplaintMaps() {
    if (!window.L) return;
    initialiseComplaintMap('addComplaintMap', 'none');
    initialiseComplaintMap('editComplaintMap', 'unchanged');
}

function initialiseComplaintMap(mapId, emptyState) {
    const element = document.getElementById(mapId);
    if (!element || complaintMaps[mapId]) return;

    const form = element.closest('form');
    const state = form.querySelector('[data-map-state]');
    const latitude = form.querySelector('[data-map-latitude]');
    const longitude = form.querySelector('[data-map-longitude]');
    const status = form.querySelector('[data-map-status]');
    const map = L.map(element).setView([14.6507, 121.1029], 13);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let marker = null;
    const setStatus = (message) => { status.textContent = message; };
    const setPoint = (lat, lng, mapState = 'selected') => {
        if (marker) marker.setLatLng([lat, lng]);
        else marker = L.marker([lat, lng]).addTo(map);
        latitude.value = Number(lat).toFixed(8);
        longitude.value = Number(lng).toFixed(8);
        state.value = mapState;
        setStatus(`Map point selected: ${latitude.value}, ${longitude.value}`);
        map.setView([lat, lng], Math.max(map.getZoom(), 16));
    };

    complaintMaps[mapId] = {
        map,
        showExistingPoint(lat, lng) {
            if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) return;
            if (marker) marker.setLatLng([Number(lat), Number(lng)]);
            else marker = L.marker([Number(lat), Number(lng)]).addTo(map);
            latitude.value = '';
            longitude.value = '';
            state.value = 'unchanged';
            setStatus(`Saved map point: ${Number(lat).toFixed(8)}, ${Number(lng).toFixed(8)}`);
            map.setView([Number(lat), Number(lng)], 16);
        },
        setPoint,
        clear(nextState = emptyState) {
            if (marker) { map.removeLayer(marker); marker = null; }
            latitude.value = '';
            longitude.value = '';
            state.value = nextState;
            setStatus(nextState === 'clear' ? 'Saved map point will be removed.' : 'No map point selected.');
        },
        refresh() { setTimeout(() => map.invalidateSize(), 0); }
    };

    map.on('click', (event) => setPoint(event.latlng.lat, event.latlng.lng));
    form.querySelector('[data-clear-map]').addEventListener('click', () => {
        complaintMaps[mapId].clear(mapId === 'editComplaintMap' ? 'clear' : 'none');
    });
}

function syncComplaintMapFromForm(mapId) {
    const controller = complaintMaps[mapId];
    if (!controller) return;
    const form = controller.map.getContainer().closest('form');
    const state = form.querySelector('[data-map-state]').value;
    const latitude = form.querySelector('[data-map-latitude]').value;
    const longitude = form.querySelector('[data-map-longitude]').value;
    if (state === 'selected' && latitude !== '' && longitude !== '') {
        controller.setPoint(Number(latitude), Number(longitude), 'selected');
    } else if (state === 'clear' || state === 'none') {
        controller.clear(state);
    }
}

function refreshComplaintMap(mapId) {
    complaintMaps[mapId]?.refresh();
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
