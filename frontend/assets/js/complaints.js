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
    initialiseCaseTabs();
    initialiseDropzone();

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
    const consent = document.querySelector('[data-ai-external-consent]');
    if (!button || !narrative || !preview || !suggestion || !consent) return;

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
        if (!consent.checked) {
            window.agapNotify?.('Confirm that the narrative may be sent to Google Gemini before using AI enhancement.', 'error');
            consent.focus();
            return;
        }

        setBusy(true);
        preview.hidden = true;
        try {
            const response = await fetch('../../../backend/api/ai/refine-narrative.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ narrative: original, external_ai_consent: consent.checked })
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

function initialiseCaseTabs() {
    document.querySelectorAll('.case-tab-item').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const tabId = btn.getAttribute('data-tab');
            if (tabId) switchCaseTab(tabId);
        });
    });

    // Check URL parameter or hash to activate specific tab on page load
    const urlParams = new URLSearchParams(window.location.search);
    const requestedTab = urlParams.get('tab') || window.location.hash.replace('#', '');
    if (requestedTab) {
        let targetId = requestedTab;
        if (!targetId.startsWith('tab-')) {
            targetId = 'tab-' + targetId;
        }
        const targetBtn = document.querySelector(`.case-tab-item[data-tab="${targetId}"]`);
        if (targetBtn) {
            switchCaseTab(targetId);
        }
    }
}

function switchCaseTab(tabId) {
    if (tabId === 'tab-parties') tabId = 'tab-mediation';
    if (tabId === 'tab-documents') tabId = 'tab-conciliation';

    document.querySelectorAll('.case-tab-item').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
    });
    document.querySelectorAll('.case-tab-pane').forEach(pane => {
        pane.classList.toggle('active', pane.id === tabId);
    });

    try {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId.replace('tab-', ''));
        window.history.replaceState({}, '', url);
    } catch (_) {}

    // Invalidate map sizes to ensure tiles render properly after tab switch
    if (tabId === 'tab-overview' && window.complaintDetailMapInstance) {
        setTimeout(() => window.complaintDetailMapInstance.invalidateSize(), 150);
    }
}
window.switchCaseTab = switchCaseTab;

function initTab2Map(lat, lng, compNum, locAddress) {
    const mapContainer = document.getElementById('tab2DetailMap');
    if (!mapContainer || !window.L || isNaN(lat) || isNaN(lng)) return;

    if (window.tab2MapInstance) {
        window.tab2MapInstance.remove();
        window.tab2MapInstance = null;
    }

    try {
        const map = L.map('tab2DetailMap', { scrollWheelZoom: false }).setView([lat, lng], 16);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        const marker = L.marker([lat, lng]).addTo(map);
        marker.bindPopup(`<strong>${escapeHtml(compNum)}</strong><br>${escapeHtml(locAddress)}`).openPopup();
        window.tab2MapInstance = map;
        setTimeout(() => map.invalidateSize(), 200);
    } catch (e) {
        console.error('Error initializing Tab 2 map:', e);
    }
}

function handlePreviewKp9() {
    const caseId = window.complaintData?.case_id || (new URLSearchParams(window.location.search)).get('case_id');
    const docs = window.complaintData?.case_workspace?.documents || [];
    const kp9Doc = docs.find(d => String(d.template_name || '').includes('KP Form 9') || String(d.template_name || '').includes('Summon'));
    const docId = kp9Doc ? kp9Doc.document_id : null;
    if (caseId) {
        if (typeof openSummonsPreviewModal === 'function') {
            openSummonsPreviewModal(caseId, docId);
        } else {
            console.warn('openSummonsPreviewModal is not defined.');
        }
    } else {
        window.agapNotify?.('Case has not been docketed yet or no summons issued.', 'warning');
    }
}
window.handlePreviewKp9 = handlePreviewKp9;

function initialiseDropzone() {
    const dropzone = document.getElementById('evidenceDropzone');
    if (!dropzone) return;

    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('dragover');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('dragover');
        }, false);
    });

    dropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt ? dt.files : null;
        if (files && files.length > 0) {
            uploadDroppedFiles(files);
        }
    }, false);
}

window.handleDropzoneFileSelect = function(e) {
    const files = e.target.files;
    if (files && files.length > 0) {
        uploadDroppedFiles(files);
    }
};

async function uploadDroppedFiles(files) {
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id');
    if (!complaintId) {
        window.agapNotify?.('Complaint ID missing.', 'error');
        return;
    }

    const progressEl = document.getElementById('dropzoneUploadProgress');
    if (progressEl) {
        progressEl.style.display = 'block';
        progressEl.textContent = `Uploading ${files.length} file(s)…`;
    }

    let successCount = 0;
    let failCount = 0;

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const formData = new FormData();
        formData.append('complaint_id', complaintId);
        formData.append('attachment', file);

        try {
            const res = await fetch('../../../backend/api/complaints/attachments.php', {
                method: 'POST',
                body: formData
            });
            const json = await res.json().catch(() => ({}));
            if (res.ok && json.success) {
                successCount++;
            } else {
                failCount++;
            }
        } catch (err) {
            failCount++;
        }
    }

    if (progressEl) progressEl.style.display = 'none';

    if (successCount > 0) {
        window.agapNotify?.(`Successfully uploaded ${successCount} attachment(s).`, 'success');
        await loadComplaintDetails();
    }
    if (failCount > 0) {
        window.agapNotify?.(`Failed to upload ${failCount} attachment(s).`, 'error');
    }
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

        window.complaintData = data;

        // Auto-open Print Preview modal if redirected from complaint creation
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('preview') === '1' || urlParams.get('print_preview') === '1') {
            setTimeout(() => {
                if (typeof openPaanyayaPreviewModal === 'function') {
                    openPaanyayaPreviewModal();
                }
            }, 350);
            try {
                const cleanUrl = new URL(window.location);
                cleanUrl.searchParams.delete('preview');
                cleanUrl.searchParams.delete('print_preview');
                window.history.replaceState({}, '', cleanUrl);
            } catch (_) {}
        }

        // Format Docket Number (e.g. 10-17-2026) - never show CMP- prefix
        const formatDocketDisplay = (num, id, dt) => {
            if (num && !String(num).startsWith('CMP-')) return String(num);
            const d = dt ? new Date(dt) : new Date();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const ss = String(id || 1).padStart(2, '0');
            const yy = d.getFullYear() || 2026;
            return `${mm}-${ss}-${yy}`;
        };
        const docketNum = formatDocketDisplay(data.case_number || data.complaint_number, data.complaint_id, data.created_at);
        const compNum = docketNum;

        // Header Case Number
        const compNumEl = document.getElementById('complaintNumber');
        if (compNumEl) {
            compNumEl.textContent = 'Case #' + docketNum;
        }

        const compTitleEl = document.getElementById('complaintTitle');
        if (compTitleEl) compTitleEl.textContent = data.complaint_title || 'Untitled Complaint';

        // Status Badge
        const statusEl = document.getElementById('complaintStatusBadge');
        const caseStage = data.case_workspace?.case?.case_status;
        let displayStage = caseStage ? (caseStage + ' Stage') : (data.lifecycle_status || 'Under Review');
        if (data.status === 'Pre-Docketed (Paanyaya)') {
            displayStage = 'Pre-Docketed (Paanyaya)';
        } else if (data.status === 'Settled' || data.status === 'Resolved at Paanyaya / Closed') {
            displayStage = 'Settled';




        }
        if (statusEl) {
            if (data.status === 'Pre-Docketed (Paanyaya)') {
                statusEl.className = 'case-stage-pill';
                statusEl.style.background = '#dcfce7';
                statusEl.style.color = '#15803d';
                statusEl.style.border = '1px solid #86efac';
            } else if (data.status === 'Settled' || data.status === 'Resolved at Paanyaya / Closed') {



                statusEl.className = 'case-stage-pill';
                statusEl.style.background = '#dcfce7';
                statusEl.style.color = '#15803d';
                statusEl.style.border = '1px solid #86efac';


            } else {
                const stSlug = (caseStage || data.lifecycle_status || 'pending').toLowerCase().replace(/[^a-z0-9]/g, '-');
                statusEl.className = 'case-stage-pill status-' + stSlug;
            }
            statusEl.textContent = displayStage;
        }

        // Reopen for CFA button: only show for Settled cases (RA 7160 Sec. 417)
        const reopenBtn = document.getElementById('btnReopenForCfa');
        if (reopenBtn) {
            const isSettled = (data.status === 'Settled' || data.case_workspace?.case?.case_status === 'Settled');
            reopenBtn.style.display = isSettled ? '' : 'none';
            // Store case_id on window for the modal to use
            if (isSettled && data.case_id) {
                window._reopenCaseCaseId = data.case_id;
            }
        }

        // Action Bar Status Tracker Button
        const statusBtnLabel = document.getElementById('statusTrackerButtonLabel');
        if (statusBtnLabel) {
            statusBtnLabel.textContent = 'Status: ' + (caseStage ? 'In Progress' : (data.status === 'Pre-Docketed (Paanyaya)' ? 'Paanyaya' : (data.status === 'Settled' ? 'Settled' : (data.lifecycle_status || 'In Progress'))));
        }
        const statusDot = document.getElementById('statusDotIndicator');
        if (statusDot) {
            const isClosed = ['Amicable Settlement', 'Arbitration Award', 'Settled', 'Closed', 'Resolved at Paanyaya / Closed'].includes(data.lifecycle_status) || data.status === 'Resolved at Paanyaya / Closed' || data.status === 'Settled';
            statusDot.style.background = isClosed ? '#10b981' : (data.status === 'Pre-Docketed (Paanyaya)' ? '#16a34a' : '#f59e0b');
        }

        // Hero Case Type Badge
        const heroTypeEl = document.getElementById('heroCaseTypeBadge');
        if (heroTypeEl) heroTypeEl.textContent = (data.case_type || 'Civil').toUpperCase() + ' COMPLAINT';

        // Hero Days Remaining Counter Badge
        const daysBadge = document.getElementById('heroDaysRemainingBadge');
        const daysText = document.getElementById('heroDaysRemainingText');
        const timer = data.case_workspace?.case?.mediation_timer;
        if (daysBadge && daysText) {
            if (data.status === 'Pre-Docketed (Paanyaya)') {
                daysBadge.style.display = 'inline-flex';
                daysBadge.className = 'hero-days-pill';
                daysBadge.style.background = '#dcfce7';
                daysBadge.style.color = '#15803d';
                daysBadge.style.border = '1px solid #86efac';
                daysText.textContent = 'Paanyaya (Statutory Clock Paused)';
            } else if (timer) {
                daysBadge.style.display = 'inline-flex';
                daysBadge.className = 'hero-days-pill ' + (timer.badge_class || 'badge-within-period');
                daysText.textContent = timer.badge_label || (timer.days_remaining !== undefined ? `${timer.days_remaining} days remaining` : 'Within Period');
            } else if (data.case_id) {
                daysBadge.style.display = 'inline-flex';
                daysBadge.className = 'hero-days-pill badge-within-period';
                daysText.textContent = 'Active Proceeding';
            } else {
                daysBadge.style.display = 'inline-flex';
                daysBadge.className = 'hero-days-pill badge-within-period';
                daysText.textContent = 'Pre-docketing';
            }
        }

        // Legacy Type & Category Badges
        const typeEl = document.getElementById('complaintCaseTypeBadge');
        if (typeEl) typeEl.textContent = data.case_type || 'Civil';

        const catEl = document.getElementById('complaintCategoryBadge');
        if (catEl) catEl.textContent = data.category_name || (data.category_id ? 'Category #' + data.category_id : 'General');

        const caseNumber = data.case_number || (data.case_id ? String(data.case_id) : null);

        // Edit link
        const editBtn = document.getElementById('editComplaintBtn');
        if (editBtn) editBtn.href = 'complaint-edit.php?id=' + encodeURIComponent(complaintId);

        // Load Case Status / Progress Tracker & Action Buttons first
        await loadCaseProgress(complaintId);

        // Render dedicated Paanyaya Pre-Docketing Tab
        renderPaanyayaTab(data);

        // Hero Meta Columns
        setText('infoComplaintTitle', data.complaint_title);
        setText('infoCategory', data.category_name || (data.category_id ? 'Category #' + data.category_id : 'Threats'));
        setText('infoCaseType', data.case_type || 'Civil');
        const incTime = data.incident_time ? formatTime12(data.incident_time) : '';
        const incDate = data.incident_date ? formatDateReadable(data.incident_date) : 'N/A';
        setText('infoIncidentDate', (incDate !== 'N/A' && incTime && incTime !== 'Not recorded') ? `${incDate} at ${incTime}` : incDate);
        setText('infoIncidentTime', incTime || 'Not recorded');
        setText('infoDateFiled', data.created_at ? formatDateReadable(data.created_at) : '—');
        setText('infoStatus', displayStage);
        window.complaintCreatedAt = data.created_at || null;

        const infoDocketEl = document.getElementById('infoDocketCase');
        if (infoDocketEl) {
            if (data.case_id) {
                infoDocketEl.innerHTML = `<span class="meta-pill case-pill" style="display:inline-flex;">${escapeHtml(caseNumber || ('Case #' + data.case_id))}</span>`;
            } else {
                infoDocketEl.textContent = 'Not yet docketed';
            }
        }

        // Narrative & Additional Details (Overview & Tab 2)
        const narrEl = document.getElementById('complaintNarrative');
        if (narrEl) narrEl.textContent = data.narrative || 'No statement narrative provided.';
        setText('tab2ComplaintNarrative', data.narrative || 'No statement narrative provided.');

        const addWrap = document.getElementById('complaintAdditionalDetailsWrap');
        const addEl = document.getElementById('complaintAdditionalDetails');
        const tab2AddWrap = document.getElementById('tab2AdditionalDetailsWrap');
        const tab2AddEl = document.getElementById('tab2AdditionalDetails');
        if (data.additional_details && data.additional_details.trim()) {
            if (addWrap && addEl) {
                addWrap.style.display = 'block';
                addEl.textContent = data.additional_details;
            }
            if (tab2AddWrap && tab2AddEl) {
                tab2AddWrap.style.display = 'block';
                tab2AddEl.textContent = data.additional_details;
            }
        } else {
            if (addWrap) addWrap.style.display = 'none';
            if (tab2AddWrap) tab2AddWrap.style.display = 'none';
        }

        // Incident Location & Leaflet Maps
        const locAddress = data.incident_location || (data.location && data.location.address) || 'Not specified';
        const locLandmark = data.incident_landmark || 'None';
        setText('locationAddressText', locAddress);
        setText('locationLandmarkText', locLandmark);
        setText('tab2LocationAddress', locAddress);
        setText('tab2LocationLandmark', locLandmark);

        const mapContainer = document.getElementById('complaintDetailMap');
        const emptyMapPlaceholder = document.getElementById('emptyMapPlaceholder');
        const coordText = document.getElementById('locationCoordinatesText');

        const lat = data.location && data.location.latitude ? parseFloat(data.location.latitude) : null;
        const lng = data.location && data.location.longitude ? parseFloat(data.location.longitude) : null;

        if (lat && lng && !isNaN(lat) && !isNaN(lng)) {
            const formattedCoords = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            if (coordText) coordText.textContent = formattedCoords;
            setText('tab2LocationCoords', formattedCoords);

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
                marker.bindPopup(`<strong>${escapeHtml(compNum)}</strong><br>${escapeHtml(locAddress)}`).openPopup();
                window.complaintDetailMapInstance = map;
                setTimeout(() => map.invalidateSize(), 200);
            }

            initTab2Map(lat, lng, compNum, locAddress);
        } else {
            if (coordText) coordText.textContent = 'None';
            setText('tab2LocationCoords', 'None');
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

        // Requested Resolution & Location Details
        const reqWrap = document.getElementById('complaintRequestedResolutionWrap');
        const reqEl = document.getElementById('complaintRequestedResolution');
        if (reqWrap && reqEl) {
            if (data.requested_resolution && data.requested_resolution.trim()) {
                reqWrap.style.display = 'block';
                reqEl.textContent = data.requested_resolution;
            } else {
                reqWrap.style.display = 'none';
            }
        }

        const locDetRow = document.getElementById('locationDetailsRow');
        const locDetText = document.getElementById('locationDetailsText');
        if (locDetRow && locDetText) {
            if (data.incident_location_details && data.incident_location_details.trim()) {
                locDetRow.style.display = 'flex';
                locDetText.textContent = data.incident_location_details;
            } else {
                locDetRow.style.display = 'none';
            }
        }

        // Render Parties and Attachments
        const parties = data.parties || [];
        const attachments = data.attachments || [];
        renderParties(parties);
        renderAttachments(attachments);

        // Render KP Form 7 Overview & Scanned Document
        renderKp7Overview(data);

        // Render Combined Case Workspace if docketed
        renderCaseWorkspace(data.case_workspace, data);

        // Render Comprehensive Case Summary Dossier
        renderCaseSummary(data);
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
            options += `<option value="${Number(resident.resident_id)}">${escapeHtml(name + ' — ' + addrText)}</option>`;
        });
        select.innerHTML = options;
    });
}

function renderParties(parties) {
    const container = document.getElementById('partiesListContainer');
    const fullContainer = document.getElementById('partiesFullContainer');
    const tbody = document.getElementById('partiesTable');
    const countEl = document.getElementById('partiesCount');
    const fullCountEl = document.getElementById('partiesFullCount');

    if (countEl) countEl.textContent = parties.length;
    if (fullCountEl) fullCountEl.textContent = parties.length;

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

    if (!container && !fullContainer) return;

    if (parties.length === 0) {
        const emptyHtml = '<div class="empty-detail-state"><p style="margin:0;">No parties added to this complaint yet.</p></div>';
        if (container) container.innerHTML = emptyHtml;
        if (fullContainer) fullContainer.innerHTML = emptyHtml;
        return;
    }

    let cards = '';
    let firstComplainantSeen = false;

    parties.forEach(party => {
        const pType = String(party.party_type || '').trim().toLowerCase();
        let typeClass = 'complainant';
        let isPrimary = false;

        if (pType === 'complainant') {
            typeClass = 'complainant';
            if (!firstComplainantSeen) {
                isPrimary = true;
                firstComplainantSeen = true;
            }
        } else if (pType === 'respondent') {
            typeClass = 'respondent';
        } else {
            typeClass = 'witness';
        }

        const addrParts = [];
        if (party.purok) {
            const pLower = String(party.purok).toLowerCase();
            addrParts.push(pLower.startsWith('purok') ? party.purok : `Purok ${party.purok}`);
        }
        if (party.address && (!party.purok || !party.address.toLowerCase().includes(party.purok.toLowerCase()))) {
            addrParts.push(party.address);
        } else if (party.address && !addrParts.length) {
            addrParts.push(party.address);
        }
        const fullAddress = addrParts.length > 0 ? addrParts.join(', ') : 'No address on file';

        const isMinor = Number(party.is_minor) === 1 || (party.age !== undefined && party.age !== null && party.age !== '' && Number(party.age) < 18);
        const guardianInfo = party.guardian_name ? `🛡️ Guardian: ${escapeHtml(party.guardian_name)}${party.guardian_relationship ? ` (${escapeHtml(party.guardian_relationship)})` : ''}` : '';
        const prefContact = party.preferred_contact ? `✉️ ${escapeHtml(party.preferred_contact)}` : '';
        const ageText = (party.age !== undefined && party.age !== null && party.age !== '') ? `🎂 ${party.age} yrs` : '';

        cards += `
            <div class="party-entry-card">
                <div class="party-entry-top">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <span class="party-pill-tag ${typeClass}">${escapeHtml((party.party_type || 'Party').toUpperCase())}</span>
                        ${isPrimary ? '<span class="party-sub-badge">Primary</span>' : ''}
                        ${isMinor ? '<span style="background:#fee2e2;color:#991b1b;font-size:0.7rem;font-weight:700;padding:2px 6px;border-radius:4px;">Minor</span>' : ''}
                        ${ageText ? `<span style="font-size:0.75rem;color:#64748b;font-weight:600;">${ageText}</span>` : ''}
                    </div>
                </div>
                <div class="party-entry-name">${escapeHtml(party.resident_name || 'Unnamed Party')}${party.suffix ? ' ' + escapeHtml(party.suffix) : ''}</div>
                <div class="party-entry-meta">📞 ${party.contact_unknown ? 'Contact: Unknown' : escapeHtml(party.contact_no || 'No contact on file')}${prefContact ? ' &bull; ' + prefContact : ''}</div>
                <div class="party-entry-meta">📍 ${escapeHtml(fullAddress)}${party.address_requires_verification ? ' <span style="color:#b45309;font-weight:600;">(Needs Verification)</span>' : ''}</div>
                ${guardianInfo ? `<div class="party-entry-meta" style="color:#4338ca;font-weight:600;font-size:0.78rem;">${guardianInfo}</div>` : ''}
            </div>
        `;
    });

    if (container) container.innerHTML = cards;
    if (fullContainer) fullContainer.innerHTML = cards;
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
                            <button type="button" class="delete-button" onclick="deleteAttachment(${Number(attachment.attachment_id)})">Delete</button>
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
                    <button type="button" class="btn-icon-action delete" title="Delete attachment" onclick="deleteAttachment(${Number(att.attachment_id)})">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>
                </div>
            </div>
        `;
    });
    container.innerHTML = cards;
}

function formatDateTimeReadable(str) {
    if (!str) return '—';
    try {
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) + ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch (e) {
        return str;
    }
}

function renderCaseWorkspace(workspace, complaint) {
    if (!complaint) return;

    window.__agapComplaintWorkspace = workspace;
    window.__agapCurrentComplaint = complaint;

    const caseData = workspace?.case || {};
    const caseNum = caseData.case_number || complaint.case_number || (complaint.case_id ? String(complaint.case_id) : 'N/A');
    const stageCaseId = caseData.case_id || complaint.case_id || null;
    const stages = workspace?.stages || {};
    const med = stages.mediation || {};
    const con = stages.conciliation || {};
    const docs = workspace?.documents || [];
    const hearings = workspace?.hearings || [];
    const assignments = workspace?.assignments || [];

    // 1. Stage Progression Cards (Stage 1, Stage 2, Stage 3)
    const stage1Card = document.getElementById('stage1Card');
    const stage1Status = document.getElementById('stage1StatusText');
    const stage1Icon = document.getElementById('stage1Icon');
    const stage2Card = document.getElementById('stage2Card');
    const stage2Icon = document.getElementById('stage2Icon');
    const stage2Status = document.getElementById('stage2StatusText');
    const stage3Card = document.getElementById('stage3Card');
    const stage3Icon = document.getElementById('stage3Icon');
    const stage3Status = document.getElementById('stage3StatusText');

    if (stageCaseId) {
        if (stage1Card && stage1Status) {
            stage1Card.className = 'stage-card-item active';
            stage1Status.textContent = med.stage_status || 'In Progress';
            if (stage1Icon) stage1Icon.textContent = '✓';
        }
        if (stage2Card && stage2Status && stage2Icon) {
            if (con.is_locked) {
                stage2Card.className = 'stage-card-item locked';
                stage2Icon.textContent = '🔒';
                stage2Status.textContent = 'Locked until mediation concludes';
            } else {
                stage2Card.className = 'stage-card-item active';
                stage2Icon.textContent = '✓';
                stage2Status.textContent = con.stage_status || 'In Progress';
            }
        }
        if (stage3Card && stage3Status) {
            const arb = stages?.arbitration || {};
            const isArbActive = Boolean(arb.stage_status && arb.stage_status !== 'Locked' && arb.stage_status !== 'Pending');
            if (isArbActive) {
                stage3Card.className = 'stage-card-item active';
                stage3Status.textContent = arb.stage_status || 'In Progress';
                if (stage3Icon) stage3Icon.textContent = '✓';
            } else {
                stage3Card.className = 'stage-card-item pending';
                stage3Status.textContent = arb.stage_status || 'Pending';
                if (stage3Icon) stage3Icon.textContent = '🔒';
            }
        }
    }

    // 2. Session #1 Schedule & Attendance Card
    const sessHeading = document.getElementById('sessionTitleHeading');
    const sessSubtitle = document.getElementById('sessionSubtitleHeading');
    const manageSchedLink = document.getElementById('cwManageScheduleLink');
    const cAttText = document.getElementById('complainantAttendanceText');
    const rAttText = document.getElementById('respondentAttendanceText');
    const savedMinText = document.getElementById('savedMinutesText');
    const savedMinIcon = document.getElementById('savedMinutesIcon');

    const nextH = med.next_hearing;
    const cAtt = med.attendance_summary?.complainant || { present: 0, absent: 0 };
    const rAtt = med.attendance_summary?.respondent || { present: 0, absent: 0 };

    if (sessHeading) {
        sessHeading.textContent = `Session #${med.sessions_count || 1} Schedule & Attendance`;
    }
    if (sessSubtitle) {
        if (nextH && nextH.hearing_date) {
            const [datePart, timePart] = nextH.hearing_date.split(' ');
            const readableDate = formatDateReadable(datePart);
            const readableTime = timePart ? formatTime12(timePart) : '';
            sessSubtitle.textContent = `Next Session: ${readableDate}${readableTime ? ' at ' + readableTime : ''} (${nextH.venue || 'Barangay Hall'})`;
        } else {
            sessSubtitle.textContent = 'No upcoming session scheduled';
        }
    }
    if (manageSchedLink) {
        manageSchedLink.href = stageCaseId
            ? `../hearings/schedules.php?case_id=${encodeURIComponent(stageCaseId)}`
            : '../hearings/schedules.php';
    }

    if (cAttText) cAttText.textContent = `Present (${cAtt.present || 0}), Absent (${cAtt.absent || 0})`;
    if (rAttText) rAttText.textContent = `Present (${rAtt.present || 0}), Absent (${rAtt.absent || 0})`;

    if (savedMinText) {
        if (med.minutes_summary?.count > 0) {
            savedMinText.textContent = med.minutes_summary.latest_status || 'Recorded';
            if (savedMinIcon) savedMinIcon.textContent = '✓';
        } else {
            savedMinText.textContent = 'Not Recorded Yet';
            if (savedMinIcon) savedMinIcon.textContent = '⚠️';
        }
    }

    // Session Action Buttons
    const recAttBtn = document.getElementById('recordAttendanceBtn');
    const addMinBtn = document.getElementById('addMinutesBtn');
    const recOutBtn = document.getElementById('recordOutcomeBtn');

    if (stageCaseId) {
        if (recAttBtn) recAttBtn.href = `../cases/mediation-workspace.php?case_id=${encodeURIComponent(stageCaseId)}&action=attendance`;
        if (addMinBtn) addMinBtn.href = `../cases/mediation-workspace.php?case_id=${encodeURIComponent(stageCaseId)}&action=minutes`;
        if (recOutBtn) recOutBtn.href = `../cases/mediation-workspace.php?case_id=${encodeURIComponent(stageCaseId)}&action=outcome`;
    }

    // 3. Case Team Assignment
    const teamContainer = document.getElementById('cwTeamContainer');
    const manageTeamLink = document.getElementById('cwManageTeamLink');
    if (manageTeamLink && stageCaseId) {
        manageTeamLink.href = `../cases/case-list.php?assign_case_id=${encodeURIComponent(stageCaseId)}#caseAssignments`;
    }
    if (teamContainer) {
        if (!assignments.length) {
            teamContainer.innerHTML = `
                <div class="empty-detail-state" style="text-align: center; padding: 20px; color: #64748b;">
                    <p style="margin: 0 0 10px; font-size: 0.88rem;">No case team has been assigned yet. 3 active Lupon Members are required.</p>
                    <a href="../cases/case-list.php?assign_case_id=${encodeURIComponent(stageCaseId || '')}#caseAssignments" class="btn-create" style="font-size: 0.82rem; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                        Assign 3-Member Team &rarr;
                    </a>
                </div>
            `;
        } else {
            let teamHtml = '';
            assignments.forEach((m) => {
                const role = m.assignment_role || 'Member';
                let roleColor = '#64748b';
                let roleBg = '#f1f5f9';
                if (role === 'Head') {
                    roleColor = '#15803d';
                    roleBg = '#dcfce7';
                } else if (role === 'Secretary') {
                    roleColor = '#1d4ed8';
                    roleBg = '#dbeafe';
                } else if (role === 'Member') {
                    roleColor = '#7e22ce';
                    roleBg = '#f3e8ff';
                }

                let displayName = m.member_name || 'Assigned Lupon Member';
                if (displayName.trim().toLowerCase() === 'admin user' || (role === 'Head' && displayName.trim().toLowerCase() === 'administrator')) {
                    displayName = 'Barangay Captain';
                }

                teamHtml += `
                    <div class="party-entry-card" style="margin-bottom: 8px;">
                        <div class="party-entry-top">
                            <span class="party-pill-tag" style="background: ${roleBg}; color: ${roleColor}; font-weight: 800;">
                                ${escapeHtml(role.toUpperCase())}
                            </span>
                        </div>
                        <div class="party-entry-name">${escapeHtml(displayName)}</div>
                        <div class="party-entry-meta">
                            <span>📅 Assigned: ${m.assigned_date ? escapeHtml(formatDateReadable(m.assigned_date)) : 'N/A'}</span>
                        </div>
                    </div>
                `;
            });
            teamContainer.innerHTML = teamHtml;
        }
    }

    // 4. Pangkat Conciliation State
    renderConciliationCard(stages, stageCaseId, complaint);

    // 5. Hearings & Deadlines Schedule
    const hearingsContainer = document.getElementById('cwHearingsContainer');
    const openHearingsLink = document.getElementById('cwOpenHearingsLink');
    if (openHearingsLink && stageCaseId) {
        openHearingsLink.href = `../hearings/schedules.php?case_id=${encodeURIComponent(stageCaseId)}`;
    }
    if (hearingsContainer) {
        if (!hearings.length) {
            hearingsContainer.innerHTML = `
                <div class="empty-detail-state" style="text-align: center; padding: 24px 16px; color: #64748b;">
                    <p style="margin: 0 0 10px; font-size: 0.88rem;">No hearings have been scheduled for this case yet.</p>
                    <button type="button" class="btn-create" onclick="handleIssueSummonClick()" style="font-size: 0.82rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 5px;">
                        Issue Summon 1st Attempt &amp; Schedule &rarr;
                    </button>
                </div>
            `;
        } else {
            let hearingsHtml = '<div style="display: flex; flex-direction: column; gap: 8px;">';
            hearings.forEach((h) => {
                const status = h.hearing_status || 'Scheduled';
                let statusBadgeStyle = 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;';
                if (status === 'Completed') statusBadgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;';
                else if (status === 'Rescheduled') statusBadgeStyle = 'background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff;';
                else if (status === 'Cancelled') statusBadgeStyle = 'background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;';

                const hearingDateFormatted = h.hearing_date ? formatDateTimeReadable(h.hearing_date) : 'Date Pending';
                const presentCount = Number(h.present_count || 0);
                const absentCount = Number(h.unjustified_absent_count || h.absent_count || 0);

                hearingsHtml += `
                    <div class="kp-form-item-row" style="margin-bottom: 0;">
                        <div class="kp-form-info-left">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="kp-form-name-text">${escapeHtml(h.hearing_type || 'Mediation Session')}</span>
                                <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 7px; border-radius: 4px; text-transform: uppercase; ${statusBadgeStyle}">
                                    ${escapeHtml(status)}
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>📅 <strong>${escapeHtml(hearingDateFormatted)}</strong></span> &bull; <span>📍 ${escapeHtml(h.venue || 'Barangay Hall')}</span>
                            </div>
                            ${(presentCount > 0 || absentCount > 0) ? `
                                <div style="display: flex; align-items: center; gap: 10px; font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                    <span style="color: #15803d; font-weight: 600;">✓ ${presentCount} Present</span>
                                    ${absentCount > 0 ? `<span style="color: #b91c1c; font-weight: 600;">✕ ${absentCount} Non-appearance</span>` : ''}
                                </div>
                            ` : ''}
                        </div>
                        <button type="button" class="btn-small-link" onclick="openHearingSessionDetails(${h.hearing_id})" style="cursor: pointer; background: #f8fafc; border: 1px solid #cbd5e1; font-family: inherit;">
                            View Session &rarr;
                        </button>
                    </div>
                `;
            });
            hearingsHtml += '</div>';
            hearingsContainer.innerHTML = hearingsHtml;
        }
    }

    // 6. Official KP Forms (both Overview Tab and Documents Tab)
    renderOfficialKpForms(workspace, complaint);

    // Call paanyaya, mediation and arbitration helpers to keep respective tab containers populated
    if (complaint || window.complaintData) {
        renderPaanyayaTab(complaint || window.complaintData);
    }
    renderMediationCard(stages, stageCaseId, complaint, workspace);
    renderArbitrationCard(stages, stageCaseId, complaint);
}

