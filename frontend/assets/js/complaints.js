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

window.addEventListener('pageshow', (event) => {
    if (event.persisted && document.getElementById('complaintNumber')) {
        loadComplaintDetails();
    }
});

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

    const issueSummonForm = document.getElementById('issueSummonForm');
    if (issueSummonForm) {
        const dateInput = document.getElementById('summonMediationDate');
        const timeInput = document.getElementById('summonMediationTime');
        const venueInput = document.getElementById('summonMediationVenue');
        if (dateInput) {
            const today = new Date();
            dateInput.min = today.toISOString().slice(0, 10);
            if (!dateInput.value) {
                const defaultDate = new Date(today);
                defaultDate.setDate(defaultDate.getDate() + 3);
                dateInput.value = defaultDate.toISOString().slice(0, 10);
            }
            dateInput.addEventListener('change', checkMediationScheduleConflict);
        }
        if (timeInput) {
            timeInput.addEventListener('input', debouncedCheckMediationScheduleConflict);
            timeInput.addEventListener('change', checkMediationScheduleConflict);
        }
        if (venueInput) {
            venueInput.addEventListener('input', debouncedCheckMediationScheduleConflict);
            venueInput.addEventListener('change', checkMediationScheduleConflict);
        }
    }

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

async function loadComplaintDetails() {
    // Get complaint ID from URL
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id');
    if (!complaintId) return;

    try {
        const response = await fetch('../../../backend/api/complaints/view.php?id=' + encodeURIComponent(complaintId) + '&_t=' + Date.now(), { cache: 'no-store' });
        const data = await response.json();
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
            let st = String(data.status || 'Filed');
            if (st === 'Docketed') {
                st = (data.case_status && data.case_status !== 'Docketed') ? data.case_status : 'Active';
            }
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

        // Load Case Status / Progress Tracker & Action Buttons first
        await loadCaseProgress(complaintId);


        // Card 1: Incident & Classification Info Grid
        setText('infoComplaintTitle', data.complaint_title);
        setText('infoCategory', data.category_name || (data.category_id ? 'Category #' + data.category_id : 'General'));
        setText('infoCaseType', data.case_type || 'Civil');
        setText('infoIncidentDate', data.incident_date ? formatDateReadable(data.incident_date) : 'N/A');
        setText('infoIncidentTime', data.incident_time ? formatTime12(data.incident_time) : 'Not recorded');
        setText('infoDateFiled', data.created_at ? formatDateReadable(data.created_at) : '—');
        // Store complaint date for summon modal deadline enforcement (15 days from filing)
        window.complaintCreatedAt = data.created_at || null;

        const infoStatusEl = document.getElementById('infoStatus');
        if (infoStatusEl) {
            let st = String(data.status || 'Filed');
            if (st === 'Docketed') {
                st = (data.case_status && data.case_status !== 'Docketed') ? data.case_status : 'Active';
            }
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
    } catch (err) {
        console.error('Error loading complaint details:', err);
    }

    // Load residents only if party select exists
    if (document.getElementById('partyResidentId')) {
        loadResidents();
    }

    // Set complaint ID in hidden/modal elements if present
    const partyComplaintInput = document.getElementById('partyComplaintId');
    if (partyComplaintInput) partyComplaintInput.value = complaintId;
    const attachmentComplaintInput = document.getElementById('attachmentComplaintId');
    if (attachmentComplaintInput) attachmentComplaintInput.value = complaintId;
    const summonComplaintId = document.getElementById('summonComplaintId');
    if (summonComplaintId) summonComplaintId.value = complaintId;
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
        let options = '<option value="">Select Resident Profile...</option>';
        data.forEach(resident => {
            const name = [resident.first_name, resident.middle_name, resident.last_name].filter(Boolean).join(' ');
            const addrParts = [];
            if (resident.address) addrParts.push(resident.address.trim());
            if (resident.purok && (!resident.address || !resident.address.toLowerCase().includes(resident.purok.toLowerCase()))) {
                addrParts.push(resident.purok.trim());
            }
            const addrText = addrParts.length > 0 ? addrParts.join(', ') : 'No address on file';
            options += `<option value="${resident.resident_id}">${escapeHtml(name + ' — ' + addrText)}</option>`;
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

let conflictCheckDebounceTimer = null;

async function checkMediationScheduleConflict() {
    const dateInput = document.getElementById('summonMediationDate');
    const timeInput = document.getElementById('summonMediationTime');
    const venueInput = document.getElementById('summonMediationVenue');
    const complaintInput = document.getElementById('summonComplaintId');
    const conflictAlert = document.getElementById('summonScheduleConflictAlert');
    const submitBtn = document.getElementById('submitIssueSummonBtn');
    const schedulesWrap = document.getElementById('summonDaySchedulesWrap');
    const slotsCount = document.getElementById('summonDaySlotsCount');
    const slotsList = document.getElementById('summonDaySlotsList');

    if (!dateInput || !dateInput.value) return;

    const dateVal = dateInput.value;
    const timeVal = timeInput ? timeInput.value : '';
    const venueVal = venueInput ? venueInput.value : 'Barangay Hall';
    const complaintIdVal = complaintInput ? complaintInput.value : '';

    const params = new URLSearchParams();
    params.set('date', dateVal);
    if (timeVal) params.set('time', timeVal);
    if (venueVal) params.set('venue', venueVal);
    if (complaintIdVal) params.set('complaint_id', complaintIdVal);

    try {
        const response = await fetch(`../../../backend/api/summons/check-schedule.php?${params.toString()}`);
        const result = await response.json();

        if (!response.ok || !result.success) {
            return;
        }

        // 1. Render day's scheduled mediations
        if (schedulesWrap && slotsList && slotsCount) {
            schedulesWrap.style.display = 'block';
            const scheduled = result.scheduled_mediations || [];
            slotsCount.textContent = scheduled.length;
            if (scheduled.length === 0) {
                slotsList.innerHTML = '<span style="color: #64748b;">No other mediations scheduled on this date. All hours available.</span>';
            } else {
                slotsList.innerHTML = scheduled.map(s => `
                    <div style="margin-top: 4px; padding: 4px 8px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <div>
                            <strong style="color: #1e293b;">${escapeHtml(s.start_time)} – ${escapeHtml(s.end_time)}</strong>
                            <span style="color: #64748b; font-size: 0.8rem; margin-left: 6px;">Case ${escapeHtml(s.case_number || 'N/A')}</span>
                        </div>
                        <span style="font-size: 0.72rem; color: #475569; background: #f1f5f9; padding: 1px 6px; border-radius: 4px;">${escapeHtml(s.venue || 'Barangay Hall')}</span>
                    </div>
                `).join('');
            }
        }

        // 2. Check for conflict
        if (result.has_conflict && result.conflict) {
            const conflictMsg = result.conflict.message || 'The selected time overlaps with an existing scheduled mediation.';
            if (conflictAlert) {
                conflictAlert.textContent = `⚠️ Schedule Conflict: ${conflictMsg}`;
                conflictAlert.style.display = 'block';
            }
            if (timeInput) {
                timeInput.setCustomValidity('Selected time overlaps with another scheduled mediation on this day.');
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = 'Cannot submit: Schedule conflict with an existing mediation on this day.';
            }
        } else {
            if (conflictAlert) {
                conflictAlert.textContent = '';
                conflictAlert.style.display = 'none';
            }
            if (timeInput) {
                timeInput.setCustomValidity('');
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.title = '';
            }
        }
    } catch (err) {
        console.warn('Failed to check mediation schedule:', err);
    }
}

function debouncedCheckMediationScheduleConflict() {
    clearTimeout(conflictCheckDebounceTimer);
    conflictCheckDebounceTimer = setTimeout(checkMediationScheduleConflict, 200);
}

function openIssueSummonModal() {
    const modal = document.getElementById('issueSummonModal');
    if (!modal) return;
    const msg = document.getElementById('issueSummonMessage');
    const conflictAlert = document.getElementById('summonScheduleConflictAlert');
    if (msg) msg.textContent = '';
    if (conflictAlert) {
        conflictAlert.textContent = '';
        conflictAlert.style.display = 'none';
    }

    const dateInput = document.getElementById('summonMediationDate');
    if (dateInput) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const todayStr = today.toISOString().slice(0, 10);

        // Compute deadline: complaint filing date + 15 days
        let deadlineStr = null;
        let deadlineLabel = '';
        if (window.complaintCreatedAt) {
            const filingDate = new Date(window.complaintCreatedAt);
            filingDate.setHours(0, 0, 0, 0);
            const deadline = new Date(filingDate);
            deadline.setDate(deadline.getDate() + 15);
            deadlineStr = deadline.toISOString().slice(0, 10);
            deadlineLabel = deadline.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
        }

        dateInput.min = todayStr;
        if (deadlineStr) dateInput.max = deadlineStr;

        // Set default only if not already set
        if (!dateInput.value) {
            const defaultDate = new Date(today);
            defaultDate.setDate(defaultDate.getDate() + 3);
            const defaultStr = defaultDate.toISOString().slice(0, 10);
            // Clamp default to deadline if it exceeds it
            dateInput.value = (deadlineStr && defaultStr > deadlineStr) ? deadlineStr : defaultStr;
        } else if (deadlineStr && dateInput.value > deadlineStr) {
            // Correct any out-of-range existing value
            dateInput.value = deadlineStr;
        }

        // Show/update the deadline hint label
        const hintId = 'summonDateDeadlineHint';
        let hintEl = document.getElementById(hintId);
        if (!hintEl) {
            hintEl = document.createElement('small');
            hintEl.id = hintId;
            hintEl.style.cssText = 'display:block; margin-top:4px; color:#b45309; font-size:0.82rem;';
            dateInput.parentNode.appendChild(hintEl);
        }
        if (deadlineLabel) {
            hintEl.textContent = `⚠️ Mediation must be scheduled within 15 days of filing — deadline: ${deadlineLabel}.`;
        } else {
            hintEl.textContent = '';
        }
    }

    modal.style.display = 'flex';
    checkMediationScheduleConflict();
}

function closeIssueSummonModal() {
    const modal = document.getElementById('issueSummonModal');
    if (modal) modal.style.display = 'none';
}

function handleIssueSummonClick() {
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id');
    if (!complaintId) return;

    const progress = window.caseProgressData;
    const btnInfo  = progress?.actions?.summon_button;

    // If gated: service attempt not yet recorded for previous summons — block and notify only
    const issueBtn = document.getElementById('issueSummonButton');
    if (issueBtn?.getAttribute('data-summon-gated') === 'true') {
        window.agapNotify?.(
            btnInfo?.tooltip || 'Please record the service attempt for the previous summons before issuing a new one.',
            'warning'
        );
        return;
    }

    // If button action is to view existing proof of service
    if (btnInfo?.action === 'view_proof' && btnInfo.url) {
        window.location.href = btnInfo.url;
        return;
    }

    openIssueSummonModal();
}

async function submitIssueSummon(event) {
    if (event) event.preventDefault();
    const form = document.getElementById('issueSummonForm');
    const submitBtn = document.getElementById('submitIssueSummonBtn');
    const msg = document.getElementById('issueSummonMessage');
    const conflictAlert = document.getElementById('summonScheduleConflictAlert');
    if (msg) msg.textContent = '';

    if (conflictAlert && conflictAlert.style.display !== 'none') {
        window.agapNotify?.('Please resolve the mediation schedule conflict before proceeding.', 'error');
        return;
    }

    if (!form || !form.reportValidity()) return;

    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(form);
        const result = await complaintApi('../../../backend/api/summons/issue.php', {
            method: 'POST',
            body: formData
        });

        window.agapNotify?.(result.message, 'success');
        closeIssueSummonModal();

        const caseId = result.case_id;
        const docId = result.document_id;
        const redirectUrl = `../gps/proof-service.php?case_id=${encodeURIComponent(caseId)}` + (docId ? `&document_id=${encodeURIComponent(docId)}` : '');

        setTimeout(() => {
            window.location.href = redirectUrl;
        }, 350);
    } catch (error) {
        if (msg) {
            msg.textContent = error.message;
            msg.style.color = '#dc2626';
        }
        window.agapNotify?.(error.message, 'error');
        if (submitBtn) submitBtn.disabled = false;
    }
}

window.openIssueSummonModal = openIssueSummonModal;
window.closeIssueSummonModal = closeIssueSummonModal;
window.handleIssueSummonClick = handleIssueSummonClick;
window.submitIssueSummon = submitIssueSummon;

function toggleStatusTracker() {
    const section = document.getElementById('statusTrackerSection');
    const arrow = document.getElementById('statusToggleArrow');
    const collapseIcon = document.getElementById('trackerCollapseIcon');
    if (!section) return;

    const isOpen = section.style.display !== 'none';
    if (isOpen) {
        section.style.display = 'none';
        if (arrow) arrow.textContent = '▼';
        if (collapseIcon) collapseIcon.textContent = '▼';
    } else {
        section.style.display = 'block';
        if (arrow) arrow.textContent = '▲';
        if (collapseIcon) collapseIcon.textContent = '▲';
    }
}

function toggleCaseHistoryDrawer() {
    const content = document.getElementById('caseHistoryContent');
    const icon = document.getElementById('historyDrawerIcon');
    if (!content) return;
    const isOpen = content.style.display !== 'none';
    content.style.display = isOpen ? 'none' : 'block';
    if (icon) icon.textContent = isOpen ? '▶' : '▼';
}

async function loadCaseProgress(complaintId) {
    if (!complaintId) return;
    try {
        const response = await fetch(`../../../backend/api/complaints/progress.php?id=${encodeURIComponent(complaintId)}&_t=${Date.now()}`, { cache: 'no-store' });
        const result = await response.json();
        if (!response.ok || !result.success || !result.data) {
            return;
        }

        const progress = result.data;
        window.caseProgressData = progress;

        // Configure Issue Summon button
        const issueBtn = document.getElementById('issueSummonButton');
        const issueText = document.getElementById('issueSummonButtonText');
        if (issueBtn && progress.actions?.summon_button) {
            const btnInfo = progress.actions.summon_button;
            if (issueText) issueText.textContent = btnInfo.label;
            issueBtn.title = btnInfo.tooltip || '';
            // Reset inline overrides each time
            issueBtn.style.opacity = '';
            issueBtn.style.cursor = '';
            issueBtn.removeAttribute('data-summon-gated');

            if (btnInfo.disabled) {
                // Visually disabled — mark as gated so handleIssueSummonClick can redirect to proof page
                issueBtn.setAttribute('data-summon-gated', 'true');
                issueBtn.className = 'btn-secondary';
                issueBtn.style.opacity = '0.6';
                issueBtn.style.cursor = 'not-allowed';
            } else if (btnInfo.action === 'view_proof') {
                issueBtn.className = 'btn-secondary';
            } else {
                issueBtn.className = 'btn-create';
            }
        }


        // Render Status Tracker
        renderStatusTracker(progress);
    } catch (err) {
        console.error('Error loading case progress:', err);
    }
}


function renderStatusTracker(progress) {
    const track = document.getElementById('statusStepperTrack');
    const badge = document.getElementById('trackerCurrentStageBadge');
    const highlightBox = document.getElementById('statusStageHighlights');
    const stageNameEl = document.getElementById('highlightStageName');
    const stageDetailsEl = document.getElementById('highlightStageDetails');
    const historyCountEl = document.getElementById('historyEventCount');
    const historyTimelineEl = document.getElementById('caseHistoryTimeline');

    if (!track || !progress.stages) return;

    const stages = progress.stages;
    const currentIdx = progress.current_stage_index ?? 0;
    const currentStage = stages[currentIdx] || stages[0];

    // Current stage badge
    if (badge && currentStage) {
        const titleSlug = currentStage.title.toLowerCase().replace(/[^a-z0-9]/g, '-');
        badge.className = `status-pill status-${titleSlug}`;
        badge.textContent = `Stage ${currentStage.number}: ${currentStage.title}`;
    }

    // Render 9 horizontal steps
    track.innerHTML = stages.map((st, idx) => {
        const isCompleted = st.state === 'completed';
        const isCurrent = st.state === 'current';
        const isLast = idx === stages.length - 1;
        const flagClass = st.flag ? `flag-${st.flag}` : '';

        let circleContent = '';
        if (isCompleted) {
            circleContent = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        } else if (isCurrent) {
            circleContent = '<span class="circle-dot"></span>';
        } else {
            circleContent = '<span class="circle-empty"></span>';
        }

        return `
            <div class="stepper-step-item ${st.state} ${flagClass}" data-step-id="${escapeHtml(st.id)}">
                <div class="stepper-node-wrap">
                    <div class="stepper-circle" title="${escapeHtml(st.title + ': ' + (st.subtitle || st.state))}">${circleContent}</div>
                    ${!isLast ? '<div class="stepper-connector-line"></div>' : ''}
                </div>
                <div class="stepper-label-wrap">
                    <span class="stepper-step-title">${escapeHtml(st.title)}</span>
                    <span class="stepper-step-sub">${escapeHtml(st.subtitle || '—')}</span>
                </div>
            </div>
        `;
    }).join('');

    // Highlight info
    if (highlightBox && currentStage) {
        highlightBox.style.display = 'block';
        if (stageNameEl) stageNameEl.textContent = `Current Stage: ${currentStage.title} (${currentStage.subtitle || currentStage.state})`;
        if (stageDetailsEl) stageDetailsEl.textContent = currentStage.details || 'Awaiting procedural actions.';
    }

    // History Timeline
    const historyEvents = progress.history || [];
    if (historyCountEl) historyCountEl.textContent = historyEvents.length;
    if (historyTimelineEl) {
        if (!historyEvents.length) {
            historyTimelineEl.innerHTML = '<p style="color:#64748b; font-size:0.85rem; margin:0;">No case events recorded yet.</p>';
        } else {
            historyTimelineEl.innerHTML = historyEvents.map(evt => {
                const dateStr = evt.date ? formatDateReadable(evt.date) + ' ' + formatTime12(evt.date.slice(11, 16)) : '—';
                const badgeClass = evt.badge_class || 'badge-info';
                return `
                    <div class="history-event-card">
                        <div class="history-event-body">
                            <div class="history-event-header">
                                <span class="history-event-title">${escapeHtml(evt.title)}</span>
                                <span class="history-event-date">${escapeHtml(dateStr)}</span>
                            </div>
                            <div class="history-event-details">
                                <span class="status-pill ${escapeHtml(badgeClass)}" style="font-size:0.72rem; padding: 2px 7px; margin-right: 6px;">${escapeHtml(evt.badge || evt.category)}</span>
                                <span>${escapeHtml(evt.details || '')}</span>
                                ${evt.actor ? `<span style="color:#94a3b8; font-size:0.75rem; margin-left: 6px;">· Recorded by ${escapeHtml(evt.actor)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }
    }
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
            if (purok) {
                if (purok.tagName === 'SELECT') {
                    // Gather all relevant Nominatim fields for matching
                    const rawArea = [
                        address.quarter, address.suburb, address.neighbourhood,
                        address.village, address.road, address.locality
                    ].filter(Boolean).join(' ').toLowerCase();

                    let matched = false;
                    // Try each option: check if the option value appears in the raw area string,
                    // or if a keyword from the raw area appears in the option value
                    for (const opt of purok.options) {
                        if (!opt.value) continue;
                        const optLower = opt.value.toLowerCase();
                        // Direct: raw area contains option value keyword
                        if (rawArea.includes(optLower)) {
                            purok.value = opt.value;
                            matched = true;
                            break;
                        }
                        // Reverse: option value contains a word from raw area
                        const rawWords = rawArea.split(/[\s,]+/).filter(w => w.length > 2);
                        if (rawWords.some(w => optLower.includes(w))) {
                            purok.value = opt.value;
                            matched = true;
                            break;
                        }
                    }
                    // Fire change event so any listeners (e.g., geocodeAddress) know about the update
                    if (matched) purok.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (address.quarter) {
                    purok.value = address.quarter;
                }
            }
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
    [street, purok].filter(Boolean).forEach((field) => {
        field.addEventListener('input', () => {
            clearTimeout(geocodeTimer);
            geocodeTimer = setTimeout(geocodeAddress, 700);
        });
        field.addEventListener('change', () => {
            clearTimeout(geocodeTimer);
            geocodeTimer = setTimeout(geocodeAddress, 300);
        });
    });

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

/**
 * Searchable Resident Combobox Component
 * Allows typing to search residents by name, purok, or address,
 * but strictly enforces that ONLY a valid registered resident profile can be selected.
 */
window.initPartySearchCombobox = function(comboboxEl, residentsList) {
    if (!comboboxEl || comboboxEl._comboboxInitialized) return;
    comboboxEl._comboboxInitialized = true;

    residentsList = residentsList || window.AGAP_RESIDENTS || [];

    const searchInput = comboboxEl.querySelector('.party-search-input');
    const clearBtn = comboboxEl.querySelector('.party-search-clear');
    const hiddenId = comboboxEl.querySelector('input[type="hidden"][name$="resident_id"], input[type="hidden"][name$="resident_ids[]"]');
    const hiddenName = comboboxEl.querySelector('input[type="hidden"][name$="name"], input[type="hidden"][name$="names[]"]');
    const dropdown = comboboxEl.querySelector('.party-search-dropdown');
    const partyRole = comboboxEl.dataset.partyRole || '';

    if (!searchInput || !hiddenId) return;

    // Find preview element
    let previewEl = null;
    if (partyRole === 'complainant') {
        previewEl = document.getElementById('complainantPreview');
    } else if (partyRole === 'respondent') {
        previewEl = document.getElementById('respondentPreview');
    } else {
        const row = comboboxEl.closest('.additional-party-row');
        previewEl = row ? row.querySelector('.additional-party-preview') : null;
    }

    let highlightedIndex = -1;
    let currentRenderedItems = [];

    function escapeHtml(val) {
        return String(val ?? '').replace(/[&<>'"]/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[c] || c));
    }

    function renderPreview(res) {
        if (!previewEl) return;
        const details = [
            res.address ? `🏠 ${escapeHtml(res.address)}` : '',
            res.purok ? `📍 Purok ${escapeHtml(res.purok)}` : '',
            res.contact_no ? `📞 ${escapeHtml(res.contact_no)}` : ''
        ].filter(Boolean).join(' &bull; ');

        previewEl.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <div>
                    <strong style="color: #0f172a; font-size: 0.92rem;">${escapeHtml(res.name)}</strong>
                    ${details ? `<div style="font-size: 0.8rem; color: #475569; margin-top: 2px;">${details}</div>` : ''}
                </div>
                <span class="party-search-item-badge party-search-badge-verified">Verified Profile</span>
            </div>
        `;
        previewEl.style.display = 'block';
    }

    function hidePreview() {
        if (!previewEl) return;
        previewEl.style.display = 'none';
        previewEl.innerHTML = '';
    }

    function selectResident(res, triggerChange = true) {
        hiddenId.value = res.resident_id;
        if (hiddenName) hiddenName.value = res.name;

        const displayLabel = res.display_name + (res.address_text ? ' — ' + res.address_text : '');
        searchInput.value = displayLabel;
        searchInput.dataset.selectedId = String(res.resident_id);
        searchInput.dataset.selectedText = displayLabel;

        searchInput.classList.remove('is-invalid-unselected');
        searchInput.classList.add('is-valid-selected');
        if (clearBtn) clearBtn.style.display = 'flex';

        renderPreview(res);
        closeDropdown();

        if (triggerChange) {
            hiddenId.dispatchEvent(new Event('change', { bubbles: true }));
            window.syncDistinctPartyOptions?.();
            window.validateDistinctParties?.();
        }
    }

    function clearSelection(triggerChange = true) {
        hiddenId.value = '';
        if (hiddenName) hiddenName.value = '';
        searchInput.value = '';
        delete searchInput.dataset.selectedId;
        delete searchInput.dataset.selectedText;

        searchInput.classList.remove('is-valid-selected', 'is-invalid-unselected');
        if (clearBtn) clearBtn.style.display = 'none';

        hidePreview();
        closeDropdown();

        if (triggerChange) {
            hiddenId.dispatchEvent(new Event('change', { bubbles: true }));
            window.syncDistinctPartyOptions?.();
            window.validateDistinctParties?.();
        }
    }

    function closeDropdown() {
        if (dropdown) dropdown.style.display = 'none';
        highlightedIndex = -1;
    }

    function renderList(query = '') {
        if (!dropdown) return;
        const tokens = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

        let filtered = residentsList;
        if (tokens.length > 0) {
            filtered = residentsList.filter(res => {
                const text = res.search_text || '';
                return tokens.every(t => text.includes(t));
            });
        }

        // Limit results to 30 items
        const results = filtered.slice(0, 30);
        currentRenderedItems = results;
        highlightedIndex = -1;

        if (results.length === 0) {
            dropdown.innerHTML = `
                <div class="party-search-no-results">
                    <strong>No registered resident found matching "${escapeHtml(query)}"</strong>
                    <span>Only profiles registered in the resident database can be selected.</span>
                </div>
            `;
            dropdown.style.display = 'block';
            return;
        }

        // Check mutual exclusion IDs
        const compVal = document.getElementById('complainantResidentId')?.value;
        const respVal = document.getElementById('respondentResidentId')?.value;

        dropdown.innerHTML = results.map((res, idx) => {
            const isSelected = String(res.resident_id) === String(hiddenId.value);
            let isDisabled = false;
            let disabledReason = '';

            if (partyRole === 'complainant' && respVal && String(res.resident_id) === String(respVal)) {
                isDisabled = true;
                disabledReason = 'Selected as Respondent';
            } else if (partyRole === 'respondent' && compVal && String(res.resident_id) === String(compVal)) {
                isDisabled = true;
                disabledReason = 'Selected as Complainant';
            }

            return `
                <div class="party-search-item ${isSelected ? 'is-selected' : ''} ${isDisabled ? 'is-disabled' : ''}" data-index="${idx}" data-resident-id="${res.resident_id}">
                    <div class="party-search-item-top">
                        <span class="party-search-item-name">${escapeHtml(res.display_name)}</span>
                        ${isDisabled 
                            ? `<span class="party-search-item-badge party-search-badge-disabled">${disabledReason}</span>` 
                            : `<span class="party-search-item-badge party-search-badge-verified">Resident</span>`}
                    </div>
                    <div class="party-search-item-meta">
                        <span>📍 ${escapeHtml(res.purok ? 'Purok ' + res.purok : 'Barangay Tumana')}</span>
                        ${res.address ? `<span>🏠 ${escapeHtml(res.address)}</span>` : ''}
                        ${res.contact_no ? `<span>📞 ${escapeHtml(res.contact_no)}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        dropdown.querySelectorAll('.party-search-item').forEach(item => {
            item.addEventListener('click', () => {
                if (item.classList.contains('is-disabled')) {
                    window.agapNotify?.('The complainant and respondent cannot be the same resident profile.', 'error');
                    return;
                }
                const resId = Number(item.dataset.residentId);
                const selected = residentsList.find(r => r.resident_id === resId);
                if (selected) {
                    selectResident(selected);
                }
            });
        });

        dropdown.style.display = 'block';
    }

    function updateHighlight(index) {
        const items = dropdown.querySelectorAll('.party-search-item:not(.is-disabled)');
        if (!items.length) return;
        items.forEach(el => el.classList.remove('highlighted'));
        if (index >= 0 && index < items.length) {
            highlightedIndex = index;
            items[index].classList.add('highlighted');
            items[index].scrollIntoView({ block: 'nearest' });
        }
    }

    // Input Events
    searchInput.addEventListener('input', () => {
        if (clearBtn) clearBtn.style.display = searchInput.value ? 'flex' : 'none';

        // If user typed something that doesn't match selectedText, clear hiddenId
        if (searchInput.value !== searchInput.dataset.selectedText) {
            hiddenId.value = '';
            if (hiddenName) hiddenName.value = '';
            delete searchInput.dataset.selectedId;
            searchInput.classList.remove('is-valid-selected');
            hidePreview();
        }

        renderList(searchInput.value);
    });

    searchInput.addEventListener('focus', () => {
        renderList(searchInput.dataset.selectedId ? '' : searchInput.value);
    });

    searchInput.addEventListener('click', () => {
        renderList(searchInput.dataset.selectedId ? '' : searchInput.value);
    });

    searchInput.addEventListener('keydown', (e) => {
        if (dropdown.style.display !== 'block') {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                renderList(searchInput.value);
                e.preventDefault();
                return;
            }
        }

        const items = dropdown.querySelectorAll('.party-search-item:not(.is-disabled)');
        if (!items.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const nextIdx = (highlightedIndex + 1) % items.length;
            updateHighlight(nextIdx);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prevIdx = (highlightedIndex - 1 + items.length) % items.length;
            updateHighlight(prevIdx);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (highlightedIndex >= 0 && items[highlightedIndex]) {
                items[highlightedIndex].click();
            } else if (items.length === 1) {
                items[0].click();
            }
        } else if (e.key === 'Escape') {
            closeDropdown();
        }
    });

    // Enforce selection from resident profile only
    searchInput.addEventListener('blur', () => {
        setTimeout(() => {
            if (!hiddenId.value) {
                // Typed arbitrary text without picking a valid profile: reset input!
                searchInput.value = '';
                if (clearBtn) clearBtn.style.display = 'none';
                searchInput.classList.remove('is-valid-selected');
                delete searchInput.dataset.selectedId;
                delete searchInput.dataset.selectedText;
                hidePreview();
            } else if (searchInput.dataset.selectedText) {
                searchInput.value = searchInput.dataset.selectedText;
                searchInput.classList.add('is-valid-selected');
            }
            closeDropdown();
        }, 220);
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearSelection();
            searchInput.focus();
        });
    }

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!comboboxEl.contains(e.target)) {
            closeDropdown();
        }
    });

    // Initial pre-population
    if (hiddenId.value) {
        const found = residentsList.find(r => String(r.resident_id) === String(hiddenId.value));
        if (found) {
            selectResident(found, false);
        }
    }
};

window.initAllPartyComboboxes = function() {
    const list = window.AGAP_RESIDENTS || [];
    document.querySelectorAll('.party-search-combobox').forEach(el => {
        window.initPartySearchCombobox(el, list);
    });
};

