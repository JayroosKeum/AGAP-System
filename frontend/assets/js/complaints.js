const complaintApi = async (url, options = {}) => {
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ success: false, message: 'Invalid server response.' }));
    if (!response.ok) throw new Error(data.message || 'Request failed.');
    return data;
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
})[character]);

const setText = (id, text) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = (text !== null && text !== undefined && String(text).trim() !== '') ? text : '—';
};

const formatTime12 = (timeStr) => {
    if (!timeStr) return 'Not recorded';
    const parts = String(timeStr).split(':');
    if (parts.length < 2) return timeStr;
    let h = parseInt(parts[0], 10);
    const m = parts[1];
    if (isNaN(h)) return timeStr;
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return `${h}:${m} ${ampm}`;
};

const formatDateReadable = (dateStr) => {
    if (!dateStr) return '—';
    try {
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (_) {
        return dateStr;
    }
};

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
    initialiseNarrativeEnhancement();

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

function initialiseNarrativeEnhancement() {
    const button = document.querySelector('[data-enhance-narrative]');
    const narrative = document.getElementById('narrative');
    const preview = document.querySelector('[data-narrative-preview]');
    const suggestion = document.querySelector('[data-narrative-suggestion]');
    if (!button || !narrative || !preview || !suggestion) return;

    const applyButton = preview.querySelector('[data-apply-narrative]');
    const discardButton = preview.querySelector('[data-discard-narrative]');
    let proposedNarrative = '';
    const setBusy = (busy) => {
        button.disabled = busy;
        button.textContent = busy ? 'Enhancing…' : '✨ Enhance with AI';
    };

    button.addEventListener('click', async () => {
        const original = narrative.value.trim();
        if (original.length < 10) {
            window.agapNotify?.('Enter at least 10 characters before using AI enhancement.', 'error');
            narrative.focus();
            return;
        }

        setBusy(true);
        preview.hidden = true;
        try {
            const response = await fetch('../../../backend/api/ai/refine-narrative.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ narrative: original })
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.success || !result.narrative) {
                throw new Error(result.message || 'Unable to enhance the narrative.');
            }
            proposedNarrative = result.narrative;
            suggestion.textContent = proposedNarrative;
            preview.hidden = false;
            preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (error) {
            window.agapNotify?.(error.message || 'Unable to enhance the narrative.', 'error');
        } finally {
            setBusy(false);
        }
    });

    applyButton?.addEventListener('click', () => {
        if (!proposedNarrative) return;
        narrative.value = proposedNarrative;
        narrative.dispatchEvent(new Event('input', { bubbles: true }));
        preview.hidden = true;
        window.agapNotify?.('AI suggestion applied. Please review it before saving.', 'success');
    });

    discardButton?.addEventListener('click', () => {
        proposedNarrative = '';
        preview.hidden = true;
        narrative.focus();
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
        // Header Title & Number
        const compNum = data.complaint_number || ('CMP-' + String(data.complaint_id).padStart(5, '0'));
        const compNumEl = document.getElementById('complaintNumber');
        if (compNumEl) compNumEl.textContent = 'Complaint #' + compNum;

        const compTitleEl = document.getElementById('complaintTitle');
        if (compTitleEl) compTitleEl.textContent = data.complaint_title || 'Untitled Complaint';

        // Status Badge
        const statusEl = document.getElementById('complaintStatusBadge');
        if (statusEl) {
            const st = String(data.status || 'Filed');
            const stSlug = st.toLowerCase().replace(/[^a-z0-9]/g, '-');
            statusEl.className = 'status-pill status-' + stSlug;
            statusEl.textContent = st;
        }

        // Type & Category Badges
        const typeEl = document.getElementById('complaintCaseTypeBadge');
        if (typeEl) typeEl.textContent = data.case_type || 'Civil';

        const catEl = document.getElementById('complaintCategoryBadge');
        if (catEl) catEl.textContent = data.category_name || (data.category_id ? 'Category #' + data.category_id : 'General');

        // Linked Case Actions & Badges
        const caseNumber = data.case_number || (data.case_id ? 'KP-' + String(data.case_id).padStart(5, '0') : null);
        const caseLinkEl = document.getElementById('complaintCaseLink');
        const viewCaseBtn = document.getElementById('viewCaseBtn');
        const caseBadgeText = document.getElementById('caseNumberBadgeText');

        if (data.case_id) {
            const caseUrl = '../cases/case-details.php?id=' + encodeURIComponent(data.case_id);
            if (caseLinkEl) {
                caseLinkEl.href = caseUrl;
                caseLinkEl.style.display = 'inline-flex';
                if (caseBadgeText) caseBadgeText.textContent = caseNumber || data.case_id;
            }
            if (viewCaseBtn) {
                viewCaseBtn.href = caseUrl;
                viewCaseBtn.style.display = 'inline-flex';
            }
        } else {
            if (caseLinkEl) caseLinkEl.style.display = 'none';
            if (viewCaseBtn) viewCaseBtn.style.display = 'none';
        }

        // Edit link
        const editBtn = document.getElementById('editComplaintBtn');
        if (editBtn) editBtn.href = 'complaint-edit.php?id=' + encodeURIComponent(complaintId);

        // Schedule Mediation Button configuration
        const scheduleButton = document.getElementById('scheduleMediationButton');
        if (scheduleButton) {
            configureMediationScheduleButton(scheduleButton, data);
        }

        // Card 1: Incident & Classification Info Grid
        setText('infoComplaintTitle', data.complaint_title);
        setText('infoCategory', data.category_name || (data.category_id ? 'Category #' + data.category_id : 'General'));
        setText('infoCaseType', data.case_type || 'Civil');
        setText('infoIncidentDate', data.incident_date ? formatDateReadable(data.incident_date) : 'N/A');
        setText('infoIncidentTime', data.incident_time ? formatTime12(data.incident_time) : 'Not recorded');
        setText('infoDateFiled', data.created_at ? formatDateReadable(data.created_at) : '—');

        const infoStatusEl = document.getElementById('infoStatus');
        if (infoStatusEl) {
            const st = String(data.status || 'Filed');
            const stSlug = st.toLowerCase().replace(/[^a-z0-9]/g, '-');
            infoStatusEl.innerHTML = `<span class="status-pill status-${stSlug}">${escapeHtml(st)}</span>`;
        }

        const infoDocketEl = document.getElementById('infoDocketCase');
        if (infoDocketEl) {
            if (data.case_id) {
                infoDocketEl.innerHTML = `<a href="../cases/case-details.php?id=${encodeURIComponent(data.case_id)}" class="meta-pill case-pill" style="display:inline-flex;">📁 ${escapeHtml(caseNumber || ('Case #' + data.case_id))} &rarr;</a>`;
            } else {
                infoDocketEl.textContent = 'Not yet docketed';
            }
        }

        // Card 3: Narrative & Additional Details
        const narrEl = document.getElementById('complaintNarrative');
        if (narrEl) narrEl.textContent = data.narrative || 'No statement narrative provided.';

        const addWrap = document.getElementById('complaintAdditionalDetailsWrap');
        const addEl = document.getElementById('complaintAdditionalDetails');
        if (addWrap && addEl) {
            if (data.additional_details && data.additional_details.trim()) {
                addWrap.style.display = 'block';
                addEl.textContent = data.additional_details;
            } else {
                addWrap.style.display = 'none';
            }
        }

        // Card 4: Incident Location & Leaflet Map
        setText('locationAddressText', data.incident_location || (data.location && data.location.address) || 'Not specified');
        setText('locationLandmarkText', data.incident_landmark || 'None');

        const mapContainer = document.getElementById('complaintDetailMap');
        const emptyMapPlaceholder = document.getElementById('emptyMapPlaceholder');
        const coordText = document.getElementById('locationCoordinatesText');

        const lat = data.location && data.location.latitude ? parseFloat(data.location.latitude) : null;
        const lng = data.location && data.location.longitude ? parseFloat(data.location.longitude) : null;

        if (lat && lng && !isNaN(lat) && !isNaN(lng)) {
            if (coordText) coordText.textContent = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            if (mapContainer && window.L) {
                mapContainer.style.display = 'block';
                if (emptyMapPlaceholder) emptyMapPlaceholder.style.display = 'none';
                if (window.complaintDetailMapInstance) {
                    window.complaintDetailMapInstance.remove();
                }
                const map = L.map('complaintDetailMap', { scrollWheelZoom: false }).setView([lat, lng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);
                const marker = L.marker([lat, lng]).addTo(map);
                marker.bindPopup(`<strong>${escapeHtml(compNum)}</strong><br>${escapeHtml(data.incident_location || 'Incident Location')}`).openPopup();
                window.complaintDetailMapInstance = map;
                setTimeout(() => map.invalidateSize(), 200);
            }
        } else {
            if (coordText) coordText.textContent = 'No GPS coordinates pinned';
            if (mapContainer) mapContainer.style.display = 'none';
            if (emptyMapPlaceholder) emptyMapPlaceholder.style.display = 'flex';
        }

        // Card 6: Administrative Review Notes
        const revEl = document.getElementById('complaintReviewNotes');
        if (revEl) {
            revEl.textContent = (data.review_notes && data.review_notes.trim()) ? data.review_notes : 'No administrative review notes recorded yet.';
        }

        // Legacy compatibility container
        const legacyInfo = document.getElementById('complaintInfo');
        if (legacyInfo) {
            legacyInfo.innerHTML = `
                <p><strong>Category:</strong> ${escapeHtml(data.category_name || data.category_id)}</p>
                <p><strong>Incident Date:</strong> ${escapeHtml(data.incident_date || 'N/A')}</p>
                <p><strong>Incident Time:</strong> ${escapeHtml(data.incident_time || 'Not recorded')}</p>
                <p><strong>Status:</strong> ${escapeHtml(data.status)}</p>
            `;
        }

        // Render Parties and Attachments
        const parties = data.parties || [];
        const attachments = data.attachments || [];
        renderParties(parties);
        renderAttachments(attachments);
    });

    // Load residents only if party select exists
    if (document.getElementById('partyResidentId')) {
        loadResidents();
    }

    // Set complaint ID in hidden/modal elements if present
    const partyComplaintInput = document.getElementById('partyComplaintId');
    if (partyComplaintInput) partyComplaintInput.value = complaintId;
    const attachmentComplaintInput = document.getElementById('attachmentComplaintId');
    if (attachmentComplaintInput) attachmentComplaintInput.value = complaintId;
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
    const container = document.getElementById('partiesListContainer');
    const tbody = document.getElementById('partiesTable');
    const countEl = document.getElementById('partiesCount');
    if (countEl) countEl.textContent = parties.length;

    if (tbody) {
        if (parties.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; padding: 16px;">No parties added yet</td></tr>';
        } else {
            let rows = '';
            parties.forEach(party => {
                rows += `
                    <tr>
                        <td>${escapeHtml(party.resident_name)}</td>
                        <td>${escapeHtml(party.party_type)}</td>
                        <td></td>
                    </tr>
                `;
            });
            tbody.innerHTML = rows;
        }
    }

    if (!container) return;

    if (parties.length === 0) {
        container.innerHTML = '<div class="empty-detail-state"><p style="margin:0;">No parties added to this complaint yet.</p></div>';
        return;
    }

    let cards = '';
    parties.forEach(party => {
        const typeClass = 'badge-' + escapeHtml(String(party.party_type).toLowerCase());
        cards += `
            <div class="party-detail-card">
                <div class="party-detail-left">
                    <span class="party-badge ${typeClass}">${escapeHtml(party.party_type)}</span>
                    <div class="party-detail-info">
                        <div class="party-detail-name">${escapeHtml(party.resident_name)}</div>
                        <div class="party-detail-sub">
                            ${party.contact_no ? `<span>📞 ${escapeHtml(party.contact_no)}</span>` : ''}
                            ${party.purok ? `<span>📍 Purok ${escapeHtml(party.purok)}</span>` : ''}
                            ${party.address ? `<span>🏠 ${escapeHtml(party.address)}</span>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    container.innerHTML = cards;
}

function renderAttachments(attachments) {
    const container = document.getElementById('attachmentsListContainer');
    const tbody = document.getElementById('attachmentsTable');
    const countEl = document.getElementById('attachmentsCount');
    if (countEl) countEl.textContent = attachments.length;

    if (tbody) {
        if (attachments.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 16px;">No attachments added yet</td></tr>';
        } else {
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
    }

    if (!container) return;

    if (attachments.length === 0) {
        container.innerHTML = '<div class="empty-detail-state"><p style="margin:0;">No evidence or attachments uploaded yet.</p></div>';
        return;
    }

    let cards = '';
    attachments.forEach(att => {
        const downloadUrl = `../../../backend/api/complaints/download-attachment.php?id=${Number(att.attachment_id)}`;
        const ext = String(att.file_name).split('.').pop().toLowerCase();
        const isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext) || String(att.file_type).startsWith('image/');
        const isVid = ['mp4', 'webm'].includes(ext) || String(att.file_type).startsWith('video/');
        const isPdf = ext === 'pdf' || att.file_type === 'application/pdf';
        const isDoc = ['doc', 'docx'].includes(ext) || String(att.file_type).includes('word');

        let iconClass = 'icon-file';
        let iconText = 'FILE';
        if (isVid) { iconClass = 'icon-video'; iconText = 'VID'; }
        else if (isPdf) { iconClass = 'icon-pdf'; iconText = 'PDF'; }
        else if (isDoc) { iconClass = 'icon-doc'; iconText = 'DOC'; }

        const uploadDate = att.uploaded_at ? new Date(att.uploaded_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

        cards += `
            <div class="attachment-detail-card">
                <div class="attachment-detail-left">
                    ${isImg
                        ? `<img src="${downloadUrl}" class="attachment-thumb-img" alt="${escapeHtml(att.file_name)}">`
                        : `<div class="attachment-type-icon ${iconClass}">${iconText}</div>`
                    }
                    <div style="min-width: 0; flex: 1;">
                        <a href="${downloadUrl}" class="attachment-detail-name" title="Download ${escapeHtml(att.file_name)}">${escapeHtml(att.file_name)}</a>
                        <div class="attachment-detail-meta">
                            <span>${escapeHtml(att.file_type || ext.toUpperCase())}</span> &bull; <span>${uploadDate}</span>
                        </div>
                    </div>
                </div>
                <div class="attachment-detail-actions">
                    <a href="${downloadUrl}" class="btn-icon-action" title="Download file" download>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    </a>
                    <button type="button" class="btn-icon-action delete" title="Delete attachment" onclick="deleteAttachment(${att.attachment_id})">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>
                </div>
            </div>
        `;
    });
    container.innerHTML = cards;
}

function openAddPartyModal() {
    const modal = document.getElementById('addPartyModal');
    if (modal) modal.style.display = 'flex';
}

function closeAddPartyModal() {
    const modal = document.getElementById('addPartyModal');
    if (modal) modal.style.display = 'none';
}

function openAddAttachmentModal(type = 'all') {
    const modal = document.getElementById('addAttachmentModal');
    if (!modal) return;
    const fileInput = document.getElementById('attachmentFile');
    if (!fileInput) return;
    const isImage = type === 'image';
    const isVideo = type === 'video';
    const isDoc = type === 'document';

    const titleEl = document.getElementById('attachmentModalTitle');
    const labelEl = document.getElementById('attachmentFileLabel');
    const helpEl = document.getElementById('attachmentFileHelp');

    if (isImage) {
        if (titleEl) titleEl.textContent = 'Upload Picture Evidence';
        if (labelEl) labelEl.textContent = 'Picture *';
        if (helpEl) helpEl.textContent = 'JPG, PNG, GIF, or WebP up to 25 MB.';
        fileInput.accept = 'image/*';
    } else if (isVideo) {
        if (titleEl) titleEl.textContent = 'Upload Video Evidence';
        if (labelEl) labelEl.textContent = 'Video *';
        if (helpEl) helpEl.textContent = 'MP4 or WebM up to 25 MB.';
        fileInput.accept = 'video/mp4,video/webm';
    } else if (isDoc) {
        if (titleEl) titleEl.textContent = 'Upload Document Evidence';
        if (labelEl) labelEl.textContent = 'Document *';
        if (helpEl) helpEl.textContent = 'PDF, DOC, or DOCX up to 25 MB.';
        fileInput.accept = '.pdf,.doc,.docx';
    } else {
        if (titleEl) titleEl.textContent = 'Upload Evidence / Attachment';
        if (labelEl) labelEl.textContent = 'Evidence File *';
        if (helpEl) helpEl.textContent = 'Images, videos, PDF, and DOC/DOCX up to 25 MB.';
        fileInput.accept = 'image/*,video/*,.pdf,.doc,.docx';
    }
    modal.style.display = 'flex';
}

function closeAddAttachmentModal() {
    const modal = document.getElementById('addAttachmentModal');
    if (modal) modal.style.display = 'none';
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
    window.location.href = 'complaint-create.php';
}

function closeAddComplaintModal() {
    const modal = document.getElementById('addComplaintModal');
    if (modal) modal.style.display = 'none';
}

function viewComplaint(id) {
    window.location.href = 'complaint-details.php?id=' + id;
}

function closeViewComplaintModal() {
    document.getElementById('viewComplaintModal').style.display = 'none';
}

function editComplaint(id) {
    window.location.href = 'complaint-edit.php?id=' + id;
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

// Barangay Tumana boundary, OpenStreetMap relation 1225795 (retrieved 2026-09-25).
// Kept in the client so the restriction remains available if geocoding is offline.
const tumanaBoundary = [[14.6554682,121.0857081],[14.6562612,121.0859908],[14.6557911,121.0865123],[14.6566853,121.0867891],[14.6573361,121.0874608],[14.6566672,121.0882081],[14.6596216,121.0912009],[14.6605249,121.0911456],[14.6609324,121.0914765],[14.6617729,121.0920319],[14.6634173,121.0935248],[14.6639892,121.0936321],[14.6643486,121.0936995],[14.6645004,121.0938826],[14.6649347,121.0948585],[14.6652695,121.0952371],[14.6652424,121.0956829],[14.6648805,121.0961861],[14.664908,121.0963356],[14.6645002,121.0966408],[14.6642299,121.0967374],[14.6637829,121.0977901],[14.6629907,121.0988145],[14.6625832,121.0991486],[14.6625136,121.0996819],[14.6625395,121.100022],[14.6623861,121.1006225],[14.6621551,121.1005299],[14.6614757,121.1023379],[14.6602368,121.1019108],[14.6595948,121.1016599],[14.6589154,121.1019614],[14.6581343,121.1022551],[14.6574788,121.102553],[14.6569962,121.1027094],[14.6566377,121.102703],[14.656158,121.1026733],[14.6559169,121.1026982],[14.6553478,121.1027577],[14.6550262,121.1027938],[14.6548318,121.1027249],[14.6541841,121.1025018],[14.6534713,121.1024013],[14.6532707,121.102373],[14.6510737,121.101336],[14.6508768,121.1007973],[14.6507211,121.0992879],[14.650753,121.0989571],[14.6509538,121.098811],[14.6514718,121.0983827],[14.6521496,121.0978851],[14.6527322,121.0972236],[14.6535364,121.0959443],[14.6539003,121.0955034],[14.654028,121.0953166],[14.6537619,121.0950108],[14.6533329,121.0942299],[14.6530062,121.0934184]];

function isWithinTumana(lat, lng) {
    let inside = false;
    for (let i = 0, j = tumanaBoundary.length - 1; i < tumanaBoundary.length; j = i++) {
        const [yi, xi] = tumanaBoundary[i];
        const [yj, xj] = tumanaBoundary[j];
        if ((yi > lat) !== (yj > lat) && lng < ((xj - xi) * (lat - yi) / (yj - yi) + xi)) inside = !inside;
    }
    return inside;
}

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
    const street = form.querySelector('[name="incident_street"]');
    const purok = form.querySelector('[name="incident_purok"]');
    const city = form.querySelector('[name="incident_city"]');
    const barangay = form.querySelector('[name="incident_barangay"]');
    const tumanaBounds = L.latLngBounds(tumanaBoundary);
    const map = L.map(element, { maxBounds: tumanaBounds, maxBoundsViscosity: 1 }).fitBounds(tumanaBounds, { padding: [8, 8] });
    map.setMinZoom(map.getZoom());
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    L.polygon(tumanaBoundary, { color: '#2563eb', weight: 2, fillColor: '#60a5fa', fillOpacity: 0.10, interactive: false }).addTo(map);

    let marker = null;
    let geocodeTimer = null;
    let lookupVersion = 0;
    const setStatus = (message) => { status.textContent = message; };
    const updateAddressFromPoint = async (lat, lng) => {
        const requestVersion = ++lookupVersion;
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=1`, { headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (requestVersion !== lookupVersion || !result.address) return;
            const address = result.address;
            if (street) street.value = [address.house_number, address.road || address.pedestrian || address.neighbourhood].filter(Boolean).join(' ') || street.value;
            if (purok && address.quarter) purok.value = address.quarter;
            if (city) city.value = 'Marikina City';
            if (barangay) barangay.value = 'Tumana';
            setStatus(`Map point selected: ${latitude.value}, ${longitude.value}. Address updated.`);
        } catch (_) {
            setStatus(`Map point selected: ${latitude.value}, ${longitude.value}. Address lookup is unavailable.`);
        }
    };
    const setPoint = (lat, lng, mapState = 'selected', syncAddress = false) => {
        if (!isWithinTumana(lat, lng)) {
            setStatus('Choose a point within Barangay Tumana.');
            return false;
        }
        if (marker) marker.setLatLng([lat, lng]);
        else {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', (event) => {
                const point = event.target.getLatLng();
                if (!setPoint(point.lat, point.lng, 'selected', true) && marker) marker.setLatLng([Number(latitude.value), Number(longitude.value)]);
            });
        }
        latitude.value = Number(lat).toFixed(8);
        longitude.value = Number(lng).toFixed(8);
        state.value = mapState;
        setStatus(`Map point selected: ${latitude.value}, ${longitude.value}`);
        map.setView([lat, lng], Math.max(map.getZoom(), 16));
        if (syncAddress) updateAddressFromPoint(lat, lng);
        return true;
    };

    const geocodeAddress = async () => {
        const streetValue = street?.value.trim() || '';
        const purokValue = purok?.value.trim() || '';
        if (streetValue.length < 3) return;
        const query = [streetValue, purokValue, 'Barangay Tumana', 'Marikina City', 'Metro Manila', 'Philippines'].filter(Boolean).join(', ');
        const box = `${tumanaBounds.getWest()},${tumanaBounds.getNorth()},${tumanaBounds.getEast()},${tumanaBounds.getSouth()}`;
        const requestVersion = ++lookupVersion;
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&bounded=1&viewbox=${encodeURIComponent(box)}&q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
            const results = await response.json();
            if (requestVersion !== lookupVersion || !results[0]) return;
            const lat = Number(results[0].lat), lng = Number(results[0].lon);
            if (isWithinTumana(lat, lng)) setPoint(lat, lng, 'selected');
            else setStatus('The typed address is outside Barangay Tumana.');
        } catch (_) { setStatus('Address lookup is unavailable. You can still place the pin on the map.'); }
    };
    [street, purok].filter(Boolean).forEach((field) => field.addEventListener('input', () => {
        clearTimeout(geocodeTimer);
        geocodeTimer = setTimeout(geocodeAddress, 700);
    }));

    complaintMaps[mapId] = {
        map,
        showExistingPoint(lat, lng) {
            if (!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) return;
            if (marker) marker.setLatLng([Number(lat), Number(lng)]);
            else marker = L.marker([Number(lat), Number(lng)], { draggable: true }).addTo(map);
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

    const enableExistingMarkerDrag = () => {
        if (!marker || marker.__agapDragBound) return;
        marker.__agapDragBound = true;
        marker.on('dragend', (event) => {
            const point = event.target.getLatLng();
            if (!setPoint(point.lat, point.lng, 'selected', true)) marker.setLatLng([Number(latitude.value), Number(longitude.value)]);
        });
    };
    enableExistingMarkerDrag();
    const savedLat = Number(latitude.value);
    const savedLng = Number(longitude.value);
    if (state.value === 'unchanged' && Number.isFinite(savedLat) && Number.isFinite(savedLng) && isWithinTumana(savedLat, savedLng)) {
        marker = L.marker([savedLat, savedLng], { draggable: true }).addTo(map);
        enableExistingMarkerDrag();
        setStatus(`Saved map point: ${savedLat.toFixed(8)}, ${savedLng.toFixed(8)}`);
        map.setView([savedLat, savedLng], 16);
    }

    map.on('click', (event) => setPoint(event.latlng.lat, event.latlng.lng, 'selected', true));
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