function renderOfficialKpForms(workspace, complaint) {
    const heroList = document.getElementById('heroKpFormsList');
    const docContainer = document.getElementById('cwDocumentsContainer');
    const heroBadge = document.getElementById('heroKpDocCountBadge');
    const tabDocsBadge = document.getElementById('tabDocsCountBadge');
    const cwDocCountBadge = document.getElementById('cwDocCountBadge');

    const activeComplaint = complaint || window.complaintData || {};
    const complaintId = activeComplaint.complaint_id || 0;
    const scanDoc = activeComplaint.current_scan || null;
    const hasScan = Boolean(scanDoc && scanDoc.file_path);
    const filedDate = activeComplaint.created_at ? formatDateReadable(activeComplaint.created_at) : 'N/A';

    const docs = workspace?.documents || [];
    const paanyayaStatuses = [
        'Pre-Docketed (Paanyaya)',
        '1st Paanyaya Failed (No-Show)',
        'Paanyaya In Progress',
        'Resolved at Paanyaya / Closed',
        'Paanyaya'
    ];
    const isPaanyaya = paanyayaStatuses.includes(activeComplaint.status || '') ||
                       Boolean(activeComplaint.resolved_without_docketing) ||
                       (typeof activeComplaint.status === 'string' && activeComplaint.status.toLowerCase().includes('paanyaya'));
    const hasPaanyayaDoc = isPaanyaya || Boolean(activeComplaint.paanyaya);

    const totalCount = docs.length + (complaintId > 0 ? (hasPaanyayaDoc ? 2 : 1) : 0);
    const countText = `${totalCount} Issued`;
    const formsCountText = `${totalCount} form${totalCount === 1 ? '' : 's'}`;

    if (heroBadge) heroBadge.textContent = countText;
    if (tabDocsBadge) tabDocsBadge.textContent = String(totalCount);
    if (cwDocCountBadge) cwDocCountBadge.textContent = formsCountText;

    let rowsHtml = '';

    // 0. Paanyaya (Informal Invitation Letter) - Visible during Pre-Docketing phase
    if (hasPaanyayaDoc && complaintId > 0) {
        const pSess = activeComplaint.paanyaya || {};
        const pDate = pSess.invitation_date ? formatDateReadable(pSess.invitation_date) : 'Scheduled';
        const pTime = pSess.invitation_time || '';
        const pStatus = pSess.status || activeComplaint.status || 'Active';
        rowsHtml += `
            <div class="kp-form-item-row" style="background: #f0fdf4; border-left: 4px solid #16a34a;">
                <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                    <span style="font-size: 1.5rem; color: #16a34a; line-height: 1;">✉️</span>
                    <div>
                        <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-weight: 700; color: #0f172a;">Paanyaya (Informal Invitation Letter)</span>
                            <span style="font-size: 0.72rem; padding: 1px 7px; border-radius: 4px; background: #dcfce7; color: #15803d; font-weight: 700;">PRE-DOCKETING</span>
                        </div>
                        <div class="kp-form-meta-text">
                            <span>Dialogue: ${escapeHtml(pDate)} ${escapeHtml(pTime)}</span>
                            <span>&bull; Status: ${escapeHtml(pStatus)}</span>
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn-small-link" onclick="openPaanyayaPreviewModal()" style="color: #16a34a; border-color: #bbf7d0; font-weight: 600;">
                        Preview / Print
                    </button>
                    <a href="../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(complaintId)}&download=1" class="btn-pdf-chip" download title="Download Paanyaya PDF" style="background: #16a34a; color: #ffffff;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <span>PDF</span>
                    </a>
                </div>
            </div>
        `;
    }

    // 1. Primary Complaint Form: KP Form 7 (Reklamo)
    if (complaintId > 0) {
        if (isPaanyaya) {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #f8fafc; border-left: 4px solid #f59e0b;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <span style="font-size: 1.5rem; color: #94a3b8; line-height: 1;">⚖️</span>
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #64748b;">Pormularyo ng KP Blg. 7 (Reklamo / Formal Complaint)</span>
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">
                                    KP Form 7: On Hold (Pending Paanyaya Outcome)
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Pre-Docketing Phase &bull; Form will only generate if formal escalation occurs</span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-small-link" disabled style="opacity: 0.55; cursor: not-allowed; color: #94a3b8; border-color: #cbd5e1;" title="KP Form 7 is on hold pending Paanyaya outcome.">
                            On Hold
                        </button>
                    </div>
                </div>
            `;
        } else {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #ffffff;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <span style="font-size: 1.5rem; color: #0284c7; line-height: 1;">⚖️</span>
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #0f172a;">Pormularyo ng KP Blg. 7 (Reklamo / Formal Complaint)</span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Filed: ${escapeHtml(filedDate)}</span>
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: ${hasScan ? '#dcfce7' : '#e0f2fe'}; color: ${hasScan ? '#15803d' : '#0369a1'};">
                                    ${hasScan ? `SCANNED COPY ATTACHED (v${escapeHtml(String(scanDoc.version_number || 1))})` : 'DIGITAL FORM ISSUED'}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-small-link" onclick="openKp7DigitalPreviewModal(${Number(complaintId)})" style="color: #0284c7; border-color: #bae6fd; font-weight: 600;">
                            Preview / Print
                        </button>
                        ${hasScan ? `
                            <a href="../../../backend/api/complaints/scanned-document.php?id=${encodeURIComponent(complaintId)}" target="_blank" class="btn-small-link" style="color: #15803d; border-color: #bbf7d0; text-decoration: none;" title="View scanned handwritten copy">
                                View Scan
                            </a>
                            <button type="button" class="btn-small-link" onclick="openScanHistoryModal(${Number(complaintId)})" title="View version history">
                                Version History
                            </button>
                        ` : `
                            <button type="button" class="btn-small-link" onclick="openUploadScanModal()" style="color: #64748b; border-color: #cbd5e1;">
                                + Upload Scan
                            </button>
                        `}
                    </div>
                </div>
            `;
        }
    }

    // 2. Subsequent Issued Documents (Summons, Notices, etc.)
    docs.forEach(doc => {
        const docStatus = doc.service_status || 'Issued';
        let statusBg = '#f1f5f9', statusColor = '#475569';
        if (docStatus === 'Served') {
            statusBg = '#dcfce7'; statusColor = '#15803d';
        } else if (docStatus === 'For Service' || docStatus === 'Pending') {
            statusBg = '#fef3c7'; statusColor = '#b45309';
        }

        const isSummonOrKp9 = doc.template_name?.includes('KP Form 9') || doc.template_name?.includes('Summon');
        const caseId = activeComplaint?.case_id || workspace?.case?.case_id || 0;

        rowsHtml += `
            <div class="kp-form-item-row">
                <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                    <span style="font-size: 1.5rem; color: #64748b; line-height: 1;">📄</span>
                    <div>
                        <div class="kp-form-name-text">
                            ${escapeHtml(doc.template_name || 'KP Form Document')}
                        </div>
                        <div class="kp-form-meta-text">
                            <span>Generated: ${doc.generated_at ? escapeHtml(formatDateReadable(doc.generated_at)) : 'N/A'}</span>
                            <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: ${statusBg}; color: ${statusColor};">
                                ${escapeHtml(docStatus.toUpperCase())}
                            </span>
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    ${isSummonOrKp9 ? `
                        <button type="button" class="btn-small-link" onclick="openSummonsPreviewModal(${Number(caseId)}, ${Number(doc.document_id)})" style="color: #4f46e5; border-color: #c7d2fe; font-weight: 600;">
                            Preview / Print
                        </button>
                    ` : ''}
                    ${doc.file_path ? `
                        <a href="../../../backend/api/documents/download.php?id=${encodeURIComponent(doc.document_id)}&download=1" class="btn-pdf-chip" download title="Download official PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            <span>PDF</span>
                        </a>
                    ` : `
                        <a href="../documents/document-center.php" class="btn-small-link">
                            View in Docs &rarr;
                        </a>
                    `}
                </div>
            </div>
        `;
    });

    if (heroList) heroList.innerHTML = rowsHtml;
    if (docContainer) docContainer.innerHTML = rowsHtml;
}

function populatePaanyayaOfficersSelect(officers) {
    if (!officers || !Array.isArray(officers)) return;
    const select1 = document.getElementById('issueStageOfficer');
    const select2 = document.getElementById('rorServingOfficer');
    const optionsHtml = '<option value="">-- Select Serving Officer --</option>' +
        officers.map(o => `<option value="${o.user_id}">${escapeHtml(o.full_name)} (${escapeHtml(o.role_name)})</option>`).join('');

    if (select1 && select1.options.length <= 1) {
        select1.innerHTML = optionsHtml;
    }
    if (select2 && select2.options.length <= 1) {
        select2.innerHTML = optionsHtml;
    }
}

function renderPaanyayaStageCard(stageNum, stageData, caseNum, compNames, respNames, respAddr) {
    const isP2 = stageNum === '2';
    const cardId = isP2 ? 'paanyaya2Card' : 'paanyaya1Card';
    const cardEl = document.getElementById(cardId);
    if (!cardEl) return;

    const details = stageData?.details || {};
    const attempts = stageData?.attempts || [];
    const count = stageData?.attempts_count || 0;
    const latest = stageData?.latest_attempt || null;
    const status = details.status || (isP2 && !stageData?.is_eligible ? 'Locked' : 'Not Started');

    if (isP2) {
        const lockedNotice = document.getElementById('p2LockedNotice');
        const metricGrid = document.getElementById('p2MetricGrid');
        const stepperWrap = document.getElementById('p2StepperWrap');
        const partiesBox = document.getElementById('p2PartiesBox');
        const btnIssue = document.getElementById('btnIssueP2');

        const stage1 = window.paanyayaStagesData?.stage_1 || {};
        const isP1Solved = (window.complaintData?.status === 'Settled') ||
                           (window.complaintData?.status === 'Resolved at Paanyaya / Closed') ||
                           (window.complaintData?.paanyaya_1_outcome === 'Settled') ||
                           (stage1.outcome === 'Settled') ||
                           (stage1.minutes?.outcome === 'Settled') ||
                           (stageData?.bypassed === true);

        const p2WasIssued = !!(details.issued_at) || (count > 0);

        // When the dispute is solved right from Paanyaya 1, hide Card 2 completely
        const p2Card = document.getElementById('paanyaya2Card');
        if (isP1Solved && !p2WasIssued) {
            if (p2Card) p2Card.style.display = 'none';
            return;
        } else if (p2Card) {
            p2Card.style.display = '';
        }

        const canIssueP2 = (stage1.minutes_recorded && 
                            ['No_Agreement', 'Party_Absent'].includes(stage1.outcome) && 
                            stage1.complainant_choice === 'Request_Paanyaya_2');

        const isP2Active = (details.status && details.status !== 'Not Started' && details.status !== 'Locked') && p2WasIssued;
        const isP2Unlocked = !isP1Solved && (stageData?.is_eligible || canIssueP2 || isP2Active);

        if (!isP2Unlocked) {
            if (lockedNotice) lockedNotice.style.display = 'flex';
            if (metricGrid) metricGrid.style.display = 'none';
            if (stepperWrap) stepperWrap.style.display = 'none';
            if (partiesBox) partiesBox.style.display = 'none';
            const badge = document.getElementById('p2StatusBadge');
            if (badge) {
                badge.textContent = 'Locked';
                badge.className = 'paanyaya-status-badge status-locked';
            }
            if (btnIssue) {
                btnIssue.style.display = 'none'; // Strictly hidden per spec
                btnIssue.disabled = true;
            }
            const btnRecord = document.getElementById('btnRecordReturnP2');
            const btnPreview = document.getElementById('btnPreviewP2');
            const btnReview = document.getElementById('btnReviewP2');
            if (btnRecord) btnRecord.style.display = 'none';
            if (btnPreview) btnPreview.style.display = 'none';
            if (btnReview) btnReview.style.display = 'none';
            const btnRecMin = document.getElementById('btnRecordMinutesP2');
            const btnViewMin = document.getElementById('btnViewMinutesP2');
            if (btnRecMin) btnRecMin.style.display = 'none';
            if (btnViewMin) btnViewMin.style.display = 'none';

            return;
        } else {
            if (lockedNotice) lockedNotice.style.display = 'none';
            if (metricGrid) metricGrid.style.display = 'grid';
            if (stepperWrap) stepperWrap.style.display = 'block';
            if (partiesBox) partiesBox.style.display = 'block';
        }
    }

    // Status Badge
    const badgeId = isP2 ? 'p2StatusBadge' : 'p1StatusBadge';
    const badge = document.getElementById(badgeId);
    if (badge) {
        badge.textContent = status;
        let badgeClass = 'status-not-started';
        if (status === 'In Progress') badgeClass = 'status-in-progress';
        else if (status === 'Successfully Delivered') badgeClass = 'status-delivered';
        else if (status === 'Attempts Exhausted – For Review') badgeClass = 'status-exhausted';
        else if (status === 'Completed') badgeClass = 'status-completed';
        else if (status === 'Locked') badgeClass = 'status-locked';
        badge.className = 'paanyaya-status-badge ' + badgeClass;
    }

    // Metric Grid
    const attemptTextId = isP2 ? 'p2AttemptText' : 'p1AttemptText';
    const serviceStatusId = isP2 ? 'p2ServiceStatus' : 'p1ServiceStatus';
    const officerId = isP2 ? 'p2ServingOfficer' : 'p1ServingOfficer';
    const dateInitId = isP2 ? 'p2DateInitiated' : 'p1DateInitiated';

    setText(attemptTextId, `${count} of 6 used`);
    const currentServiceOutcome = latest ? latest.delivery_outcome : (status === 'Not Started' ? 'Not Initiated' : 'Pending Service');
    setText(serviceStatusId, currentServiceOutcome);
    setText(officerId, details.assigned_officer_name || 'Unassigned');
    setText(dateInitId, details.invitation_date ? formatDateReadable(details.invitation_date) : (details.created_at ? formatDateReadable(details.created_at) : '—'));

    // Stepper Count & Dots
    const stepperCountId = isP2 ? 'p2StepperCountText' : 'p1StepperCountText';
    const stepperDotsId = isP2 ? 'p2StepperDots' : 'p1StepperDots';
    setText(stepperCountId, `${count} / 6 Attempts`);

    const stepperDots = document.getElementById(stepperDotsId);
    if (stepperDots) {
        const dots = stepperDots.querySelectorAll('.stepper-dot');
        dots.forEach((dot, idx) => {
            dot.className = 'stepper-dot';
            if (idx < count) {
                const att = attempts[idx];
                if (att && att.delivery_outcome === 'Successfully delivered') {
                    dot.classList.add('dot-served');
                    dot.title = `Attempt ${idx + 1}: Delivered (${att.service_datetime || ''})`;
                } else {
                    dot.classList.add('dot-unserved');
                    dot.title = `Attempt ${idx + 1}: Unsuccessful - ${att?.delivery_outcome || 'Unserved'}`;
                }
            } else if (idx === count && stageData?.can_record_attempt) {
                dot.classList.add('dot-current');
                dot.title = `Attempt ${idx + 1}: Ready to Record`;
            } else {
                dot.title = `Attempt ${idx + 1}: Not recorded`;
            }
        });
    }

    // Parties & Info Box
    const caseNumId = isP2 ? 'p2CaseNumber' : 'p1CaseNumber';
    const compNameId = isP2 ? 'p2ComplainantName' : 'p1ComplainantName';
    const respNameId = isP2 ? 'p2RespondentName' : 'p1RespondentName';
    const respAddrId = isP2 ? 'p2RespondentAddr' : 'p1RespondentAddr';

    setText(caseNumId, caseNum || '—');
    setText(compNameId, compNames || '—');
    setText(respNameId, respNames || '—');
    setText(respAddrId, respAddr || 'Barangay Tumana');

    // Latest Outcome Row
    const latestRowId = isP2 ? 'p2LatestOutcomeRow' : 'p1LatestOutcomeRow';
    const latestBadgeId = isP2 ? 'p2LatestOutcomeBadge' : 'p1LatestOutcomeBadge';
    const latestRemarksId = isP2 ? 'p2LatestRemarks' : 'p1LatestRemarks';
    const latestRow = document.getElementById(latestRowId);
    const latestBadge = document.getElementById(latestBadgeId);
    const latestRemarks = document.getElementById(latestRemarksId);

    if (latestRow && latestBadge && latestRemarks) {
        if (latest) {
            latestRow.style.display = 'block';
            let outClass = 'outcome-other';
            if (latest.delivery_outcome === 'Successfully delivered') outClass = 'outcome-delivered';
            else if (latest.delivery_outcome === 'Recipient unavailable') outClass = 'outcome-unavailable';
            else if (latest.delivery_outcome === 'Recipient refused to receive') outClass = 'outcome-refused';
            else if (latest.delivery_outcome === 'Respondent not found at the address') outClass = 'outcome-not-found';
            else if (latest.delivery_outcome === 'Incorrect or incomplete address') outClass = 'outcome-incorrect-addr';

            latestBadge.innerHTML = `<span class="outcome-badge ${outClass}">${escapeHtml(latest.delivery_outcome)}</span>`;
            const remText = latest.remarks || latest.unsuccessful_reason || '';
            latestRemarks.textContent = remText ? `“${remText}”` : '';
        } else {
            latestRow.style.display = 'none';
        }
    }

    // Action Buttons
    const btnIssueId = isP2 ? 'btnIssueP2' : 'btnIssueP1';
    const btnRecordId = isP2 ? 'btnRecordReturnP2' : 'btnRecordReturnP1';
    const btnPreviewId = isP2 ? 'btnPreviewP2' : 'btnPreviewP1';
    const btnReviewId = isP2 ? 'btnReviewP2' : 'btnReviewP1';
    const btnIssue = document.getElementById(btnIssueId);
    const btnRecord = document.getElementById(btnRecordId);
    const btnPreview = document.getElementById(btnPreviewId);
    const btnReview = document.getElementById(btnReviewId);

    if (btnIssue) {
        btnIssue.style.display = stageData?.can_issue ? 'inline-flex' : 'none';
        btnIssue.disabled = false;
        btnIssue.style.opacity = '';
        btnIssue.style.cursor = '';
    }
    if (btnRecord) {
        btnRecord.style.display = stageData?.can_record_attempt ? 'inline-flex' : 'none';
    }
    if (btnPreview) {
        btnPreview.style.display = (details.status && details.status !== 'Not Started') ? 'inline-flex' : 'none';
    }
    if (btnReview) {
        btnReview.style.display = stageData?.needs_review ? 'inline-flex' : 'none';
    }

    const isDelivered = !!stageData?.is_delivered || (details.status === 'Successfully Delivered');
    const isClosedOrDocketed = ['Settled', 'Closed', 'Resolved at Paanyaya / Closed'].includes(window.complaintData?.status) || !!window.complaintData?.case_id;
    const p2Started = isP2 ? false : (window.paanyayaStagesData?.stage_2?.details?.status && window.paanyayaStagesData?.stage_2?.details?.status !== 'Not Started');

    // Dialogue Minutes Bindings
    const btnRecordMinutesId = isP2 ? 'btnRecordMinutesP2' : 'btnRecordMinutesP1';
    const btnViewMinutesId = isP2 ? 'btnViewMinutesP2' : 'btnViewMinutesP1';
    const minutesRowId = isP2 ? 'p2MinutesRow' : 'p1MinutesRow';
    const minutesBadgeId = isP2 ? 'p2MinutesBadge' : 'p1MinutesBadge';
    const minutesSummaryId = isP2 ? 'p2MinutesSummary' : 'p1MinutesSummary';

    const btnRecordMinutes = document.getElementById(btnRecordMinutesId);
    const btnViewMinutes = document.getElementById(btnViewMinutesId);
    const minutesRow = document.getElementById(minutesRowId);
    const minutesBadge = document.getElementById(minutesBadgeId);
    const minutesSummary = document.getElementById(minutesSummaryId);

    const hasMinutes = !!stageData?.minutes_recorded;
    const minutesData = stageData?.minutes || null;

    if (minutesRow && minutesBadge && minutesSummary) {
        if (hasMinutes && minutesData) {
            minutesRow.style.display = 'block';
            let bText = 'Minutes Recorded';
            let bClass = 'outcome-delivered';
            if (minutesData.outcome === 'Settled') {
                bText = '✓ Settled Informally';
                bClass = 'outcome-delivered';
            } else if (minutesData.complainant_choice === 'Request_Paanyaya_2') {
                bText = '⚡ 2nd Paanyaya Requested';
                bClass = 'outcome-unavailable';
            } else if (minutesData.complainant_choice === 'Escalate_Mediation') {
                bText = '⚡ Escalated to Mediation';
                bClass = 'outcome-refused';
            }
            minutesBadge.innerHTML = `<span class="outcome-badge ${bClass}">${bText}</span>`;
            const notePreview = minutesData.agreed_terms || minutesData.discussion_summary || '';
            minutesSummary.textContent = notePreview ? `“${notePreview.substring(0, 90)}${notePreview.length > 90 ? '...' : ''}”` : '';
        } else {
            minutesRow.style.display = 'none';
        }
    }

    if (btnRecordMinutes) {
        // Show prominent Record Minutes button once Delivered and not yet recorded
        btnRecordMinutes.style.display = (isDelivered && !hasMinutes && !isClosedOrDocketed && !p2Started) ? 'inline-flex' : 'none';
    }
    if (btnViewMinutes) {
        btnViewMinutes.style.display = hasMinutes ? 'inline-flex' : 'none';
    }


}

function renderPaanyayaTab(data) {
    if (!data) return;
    const stages = data.paanyaya_stages || {};
    window.paanyayaStagesData = stages;

    const stage1 = stages.stage_1 || {};
    const stage2 = stages.stage_2 || {};
    const servingOfficers = stages.serving_officers || [];

    const esc = (s) => String(s ?? '').replace(/[&<>'"]/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[c] || c));

    // Populate Serving Officers dropdown in modals
    populatePaanyayaOfficersSelect(servingOfficers);

    // 1. Stage 0 Banner Text and Badges
    const stage0Badge = document.getElementById('stage0StatusText');
    const stage0Icon = document.getElementById('stage0Icon');
    if (stage0Badge) {
        if (data.status === 'Settled' || data.status === 'Resolved at Paanyaya / Closed' || stage1.outcome === 'Settled' || stage1.minutes?.outcome === 'Settled') {
            stage0Badge.textContent = 'Resolved at Paanyaya (Informal Settlement)';
            stage0Badge.style.background = '#dcfce7';
            stage0Badge.style.color = '#15803d';
            if (stage0Icon) stage0Icon.textContent = '✓';
        } else if (stage2.is_delivered || stage1.is_delivered) {
            stage0Badge.textContent = 'Successfully Delivered';
            stage0Badge.style.background = '#dcfce7';
            stage0Badge.style.color = '#15803d';
            if (stage0Icon) stage0Icon.textContent = '✓';
        } else if (stage1.details?.status === 'Attempts Exhausted – For Review' || stage2.details?.status === 'Attempts Exhausted – For Review') {
            stage0Badge.textContent = 'Attempts Exhausted – For Review';
            stage0Badge.style.background = '#fee2e2';
            stage0Badge.style.color = '#991b1b';
            if (stage0Icon) stage0Icon.textContent = '⚠️';
        } else if (stage1.details?.status === 'In Progress' || stage2.details?.status === 'In Progress') {
            stage0Badge.textContent = 'Paanyaya In Progress';
            stage0Badge.style.background = '#fef08a';
            stage0Badge.style.color = '#854d0e';
            if (stage0Icon) stage0Icon.textContent = '✉️';
        } else {
            stage0Badge.textContent = 'Paanyaya Preliminary Stage';
            stage0Badge.style.background = '#fef08a';
            stage0Badge.style.color = '#854d0e';
            if (stage0Icon) stage0Icon.textContent = '✉️';
        }
    }

    // 2. Nagpaanyaya (Complainants) & Inanyayahan (Respondents)
    const complainants = data.parties ? data.parties.filter(x => x.party_type === 'Complainant') : [];
    const respondents = data.parties ? data.parties.filter(x => x.party_type === 'Respondent') : [];

    const compBadge = document.getElementById('tabPaaComplainantsCountBadge');
    if (compBadge) compBadge.textContent = `${complainants.length} Party(ies)`;
    const respBadge = document.getElementById('tabPaaRespondentsCountBadge');
    if (respBadge) respBadge.textContent = `${respondents.length} Party(ies)`;

    const renderPartyRows = (parties) => {
        if (!parties || parties.length === 0) {
            return '<p style="color: #64748b; font-size: 0.85rem; margin: 0; padding: 6px 0;">No party recorded on file.</p>';
        }
        return parties.map(pt => {
            const name = pt.full_name || `${pt.first_name || ''} ${pt.last_name || ''}`.trim() || 'Unknown';
            const addr = pt.address || pt.purok || 'Barangay Tumana';
            const phone = pt.contact_no || pt.phone || '';
            return `
                <div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px dashed #e2e8f0;">
                    <div style="font-weight: 700; color: #0f172a; font-size: 0.92rem;">${esc(name)}</div>
                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">
                        📍 ${esc(addr)}
                        ${phone ? ` &bull; 📞 ${esc(phone)}` : ''}
                    </div>
                </div>
            `;
        }).join('');
    };

    const compList = document.getElementById('tabPaaComplainantsList');
    if (compList) compList.innerHTML = renderPartyRows(complainants);
    const respList = document.getElementById('tabPaaRespondentsList');
    if (respList) respList.innerHTML = renderPartyRows(respondents);

    const compNames = complainants.map(c => c.full_name || `${c.first_name || ''} ${c.last_name || ''}`.trim()).filter(Boolean).join(', ') || 'Complainant';
    const respNames = respondents.map(r => r.full_name || `${r.first_name || ''} ${r.last_name || ''}`.trim()).filter(Boolean).join(', ') || 'Respondent';
    const respAddr = respondents[0]?.address || respondents[0]?.purok || 'Barangay Tumana';

    const isP1Solved = (data.status === 'Settled') ||
                       (data.status === 'Resolved at Paanyaya / Closed') ||
                       (data.paanyaya_1_outcome === 'Settled') ||
                       (stage1.outcome === 'Settled') ||
                       (stage1.minutes?.outcome === 'Settled') ||
                       (stage2.bypassed === true);

    const p2WasIssued = !!(stage2.details?.issued_at) || ((stage2.attempts_count || 0) > 0);

    const p2Card = document.getElementById('paanyaya2Card');
    if (p2Card) {
        p2Card.style.display = (isP1Solved && !p2WasIssued) ? 'none' : '';
    }

    const tab2Btn = document.getElementById('btnHistTabP2');
    if (tab2Btn) {
        tab2Btn.style.display = (isP1Solved && !p2WasIssued) ? 'none' : '';
    }

    // 3. Render CARD 1: PAANYAYA 1
    renderPaanyayaStageCard('1', stage1, data.complaint_number, compNames, respNames, respAddr);

    // 4. Render CARD 2: PAANYAYA 2 (only if not solved at Paanyaya 1)
    if (!isP1Solved || p2WasIssued) {
        renderPaanyayaStageCard('2', stage2, data.complaint_number, compNames, respNames, respAddr);
    }

    // 5. Render DELIVERY HISTORY TABLE
    if (!window.activePaanyayaHistoryStage) {
        window.activePaanyayaHistoryStage = 'Paanyaya 1';
    }
    renderPaanyayaDeliveryHistoryTable(window.activePaanyayaHistoryStage);
}

function renderMediationCard(stages, caseId, complaint, workspace) {
    const med = stages?.mediation || {};
    const badgeEl = document.getElementById('cwMediationStatusBadge');
    const linkEl = document.getElementById('cwManageMediationLink');
    const container = document.getElementById('cwMediationContainer');

    const status = med.stage_status || 'Not Started';
    if (badgeEl) {
        badgeEl.textContent = status;
        let bg = '#f1f5f9', color = '#475569';
        if (status === 'Completed' || status === 'Settled') { bg = '#dcfce7'; color = '#15803d'; }
        else if (status === 'In Progress') { bg = '#dbeafe'; color = '#1d4ed8'; }
        else if (status === 'Awaiting Outcome' || status === 'Ready for Referral') { bg = '#fef3c7'; color = '#b45309'; }
        else if (status === 'Referred to Pangkat') { bg = '#e0f2fe'; color = '#0369a1'; }
        badgeEl.style.background = bg;
        badgeEl.style.color = color;
    }

    if (linkEl && caseId) {
        linkEl.href = `../cases/mediation-workspace.php?case_id=${encodeURIComponent(caseId)}&complaint_id=${encodeURIComponent(complaint.complaint_id || '')}`;
    }

    if (!container) return;

    const cAtt = med.attendance_summary?.complainant || { present: 0, absent: 0, excused: 0, pending: 0 };
    const rAtt = med.attendance_summary?.respondent || { present: 0, absent: 0, excused: 0, pending: 0 };
    const nextH = med.next_hearing;
    const actions = med.documents_and_actions || {};

    const renderAction = (label, url, enabled, disabledReason) => {
        if (enabled) {
            return `<a href="${url}" class="btn-secondary" style="font-size: 0.74rem; padding: 4px 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border-color: #cbd5e1; background: #fff;">${label} &rarr;</a>`;
        }
        return `<span title="${escapeHtml(disabledReason)}" style="font-size: 0.74rem; padding: 4px 8px; border-radius: 4px; background: #f8fafc; color: #94a3b8; border: 1px dashed #e2e8f0; cursor: not-allowed; display: inline-flex; align-items: center; gap: 4px;">${label} 🔒</span>`;
    };

    container.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <div style="padding: 10px 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.78rem; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.03em;">
                        📅 Hearings &amp; Attendance
                    </span>
                    <a href="../hearings/schedules.php?case_id=${encodeURIComponent(caseId || '')}&type=Mediation" class="btn-secondary" style="font-size: 0.72rem; padding: 2px 7px; text-decoration: none;">
                        View Hearings &rarr;
                    </a>
                </div>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 6px; font-size: 0.78rem;">
                    <div style="background: #f8fafc; padding: 5px 8px; border-radius: 6px;">
                        <span style="color: #64748b; font-size: 0.7rem; display: block;">Sessions</span>
                        <strong style="color: #0f172a; font-size: 0.9rem;">${Number(med.sessions_count || 0)}</strong>
                    </div>
                    <div style="background: #f8fafc; padding: 5px 8px; border-radius: 6px;">
                        <span style="color: #64748b; font-size: 0.7rem; display: block;">Next Session</span>
                        <strong style="color: #0f172a; font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">${nextH ? escapeHtml(formatDateReadable(nextH.hearing_date)) : 'None'}</strong>
                    </div>
                    <div style="background: #f8fafc; padding: 5px 8px; border-radius: 6px;">
                        <span style="color: #64748b; font-size: 0.7rem; display: block;">Latest Date</span>
                        <strong style="color: #0f172a; font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">${med.latest_hearing_date ? escapeHtml(formatDateReadable(med.latest_hearing_date)) : '—'}</strong>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function renderConciliationCard(stages, caseId, complaint) {
    const con = stages?.conciliation || {};
    const badgeEl = document.getElementById('cwConciliationStatusBadge');
    const linkEl = document.getElementById('cwManageConciliationLink');
    const container = document.getElementById('cwConciliationContainer');

    const status = con.stage_status || (con.is_locked ? 'Locked' : 'Not Started');
    const isLocked = Boolean(con.is_locked);

    if (badgeEl) {
        badgeEl.textContent = status;
        let bg = '#f1f5f9', color = '#64748b';
        if (status === 'Completed' || status === 'Settled') { bg = '#dcfce7'; color = '#15803d'; }
        else if (status === 'In Progress') { bg = '#dbeafe'; color = '#1d4ed8'; }
        else if (status === 'Awaiting Outcome' || status === 'Ready for Referral') { bg = '#fef3c7'; color = '#b45309'; }
        else if (status === 'Locked') { bg = '#f1f5f9'; color = '#64748b'; }
        badgeEl.style.background = bg;
        badgeEl.style.color = color;
    }

    if (linkEl) {
        if (isLocked) {
            linkEl.href = '#';
            linkEl.onclick = (e) => {
                e.preventDefault();
                alert('Pangkat Conciliation is locked. Complete the mediation stage and record an authorized referral to unlock this workspace.');
            };
            linkEl.style.opacity = '0.6';
            linkEl.style.cursor = 'not-allowed';
            linkEl.innerHTML = 'Manage Conciliation 🔒';
        } else {
            linkEl.href = `../cases/pangkat-workspace.php?case_id=${encodeURIComponent(caseId || '')}&complaint_id=${encodeURIComponent(complaint.complaint_id || '')}`;
            linkEl.onclick = null;
            linkEl.style.opacity = '1';
            linkEl.style.cursor = 'pointer';
            linkEl.innerHTML = 'Manage Conciliation &rarr;';
        }
    }

    if (!container) return;

    if (isLocked) {
        const canRefer = stages?.mediation?.documents_and_actions?.can_proceed_to_pangkat;
        container.innerHTML = `
            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 24px 16px; text-align: center; color: #64748b;">
                <div style="font-size: 1.8rem; margin-bottom: 6px;">🔒</div>
                <div style="font-size: 0.92rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
                    Pangkat Conciliation is locked
                </div>
                <p style="margin: 0; font-size: 0.82rem; color: #64748b; line-height: 1.5; max-width: 440px; margin: 0 auto;">
                    Complete the mediation stage and record an authorized referral to unlock this workspace.
                </p>
                ${canRefer ? `
                    <div style="margin-top: 12px;">
                        <a href="../cases/mediation-workspace.php?case_id=${encodeURIComponent(caseId || '')}&action=referral" class="btn-create" style="font-size: 0.78rem; padding: 5px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                            Proceed with Referral in Mediation &rarr;
                        </a>
                    </div>
                ` : ''}
            </div>
        `;
        return;
    }

    const pInfo = con.pangkat_assignment || {};
    container.innerHTML = `
        <div style="font-size: 0.84rem; color: #334155; display: flex; flex-direction: column; gap: 6px;">
            <div><strong>Chairperson:</strong> ${escapeHtml(pInfo.chairperson?.member_name || 'Not assigned')}</div>
            <div><strong>Secretary:</strong> ${escapeHtml(pInfo.secretary?.member_name || 'Not assigned')}</div>
            <div><strong>Member:</strong> ${escapeHtml(pInfo.member?.member_name || 'Not assigned')}</div>
            <div style="margin-top: 6px; font-size: 0.78rem; color: #64748b;">
                <span>📅 Constituted: ${pInfo.formation_date ? escapeHtml(formatDateReadable(pInfo.formation_date)) : 'N/A'}</span>
                &bull; <span>Status: <strong>${escapeHtml(pInfo.assignment_status || 'Pending')}</strong></span>
            </div>
            <div style="margin-top: 10px;">
                <a href="../cases/pangkat-workspace.php?case_id=${encodeURIComponent(caseId || '')}&complaint_id=${encodeURIComponent(complaint.complaint_id || '')}" class="btn-small-link">
                    Open Conciliation Workspace &rarr;
                </a>
            </div>
        </div>
    `;
}

function renderArbitrationCard(stages, caseId, complaint) {
    const arb = stages?.arbitration || {};
    const badgeEl = document.getElementById('cwArbitrationStatusBadge');
    const linkEl = document.getElementById('cwManageArbitrationLink');
    const container = document.getElementById('cwArbitrationContainer');

    const status = arb.stage_status || (arb.is_locked ? 'Pending' : 'Not Started');
    const isLocked = Boolean(arb.is_locked !== false && (status === 'Locked' || status === 'Pending'));

    if (badgeEl) {
        badgeEl.textContent = status;
        let bg = '#f1f5f9', color = '#64748b';
        if (status === 'Completed' || status === 'Award Rendered' || status === 'Settled') { bg = '#dcfce7'; color = '#15803d'; }
        else if (status === 'In Progress') { bg = '#fae8ff'; color = '#86198f'; }
        else if (status === 'CFA Issued' || status === 'Certified for Court') { bg = '#fee2e2'; color = '#b91c1c'; }
        else if (status === 'Pending' || status === 'Locked') { bg = '#f8fafc'; color = '#64748b'; }
        badgeEl.style.background = bg;
        badgeEl.style.color = color;
    }

    if (linkEl && caseId) {
        linkEl.href = `../cases/arbitration-workspace.php?case_id=${encodeURIComponent(caseId)}&complaint_id=${encodeURIComponent(complaint.complaint_id || '')}`;
    }

    if (!container) return;

    if (isLocked) {
        container.innerHTML = `
            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 24px 16px; text-align: center; color: #64748b;">
                <div style="font-size: 1.8rem; margin-bottom: 6px;">⚖️</div>
                <div style="font-size: 0.92rem; font-weight: 700; color: #334155; margin-bottom: 4px;">
                    Arbitration / CFA is not active
                </div>
                <p style="margin: 0; font-size: 0.82rem; color: #64748b; line-height: 1.5; max-width: 480px; margin: 0 auto;">
                    Parties may enter binding arbitration at any stage by executing <strong>KP Form 14 (Agreement for Arbitration)</strong>, or obtain a <strong>Certificate to File Action (KP Form 20/21/22)</strong> upon failure to settle.
                </p>
                <div style="margin-top: 12px;">
                    <a href="../cases/arbitration-workspace.php?case_id=${encodeURIComponent(caseId || '')}&complaint_id=${encodeURIComponent(complaint.complaint_id || '')}" class="btn-create" style="font-size: 0.78rem; padding: 5px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                        Open Arbitration / CFA Workspace &rarr;
                    </a>
                </div>
            </div>
        `;
        return;
    }

    const sessCount = Number(arb.sessions_count || 0);
    const nextH = arb.next_hearing;
    const outcome = arb.outcome || 'Pending Determination';

    container.innerHTML = `
        <div style="font-size: 0.84rem; color: #334155; display: flex; flex-direction: column; gap: 8px;">
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 6px;">
                <div style="background: #f8fafc; padding: 8px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <span style="font-size: 0.72rem; color: #64748b; display: block;">Arbitration Sessions</span>
                    <strong style="font-size: 1rem; color: #0f172a;">${sessCount}</strong>
                </div>
                <div style="background: #f8fafc; padding: 8px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <span style="font-size: 0.72rem; color: #64748b; display: block;">Next Hearing</span>
                    <strong style="font-size: 0.84rem; color: #0f172a;">${nextH ? escapeHtml(formatDateReadable(nextH.hearing_date)) : 'None scheduled'}</strong>
                </div>
                <div style="background: #f8fafc; padding: 8px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <span style="font-size: 0.72rem; color: #64748b; display: block;">Arbitration Outcome</span>
                    <strong style="font-size: 0.84rem; color: #0f172a;">${escapeHtml(outcome)}</strong>
                </div>
            </div>
            <div style="margin-top: 6px;">
                <a href="../cases/arbitration-workspace.php?case_id=${encodeURIComponent(caseId || '')}&complaint_id=${encodeURIComponent(complaint.complaint_id || '')}" class="btn-small-link">
                    Open Arbitration Workspace &rarr;
                </a>
            </div>
        </div>
    `;
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

function calculateBusinessDays(startDate, daysToAdd = 15, holidayList = null) {
    const fixedHolidays = holidayList || [
        '01-01', '04-09', '05-01', '06-12', '08-21', '11-01', '11-02', '11-30', '12-08', '12-25', '12-30', '12-31'
    ];
    let cur = new Date(startDate);
    cur.setHours(0, 0, 0, 0);
    let added = 0;
    while (added < daysToAdd) {
        cur.setDate(cur.getDate() + 1);
        const day = cur.getDay(); // 0 = Sun, 6 = Sat
        if (day === 0 || day === 6) continue;
        const mm = String(cur.getMonth() + 1).padStart(2, '0');
        const dd = String(cur.getDate()).padStart(2, '0');
        if (fixedHolidays.includes(`${mm}-${dd}`)) continue;
        added++;
    }
    const yyyy = cur.getFullYear();
    const mm = String(cur.getMonth() + 1).padStart(2, '0');
    const dd = String(cur.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}
window.calculateBusinessDays = calculateBusinessDays;

let conflictCheckDebounceTimer = null;

function renderSummonVisualDatePicker(windowMeta, selectedDate) {
    const gridEl = document.getElementById('summonVisualDayGrid');
    const noticeEl = document.getElementById('summonVisualDateStatusNotice');
    const badgeEl = document.getElementById('summonWindowStatutoryBadge');
    if (!gridEl || !windowMeta || !windowMeta.daily_slots) return;

    if (badgeEl && windowMeta.deadline_date) {
        const dObj = new Date(windowMeta.deadline_date + 'T00:00:00');
        const formatted = dObj.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
        badgeEl.textContent = `15-Working-Day Window (Deadline: ${formatted})`;
    }

    const days = Object.values(windowMeta.daily_slots);
    if (!days.length) {
        gridEl.innerHTML = '<div style="color: #64748b; font-size: 0.78rem; grid-column: 1 / -1; text-align: center;">No date slots available in window.</div>';
        return;
    }

    gridEl.innerHTML = days.map(d => {
        const isSelected = (d.date === selectedDate);
        let badgeText = `${d.available} open`;
        let badgeBg = '#dcfce7';
        let badgeColor = '#15803d';
        let cardStyle = 'border: 1px solid #cbd5e1; background: #ffffff; color: #1e293b; cursor: pointer;';
        let disabledAttr = '';
        let tooltip = `${d.date}: ${d.available} of ${d.max} slots available.`;

        if (d.is_past) {
            badgeText = 'Past';
            badgeBg = '#f1f5f9';
            badgeColor = '#94a3b8';
            cardStyle = 'border: 1px solid #e2e8f0; background: #f8fafc; color: #94a3b8; cursor: not-allowed; opacity: 0.5;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Past date cannot be scheduled.`;
        } else if (d.is_outside_window) {
            badgeText = '>15 Days';
            badgeBg = '#fee2e2';
            badgeColor = '#991b1b';
            cardStyle = 'border: 1px dashed #fca5a5; background: #fff5f5; color: #991b1b; cursor: not-allowed; opacity: 0.55;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Exceeds 15-working-day statutory window under RA 7160 Sec. 410.`;
        } else if (d.is_fully_booked) {
            badgeText = '8/8 FULL';
            badgeBg = '#fee2e2';
            badgeColor = '#b91c1c';
            cardStyle = 'border: 1px solid #fecaca; background: #fff1f2; color: #881337; cursor: not-allowed; opacity: 0.65;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Fully booked! Maximum 8 mediation sessions reached for this date.`;
        } else if (d.is_weekend || d.is_non_working_day) {
            badgeText = d.is_non_working_day ? 'Holiday' : 'Weekend';
            badgeBg = '#f1f5f9';
            badgeColor = '#64748b';
            cardStyle = 'border: 1px dashed #cbd5e1; background: #f8fafc; color: #64748b; cursor: pointer;';
            tooltip = `${d.date}: ${d.is_non_working_day ? 'Official holiday' : 'Weekend'}. Allowed for emergency scheduling, but standard working days preferred.`;
        }

        if (isSelected) {
            cardStyle = 'border: 2px solid #2563eb; background: #eff6ff; color: #1e3a8a; box-shadow: 0 0 0 2px rgba(37,99,235,0.25); font-weight: 700; cursor: pointer;';
            badgeBg = '#2563eb';
            badgeColor = '#ffffff';
        }

        return `
            <div class="summon-day-card ${isSelected ? 'active' : ''}" 
                 onclick="${disabledAttr ? '' : `selectSummonVisualDate('${d.date}')`}"
                 title="${escapeHtml(tooltip)}"
                 style="border-radius: 6px; padding: 6px 4px; text-align: center; transition: all 0.15s; user-select: none; ${cardStyle}">
                <div style="font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em;">
                    ${escapeHtml(d.day_name)}
                </div>
                <div style="font-size: 0.85rem; font-weight: 800; margin: 2px 0;">
                    ${d.day_num} ${escapeHtml(d.month_name)}
                </div>
                <div style="font-size: 0.65rem; font-weight: 700; padding: 1px 4px; border-radius: 4px; background: ${badgeBg}; color: ${badgeColor}; display: inline-block;">
                    ${badgeText}
                </div>
            </div>
        `;
    }).join('');

    if (noticeEl) {
        if (selectedDate) {
            const selectedDayData = windowMeta.daily_slots[selectedDate];
            if (selectedDayData) {
                if (selectedDayData.is_outside_window) {
                    noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">⚠️ Selected date exceeds the 15-working-day statutory limit (Deadline: ${windowMeta.deadline_date}).</span>`;
                } else if (selectedDayData.is_fully_booked) {
                    noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">⚠️ Selected date is fully booked (8 of 8 daily mediation sessions).</span>`;
                } else {
                    noticeEl.innerHTML = `<span style="color: #15803d; font-weight: 600;">✓ Valid statutory mediation date selected: ${selectedDayData.available} slots remaining.</span>`;
                }
            }
        } else {
            noticeEl.textContent = `Click any available day above to select. Days exceeding 8 hearings or past 15 working days are grayed out.`;
        }
    }
}
window.renderSummonVisualDatePicker = renderSummonVisualDatePicker;

function selectSummonVisualDate(dateStr) {
    const dateInput = document.getElementById('summonMediationDate');
    if (!dateInput) return;
    dateInput.value = dateStr;
    checkMediationScheduleConflict();
}
window.selectSummonVisualDate = selectSummonVisualDate;

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

    if (!dateInput) return;

    const dateVal = dateInput.value;
    const timeVal = timeInput ? timeInput.value : '';
    const venueVal = venueInput ? venueInput.value : 'Lupon Court Room';
    const complaintIdVal = complaintInput ? complaintInput.value : '';

    const params = new URLSearchParams();
    if (dateVal) params.set('date', dateVal);
    if (timeVal) params.set('time', timeVal);
    if (venueVal) params.set('venue', venueVal);
    if (complaintIdVal) params.set('complaint_id', complaintIdVal);
    params.set('get_window', '1');

    try {
        const response = await fetch(`../../../backend/api/summons/check-schedule.php?${params.toString()}`);
        const result = await response.json();

        if (result.window) {
            window.currentSummonWindowMeta = result.window;
            renderSummonVisualDatePicker(result.window, dateVal);
        }

        if (!response.ok || !result.success) {
            if (conflictAlert && result.message) {
                conflictAlert.textContent = `⚠️ Notice: ${result.message}`;
                conflictAlert.style.display = 'block';
                if (submitBtn) submitBtn.disabled = true;
            }
            return;
        }

        // 1. Statutory 15-working-day window validation check
        if (result.is_outside_window) {
            if (conflictAlert) {
                conflictAlert.textContent = `⚠️ Statutory Limit Exceeded: ${result.deadline_message || 'The selected date exceeds the 15-working-day mediation statutory limit.'}`;
                conflictAlert.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = 'Cannot submit: Selected mediation date falls outside the 15-working-day statutory window.';
            }
            return;
        }

        // 2. Capacity limit check: Max 8 sessions per day
        const bookedCount = result.total_booked ?? (result.scheduled_mediations ? result.scheduled_mediations.length : 0);
        const maxSlots = result.max_slots || 8;
        const availSlots = result.available_slots ?? Math.max(0, maxSlots - bookedCount);

        if (result.is_fully_booked || bookedCount >= maxSlots) {
            if (conflictAlert) {
                conflictAlert.textContent = `⚠️ Date Fully Booked: All ${maxSlots} daily mediation slots on this date are occupied. Please select another date.`;
                conflictAlert.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = 'Cannot submit: Maximum daily capacity of 8 mediation sessions reached for this date.';
            }
            return;
        }

        // 3. Render day's capacity & scheduled mediations breakdown
        if (schedulesWrap && slotsList && slotsCount) {
            schedulesWrap.style.display = 'block';
            slotsCount.textContent = `${availSlots} of ${maxSlots} slots available`;
            slotsCount.style.background = availSlots > 2 ? '#dcfce7' : (availSlots > 0 ? '#fef3c7' : '#fee2e2');
            slotsCount.style.color = availSlots > 2 ? '#15803d' : (availSlots > 0 ? '#92400e' : '#b91c1c');

            const scheduled = result.scheduled_mediations || [];
            if (scheduled.length === 0) {
                slotsList.innerHTML = `<span style="color: #166534; font-weight: 500;">✓ All 8 session slots available on this date (${dateVal}).</span>`;
            } else {
                slotsList.innerHTML = `
                    <div style="margin-bottom: 6px; font-weight: 600; color: #475569;">${availSlots} of ${maxSlots} slots available for this date:</div>
                ` + scheduled.map(s => `
                    <div style="margin-top: 4px; padding: 4px 8px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <div>
                            <strong style="color: #1e293b;">${escapeHtml(s.start_time || '')} – ${escapeHtml(s.end_time || '')}</strong>
                            <span style="color: #64748b; font-size: 0.8rem; margin-left: 6px;">Case ${escapeHtml(s.case_number || 'N/A')}</span>
                        </div>
                        <span style="font-size: 0.72rem; color: #475569; background: #f1f5f9; padding: 1px 6px; border-radius: 4px;">${escapeHtml(s.venue || 'Barangay Hall')}</span>
                    </div>
                `).join('');
            }
        }

        // 4. Time conflict check
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
    // Guard against issuing summons before completing Paanyaya prerequisites
    const paaPrereq = window.complaintData?.paanyaya_stages?.summons_prerequisite;
    if (paaPrereq && !paaPrereq.allowed) {
        window.agapNotify?.(
            paaPrereq.message || paaPrereq.reason || 'Complete the required Paanyaya process before issuing the first formal summons.',
            'warning',
            'Paanyaya Prerequisite Required'
        );
        if (typeof switchCaseTab === 'function') {
            switchCaseTab('tab-paanyaya');
        }
        return;
    }
    const progress = window.caseProgressData;
    const btnInfo = progress?.actions?.summon_button;
    if (btnInfo?.disabled) {
        window.agapNotify?.(
            btnInfo.tooltip || 'An attempt to deliver the summons must first be made and recorded before issuing the next attempt.',
            'warning',
            'Delivery Attempt Required'
        );
        return;
    }

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

        // Compute deadline: strictly 15 business/working days (skipping weekends & holidays)
        let deadlineStr = null;
        let deadlineLabel = '';
        const baseDate = window.complaintCreatedAt ? new Date(window.complaintCreatedAt) : today;
        deadlineStr = calculateBusinessDays(baseDate, 15);
        const deadlineDateObj = new Date(deadlineStr + 'T00:00:00');
        deadlineLabel = deadlineDateObj.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });

        dateInput.min = todayStr;
        if (deadlineStr) dateInput.max = deadlineStr;

        // Set default to next business day if not set
        if (!dateInput.value) {
            const nextBizDay = calculateBusinessDays(today, 2);
            dateInput.value = (deadlineStr && nextBizDay > deadlineStr) ? deadlineStr : nextBizDay;
        } else if (deadlineStr && dateInput.value > deadlineStr) {
            dateInput.value = deadlineStr;
        }

        // Show/update the deadline hint label
        const hintId = 'summonDateDeadlineHint';
        let hintEl = document.getElementById(hintId);
        if (!hintEl) {
            hintEl = document.createElement('small');
            hintEl.id = hintId;
            hintEl.style.cssText = 'display:block; margin-top:4px; color:#b45309; font-size:0.82rem; font-weight:600;';
            dateInput.parentNode.appendChild(hintEl);
        }
        if (deadlineLabel) {
            hintEl.textContent = `⚠️ 15-day statutory mediation deadline (working days): ${deadlineLabel}.`;
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
    // Guard against issuing summons before completing Paanyaya prerequisites
    const paaPrereq = window.complaintData?.paanyaya_stages?.summons_prerequisite;
    if (paaPrereq && !paaPrereq.allowed) {
        window.agapNotify?.(
            paaPrereq.message || paaPrereq.reason || 'Complete the required Paanyaya process before issuing the first formal summons.',
            'warning',
            'Paanyaya Prerequisite Required'
        );
        if (typeof switchCaseTab === 'function') {
            switchCaseTab('tab-paanyaya');
        }
        return;
    }
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id');
    if (!complaintId) return;

    const progress = window.caseProgressData;
    const btnInfo  = progress?.actions?.summon_button;

    // If gated: service attempt not yet recorded for previous summons — block and notify
    const issueBtn = document.getElementById('issueSummonButton');
    if (issueBtn?.getAttribute('data-summon-gated') === 'true' || btnInfo?.disabled) {
        window.agapNotify?.(
            btnInfo?.tooltip || 'An attempt to deliver the summons must first be made and recorded before issuing the next attempt.',
            'warning',
            'Delivery Attempt Required'
        );
        if (btnInfo?.url) {
            setTimeout(() => {
                window.location.href = btnInfo.url;
            }, 1200);
        }
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
    if (window.isSubmittingSummon) return;

    const progress = window.caseProgressData;
    const btnInfo = progress?.actions?.summon_button;
    if (btnInfo?.disabled) {
        window.agapNotify?.(
            btnInfo.tooltip || 'An attempt to deliver the summons must first be made and recorded before issuing the next attempt.',
            'warning',
            'Delivery Attempt Required'
        );
        return;
    }

    const form = document.getElementById('issueSummonForm');
    const submitBtn = document.getElementById('submitIssueSummonBtn');
    const msg = document.getElementById('issueSummonMessage');
    const conflictAlert = document.getElementById('summonScheduleConflictAlert');
    if (msg) msg.textContent = '';

    if (conflictAlert && conflictAlert.style.display !== 'none') {
        window.agapNotify?.('Please resolve the mediation schedule conflict before proceeding.', 'error');
        return;
    }

    const dateVal = document.getElementById('summonMediationDate')?.value;
    if (dateVal && window.currentSummonWindowMeta) {
        const meta = window.currentSummonWindowMeta;
        if (meta.deadline_date && dateVal > meta.deadline_date) {
            const formatted = new Date(meta.deadline_date + 'T00:00:00').toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
            window.agapNotify?.(`Cannot issue summon: 1st Mediation date must fall within 15 working days of complaint filing (Statutory deadline: ${formatted}).`, 'error');
            return;
        }
        const daySlot = meta.daily_slots?.[dateVal];
        if (daySlot && daySlot.is_fully_booked) {
            window.agapNotify?.(`Cannot issue summon: The selected date (${dateVal}) has reached the maximum capacity of 8 mediation hearings per day.`, 'error');
            return;
        }
    }

    if (!form || !form.reportValidity()) return;

    window.isSubmittingSummon = true;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving Schedule...';
    }

    try {
        const formData = new FormData(form);
        const result = await complaintApi('../../../backend/api/summons/issue.php', {
            method: 'POST',
            body: formData
        });

        closeIssueSummonModal();
        window.agapNotify?.('First mediation schedule saved successfully. Review the KP Form No. 9 summons before printing or continuing to service.', 'success');

        // Refresh complaint details in the background so all cards & actions reflect new status
        loadComplaintDetails().catch(err => console.error('Error reloading complaint details:', err));

        // Immediately open the official KP Form No. 9 Summons Preview
        if (typeof openSummonsPreviewModal === 'function') {
            openSummonsPreviewModal(result.case_id, result.document_id, result.hearing_id, true);
        } else {
            console.warn('openSummonsPreviewModal is not defined.');
        }
    } catch (error) {
        if (msg) {
            msg.textContent = error.message;
            msg.style.color = '#dc2626';
        }
        window.agapNotify?.(error.message, 'error');
    } finally {
        window.isSubmittingSummon = false;
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Issue Summon & Schedule';
        }
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

        // Remove Issue Summon when the complaint has reached Conciliation or a later terminal stage.
        const issueBtn = document.getElementById('issueSummonButton');
        const issueText = document.getElementById('issueSummonButtonText');
        if (issueBtn) {
            issueBtn.style.display = progress.hide_issue_summon ? 'none' : 'inline-flex';
        }
        if (issueBtn && !progress.hide_issue_summon && progress.actions?.summon_button) {
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
                issueBtn.className = issueBtn.closest('.case-bar-right') ? 'btn-case-outline disabled' : 'btn-secondary';
                issueBtn.style.opacity = '0.6';
                issueBtn.style.cursor = 'not-allowed';
            } else if (btnInfo.action === 'view_proof') {
                issueBtn.className = issueBtn.closest('.case-bar-right') ? 'btn-case-outline' : 'btn-secondary';
            } else {
                issueBtn.className = issueBtn.closest('.case-bar-right') ? 'btn-case-outline' : 'btn-create';
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
    if (!track) return;

    const statuses = ['Pending', 'Mediation', 'Conciliation', 'CFA', 'Resolution / Closed'];
    const currentStatus = statuses.includes(progress.lifecycle_status)
        ? progress.lifecycle_status
        : 'Pending';
    const currentIndex = statuses.indexOf(currentStatus);

    if (badge) {
        const slug = currentStatus.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
        badge.className = `status-pill status-${slug}`;
        badge.textContent = currentStatus;
    }

    track.innerHTML = statuses.map((status, index) => {
        const state = index < currentIndex ? 'completed' : (index === currentIndex ? 'current' : 'upcoming');
        const circleContent = state === 'completed'
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>'
            : (state === 'current' ? '<span class="circle-dot"></span>' : '<span class="circle-empty"></span>');
        return `
            <div class="stepper-step-item ${state}" data-step-id="${escapeHtml(status)}">
                <div class="stepper-node-wrap">
                    <div class="stepper-circle" title="${escapeHtml(status)}">${circleContent}</div>
                    ${index < statuses.length - 1 ? '<div class="stepper-connector-line"></div>' : ''}
                </div>
                <div class="stepper-label-wrap">
                    <span class="stepper-step-title">${escapeHtml(status)}</span>
                </div>
            </div>
        `;
    }).join('');

    if (highlightBox) highlightBox.style.display = 'block';
    if (stageNameEl) stageNameEl.textContent = `Current Status: ${currentStatus}`;
    if (stageDetailsEl) stageDetailsEl.textContent = progress.lifecycle_description || 'Complaint workflow status.';

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
                                <span class="status-pill ${escapeHtml(badgeClass)}" style="font-size:0.72rem; padding:2px 7px; margin-right:6px;">${escapeHtml(evt.badge || evt.category)}</span>
                                <span>${escapeHtml(evt.details || '')}</span>
                                ${evt.actor ? `<span style="color:#94a3b8; font-size:0.75rem; margin-left:6px;">· Recorded by ${escapeHtml(evt.actor)}</span>` : ''}
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
window.tumanaBoundary = tumanaBoundary;
window.isWithinTumana = isWithinTumana;


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
    const map = L.map(element).fitBounds(tumanaBounds, { padding: [8, 8] });
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
            if (requestVersion !== lookupVersion) return;

            const addr = result.address || {};
            const fullAddress = (result.display_name || '').toLowerCase();
            const cityCandidates = [addr.city, addr.town, addr.municipality, addr.county, addr.city_district].filter(Boolean).map(s => s.toLowerCase());

            const isMarikinaCity = cityCandidates.some(c => c.includes('marikina')) || fullAddress.includes('marikina');
            const isWithinMarikinaBox = (lat >= 14.610 && lat <= 14.690 && lng >= 121.075 && lng <= 121.155);

            const otherCities = ['quezon city', 'pasig', 'san mateo', 'antipolo', 'cainta', 'rodriguez', 'taguig', 'makati', 'city of manila'];
            const inOtherCity = cityCandidates.some(c => otherCities.some(oc => c.includes(oc))) && !isMarikinaCity;

            const isMarikina = (isMarikinaCity || isWithinMarikinaBox) && !inOtherCity;

            // Case C: Outside Marikina City
            if (!isMarikina) {
                if (marker) {
                    map.removeLayer(marker);
                    marker = null;
                }
                latitude.value = '';
                longitude.value = '';
                state.value = (mapId === 'editComplaintMap' ? 'clear' : 'none');
                setStatus('Incident location must be within Marikina City.');
                window.agapNotify?.('Incident location must be within Marikina City.', 'error');
                return;
            }

            if (street) {
                street.value = [addr.house_number, addr.road || addr.pedestrian || addr.neighbourhood].filter(Boolean).join(' ') || street.value;
            }
            if (city) {
                city.value = 'Marikina City';
            }

            const inTumana = isWithinTumana(lat, lng);

            if (inTumana) {
                // Case A: Pin is within Barangay Tumana
                if (barangay) {
                    barangay.value = 'Tumana';
                    barangay.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (purok && purok.tagName === 'SELECT') {
                    const outsideOpt = purok.querySelector('option[value="Outside Tumana"]') || purok.querySelector('#purokOutsideTumanaOption');
                    if (outsideOpt) {
                        outsideOpt.disabled = true;
                        outsideOpt.hidden = true;
                    }
                    if (purok.value === 'Outside Tumana' || purok.value === 'Non-Resident') {
                        purok.value = '';
                    }

                    const rawArea = [
                        addr.quarter, addr.suburb, addr.neighbourhood,
                        addr.village, addr.road, addr.locality
                    ].filter(Boolean).join(' ').toLowerCase();

                    let matched = false;
                    for (const opt of purok.options) {
                        if (!opt.value || opt.value === 'Outside Tumana' || opt.value === 'Non-Resident') continue;
                        const optLower = opt.value.toLowerCase();
                        if (rawArea.includes(optLower)) {
                            purok.value = opt.value;
                            matched = true;
                            break;
                        }
                        const rawWords = rawArea.split(/[\s,]+/).filter(w => w.length > 2);
                        if (rawWords.some(w => optLower.includes(w))) {
                            purok.value = opt.value;
                            matched = true;
                            break;
                        }
                    }
                    if (matched) {
                        purok.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                setStatus(`Map point selected: ${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)} (Barangay Tumana). Address updated.`);
            } else {
                // Case B: Pin is outside Tumana, but still within Marikina City
                const detectedCandidates = [addr.suburb, addr.village, addr.quarter, addr.neighbourhood, addr.city_district].filter(Boolean).join(' ');
                const detectedLower = (detectedCandidates + ' ' + fullAddress).toLowerCase();

                let detectedBgy = '';
                if (detectedLower.includes('concepcion dos') || detectedLower.includes('concepcion ii') || detectedLower.includes('concepcion 2')) {
                    detectedBgy = 'Concepcion Dos';
                } else if (detectedLower.includes('concepcion uno') || detectedLower.includes('concepcion i') || detectedLower.includes('concepcion 1')) {
                    detectedBgy = 'Concepcion Uno';
                } else if (detectedLower.includes('santo niño') || detectedLower.includes('santo nino') || detectedLower.includes('sto. niño') || detectedLower.includes('sto nino') || detectedLower.includes('sto. nino')) {
                    detectedBgy = 'Santo Niño';
                } else if (detectedLower.includes('santa elena') || detectedLower.includes('sta. elena') || detectedLower.includes('sta elena')) {
                    detectedBgy = 'Santa Elena';
                } else if (detectedLower.includes('jesus dela peña') || detectedLower.includes('jesus dela pena') || detectedLower.includes('j. dela peña') || detectedLower.includes('j. dela pena')) {
                    detectedBgy = 'Jesus Dela Peña';
                } else if (detectedLower.includes('industrial valley') || detectedLower.includes('ivc')) {
                    detectedBgy = 'Industrial Valley Complex';
                } else if (detectedLower.includes('marikina heights')) {
                    detectedBgy = 'Marikina Heights';
                } else {
                    const simpleBgys = ['Barangka', 'Calumpang', 'Fortune', 'Malanday', 'Nangka', 'Parang', 'San Roque', 'Tañong'];
                    for (const b of simpleBgys) {
                        if (detectedLower.includes(b.toLowerCase()) || (b === 'Tañong' && detectedLower.includes('tanong'))) {
                            detectedBgy = b;
                            break;
                        }
                    }
                }

                if (barangay) {
                    if (detectedBgy) {
                        barangay.value = detectedBgy;
                    }
                    barangay.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (purok && purok.tagName === 'SELECT') {
                    const outsideOpt = purok.querySelector('option[value="Outside Tumana"]') || purok.querySelector('#purokOutsideTumanaOption');
                    if (outsideOpt) {
                        outsideOpt.disabled = false;
                        outsideOpt.hidden = false;
                    }
                    purok.value = 'Outside Tumana';
                    purok.dispatchEvent(new Event('change', { bubbles: true }));
                }

                const bgyLabel = detectedBgy ? `Barangay ${detectedBgy}` : 'Outside Tumana';
                setStatus(`Map point selected: ${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)} (${bgyLabel}). Address updated.`);
            }
        } catch (_) {
            const inTumana = isWithinTumana(lat, lng);
            if (city) city.value = 'Marikina City';
            if (inTumana) {
                if (barangay) {
                    barangay.value = 'Tumana';
                    barangay.dispatchEvent(new Event('change', { bubbles: true }));
                }
            } else {
                if (purok && purok.tagName === 'SELECT') {
                    const outsideOpt = purok.querySelector('option[value="Outside Tumana"]') || purok.querySelector('#purokOutsideTumanaOption');
                    if (outsideOpt) {
                        outsideOpt.disabled = false;
                        outsideOpt.hidden = false;
                    }
                    purok.value = 'Outside Tumana';
                    purok.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            setStatus(`Map point selected: ${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}. Address lookup is unavailable.`);
        }
    };

    const setPoint = (lat, lng, mapState = 'selected', syncAddress = false) => {
        const latNum = Number(lat);
        const lngNum = Number(lng);
        if (!Number.isFinite(latNum) || !Number.isFinite(lngNum)) return false;

        // Immediate bounding box check for Marikina City (Lat 14.610 to 14.690, Lng 121.075 to 121.155)
        if (latNum < 14.610 || latNum > 14.690 || lngNum < 121.075 || lngNum > 121.155) {
            window.agapNotify?.('Incident location must be within Marikina City.', 'error');
            if (marker) {
                map.removeLayer(marker);
                marker = null;
            }
            latitude.value = '';
            longitude.value = '';
            state.value = (mapId === 'editComplaintMap' ? 'clear' : 'none');
            setStatus('Incident location must be within Marikina City.');
            return false;
        }

        if (marker) marker.setLatLng([latNum, lngNum]);
        else {
            marker = L.marker([latNum, lngNum], { draggable: true }).addTo(map);
            marker.on('dragend', (event) => {
                const point = event.target.getLatLng();
                if (!setPoint(point.lat, point.lng, 'selected', true) && marker) {
                    if (Number.isFinite(Number(latitude.value)) && Number.isFinite(Number(longitude.value))) {
                        marker.setLatLng([Number(latitude.value), Number(longitude.value)]);
                    }
                }
            });
        }
        latitude.value = latNum.toFixed(8);
        longitude.value = lngNum.toFixed(8);
        state.value = mapState;
        setStatus(`Map point selected: ${latitude.value}, ${longitude.value}`);
        map.setView([latNum, lngNum], Math.max(map.getZoom(), 16));
        if (syncAddress) updateAddressFromPoint(latNum, lngNum);
        return true;
    };

    // Synchronize Purok dropdown with manual Barangay selection
    if (barangay && purok && purok.tagName === 'SELECT') {
        const syncPurokWithBarangay = () => {
            const outsideOpt = purok.querySelector('option[value="Outside Tumana"]') || purok.querySelector('#purokOutsideTumanaOption');
            if (barangay.value === 'Tumana') {
                if (outsideOpt) {
                    outsideOpt.disabled = true;
                    outsideOpt.hidden = true;
                }
                if (purok.value === 'Outside Tumana' || purok.value === 'Non-Resident') {
                    purok.value = '';
                }
            } else if (barangay.value !== '') {
                if (outsideOpt) {
                    outsideOpt.disabled = false;
                    outsideOpt.hidden = false;
                }
                purok.value = 'Outside Tumana';
            }
        };
        barangay.addEventListener('change', syncPurokWithBarangay);
        syncPurokWithBarangay();
    }

    const geocodeAddress = async () => {
        const streetValue = street?.value.trim() || '';
        const purokValue = purok?.value.trim() || '';
        const barangayValue = barangay?.value.trim() || 'Barangay Tumana';
        if (streetValue.length < 3) return;
        const query = [streetValue, (purokValue !== 'Outside Tumana' && purokValue !== 'Non-Resident') ? purokValue : '', barangayValue, 'Marikina City', 'Metro Manila', 'Philippines'].filter(Boolean).join(', ');
        const requestVersion = ++lookupVersion;
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
            const results = await response.json();
            if (requestVersion !== lookupVersion || !results[0]) return;
            const lat = Number(results[0].lat), lng = Number(results[0].lon);
            setPoint(lat, lng, 'selected');
        } catch (_) { setStatus('Address lookup is unavailable. You can still place the pin on the map.'); }
    };
    [street, purok, barangay].filter(Boolean).forEach((field) => {
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
    if (state.value === 'unchanged' && Number.isFinite(savedLat) && Number.isFinite(savedLng)) {
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

function archiveComplaint(id) {
    const modal = document.getElementById('archiveComplaintModal') || document.getElementById('deleteComplaintModal');
    const input = document.getElementById('archiveComplaintId') || document.getElementById('deleteComplaintId');
    if (input) input.value = id;
    if (modal) modal.style.display = 'flex';
}

function closeArchiveComplaintModal() {
    const modal = document.getElementById('archiveComplaintModal') || document.getElementById('deleteComplaintModal');
    if (modal) modal.style.display = 'none';
}

async function confirmArchiveComplaint() {
    const input = document.getElementById('archiveComplaintId') || document.getElementById('deleteComplaintId');
    const id = input ? input.value : '';
    if (!id) return;
    try {
        const response = await fetch('../../../backend/api/complaints/archive.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-Token': window.agapCsrfToken || ''
            },
            body: new URLSearchParams({ complaint_id: id }),
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to archive complaint.');
        }
        closeArchiveComplaintModal();
        window.agapNotify?.(result.message, 'success');
        window.location.reload();
    } catch (error) {
        window.agapNotify?.(error.message || 'Unable to archive complaint.', 'error');
    }
}

window.archiveComplaint = archiveComplaint;
window.closeArchiveComplaintModal = closeArchiveComplaintModal;
window.confirmArchiveComplaint = confirmArchiveComplaint;
window.complaintMaps = complaintMaps;
window.initialiseComplaintMap = initialiseComplaintMap;
window.refreshComplaintMap = refreshComplaintMap;

// Backward-compatibility aliases
function deleteComplaint(id) {
    archiveComplaint(id);
}

function closeDeleteComplaintModal() {
    closeArchiveComplaintModal();
}

function confirmDeleteComplaint() {
    confirmArchiveComplaint();
}

window.deleteComplaint = archiveComplaint;
window.closeDeleteComplaintModal = closeArchiveComplaintModal;
window.confirmDeleteComplaint = confirmArchiveComplaint;

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

    // Find preview element and row
    const additionalRow = comboboxEl.closest('.additional-party-row');
    let previewEl = null;
    if (partyRole === 'complainant') {
        previewEl = document.getElementById('complainantPreview');
    } else if (partyRole === 'respondent') {
        previewEl = document.getElementById('respondentPreview');
    } else {
        previewEl = additionalRow ? additionalRow.querySelector('.additional-party-preview') : null;
    }

    // Inline residency warning element
    let warningEl = comboboxEl.parentElement ? comboboxEl.parentElement.querySelector('.party-residency-warning') : null;
    if (!warningEl && (partyRole === 'complainant' || partyRole === 'respondent')) {
        warningEl = document.createElement('div');
        warningEl.className = 'party-residency-warning';
        warningEl.style.display = 'none';
        comboboxEl.parentElement.insertBefore(warningEl, previewEl || comboboxEl.nextSibling);
    } else if (!warningEl && additionalRow) {
        warningEl = document.createElement('div');
        warningEl.className = 'party-residency-warning';
        warningEl.style.display = 'none';
        additionalRow.insertBefore(warningEl, previewEl || comboboxEl.nextSibling);
    }

    function showInlineWarning(msg) {
        if (!warningEl) return;
        warningEl.innerHTML = `<span>⚠️ ${escapeHtml(msg)}</span>`;
        warningEl.style.display = 'flex';
    }

    function hideInlineWarning() {
        if (!warningEl) return;
        warningEl.style.display = 'none';
        warningEl.innerHTML = '';
    }

    function getEffectiveRole() {
        if (partyRole === 'complainant') return 'complainant';
        if (partyRole === 'respondent') return 'respondent';
        if (additionalRow) {
            const typeSelect = additionalRow.querySelector('select[name="party_types[]"], select[name="party_type[]"]');
            if (typeSelect) {
                return (typeSelect.value || '').trim().toLowerCase();
            }
        }
        return (partyRole || '').toLowerCase();
    }

    function isNonResident(res) {
        if (!res) return false;
        const isTenant = Number(res.is_tenant);
        const purok = (res.purok || '').trim().toLowerCase();
        return isTenant === 2 || purok === 'non-resident' || (isTenant !== 0 && isTenant !== 1);
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

        let resBadge = '';
        const isTenant = Number(res.is_tenant);
        const purok = (res.purok || '').trim().toLowerCase();
        if (isTenant === 2 || purok === 'non-resident') {
            resBadge = '<span class="residency-badge badge-non-resident"><span class="residency-dot"></span>Non-Resident</span>';
        } else if (isTenant === 1) {
            resBadge = '<span class="residency-badge badge-tenant"><span class="residency-dot"></span>Tenant - Tumana</span>';
        } else {
            resBadge = '<span class="residency-badge badge-resident"><span class="residency-dot"></span>Resident</span>';
        }

        previewEl.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <strong style="color: #0f172a; font-size: 0.92rem;">${escapeHtml(res.name)}</strong>
                        ${resBadge}
                    </div>
                    ${details ? `<div style="font-size: 0.8rem; color: #475569; margin-top: 2px;">${details}</div>` : ''}
                </div>
                <span class="party-search-item-badge party-search-badge-verified">Verified</span>
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
        const effectiveRole = getEffectiveRole();
        if (effectiveRole === 'respondent' && isNonResident(res)) {
            showInlineWarning('Under Katarungang Pambarangay rules, only residents and tenants living within Barangay Tumana can be named as respondents.');
            window.agapNotify?.('Non-residents cannot be named as respondents in Barangay Tumana.', 'error');
            return;
        }

        hideInlineWarning();
        hiddenId.value = res.resident_id;
        if (hiddenName) hiddenName.value = res.name;

        const displayLabel = res.display_name || res.name;
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
        hideInlineWarning();
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
        const effectiveRole = getEffectiveRole();

        let filtered = residentsList;
        // Strictly restrict respondent (primary or additional row) to verified residents and tenants living within Barangay Tumana
        if (effectiveRole === 'respondent') {
            filtered = filtered.filter(res => !isNonResident(res));
        }

        if (tokens.length > 0) {
            filtered = filtered.filter(res => {
                const text = res.search_text || '';
                return tokens.every(t => text.includes(t));
            });
        }

        // Limit results to 30 items
        const results = filtered.slice(0, 30);
        currentRenderedItems = results;
        highlightedIndex = -1;

        if (results.length === 0) {
            const noResMsg = effectiveRole === 'respondent'
                ? 'Only residents and tenants living within Barangay Tumana can be named as respondents in this barangay.'
                : 'Only registered profiles can be selected.';
            dropdown.innerHTML = `
                <div class="party-search-no-results">
                    <strong>No matching profile found for "${escapeHtml(query)}"</strong>
                    <span>${noResMsg}</span>
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

            if (effectiveRole === 'complainant' && respVal && String(res.resident_id) === String(respVal)) {
                isDisabled = true;
                disabledReason = 'Selected as Respondent';
            } else if (effectiveRole === 'respondent' && compVal && String(res.resident_id) === String(compVal)) {
                isDisabled = true;
                disabledReason = 'Selected as Complainant';
            }

            let itemBadge = '';
            const isTenant = Number(res.is_tenant);
            const purok = (res.purok || '').trim().toLowerCase();
            if (isTenant === 2 || purok === 'non-resident') {
                itemBadge = '<span class="party-search-item-badge badge-non-resident">Non-Resident</span>';
            } else if (isTenant === 1) {
                itemBadge = '<span class="party-search-item-badge badge-tenant">Tenant - Tumana</span>';
            } else {
                itemBadge = '<span class="party-search-item-badge badge-resident">Resident</span>';
            }

            return `
                <div class="party-search-item ${isSelected ? 'is-selected' : ''} ${isDisabled ? 'is-disabled' : ''}" data-index="${idx}" data-resident-id="${res.resident_id}">
                    <div class="party-search-item-top">
                        <span class="party-search-item-name">${escapeHtml(res.display_name)}</span>
                        ${isDisabled 
                            ? `<span class="party-search-item-badge party-search-badge-disabled">${disabledReason}</span>` 
                            : itemBadge}
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
                    window.agapNotify?.('The complainant and respondent cannot be the same profile.', 'error');
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

    // Listen to role changes on additional rows to re-check validity and update dropdown suggestions
    if (additionalRow) {
        const typeSelect = additionalRow.querySelector('select[name="party_types[]"], select[name="party_type[]"]');
        if (typeSelect) {
            typeSelect.addEventListener('change', () => {
                const currentRoleId = hiddenId.value ? Number(hiddenId.value) : 0;
                if (currentRoleId > 0) {
                    const currentRes = residentsList.find(r => r.resident_id === currentRoleId);
                    if (currentRes && getEffectiveRole() === 'respondent' && isNonResident(currentRes)) {
                        showInlineWarning('Selected profile is a non-resident and cannot be a respondent. Please select a resident/tenant or switch role.');
                        searchInput.classList.remove('is-valid-selected');
                        searchInput.classList.add('is-invalid-unselected');
                    } else {
                        hideInlineWarning();
                        if (currentRoleId > 0) {
                            searchInput.classList.remove('is-invalid-unselected');
                            searchInput.classList.add('is-valid-selected');
                        }
                    }
                } else {
                    hideInlineWarning();
                }
                if (dropdown.style.display === 'block') {
                    renderList(searchInput.value);
                }
            });
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
            hideInlineWarning();
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
                hideInlineWarning();
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


// AGAP_UNIFIED_SYNC_COMPLAINTS
window.addEventListener('agap:data-changed', async (event) => {
    const changed = event.detail?.modules || [];
    if (!changed.some((name) => ['complaints', 'cases', 'assignments', 'pangkat', 'hearings', 'deadlines', 'history'].includes(name))) {
        return;
    }
    const complaintId = new URLSearchParams(window.location.search).get('id');
    if (!complaintId || document.body.dataset.complaintSyncRefreshing === '1') return;
    document.body.dataset.complaintSyncRefreshing = '1';
    try {
        await loadComplaintDetails();
    } finally {
        window.setTimeout(() => delete document.body.dataset.complaintSyncRefreshing, 400);
    }
});

// ===================================================================
// SESSION & PROCESS DETAILS MODAL (Mediation / Conciliation Details)
// ===================================================================
async function openHearingSessionDetails(hearingId) {
    const modal = document.getElementById('sessionProcessDetailsModal');
    if (!modal) return;

    modal.style.display = 'flex';

    const titleEl = document.getElementById('spModalTitle');
    const subtitleEl = document.getElementById('spModalSubtitle');
    const statusBadge = document.getElementById('spModalStatusBadge');
    const stageBadge = document.getElementById('spModalStageBadge');
    const bodyEl = document.getElementById('spModalBody');
    const leftActions = document.getElementById('spModalLeftActions');

    bodyEl.innerHTML = `
        <div style="text-align: center; padding: 40px 20px; color: #64748b;">
            <div style="font-size: 1.5rem; margin-bottom: 8px;">⏳</div>
            <p style="margin: 0; font-weight: 500;">Loading mediation / conciliation session &amp; process details...</p>
        </div>
    `;

    const workspace = window.__agapComplaintWorkspace;
    const complaint = window.__agapCurrentComplaint;
    const stageCaseId = workspace?.case?.case_id || complaint?.case_id || null;
    let hearing = (workspace?.hearings || []).find(item => Number(item.hearing_id) === Number(hearingId));

    let attendanceData = null;
    let minutesData = null;

    try {
        const [attRes, minRes] = await Promise.allSettled([
            complaintApi(`../../../backend/api/hearings/attendance.php?hearing_id=${encodeURIComponent(hearingId)}`),
            complaintApi(`../../../backend/api/hearings/minutes.php?hearing_id=${encodeURIComponent(hearingId)}`)
        ]);

        if (attRes.status === 'fulfilled' && attRes.value?.success) {
            attendanceData = attRes.value.data || attRes.value;
        }
        if (minRes.status === 'fulfilled' && minRes.value?.success) {
            minutesData = minRes.value.data;
        }
    } catch (e) {
        console.warn('Could not fetch extra session data:', e);
    }

    const h = attendanceData?.hearing || attendanceData || hearing || {};
    const parties = attendanceData?.parties || hearing?.parties || [];
    const minutes = minutesData || null;
    const situation = attendanceData?.situation || hearing?.situation || null;

    const rawType = h.hearing_type || hearing?.hearing_type || 'Mediation Session';
    const status = h.hearing_status || hearing?.hearing_status || 'Scheduled';
    const dateFormatted = (h.hearing_date || hearing?.hearing_date) ? formatDateTimeReadable(h.hearing_date || hearing?.hearing_date) : 'Pending Schedule';
    const venue = h.venue || hearing?.venue || 'Barangay Hall';
    const caseNum = h.case_number || workspace?.case?.case_number || complaint?.case_number || 'N/A';
    const compTitle = h.complaint_title || complaint?.complaint_title || '';

    // Classification of Stage & Process
    const typeLower = rawType.toLowerCase();
    const isConciliation = typeLower.includes('conciliation') || typeLower.includes('pangkat');
    const isShowCause = typeLower.includes('show cause') || typeLower.includes('cause');
    const isArbitration = typeLower.includes('arbitration');
    const isMediation = !isConciliation && !isShowCause && !isArbitration;

    // Badges & Presider
    let stageTitle = 'Stage 1: Mediation';
    let stageBg = '#dbeafe';
    let stageColor = '#1e40af';
    let processHeading = 'Punong Barangay Mediation';
    let legalCitation = 'Republic Act No. 7160 (Local Government Code), Sec. 410(b)';
    let bannerAccent = '#2563eb';
    let processOverview = '';
    let processRules = [];
    let presidingRole = 'Punong Barangay / Lupon Head';
    let presidingOfficial = h.presiding_officer_name || 'Barangay Captain';
    if (presidingOfficial.toLowerCase() === 'admin user' || presidingOfficial.toLowerCase() === 'administrator') {
        presidingOfficial = 'Barangay Captain';
    }
    if (h.substitute_presider_name) {
        presidingOfficial = `${h.substitute_presider_name} (Substitute Presider)`;
        presidingRole = 'Authorized Substitute Presider (RA 7160 Sec. 411)';
    }

    if (isConciliation) {
        stageTitle = 'Stage 2: Conciliation';
        stageBg = '#f3e8ff';
        stageColor = '#7e22ce';
        processHeading = 'Pangkat ng Tagapagkasundo Conciliation';
        legalCitation = 'Republic Act No. 7160, Sec. 410(c)';
        bannerAccent = '#7c3aed';
        presidingRole = '3-Member Lupon Panel (Chairman, Secretary, Member)';
        presidingOfficial = 'Pangkat ng Tagapagkasundo';
        processOverview = 'Formal conciliation proceedings conducted by the 3-member Pangkat panel after mediation was not settled. The Pangkat is constituted by agreement of the parties (or drawn lots by the Punong Barangay) to conduct personal conciliation hearings.';
        processRules = [
            '<strong>Panel Composition:</strong> 3 members chosen from the Lupon Tagapamayapa roster (Chairman, Secretary, Member).',
            '<strong>Statutory Window:</strong> The Pangkat convenes within 3 days from constitution and has a statutory 15-day period (extendable by another 15 days for meritorious grounds) to reach an amicable settlement.',
            '<strong>Settlement Effect:</strong> Amicable Settlement (KP Form 16) or Arbitration Award (KP Form 17) carries the force and effect of a final court judgment after 10 days if not repudiated under oath.',
            '<strong>Failure to Conciliate:</strong> If no settlement is reached, the Pangkat Secretary attests and Chairman issues KP Form 20 (Certificate to File Action / CFA), endorsed by the Punong Barangay, authorizing the parties to file in court or proper government agency.'
        ];
    } else if (isShowCause) {
        stageTitle = 'Administrative Due Process';
        stageBg = '#ffedd5';
        stageColor = '#c2410c';
        processHeading = 'Administrative Show Cause Proceeding';
        legalCitation = 'Republic Act No. 7160, Sec. 410(b)/(d) & KP Implementing Rules';
        bannerAccent = '#ea580c';
        presidingRole = 'Punong Barangay / Barangay Captain';
        processOverview = 'A formal hearing scheduled following a party\'s failure to appear for a scheduled mediation or conciliation session despite confirmed service of summons (KP Form 8 / KP Form 9). This proceeding accords due process to verify if valid justifiable cause exists for the absence.';
        processRules = [
            '<strong>Justifiable Cause Requirement:</strong> The absent party must establish a meritorious ground (e.g., sudden serious illness, hospitalization, force majeure / natural disaster) that prevented attendance.',
            '<strong>Sanction for Complainant Absence:</strong> Failure of complainant to appear without justifiable cause results in dismissal of the complaint with prejudice and issuance of KP Form 20-A (Certificate to Bar Action).',
            '<strong>Sanction for Respondent Absence:</strong> Failure of respondent to appear without justifiable cause results in barring of any counterclaim (KP Form 20-B) and immediate issuance of a Certificate to File Action (KP Form 20) to the complainant.'
        ];
    } else if (isArbitration) {
        stageTitle = 'Stage 3: Arbitration';
        stageBg = '#fef3c7';
        stageColor = '#b45309';
        processHeading = 'Voluntary Arbitration Proceeding';
        legalCitation = 'Republic Act No. 7160, Sec. 413';
        bannerAccent = '#d97706';
        presidingRole = 'Punong Barangay or Pangkat Chairman';
        processOverview = 'Voluntary arbitration proceedings where both parties executed an Agreement for Arbitration (KP Form 14), agreeing to submit their dispute and be bound by the arbitration award of the Lupon.';
        processRules = [
            '<strong>Agreement to Arbitrate:</strong> Irrevocable after 5 days, unless repudiated on grounds of vitiated consent (fraud, violence, intimidation).',
            '<strong>Arbitration Award:</strong> Rendered after formal hearing within 15 days (KP Form 17), with the force of a final court judgment after 10 days.'
        ];
    } else {
        // Default Mediation
        processOverview = 'Stage 1 of the Katarungang Pambarangay system. Conducted personally by the Punong Barangay / Barangay Captain (or designated substitute presider) to mediate disputes directly and amicably between neighborhood residents.';
        processRules = [
            '<strong>Presiding Official:</strong> Conducted personally by the Punong Barangay / Barangay Captain (or authorized Substitute Presider if the Barangay Captain is disqualified or absent under Sec. 411).',
            '<strong>Statutory Window:</strong> 15-day statutory conciliation window starting from the first mediation meeting. Up to 3 mediation sessions may be conducted.',
            '<strong>No Attorneys Allowed:</strong> Parties must appear in person without legal representation or lawyers (Sec. 415).',
            '<strong>Amicable Settlement:</strong> When settlement is reached, KP Form 16 (Amicable Settlement) is drafted and signed, carrying the force of a final court judgment after 10 days.',
            '<strong>Referral to Pangkat:</strong> If mediation fails or 15 days expire without settlement, the case must be formally elevated to the 3-member Pangkat ng Tagapagkasundo (Stage 2) via KP Form 10/11.'
        ];
    }

    // Set Header
    titleEl.textContent = rawType;
    subtitleEl.innerHTML = `Case <strong>${escapeHtml(caseNum)}</strong> ${compTitle ? `&bull; ${escapeHtml(compTitle)}` : ''}`;

    let statusStyle = 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;';
    if (status === 'Completed') statusStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;';
    else if (status === 'Rescheduled') statusStyle = 'background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff;';
    else if (status === 'Cancelled') statusStyle = 'background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;';

    statusBadge.textContent = status;
    statusBadge.style.cssText = statusStyle;

    stageBadge.textContent = stageTitle;
    stageBadge.style.background = stageBg;
    stageBadge.style.color = stageColor;

    // Attendance numbers
    const presentCount = Number(h.present_count || (parties.filter(p => p.attendance_status === 'Present').length));
    const absentCount = Number(h.unjustified_absent_count || (parties.filter(p => p.attendance_status === 'Absent').length));
    const excusedCount = Number(h.excused_count || (parties.filter(p => p.attendance_status === 'Excused' || p.is_justified).length));

    // Build Minutes Section
    let minutesContentHtml = '';
    if (minutes && (minutes.session_notes || minutes.settlement_discussion_notes || minutes.complainant_statement || minutes.main_dispute_identified || minutes.agreed_terms)) {
        minutesContentHtml = `
            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.85rem; color: #334155;">
                ${minutes.main_dispute_identified ? `
                    <div style="background: #f8fafc; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                        <strong style="color: #475569; font-size: 0.75rem; text-transform: uppercase; display: block; margin-bottom: 2px;">Main Dispute Identified:</strong>
                        <p style="margin: 0; color: #0f172a;">${escapeHtml(minutes.main_dispute_identified)}</p>
                    </div>
                ` : ''}
                ${minutes.complainant_statement ? `
                    <div style="background: #f8fafc; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                        <strong style="color: #1e40af; font-size: 0.75rem; text-transform: uppercase; display: block; margin-bottom: 2px;">Complainant Statement:</strong>
                        <p style="margin: 0; color: #0f172a;">${escapeHtml(minutes.complainant_statement)}</p>
                    </div>
                ` : ''}
                ${minutes.respondent_statement ? `
                    <div style="background: #f8fafc; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                        <strong style="color: #9f1239; font-size: 0.75rem; text-transform: uppercase; display: block; margin-bottom: 2px;">Respondent Statement:</strong>
                        <p style="margin: 0; color: #0f172a;">${escapeHtml(minutes.respondent_statement)}</p>
                    </div>
                ` : ''}
                ${(minutes.settlement_discussion_notes || minutes.session_notes) ? `
                    <div style="background: #f8fafc; padding: 10px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                        <strong style="color: #475569; font-size: 0.75rem; text-transform: uppercase; display: block; margin-bottom: 2px;">Discussion &amp; Settlement Notes:</strong>
                        <p style="margin: 0; color: #0f172a; white-space: pre-wrap;">${escapeHtml(minutes.settlement_discussion_notes || minutes.session_notes)}</p>
                    </div>
                ` : ''}
                ${minutes.agreed_terms ? `
                    <div style="background: #f0fdf4; padding: 10px 12px; border-radius: 6px; border: 1px solid #bbf7d0;">
                        <strong style="color: #15803d; font-size: 0.75rem; text-transform: uppercase; display: block; margin-bottom: 2px;">Agreed Terms &amp; Conditions:</strong>
                        <p style="margin: 0; color: #14532d; font-weight: 500;">${escapeHtml(minutes.agreed_terms)}</p>
                    </div>
                ` : ''}
                <div style="font-size: 0.75rem; color: #64748b; margin-top: 4px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 4px;">
                    <span>Recorded by: <strong>${escapeHtml(minutes.recorded_by_name || 'Lupon Staff')}</strong></span>
                    <span>Status: <strong style="color: ${minutes.status === 'Finalized' ? '#15803d' : '#b45309'};">${escapeHtml(minutes.status || 'Draft')}</strong></span>
                </div>
            </div>
        `;
    } else if (h.attendance_notes) {
        minutesContentHtml = `
            <div style="background: #f8fafc; padding: 12px 14px; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 0.85rem; color: #334155;">
                <strong style="color: #475569; font-size: 0.75rem; text-transform: uppercase; display: block; margin-bottom: 4px;">Session Attendance Remarks:</strong>
                <p style="margin: 0; color: #0f172a;">${escapeHtml(h.attendance_notes)}</p>
            </div>
        `;
    } else {
        minutesContentHtml = `
            <div style="text-align: center; padding: 20px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; color: #64748b;">
                <p style="margin: 0 0 4px; font-size: 0.85rem; font-weight: 500;">No formal minutes or proceedings recorded yet for this session.</p>
                <span style="font-size: 0.78rem; color: #94a3b8;">Minutes can be added from the Mediation / Conciliation workspace.</span>
            </div>
        `;
    }

    // Guidance text
    let guidanceHtml = '';
    if (situation && (situation.title || situation.recommendation || situation.description)) {
        guidanceHtml = `
            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #3b82f6; border-radius: 8px; padding: 14px 16px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                    <span style="font-size: 1.05rem;">⚖️</span>
                    <strong style="font-size: 0.88rem; color: #1e3a8a;">Katarungang Pambarangay Procedural Guidance:</strong>
                    ${situation.title ? `<span style="font-size: 0.76rem; font-weight: 700; background: #dbeafe; color: #1d4ed8; padding: 2px 7px; border-radius: 4px;">${escapeHtml(situation.title)}</span>` : ''}
                </div>
                <p style="margin: 0; font-size: 0.84rem; color: #1e40af; line-height: 1.5;">
                    ${escapeHtml(situation.recommendation || situation.description || '')}
                </p>
            </div>
        `;
    }

    // Party Attendance Rows
    let partiesListHtml = '';
    if (!parties.length) {
        partiesListHtml = '<p style="margin: 0; color: #64748b; font-size: 0.84rem; text-align: center; padding: 12px;">No parties recorded for this hearing.</p>';
    } else {
        partiesListHtml = parties.map(p => {
            const st = p.attendance_status || 'Pending';
            let pPillBg = '#f1f5f9', pPillColor = '#475569';
            if (st === 'Present') { pPillBg = '#dcfce7'; pPillColor = '#15803d'; }
            else if (st === 'Absent') { pPillBg = '#fee2e2'; pPillColor = '#991b1b'; }
            else if (st === 'Excused') { pPillBg = '#fef3c7'; pPillColor = '#b45309'; }

            return `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; flex-wrap: wrap; gap: 8px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 0.7rem; font-weight: 800; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; ${p.party_type === 'Complainant' ? 'background: #dbeafe; color: #1e40af;' : 'background: #ffe4e6; color: #be123c;'}">
                            ${escapeHtml(p.party_type)}
                        </span>
                        <div>
                            <strong style="font-size: 0.88rem; color: #0f172a;">${escapeHtml(p.full_name || 'Party Member')}</strong>
                            <div style="font-size: 0.74rem; color: #64748b; margin-top: 1px;">
                                ${p.service_confirmed ? '<span style="color: #15803d; font-weight: 600;">✓ Summons Confirmed Served</span>' : '<span style="color: #64748b;">⏳ Summons Service In Progress</span>'}
                                ${p.justification_reason ? ` &bull; <em>Excuse: ${escapeHtml(p.justification_reason)}</em>` : ''}
                                ${p.remarks ? ` &bull; <span>Remarks: ${escapeHtml(p.remarks)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <span style="font-size: 0.76rem; font-weight: 700; padding: 3px 10px; border-radius: 9999px; text-transform: uppercase; background: ${pPillBg}; color: ${pPillColor};">
                        ${escapeHtml(st)}
                    </span>
                </div>
            `;
        }).join('');
    }

    // Assemble Full Modal Body
    bodyEl.innerHTML = `
        <!-- Section 1: Process Details & Legal Mandate Banner -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 5px solid ${bannerAccent}; border-radius: 8px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px; flex-wrap: wrap;">
                <div>
                    <span style="font-size: 0.72rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; display: block;">
                        Katarungang Pambarangay Legal Mandate
                    </span>
                    <h3 style="margin: 2px 0 0; font-size: 1.05rem; color: #0f172a; font-weight: 800;">
                        ${escapeHtml(processHeading)}
                    </h3>
                </div>
                <span style="font-size: 0.74rem; font-weight: 700; color: #334155; background: #f1f5f9; padding: 3px 8px; border-radius: 6px;">
                    ${escapeHtml(legalCitation)}
                </span>
            </div>
            <p style="margin: 0 0 12px; font-size: 0.85rem; color: #334155; line-height: 1.55;">
                ${escapeHtml(processOverview)}
            </p>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px;">
                <span style="font-size: 0.74rem; font-weight: 700; color: #475569; text-transform: uppercase; display: block; margin-bottom: 6px;">
                    Procedural Rules &amp; Statutory Guidelines:
                </span>
                <ul style="margin: 0; padding-left: 18px; font-size: 0.82rem; color: #475569; line-height: 1.55; display: flex; flex-direction: column; gap: 5px;">
                    ${processRules.map(r => `<li>${r}</li>`).join('')}
                </ul>
            </div>
        </div>

        <!-- Section 2: Session Schedule & Logistics Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px;">
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">📅 Hearing Date &amp; Time</span>
                <strong style="font-size: 0.92rem; color: #0f172a; display: block;">${escapeHtml(dateFormatted)}</strong>
            </div>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">📍 Hearing Venue</span>
                <strong style="font-size: 0.92rem; color: #0f172a; display: block;">${escapeHtml(venue)}</strong>
            </div>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">👤 Presiding Authority</span>
                <strong style="font-size: 0.92rem; color: #0f172a; display: block;">${escapeHtml(presidingOfficial)}</strong>
                <span style="font-size: 0.72rem; color: #64748b;">${escapeHtml(presidingRole)}</span>
            </div>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">📋 Stage Progression</span>
                <strong style="font-size: 0.92rem; color: #0f172a; display: block;">${escapeHtml(status)}</strong>
                <span style="font-size: 0.72rem; color: #64748b;">${h.rescheduled_from_id ? 'Rescheduled hearing' : 'Original schedule'}</span>
            </div>
        </div>

        ${guidanceHtml}

        <!-- Section 3: Involved Parties & Attendance Card -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 18px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <div>
                    <h4 style="margin: 0; font-size: 0.92rem; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.03em;">
                        Involved Parties &amp; Attendance
                    </h4>
                    <span style="font-size: 0.78rem; color: #64748b;">Attendance, appearance verification, and summons service status</span>
                </div>
                <div style="display: flex; gap: 6px; align-items: center;">
                    <span style="font-size: 0.74rem; font-weight: 700; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 9999px;">✓ ${presentCount} Present</span>
                    <span style="font-size: 0.74rem; font-weight: 700; color: #b91c1c; background: #fee2e2; padding: 2px 8px; border-radius: 9999px;">✕ ${absentCount} Non-appearance</span>
                    ${excusedCount > 0 ? `<span style="font-size: 0.74rem; font-weight: 700; color: #b45309; background: #fef3c7; padding: 2px 8px; border-radius: 9999px;">⚠️ ${excusedCount} Excused</span>` : ''}
                </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                ${partiesListHtml}
            </div>
        </div>

        <!-- Section 4: Session Minutes & Discussion Records -->
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 18px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <div>
                    <h4 style="margin: 0; font-size: 0.92rem; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.03em;">
                        Session Proceedings &amp; Minutes
                    </h4>
                    <span style="font-size: 0.78rem; color: #64748b;">Statements, discussion summaries, settlement proposals, and agreed terms</span>
                </div>
            </div>
            ${minutesContentHtml}
        </div>
    `;

    // Populate Left Actions
    let actionButtonsHtml = '';
    if (isMediation) {
        actionButtonsHtml += `
            <button type="button" class="btn-case-primary" onclick="switchCaseTab('tab-mediation'); closeSessionProcessDetailsModal();" style="font-size: 0.82rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 5px;">
                View Mediation Tab &rarr;
            </button>
            <a href="../cases/mediation-workspace.php?case_id=${encodeURIComponent(stageCaseId || '')}&hearing_id=${encodeURIComponent(hearingId)}" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 14px; text-decoration: none;">
                Open Mediation Workspace &rarr;
            </a>
        `;
    } else if (isConciliation) {
        actionButtonsHtml += `
            <button type="button" class="btn-case-primary" onclick="switchCaseTab('tab-conciliation'); closeSessionProcessDetailsModal();" style="font-size: 0.82rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 5px;">
                View Conciliation Tab &rarr;
            </button>
            <a href="../cases/pangkat-workspace.php?case_id=${encodeURIComponent(stageCaseId || '')}&hearing_id=${encodeURIComponent(hearingId)}" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 14px; text-decoration: none;">
                Open Pangkat Workspace &rarr;
            </a>
        `;
    } else {
        actionButtonsHtml += `
            <a href="../hearings/schedules.php?case_id=${encodeURIComponent(stageCaseId || '')}&hearing_id=${encodeURIComponent(hearingId)}" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 14px; text-decoration: none;">
                Manage in Hearings Schedule &rarr;
            </a>
        `;
    }
    leftActions.innerHTML = actionButtonsHtml;
}

function closeSessionProcessDetailsModal() {
    const modal = document.getElementById('sessionProcessDetailsModal');
    if (modal) modal.style.display = 'none';
}

window.openHearingSessionDetails = openHearingSessionDetails;
window.closeSessionProcessDetailsModal = closeSessionProcessDetailsModal;

// Auto-bind outside clicks for sessionProcessDetailsModal
document.addEventListener('DOMContentLoaded', () => {
    const spModal = document.getElementById('sessionProcessDetailsModal');
    if (spModal) {
        spModal.addEventListener('click', (e) => {
            if (e.target === spModal) closeSessionProcessDetailsModal();
        });
    }

    ['kp7DigitalPreviewModal', 'kp7UploadScanModal', 'kp7ScanHistoryModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m) {
            m.addEventListener('click', (e) => {
                if (e.target === m) m.style.display = 'none';
            });
        }
    });

    const scanInput = document.getElementById('scanFileInput');
    const scanDetails = document.getElementById('scanFileDetails');
    if (scanInput && scanDetails) {
        scanInput.addEventListener('change', () => {
            if (scanInput.files && scanInput.files[0]) {
                const f = scanInput.files[0];
                scanDetails.textContent = `Selected: ${f.name} (${(f.size/1024).toFixed(1)} KB)`;
                scanDetails.style.display = 'block';
            } else {
                scanDetails.style.display = 'none';
            }
        });
    }
});

// ══════════════════════════════════════════════════════════════════════════════
// KP Form 7 Overview & Scanned Document Handlers
// ══════════════════════════════════════════════════════════════════════════════
function renderKp7Overview(complaint) {
    if (!complaint) return;
    const paanyayaStatuses = [
        'Pre-Docketed (Paanyaya)',
        '1st Paanyaya Failed (No-Show)',
        'Paanyaya In Progress',
        'Resolved at Paanyaya / Closed',
        'Paanyaya'
    ];
    const isPaanyaya = paanyayaStatuses.includes(complaint.status || '') ||
                       Boolean(complaint.resolved_without_docketing) ||
                       (typeof complaint.status === 'string' && complaint.status.toLowerCase().includes('paanyaya'));

    const docStatus = complaint.document_status || (complaint.current_scan ? 'Scan Uploaded' : 'No Scan Uploaded');
    const badgeEl = document.getElementById('kp7DocStatusBadge');
    if (badgeEl) {
        if (isPaanyaya) {
            badgeEl.className = 'badge-doc-status badge-doc-pending';
            badgeEl.style.background = '#fef3c7';
            badgeEl.style.color = '#b45309';
            badgeEl.style.borderColor = '#fde68a';
            badgeEl.textContent = 'KP Form 7: On Hold (Pending Paanyaya Outcome)';
        } else {
            let badgeCls = 'badge-doc-no-scan';
            if (docStatus === 'Updated Scan Uploaded') badgeCls = 'badge-doc-updated';
            else if (docStatus === 'Scan Uploaded') badgeCls = 'badge-doc-uploaded';
            badgeEl.className = 'badge-doc-status ' + badgeCls;
            badgeEl.textContent = docStatus;
        }
    }

    const pillEl = document.getElementById('kp7ScanStatusPill');
    if (pillEl) {
        if (isPaanyaya) {
            pillEl.textContent = 'Held in Pre-Docketing';
            pillEl.style.background = '#fffbeb';
            pillEl.style.color = '#b45309';
        } else if (complaint.current_scan) {
            pillEl.textContent = `v${complaint.current_scan.version_number || 1} Uploaded`;
            pillEl.style.background = '#dcfce7';
            pillEl.style.color = '#15803d';
        } else {
            pillEl.textContent = 'No Scan';
            pillEl.style.background = '#f1f5f9';
            pillEl.style.color = '#64748b';
        }
    }

    const contentWrap = document.getElementById('kp7ScanContentWrap');
    const actionsWrap = document.getElementById('kp7ScanActionsWrap');
    const complaintId = complaint.complaint_id;

    if (!contentWrap) return;

    if (complaint.current_scan) {
        const scan = complaint.current_scan;
        const scanUrl = `../../../backend/api/complaints/scanned-document.php?id=${encodeURIComponent(complaintId)}`;
        const downloadUrl = `${scanUrl}&download=1`;
        const isImg = scan.file_type && scan.file_type.startsWith('image/');
        const sizeFormatted = scan.file_size ? (scan.file_size / 1024).toFixed(1) + ' KB' : '—';
        const dateFormatted = scan.uploaded_at ? formatDateReadable(scan.uploaded_at) : '—';

        contentWrap.innerHTML = `
            <div style="display: flex; gap: 12px; align-items: center; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px;">
                <div style="width: 44px; height: 44px; border-radius: 6px; overflow: hidden; background: #e2e8f0; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    ${isImg
                        ? `<img src="${scanUrl}" style="width: 100%; height: 100%; object-fit: cover;" alt="Scan">`
                        : `<span style="font-weight: 700; font-size: 0.78rem; color: #dc2626;">PDF</span>`
                    }
                </div>
                <div style="min-width: 0; flex: 1;">
                    <div style="font-weight: 600; font-size: 0.88rem; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${escapeHtml(scan.file_name)}">
                        ${escapeHtml(scan.file_name)}
                    </div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                        <span>Version ${scan.version_number}</span> &bull;
                        <span>${sizeFormatted}</span> &bull;
                        <span>Uploaded by ${escapeHtml(scan.uploader_name || 'Staff')} (${dateFormatted})</span>
                    </div>
                    ${scan.replacement_reason ? `<div style="font-size: 0.74rem; color: #475569; font-style: italic; margin-top: 3px;">"${escapeHtml(scan.replacement_reason)}"</div>` : ''}
                </div>
            </div>
        `;

        if (actionsWrap) {
            actionsWrap.innerHTML = `
                <a href="${scanUrl}" target="_blank" class="btn-outline-sm" title="View scan in new tab">
                    👁️ View Scan
                </a>
                <a href="${downloadUrl}" class="btn-outline-sm" title="Download scan">
                    📥 Download
                </a>
                <button type="button" class="btn-outline-sm" onclick="openUploadScanModal()" title="Upload a clearer or corrected copy">
                    🔄 Replace Scan
                </button>
                <button type="button" class="btn-outline-sm" onclick="openScanHistoryModal(${complaintId})" title="View upload and replacement history">
                    📜 Version History (${(complaint.scan_history && complaint.scan_history.length) || 1})
                </button>
            `;
        }
    } else {
        if (isPaanyaya) {
            contentWrap.innerHTML = `
                <div style="padding: 12px; background: #fffbeb; border: 1px dashed #fde68a; border-radius: 8px; text-align: center; color: #92400e; font-size: 0.82rem;">
                    ⚖️ <strong>KP Form 7 is On Hold</strong> during the informal Paanyaya dialogue phase. If the dispute is settled informally, KP Form 7 is never created.
                </div>
            `;
        } else {
            contentWrap.innerHTML = `
                <div style="padding: 12px; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 8px; text-align: center; color: #64748b; font-size: 0.82rem;">
                    No handwritten or scanned KP Form 7 uploaded yet. You can attach a scanned copy anytime.
                </div>
            `;
        }
        if (actionsWrap) {
            actionsWrap.innerHTML = `
                <button type="button" class="btn-outline-sm" onclick="openUploadScanModal()">
                    📤 Upload Handwritten Scan
                </button>
            `;
        }
    }
}
window.renderKp7Overview = renderKp7Overview;

function openKp7DigitalPreviewModal(complaintId) {
    const activeComp = window.complaintData;
    const paanyayaStatuses = [
        'Pre-Docketed (Paanyaya)',
        '1st Paanyaya Failed (No-Show)',
        'Paanyaya In Progress',
        'Resolved at Paanyaya / Closed',
        'Paanyaya'
    ];
    if (activeComp && (paanyayaStatuses.includes(activeComp.status || '') || Boolean(activeComp.resolved_without_docketing) || (typeof activeComp.status === 'string' && activeComp.status.toLowerCase().includes('paanyaya')))) {
        window.agapNotify?.('KP Form 7 is on hold pending the outcome of the Paanyaya informal dialogue.', 'info');
        return;
    }
    const modal = document.getElementById('kp7DigitalPreviewModal');
    const iframe = document.getElementById('kp7PreviewIframe');
    if (!modal || !iframe) return;
    iframe.src = `../../../backend/api/complaints/kp7-preview.php?id=${encodeURIComponent(complaintId)}&_t=${Date.now()}`;
    modal.style.display = 'flex';
}
window.openKp7DigitalPreviewModal = openKp7DigitalPreviewModal;

function closeKp7DigitalPreviewModal() {
    const modal = document.getElementById('kp7DigitalPreviewModal');
    const iframe = document.getElementById('kp7PreviewIframe');
    if (iframe) iframe.src = '';
    if (modal) modal.style.display = 'none';
}
window.closeKp7DigitalPreviewModal = closeKp7DigitalPreviewModal;

function printKp7Iframe() {
    const iframe = document.getElementById('kp7PreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}
window.printKp7Iframe = printKp7Iframe;

function openUploadScanModal() {
    const modal = document.getElementById('kp7UploadScanModal');
    const errBox = document.getElementById('scanUploadError');
    const details = document.getElementById('scanFileDetails');
    const form = document.getElementById('uploadScanForm');
    if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }
    if (details) { details.style.display = 'none'; details.textContent = ''; }
    if (form) form.reset();
    if (modal) modal.style.display = 'flex';
}
window.openUploadScanModal = openUploadScanModal;

function closeUploadScanModal() {
    const modal = document.getElementById('kp7UploadScanModal');
    if (modal) modal.style.display = 'none';
}
window.closeUploadScanModal = closeUploadScanModal;

async function submitScanUpload(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitScanUpload');
    const errBox = document.getElementById('scanUploadError');
    const fileInput = document.getElementById('scanFileInput');

    if (!fileInput || !fileInput.files || !fileInput.files.length) {
        if (errBox) {
            errBox.textContent = 'Please choose a scan file to upload.';
            errBox.style.display = 'block';
        }
        return;
    }

    const file = fileInput.files[0];
    const maxBytes = 25 * 1024 * 1024;
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['pdf', 'jpg', 'jpeg', 'png'].includes(ext)) {
        if (errBox) {
            errBox.textContent = `File extension .${ext} is not allowed. Only PDF, JPG, JPEG, and PNG files are accepted.`;
            errBox.style.display = 'block';
        }
        return;
    }
    if (file.size > maxBytes) {
        if (errBox) {
            errBox.textContent = `File size exceeds 25 MB limit (${(file.size / (1024*1024)).toFixed(1)} MB).`;
            errBox.style.display = 'block';
        }
        return;
    }

    const formData = new FormData(form);
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Uploading...';
    }

    try {
        const resp = await fetch('../../../backend/api/complaints/scanned-document.php', {
            method: 'POST',
            body: formData
        });
        const result = await resp.json();
        if (!resp.ok || !result.success) {
            throw new Error(result.message || 'Upload failed.');
        }

        closeUploadScanModal();
        window.agapNotify?.(result.message || 'Scanned KP Form 7 uploaded successfully.', 'success');
        await loadComplaintDetails();
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'An error occurred during upload.';
            errBox.style.display = 'block';
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Upload Document';
        }
    }
}
window.submitScanUpload = submitScanUpload;

async function openScanHistoryModal(complaintId) {
    const modal = document.getElementById('kp7ScanHistoryModal');
    const body = document.getElementById('scanHistoryBody');
    if (!modal || !body) return;

    const safeComplaintId = Number(complaintId);
    if (!Number.isSafeInteger(safeComplaintId) || safeComplaintId < 1) {
        body.textContent = 'A valid complaint is required to view version history.';
        modal.style.display = 'flex';
        return;
    }

    modal.style.display = 'flex';
    body.innerHTML = '<div style="text-align: center; color: #64748b; padding: 20px;">Loading version history...</div>';

    try {
        const resp = await fetch(`../../../backend/api/complaints/scanned-document.php?id=${safeComplaintId}&action=history&_t=${Date.now()}`);
        const result = await resp.json();

        if (!resp.ok || !result.success || !result.history || !result.history.length) {
            body.innerHTML = '<div style="text-align: center; color: #64748b; padding: 20px;">No version history records found.</div>';
            return;
        }

        let html = '<div style="display: flex; flex-direction: column; gap: 10px;">';
        result.history.forEach(item => {
            const isCurrent = Number(item.is_current) === 1;
            const versionNumber = Number(item.version_number);
            if (!Number.isSafeInteger(versionNumber) || versionNumber < 1) {
                throw new Error('The document history contains an invalid version number.');
            }
            const sizeStr = item.file_size ? (item.file_size / 1024).toFixed(1) + ' KB' : '';
            const downloadUrl = `../../../backend/api/complaints/scanned-document.php?id=${safeComplaintId}&version=${versionNumber}&download=1`;

            html += `
                <div style="background: ${isCurrent ? '#f0fdf4' : '#f8fafc'}; border: 1px solid ${isCurrent ? '#86efac' : '#e2e8f0'}; border-radius: 8px; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <strong style="color: #0f172a; font-size: 0.9rem;">Version ${versionNumber}</strong>
                            ${isCurrent ? '<span style="background: #dcfce7; color: #15803d; font-size: 0.72rem; font-weight: 700; padding: 1px 6px; border-radius: 4px;">CURRENT</span>' : '<span style="background: #f1f5f9; color: #64748b; font-size: 0.72rem; font-weight: 700; padding: 1px 6px; border-radius: 4px;">ARCHIVED</span>'}
                            <span style="font-size: 0.8rem; color: #64748b;">${escapeHtml(item.file_name)} (${sizeStr})</span>
                        </div>
                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 3px;">
                            Uploaded by <strong>${escapeHtml(item.uploader_name || 'Staff')}</strong> on ${formatDateTimeReadable(item.uploaded_at)}
                        </div>
                        ${item.replacement_reason ? `<div style="font-size: 0.75rem; color: #475569; font-style: italic; margin-top: 2px;">Note: "${escapeHtml(item.replacement_reason)}"</div>` : ''}
                    </div>
                    <div style="flex-shrink: 0;">
                        <a href="${downloadUrl}" class="btn-outline-sm" style="font-size: 0.78rem;" download title="Download this version">
                            📥 Download
                        </a>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        body.innerHTML = html;
    } catch (err) {
        body.innerHTML = `<div style="color: #dc2626; padding: 20px; text-align: center;">Error loading history: ${escapeHtml(err.message)}</div>`;
    }
}
window.openScanHistoryModal = openScanHistoryModal;

function closeScanHistoryModal() {
    const modal = document.getElementById('kp7ScanHistoryModal');
    if (modal) modal.style.display = 'none';
}
window.closeScanHistoryModal = closeScanHistoryModal;

// ==========================================================================
// CASE SUMMARY & EXECUTIVE BRIEFING
// ==========================================================================
function getStatusBadgeClass(status) {
    const s = String(status || '').toLowerCase();
    if (s.includes('settled')) return 'status-settled';
    if (s.includes('conciliation')) return 'status-conciliation';
    if (s.includes('mediation')) return 'status-mediation';
    if (s.includes('arbitration')) return 'status-arbitration';
    if (s.includes('cfa')) return 'status-cfa';
    if (s.includes('dismissed')) return 'status-dismissed';
    if (s.includes('archived')) return 'status-archived';
    if (s.includes('docketed')) return 'status-docketed';
    return 'status-default';
}

function renderCaseSummary(data) {
    if (!data) return;
    const caseWorkspace = data.case_workspace || {};
    const caseItem = caseWorkspace.case || {};
    const hearings = caseWorkspace.hearings || [];
    const documents = caseWorkspace.documents || [];
    const parties = data.parties || [];

    // Title & Header
    const formatDocketDisplay = (num, id, dt) => {
        if (num && !String(num).startsWith('CMP-')) return String(num);
        const d = dt ? new Date(dt) : new Date();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const ss = String(id || 1).padStart(2, '0');
        const yy = d.getFullYear() || 2026;
        return `${mm}-${ss}-${yy}`;
    };
    const docketNum = formatDocketDisplay(caseItem.case_number || data.case_number || data.complaint_number, data.complaint_id, data.created_at);
    setText('summaryCaseTitle', data.complaint_title || 'Untitled Case');
    setText('summaryCaseNumber', docketNum ? ('Case #' + docketNum) : 'Not assigned');
    setText('summaryCategory', data.category_name || (data.category_id ? 'Category #' + data.category_id : 'General Dispute'));
    setText('summaryDateFiled', data.created_at ? formatDateReadable(data.created_at) : '—');

    const incTime = data.incident_time ? formatTime12(data.incident_time) : '';
    const incDate = data.incident_date ? formatDateReadable(data.incident_date) : 'N/A';
    const incDateTime = (incDate !== 'N/A' && incTime && incTime !== 'Not recorded') ? `${incDate} at ${incTime}` : incDate;
    setText('summaryIncidentDate', incDate);
    setText('summaryIncidentDateTime', incDateTime);

    const timer = caseWorkspace.case?.mediation_timer;
    const deadlineText = timer ? (timer.days_remaining !== undefined ? `${timer.days_remaining} days remaining` : timer.badge_label) : (caseItem.mediation_deadline_date ? formatDateReadable(caseItem.mediation_deadline_date) : 'Within 15 days of filing');
    setText('summaryDeadline', deadlineText);
    setText('summaryHearingsCount', `${hearings.length} session${hearings.length === 1 ? '' : 's'}`);
    setText('summaryHearingsBadge', `${hearings.length} recorded`);
    setText('summaryKpDocsBadge', `${documents.length} document${documents.length === 1 ? '' : 's'}`);

    const caseStatus = caseItem.case_status || data.lifecycle_status || 'Under Review';
    const statusBadge = document.getElementById('summaryStatusBadge');
    if (statusBadge) {
        statusBadge.textContent = caseStatus;
        statusBadge.className = `status-badge ${getStatusBadgeClass(caseStatus)}`;
    }
    const typeBadge = document.getElementById('summaryCaseTypeBadge');
    if (typeBadge) {
        typeBadge.textContent = data.case_type || 'Civil';
        typeBadge.className = `type-pill ${data.case_type === 'Criminal' ? 'type-criminal' : 'type-civil'}`;
    }

    // Location & Presider
    const locAddress = data.incident_location || (data.location && data.location.address) || 'Not specified';
    const locLandmark = data.incident_landmark || 'None';
    setText('summaryLocationAddress', locAddress);
    setText('summaryLocationLandmark', locLandmark);
    const lat = data.location && data.location.latitude ? parseFloat(data.location.latitude) : null;
    const lng = data.location && data.location.longitude ? parseFloat(data.location.longitude) : null;
    setText('summaryCoordinates', (lat && lng) ? `${lat.toFixed(6)}, ${lng.toFixed(6)}` : 'None');
    setText('summaryPresidingOfficer', caseItem.presiding_officer_name || 'Punong Barangay');

    // Parties
    const compContainer = document.getElementById('summaryComplainantsList');
    const respContainer = document.getElementById('summaryRespondentsList');
    setText('summaryPartiesCount', `${parties.length} part${parties.length === 1 ? 'y' : 'ies'}`);

    const complainants = parties.filter(p => p.party_type === 'Complainant');
    const respondents = parties.filter(p => p.party_type === 'Respondent');

    if (compContainer) {
        compContainer.replaceChildren();
        if (complainants.length === 0) {
            compContainer.innerHTML = '<p class="empty-text">No complainant recorded.</p>';
        } else {
            complainants.forEach(p => {
                const item = document.createElement('div');
                item.className = 'summary-party-item';
                const name = [p.first_name, p.middle_name, p.last_name].filter(Boolean).join(' ');
                const contact = p.contact_no || p.phone || 'No phone recorded';
                const addr = p.address || 'Address unrecorded';
                item.innerHTML = `<strong>${escapeHtml(name)}</strong><span>${escapeHtml(addr)} &bull; ${escapeHtml(contact)}</span>`;
                compContainer.appendChild(item);
            });
        }
    }

    if (respContainer) {
        respContainer.replaceChildren();
        if (respondents.length === 0) {
            respContainer.innerHTML = '<p class="empty-text">No respondent recorded.</p>';
        } else {
            respondents.forEach(p => {
                const item = document.createElement('div');
                item.className = 'summary-party-item';
                const name = [p.first_name, p.middle_name, p.last_name].filter(Boolean).join(' ');
                const contact = p.contact_no || p.phone || 'No phone recorded';
                const addr = p.address || 'Address unrecorded';
                item.innerHTML = `<strong>${escapeHtml(name)}</strong><span>${escapeHtml(addr)} &bull; ${escapeHtml(contact)}</span>`;
                respContainer.appendChild(item);
            });
        }
    }

    // Narrative & Facts
    const narrEl = document.getElementById('summaryNarrativeText');
    if (narrEl) narrEl.textContent = data.narrative || 'No statement of complaint recorded.';

    const addWrap = document.getElementById('summaryAdditionalWrap');
    const addEl = document.getElementById('summaryAdditionalText');
    if (addWrap && addEl) {
        if (data.additional_details && data.additional_details.trim()) {
            addWrap.style.display = 'block';
            addEl.textContent = data.additional_details;
        } else {
            addWrap.style.display = 'none';
        }
    }

    const relEl = document.getElementById('summaryReliefText');
    if (relEl) {
        relEl.textContent = (data.requested_resolution && data.requested_resolution.trim()) ? data.requested_resolution : 'No specific relief or settlement terms recorded.';
    }

    // Hearings list
    const hearingsContainer = document.getElementById('summaryHearingsList');
    if (hearingsContainer) {
        hearingsContainer.replaceChildren();
        if (!hearings.length) {
            hearingsContainer.innerHTML = '<p class="empty-text">No mediation or conciliation sessions scheduled yet.</p>';
        } else {
            hearings.forEach(h => {
                const item = document.createElement('div');
                item.className = 'summary-log-item';
                const hDate = h.hearing_date ? formatDateReadable(h.hearing_date) + ' ' + (formatTime12(h.hearing_date.split(' ')[1] || '') || '') : 'Date unrecorded';
                item.innerHTML = `
                    <div class="log-item-header">
                        <strong>${escapeHtml(h.hearing_type || 'Session')}</strong>
                        <span class="status-badge ${getStatusBadgeClass(h.status)}">${escapeHtml(h.status || 'Scheduled')}</span>
                    </div>
                    <div class="log-item-meta">
                        <span>📅 ${escapeHtml(hDate)}</span> &bull; <span>📍 ${escapeHtml(h.venue || 'Barangay Hall')}</span>
                    </div>
                `;
                hearingsContainer.appendChild(item);
            });
        }
    }

    // Documents list
    const docsContainer = document.getElementById('summaryKpDocsList');
    if (docsContainer) {
        docsContainer.replaceChildren();
        if (!documents.length) {
            docsContainer.innerHTML = '<p class="empty-text">No official KP documents generated for this case yet.</p>';
        } else {
            documents.forEach(doc => {
                const item = document.createElement('div');
                item.className = 'summary-log-item';
                const docDate = doc.generated_at ? formatDateReadable(doc.generated_at) : '—';
                item.innerHTML = `
                    <div class="log-item-header">
                        <strong>${escapeHtml(doc.template_name || 'KP Form')}</strong>
                        <span class="status-badge ${doc.service_status === 'Served' ? 'status-settled' : 'status-docketed'}">${escapeHtml(doc.service_status || 'Generated')}</span>
                    </div>
                    <div class="log-item-meta">
                        <span>Issued: ${escapeHtml(docDate)}</span>
                    </div>
                `;
                docsContainer.appendChild(item);
            });
        }
    }
}
window.renderCaseSummary = renderCaseSummary;

function formatAiSummaryHtml(text) {
    if (!text) return '';
    let html = escapeHtml(text);
    html = html.replace(/^###\s*(.+)$/gm, '<strong class="brief-heading">📌 $1</strong>');
    html = html.replace(/^##\s*(.+)$/gm, '<strong class="brief-heading">📌 $1</strong>');
    html = html.replace(/^([0-9]+\.\s+[A-Z\s&]+)$/gm, '<strong class="brief-heading">📌 $1</strong>');
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/^[\*\-\•]\s+(.+)$/gm, '• $1');
    return html;
}

async function generateAiCaseBrief() {
    const consentCheck = document.getElementById('aiBriefConsentCheck');
    if (consentCheck && !consentCheck.checked) {
        window.agapNotify?.('Please confirm consent to allow processing by Google Gemini.', 'error');
        return;
    }

    const complaintId = new URLSearchParams(window.location.search).get('id');
    if (!complaintId) return;

    const btn = document.getElementById('btnGenerateAiBrief');
    const spinner = document.getElementById('aiBriefBtnSpinner');
    const btnText = document.getElementById('aiBriefBtnText');
    const outputContainer = document.getElementById('aiBriefOutputContainer');

    if (btn) btn.disabled = true;
    if (spinner) spinner.style.display = 'inline';
    if (btnText) btnText.textContent = 'Compiling Brief...';

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const response = await fetch('../../../backend/api/ai/case-summary.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                complaint_id: parseInt(complaintId, 10),
                external_ai_consent: true
            })
        });

        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to generate AI case summary.');
        }

        if (outputContainer) {
            outputContainer.innerHTML = `
                <div class="ai-brief-content">
                    <div class="ai-brief-toolbar">
                        <span class="ai-brief-tag">${result.is_fallback ? '📋 Structured Case Brief' : '⚡ Gemini AI Legal Briefing'}</span>
                        <span class="ai-brief-timestamp">Generated on ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
                    </div>
                    <div class="ai-brief-body">${formatAiSummaryHtml(result.summary)}</div>
                </div>
            `;
        }
        window.agapNotify?.('Case Brief successfully compiled.', 'success');
    } catch (err) {
        console.error('AI brief error:', err);
        window.agapNotify?.(err.message || 'Failed to generate AI brief.', 'error');
    } finally {
        if (btn) btn.disabled = false;
        if (spinner) spinner.style.display = 'none';
        if (btnText) btnText.textContent = 'Regenerate AI Brief';
    }
}
window.generateAiCaseBrief = generateAiCaseBrief;

function copyCaseSummaryText() {
    const title = document.getElementById('summaryCaseTitle')?.textContent || '';
    const caseNum = document.getElementById('summaryCaseNumber')?.textContent || '';
    const cat = document.getElementById('summaryCategory')?.textContent || '';
    const dateFiled = document.getElementById('summaryDateFiled')?.textContent || '';
    const incident = document.getElementById('summaryIncidentDateTime')?.textContent || '';
    const address = document.getElementById('summaryLocationAddress')?.textContent || '';
    const narrative = document.getElementById('summaryNarrativeText')?.textContent || '';
    const relief = document.getElementById('summaryReliefText')?.textContent || '';
    const aiBrief = document.querySelector('.ai-brief-body')?.textContent || '';

    const textToCopy = `=== BARANGAY CASE SUMMARY ===
${caseNum}: ${title}
Category: ${cat} | Filed: ${dateFiled}
Incident: ${incident} (${address})

STATEMENT OF COMPLAINT:
${narrative}

RELIEF DEMANDED:
${relief}
${aiBrief ? `\nEXECUTIVE BRIEFING:\n${aiBrief}` : ''}
=============================`;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(textToCopy).then(() => {
            window.agapNotify?.('Case summary copied to clipboard.', 'success');
        }).catch(() => {
            window.agapNotify?.('Unable to copy to clipboard.', 'error');
        });
    } else {
        const textarea = document.createElement('textarea');
        textarea.value = textToCopy;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        window.agapNotify?.('Case summary copied to clipboard.', 'success');
    }
}
window.copyCaseSummaryText = copyCaseSummaryText;

function printCaseSummary() {
    window.print();
}
window.printCaseSummary = printCaseSummary;

// ── Paanyaya Pre-Docketing Workflow Functions ──

window.openPaanyayaPreviewModal = function() {
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id') || params.get('complaint_id') || window.complaintData?.complaint_id;
    if (!complaintId) return;

    const iframe = document.getElementById('paanyayaPreviewIframe');
    if (iframe) {
        iframe.src = `../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(complaintId)}&format=html&_t=${Date.now()}`;
    }
    const dlBtn = document.getElementById('btnModalDownloadPaanyaya');
    if (dlBtn) {
        dlBtn.href = `../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(complaintId)}&download=1`;
    }

    const modal = document.getElementById('paanyayaPreviewModal');
    if (modal) modal.style.display = 'flex';
};

window.closePaanyayaPreviewModal = function() {
    const modal = document.getElementById('paanyayaPreviewModal');
    if (modal) modal.style.display = 'none';
    const iframe = document.getElementById('paanyayaPreviewIframe');
    if (iframe) iframe.src = 'about:blank';
};

window.printPaanyayaIframe = function() {
    const iframe = document.getElementById('paanyayaPreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
};

window.openPaanyayaScheduleModal = function() {
    const modal = document.getElementById('paanyayaScheduleModal');
    if (!modal) return;

    const p = window.complaintData?.paanyaya || {};
    const dateInput = document.getElementById('modalPaanyayaDate');
    const timeInput = document.getElementById('modalPaanyayaTime');
    const venueInput = document.getElementById('modalPaanyayaVenue');
    const salaysayInput = document.getElementById('modalPaanyayaSalaysay');
    const sigInput = document.getElementById('modalPaanyayaHasSig');

    if (dateInput) {
        dateInput.value = p.invitation_date || new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);
    }
    if (timeInput) {
        timeInput.value = p.invitation_time ? p.invitation_time.slice(0, 5) : '14:00';
    }
    if (venueInput) {
        venueInput.value = p.venue || 'Tanggapan ng Barangay Tumana';
    }
    if (salaysayInput) {
        salaysayInput.value = p.salaysay || window.complaintData?.narrative || '';
    }
    if (sigInput) {
        sigInput.checked = (p.has_e_signature !== undefined && p.has_e_signature !== null) ? Number(p.has_e_signature) === 1 : true;
    }

    const errBox = document.getElementById('paanyayaScheduleError');
    if (errBox) errBox.style.display = 'none';

    modal.style.display = 'flex';
};

window.closePaanyayaScheduleModal = function() {
    const modal = document.getElementById('paanyayaScheduleModal');
    if (modal) modal.style.display = 'none';
};

window.submitSchedulePaanyaya = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitPaanyayaSchedule');
    const errBox = document.getElementById('paanyayaScheduleError');

    const formData = new FormData(form);
    const payload = {
        complaint_id: formData.get('complaint_id'),
        invitation_date: formData.get('invitation_date'),
        invitation_time: formData.get('invitation_time'),
        venue: formData.get('venue'),
        salaysay: formData.get('salaysay'),
        has_e_signature: form.querySelector('input[name="has_e_signature"]').checked ? 1 : 0
    };

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Scheduling...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/schedule.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Paanyaya scheduled successfully!', 'success');
            closePaanyayaScheduleModal();
            await loadComplaintDetails();
            openPaanyayaPreviewModal();
        } else {
            throw new Error(res.message || 'Failed to schedule Paanyaya.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error scheduling Paanyaya.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error scheduling Paanyaya.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Issue Paanyaya';
        }
    }
};

window.openPaanyayaOutcomeModal = function(outcomeType) {
    const modal = document.getElementById('paanyayaOutcomeModal');
    if (!modal) return;

    const p = window.complaintData?.paanyaya || window.complaintData?.paanyaya_workspace?.latest_session;
    const paaId = p?.paanyaya_id || p?.session_id || p?.id;
    const compId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search).get('id'));

    if (!paaId && !compId) {
        window.agapNotify?.('No scheduled Paanyaya session or complaint found.', 'error');
        return;
    }

    const paIdInput = document.getElementById('outcomePaanyayaId');
    if (paIdInput) paIdInput.value = paaId || '';
    const compIdInput = document.getElementById('outcomeComplaintId');
    if (compIdInput) compIdInput.value = compId || '';
    document.getElementById('outcomeActionType').value = outcomeType;

    const modalTitle = document.getElementById('outcomeModalTitle');
    const modalIcon = document.getElementById('outcomeModalIcon');
    const resolvedNotice = document.getElementById('outcomeResolvedNotice');
    const escalateNotice = document.getElementById('outcomeEscalateNotice');
    const escalationSection = document.getElementById('escalationScheduleSection');
    const submitBtn = document.getElementById('btnSubmitPaanyayaOutcome');
    const errBox = document.getElementById('paanyayaOutcomeError');
    if (errBox) errBox.style.display = 'none';

    if (outcomeType === 'Resolved') {
        if (modalTitle) modalTitle.textContent = 'Record Informal Amicable Resolution';
        if (modalIcon) modalIcon.textContent = '✓';
        if (resolvedNotice) resolvedNotice.style.display = 'block';
        if (escalateNotice) escalateNotice.style.display = 'none';
        if (escalationSection) escalationSection.style.display = 'none';
        if (submitBtn) {
            submitBtn.textContent = 'Confirm Resolution & Close Complaint';
            submitBtn.style.background = '#16a34a';
            submitBtn.style.borderColor = '#16a34a';
        }
    } else {
        if (modalTitle) modalTitle.textContent = 'Escalate to Formal Docketing (KP Form 7 & 9)';
        if (modalIcon) modalIcon.textContent = '⚡';
        if (resolvedNotice) resolvedNotice.style.display = 'none';
        if (escalateNotice) escalateNotice.style.display = 'block';
        if (escalationSection) escalationSection.style.display = 'block';
        if (submitBtn) {
            submitBtn.textContent = 'Escalate & Issue Summons';
            submitBtn.style.background = '#dc2626';
            submitBtn.style.borderColor = '#dc2626';
        }

        // Configure next Tuesday date per Tumana mediation rules
        const medDateInput = document.getElementById('escalationMedDate');
        if (medDateInput) {
            const d = new Date();
            d.setDate(d.getDate() + 3);
            while (d.getDay() !== 2) {
                d.setDate(d.getDate() + 1);
            }
            medDateInput.value = d.toISOString().slice(0, 10);
            medDateInput.min = new Date().toISOString().slice(0, 10);
        }
    }

    modal.style.display = 'flex';
};

window.closePaanyayaOutcomeModal = function() {
    const modal = document.getElementById('paanyayaOutcomeModal');
    if (modal) modal.style.display = 'none';
};

window.submitPaanyayaOutcome = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitPaanyayaOutcome');
    const errBox = document.getElementById('paanyayaOutcomeError');

    const formData = new FormData(form);
    const compId = formData.get('complaint_id') || window.complaintData?.complaint_id || (new URLSearchParams(window.location.search).get('id'));
    const payload = {
        paanyaya_id: formData.get('paanyaya_id'),
        complaint_id: compId,
        outcome: formData.get('outcome'),
        resolution_notes: formData.get('resolution_notes'),
        mediation_date: formData.get('mediation_date'),
        mediation_time: formData.get('mediation_time'),
        venue: formData.get('venue')
    };

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Recording outcome...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/record-outcome.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Outcome recorded successfully!', 'success');
            closePaanyayaOutcomeModal();
            await loadComplaintDetails();
            if (payload.outcome === 'Escalate' && res.case_id) {
                if (typeof openSummonsPreviewModal === 'function') {
                    openSummonsPreviewModal(res.case_id, null, null, true);
                }
            }
        } else {
            throw new Error(res.message || 'Failed to record outcome.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error recording outcome.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error recording outcome.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Confirm Outcome';
        }
    }
};



// ===================================================================
// PAANYAYA 1 & 2 WORKFLOW & OFFICER'S RETURN INTERACTIVE HANDLERS
// ===================================================================

window.switchDeliveryHistoryView = function(stageType) {
    window.activePaanyayaHistoryStage = stageType;
    renderPaanyayaDeliveryHistoryTable(stageType);
};

window.renderPaanyayaDeliveryHistoryTable = function(stageType) {
    const stages = window.paanyayaStagesData || window.complaintData?.paanyaya_stages || {};
    const stage1 = stages.stage_1 || {};
    const stage2 = stages.stage_2 || {};

    const tab1Btn = document.getElementById('btnHistTabP1');
    const tab2Btn = document.getElementById('btnHistTabP2');
    if (tab1Btn) tab1Btn.classList.toggle('active', stageType === 'Paanyaya 1');
    if (tab2Btn) tab2Btn.classList.toggle('active', stageType === 'Paanyaya 2');

    const p1CountEl = document.getElementById('p1HistBadgeCount');
    const p2CountEl = document.getElementById('p2HistBadgeCount');
    if (p1CountEl) p1CountEl.textContent = stage1.attempts_count || 0;
    if (p2CountEl) p2CountEl.textContent = stage2.attempts_count || 0;

    const targetStage = (stageType === 'Paanyaya 2') ? stage2 : stage1;
    const attempts = targetStage.attempts || [];

    const table = document.getElementById('paanyayaDeliveryHistoryTable');
    const tbody = document.getElementById('paanyayaDeliveryHistoryTbody');
    const emptyState = document.getElementById('paanyayaDeliveryHistoryEmpty');

    if (!tbody) return;

    if (!attempts || attempts.length === 0) {
        if (table) table.style.display = 'none';
        if (emptyState) {
            emptyState.style.display = 'block';
            emptyState.innerHTML = `<p style="margin: 0; font-size: 0.9rem;">No delivery attempts recorded yet for <strong>${escapeHtml(stageType)}</strong>.</p>`;
        }
        tbody.innerHTML = '';
        return;
    }

    if (table) table.style.display = 'table';
    if (emptyState) emptyState.style.display = 'none';

    const esc = (s) => escapeHtml(s ?? '');

    tbody.innerHTML = attempts.map(att => {
        let outClass = 'outcome-other';
        if (att.delivery_outcome === 'Successfully delivered') outClass = 'outcome-delivered';
        else if (att.delivery_outcome === 'Recipient unavailable') outClass = 'outcome-unavailable';
        else if (att.delivery_outcome === 'Recipient refused to receive') outClass = 'outcome-refused';
        else if (att.delivery_outcome === 'Respondent not found at the address') outClass = 'outcome-not-found';
        else if (att.delivery_outcome === 'Incorrect or incomplete address') outClass = 'outcome-incorrect-addr';

        const rawDate = att.service_datetime ? new Date(att.service_datetime.replace(' ', 'T')) : null;
        const dtFormatted = rawDate && !isNaN(rawDate.getTime()) ?
            rawDate.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
            rawDate.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true }) :
            esc(att.service_datetime || '—');

        const proofBtn = att.proof_file_path ?
            `<button type="button" class="btn-case-outline" style="padding: 3px 8px; font-size: 0.76rem;" onclick="openPaanyayaProofLightbox('${esc(att.proof_file_path)}')">📷 View</button>` :
            `<span style="color: #94a3b8; font-size: 0.78rem;">None</span>`;

        const remarksText = att.remarks || att.unsuccessful_reason || '—';

        return `
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 10px 12px; font-weight: 700; color: #0f172a;">
                    <span class="badge-attempt">Attempt ${att.attempt_number} of 6</span>
                </td>
                <td style="padding: 10px 12px; white-space: nowrap; color: #334155; font-size: 0.82rem;">
                    ${dtFormatted}
                </td>
                <td style="padding: 10px 12px; color: #0f172a; font-weight: 600;">
                    ${esc(att.serving_officer_name || 'Serving Officer')}
                </td>
                <td style="padding: 10px 12px; color: #475569; max-width: 180px; font-size: 0.82rem;">
                    ${esc(att.service_address || '—')}
                </td>
                <td style="padding: 10px 12px;">
                    <span class="outcome-badge ${outClass}">${esc(att.delivery_outcome)}</span>
                </td>
                <td style="padding: 10px 12px; color: #475569; max-width: 180px; font-size: 0.82rem;">
                    ${esc(remarksText)}
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                    ${proofBtn}
                </td>
                <td style="padding: 10px 12px; text-align: center;">
                    <button type="button" class="btn-case-outline" style="padding: 3px 8px; font-size: 0.76rem;" onclick="viewOfficersReturnDetails(${att.id})">
                        📄 Return
                    </button>
                </td>
                <td style="padding: 10px 12px; color: #64748b; font-size: 0.78rem; white-space: nowrap;">
                    ${esc(att.recorded_by_name || 'Staff')}
                </td>
                <td style="padding: 10px 12px; text-align: right; white-space: nowrap;">
                    <button type="button" class="btn-case-outline" style="padding: 3px 8px; font-size: 0.76rem;" onclick="openEditOfficersReturnModal(${att.id})" title="Permitted administrative correction">
                        ✏️ Edit
                    </button>
                </td>
            </tr>
        `;
    }).join('');
};

window.openIssuePaanyayaModal = function(stageType) {
    const modal = document.getElementById('issuePaanyayaStageModal');
    if (!modal) return;

    const inputType = document.getElementById('issueStageType');
    const heading = document.getElementById('issueStageModalHeading');
    const sub = document.getElementById('issueStageModalSub');
    const dateInput = document.getElementById('issueStageDate');
    const timeInput = document.getElementById('issueStageTime');
    const venueInput = document.getElementById('issueStageVenue');
    const salaysayInput = document.getElementById('issueStageSalaysay');
    const errBox = document.getElementById('issueStageError');

    if (inputType) inputType.value = stageType;
    if (heading) heading.textContent = `Issue ${stageType}`;
    if (sub) sub.textContent = stageType === 'Paanyaya 1' ? 'First Preliminary Invitation (Informal Dialogue)' : 'Second Preliminary Invitation (Informal Dialogue)';

    // Compute default date: 3 weekdays ahead
    if (dateInput) {
        const d = new Date();
        let added = 0;
        while (added < 3) {
            d.setDate(d.getDate() + 1);
            if (d.getDay() !== 0 && d.getDay() !== 6) {
                added++;
            }
        }
        dateInput.value = d.toISOString().slice(0, 10);
        dateInput.min = new Date().toISOString().slice(0, 10);
    }
    if (timeInput) timeInput.value = '14:00';
    if (venueInput) venueInput.value = 'Tanggapan ng Barangay Tumana';
    if (salaysayInput) salaysayInput.value = window.complaintData?.narrative || '';
    if (errBox) errBox.style.display = 'none';

    modal.style.display = 'flex';
};

window.closeIssuePaanyayaStageModal = function() {
    const modal = document.getElementById('issuePaanyayaStageModal');
    if (modal) modal.style.display = 'none';
};

window.submitIssuePaanyayaStage = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitIssueStage');
    const errBox = document.getElementById('issueStageError');

    const formData = new FormData(form);
    const payload = {
        complaint_id: parseInt(formData.get('complaint_id'), 10),
        stage_type: formData.get('stage_type'),
        invitation_date: formData.get('invitation_date'),
        invitation_time: formData.get('invitation_time'),
        assigned_officer_id: formData.get('assigned_officer_id') ? parseInt(formData.get('assigned_officer_id'), 10) : null,
        venue: formData.get('venue'),
        salaysay: formData.get('salaysay'),
        has_e_signature: form.querySelector('input[name="has_e_signature"]').checked ? 1 : 0
    };

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Issuing Invitation...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/issue-stage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Invitation issued successfully!', 'success');
            closeIssuePaanyayaStageModal();
            await loadComplaintDetails();
            previewPaanyayaStageLetter(payload.stage_type);
        } else {
            throw new Error(res.message || 'Failed to issue invitation.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error issuing invitation.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error issuing invitation.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Issue Invitation & Generate Letter';
        }
    }
};

window.openRecordReturnModal = function(stageType) {
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id') || params.get('complaint_id') || window.complaintData?.complaint_id;
    if (complaintId) {
        window.location.href = `../gps/proof-service.php?complaint_id=${encodeURIComponent(complaintId)}&stage=${encodeURIComponent(stageType || 'Paanyaya 1')}&open_return=1`;
        return;
    }

    const modal = document.getElementById('recordOfficersReturnModal');
    if (!modal) return;

    const stages = window.paanyayaStagesData || window.complaintData?.paanyaya_stages || {};
    const targetStage = (stageType === 'Paanyaya 2') ? stages.stage_2 : stages.stage_1;
    const nextAttempt = targetStage?.next_attempt_number || (targetStage?.attempts_count ? targetStage.attempts_count + 1 : 1);

    const stageInput = document.getElementById('rorStageType');
    const stageBadge = document.getElementById('rorStageBadge');
    const attemptBadge = document.getElementById('rorAttemptBadge');
    const dtInput = document.getElementById('rorServiceDatetime');
    const officerSelect = document.getElementById('rorServingOfficer');
    const recipientInput = document.getElementById('rorRecipientName');
    const addressInput = document.getElementById('rorServiceAddress');
    const outcomeSelect = document.getElementById('rorDeliveryOutcome');
    const reasonWrap = document.getElementById('rorUnsuccessfulReasonWrap');
    const reasonInput = document.getElementById('rorUnsuccessfulReason');
    const remarksInput = document.getElementById('rorRemarks');
    const proofFile = document.getElementById('rorProofFile');
    const latInput = document.getElementById('rorLatitude');
    const lngInput = document.getElementById('rorLongitude');
    const gpsStatus = document.getElementById('rorGpsStatus');
    const errBox = document.getElementById('rorErrorBox');

    if (stageInput) stageInput.value = stageType;
    if (stageBadge) stageBadge.textContent = stageType;
    if (attemptBadge) attemptBadge.textContent = `Attempt ${nextAttempt} of 6`;

    // Default datetime to now (local timezone)
    if (dtInput) {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        dtInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    // Pre-fill officer from stage
    if (officerSelect && targetStage?.details?.assigned_officer_id) {
        officerSelect.value = targetStage.details.assigned_officer_id;
    }

    // Pre-fill recipient & address from complaint parties
    const respondents = window.complaintData?.parties ? window.complaintData.parties.filter(x => x.party_type === 'Respondent') : [];
    if (recipientInput) {
        const respName = respondents.map(r => r.full_name || `${r.first_name || ''} ${r.last_name || ''}`.trim()).filter(Boolean).join(', ');
        recipientInput.value = respName || '';
    }
    if (addressInput) {
        addressInput.value = respondents[0]?.address || respondents[0]?.purok || 'Barangay Tumana';
    }

    if (outcomeSelect) outcomeSelect.value = '';
    if (reasonWrap) reasonWrap.style.display = 'none';
    if (reasonInput) {
        reasonInput.value = '';
        reasonInput.required = false;
    }
    if (remarksInput) remarksInput.value = '';
    if (proofFile) proofFile.value = '';
    if (latInput) latInput.value = '';
    if (lngInput) lngInput.value = '';
    if (gpsStatus) gpsStatus.textContent = '';
    if (errBox) errBox.style.display = 'none';

    modal.style.display = 'flex';
};

window.closeRecordOfficersReturnModal = function() {
    const modal = document.getElementById('recordOfficersReturnModal');
    if (modal) modal.style.display = 'none';
};

window.handleRorOutcomeChange = function() {
    const outcomeSelect = document.getElementById('rorDeliveryOutcome');
    const reasonWrap = document.getElementById('rorUnsuccessfulReasonWrap');
    const reasonInput = document.getElementById('rorUnsuccessfulReason');
    if (!outcomeSelect || !reasonWrap || !reasonInput) return;

    if (outcomeSelect.value && outcomeSelect.value !== 'Successfully delivered') {
        reasonWrap.style.display = 'block';
        reasonInput.required = true;
    } else {
        reasonWrap.style.display = 'none';
        reasonInput.required = false;
    }
};

window.captureReturnGpsLocation = function() {
    const latInput = document.getElementById('rorLatitude');
    const lngInput = document.getElementById('rorLongitude');
    const gpsStatus = document.getElementById('rorGpsStatus');

    if (!navigator.geolocation) {
        if (gpsStatus) gpsStatus.textContent = 'Geolocation is not supported by your browser.';
        return;
    }

    if (gpsStatus) gpsStatus.textContent = 'Acquiring GPS coordinates...';

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            if (latInput) latInput.value = pos.coords.latitude.toFixed(6);
            if (lngInput) lngInput.value = pos.coords.longitude.toFixed(6);
            if (gpsStatus) {
                gpsStatus.textContent = `📍 Captured: ${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)} (Accuracy ±${Math.round(pos.coords.accuracy)}m)`;
                gpsStatus.style.color = '#15803d';
            }
        },
        (err) => {
            if (gpsStatus) {
                gpsStatus.textContent = 'Unable to retrieve location: ' + err.message;
                gpsStatus.style.color = '#dc2626';
            }
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
};

window.submitRecordOfficersReturn = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitRecordReturn');
    const errBox = document.getElementById('rorErrorBox');

    const formData = new FormData(form);

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving Return...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await fetch('../../../backend/api/paanyaya/record-return.php', {
            method: 'POST',
            body: formData
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Failed to record Officer’s Return.');
        }

        window.agapNotify?.(data.message || 'Officer’s Return recorded successfully!', 'success');
        closeRecordOfficersReturnModal();
        await loadComplaintDetails();
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error recording return.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error recording return.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Save Officer\'s Return';
        }
    }
};

window.viewOfficersReturnDetails = async function(returnId) {
    const modal = document.getElementById('viewOfficersReturnModal');
    const body = document.getElementById('vorContentBody');
    const editBtn = document.getElementById('btnVorEditCorrection');
    if (!modal || !body) return;

    body.innerHTML = '<div style="padding: 24px; text-align: center; color: #64748b;">Loading return details...</div>';
    modal.style.display = 'flex';

    try {
        const res = await fetch(`../../../backend/api/paanyaya/return-details.php?id=${encodeURIComponent(returnId)}`);
        const json = await res.json();

        if (!json.success || !json.data) {
            throw new Error(json.message || 'Unable to load return details.');
        }

        const d = json.data;
        const esc = (s) => escapeHtml(s ?? '');

        let outClass = 'outcome-other';
        if (d.delivery_outcome === 'Successfully delivered') outClass = 'outcome-delivered';
        else if (d.delivery_outcome === 'Recipient unavailable') outClass = 'outcome-unavailable';
        else if (d.delivery_outcome === 'Recipient refused to receive') outClass = 'outcome-refused';
        else if (d.delivery_outcome === 'Respondent not found at the address') outClass = 'outcome-not-found';
        else if (d.delivery_outcome === 'Incorrect or incomplete address') outClass = 'outcome-incorrect-addr';

        const dtFormatted = d.service_datetime ?
            new Date(d.service_datetime.replace(' ', 'T')).toLocaleString('en-PH', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }) :
            esc(d.service_datetime || '—');

        const proofSection = d.proof_file_path ? `
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 6px;">
                <strong style="font-size: 0.82rem; color: #475569; display: block; margin-bottom: 6px;">Proof of Service Attachment:</strong>
                <button type="button" class="btn-create" style="padding: 6px 14px; font-size: 0.82rem;" onclick="openPaanyayaProofLightbox('${esc(d.proof_file_path)}')">
                    📷 View Attached Proof Document
                </button>
            </div>
        ` : '<span style="color: #94a3b8; font-size: 0.84rem;">No proof file attached</span>';

        const gpsSection = (d.latitude && d.longitude) ? `
            <span>📍 ${esc(d.latitude)}, ${esc(d.longitude)} &bull; <a href="https://www.google.com/maps?q=${esc(d.latitude)},${esc(d.longitude)}" target="_blank" style="color: #2563eb; text-decoration: underline;">View on Map</a></span>
        ` : '<span style="color: #94a3b8;">No GPS coordinates recorded</span>';

        let auditHtml = '';
        if (d.corrections && d.corrections.length > 0) {
            auditHtml = `
                <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 12px; margin-top: 8px;">
                    <strong style="color: #92400e; font-size: 0.84rem; display: block; margin-bottom: 6px;">Correction Audit Trail:</strong>
                    ${d.corrections.map(c => `
                        <div style="font-size: 0.78rem; color: #78350f; border-bottom: 1px dashed #fde68a; padding: 4px 0;">
                            <strong>By ${esc(c.user_name || 'Staff')} on ${esc(c.created_at)}:</strong> ${esc(c.justification || c.details || 'Correction applied')}
                        </div>
                    `).join('')}
                </div>
            `;
        }

        body.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 12px 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div>
                    <span style="font-size: 0.82rem; font-weight: 700; color: #64748b; text-transform: uppercase;">${esc(d.stage_type)}</span>
                    <h3 style="margin: 2px 0 0; font-size: 1.1rem; color: #0f172a; font-weight: 800;">Attempt ${d.attempt_number} of 6</h3>
                </div>
                <span class="outcome-badge ${outClass}" style="font-size: 0.86rem; padding: 4px 12px;">${esc(d.delivery_outcome)}</span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 0.86rem;">
                <div><strong>Date & Time of Service:</strong><br><span style="color: #334155;">${dtFormatted}</span></div>
                <div><strong>Assigned Serving Officer:</strong><br><span style="color: #334155;">${esc(d.serving_officer_name || 'Unassigned')}</span></div>
                <div><strong>Intended Recipient:</strong><br><span style="color: #334155;">${esc(d.recipient_name || '—')}</span></div>
                <div><strong>Address Visited:</strong><br><span style="color: #334155;">${esc(d.service_address || '—')}</span></div>
            </div>

            ${d.unsuccessful_reason ? `
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; color: #991b1b;">
                    <strong>Reason for Unsuccessful Delivery:</strong>
                    <p style="margin: 4px 0 0;">${esc(d.unsuccessful_reason)}</p>
                </div>
            ` : ''}

            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.85rem;">
                <strong style="color: #475569;">Remarks / Detailed Explanation:</strong>
                <p style="margin: 6px 0 0; color: #1e293b; line-height: 1.5;">${esc(d.remarks || 'No detailed remarks recorded.')}</p>
            </div>

            <div style="font-size: 0.85rem;">
                <strong>GPS Coordinates:</strong> ${gpsSection}
            </div>

            <div>${proofSection}</div>

            <div style="font-size: 0.8rem; color: #64748b; padding-top: 8px; border-top: 1px solid #f1f5f9;">
                <strong>Recorded By:</strong> ${esc(d.recorded_by_name || 'Authorized Staff')} &bull; 
                <strong>Recorded At:</strong> ${esc(d.created_at || '—')}
            </div>

            ${auditHtml}
        `;

        if (editBtn) {
            editBtn.style.display = 'inline-flex';
            editBtn.setAttribute('data-return-id', returnId);
        }
    } catch (err) {
        body.innerHTML = `<div class="alert alert-danger" style="margin: 0;">${escapeHtml(err.message)}</div>`;
        if (editBtn) editBtn.style.display = 'none';
    }
};

window.closeViewOfficersReturnModal = function() {
    const modal = document.getElementById('viewOfficersReturnModal');
    if (modal) modal.style.display = 'none';
};

window.openEditFromViewReturn = function() {
    const editBtn = document.getElementById('btnVorEditCorrection');
    const returnId = editBtn?.getAttribute('data-return-id');
    closeViewOfficersReturnModal();
    if (returnId) openEditOfficersReturnModal(returnId);
};

window.openEditOfficersReturnModal = async function(returnId) {
    const modal = document.getElementById('editOfficersReturnModal');
    if (!modal) return;

    const idInput = document.getElementById('eorReturnId');
    const addrInput = document.getElementById('eorServiceAddress');
    const reasonInput = document.getElementById('eorUnsuccessfulReason');
    const remarksInput = document.getElementById('eorRemarks');
    const notesInput = document.getElementById('eorCorrectionNotes');
    const errBox = document.getElementById('eorErrorBox');

    if (idInput) idInput.value = returnId;
    if (notesInput) notesInput.value = '';
    if (errBox) errBox.style.display = 'none';

    try {
        const res = await fetch(`../../../backend/api/paanyaya/return-details.php?id=${encodeURIComponent(returnId)}`);
        const json = await res.json();
        if (json.success && json.data) {
            const d = json.data;
            if (addrInput) addrInput.value = d.service_address || '';
            if (reasonInput) reasonInput.value = d.unsuccessful_reason || '';
            if (remarksInput) remarksInput.value = d.remarks || '';
        }
    } catch (_) {}

    modal.style.display = 'flex';
};

window.closeEditOfficersReturnModal = function() {
    const modal = document.getElementById('editOfficersReturnModal');
    if (modal) modal.style.display = 'none';
};

window.submitEditOfficersReturn = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitEditReturn');
    const errBox = document.getElementById('eorErrorBox');

    const formData = new FormData(form);
    const payload = {
        return_id: parseInt(formData.get('return_id'), 10),
        service_address: formData.get('service_address'),
        unsuccessful_reason: formData.get('unsuccessful_reason'),
        remarks: formData.get('remarks'),
        correction_notes: formData.get('correction_notes')
    };

    if (!payload.correction_notes || !payload.correction_notes.trim()) {
        if (errBox) {
            errBox.textContent = 'Correction justification is required for the audit trail.';
            errBox.style.display = 'block';
        }
        return;
    }

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving Correction...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/update-return.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Correction saved successfully with audit record!', 'success');
            closeEditOfficersReturnModal();
            await loadComplaintDetails();
        } else {
            throw new Error(res.message || 'Failed to save correction.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error saving correction.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error saving correction.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Save Correction';
        }
    }
};

window.openPaanyayaReviewModal = function(stageType) {
    const modal = document.getElementById('reviewPaanyayaModal');
    if (!modal) return;

    const inputType = document.getElementById('rpmStageType');
    const sub = document.getElementById('rpmModalSub');
    const decisionSelect = document.getElementById('rpmDecision');
    const notesInput = document.getElementById('rpmReviewNotes');
    const errBox = document.getElementById('rpmErrorBox');

    if (inputType) inputType.value = stageType;
    if (sub) sub.textContent = `Review for ${stageType}`;
    if (decisionSelect) decisionSelect.value = '';
    if (notesInput) notesInput.value = '';
    if (errBox) errBox.style.display = 'none';

    modal.style.display = 'flex';
};

window.closePaanyayaReviewModal = function() {
    const modal = document.getElementById('reviewPaanyayaModal');
    if (modal) modal.style.display = 'none';
};

window.submitPaanyayaReview = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitReview');
    const errBox = document.getElementById('rpmErrorBox');

    const formData = new FormData(form);
    const payload = {
        complaint_id: parseInt(formData.get('complaint_id'), 10),
        stage_type: formData.get('stage_type'),
        decision: formData.get('decision'),
        review_notes: formData.get('review_notes')
    };

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Confirming Decision...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/review-stage.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Lupon review decision recorded successfully!', 'success');
            closePaanyayaReviewModal();
            await loadComplaintDetails();
        } else {
            throw new Error(res.message || 'Failed to record review decision.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error recording review decision.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error recording review decision.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Confirm Decision';
        }
    }
};

window.openPaanyayaProofLightbox = function(filePath) {
    const modal = document.getElementById('paanyayaProofLightboxModal');
    const img = document.getElementById('plbImage');
    const pdfWrap = document.getElementById('plbPdfContainer');
    const pdfLink = document.getElementById('plbPdfLink');
    const dlBtn = document.getElementById('plbDownloadBtn');
    if (!modal) return;

    const fullUrl = '../../../' + filePath.replace(/^\/+/, '');
    const isPdf = filePath.toLowerCase().endsWith('.pdf');

    if (isPdf) {
        if (img) img.style.display = 'none';
        if (pdfWrap) pdfWrap.style.display = 'block';
        if (pdfLink) pdfLink.href = fullUrl;
    } else {
        if (img) {
            img.style.display = 'inline-block';
            img.src = fullUrl;
        }
        if (pdfWrap) pdfWrap.style.display = 'none';
    }

    if (dlBtn) dlBtn.href = fullUrl;
    modal.style.display = 'flex';
};

window.closePaanyayaProofLightbox = function() {
    const modal = document.getElementById('paanyayaProofLightboxModal');
    if (modal) modal.style.display = 'none';
    const img = document.getElementById('plbImage');
    if (img) img.src = '';
};

window.previewPaanyayaStageLetter = function(stageType) {
    const complaintId = window.complaintData?.complaint_id || new URLSearchParams(window.location.search).get('id');
    if (!complaintId) return;

    const iframe = document.getElementById('paanyayaPreviewIframe');
    if (iframe) {
        iframe.src = `../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(complaintId)}&stage_type=${encodeURIComponent(stageType)}&format=html&_t=${Date.now()}`;
    }
    const dlBtn = document.getElementById('btnModalDownloadPaanyaya');
    if (dlBtn) {
        dlBtn.href = `../../../backend/api/paanyaya/preview.php?id=${encodeURIComponent(complaintId)}&stage_type=${encodeURIComponent(stageType)}&download=1`;
    }

    const modal = document.getElementById('paanyayaPreviewModal');
    if (modal) modal.style.display = 'flex';
};


window.proceedToOfficersReturnFromPreview = function() {
    if (typeof closePaanyayaPreviewModal === 'function') {
        closePaanyayaPreviewModal();
    }
    const params = new URLSearchParams(window.location.search);
    const complaintId = params.get('id') || params.get('complaint_id') || window.complaintData?.complaint_id;
    window.location.href = `../gps/proof-service.php?complaint_id=${encodeURIComponent(complaintId)}&stage=Paanyaya+1&open_return=1`;
};

// ===================================================================
// TEMPORARY MINUTES OF THE MEETING & COMPLAINANT DECISION HANDLERS
// ===================================================================

window.openPaanyayaMinutesModal = function(stageType = 'Paanyaya 1') {
    const modal = document.getElementById('paanyayaMinutesModal');
    if (!modal) return;

    const compId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search).get('id'));
    if (!compId) {
        window.agapNotify?.('Complaint ID not found.', 'error');
        return;
    }

    document.getElementById('minutesComplaintId').value = compId;
    document.getElementById('minutesStageType').value = stageType;

    const heading = document.getElementById('minutesModalHeading');
    const sub = document.getElementById('minutesModalSub');
    if (heading) heading.textContent = `Record Dialogue Minutes & Result (${stageType})`;
    if (sub) sub.textContent = (stageType === 'Paanyaya 1') 
        ? 'First Preliminary Invitation Dialogue Minutes' 
        : 'Second Preliminary Invitation Dialogue Minutes';

    // Set default dialogue date/time to now (local timezone)
    const dtInput = document.getElementById('minutesDialogueDate');
    if (dtInput) {
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        dtInput.value = now.toISOString().slice(0, 16);
    }

    // Reset inputs
    const form = document.getElementById('paanyayaMinutesForm');
    if (form) {
        const summary = document.getElementById('minutesDiscussionSummary');
        const terms = document.getElementById('minutesAgreedTerms');
        const file = document.getElementById('minutesScannedFile');
        if (summary) summary.value = '';
        if (terms) terms.value = '';
        if (file) file.value = '';

        // Reset radio buttons
        const compRadio = form.querySelector('input[name="complainant_attended"][value="Present"]');
        if (compRadio) compRadio.checked = true;
        const respRadio = form.querySelector('input[name="respondent_attended"][value="Present"]');
        if (respRadio) respRadio.checked = true;
        const outcomeRadio = form.querySelector('input[name="outcome"][value="Settled"]');
        if (outcomeRadio) outcomeRadio.checked = true;
        const choiceRadio = form.querySelector('input[name="complainant_choice"][value="Request_Paanyaya_2"]');
        if (choiceRadio) choiceRadio.checked = true;
    }

    // Configure next Tuesday date for mediation
    const medDateInput = document.getElementById('minutesMedDate');
    if (medDateInput) {
        const d = new Date();
        d.setDate(d.getDate() + 3);
        while (d.getDay() !== 2) {
            d.setDate(d.getDate() + 1);
        }
        medDateInput.value = d.toISOString().slice(0, 10);
        medDateInput.min = new Date().toISOString().slice(0, 10);
    }

    // For Paanyaya 2, Choice A is not applicable ("Dalawang Beses Lang" rule)
    const optChoiceP2Wrap = document.getElementById('optChoiceP2Wrap');
    if (optChoiceP2Wrap) {
        if (stageType === 'Paanyaya 2') {
            optChoiceP2Wrap.style.display = 'none';
            const escChoice = form.querySelector('input[name="complainant_choice"][value="Escalate_Mediation"]');
            if (escChoice) escChoice.checked = true;
        } else {
            optChoiceP2Wrap.style.display = 'flex';
        }
    }

    toggleMinutesOutcomeViews();
    toggleComplainantChoiceViews();

    const errBox = document.getElementById('paanyayaMinutesError');
    if (errBox) errBox.style.display = 'none';

    modal.style.display = 'flex';
};

window.closePaanyayaMinutesModal = function() {
    const modal = document.getElementById('paanyayaMinutesModal');
    if (modal) modal.style.display = 'none';
};

window.handleMinutesAttendanceChange = function() {
    const form = document.getElementById('paanyayaMinutesForm');
    if (!form) return;
    const respAttended = form.querySelector('input[name="respondent_attended"]:checked')?.value;
    const optSettledWrap = document.getElementById('optMinutesSettledWrap');
    if (respAttended === 'Absent') {
        const noAgreeRadio = form.querySelector('input[name="outcome"][value="No_Agreement"]');
        if (noAgreeRadio) noAgreeRadio.checked = true;
        if (optSettledWrap) {
            optSettledWrap.style.opacity = '0.5';
            optSettledWrap.style.pointerEvents = 'none';
        }
    } else {
        if (optSettledWrap) {
            optSettledWrap.style.opacity = '1';
            optSettledWrap.style.pointerEvents = '';
        }
    }
    toggleMinutesOutcomeViews();
};

window.toggleMinutesOutcomeViews = function() {
    const form = document.getElementById('paanyayaMinutesForm');
    if (!form) return;
    const outcome = form.querySelector('input[name="outcome"]:checked')?.value || 'Settled';
    const choiceSection = document.getElementById('minutesComplainantChoiceSection');
    const submitBtn = document.getElementById('btnSubmitPaanyayaMinutes');

    if (outcome === 'Settled') {
        if (choiceSection) choiceSection.style.display = 'none';
        const escSec = document.getElementById('minutesEscalationScheduleSection');
        if (escSec) escSec.style.display = 'none';
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Close Complaint (Nagkasundo)';
            submitBtn.style.background = '#16a34a';
            submitBtn.style.borderColor = '#16a34a';
        }
    } else {
        if (choiceSection) choiceSection.style.display = 'block';
        toggleComplainantChoiceViews();
    }
};

window.toggleComplainantChoiceViews = function() {
    const form = document.getElementById('paanyayaMinutesForm');
    if (!form) return;
    const outcome = form.querySelector('input[name="outcome"]:checked')?.value;
    const choice = form.querySelector('input[name="complainant_choice"]:checked')?.value || 'Request_Paanyaya_2';
    const escSec = document.getElementById('minutesEscalationScheduleSection');
    const submitBtn = document.getElementById('btnSubmitPaanyayaMinutes');

    if (outcome !== 'Settled' && choice === 'Escalate_Mediation') {
        if (escSec) escSec.style.display = 'block';
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Escalate to Mediation';
            submitBtn.style.background = '#dc2626';
            submitBtn.style.borderColor = '#dc2626';
        }
    } else {
        if (escSec) escSec.style.display = 'none';
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Unlock Paanyaya 2';
            submitBtn.style.background = '#2563eb';
            submitBtn.style.borderColor = '#2563eb';
        }
    }
};

window.submitPaanyayaMinutes = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = document.getElementById('btnSubmitPaanyayaMinutes');
    const errBox = document.getElementById('paanyayaMinutesError');

    const formData = new FormData(form);

    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving dialogue minutes...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await complaintApi('../../../backend/api/paanyaya/record-minutes.php', {
            method: 'POST',
            body: formData
        });

        if (res.success) {
            window.agapNotify?.(res.message || 'Dialogue minutes recorded successfully!', 'success');
            closePaanyayaMinutesModal();
            await loadComplaintDetails();
            if (res.action === 'escalate_mediation' && res.case_id) {
                if (typeof openSummonsPreviewModal === 'function') {
                    openSummonsPreviewModal(res.case_id, null, null, true);
                }
            }
        } else {
            throw new Error(res.message || 'Failed to record dialogue minutes.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'Error recording dialogue minutes.';
            errBox.style.display = 'block';
        } else {
            alert(err.message || 'Error recording dialogue minutes.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Save Minutes & Confirm Result';
        }
    }
};

window.viewPaanyayaMinutes = async function(stageType = 'Paanyaya 1') {
    const modal = document.getElementById('paanyayaMinutesViewModal');
    const body = document.getElementById('viewMinutesBody');
    if (!modal || !body) return;

    const compId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search).get('id'));
    if (!compId) return;

    body.innerHTML = '<p style="color: #64748b;">Loading dialogue minutes...</p>';
    modal.style.display = 'flex';

    try {
        const res = await complaintApi(`../../../backend/api/paanyaya/get-minutes.php?complaint_id=${compId}&stage_type=${encodeURIComponent(stageType)}`);
        const minutes = res.data;
        if (!minutes) {
            body.innerHTML = '<p style="color: #64748b;">No recorded minutes found for this stage.</p>';
            return;
        }

        const esc = (s) => String(s ?? '').replace(/[&<>'"]/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[c] || c));

        let outcomeBadge = '<span class="outcome-badge outcome-other">None</span>';
        if (minutes.outcome === 'Settled') {
            outcomeBadge = '<span class="outcome-badge outcome-delivered">✓ Nagkasundo / Amicably Settled</span>';
        } else if (minutes.complainant_choice === 'Request_Paanyaya_2') {
            outcomeBadge = '<span class="outcome-badge outcome-unavailable">⚡ Walang Kasunduan – Humiling ng 2nd Paanyaya</span>';
        } else if (minutes.complainant_choice === 'Escalate_Mediation') {
            outcomeBadge = '<span class="outcome-badge outcome-refused">⚡ Inakyat sa Pormal na Mediation</span>';
        }

        let fileAttachmentHtml = '<span style="color: #94a3b8; font-style: italic;">Walang naka-attach na dokumento</span>';
        if (minutes.scanned_minutes_path) {
            fileAttachmentHtml = `<a href="../../../${esc(minutes.scanned_minutes_path)}" target="_blank" style="color: #2563eb; text-decoration: underline; font-weight: 600;">📎 Tingnan ang Naka-attach na Katitikan / Larawan</a>`;
        }

        body.innerHTML = `
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 0.82rem; font-weight: 700; color: #1e293b;">${esc(minutes.stage_type)}</span>
                    <span style="font-size: 0.78rem; color: #64748b;">Itinala noong: ${formatDateReadable(minutes.dialogue_date || minutes.created_at)}</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.83rem;">
                    <div><strong>Nagpaanyaya:</strong> <span style="color: ${minutes.complainant_attended === 'Present' ? '#166534' : '#991b1b'};">${minutes.complainant_attended === 'Present' ? '✓ Dumalo' : '✗ Hindi Dumalo'}</span></div>
                    <div><strong>Inanyayahan:</strong> <span style="color: ${minutes.respondent_attended === 'Present' ? '#166534' : '#991b1b'};">${minutes.respondent_attended === 'Present' ? '✓ Dumalo' : '✗ Hindi Dumalo'}</span></div>
                    <div><strong>Nagtala:</strong> <span>${esc(minutes.recorded_by_name || 'Opisyal ng Barangay')}</span></div>
                    <div><strong>Kinalabasan:</strong> ${outcomeBadge}</div>
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <strong style="font-size: 0.85rem; color: #0f172a; display: block; margin-bottom: 4px;">Salaysay at Buod ng Napag-usapan:</strong>
                <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 0.85rem; color: #334155; white-space: pre-wrap;">${esc(minutes.discussion_summary)}</div>
            </div>

            <div style="margin-bottom: 14px;">
                <strong style="font-size: 0.85rem; color: #0f172a; display: block; margin-bottom: 4px;">Mga Mungkahi o Napagkasunduang Kondisyon:</strong>
                <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 0.85rem; color: #334155; white-space: pre-wrap;">${esc(minutes.agreed_terms || 'Walang karagdagang kondisyon na itinala.')}</div>
            </div>

            <div style="margin-top: 10px;">
                <strong style="font-size: 0.85rem; color: #0f172a; display: block; margin-bottom: 4px;">Kalakip na Dokumento (Scanned Copy):</strong>
                ${fileAttachmentHtml}
            </div>
        `;
    } catch (err) {
        body.innerHTML = `<p style="color: #dc2626;">Error loading minutes: ${err.message || 'Unknown error'}</p>`;
    }
};

window.closePaanyayaMinutesViewModal = function() {
    const modal = document.getElementById('paanyayaMinutesViewModal');
    if (modal) modal.style.display = 'none';
};

// =========================================================
// Reopen Settled Case for CFA — RA 7160 Sec. 417
// =========================================================
window.openReopenCfaModal = function() {
    const modal = document.getElementById('modalReopenForCfa');
    if (modal) {
        modal.style.display = 'flex';
        const ta = document.getElementById('reopenCfaReason');
        if (ta) ta.value = '';
    }
};

window.closeReopenCfaModal = function() {
    const modal = document.getElementById('modalReopenForCfa');
    if (modal) modal.style.display = 'none';
};

window.submitReopenForCfa = async function() {
    const reason = (document.getElementById('reopenCfaReason')?.value || '').trim();
    if (!reason) {
        alert('Please describe the non-compliance reason before proceeding.');
        return;
    }

    const caseId = window._reopenCaseCaseId;
    if (!caseId) {
        alert('Case ID not found. Please reload the page.');
        return;
    }

    const confirmBtn = document.getElementById('btnReopenCfaConfirm');
    const cancelBtn = document.getElementById('btnReopenCfaCancel');
    if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = 'Processing…'; }
    if (cancelBtn) cancelBtn.disabled = true;

    try {
        const formData = new FormData();
        formData.append('case_id', caseId);
        formData.append('reason', reason);

        const res = await fetch('../../../backend/api/cases/reopen.php', { method: 'POST', body: formData });
        const json = await res.json();

        if (json.success) {
            window.closeReopenCfaModal();
            if (window.agapNotify) window.agapNotify(json.message, 'success');
            // Brief pause then redirect to CFA page
            setTimeout(() => {
                if (json.cfa_url) {
                    window.location.href = json.cfa_url;
                } else {
                    window.location.reload();
                }
            }, 1200);
        } else {
            alert(json.message || 'Unable to reopen case. Please try again.');
            if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = '⚠️ Reopen &amp; Issue CFA'; }
            if (cancelBtn) cancelBtn.disabled = false;
        }
    } catch (err) {
        alert('Network error. Please try again.');
        if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = '⚠️ Reopen &amp; Issue CFA'; }
        if (cancelBtn) cancelBtn.disabled = false;
    }
};
