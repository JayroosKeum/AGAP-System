function getOrdinal(n) {
    const s = ["th", "st", "nd", "rd"];
    const v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
}
function getSessionOrdinalLabel(stageType, index) {
    const idx = parseInt(index, 10) || 1;
    const ord = getOrdinal(idx);
    const stage = (stageType === 'Conciliation') ? 'Conciliation' : 'Mediation';
    return `${ord} ${stage}`;
}
window.getSessionOrdinalLabel = getSessionOrdinalLabel;

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
        button.textContent = busy ? 'Enhancing…' : 'Enhance with AI';
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

function canAccessTab(tabId, caseStatus, stageProgress) {
    if (tabId === 'tab-overview' || tabId === 'tab-paanyaya') {
        return { allowed: true };
    }

    const complaint = window.complaintData || {};
    const cStatus = caseStatus || complaint.case_workspace?.case?.case_status || complaint.status || '';
    const progress = stageProgress || window.caseProgressData || {};
    const stages = complaint.case_workspace?.stages || {};
    const lifecycle = progress.lifecycle_status || complaint.lifecycle_status || '';
    const caseId = complaint.case_id || complaint.case_workspace?.case?.case_id || (new URLSearchParams(window.location.search)).get('case_id');

    if (complaint.status === 'Dismissed' || cStatus === 'Dismissed') {
        return {
            allowed: false,
            prerequisite: 'Paanyaya',
            message: 'Ang reklamo ay opisyal nang na-dismiss. Hindi na maaaring buksan ang mga susunod na yugto.'
        };
    }

    const paanyayaStatuses = [
        'Pre-Docketed (Paanyaya)',
        '1st Paanyaya Failed (No-Show)',
        'Paanyaya In Progress',
        'Paanyaya'
    ];
    const isPaanyayaActive = paanyayaStatuses.includes(complaint.status) && !caseId;

    const summonsPrereq = complaint.paanyaya_stages?.summons_prerequisite;
    const isSummonsLocked = summonsPrereq && (summonsPrereq.allowed === false) && !caseId;
    const p1Choice = complaint.paanyaya_stages?.stage_1?.complainant_choice;
    const p2Stage = complaint.paanyaya_stages?.stage_2;
    const isP2Pending = (p1Choice === 'Request_Paanyaya_2') && (!p2Stage || !p2Stage.is_completed) && (!summonsPrereq || !summonsPrereq.allowed) && !caseId;

    const isPaanyayaCompletedOrEscalated = !isPaanyayaActive && !isSummonsLocked && !isP2Pending && (
        Boolean(caseId) ||
        ['Mediation', 'Conciliation', 'Arbitration', 'CFA', 'Settled', 'Resolution / Closed'].includes(lifecycle) ||
        ['Mediation', 'Conciliation', 'Arbitration', 'Settled', 'Closed'].includes(cStatus) ||
        Boolean(complaint.paanyaya_stages?.stage_1?.complainant_choice === 'Escalate_Mediation') ||
        Boolean(complaint.paanyaya_stages?.stage_2?.complainant_choice === 'Escalate_Mediation') ||
        Boolean(complaint.paanyaya_workspace?.escalated_to_case_id) ||
        Boolean(progress.summons && progress.summons.length > 0)
    );

    if (tabId === 'tab-mediation') {
        if (!isPaanyayaCompletedOrEscalated || isSummonsLocked || isP2Pending) {
            return {
                allowed: false,
                prerequisite: 'Paanyaya',
                message: summonsPrereq?.message || 'This stage is locked until Paanyaya is completed'
            };
        }
        return { allowed: true };
    }

    const medStage = stages.mediation || {};
    const conStage = stages.conciliation || {};
    const isMediationCompletedOrReferred = isPaanyayaCompletedOrEscalated && (
        conStage.is_locked === false ||
        ['Completed', 'Referred to Pangkat', 'Unsuccessful', 'Settled'].includes(medStage.stage_status) ||
        ['Conciliation', 'CFA', 'Arbitration'].includes(lifecycle) ||
        ['Conciliation', 'Arbitration', 'Repudiated', 'CFA'].includes(cStatus) ||
        Boolean(conStage.sessions_count > 0) ||
        Boolean(conStage.stage_status && conStage.stage_status !== 'Locked' && conStage.stage_status !== 'Not Started')
    );

    if (tabId === 'tab-conciliation') {
        if (!isPaanyayaCompletedOrEscalated) {
            return {
                allowed: false,
                prerequisite: 'Paanyaya',
                message: 'This stage is locked until Paanyaya is completed'
            };
        }
        if (!isMediationCompletedOrReferred) {
            return {
                allowed: false,
                prerequisite: 'Mediation',
                message: 'This stage is locked until Mediation is completed'
            };
        }
        return { allowed: true };
    }

    const isConciliationTerminated = isMediationCompletedOrReferred && (
        ['Completed', 'Unsuccessful', 'Settled', 'Repudiated', 'CFA Issued'].includes(conStage.stage_status) ||
        ['CFA', 'Arbitration', 'Resolution / Closed'].includes(lifecycle) ||
        ['Arbitration', 'Repudiated', 'CFA', 'Settled', 'Closed'].includes(cStatus) ||
        Boolean(stages.arbitration && !stages.arbitration.is_locked)
    );

    const arbStage = stages.arbitration || {};

    if (tabId === 'tab-arbitration') {
        const cId = complaint?.complaint_id || (window.complaintDetailsData && window.complaintDetailsData.complaint_id) || window.currentComplaintId;
        const isSessionUnlocked = (cId && sessionStorage.getItem('agap_outcomes_unlocked_' + cId) === '1') ||
                                  sessionStorage.getItem('agap_outcomes_unlocked_global') === '1' ||
                                  window.agapOutcomesUnlocked === true;
        const isSettledOrElevated = ['Settled', 'Repudiated', 'CFA Issued', 'Arbitration', 'Closed', 'Resolution / Closed'].includes(complaint?.complaint_status || '') ||
                                    ['Settled', 'Repudiated', 'CFA Issued', 'Arbitration', 'Closed'].includes(complaint?.case_status || '') ||
                                    ['Settled', 'Repudiated', 'CFA Issued', 'Arbitration'].includes(cStatus) ||
                                    ['Settled', 'Repudiated', 'CFA Issued', 'Arbitration'].includes(medStage?.stage_status || '') ||
                                    ['Settled', 'Repudiated', 'CFA Issued', 'Arbitration'].includes(conStage?.stage_status || '') ||
                                    ['Settled', 'Repudiated', 'CFA Issued', 'Arbitration'].includes(arbStage?.stage_status || '') ||
                                    Boolean(complaint?.case_workspace?.settlement) ||
                                    Boolean(window.__agapComplaintWorkspace?.settlement) ||
                                    Boolean(complaint?.settlement);
        if (isSessionUnlocked || isSettledOrElevated) {
            return { allowed: true };
        }
        if (!isPaanyayaCompletedOrEscalated) {
            return {
                allowed: false,
                prerequisite: 'Paanyaya',
                message: 'This stage is locked until Paanyaya is completed'
            };
        }
        if (!isMediationCompletedOrReferred) {
            return {
                allowed: false,
                prerequisite: 'Mediation',
                message: 'This stage is locked until Mediation is completed'
            };
        }
        if (!isConciliationTerminated) {
            return {
                allowed: false,
                prerequisite: 'Conciliation',
                message: 'This stage is locked until Conciliation is completed'
            };
        }
        return { allowed: true };
    }

    return { allowed: true };
}
window.canAccessTab = canAccessTab;

function updateStageTabsLockState() {
    const tabs = ['tab-mediation', 'tab-conciliation', 'tab-arbitration'];
    tabs.forEach(tabId => {
        const btn = document.querySelector(`.case-tab-item[data-tab="${tabId}"]`);
        if (!btn) return;

        const access = canAccessTab(tabId);
        const lockIcon = btn.querySelector('.case-tab-lock-icon');

        if (!access.allowed) {
            btn.classList.add('tab-locked');
            btn.classList.add('locked');
            btn.setAttribute('aria-disabled', 'true');
            btn.title = access.message;
            if (lockIcon) lockIcon.style.display = 'inline-flex';
            if (btn.classList.contains('active')) {
                switchCaseTab('tab-overview');
            }
        } else {
            btn.classList.remove('tab-locked');
            btn.classList.remove('locked');
            btn.removeAttribute('aria-disabled');
            btn.title = '';
            if (lockIcon) lockIcon.style.display = 'none';
        }
    });
}
window.updateStageTabsLockState = updateStageTabsLockState;

function unlockOutcomesTab(cId) {
    const compId = cId || (window.complaintDetailsData && window.complaintDetailsData.complaint_id) || window.currentComplaintId;
    if (compId) {
        sessionStorage.setItem('agap_outcomes_unlocked_' + compId, '1');
    }
    sessionStorage.setItem('agap_outcomes_unlocked_global', '1');
    window.agapOutcomesUnlocked = true;
    updateStageTabsLockState();
}
window.unlockOutcomesTab = unlockOutcomesTab;

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
            const access = canAccessTab(targetId);
            if (access.allowed) {
                switchCaseTab(targetId);
            } else {
                switchCaseTab('tab-overview');
            }
        }
    }
}

function switchCaseTab(tabId) {
    if (tabId === 'tab-parties') tabId = 'tab-mediation';
    if (tabId === 'tab-documents') tabId = 'tab-conciliation';

    // Sequential stage locking verification
    const access = canAccessTab(tabId);
    if (!access.allowed) {
        if (window.agapNotify) {
            window.agapNotify(access.message, 'warning', 'Stage Locked');
        } else {
            alert(access.message);
        }
        return false;
    }

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

    if (tabId === 'tab-mediation') {
        loadHearingSessions('Mediation');
    } else if (tabId === 'tab-conciliation') {
        loadHearingSessions('Conciliation');
    }

    return true;
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

function handleOpenMediationWorkspace(e) {
    if (e && e.preventDefault) e.preventDefault();
    const data = window.complaintData || {};
    const comp = data.complaint || data;
    const stageCaseId = data.case_id || comp.case_id || comp.stage_case_id || (new URLSearchParams(window.location.search)).get('case_id');
    const complaintId = data.complaint_id || comp.complaint_id || (new URLSearchParams(window.location.search)).get('id');

    if (stageCaseId) {
        window.location.href = `../cases/mediation-workspace.php?case_id=${encodeURIComponent(stageCaseId)}&complaint_id=${encodeURIComponent(complaintId || '')}`;
    } else if (complaintId) {
        window.location.href = `../cases/mediation-workspace.php?complaint_id=${encodeURIComponent(complaintId)}`;
    } else {
        window.location.href = '../cases/mediation-workspace.php';
    }
}
window.handleOpenMediationWorkspace = handleOpenMediationWorkspace;

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

        // Format Docket Number (e.g. 10-17-2026) or Paanyaya Case Number (e.g. P-10-86-2026)
        const isPaanyayaRecord = data.status === 'Pre-Docketed (Paanyaya)' || (!data.case_id && !data.case_workspace?.case?.case_id);
        const formatDocketDisplay = (num, id, dt) => {
            if (num && String(num).startsWith('P-')) return String(num);
            if (num && !String(num).startsWith('CMP-')) return String(num);
            const d = dt ? new Date(dt) : new Date();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const ss = String(id || 1).padStart(2, '0');
            const yy = d.getFullYear() || 2026;
            return isPaanyayaRecord ? `P-${mm}-${ss}-${yy}` : `${mm}-${ss}-${yy}`;
        };
        const docketNum = formatDocketDisplay(data.case_number || data.complaint_number, data.complaint_id, data.created_at);
        const compNum = docketNum;

        // Header Case Number
        const compNumEl = document.getElementById('complaintNumber');
        if (compNumEl) {
            compNumEl.textContent = (isPaanyayaRecord ? 'Paanyaya Case #' : 'Case #') + docketNum;
        }

        const compTitleEl = document.getElementById('complaintTitle');
        if (compTitleEl) compTitleEl.textContent = data.complaint_title || 'Untitled Complaint';

        // Status Determination & Display Stage
        const settlement = data.settlement || data.case_settlement || data.case_workspace?.settlement;
        const isRepudiated = Boolean(
            (settlement && (settlement.repudiation_status === 'Repudiated' || settlement.repudiation_date)) ||
            data.case_status === 'Repudiated' ||
            data.case_workspace?.case?.case_status === 'Repudiated'
        );
        const isCfa = Boolean(
            data.cfa ||
            data.case_workspace?.cfa ||
            data.case_status === 'CFA Issued' ||
            data.case_status === 'For CFA' ||
            data.case_workspace?.case?.case_status === 'CFA Issued' ||
            data.case_workspace?.case?.case_status === 'For CFA' ||
            data.status === 'CFA Issued'
        );
        const isSettled = !isRepudiated && Boolean(
            data.case_status === 'Settled' ||
            data.status === 'Settled' ||
            data.case_workspace?.case?.case_status === 'Settled' ||
            settlement
        );

        const caseStage = data.case_workspace?.case?.case_status || data.current_stage || data.lifecycle_status || '';
        let displayStage = '';

        if (isRepudiated && isCfa) {
            displayStage = 'Repudiated — For CFA';
        } else if (isRepudiated) {
            displayStage = 'Repudiated Settlement';
        } else if (isCfa) {
            displayStage = 'CFA Stage (Court Referral)';
        } else if (isSettled) {
            displayStage = 'Settled (Amicable Settlement)';
        } else if (isPaanyayaRecord || data.status === 'Pre-Docketed (Paanyaya)') {
            displayStage = 'Paanyaya';
        } else {
            const effStage = caseStage || 'Mediation';
            displayStage = effStage.endsWith('Stage') ? effStage : (effStage + ' Stage');
        }

        // Status Badge
        const statusEl = document.getElementById('complaintStatusBadge');
        if (statusEl) {
            statusEl.className = 'case-stage-pill';
            if (isRepudiated && isCfa) {
                statusEl.style.background = '#fee2e2';
                statusEl.style.color = '#991b1b';
                statusEl.style.border = '1px solid #fca5a5';
            } else if (isRepudiated) {
                statusEl.style.background = '#fef2f2';
                statusEl.style.color = '#dc2626';
                statusEl.style.border = '1px solid #fecaca';
            } else if (isCfa) {
                statusEl.style.background = '#ede9fe';
                statusEl.style.color = '#6d28d9';
                statusEl.style.border = '1px solid #c4b5fd';
            } else if (isSettled) {
                statusEl.style.background = '#dcfce7';
                statusEl.style.color = '#15803d';
                statusEl.style.border = '1px solid #86efac';
            } else if (isPaanyayaRecord || data.status === 'Pre-Docketed (Paanyaya)') {
                statusEl.style.background = '#dcfce7';
                statusEl.style.color = '#15803d';
                statusEl.style.border = '1px solid #86efac';
            } else {
                const effStage = caseStage || 'Mediation';
                const stSlug = effStage.toLowerCase().replace(/[^a-z0-9]/g, '-');
                statusEl.className = 'case-stage-pill status-' + stSlug;
                statusEl.style.background = '';
                statusEl.style.color = '';
                statusEl.style.border = '';
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
            statusBtnLabel.textContent = 'Status: ' + (data.status === 'Pre-Docketed (Paanyaya)' ? 'Paanyaya' : (data.status === 'Settled' ? 'Settled' : (caseStage || data.lifecycle_status || 'In Progress')));
        }
        const statusDot = document.getElementById('statusDotIndicator');
        if (statusDot) {
            const isClosed = ['Amicable Settlement', 'Arbitration Award', 'Settled', 'Closed', 'Resolved at Paanyaya / Closed'].includes(data.lifecycle_status) || data.status === 'Resolved at Paanyaya / Closed' || data.status === 'Settled';
            statusDot.style.background = isClosed ? '#10b981' : (data.status === 'Pre-Docketed (Paanyaya)' ? '#16a34a' : '#f59e0b');
        }

        // Update stage tab lock state based on active complaint lifecycle
        updateStageTabsLockState();

        // Hero Case Type Badge
        const heroTypeEl = document.getElementById('heroCaseTypeBadge');
        if (heroTypeEl) heroTypeEl.textContent = (data.case_type || 'Civil').toUpperCase() + ' COMPLAINT';

        // Hero Days Remaining Counter Badge (hidden per statutory timer removal)
        const daysBadge = document.getElementById('heroDaysRemainingBadge');
        if (daysBadge) {
            daysBadge.style.display = 'none';
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
        let catDisplay = data.category_name_display || data.category_name || (data.category_id ? 'Category #' + data.category_id : 'General');
        if (data.other_category_specification && (catDisplay.includes('Others') || catDisplay.includes('Iba pa'))) {
            catDisplay = `Others: ${data.other_category_specification}`;
        }
        setText('infoCategory', catDisplay);
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
        const guardianInfo = party.guardian_name ? `Guardian: ${escapeHtml(party.guardian_name)}${party.guardian_relationship ? ` (${escapeHtml(party.guardian_relationship)})` : ''}` : '';
        const prefContact = party.preferred_contact ? `${escapeHtml(party.preferred_contact)}` : '';
        const ageText = (party.age !== undefined && party.age !== null && party.age !== '') ? `${party.age} yrs` : '';

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
                <div class="party-entry-meta">Tel: ${party.contact_unknown ? 'Contact: Unknown' : escapeHtml(party.contact_no || 'No contact on file')}${prefContact ? ' &bull; ' + prefContact : ''}</div>
                <div class="party-entry-meta">${escapeHtml(fullAddress)}${party.address_requires_verification ? ' <span style="color:#b45309;font-weight:600;">(Needs Verification)</span>' : ''}</div>
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
                stage2Icon.textContent = '2';
                stage2Status.textContent = 'Locked until mediation concludes';
            } else {
                stage2Card.className = 'stage-card-item active';
                stage2Icon.textContent = '2';
                stage2Status.textContent = con.stage_status || 'In Progress';
            }
        }
        if (stage3Card && stage3Status) {
            const arb = stages?.arbitration || {};
            const isArbActive = Boolean(arb.stage_status && arb.stage_status !== 'Locked' && arb.stage_status !== 'Pending');
            if (isArbActive) {
                stage3Card.className = 'stage-card-item active';
                stage3Status.textContent = arb.stage_status || 'In Progress';
                if (stage3Icon) stage3Icon.textContent = '3';
            } else {
                stage3Card.className = 'stage-card-item pending';
                stage3Status.textContent = arb.stage_status || 'Pending';
                if (stage3Icon) stage3Icon.textContent = '3';
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
            if (savedMinIcon) savedMinIcon.textContent = '';
        }
    }

    // Session Action Buttons
    const openMedBtn = document.getElementById('openMediationBtn');
    if (openMedBtn) {
        if (stageCaseId) {
            openMedBtn.href = `../cases/mediation-workspace.php?case_id=${encodeURIComponent(stageCaseId)}&complaint_id=${encodeURIComponent(complaint.complaint_id || '')}`;
        } else if (complaint.complaint_id) {
            openMedBtn.href = `../cases/mediation-workspace.php?complaint_id=${encodeURIComponent(complaint.complaint_id)}`;
        }
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
                            <span>Assigned: ${m.assigned_date ? escapeHtml(formatDateReadable(m.assigned_date)) : 'N/A'}</span>
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
                                <span><strong>${escapeHtml(hearingDateFormatted)}</strong></span> &bull; <span>${escapeHtml(h.venue || 'Barangay Hall')}</span>
                            </div>
                            ${(presentCount > 0 || absentCount > 0) ? `
                                <div style="display: flex; align-items: center; gap: 10px; font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                    <span style="color: #15803d; font-weight: 600;">${presentCount} Present</span>
                                    ${absentCount > 0 ? `<span style="color: #b91c1c; font-weight: 600;">${absentCount} Non-appearance</span>` : ''}
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

    // Dynamic Outcomes Tab & Amicable Settlement Workspace Transformation (RA 7160)
    const settlement = workspace?.settlement || complaint?.settlement || complaint?.case_settlement || {};
    const isRepudiated = Boolean(
        settlement.repudiation_status === 'Repudiated' ||
        settlement.repudiation_date ||
        caseData?.case_status === 'Repudiated' ||
        complaint?.case_status === 'Repudiated'
    );
    const isCfa = Boolean(
        complaint?.cfa ||
        workspace?.cfa ||
        caseData?.case_status === 'CFA Issued' ||
        caseData?.case_status === 'For CFA' ||
        complaint?.case_status === 'CFA Issued' ||
        complaint?.status === 'CFA Issued'
    );
    const hasExecution = Boolean(workspace?.execution || complaint?.execution);
    const isArbitration = Boolean(
        workspace?.arbitration ||
        caseData?.case_status === 'Arbitration' ||
        stages?.arbitration?.stage_status === 'Settled' ||
        stages?.arbitration?.stage_status === 'Arbitration Award'
    );
    const hasSettledSession = (workspace?.hearings || []).some(h => (h.session_outcome === 'Settled' || h.outcome === 'Settled')) ||
                              (workspace?.sessions || []).some(s => (s.session_outcome === 'Settled' || s.outcome === 'Settled')) ||
                              med?.outcome === 'Settled' || con?.outcome === 'Settled' ||
                              (complaint?.case_workspace?.hearings || []).some(h => (h.session_outcome === 'Settled' || h.outcome === 'Settled'));

    const isSettledCase = Boolean(
        complaint?.status === 'Settled' ||
        complaint?.case_status === 'Settled' ||
        caseData?.case_status === 'Settled' ||
        workspace?.settlement ||
        med?.stage_status === 'Settled' ||
        con?.stage_status === 'Settled' ||
        stages?.arbitration?.stage_status === 'Settled' ||
        hasSettledSession
    );

    const tabLabel = document.getElementById('tabOutcomesLabel');
    const amicWs = document.getElementById('amicableSettlementWorkspace');
    const arbWs = document.getElementById('arbitrationCfaWorkspace');

    let tabPillHtml = '';
    if (isRepudiated) {
        tabPillHtml = '<span class="status-pill" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-size:0.7rem; padding:2px 8px; border-radius:9999px; margin-left:6px; font-weight:700;">⚠ REPUDIATED</span>';
    } else if (isCfa) {
        tabPillHtml = '<span class="status-pill" style="background:#ede9fe; color:#6d28d9; border:1px solid #c4b5fd; font-size:0.7rem; padding:2px 8px; border-radius:9999px; margin-left:6px; font-weight:700;">📄 FOR CFA</span>';
    } else if (hasExecution) {
        tabPillHtml = '<span class="status-pill" style="background:#dbeafe; color:#1d4ed8; border:1px solid #93c5fd; font-size:0.7rem; padding:2px 8px; border-radius:9999px; margin-left:6px; font-weight:700;">⚖️ FOR EXECUTION</span>';
    } else if (isArbitration) {
        tabPillHtml = '<span class="status-pill" style="background:#fef3c7; color:#b45309; border:1px solid #fcd34d; font-size:0.7rem; padding:2px 8px; border-radius:9999px; margin-left:6px; font-weight:700;">⚖️ ARBITRATION</span>';
    } else if (isSettledCase) {
        tabPillHtml = '<span class="status-pill status-active" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; font-size:0.7rem; padding:2px 8px; border-radius:9999px; margin-left:6px; font-weight:700;">✓ AMICABLE SETTLEMENT</span>';
    } else {
        tabPillHtml = '<span class="status-pill" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; font-size:0.7rem; padding:2px 8px; border-radius:9999px; margin-left:6px; font-weight:700;">⏳ PENDING</span>';
    }

    if (tabLabel) {
        tabLabel.innerHTML = `Outcome ${tabPillHtml}`;
    }

    const arbProgWs = document.getElementById('arbitrationProgressiveWorkspace');

    if (isSettledCase || (isRepudiated && !isArbitration)) {
        if (typeof unlockOutcomesTab === 'function') {
            unlockOutcomesTab(stageCaseId || complaint.complaint_id);
        }
        if (amicWs) amicWs.style.display = 'block';
        if (arbProgWs) arbProgWs.style.display = 'none';
        if (arbWs) arbWs.style.display = 'none';
        if (typeof renderAmicableSettlementWorkspace === 'function') {
            renderAmicableSettlementWorkspace(workspace, complaint);
        }
    } else if (isArbitration) {
        if (typeof unlockOutcomesTab === 'function') {
            unlockOutcomesTab(stageCaseId || complaint.complaint_id);
        }
        if (amicWs) amicWs.style.display = 'none';
        if (arbProgWs) arbProgWs.style.display = 'block';
        if (arbWs) arbWs.style.display = 'none';
        renderArbitrationProgressiveWorkspace(workspace, complaint, stageCaseId);
    } else {
        if (amicWs) amicWs.style.display = 'none';
        if (arbProgWs) arbProgWs.style.display = 'none';
        if (arbWs) arbWs.style.display = 'block';
    }
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

    const rawDocs = workspace?.documents || [];
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

    // Filter and deduplicate documents:
    // 1. Skip KP Form 7 because Section 1 explicitly renders the primary complaint form.
    // 2. Deduplicate duplicate KP Form 8 and KP Form 9 records, favoring 'Served' over 'For Service'.
    const docs = [];
    rawDocs.forEach(doc => {
        const tName = doc.template_name || '';
        // Skip KP Form 7 as it is already rendered by Section 1
        if (tName.includes('KP Form 7') || tName.toLowerCase().includes('complaint')) {
            return;
        }
        // Deduplicate KP Form 8 (Notice of Hearing) - Complainant receives personal notice upon filing/fee payment
        if (tName.includes('KP Form 8') || tName.includes('Notice of Hearing')) {
            const existing8Idx = docs.findIndex(d => d.template_name?.includes('KP Form 8') || d.template_name?.includes('Notice of Hearing'));
            if (existing8Idx !== -1) {
                if (docs[existing8Idx].service_status !== 'Served' && doc.service_status === 'Served') {
                    docs[existing8Idx] = doc;
                }
                return;
            }
        }
        // Deduplicate redundant KP Form 9 preview entries
        if (tName.includes('KP Form 9') || tName.includes('Summon')) {
            const existing9Idx = docs.findIndex(d => (d.template_name?.includes('KP Form 9') || d.template_name?.includes('Summon')) && (d.file_path === doc.file_path || (d.service_status === 'Served' && doc.service_status === 'For Service')));
            if (existing9Idx !== -1) {
                if (docs[existing9Idx].service_status !== 'Served' && doc.service_status === 'Served') {
                    docs[existing9Idx] = doc;
                }
                return;
            }
        }
        docs.push(doc);
    });

    // Officer's Return details for Paanyaya 1 & 2
    const stg1 = window.paanyayaStagesData?.stage_1 || activeComplaint.paanyaya_stages?.stage_1 || {};
    const stg1Details = stg1.details || {};
    const stg1Latest = stg1.latest_attempt || null;
    const stg1Count = stg1.attempts_count || 0;

    const stg2 = window.paanyayaStagesData?.stage_2 || activeComplaint.paanyaya_stages?.stage_2 || {};
    const stg2Details = stg2.details || {};
    const stg2Latest = stg2.latest_attempt || null;
    const stg2Count = stg2.attempts_count || 0;
    const hasP2Attempt = stg2Count > 0 || Boolean(stg2Details.issued_at && stg2Details.status !== 'Locked');

    const totalCount = docs.length + (complaintId > 0 ? (hasPaanyayaDoc ? (hasP2Attempt ? 3 : 2) : 1) : 0) + 2;
    const countText = `${totalCount} Issued`;
    const formsCountText = `${totalCount} form${totalCount === 1 ? '' : 's'}`;

    if (heroBadge) heroBadge.textContent = countText;
    if (tabDocsBadge) tabDocsBadge.textContent = String(totalCount);
    if (cwDocCountBadge) cwDocCountBadge.textContent = formsCountText;

    let rowsHtml = '';

    // 0. Paanyaya 1 (Informal Invitation Letter) - Visible during Pre-Docketing phase
    if (hasPaanyayaDoc && complaintId > 0) {
        const pSess = activeComplaint.paanyaya || {};
        const pDate = pSess.invitation_date ? formatDateReadable(pSess.invitation_date) : (stg1Details.invitation_date ? formatDateReadable(stg1Details.invitation_date) : 'Scheduled');
        const pTime = pSess.invitation_time || '';
        const pStatus = pSess.status || activeComplaint.status || 'Active';

        const isP1Delivered = Boolean(stg1.is_delivered || stg1Details.status === 'Successfully Delivered' || stg1Latest?.delivery_outcome === 'Successfully delivered');
        let p1BadgeText = '';
        let p1BadgeBg = '#f1f5f9';
        let p1BadgeColor = '#475569';

        if (isP1Delivered) {
            p1BadgeText = 'Successfully Delivered';
            p1BadgeBg = '#dcfce7';
            p1BadgeColor = '#15803d';
        } else if (stg1Latest?.delivery_outcome) {
            p1BadgeText = stg1Latest.delivery_outcome;
            p1BadgeBg = '#fee2e2';
            p1BadgeColor = '#991b1b';
        } else if (stg1Details.status && stg1Details.status !== 'Not Started') {
            p1BadgeText = 'Pending Service';
            p1BadgeBg = '#e0f2fe';
            p1BadgeColor = '#0369a1';
        }

        const p1Officer = stg1Details.assigned_officer_name || stg1Latest?.server_name || '';

        rowsHtml += `
            <div class="kp-form-item-row" style="background: #f0fdf4; border-left: 4px solid #16a34a;">
                <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                    <div>
                        <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-weight: 700; color: #0f172a;">Paanyaya (Informal Invitation Letter)</span>
                            <span style="font-size: 0.72rem; padding: 1px 7px; border-radius: 4px; background: #dcfce7; color: #15803d; font-weight: 700;">PRE-DOCKETING</span>
                            ${p1BadgeText ? `
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: ${p1BadgeBg}; color: ${p1BadgeColor};">
                                    ${escapeHtml(p1BadgeText)}
                                </span>
                            ` : ''}
                        </div>
                        <div class="kp-form-meta-text">
                            <span>Dialogue: ${escapeHtml(pDate)} ${escapeHtml(pTime)}</span>
                            <span>&bull; Status: ${escapeHtml(pStatus)}</span>
                            ${stg1Details.invitee_name ? `<span>&bull; Invitee: <strong>${escapeHtml(stg1Details.invitee_name)}</strong></span>` : ''}
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    ${stg1Latest?.proof_file_path ? `
                        <a href="../../../${escapeHtml(stg1Latest.proof_file_path)}" target="_blank" class="btn-small-link" style="color: #16a34a; border-color: #bbf7d0; text-decoration: none;" title="View uploaded proof / receipt">
                            View Proof
                        </a>
                    ` : ''}
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

    // 0.2 Paanyaya 2 (Second Informal Invitation Letter) - Visible if unlocked/issued
    if (hasP2Attempt && complaintId > 0) {
        const p2Date = stg2Details.invitation_date ? formatDateReadable(stg2Details.invitation_date) : 'Scheduled';
        const isP2Delivered = Boolean(stg2.is_delivered || stg2Details.status === 'Successfully Delivered' || stg2Latest?.delivery_outcome === 'Successfully delivered');
        let p2BadgeText = 'For Service';
        let p2BadgeBg = '#f1f5f9';
        let p2BadgeColor = '#475569';

        if (isP2Delivered) {
            p2BadgeText = 'Successfully Delivered';
            p2BadgeBg = '#dcfce7';
            p2BadgeColor = '#15803d';
        } else if (stg2Latest?.delivery_outcome) {
            p2BadgeText = stg2Latest.delivery_outcome;
            p2BadgeBg = '#fee2e2';
            p2BadgeColor = '#991b1b';
        } else if (stg2Details.status && stg2Details.status !== 'Not Started') {
            p2BadgeText = 'Pending Service';
            p2BadgeBg = '#e0f2fe';
            p2BadgeColor = '#0369a1';
        }

        const p2Officer = stg2Details.assigned_officer_name || stg2Latest?.server_name || '';

        rowsHtml += `
            <div class="kp-form-item-row" style="background: #f0fdf4; border-left: 4px solid #16a34a;">
                <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                    <div>
                        <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-weight: 700; color: #0f172a;">Paanyaya 2 (Second Informal Invitation Letter)</span>
                            <span style="font-size: 0.72rem; padding: 1px 7px; border-radius: 4px; background: #e0f2fe; color: #0369a1; font-weight: 700;">2ND PAANYAYA</span>
                            <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: ${p2BadgeBg}; color: ${p2BadgeColor};">
                                ${escapeHtml(p2BadgeText)}
                            </span>
                        </div>
                        <div class="kp-form-meta-text">
                            <span>Date: ${escapeHtml(p2Date)}</span>
                            ${stg2Details.invitee_name ? `<span>&bull; Invitee: <strong>${escapeHtml(stg2Details.invitee_name)}</strong></span>` : ''}
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    ${stg2Latest?.proof_file_path ? `
                        <a href="../../../${escapeHtml(stg2Latest.proof_file_path)}" target="_blank" class="btn-small-link" style="color: #16a34a; border-color: #bbf7d0; text-decoration: none;" title="View uploaded proof / receipt">
                            View Proof
                        </a>
                    ` : ''}
                    <button type="button" class="btn-small-link" onclick="previewPaanyayaStageLetter('Paanyaya 2')" style="color: #16a34a; border-color: #bbf7d0; font-weight: 600;">
                        Preview Notice
                    </button>
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

    // 2. Subsequent Issued Documents (Summons, Notices, Subpoenas, etc.)
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
        const caseNumber = activeComplaint?.complaint_number || workspace?.case?.case_number || '';
        const docId = Number(doc.document_id);
        const tplNameEscaped = String(doc.template_name || 'KP Form').replace(/'/g, "\\'");

        // Check if this KP form requires an Officer's Return proof of service
        const tNameLower = (doc.template_name || '').toLowerCase();
        const isKp12 = tNameLower.includes('kp form 12') 
            || tNameLower.includes('blg. 12') 
            || tNameLower.includes('pangkat hearing') 
            || tNameLower.includes('conciliation hearing');

        const needsOfficerReturn = tNameLower.includes('summon') || 
                                   tNameLower.includes('patawag') || 
                                   tNameLower.includes('kp form 9') || 
                                   tNameLower.includes('kp form 8') || 
                                   tNameLower.includes('kp form 18') || 
                                   tNameLower.includes('kp form 19') || 
                                   tNameLower.includes('notice of hearing') || 
                                   tNameLower.includes('subpoena') || 
                                   tNameLower.includes('kp form 13') ||
                                   isKp12;

        const isPangkatNotice = tNameLower.includes('kp form 10') || tNameLower.includes('kp form 11') || tNameLower.includes('kp form 12');

        const previewOnClick = (isSummonOrKp9 && typeof openSummonsPreviewModal === 'function')
            ? `openSummonsPreviewModal(${Number(caseId)}, ${docId})`
            : `openKpDocumentPreviewModal(${docId}, '${tplNameEscaped}', '${caseNumber}', ${Number(caseId) || 'null'})`;

        rowsHtml += `
            <div class="kp-form-item-row">
                <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
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
                    ${needsOfficerReturn ? `
                        <button type="button" 
                                class="btn-small-link" 
                                onclick="openDocOfficerReturn(${docId}, ${caseId}, '${tplNameEscaped}')" 
                                style="color: #2563eb; border-color: #bfdbfe; font-weight: 600;" 
                                title="Record Officer's Return for ${escapeHtml(doc.template_name || 'KP Form')}">
                            Record / Return
                        </button>
                    ` : ''}
                    <button type="button" class="btn-small-link" onclick="${previewOnClick}" style="color: #4f46e5; border-color: #c7d2fe; font-weight: 600;" title="Preview and Print ${escapeHtml(doc.template_name || 'KP Form')}">
                        Preview / Print
                    </button>
                    ${isPangkatNotice ? `
                        <button type="button" class="btn-small-link" onclick="openTriPanelPangkatModal(${caseId})" style="color: #7c3aed; border-color: #ddd6fe; font-weight: 600;" title="Preview All Pangkat Notices (KP 10, 11, 12)">
                            All Pangkat Notices
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

    // Dynamic Outcome-Driven KP Forms Unlocking
    const activeCaseId = activeComplaint?.case_id || workspace?.case?.case_id || 0;
    const compStatus = (activeComplaint?.status || workspace?.case?.case_status || '').toLowerCase();
    const compStage = (activeComplaint?.current_stage || workspace?.case?.current_stage || '').toLowerCase();
    const medOutcome = (workspace?.mediation?.outcome || '').toLowerCase();
    const concOutcome = (workspace?.conciliation?.outcome || '').toLowerCase();
    const arbOutcome = (workspace?.arbitration?.outcome || '').toLowerCase();

    // Check all recorded sessions outcomes
    const allEmbeddedSessions = [
        ...(window.embeddedHearingsState?.mediation_sessions || []),
        ...(window.embeddedHearingsState?.conciliation_sessions || []),
        ...(window.embeddedHearingsState?.arbitration_sessions || [])
    ];
    const sessionOutcomes = allEmbeddedSessions.map(s => (s.session_outcome || s.outcome || '').toLowerCase());

    const isSettled = compStatus.includes('settle') || medOutcome.includes('settle') || concOutcome.includes('settle') ||
                      sessionOutcomes.some(o => o.includes('settle')) || Boolean(activeComplaint?.settlement || workspace?.settlement);

    const isArbitration = compStatus.includes('arbitrat') || compStage.includes('arbitrat') ||
                          arbOutcome !== '' || medOutcome.includes('arbitrat') || concOutcome.includes('arbitrat') ||
                          sessionOutcomes.some(o => o.includes('arbitrat')) || Boolean(activeComplaint?.arbitration || workspace?.arbitration);
    const isArbitrationAward = arbOutcome.includes('award') ||
                               sessionOutcomes.some(o => o.includes('arbitration award')) ||
                               Boolean(activeComplaint?.arbitration?.award_date || activeComplaint?.arbitration?.award_terms ||
                                   workspace?.arbitration?.award_date || workspace?.arbitration?.award_terms);

    const isRepudiated = compStatus.includes('repudiat') || sessionOutcomes.some(o => o.includes('repudiat')) ||
                         Boolean(activeComplaint?.settlement?.repudiation_date || workspace?.settlement?.repudiation_date);

    const isElevatedToPangkat = compStage.includes('conciliation') || compStatus.includes('conciliation') ||
                                medOutcome.includes('pangkat') || sessionOutcomes.some(o => o.includes('pangkat') || o.includes('elevate')) ||
                                Boolean(activeComplaint?.pangkat || workspace?.conciliation?.pangkat_id);

    const isFailedConciliation = compStatus.includes('cfa') || concOutcome.includes('cfa') || concOutcome.includes('fail') ||
                                sessionOutcomes.some(o => o.includes('cfa') || o.includes('fail of conciliation')) ||
                                Boolean(activeComplaint?.cfa || workspace?.cfa);

    const hasDocTpl = (tpl) => docs.some(d => (d.template_name || '').toLowerCase().includes(tpl.toLowerCase()));
    const cfaDetails = activeComplaint?.cfa || workspace?.cfa || {};
    const cfaType = String(cfaDetails.cfa_type || cfaDetails.form_code || '').toLowerCase();
    const repudiatingParty = String(activeComplaint?.settlement?.repudiating_party || workspace?.settlement?.repudiating_party || '').toLowerCase();
    const isComplainantBarred = cfaType.includes('20-a') || compStatus.includes('dismiss') ||
        sessionOutcomes.some(o => o.includes('complainant absent')) || repudiatingParty.includes('complainant');
    const isRespondentBarred = cfaType.includes('20-b') ||
        sessionOutcomes.some(o => o.includes('respondent absent')) || repudiatingParty.includes('respondent');

    const appendCfaVariant = (code, title, description) => {
        if (hasDocTpl(code) || !activeCaseId) return;
        const encodedCode = encodeURIComponent(code);
        rowsHtml += `
            <div class="kp-form-item-row" style="background: #fef2f2; border-left: 4px solid #ef4444;">
                <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                    <div>
                        <div class="kp-form-name-text"><span style="font-weight: 700; color: #7f1d1d;">${title}</span></div>
                        <div class="kp-form-meta-text"><span>${description}</span></div>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, '${code}')" style="color: #991b1b; border-color: #fca5a5; font-weight: 600;">Preview / Print</button>
                    <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=${encodedCode}&pdf=1" class="btn-pdf-chip" download title="Download official PDF"><span>PDF</span></a>
                </div>
            </div>`;
    };

    // 1. When Outcome = "Amicably Settled (KP Form 16)"
    if (isSettled && !hasDocTpl('KP Form 16') && activeCaseId > 0) {
        rowsHtml += `
            <div class="kp-form-item-row" style="background: #f0fdf4; border-left: 4px solid #16a34a;">
                <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                    <div>
                        <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-weight: 700; color: #14532d;">Pormularyo ng KP Blg. 16 (Kasunduang Pag-aayos / Amicable Settlement)</span>
                            <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;">
                                AMICABLY SETTLED
                            </span>
                        </div>
                        <div class="kp-form-meta-text">
                            <span>Binding settlement agreement entered freely by parties &bull; Has the force of a final court judgment after 10 days</span>
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 16')" style="color: #16a34a; border-color: #86efac; font-weight: 600;" title="Preview / Print KP Form 16">
                        Preview / Print
                    </button>
                    <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+16&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <span>PDF</span>
                    </a>
                </div>
            </div>
        `;
    }

    // 2. When Outcome = "Arbitration" / "Voluntary Arbitration (KP Form 14)"
    if (isArbitration && activeCaseId > 0) {
        if (!hasDocTpl('KP Form 14')) {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #fffbeb; border-left: 4px solid #d97706;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #78350f;">Pormularyo ng KP Blg. 14 (Kasunduan sa Pag-aarbitro / Agreement for Arbitration)</span>
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">
                                    VOLUNTARY ARBITRATION
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Parties' formal agreement in writing submitting dispute to binding arbitration under RA 7160 Sec. 413</span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 14')" style="color: #b45309; border-color: #fde68a; font-weight: 600;" title="Preview / Print KP Form 14">
                            Preview / Print
                        </button>
                        <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+14&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            <span>PDF</span>
                        </a>
                    </div>
                </div>
            `;
        }
        if (isArbitrationAward && !hasDocTpl('KP Form 15')) {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #fffbeb; border-left: 4px solid #d97706;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #78350f;">Pormularyo ng KP Blg. 15 (Gawad ng Arbitrasyon / Arbitration Award)</span>
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">
                                    ARBITRATION AWARD
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Official binding award rendered by Lupon Presider / Pangkat Chairman</span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 15')" style="color: #b45309; border-color: #fde68a; font-weight: 600;" title="Preview / Print KP Form 15">
                            Preview / Print
                        </button>
                        <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+15&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            <span>PDF</span>
                        </a>
                    </div>
                </div>
            `;
        }
    }

    // 3. When Outcome = "Repudiated Settlement"
    if (isRepudiated && activeCaseId > 0) {
        if (!hasDocTpl('KP Form 17')) {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #fef2f2; border-left: 4px solid #ef4444;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #7f1d1d;">Pormularyo ng KP Blg. 17 (Pagtatwa ng Kasunduan / Repudiation)</span>
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;">
                                    REPUDIATED SETTLEMENT
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Sworn statement repudiating settlement due to vitiated consent (fraud/violence/intimidation)</span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 17')" style="color: #991b1b; border-color: #fca5a5; font-weight: 600;" title="Preview / Print KP Form 17">
                            Preview / Print
                        </button>
                        <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+17&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            <span>PDF</span>
                        </a>
                    </div>
                </div>
            `;
        }
        if (!isComplainantBarred && !isRespondentBarred && !hasDocTpl('KP Form 20')) {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #fef2f2; border-left: 4px solid #ef4444;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #7f1d1d;">Pormularyo ng KP Blg. 20 (Katibayan Upang Makadulog sa Hukuman / CFA)</span>
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;">
                                    CFA UNLOCKED
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Certificate to File Action resulting from repudiated settlement</span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 20')" style="color: #991b1b; border-color: #fca5a5; font-weight: 600;" title="Preview / Print KP Form 20">
                            Preview / Print
                        </button>
                        <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+20&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            <span>PDF</span>
                        </a>
                    </div>
                </div>
            `;
        }
        if (isComplainantBarred) {
            appendCfaVariant('KP Form 20-A', 'Pormularyo ng KP Blg. 20-A (Katibayan Upang Hadlangan ang Paghahain ng Aksyon)', 'Certificate to Bar Action — complainant caused the failure or repudiation without a valid ground.');
        }
        if (isRespondentBarred) {
            appendCfaVariant('KP Form 20-B', 'Pormularyo ng KP Blg. 20-B (Katibayan Upang Hadlangan ang Ganting-Sakdal)', 'Certificate to Bar Counterclaim — respondent caused the failure or repudiation without a valid ground.');
        }
    }

    // 4. When Outcome = "Failure of Mediation / Endorse to Pangkat (KP Form 10)"
    if (isElevatedToPangkat && activeCaseId > 0) {
        if (!hasDocTpl('KP Form 10')) {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #f8fafc; border-left: 4px solid #6366f1;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #1e1b4b;">Pormularyo ng KP Blg. 10 (Paabiso sa Pagbubuo ng Pangkat)</span>
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;">
                                    PANGKAT CONSTITUTION
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Notice summoning parties to appear and agree upon 3 Lupon members for Pangkat</span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-small-link" onclick="openTriPanelPangkatModal(${Number(activeCaseId)})" style="color: #4338ca; border-color: #c7d2fe; font-weight: 600;" title="View in Tri-Panel Side-by-Side Pangkat Preview">
                            Tri-Panel Notices
                        </button>
                        <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 10')" style="color: #4f46e5; border-color: #c7d2fe; font-weight: 600;">
                            Preview / Print
                        </button>
                        <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+10&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            <span>PDF</span>
                        </a>
                    </div>
                </div>
            `;
        }
        if (!hasDocTpl('KP Form 11')) {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #f8fafc; border-left: 4px solid #6366f1;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #1e1b4b;">Pormularyo ng KP Blg. 11 (Patalastas sa Piniling Miyembro ng Pangkat)</span>
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;">
                                    PANGKAT APPOINTMENT
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Official notice served to chosen Pangkat members</span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <button type="button" class="btn-small-link" onclick="openTriPanelPangkatModal(${Number(activeCaseId)})" style="color: #4338ca; border-color: #c7d2fe; font-weight: 600;" title="View in Tri-Panel Side-by-Side Pangkat Preview">
                            Tri-Panel Notices
                        </button>
                        <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 11')" style="color: #4f46e5; border-color: #c7d2fe; font-weight: 600;">
                            Preview / Print
                        </button>
                        <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+11&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            <span>PDF</span>
                        </a>
                    </div>
                </div>
            `;
        }
        if (!hasDocTpl('KP Form 12')) {
            rowsHtml += `
                <div class="kp-form-item-row" style="background: #f8fafc; border-left: 4px solid #6366f1;">
                    <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                        <div>
                            <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #1e1b4b;">Pormularyo ng KP Blg. 12 (Paabiso ng Pagdinig sa Harap ng Pangkat)</span>
                                <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe;">
                                    CONCILIATION SUMMONS
                                </span>
                            </div>
                            <div class="kp-form-meta-text">
                                <span>Formal notice of hearing setting 1st Pangkat conciliation session &bull; Connected to Officer's Return</span>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <a href="../gps/proof-service.php?case_id=${encodeURIComponent(activeCaseId)}&complaint_id=${encodeURIComponent(complaintId)}&stage=Conciliation&form=KP12" class="btn-small-link" style="color: #2563eb; border-color: #bfdbfe; font-weight: 600;" title="Record Officer's Return for KP Form 12">
                            Record / Return
                        </a>
                        <button type="button" class="btn-small-link" onclick="openTriPanelPangkatModal(${Number(activeCaseId)})" style="color: #4338ca; border-color: #c7d2fe; font-weight: 600;" title="View in Tri-Panel Side-by-Side Pangkat Preview">
                            Tri-Panel Notices
                        </button>
                        <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 12')" style="color: #4f46e5; border-color: #c7d2fe; font-weight: 600;">
                            Preview / Print
                        </button>
                        <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+12&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            <span>PDF</span>
                        </a>
                    </div>
                </div>
            `;
        }
    }

    // 5. When Outcome = "Failure of Conciliation / Elevate to Court"
    if (isFailedConciliation && !isRepudiated && !hasDocTpl('KP Form 20') && activeCaseId > 0) {
        rowsHtml += `
            <div class="kp-form-item-row" style="background: #fef2f2; border-left: 4px solid #ef4444;">
                <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                    <div>
                        <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-weight: 700; color: #7f1d1d;">Pormularyo ng KP Blg. 20 (Katibayan Upang Makadulog sa Hukuman / CFA)</span>
                            <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;">
                                CFA READY
                            </span>
                        </div>
                        <div class="kp-form-meta-text">
                            <span>Certificate to File Action &bull; Signed by Pangkat Secretary/Chairman and endorsed by Punong Barangay</span>
                        </div>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn-small-link" onclick="openKpFormModal(${Number(activeCaseId)}, 'KP Form 20')" style="color: #991b1b; border-color: #fca5a5; font-weight: 600;" title="Preview / Print KP Form 20">
                        Preview / Print
                    </button>
                    <a href="../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+20&pdf=1" class="btn-pdf-chip" download title="Download official PDF">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        <span>PDF</span>
                    </a>
                </div>
            </div>
        `;
    }

    // 3. Official Printable Blank Minutes Sheet
    rowsHtml += `
        <div class="kp-form-item-row" style="background: #ffffff; border-left: 4px solid #3b82f6;">
            <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                <div>
                    <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="font-weight: 700; color: #0f172a;">Katitikan ng Pagdinig (Blank / Ruled Minutes Sheet)</span>
                        <span style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #dbeafe; color: #1e40af;">
                            STANDARD TEMPLATE
                        </span>
                    </div>
                    <div class="kp-form-meta-text">
                        <span>Official hearing template with ruled lines for taking handwritten minutes during live proceedings</span>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <button type="button" class="btn-small-link" onclick="openPrintBlankMinutes(0, ${Number(complaintId)})" style="color: #1e40af; border-color: #bfdbfe; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;" title="Print blank ruled minutes sheet">
                    🖨️ Print Blank Sheet
                </button>
            </div>
        </div>
    `;

    // 4. Court Transmittal Letter (MTC Marikina City)
    rowsHtml += `
        <div class="kp-form-item-row" id="heroTransmittalRow" style="background: #ffffff; border-left: 4px solid #0f766e;">
            <div class="kp-form-info-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px;">
                <div>
                    <div class="kp-form-name-text" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="font-weight: 700; color: #0f172a;">Court Transmittal Letter (RA 7160 Sec. 411 / 416)</span>
                        <span id="heroTransmittalBadge" style="display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; font-size: 0.72rem; background: #f1f5f9; color: #475569;">
                            Checking status...
                        </span>
                    </div>
                    <div class="kp-form-meta-text" id="heroTransmittalMeta">
                        <span>Formal endorsement to Clerk of Court, Metropolitan Trial Court (Branch Sta. Elena, Marikina)</span>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;" id="heroTransmittalActions">
                <button type="button" class="btn-small-link" disabled style="opacity: 0.55; cursor: not-allowed; color: #94a3b8; border-color: #cbd5e1;">
                    Preview Letter
                </button>
            </div>
        </div>
    `;

    if (heroList) heroList.innerHTML = rowsHtml;
    if (docContainer) docContainer.innerHTML = rowsHtml;

    if (complaintId > 0) {
        loadHeroTransmittalStatus(complaintId);
    }
}

async function loadHeroTransmittalStatus(complaintId) {
    if (!complaintId) return;
    try {
        const res = await fetch(`../../../backend/api/transmittals/status.php?complaint_id=${encodeURIComponent(complaintId)}`);
        const json = await res.json();
        const badge = document.getElementById('heroTransmittalBadge');
        const meta = document.getElementById('heroTransmittalMeta');
        const actions = document.getElementById('heroTransmittalActions');
        const banner = document.getElementById('statutoryTransmittalAlertBanner');

        if (!res.ok || !json.success) {
            if (badge) {
                badge.textContent = 'Status Unavailable';
                badge.style.background = '#f1f5f9';
                badge.style.color = '#64748b';
            }
            if (banner) banner.style.display = 'none';
            return;
        }

        const isResolved = Boolean(json.is_resolved);
        const data = json.data || {};
        const caseId = Number(data.case_id || json.case_id || window.complaintData?.case_id || 0);

        if (!isResolved || !data.transmittal_id) {
            if (badge) {
                badge.textContent = '🔒 Pending Case Resolution';
                badge.style.background = '#f1f5f9';
                badge.style.color = '#64748b';
                badge.title = 'Transmittal letter unlocks upon formal amicable settlement, arbitration award, or CFA issuance.';
            }
            if (meta) {
                meta.innerHTML = '<span>Formal endorsement to Clerk of Court, MTC Marikina &bull; Unlocks after formal dispute resolution</span>';
            }
            if (actions) {
                actions.innerHTML = `
                    <button type="button" class="btn-small-link" disabled style="opacity: 0.55; cursor: not-allowed; color: #94a3b8; border-color: #cbd5e1;" title="Case has not reached formal resolution.">
                        Locked
                    </button>
                `;
            }
            if (banner) banner.style.display = 'none';
            return;
        }

        const isLocked = Boolean(data.is_locked);
        const daysRemaining = Number(data.working_days_remaining || 0);
        const unlockDate = data.unlock_date_formatted || data.ten_day_expiry_date_formatted || 'Soon';
        const lockTitle = `Transmittal unlocks on ${unlockDate} pursuant to the 10-day repudiation period under RA 7160 Sec. 416.`;

        if (isLocked) {
            if (badge) {
                badge.textContent = `🔒 Locked (${daysRemaining} working day${daysRemaining === 1 ? '' : 's'} remaining - Repudiation Window)`;
                badge.style.background = '#fef3c7';
                badge.style.color = '#92400e';
                badge.style.border = '1px solid #fde68a';
                badge.title = lockTitle;
            }
            if (meta) {
                meta.innerHTML = `<span style="color: #b45309;">Mandatory statutory window active &bull; Unlocks on <strong>${escapeHtml(unlockDate)}</strong> (skips weekends/holidays)</span>`;
            }
            if (actions) {
                actions.innerHTML = `
                    <button type="button" class="btn-small-link" disabled style="opacity: 0.55; cursor: not-allowed; color: #94a3b8; border-color: #cbd5e1;" title="${escapeHtml(lockTitle)}">
                        🔒 Locked
                    </button>
                `;
            }
            if (banner) banner.style.display = 'none';
        } else {
            // Unlocked & Ready!
            if (badge) {
                badge.textContent = '✓ Ready for Court Transmittal';
                badge.style.background = '#dcfce7';
                badge.style.color = '#15803d';
                badge.style.border = '1px solid #86efac';
            }
            if (meta) {
                meta.innerHTML = `<span style="color: #15803d; font-weight: 600;">10-working-day repudiation period completed &bull; Official endorsement ready for print &amp; submission</span>`;
            }
            if (actions) {
                actions.innerHTML = `
                    <button type="button" class="btn-small-link" onclick="openPreviewTransmittalModal(${caseId})" style="color: #0369a1; border-color: #bae6fd; font-weight: 600;" title="Preview transmittal letterhead">
                        Preview Letter
                    </button>
                    <button type="button" class="btn-small-link" onclick="openPrintTransmittalLetter(${caseId})" style="color: #059669; border-color: #a7f3d0; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;" title="Print official transmittal letter">
                        🖨️ Print Transmittal
                    </button>
                `;
            }
            if (banner) banner.style.display = 'flex';
        }
    } catch (err) {
        console.error('Failed to load transmittal status:', err);
    }
}
window.loadHeroTransmittalStatus = loadHeroTransmittalStatus;

function openPrintTransmittalLetter(caseId) {
    const csId = caseId || window.complaintData?.case_id || 0;
    const cmpId = window.complaintData?.complaint_id || 0;
    window.open(`../../../frontend/pages/cases/transmittal-print.php?case_id=${encodeURIComponent(csId)}&complaint_id=${encodeURIComponent(cmpId)}`, '_blank');
}
window.openPrintTransmittalLetter = openPrintTransmittalLetter;

function openPrintBlankMinutes(caseId, complaintId) {
    const csId = caseId || window.complaintData?.case_id || 0;
    const cmpId = complaintId || window.complaintData?.complaint_id || 0;
    window.open(`../../../frontend/pages/complaints/print-blank-minutes.php?case_id=${encodeURIComponent(csId)}&id=${encodeURIComponent(cmpId)}`, '_blank');
}
window.openPrintBlankMinutes = openPrintBlankMinutes;

function openPreviewTransmittalModal(caseId, complaintId = null) {
    const cData = window.complaintData || {};
    const ws = window.__agapComplaintWorkspace || cData.case_workspace || {};
    const activeComplaintId = complaintId || cData.complaint_id || cData.complaint?.complaint_id || (new URLSearchParams(window.location.search)).get('id') || '';
    const activeCaseId = caseId || ws.case_id || ws.case?.case_id || cData.case_id || cData.complaint?.case_id || window.currentCaseId || activeComplaintId;
    if (typeof unlockOutcomesTab === 'function') unlockOutcomesTab(caseId);
    const modal = document.getElementById('previewTransmittalModal');
    const iframe = document.getElementById('transmittalLetterPreviewIframe');
    const downloadBtn = document.getElementById('btnDownloadTransmittalPdf');
    if (!modal) return;

    if (iframe) {
        iframe.src = `../../../backend/api/transmittals/preview.php?case_id=${encodeURIComponent(activeCaseId)}&complaint_id=${encodeURIComponent(activeComplaintId)}&html=1`;
    }
    if (downloadBtn) {
        downloadBtn.href = `../../../backend/api/transmittals/preview.php?case_id=${encodeURIComponent(activeCaseId)}&complaint_id=${encodeURIComponent(activeComplaintId)}&download=1`;
    }

    modal.style.display = 'flex';
}
window.openPreviewTransmittalModal = openPreviewTransmittalModal;

function closePreviewTransmittalModal() {
    const modal = document.getElementById('previewTransmittalModal');
    if (modal) modal.style.display = 'none';
}
window.closePreviewTransmittalModal = closePreviewTransmittalModal;

function printTransmittalIframe() {
    const iframe = document.getElementById('transmittalLetterPreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}
window.printTransmittalIframe = printTransmittalIframe;
function populatePaanyayaOfficersSelect(officers) {
    if (!officers || !Array.isArray(officers) || officers.length === 0) return;
    const select1 = document.getElementById('issueStageOfficer');
    const select2 = document.getElementById('rorServingOfficer');
    const optionsHtml = '<option value="">-- Select Serving Officer --</option>' +
        officers.map((o, idx) => `<option value="${o.user_id}" ${idx === 0 ? 'selected' : ''}>${escapeHtml(o.full_name)} (${escapeHtml(o.role_name)})</option>`).join('');

    if (select1) {
        if (select1.options.length <= 1) {
            select1.innerHTML = optionsHtml;
        }
        if (!select1.value && select1.options.length > 1) {
            select1.selectedIndex = 1;
        }
    }
    if (select2) {
        if (select2.options.length <= 1) {
            select2.innerHTML = optionsHtml;
        }
        if (!select2.value && select2.options.length > 1) {
            select2.selectedIndex = 1;
        }
    }
    const select3 = document.getElementById('minutesP2Officer');
    const select4 = document.getElementById('modalMinutesP2Officer');
    if (select3) {
        const serversOnly = officers.filter(o => parseInt(o.role_id, 10) === 4 || (o.role_name && o.role_name.toLowerCase().includes('summons server')));
        const activeServers = serversOnly.length ? serversOnly : officers;
        if (select3.options.length <= 1) {
            select3.innerHTML = activeServers.map((o, idx) => `<option value="${o.user_id}" ${idx === 0 ? 'selected' : ''}>${escapeHtml(o.full_name)} (${escapeHtml(o.role_name)})</option>`).join('');
        }
    }
    if (select4 && select4.options.length <= 1) select4.innerHTML = optionsHtml;
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
        const p2Inline = document.getElementById('p2DialogueMinutesInline');
        const btnIssue = document.getElementById('btnIssueP2');

        const stage1 = window.paanyayaStagesData?.stage_1 || {};
        const isP1Solved = (window.complaintData?.status === 'Settled') ||
                           (window.complaintData?.status === 'Resolved at Paanyaya / Closed') ||
                           (window.complaintData?.paanyaya_1_outcome === 'Settled') ||
                           (stage1.outcome === 'Settled') ||
                           (stage1.minutes?.outcome === 'Settled') ||
                           (stageData?.bypassed === true);

        const hasMedHearings = (window.embeddedHearingsState?.mediation_sessions?.length > 0) ||
                               (window.complaintData?.hearings?.some(h => (h.hearing_type || '').toLowerCase() === 'mediation'));
        const isMediationGraduated = ['Mediation', 'Conciliation', 'Arbitration', 'Referred to Lupon'].includes(window.complaintData?.status || '') || hasMedHearings;

        const p2WasIssued = !!(details.issued_at) || (count > 0);
        const hasP2Minutes = Boolean(stageData?.minutes_recorded && stageData?.minutes);

        if (isMediationGraduated && !hasP2Minutes && !p2WasIssued) {
            if (lockedNotice) {
                lockedNotice.style.display = 'flex';
                lockedNotice.innerHTML = `
                    <div style="font-size: 1.1rem;">🔒</div>
                    <div>
                        <strong style="display: block; font-size: 0.86rem; color: #92400e;">Paanyaya 2 Locked &amp; Closed</strong>
                        <span style="font-size: 0.80rem; color: #b45309;">
                            This case has graduated to formal Punong Barangay Mediation. Preliminary informal dialogue is concluded.
                        </span>
                    </div>
                `;
            }
            if (p2Inline) p2Inline.style.display = 'none';
            if (btnIssue) {
                btnIssue.style.display = 'none';
                btnIssue.disabled = true;
            }
            ['btnRecordReturnP2', 'btnPreviewP2', 'btnReviewP2', 'btnRecordMinutesP2', 'btnViewMinutesP2'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            return;
        }

        // When dispute resolved at Paanyaya 1, hide Paanyaya 2 card
        const p2Card = document.getElementById('paanyaya2Card');
        if (isP1Solved && !p2WasIssued && !hasP2Minutes) {
            if (p2Card) p2Card.style.display = 'none';
            return;
        } else if (p2Card) {
            p2Card.style.display = '';
        }

        const canIssueP2 = (stage1.minutes_recorded && 
                            ['No_Agreement', 'Party_Absent'].includes(stage1.outcome) && 
                            stage1.complainant_choice === 'Request_Paanyaya_2');

        const isP2Active = (details.status && details.status !== 'Not Started' && details.status !== 'Locked') && p2WasIssued;
        const isP2Unlocked = hasP2Minutes || isP2Active || (!isP1Solved && (stageData?.is_eligible || canIssueP2));

        if (!isP2Unlocked) {
            if (lockedNotice) lockedNotice.style.display = 'flex';
            if (p2Inline) p2Inline.style.display = 'none';
            if (btnIssue) {
                btnIssue.style.display = 'none';
                btnIssue.disabled = true;
            }
            const btnRecord = document.getElementById('btnRecordReturnP2');
            const btnPreview = document.getElementById('btnPreviewP2');
            const btnReview = document.getElementById('btnReviewP2');
            const btnRecMin = document.getElementById('btnRecordMinutesP2');
            const btnViewMin = document.getElementById('btnViewMinutesP2');
            if (btnRecord) btnRecord.style.display = 'none';
            if (btnPreview) btnPreview.style.display = 'none';
            if (btnReview) btnReview.style.display = 'none';
            if (btnRecMin) btnRecMin.style.display = 'none';
            if (btnViewMin) btnViewMin.style.display = 'none';
            return;
        } else {
            if (lockedNotice) lockedNotice.style.display = 'none';
            if (p2Inline) p2Inline.style.display = 'block';
        }
    }

    const hasMedHearingsGrad = (window.embeddedHearingsState?.mediation_sessions?.length > 0) ||
                               (window.complaintData?.hearings?.some(h => (h.hearing_type || '').toLowerCase() === 'mediation'));
    const isMediationGraduatedStage = ['Mediation', 'Conciliation', 'Arbitration', 'Referred to Lupon'].includes(window.complaintData?.status || '') || hasMedHearingsGrad;

    // Stage Status Tag & Schedule Summary
    const statusBadge = document.getElementById(isP2 ? 'p2StatusBadge' : 'p1StatusBadge');
    if (statusBadge) {
        if (stageData?.minutes_recorded) {
            statusBadge.textContent = 'Minutes Recorded';
            statusBadge.style.display = 'inline-block';
            statusBadge.style.background = '#dcfce7';
            statusBadge.style.color = '#15803d';
        } else if (details.status && details.status !== 'Not Started') {
            statusBadge.textContent = 'Scheduled';
            statusBadge.style.display = 'inline-block';
            statusBadge.style.background = '#dcfce7';
            statusBadge.style.color = '#15803d';
        } else {
            statusBadge.textContent = isP2 ? 'Locked' : 'Not Started';
            statusBadge.style.display = 'inline-block';
            statusBadge.style.background = '#f1f5f9';
            statusBadge.style.color = '#64748b';
        }
    }

    const schedMeta = document.getElementById(isP2 ? 'p2ScheduleMeta' : 'p1ScheduleMeta');
    if (schedMeta) {
        if (details.status && details.status !== 'Not Started') {
            const dateStr = details.invitation_date ? formatDateReadable(details.invitation_date) : 'Scheduled';
            const timeStr = details.invitation_time ? formatTime12(details.invitation_time) : '';
            const inviteeStr = details.invitee_name || window.complaintData?.respondent_name || window.complaintData?.respondent || '';
            schedMeta.innerHTML = `
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; margin: 10px 0 12px; font-size: 0.82rem;">
                    <div><strong>Takdang Pag-uusap:</strong> ${escapeHtml(dateStr)}${timeStr ? ` at ${escapeHtml(timeStr)}` : ''} &bull; ${escapeHtml(details.venue || 'Tanggapan ng Barangay Tumana')}</div>
                    ${inviteeStr ? `<div style="margin-top: 3px;"><strong>Inaanyayahan:</strong> ${escapeHtml(inviteeStr)}</div>` : ''}
                </div>
            `;
            schedMeta.style.display = 'block';
        } else {
            schedMeta.style.display = 'none';
        }
    }

    // Action Buttons
    const btnIssueId = isP2 ? 'btnIssueP2' : 'btnIssueP1';
    const btnRecordReturnId = isP2 ? 'btnRecordReturnP2' : 'btnRecordReturnP1';
    const btnPreviewId = isP2 ? 'btnPreviewP2' : 'btnPreviewP1';
    const btnReviewId = isP2 ? 'btnReviewP2' : 'btnReviewP1';
    const btnViewMinutesId = isP2 ? 'btnViewMinutesP2' : 'btnViewMinutesP1';
    const btnIssue = document.getElementById(btnIssueId);
    const btnRecordReturn = document.getElementById(btnRecordReturnId);
    const btnPreview = document.getElementById(btnPreviewId);
    const btnReview = document.getElementById(btnReviewId);
    const btnViewMinutes = document.getElementById(btnViewMinutesId);

    const isDelivered = Boolean(
        stageData?.is_delivered || 
        (details.status && details.status !== 'Not Started') ||
        details.issued_at !== null
    );
    const p2Started = isP2 ? false : Boolean(window.paanyayaStagesData?.stage_2?.details?.status && window.paanyayaStagesData?.stage_2?.details?.status !== 'Not Started');
    const hasMinutes = Boolean(stageData?.minutes_recorded && stageData?.minutes);
    const minutes = stageData?.minutes || null;

    if (btnIssue) {
        btnIssue.style.display = (stageData?.can_issue && !isMediationGraduatedStage) ? 'inline-flex' : 'none';
        btnIssue.disabled = false;
        btnIssue.style.opacity = '';
        btnIssue.style.cursor = '';
    }
    if (btnRecordReturn) {
        btnRecordReturn.style.display = 'none'; // Officer's Return removed from Paanyaya
    }
    if (btnPreview) {
        btnPreview.style.display = (details.status && details.status !== 'Not Started') ? 'inline-flex' : 'none';
    }
    if (btnReview) {
        btnReview.style.display = 'none'; // Officer's Return review removed
    }
    if (btnViewMinutes) {
        btnViewMinutes.style.display = hasMinutes ? 'inline-flex' : 'none';
        const cId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search)).get('id');
        if (cId) {
            const targetStage = isP2 ? 'Paanyaya 2' : 'Paanyaya 1';
            btnViewMinutes.href = `record-dialogue-minutes.php?id=${encodeURIComponent(cId)}&stage=${encodeURIComponent(targetStage)}`;
        }
    }

    // Inline Dialogue Minutes & Settlement Result
    const inlineContainerId = isP2 ? 'p2DialogueMinutesInline' : 'p1DialogueMinutesInline';
    const inlineContainer = document.getElementById(inlineContainerId);
    const btnRecordMinutesId = isP2 ? 'btnRecordMinutesP2' : 'btnRecordMinutesP1';
    const btnRecordMinutes = document.getElementById(btnRecordMinutesId);

    if (inlineContainer) {
        if (hasMinutes && minutes) {
            let outcomeBadge = '<span class="outcome-badge outcome-other">Recorded</span>';
            if (minutes.outcome === 'Settled') {
                outcomeBadge = '<span class="outcome-badge outcome-delivered" style="background:#dcfce7; color:#15803d; font-weight:700; padding:3px 10px; border-radius:9999px;">Nagkasundo / Amicably Settled</span>';
            } else if (minutes.complainant_choice === 'Request_Paanyaya_2') {
                outcomeBadge = '<span class="outcome-badge outcome-unavailable" style="background:#fff7ed; color:#c2410c; font-weight:700; padding:3px 10px; border-radius:9999px;">Walang Kasunduan &bull; Humiling ng 2nd Paanyaya</span>';
            } else if (minutes.complainant_choice === 'Escalate_Mediation' || minutes.outcome === 'Impasse_Escalate' || minutes.outcome === 'Escalate_Mediation' || (isP2 && minutes.outcome === 'No_Agreement')) {
                outcomeBadge = '<span class="outcome-badge outcome-refused" style="background:#fef2f2; color:#dc2626; font-weight:700; padding:3px 10px; border-radius:9999px;">Inakyat sa Pormal na Mediation</span>';
            }

            inlineContainer.innerHTML = `
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-top: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 12px;">
                        <div>
                            <div style="font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #475569;">
                                Dialogue Minutes &amp; Settlement Result
                            </div>
                            <div style="font-size: 0.76rem; color: #64748b; margin-top: 2px;">
                                Itinala noong: <strong>${formatDateReadable(minutes.dialogue_date || minutes.created_at)}</strong>
                                ${minutes.recorded_by_name ? ` &bull; Nagtala: <strong>${escapeHtml(minutes.recorded_by_name)}</strong>` : ''}
                            </div>
                        </div>
                        <div>${outcomeBadge}</div>
                    </div>

                    <!-- Schedule & Attendance Breakdown -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; font-size: 0.82rem;">
                        <div><strong>Takdang Oras:</strong> ${escapeHtml(minutes.dialogue_date ? formatDateReadable(minutes.dialogue_date) : 'N/A')}</div>
                        <div><strong>Aktwal na Simula:</strong> ${escapeHtml(minutes.actual_start_time || 'Nasa oras')}</div>
                        <div><strong>Nagpaanyaya:</strong> <span style="color: ${minutes.complainant_attended === 'Present' ? '#166534' : '#dc2626'}; font-weight: 700;">${minutes.complainant_attended === 'Present' ? 'Dumalo (Present)' : 'Hindi Dumalo'}</span></div>
                        <div><strong>Inanyayahan:</strong> <span style="color: ${minutes.respondent_attended === 'Present' ? '#166534' : '#dc2626'}; font-weight: 700;">${minutes.respondent_attended === 'Present' ? 'Dumalo (Present)' : 'Hindi Dumalo'}</span></div>
                    </div>

                    ${minutes.session_remarks ? `
                    <div style="background: #fffbeb; border: 1px solid #fef08a; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; font-size: 0.8rem; color: #854d0e;">
                        <strong>Puna sa Oras ng Simula:</strong> <em>"${escapeHtml(minutes.session_remarks)}"</em>
                    </div>
                    ` : ''}

                    <!-- Salaysay at Buod -->
                    <div style="margin-bottom: 12px;">
                        <strong style="font-size: 0.82rem; color: #1e293b; display: block; margin-bottom: 4px;">Salaysay at Buod ng Napag-usapan:</strong>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; font-size: 0.84rem; color: #334155; line-height: 1.5; white-space: pre-wrap;">${escapeHtml(minutes.discussion_summary || 'Walang nakatalang buod.')}</div>
                    </div>

                    <!-- Mga Mungkahi o Napagkasunduang Kondisyon -->
                    <div style="margin-bottom: 12px;">
                        <strong style="font-size: 0.82rem; color: #1e293b; display: block; margin-bottom: 4px;">Mga Mungkahi o Napagkasunduang Kondisyon:</strong>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; font-size: 0.84rem; color: #334155; line-height: 1.5; white-space: pre-wrap;">${escapeHtml(minutes.agreed_terms || 'Walang karagdagang kondisyon na itinala.')}</div>
                    </div>

                    <!-- Attached Document -->
                    ${minutes.scanned_minutes_path ? `
                    <div style="display: flex; align-items: center; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e2e8f0;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        <strong style="font-size: 0.82rem; color: #1e293b;">Naka-attach na Katitikan:</strong>
                        <a href="../../../${escapeHtml(minutes.scanned_minutes_path)}" target="_blank" style="color: #2563eb; font-size: 0.82rem; font-weight: 600; text-decoration: underline;">Tingnan ang File / Larawan</a>
                    </div>
                    ` : ''}
                </div>
            `;
            inlineContainer.style.display = 'block';
        } else {
            inlineContainer.innerHTML = `
                <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 10px; padding: 18px; text-align: center; margin-top: 10px;">
                    <p style="margin: 0 0 6px 0; font-size: 0.88rem; color: #475569; font-weight: 600;">
                        Dialogue Minutes not yet recorded.
                    </p>
                    <span style="font-size: 0.8rem; color: #64748b;">
                        ${isDelivered ? 'Ang paanyaya ay naihatid na. I-click ang "Record Dialogue Minutes" upang itala ang katitikan.' : 'I-click ang "Record Dialogue Minutes" sa ibaba kapag isinagawa na ang pag-uusap.'}
                    </span>
                </div>
            `;
            inlineContainer.style.display = 'block';
        }
    }

    if (btnRecordMinutes) {
        if (hasMinutes) {
            btnRecordMinutes.style.display = 'none';
        } else {
            btnRecordMinutes.style.display = (isDelivered && !p2Started && !isMediationGraduatedStage) ? 'inline-flex' : 'none';
            const cId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search)).get('id');
            if (cId) {
                const targetStage = isP2 ? 'Paanyaya 2' : 'Paanyaya 1';
                // Ensure direct navigation to the standalone workspace page
                btnRecordMinutes.href = `record-dialogue-minutes.php?id=${encodeURIComponent(cId)}&stage=${encodeURIComponent(targetStage)}`;
                btnRecordMinutes.onclick = null; // Remove modal interception so it navigates cleanly
            }
        }
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
        if (data.status === 'Dismissed' || stage1.outcome === 'Dismissed' || stage1.minutes?.outcome === 'Dismissed') {
            stage0Badge.textContent = 'Dismissed at Paanyaya (Complainant Absent)';
            stage0Badge.style.background = '#fee2e2';
            stage0Badge.style.color = '#991b1b';
            if (stage0Icon) stage0Icon.textContent = '🚫';
        } else if (data.status === 'Settled' || data.status === 'Resolved at Paanyaya / Closed' || stage1.outcome === 'Settled' || stage1.minutes?.outcome === 'Settled') {
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
            if (stage0Icon) stage0Icon.textContent = '0';
        } else if (stage1.details?.status === 'In Progress' || stage2.details?.status === 'In Progress') {
            stage0Badge.textContent = 'Paanyaya In Progress';
            stage0Badge.style.background = '#fef08a';
            stage0Badge.style.color = '#854d0e';
            if (stage0Icon) stage0Icon.textContent = '0';
        } else {
            stage0Badge.textContent = 'Paanyaya Preliminary Stage';
            stage0Badge.style.background = '#fef08a';
            stage0Badge.style.color = '#854d0e';
            if (stage0Icon) stage0Icon.textContent = '0';
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
                        ${esc(addr)}
                        ${phone ? ` &bull; Tel: ${esc(phone)}` : ''}
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
                       (data.status === 'Dismissed') ||
                       (data.paanyaya_1_outcome === 'Settled') ||
                       (data.paanyaya_1_outcome === 'Dismissed') ||
                       (stage1.outcome === 'Settled') ||
                       (stage1.outcome === 'Dismissed') ||
                       (stage1.minutes?.outcome === 'Settled') ||
                       (stage1.minutes?.outcome === 'Dismissed') ||
                       (stage2.bypassed === true);

    const hasP2Minutes = Boolean(stage2.minutes_recorded && stage2.minutes);
    const p2WasIssued = !!(stage2.details?.issued_at) || ((stage2.attempts_count || 0) > 0);

    const p2Card = document.getElementById('paanyaya2Card');
    if (p2Card) {
        p2Card.style.display = (isP1Solved && !p2WasIssued && !hasP2Minutes) ? 'none' : '';
    }

    const tab2Btn = document.getElementById('btnHistTabP2');
    if (tab2Btn) {
        tab2Btn.style.display = (isP1Solved && !p2WasIssued && !hasP2Minutes) ? 'none' : '';
    }

    // 3. Render CARD 1: PAANYAYA 1
    renderPaanyayaStageCard('1', stage1, data.complaint_number, compNames, respNames, respAddr);

    // 4. Render CARD 2: PAANYAYA 2 (only if not solved at Paanyaya 1, or P2 was issued/recorded)
    if (!isP1Solved || p2WasIssued || hasP2Minutes) {
        renderPaanyayaStageCard('2', stage2, data.complaint_number, compNames, respNames, respAddr);
    }

    // 5. Render DELIVERY HISTORY TABLE
    if (!window.activePaanyayaHistoryStage) {
        window.activePaanyayaHistoryStage = 'Paanyaya 1';
    }
    renderPaanyayaDeliveryHistoryTable(window.activePaanyayaHistoryStage);
}

function renderMediationCard(stages, caseId, complaint, workspace) {
    loadHearingSessions('Mediation');
}

function renderConciliationCard(stages, caseId, complaint) {
    loadHearingSessions('Conciliation');
}

// =========================================================================
// EMBEDDED HEARING SESSIONS MANAGEMENT (MEDIATION & PANGKAT CONCILIATION)
// =========================================================================

window.embeddedHearingsState = {
    mediation_sessions: [],
    conciliation_sessions: [],
    activeStageType: 'Mediation',
    luponMembers: []
};

async function loadHearingSessions(stageType = 'Mediation') {
    const data = window.complaintData || {};
    const comp = data.complaint || data;
    const caseId = data.case_id || comp.case_id || (new URLSearchParams(window.location.search)).get('case_id');
    const complaintId = data.complaint_id || comp.complaint_id || (new URLSearchParams(window.location.search)).get('id');

    const containerId = stageType === 'Mediation' ? 'mediationSessionsListContainer' : 'conciliationSessionsListContainer';
    const badgeId = stageType === 'Mediation' ? 'mediationSessionsCountBadge' : 'conciliationSessionsCountBadge';
    const container = document.getElementById(containerId);
    const badge = document.getElementById(badgeId);

    if (!container) return;

    if (!caseId) {
        if (stageType === 'Mediation') {
            container.innerHTML = `
                <div class="empty-detail-state" style="text-align: center; padding: 28px 20px; background: #fff; border: 1px dashed #cbd5e1; border-radius: 8px;">
                    <p style="margin: 0 0 10px; font-size: 0.88rem; color: #64748b;">
                        Case is currently in the Paanyaya (Preliminary Dialogue) stage. Formal mediation sessions will unlock once Paanyaya concludes or escalates to Punong Barangay Mediation.
                    </p>
                    <button type="button" class="btn-create" onclick="switchCaseTab('tab-paanyaya')" style="font-size: 0.82rem; padding: 6px 14px;">
                        Go to Paanyaya Dialogue &rarr;
                    </button>
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="empty-detail-state" style="text-align: center; padding: 28px 20px; background: #fff; border: 1px dashed #cbd5e1; border-radius: 8px;">
                    <p style="margin: 0; font-size: 0.88rem; color: #64748b;">
                        Pangkat Conciliation is locked until Punong Barangay Mediation concludes with a formal referral to Pangkat.
                    </p>
                </div>
            `;
        }
        if (badge) badge.textContent = '0 sessions';
        return;
    }

    try {
        const res = await fetch(`../../../backend/api/stages/details.php?case_id=${encodeURIComponent(caseId)}&_t=${Date.now()}`, { cache: 'no-store' });
        const json = await res.json();
        if (!json.success || !json.data) {
            throw new Error(json.message || 'Unable to load hearing session details.');
        }

        const stageData = json.data;
        const sessions = stageType === 'Mediation' ? (stageData.mediation_sessions || []) : (stageData.conciliation_sessions || []);
        if (stageType === 'Mediation') {
            window.embeddedHearingsState.mediation_sessions = sessions;
        } else {
            window.embeddedHearingsState.conciliation_sessions = sessions;
        }

        renderHearingSessionCards(sessions, containerId, badgeId, stageType, caseId);
    } catch (err) {
        console.error('loadHearingSessions error:', err);
        container.innerHTML = `
            <div style="padding: 16px; border: 1px solid #fecaca; background: #fef2f2; border-radius: 8px; color: #991b1b; font-size: 0.85rem;">
                <strong>Error loading ${escapeHtml(stageType.toLowerCase())} sessions:</strong> ${escapeHtml(err.message)}
                <div style="margin-top: 8px;">
                    <button type="button" class="btn-secondary" onclick="loadHearingSessions('${stageType}')" style="font-size: 0.78rem; padding: 4px 10px;">Retry</button>
                </div>
            </div>
        `;
    }
}
window.loadHearingSessions = loadHearingSessions;

function renderHearingSessionCards(sessions, containerId, badgeId, stageType, caseId) {
    const container = document.getElementById(containerId);
    const badge = document.getElementById(badgeId);
    if (!container) return;

    if (badge) badge.textContent = `${sessions.length} session${sessions.length === 1 ? '' : 's'}`;

    if (!sessions.length) {
        container.innerHTML = `
            <div class="empty-detail-state" style="text-align: center; padding: 32px 20px; background: #fff; border: 1px dashed #cbd5e1; border-radius: 8px;">
                <p style="margin: 0 0 12px; font-size: 0.9rem; color: #64748b;">
                    No ${escapeHtml(stageType.toLowerCase())} hearing sessions scheduled yet.
                </p>
                <button type="button" class="btn-create" onclick="openEmbeddedScheduleModal('${stageType}')" style="font-size: 0.84rem; padding: 7px 16px; display: inline-flex; align-items: center; gap: 6px;">
                    + Schedule ${escapeHtml(stageType)} Session &rarr;
                </button>
            </div>
        `;
        return;
    }

    let cardsHtml = '';
    sessions.forEach((s, idx) => {
        const sessionIndex = Number(s.session_index || (idx + 1));
        const sessionLabel = getSessionOrdinalLabel(stageType, sessionIndex);
        const targetWorkspace = stageType === 'Conciliation' ? '../cases/pangkat-workspace.php' : '../cases/mediation-workspace.php';
        const complaintIdParam = new URLSearchParams(window.location.search).get('id') || (window.currentComplaintData ? window.currentComplaintData.complaint_id : '') || (window.complaintData ? window.complaintData.complaint_id : '') || '';
        const openWorkspaceUrl = `${targetWorkspace}?case_id=${encodeURIComponent(caseId || '')}&complaint_id=${encodeURIComponent(complaintIdParam)}&hearing_id=${encodeURIComponent(s.hearing_id)}`;
        const isCompleted = s.status === 'Completed';
        const hasMinutes = s.minutes_id && s.minutes_status !== 'Not Recorded';
        const isFinalized = s.minutes_status === 'Finalized';

        const compIn = (s.complainant_attendance === 'Present' || s.complainant_attendance === 'Late');
        const respIn = (s.respondent_attendance === 'Present' || s.respondent_attendance === 'Late');
        const isReady = compIn && respIn;
        const qStatus = s.queue_status || (isReady ? 'Ready' : 'Waiting');
        const isInSession = (qStatus === 'In_Session');
        const isStandby = (qStatus === 'Standby');
        const isConcluded = (qStatus === 'Concluded' || isCompleted);

        let queueBadgeHtml = '';
        if (isInSession) {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">In Session (Started: ${formatTime12(s.actual_start_time || '10:00:00')})</span>`;
        } else if (isReady) {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 800; padding: 2px 8px; border-radius: 4px; background: #dcfce7; color: #15803d; border: 1px solid #86efac;">Complete &amp; Ready</span>`;
        } else if (isStandby) {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">On Standby</span>`;
        } else if (isConcluded) {
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">Concluded (${s.actual_duration_minutes || s.duration_minutes || 45} mins)</span>`;
        } else {
            const dateObjForBadge = new Date(s.hearing_date ? s.hearing_date.replace(' ', 'T') : '');
            const nowObjForBadge = new Date();
            const elapsedMins = (!isNaN(dateObjForBadge.getTime()) && nowObjForBadge > dateObjForBadge) ? Math.floor((nowObjForBadge - dateObjForBadge) / 60000) : 0;
            if (elapsedMins >= 60) {
                queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5;">Delayed (${elapsedMins}m)</span>`;
            } else if (elapsedMins > 0) {
                queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">Grace Period (${elapsedMins}m)</span>`;
            } else {
                queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0;">Waiting Room</span>`;
            }
        }

        let partyAttHtml = '';
        if (s.parties && s.parties.length) {
            partyAttHtml = s.parties.map(p => {
                let badgeStyle = 'background: #f1f5f9; color: #64748b;';
                const st = p.attendance_status || 'Pending';
                if (st === 'Present') badgeStyle = 'background: #dcfce7; color: #15803d;';
                else if (st === 'Late') badgeStyle = 'background: #fef3c7; color: #b45309;';
                else if (st === 'Excused') badgeStyle = 'background: #fef3c7; color: #b45309;';
                else if (st === 'Absent') badgeStyle = 'background: #fee2e2; color: #991b1b;';
                const excuseText = p.justification_reason ? ` (${escapeHtml(p.justification_reason)})` : '';
                return `<div style="font-size: 0.78rem; margin-top: 2px;">
                    <span style="font-weight: 600; color: #475569;">${escapeHtml(p.party_type)}: ${escapeHtml(p.full_name)}</span> &rarr;
                    <span style="display: inline-flex; align-items: center; gap: 3px; font-weight: 700; padding: 1px 6px; border-radius: 4px; font-size: 0.74rem; ${badgeStyle}">
                        ${escapeHtml(st)}${excuseText}
                    </span>
                </div>`;
            }).join('');
        } else {
            partyAttHtml = `
                <div style="margin-top: 3px; color: #334155;">
                    Complainant: <strong>${escapeHtml(s.complainant_attendance || 'Pending')}</strong> · Respondent: <strong>${escapeHtml(s.respondent_attendance || 'Pending')}</strong>
                </div>
            `;
        }

        cardsHtml += `
            <div class="session-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                            <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; padding: 2px 7px; border-radius: 4px; background: #e0f2fe; color: #0369a1;">
                                #${s.session_index || 1}
                            </span>
                            <h4 style="margin: 0; font-size: 1.05rem; color: #0f172a; font-weight: 700;">
                                ${escapeHtml(sessionLabel)}
                            </h4>
                            ${queueBadgeHtml}
                            <span class="status-pill status-${escapeHtml((s.status || 'scheduled').toLowerCase())}" style="font-size: 0.74rem; padding: 2px 8px; border-radius: 4px; font-weight: 700;">
                                ${escapeHtml(s.status || 'Scheduled')}
                            </span>
                        </div>
                        <div style="font-size: 0.84rem; color: #475569; display: flex; gap: 14px; flex-wrap: wrap;">
                            <span>Call Time: <strong>${s.hearing_date ? formatDateTimeReadable(s.hearing_date) : 'Pending'}</strong></span>
                            <span>Venue: <strong>${escapeHtml(s.venue || 'Lupon Court Room')}</strong></span>
                            ${s.presiding_officer_name ? `<span>Presider: <strong>${escapeHtml(s.presiding_officer_name)}</strong></span>` : ''}
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                        ${!isConcluded && !isInSession ? `
                            ${isReady ? `
                            <button type="button" class="btn-create" onclick="handleHearingStartSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 12px; background: #16a34a; display: inline-flex; align-items: center; gap: 4px;" title="Call to session immediately">
                                ▶️ Call to Session
                            </button>
                            ` : ''}
                            <button type="button" class="btn-secondary" onclick="handleHearingStandby(${s.hearing_id}, ${isStandby ? 'false' : 'true'})" style="font-size: 0.78rem; padding: 5px 9px; display: inline-flex; align-items: center; gap: 4px; ${isStandby ? 'background: #fef3c7; color: #b45309; border-color: #fde68a; font-weight: 700;' : ''}">
                                ${isStandby ? '▶️ Resume' : 'Put on Standby'}
                            </button>
                        ` : ''}
                        ${isInSession ? `
                            <button type="button" class="btn-create" onclick="handleHearingConcludeSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 12px; background: #b91c1c; display: inline-flex; align-items: center; gap: 4px;">
                                Conclude Session
                            </button>
                        ` : ''}
                        ${stageType === 'Mediation' && Number(s.session_index || 1) === 1 ? `
                            <button type="button" class="btn-secondary" onclick="openSummonsPreviewModal(${Number(caseId)}, null, ${Number(s.hearing_id)})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px; border-color: #0284c7; color: #0284c7; background: #f0f9ff;" title="Preview KP Form No. 9">
                                KP Form 9
                            </button>
                            <a href="../gps/proof-service.php?case_id=${encodeURIComponent(caseId || '')}&hearing_id=${encodeURIComponent(s.hearing_id)}&open_return=1" class="btn-secondary" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px; border-color: #10b981; color: #047857; background: #ecfdf5; text-decoration: none;" title="Record attempt to deliver summons / Officer's Return">
                                📋 Record Summons Return
                            </a>
                        ` : ''}
                        <button type="button" class="btn-secondary" onclick="openEmbeddedAttendanceModal(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;" title="Quick Attendance / Check-In for Complainant &amp; Respondent">
                            📝 Quick Attendance
                        </button>
                        <a href="${openWorkspaceUrl}" class="btn-create" style="font-size: 0.78rem; padding: 5px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; background: #2563eb; border-color: #2563eb; color: #fff; font-weight: 700;" title="Open ${escapeHtml(sessionLabel)} workspace">
                            Open ${escapeHtml(sessionLabel)} &rarr;
                        </a>
                    </div>
                </div>

                ${(() => {
                    const dateObj = new Date(s.hearing_date ? s.hearing_date.replace(' ', 'T') : '');
                    const nowObj = new Date();
                    const elapsedMins = (!isNaN(dateObj.getTime()) && nowObj > dateObj) ? Math.floor((nowObj - dateObj) / 60000) : 0;
                    const isGraceExpired = (!isConcluded && !isInSession && !isReady && !isStandby && elapsedMins >= 60);
                    const isGraceActive = (!isConcluded && !isInSession && !isReady && !isStandby && elapsedMins > 0 && elapsedMins < 60);

                    if (isGraceExpired) {
                        return `
                            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 14px; margin-bottom: 12px; font-size: 0.8rem; color: #991b1b; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <strong>1-Hour Grace Period Expired:</strong> Scheduled call time has passed by ${elapsedMins} minutes and parties remain incomplete.
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <button type="button" class="btn-secondary" onclick="handleHearingStandby(${s.hearing_id}, true)" style="font-size: 0.74rem; padding: 4px 8px; background: #fff;">Move to Standby</button>
                                    <button type="button" class="btn-secondary" onclick="openEmbeddedAttendanceModal(${s.hearing_id})" style="font-size: 0.74rem; padding: 4px 8px; background: #fee2e2; border-color: #fca5a5; color: #991b1b;">Record Non-Appearance</button>
                                </div>
                            </div>
                        `;
                    }
                    if (isGraceActive) {
                        return `
                            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 14px; margin-bottom: 12px; font-size: 0.8rem; color: #92400e; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <strong>1-Hour Grace Period Active:</strong> Call time was ${elapsedMins}m ago (${60 - elapsedMins}m remaining before non-appearance action).
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <button type="button" class="btn-secondary" onclick="handleHearingStandby(${s.hearing_id}, true)" style="font-size: 0.74rem; padding: 4px 8px; background: #fff;" title="Put on standby to keep room available for other ready cases">Put on Standby</button>
                                </div>
                            </div>
                        `;
                    }
                    return '';
                })()}

                <!-- Session Sub-Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.82rem;">
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">PROOF OF SERVICE</span>
                        <div style="margin-top: 3px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-size: 0.76rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; ${s.proof_of_service_status === 'Served' ? 'background: #dcfce7; color: #15803d;' : 'background: #fef3c7; color: #b45309;'}">
                                ${s.proof_of_service_status === 'Served' ? 'Notice Served' : 'Pending / Unserved'}
                            </span>
                            <a href="../gps/proof-service.php?case_id=${encodeURIComponent(caseId || '')}&hearing_id=${encodeURIComponent(s.hearing_id)}&open_return=1" style="font-size: 0.74rem; color: #0284c7; font-weight: 600; text-decoration: underline;" title="Record attempt to deliver summons">
                                ${s.proof_of_service_status === 'Served' ? 'View/Update Return &rarr;' : 'Record Attempt &rarr;'}
                            </a>
                        </div>
                    </div>
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">ATTENDANCE RECORD</span>
                        ${partyAttHtml}
                    </div>
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">MINUTES OF THE MEETING</span>
                        <div style="margin-top: 3px;">
                            <span style="font-size: 0.76rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; ${isFinalized ? 'background: #dcfce7; color: #15803d;' : (hasMinutes ? 'background: #dbeafe; color: #1e40af;' : 'background: #f1f5f9; color: #64748b;')}">
                                ${escapeHtml(s.minutes_status || 'Not Recorded')}
                            </span>
                            ${s.session_outcome && s.session_outcome !== 'Pending' ? `<span style="font-size: 0.74rem; color: #475569; margin-left: 4px;">(${escapeHtml(s.session_outcome)})</span>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    container.innerHTML = cardsHtml;
}

async function handleHearingCheckIn(hearingId, partyType, status, residentId = null) {
    try {
        const payload = {
            hearing_id: Number(hearingId),
            action: 'check_in',
            party_type: partyType,
            status: status
        };
        if (residentId) payload.resident_id = Number(residentId);

        const res = await fetch('../../../backend/api/hearings/queue.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            window.agapNotify?.(`Recorded ${partyType} as ${status}.`, 'success', 'Party Arrival');
            loadHearingSessions('Mediation');
            loadHearingSessions('Conciliation');
        } else {
            window.agapNotify?.(json.message || 'Error updating arrival status.', 'warning', 'Check-In');
        }
    } catch (err) {
        console.error('handleHearingCheckIn error:', err);
        window.agapNotify?.('Network error updating check-in.', 'error', 'Check-In Error');
    }
}
window.handleHearingCheckIn = handleHearingCheckIn;

async function handleHearingStandby(hearingId, standby) {
    try {
        const res = await fetch('../../../backend/api/hearings/queue.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ hearing_id: Number(hearingId), action: 'standby', standby: Boolean(standby) })
        });
        const json = await res.json();
        if (json.success) {
            window.agapNotify?.(standby ? 'Session moved to standby.' : 'Session resumed.', 'info', 'Docket Queue');
            loadHearingSessions('Mediation');
            loadHearingSessions('Conciliation');
        } else {
            window.agapNotify?.(json.message || 'Error updating standby.', 'warning');
        }
    } catch (e) {
        console.error(e);
    }
}
window.handleHearingStandby = handleHearingStandby;

async function handleHearingStartSession(hearingId) {
    try {
        const res = await fetch('../../../backend/api/hearings/queue.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ hearing_id: Number(hearingId), action: 'start' })
        });
        const json = await res.json();
        if (json.success) {
            window.agapNotify?.('Session called to order and officially in progress.', 'success', 'Session Started');
            loadHearingSessions('Mediation');
            loadHearingSessions('Conciliation');
        } else {
            window.agapNotify?.(json.message || 'Error starting session.', 'warning');
        }
    } catch (e) {
        console.error(e);
    }
}
window.handleHearingStartSession = handleHearingStartSession;

async function handleHearingConcludeSession(hearingId) {
    try {
        const res = await fetch('../../../backend/api/hearings/queue.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ hearing_id: Number(hearingId), action: 'conclude' })
        });
        const json = await res.json();
        if (json.success) {
            window.agapNotify?.('Hearing session concluded successfully.', 'success', 'Session Concluded');
            loadHearingSessions('Mediation');
            loadHearingSessions('Conciliation');
        } else {
            window.agapNotify?.(json.message || 'Error concluding session.', 'warning');
        }
    } catch (e) {
        console.error(e);
    }
}
window.handleHearingConcludeSession = handleHearingConcludeSession;

// Schedule Hearing Modal
function openEmbeddedScheduleModal(stageType = 'Mediation') {
    const modal = document.getElementById('wsScheduleModal');
    if (!modal) return;

    if (stageType === 'Mediation') {
        const existing = window.embeddedHearingsState?.mediation_sessions || [];
        if (existing.length >= 1) {
            const s1 = existing[0];
            const s1Status = s1.status || '';
            const s1Queue = s1.queue_status || '';
            const s1Outcome = (s1.session_outcome || s1.outcome || '').toLowerCase();
            const isActive = ['Scheduled', 'Waiting Room', 'Waiting', 'In_Session', 'Standby'].includes(s1Queue) ||
                             (['Scheduled', 'In_Session', 'Standby', 'Pending'].includes(s1Status) && s1Status !== 'Completed');
            const isSettled = s1Outcome.includes('settle') || s1Outcome === 'amicable settlement';
            const isConcluded = s1Status === 'Completed' || s1Queue === 'Concluded' || (s1.minutes_status === 'Finalized');
            const isUnresolved = s1Outcome.includes('unresolved') || s1Outcome.includes('reset') || s1Outcome.includes('reschedule') || s1Outcome.includes('non-settlement');

            if (isSettled) {
                if (typeof showToast === 'function') {
                    showToast('Cannot schedule 2nd Mediation: 1st Mediation concluded with an Amicable Settlement.', 'error');
                } else {
                    alert('Cannot schedule 2nd Mediation: 1st Mediation concluded with an Amicable Settlement.');
                }
                return;
            }
            if (isActive || !isConcluded || !isUnresolved) {
                if (typeof showToast === 'function') {
                    showToast('Cannot schedule 2nd Mediation until 1st Mediation has concluded with an unresolved outcome.', 'error');
                } else {
                    alert('Cannot schedule 2nd Mediation until 1st Mediation has concluded with an unresolved outcome.');
                }
                return;
            }
            if (existing.length >= 3) {
                if (typeof showToast === 'function') {
                    showToast('Mediation is limited to a maximum of 3 sessions under Katarungang Pambarangay guidelines.', 'error');
                } else {
                    alert('Mediation is limited to a maximum of 3 sessions under Katarungang Pambarangay guidelines.');
                }
                return;
            }
        }
    }

    window.embeddedHearingsState.activeStageType = stageType;

    const data = window.complaintData || {};
    const comp = data.complaint || data;
    const caseId = data.case_id || comp.case_id || (new URLSearchParams(window.location.search)).get('case_id');
    const complaintId = data.complaint_id || comp.complaint_id || (new URLSearchParams(window.location.search)).get('id');

    const typeInput = document.getElementById('schedHearingType');
    const compIdInput = document.getElementById('schedComplaintId');
    const caseIdInput = document.getElementById('schedCaseId');
    const titleEl = document.getElementById('wsSchedModalTitle');
    const subTitleEl = document.getElementById('wsSchedModalSubtitle');
    const dateLabel = document.getElementById('schedDateLabel');
    const btnSubmit = document.getElementById('submitSchedBtn');

    if (typeInput) typeInput.value = stageType;
    if (compIdInput) compIdInput.value = complaintId || '';
    if (caseIdInput) caseIdInput.value = caseId || '';

    if (titleEl) titleEl.textContent = `Schedule ${stageType} Session`;
    if (subTitleEl) {
        subTitleEl.textContent = stageType === 'Mediation'
            ? 'Enter date and time for Punong Barangay mediation session within the 15-day statutory window (RA 7160 Sec. 410b).'
            : 'Enter date and time for Pangkat Conciliation session within the 15-day statutory conciliation window (RA 7160 Sec. 410c).';
    }
    if (dateLabel) dateLabel.textContent = `${stageType} Date`;
    if (btnSubmit) btnSubmit.textContent = `Schedule ${stageType} Session`;

    if (stageType === 'Arbitration') {
        if (titleEl) titleEl.textContent = 'Schedule Arbitration Session';
        if (subTitleEl) {
            subTitleEl.textContent = 'Enter date and time for voluntary binding arbitration session under RA 7160 Sec. 413.';
        }
        if (dateLabel) dateLabel.textContent = 'Arbitration Date';
        if (btnSubmit) btnSubmit.textContent = 'Schedule Arbitration Session';
        const venueInput = document.getElementById('schedVenue');
        if (venueInput && !venueInput.value) venueInput.value = 'Lupon Court Room';
    }

    // Initialize date constraints (min today)
    const dateInput = document.getElementById('schedDate');
    if (dateInput) {
        const todayStr = (new Date()).toISOString().slice(0, 10);
        dateInput.min = todayStr;
        if (!dateInput.value) {
            const nextDay = new Date();
            nextDay.setDate(nextDay.getDate() + 2);
            dateInput.value = nextDay.toISOString().slice(0, 10);
        }
    }

    modal.style.display = 'flex';
}
window.openEmbeddedScheduleModal = openEmbeddedScheduleModal;
window.openScheduleModal = openEmbeddedScheduleModal;

function closeEmbeddedScheduleModal() {
    const modal = document.getElementById('wsScheduleModal');
    if (modal) modal.style.display = 'none';
}
window.closeEmbeddedScheduleModal = closeEmbeddedScheduleModal;
window.closeScheduleModal = closeEmbeddedScheduleModal;
// Outcome Change & Management for Embedded Stage Modals
function handleEmbeddedStageOutcomeChange(val) {
    const banner = document.getElementById('stageOutcomeArbitrationBanner');
    if (!banner) return;
    if (val === 'Arbitration') {
        banner.style.display = 'block';
    } else {
        banner.style.display = 'none';
    }
}
window.handleEmbeddedStageOutcomeChange = handleEmbeddedStageOutcomeChange;

function openEmbeddedRecordOutcomeModal(stageType = 'Mediation') {
    const modal = document.getElementById('wsRecordOutcomeModal');
    if (!modal) return;
    const typeInput = document.getElementById('stageOutcomeType');
    if (typeInput) typeInput.value = stageType;
    const titleEl = document.getElementById('wsOutcomeModalTitle');
    if (titleEl) titleEl.textContent = `Record ${stageType || ''} Procedural Outcome`;
    const sel = document.getElementById('stageOutcomeSelect');
    if (sel) {
        sel.value = 'Settled';
        handleEmbeddedStageOutcomeChange('Settled');
    }
    const remarks = document.getElementById('stageOutcomeRemarks');
    if (remarks) remarks.value = '';
    modal.style.display = 'flex';
}
window.openEmbeddedRecordOutcomeModal = openEmbeddedRecordOutcomeModal;

function closeEmbeddedRecordOutcomeModal() {
    const modal = document.getElementById('wsRecordOutcomeModal');
    if (modal) modal.style.display = 'none';
}
window.closeEmbeddedRecordOutcomeModal = closeEmbeddedRecordOutcomeModal;

async function submitEmbeddedRecordOutcome(e) {
    if (e && e.preventDefault) e.preventDefault();
    const btn = document.getElementById('submitOutcomeBtn');
    if (btn) btn.disabled = true;

    const stageType = document.getElementById('stageOutcomeType')?.value || 'Mediation';
    const outcomeVal = document.getElementById('stageOutcomeSelect')?.value || '';
    const remarksVal = document.getElementById('stageOutcomeRemarks')?.value || '';
    
    const data = window.complaintData || {};
    const comp = data.complaint || data;
    const caseId = data.case_id || comp.case_id || (new URLSearchParams(window.location.search)).get('case_id');

    if (!caseId) {
        if (typeof showToast === 'function') {
            showToast('Case ID is required to record stage outcome.', 'error');
        } else {
            alert('Case ID is required to record stage outcome.');
        }
        if (btn) btn.disabled = false;
        return;
    }

    try {
        const res = await fetch('../../../backend/api/stages/outcome.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                case_id: Number(caseId),
                stage_type: stageType,
                outcome: outcomeVal,
                remarks: remarksVal
            })
        });
        const json = await res.json();
        if (json.success) {
            closeEmbeddedRecordOutcomeModal();
            if (typeof showToast === 'function') {
                showToast(json.message || `${stageType} outcome recorded successfully!`, 'success');
            } else {
                alert(json.message || `${stageType} outcome recorded successfully!`);
            }

            const currentComplaintId = data.complaint_id || comp.complaint_id || (new URLSearchParams(window.location.search)).get('id');
            if (currentComplaintId && typeof loadComplaintDetails === 'function') {
                await loadComplaintDetails(currentComplaintId);
            }

            // Outcome-driven action prompts:
            if (outcomeVal === 'Arbitration') {
                if (typeof unlockOutcomesTab === 'function') unlockOutcomesTab(Number(caseId));
                if (typeof switchCaseTab === 'function') switchCaseTab('tab-arbitration');
            } else if (outcomeVal === 'Settled') {
                if (typeof unlockOutcomesTab === 'function') unlockOutcomesTab(Number(caseId));
                if (typeof switchCaseTab === 'function') switchCaseTab('tab-arbitration');
                setTimeout(() => {
                    openKpFormModal(Number(caseId), 'KP Form 16');
                }, 400);
            } else if (outcomeVal === 'Repudiated') {
                setTimeout(() => {
                    openKpFormModal(Number(caseId), 'KP Form 17');
                }, 400);
            }
        } else {
            if (typeof showToast === 'function') {
                showToast(json.message || 'Unable to record stage outcome.', 'error');
            } else {
                alert(json.message || 'Unable to record stage outcome.');
            }
        }
    } catch (err) {
        console.error('submitEmbeddedRecordOutcome error:', err);
        if (typeof showToast === 'function') {
            showToast('Network error recording outcome.', 'error');
        } else {
            alert('Network error recording outcome.');
        }
    } finally {
        if (btn) btn.disabled = false;
    }
}
window.submitEmbeddedRecordOutcome = submitEmbeddedRecordOutcome;

// Embedded Pangkat Referral Modal
async function openEmbeddedReferPangkatModal() {
    const modal = document.getElementById('wsReferPangkatModal');
    if (!modal) return;

    const dateInput = document.getElementById('referDate');
    if (dateInput && !dateInput.value) {
        dateInput.value = (new Date()).toISOString().slice(0, 10);
    }

    // Populate Lupon dropdowns if empty
    const chSelect = document.getElementById('referChairmanId');
    if (chSelect && (!chSelect.options || chSelect.options.length <= 1)) {
        try {
            const res = await fetch('../../../backend/api/assignments/lupon-members.php');
            const json = await res.json();
            const members = Array.isArray(json) ? json : (json.data || []);
            window.embeddedHearingsState.luponMembers = members;
            const selects = ['referChairmanId', 'referSecretaryId', 'referMemberId'];
            selects.forEach(id => {
                const sel = document.getElementById(id);
                if (!sel) return;
                let opts = '<option value="">-- Select Lupon Member --</option>';
                members.forEach(m => {
                    const name = `${m.last_name}, ${m.first_name} ${m.middle_name || ''}`.trim();
                    opts += `<option value="${Number(m.member_id)}">${escapeHtml(name)}</option>`;
                });
                sel.innerHTML = opts;
            });
        } catch (e) {
            console.warn('Could not load lupon members:', e);
        }
    }

    goEmbeddedReferralStep(1);
    modal.style.display = 'flex';
}
window.openEmbeddedReferPangkatModal = openEmbeddedReferPangkatModal;

function closeEmbeddedReferPangkatModal() {
    const modal = document.getElementById('wsReferPangkatModal');
    if (modal) modal.style.display = 'none';
}
window.closeEmbeddedReferPangkatModal = closeEmbeddedReferPangkatModal;

function goEmbeddedReferralStep(step) {
    if (step === 2) {
        const reason = document.getElementById('referReason')?.value.trim();
        const date = document.getElementById('referDate')?.value;
        if (!date || !reason) {
            alert('Enter the referral date and authorized referral justification before continuing.');
            return;
        }
    }
    if (step === 3) {
        const ids = ['referChairmanId', 'referSecretaryId', 'referMemberId'].map(id => document.getElementById(id)?.value);
        if (ids.some(id => !id) || new Set(ids).size !== 3) {
            alert('Choose three different active Lupon Members before reviewing the referral.');
            return;
        }
        const nameFor = (id) => document.getElementById(id)?.selectedOptions?.[0]?.textContent || 'Not selected';
        const summary = document.getElementById('referReviewSummary');
        if (summary) {
            summary.innerHTML = `<strong>Step 3 of 3: Review and Confirm Referral</strong><br><br><strong>Referral Date:</strong> ${escapeHtml(document.getElementById('referDate')?.value || '')}<br><strong>Pangkat Chairman:</strong> ${escapeHtml(nameFor('referChairmanId'))}<br><strong>Pangkat Secretary:</strong> ${escapeHtml(nameFor('referSecretaryId'))}<br><strong>Regular Member:</strong> ${escapeHtml(nameFor('referMemberId'))}<br><br>The system will record the statutory Pangkat convening deadline and generate KP Form 10, 11, and 12.`;
        }
    }

    const s1 = document.getElementById('refStep1');
    const s2 = document.getElementById('refStep2');
    const s3 = document.getElementById('refStep3');
    if (s1) s1.style.display = (step === 1 ? 'block' : 'none');
    if (s2) s2.style.display = (step === 2 ? 'block' : 'none');
    if (s3) s3.style.display = (step === 3 ? 'block' : 'none');
}
window.goEmbeddedReferralStep = goEmbeddedReferralStep;

async function submitEmbeddedReferPangkat(e) {
    if (e && e.preventDefault) e.preventDefault();
    const btn = document.getElementById('submitReferBtn');
    if (btn) btn.disabled = true;

    const chairmanId = document.getElementById('referChairmanId')?.value;
    const secretaryId = document.getElementById('referSecretaryId')?.value;
    const memberId = document.getElementById('referMemberId')?.value;

    if (!chairmanId || !secretaryId || !memberId) {
        alert('Please nominate all 3 distinct active Lupon Members for Chairperson, Secretary, and Member.');
        if (btn) btn.disabled = false;
        return;
    }

    if (new Set([chairmanId, secretaryId, memberId]).size !== 3) {
        alert('Chairperson, Secretary, and Member must be different Lupon members.');
        if (btn) btn.disabled = false;
        return;
    }

    const data = window.complaintData || {};
    const comp = data.complaint || data;
    const caseId = data.case_id || comp.case_id || (new URLSearchParams(window.location.search)).get('case_id');

    if (!caseId) {
        alert('Case ID is required to execute referral.');
        if (btn) btn.disabled = false;
        return;
    }

    const payload = {
        case_id: Number(caseId),
        referral_date: document.getElementById('referDate')?.value || (new Date()).toISOString().slice(0, 10),
        referral_reason: document.getElementById('referReason')?.value || 'Mediation before the Punong Barangay failed. Elevated to Pangkat Conciliation.',
        selection_method: document.getElementById('referSelectionMethod')?.value || 'Party Agreement',
        selection_notes: '',
        quorum_size: 3,
        chairman_id: Number(chairmanId),
        secretary_id: Number(secretaryId),
        member_id: Number(memberId)
    };

    try {
        const res = await fetch('../../../backend/api/stages/refer-pangkat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            closeEmbeddedReferPangkatModal();
            if (typeof showToast === 'function') {
                showToast('Case successfully referred to Pangkat ng Tagapagkasundo! KP Form 10, 11, and 12 generated.', 'success');
            } else {
                alert('Case successfully referred to Pangkat ng Tagapagkasundo! KP Form 10, 11, and 12 generated.');
            }
            const currentComplaintId = data.complaint_id || comp.complaint_id || (new URLSearchParams(window.location.search)).get('id');
            if (currentComplaintId && typeof loadComplaintDetails === 'function') {
                await loadComplaintDetails(currentComplaintId);
            }
            // Prompt side-by-side preview
            setTimeout(() => {
                openTriPanelPangkatModal(Number(caseId));
            }, 500);
        } else {
            alert(json.message || 'Unable to refer case to Pangkat.');
        }
    } catch (err) {
        console.error('submitEmbeddedReferPangkat error:', err);
        alert('Network error during referral.');
    } finally {
        if (btn) btn.disabled = false;
    }
}
window.submitEmbeddedReferPangkat = submitEmbeddedReferPangkat;


function handleSchedDateChange(val) {
    // Optional schedule conflict hook
}
window.handleSchedDateChange = handleSchedDateChange;

function handleSchedTimeChange() {
    // Optional schedule conflict hook
}
window.handleSchedTimeChange = handleSchedTimeChange;

async function submitEmbeddedSchedule(e) {
    if (e && e.preventDefault) e.preventDefault();

    const form = document.getElementById('wsScheduleForm');
    if (!form) return;

    const data = window.complaintData || {};
    const comp = data.complaint || data;
    const caseId = Number(document.getElementById('schedCaseId')?.value || data.case_id || comp.case_id);
    const complaintId = Number(document.getElementById('schedComplaintId')?.value || data.complaint_id || comp.complaint_id);
    const hearingType = document.getElementById('schedHearingType')?.value || 'Mediation';
    const dateVal = document.getElementById('schedDate')?.value;
    const timeVal = document.getElementById('schedTime')?.value;
    const venueVal = document.getElementById('schedVenue')?.value || 'Lupon Court Room';
    const remarksVal = document.getElementById('schedRemarks')?.value || '';
    const pastReason = document.getElementById('schedPastDateExceptionReason')?.value || '';

    if (!dateVal || !timeVal) {
        window.agapNotify?.('Please select both a hearing date and time.', 'warning', 'Required');
        return;
    }

    const submitBtn = document.getElementById('submitSchedBtn');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Scheduling...'; }

    try {
        const payload = {
            case_id: caseId,
            complaint_id: complaintId,
            hearing_type: hearingType,
            hearing_date: dateVal,
            hearing_time: timeVal,
            venue: venueVal,
            remarks: remarksVal
        };
        if (pastReason) payload.past_date_exception_reason = pastReason;

        const res = await fetch('../../../backend/api/hearings/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            closeEmbeddedScheduleModal();
            window.agapNotify?.(`${hearingType} session scheduled successfully.`, 'success', 'Session Scheduled');
            await loadComplaintDetails();
            loadHearingSessions(hearingType);
        } else {
            window.agapNotify?.(json.message || 'Unable to schedule hearing session.', 'warning', 'Scheduling Error');
        }
    } catch (err) {
        console.error('submitEmbeddedSchedule error:', err);
        window.agapNotify?.('Network error while scheduling hearing.', 'error', 'Error');
    } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = `Schedule ${hearingType} Session`; }
    }
}
window.submitEmbeddedSchedule = submitEmbeddedSchedule;
window.submitSchedule = submitEmbeddedSchedule;

function setEmbeddedAttendanceActualStartTimeNow() {
    const input = document.getElementById('embAttActualStartTime');
    if (!input) return;
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    input.value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
}
window.setEmbeddedAttendanceActualStartTimeNow = setEmbeddedAttendanceActualStartTimeNow;

function appendEmbeddedDelayRemark(remark) {
    const textarea = document.getElementById('embAttDelayRemarks');
    if (!textarea) return;
    const current = textarea.value.trim();
    if (!current) {
        textarea.value = remark;
    } else if (!current.includes(remark)) {
        textarea.value = current.replace(/[.,;]?$/, '') + '. ' + remark;
    }
    textarea.focus();
}
window.appendEmbeddedDelayRemark = appendEmbeddedDelayRemark;

// Streamlined Attendance Modal (matching mediation workspace)
async function openEmbeddedAttendanceModal(hearingId) {
    const modal = document.getElementById('wsAttendanceModal');
    const body = document.getElementById('wsAttModalBody');
    if (!modal || !body) return;

    modal.style.display = 'flex';
    body.innerHTML = '<div class="empty-detail-state" style="text-align: center; padding: 24px 16px;">Loading attendance records...</div>';

    try {
        const res = await fetch(`../../../backend/api/hearings/attendance.php?hearing_id=${encodeURIComponent(hearingId)}`);
        const json = await res.json();
        if (!json.success || !json.data) {
            throw new Error(json.message || 'Failed to load attendance.');
        }

        window._lastEmbeddedAttendanceData = json.data;
        const hearing = json.data.hearing || json.data || {};
        const parties = json.data.parties || [];

        const titleEl = document.getElementById('wsAttModalTitle');
        if (titleEl) {
            const hType = hearing.hearing_type || 'Mediation';
            titleEl.textContent = `Quick Attendance: ${hType}`;
        }

        const formatForDatetimeLocal = (dtString) => {
            if (!dtString) return '';
            try {
                const d = new Date(dtString.replace(' ', 'T'));
                if (isNaN(d.getTime())) return '';
                const pad = (n) => String(n).padStart(2, '0');
                return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
            } catch { return ''; }
        };

        const formatForTimeInput = (timeString) => {
            if (!timeString) return '';
            const match = timeString.match(/(\d{1,2}):(\d{2})/);
            if (!match) return '';
            let hours = parseInt(match[1], 10);
            const mins = match[2];
            if (/pm/i.test(timeString) && hours < 12) hours += 12;
            if (/am/i.test(timeString) && hours === 12) hours = 0;
            return `${String(hours).padStart(2, '0')}:${mins}`;
        };

        const scheduledVal = formatForDatetimeLocal(hearing.hearing_date);
        const actualStartVal = formatForTimeInput(hearing.actual_start_time || '');
        const delayRemarksVal = hearing.attendance_notes || '';

        let compAtt = 'Present';
        let respAtt = 'Present';
        if (hearing.complainant_attendance) {
            compAtt = hearing.complainant_attendance;
            if (compAtt !== 'Late' && compAtt !== 'Absent') compAtt = 'Present';
        }
        if (hearing.respondent_attendance) {
            respAtt = hearing.respondent_attendance;
            if (respAtt !== 'Late' && respAtt !== 'Absent') respAtt = 'Present';
        }

        const isSaved = Boolean(hearing.attendance_recorded_at);

        // Check if any complainant is absent initially
        const anyCompAbsentInitially = parties.some(p => p.party_type === 'Complainant' && p.attendance_status === 'Absent') || compAtt === 'Absent';

        const renderPartyCard = (p) => {
            const isComp = (p.party_type === 'Complainant');
            let st = p.attendance_status;
            if (!st || st === 'Pending' || st === 'Not Recorded') {
                st = isComp ? compAtt : respAtt;
            }
            if (st !== 'Late' && st !== 'Absent') st = 'Present';

            const roleBadgeStyle = isComp 
                ? 'background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;' 
                : 'background: #fdf2f8; color: #be185d; border: 1px solid #fbcfe8;';
            const roleLabel = isComp ? 'Nagpaanyaya (Complainant)' : 'Inanyayahan (Respondent)';
            const partyName = p.resident_name || p.full_name || (isComp ? 'Complainant' : 'Respondent');

            return `
                <div class="party-att-person-card" style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; background: #f8fafc; display: flex; flex-direction: column; gap: 10px;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                        <div style="min-width: 0;">
                            <div style="font-weight: 700; color: #0f172a; font-size: 0.94rem; line-height: 1.35;">
                                👤 ${escapeHtml(partyName)}
                            </div>
                            ${p.address ? `<div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">📍 ${escapeHtml(p.address)}</div>` : ''}
                        </div>
                        <span style="font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; white-space: nowrap; ${roleBadgeStyle}">
                            ${roleLabel}
                        </span>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label class="party-att-radio-label present" style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 0.83rem; cursor: pointer; color: #15803d; background: #ffffff; padding: 6px 10px; border-radius: 6px; border: 1px solid #dcfce7;">
                            <input type="radio" name="emb_att_party_${p.resident_id}" data-party-type="${escapeHtml(p.party_type)}" value="Present" onchange="checkEmbComplainantAbsence()" ${st === 'Present' ? 'checked' : ''} style="accent-color: #16a34a; width: 15px; height: 15px; margin: 0;">
                            <span>🟢 Dumalo sa Oras (Present)</span>
                        </label>
                        <label class="party-att-radio-label warning" style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 0.83rem; cursor: pointer; color: #b45309; background: #ffffff; padding: 6px 10px; border-radius: 6px; border: 1px solid #fef3c7;">
                            <input type="radio" name="emb_att_party_${p.resident_id}" data-party-type="${escapeHtml(p.party_type)}" value="Late" onchange="checkEmbComplainantAbsence()" ${st === 'Late' ? 'checked' : ''} style="accent-color: #d97706; width: 15px; height: 15px; margin: 0;">
                            <span>🟡 Nahuli / Late (Pasok sa 1-hour grace period)</span>
                        </label>
                        <label class="party-att-radio-label absent" style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 0.83rem; cursor: pointer; color: #dc2626; background: #ffffff; padding: 6px 10px; border-radius: 6px; border: 1px solid #fee2e2;">
                            <input type="radio" name="emb_att_party_${p.resident_id}" data-party-type="${escapeHtml(p.party_type)}" value="Absent" onchange="checkEmbComplainantAbsence()" ${st === 'Absent' ? 'checked' : ''} style="accent-color: #dc2626; width: 15px; height: 15px; margin: 0;">
                            <span>🔴 Hindi Dumalo (Absent - Lampas 1 oras)</span>
                        </label>
                    </div>
                </div>
            `;
        };

        let partiesHtml = '';
        if (parties.length > 0) {
            partiesHtml = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
                    ${parties.map(renderPartyCard).join('')}
                </div>
            `;
        } else {
            const fallbackParties = [
                { resident_id: 1, party_type: 'Complainant', resident_name: hearing.complainant_names || 'Complainant', attendance_status: compAtt },
                { resident_id: 2, party_type: 'Respondent', resident_name: hearing.respondent_names || 'Respondent', attendance_status: respAtt }
            ];
            partiesHtml = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
                    ${fallbackParties.map(renderPartyCard).join('')}
                </div>
            `;
        }

        body.innerHTML = `
            <form id="embAttendanceForm" onsubmit="submitEmbeddedAttendance(event, ${Number(hearingId)})" class="streamlined-attendance-container" style="display: flex; flex-direction: column; gap: 16px;">
                ${isSaved ? `
                    <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 8px 14px; font-size: 0.84rem; color: #166534; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-weight: 600;">✓ Attendance details recorded and saved in database</span>
                        <span style="font-size: 0.78rem; background: #dcfce7; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">STATUS: ${escapeHtml(hearing.status || 'Attendance Recorded')}</span>
                    </div>
                ` : ''}

                <!-- Row 1: Scheduled Date/Time -->
                <div>
                    <label for="embAttScheduledDateTime" style="display: block; font-weight: 600; color: #0f172a; font-size: 0.88rem; margin-bottom: 6px;">
                        Petsa at Takdang Oras (Scheduled Date &amp; Time) <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="datetime-local" id="embAttScheduledDateTime" value="${escapeHtml(scheduledVal)}" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 0.88rem; box-sizing: border-box; background: #fff; font-family: inherit; color: #1e293b;">
                </div>

                <!-- Row 2: Session Delay Remarks -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label for="embAttDelayRemarks" style="font-weight: 600; color: #0f172a; font-size: 0.88rem;">
                            Puna sa Oras ng Simula / Dahilan ng Pagkaantala (Session Delay Remarks)
                        </label>
                        <span style="color: #64748b; font-size: 0.8rem;">
                            (Kung sakaling late nag-umpisa ang session)
                        </span>
                    </div>
                    <textarea id="embAttDelayRemarks" rows="2" placeholder="Halimbawa: Naantala ang simula dahil may naunang kaso sa Lupon Court Room. Present ang mga partido." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 0.86rem; box-sizing: border-box; font-family: inherit; resize: vertical; line-height: 1.45; color: #1e293b;">${escapeHtml(delayRemarksVal)}</textarea>
                    
                    <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap;">
                        <span style="font-size: 0.8rem; color: #64748b; font-weight: 500;">I-click para ilagay:</span>
                        <button type="button" class="delay-helper-pill" onclick="appendEmbeddedDelayRemark('Naunang kaso sa Court Room')">
                            • Naunang kaso sa Court Room
                        </button>
                        <button type="button" class="delay-helper-pill pill-warning" onclick="appendEmbeddedDelayRemark('Nahuli ang panig')">
                            • Nahuli ang panig
                        </button>
                        <button type="button" class="delay-helper-pill pill-warning" style="background: #fef2f2; color: #991b1b; border-color: #fca5a5;" onclick="appendEmbeddedDelayRemark('Lumampas sa 1-hour grace period / Hindi sumipot')">
                            • Lumampas sa 1-hour grace period
                        </button>
                        <button type="button" class="delay-helper-pill pill-success" onclick="appendEmbeddedDelayRemark('Nasa oras')">
                            • Nasa oras
                        </button>
                    </div>
                </div>

                <!-- Row 3: Per-Person Party Attendance (Pagdalo ng Bawat Panig) -->
                <div class="party-attendance-box" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px 20px; background: #fff; margin-top: 4px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #0f172a;">
                            Pagdalo ng Bawat Panig (Per-Person Attendance) <span style="color: #dc2626;">*</span>
                        </h4>
                        <span style="font-size: 0.78rem; color: #64748b;">Itala ang pagdalo ayon sa pangalan</span>
                    </div>
                    ${partiesHtml}
                </div>

                <div id="embCompAbsentNotice" style="display: ${anyCompAbsentInitially ? 'block' : 'none'}; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 8px 12px; margin-top: 4px; font-size: 0.82rem; color: #991b1b;">
                    ⚠️ <strong>May Complainant na Hindi Dumalo:</strong> Ito ay nagbibigay ng opsyon upang i-dismiss ang reklamo alinsunod sa KP rules sa katitikan ng pagdinig (session minutes).
                </div>

                <!-- Actions -->
                <div class="modal-actions" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" class="btn-secondary" onclick="closeEmbeddedAttendanceModal()">Cancel</button>
                    <button type="submit" class="btn-create" id="btnSaveAttendance" style="padding: 8px 22px; font-size: 0.88rem; font-weight: 600; background: #0f172a; color: #fff; border-radius: 6px; cursor: pointer;">
                        ${isSaved ? 'Update Attendance' : 'Save Attendance'}
                    </button>
                </div>
            </form>
        `;
    } catch (err) {
        body.innerHTML = `<p style="color: #dc2626; padding: 14px;">Error: ${escapeHtml(err.message)}</p>`;
    }
}
window.openEmbeddedAttendanceModal = openEmbeddedAttendanceModal;
window.openAttendanceModal = openEmbeddedAttendanceModal;

function checkEmbComplainantAbsence() {
    const compRadios = document.querySelectorAll('input[name^="emb_att_party_"][data-party-type="Complainant"]:checked');
    let anyAbsent = false;
    compRadios.forEach(r => {
        if (r.value === 'Absent') anyAbsent = true;
    });
    const notice = document.getElementById('embCompAbsentNotice');
    if (notice) notice.style.display = anyAbsent ? 'block' : 'none';
}
window.checkEmbComplainantAbsence = checkEmbComplainantAbsence;
window.handleEmbCompAttendanceRadioChange = checkEmbComplainantAbsence;

function closeEmbeddedAttendanceModal() {
    const modal = document.getElementById('wsAttendanceModal');
    if (modal) modal.style.display = 'none';
}
window.closeEmbeddedAttendanceModal = closeEmbeddedAttendanceModal;
window.closeAttendanceModal = closeEmbeddedAttendanceModal;

async function submitEmbeddedAttendance(e, hearingId) {
    if (e && e.preventDefault) e.preventDefault();

    const saveBtn = document.getElementById('btnSaveAttendance');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }

    try {
        const schedDate = document.getElementById('embAttScheduledDateTime')?.value;
        const actualStart = document.getElementById('embAttActualStartTime')?.value;
        const delayRemarks = document.getElementById('embAttDelayRemarks')?.value.trim() || '';

        const currentData = window._lastEmbeddedAttendanceData || {};
        const parties = currentData.parties || [];

        let partyRecords = [];
        let compAtt = 'Present';
        let respAtt = 'Present';

        if (parties.length > 0) {
            partyRecords = parties.map(p => {
                const radio = document.querySelector(`input[name="emb_att_party_${p.resident_id}"]:checked`);
                const st = radio ? radio.value : (p.attendance_status || 'Present');
                const isPresentOrLate = (st === 'Present' || st === 'Late');
                return {
                    resident_id: p.resident_id,
                    party_type: p.party_type,
                    attendance_status: st,
                    is_justified: isPresentOrLate ? 0 : (delayRemarks ? 1 : 0),
                    justification_reason: isPresentOrLate ? null : (delayRemarks || null),
                    verification_status: isPresentOrLate ? 'Verified Served' : 'Pending'
                };
            });

            const compStatuses = partyRecords.filter(p => p.party_type === 'Complainant').map(p => p.attendance_status);
            const respStatuses = partyRecords.filter(p => p.party_type === 'Respondent').map(p => p.attendance_status);

            if (compStatuses.length > 0) {
                if (compStatuses.every(s => s === 'Absent')) compAtt = 'Absent';
                else if (compStatuses.some(s => s === 'Late')) compAtt = 'Late';
                else compAtt = 'Present';
            }
            if (respStatuses.length > 0) {
                if (respStatuses.every(s => s === 'Absent')) respAtt = 'Absent';
                else if (respStatuses.some(s => s === 'Late')) respAtt = 'Late';
                else respAtt = 'Present';
            }
        } else {
            const fallbackCompRadio = document.querySelector('input[name="emb_att_party_1"]:checked');
            const fallbackRespRadio = document.querySelector('input[name="emb_att_party_2"]:checked');
            compAtt = fallbackCompRadio ? fallbackCompRadio.value : 'Present';
            respAtt = fallbackRespRadio ? fallbackRespRadio.value : 'Present';
        }

        const payload = {
            hearing_id: Number(hearingId),
            scheduled_datetime: schedDate ? schedDate.replace('T', ' ') + ':00' : null,
            actual_start_time: actualStart || null,
            delay_remarks: delayRemarks,
            attendance_notes: delayRemarks,
            presider_attendance: 'Present',
            complainant_attendance: compAtt,
            complainant_explanation: compAtt === 'Absent' ? delayRemarks : '',
            respondent_attendance: respAtt,
            respondent_explanation: respAtt === 'Absent' ? delayRemarks : '',
            parties: partyRecords
        };

        const res = await fetch('../../../backend/api/hearings/attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            closeEmbeddedAttendanceModal();
            window.agapNotify?.('Attendance recorded successfully.', 'success', 'Attendance Saved');
            if (typeof loadComplaintDetails === 'function') loadComplaintDetails();
            if (typeof loadHearingSessions === 'function') {
                loadHearingSessions('Mediation');
                loadHearingSessions('Conciliation');
                loadHearingSessions('Arbitration');
            }
        } else {
            window.agapNotify?.(json.message || 'Unable to save attendance.', 'warning', 'Attendance Error');
        }
    } catch (err) {
        console.error('submitEmbeddedAttendance error:', err);
        window.agapNotify?.('Network error saving attendance.', 'error', 'Error');
    } finally {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save Attendance'; }
    }
}
window.submitEmbeddedAttendance = submitEmbeddedAttendance;
window.submitAttendance = submitEmbeddedAttendance;

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

    if (badgeEl) {
        badgeEl.style.display = 'none';
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
        // 15-day outside window restriction removed
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
                if (selectedDayData.is_fully_booked) {
                    noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">Selected date is fully booked (8 of 8 daily mediation sessions).</span>`;
                } else {
                    noticeEl.innerHTML = `<span style="color: #15803d; font-weight: 600;">Date selected: ${selectedDayData.available} slots remaining.</span>`;
                }
            }
        } else {
            noticeEl.textContent = `Click any available day above to select. Fully booked dates are grayed out.`;
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
                conflictAlert.textContent = `Notice: ${result.message}`;
                conflictAlert.style.display = 'block';
                if (submitBtn) submitBtn.disabled = true;
            }
            return;
        }

        // 15-working-day window validation check removed

        // 2. Capacity limit check: Max 8 sessions per day
        const bookedCount = result.total_booked ?? (result.scheduled_mediations ? result.scheduled_mediations.length : 0);
        const maxSlots = result.max_slots || 8;
        const availSlots = result.available_slots ?? Math.max(0, maxSlots - bookedCount);

        if (result.is_fully_booked || bookedCount >= maxSlots) {
            if (conflictAlert) {
                conflictAlert.textContent = `Date Fully Booked: All ${maxSlots} daily mediation slots on this date are occupied. Please select another date.`;
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
                slotsList.innerHTML = `<span style="color: #166534; font-weight: 500;">All 8 session slots available on this date (${dateVal}).</span>`;
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
                conflictAlert.textContent = `Schedule Conflict: ${conflictMsg}`;
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

        // Set default to next business day if not set
        if (!dateInput.value) {
            const nextBizDay = calculateBusinessDays(today, 2);
            dateInput.value = nextBizDay;
        }
    }

    const timeInput = document.getElementById('summonMediationTime');
    if (timeInput) {
        timeInput.setCustomValidity('');
        if (!timeInput.value || timeInput.value < '08:00' || timeInput.value > '17:00') {
            timeInput.value = '09:00';
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

            const paaPrereq = window.complaintData?.paanyaya_stages?.summons_prerequisite;
            const isPaaLocked = paaPrereq && (paaPrereq.allowed === false);

            if (btnInfo.disabled || isPaaLocked) {
                // Visually disabled — mark as gated so handleIssueSummonClick can redirect to proof page or notify
                issueBtn.setAttribute('data-summon-gated', 'true');
                issueBtn.className = issueBtn.closest('.case-bar-right') ? 'btn-case-outline disabled' : 'btn-secondary';
                issueBtn.style.opacity = '0.6';
                issueBtn.style.cursor = 'not-allowed';
                if (isPaaLocked && paaPrereq.message) {
                    issueBtn.title = paaPrereq.message;
                }
            } else if (btnInfo.action === 'view_proof') {
                issueBtn.className = issueBtn.closest('.case-bar-right') ? 'btn-case-outline' : 'btn-secondary';
            } else {
                issueBtn.className = issueBtn.closest('.case-bar-right') ? 'btn-case-outline' : 'btn-create';
            }
        }


        // Render Status Tracker
        renderStatusTracker(progress);
        updateStageTabsLockState();
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

    const statuses = ['Paanyaya', 'Mediation', 'Conciliation', 'CFA', 'Resolution / Closed'];
    let currentStatus = progress.lifecycle_status;
    if (!currentStatus || currentStatus === 'Pre-Docketed (Paanyaya)' || currentStatus === 'Pending') {
        currentStatus = 'Paanyaya';
    } else if (currentStatus === 'Settled' || currentStatus === 'Resolved at Paanyaya / Closed') {
        currentStatus = 'Resolution / Closed';
    }
    if (!statuses.includes(currentStatus)) {
        currentStatus = 'Paanyaya';
    }
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
            if (window.agapNotify) window.agapNotify('Party added successfully.', 'success');
            loadComplaintDetails();
        })
        .catch(error => {
            if (window.agapNotify) window.agapNotify(error.message, 'error');
            else alert(error.message);
        });
}

function deleteParty(partyId) {
    if (!confirm('Remove this party from the complaint?')) return;
    complaintApi('../../../backend/api/complaints/parties.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'party_id=' + encodeURIComponent(partyId)
    }).then(() => {
        if (window.agapNotify) window.agapNotify('Party removed.', 'info');
        loadComplaintDetails();
    }).catch(error => {
        if (window.agapNotify) window.agapNotify(error.message, 'error');
        else alert(error.message);
    });
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
            if (window.agapNotify) window.agapNotify('Attachment uploaded successfully.', 'success');
            loadComplaintDetails();
        })
        .catch(error => {
            if (window.agapNotify) window.agapNotify(error.message, 'error');
            else alert(error.message);
        });
}
function deleteAttachment(attachmentId) {
    if (!confirm('Are you sure you want to delete this attachment?')) return;
    complaintApi('../../../backend/api/complaints/attachments.php', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'attachment_id=' + encodeURIComponent(attachmentId)
    }).then(() => {
        if (window.agapNotify) window.agapNotify('Attachment deleted.', 'info');
        loadComplaintDetails();
    }).catch(error => {
        if (window.agapNotify) window.agapNotify(error.message, 'error');
        else alert(error.message);
    });
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
        warningEl.innerHTML = `<span>${escapeHtml(msg)}</span>`;
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
            res.address ? `${escapeHtml(res.address)}` : '',
            res.purok ? `Purok ${escapeHtml(res.purok)}` : '',
            res.contact_no ? `Tel: ${escapeHtml(res.contact_no)}` : ''
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
                        <span>${escapeHtml(res.purok ? 'Purok ' + res.purok : 'Barangay Tumana')}</span>
                        ${res.address ? `<span>${escapeHtml(res.address)}</span>` : ''}
                        ${res.contact_no ? `<span>Tel: ${escapeHtml(res.contact_no)}</span>` : ''}
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
                                ${p.service_confirmed ? '<span style="color: #15803d; font-weight: 600;">Summons Confirmed Served</span>' : '<span style="color: #64748b;">Summons Service In Progress</span>'}
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
                <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Hearing Date &amp; Time</span>
                <strong style="font-size: 0.92rem; color: #0f172a; display: block;">${escapeHtml(dateFormatted)}</strong>
            </div>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Hearing Venue</span>
                <strong style="font-size: 0.92rem; color: #0f172a; display: block;">${escapeHtml(venue)}</strong>
            </div>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Presiding Authority</span>
                <strong style="font-size: 0.92rem; color: #0f172a; display: block;">${escapeHtml(presidingOfficial)}</strong>
                <span style="font-size: 0.72rem; color: #64748b;">${escapeHtml(presidingRole)}</span>
            </div>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;">
                <span style="font-size: 0.72rem; font-weight: 700; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 2px;">Stage Progression</span>
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
                    <span style="font-size: 0.74rem; font-weight: 700; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 9999px;">${presentCount} Present</span>
                    <span style="font-size: 0.74rem; font-weight: 700; color: #b91c1c; background: #fee2e2; padding: 2px 8px; border-radius: 9999px;">${absentCount} Non-appearance</span>
                    ${excusedCount > 0 ? `<span style="font-size: 0.74rem; font-weight: 700; color: #b45309; background: #fef3c7; padding: 2px 8px; border-radius: 9999px;">${excusedCount} Excused</span>` : ''}
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
                    View Scan
                </a>
                <a href="${downloadUrl}" class="btn-outline-sm" title="Download scan">
                    Download
                </a>
                <button type="button" class="btn-outline-sm" onclick="openUploadScanModal()" title="Upload a clearer or corrected copy">
                    Replace Scan
                </button>
                <button type="button" class="btn-outline-sm" onclick="openScanHistoryModal(${complaintId})" title="View upload and replacement history">
                    Version History (${(complaint.scan_history && complaint.scan_history.length) || 1})
                </button>
            `;
        }
    } else {
        if (isPaanyaya) {
            contentWrap.innerHTML = `
                <div style="padding: 12px; background: #fffbeb; border: 1px dashed #fde68a; border-radius: 8px; text-align: center; color: #92400e; font-size: 0.82rem;">
                    <strong>KP Form 7 is On Hold</strong> during the informal Paanyaya dialogue phase. If the dispute is settled informally, KP Form 7 is never created.
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
                    Upload Handwritten Scan
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

function openKpDocumentPreviewModal(documentId, templateName, caseNumber, caseId = null) {
    const modal = document.getElementById('kpDocumentPreviewModal');
    const iframe = document.getElementById('kpDocPreviewIframe');
    const heading = document.getElementById('kpDocModalHeading');
    const sub = document.getElementById('kpDocModalSub');
    const dlBtn = document.getElementById('btnDownloadKpDocPdf');
    if (!modal || !iframe) return;

    const tName = String(templateName || 'KP Form').trim();
    if (heading) {
        heading.textContent = `${tName} Preview`;
    }
    if (sub && caseNumber) {
        sub.textContent = `Barangay Tumana Lupong Tagapamayapa · Case #${caseNumber}`;
    }

    // Use kp-form.php: checks physical file first; if missing, dynamically renders clean, printable HTML
    let previewUrl = `../../../backend/api/documents/kp-form.php?document_id=${encodeURIComponent(documentId)}&preview=1&bare=1&_t=${Date.now()}`;
    let downloadUrl = `../../../backend/api/documents/kp-form.php?document_id=${encodeURIComponent(documentId)}&pdf=1`;

    // Fallback using case_id and form_code if document_id is missing
    const resolvedCaseId = caseId || window.complaintData?.case_id || (new URLSearchParams(window.location.search)).get('case_id');
    if ((!documentId || Number(documentId) <= 0) && resolvedCaseId) {
        previewUrl = `../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(resolvedCaseId)}&form_code=${encodeURIComponent(tName)}&preview=1&bare=1&_t=${Date.now()}`;
        downloadUrl = `../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(resolvedCaseId)}&form_code=${encodeURIComponent(tName)}&pdf=1`;
    }

    iframe.src = previewUrl;

    if (dlBtn) {
        dlBtn.href = downloadUrl;
        dlBtn.style.display = 'inline-flex';
    }

    modal.style.display = 'flex';
}
window.openKpDocumentPreviewModal = openKpDocumentPreviewModal;

function openTriPanelPangkatModal(caseId) {
    const activeCaseId = caseId || window.complaintData?.case_id || (new URLSearchParams(window.location.search)).get('case_id') || 0;
    if (!activeCaseId) {
        if (typeof notifyUser === 'function') {
            notifyUser('Case ID is required for Pangkat notices preview.', 'warning');
        } else {
            alert('Case ID is required for Pangkat notices preview.');
        }
        return;
    }
    const modal = document.getElementById('pangkatNoticesModal');
    if (!modal) {
        console.warn('pangkatNoticesModal element not found in DOM.');
        return;
    }

    const complaintId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search)).get('id') || '';
    const frame10 = document.getElementById('pangkatPreviewFrameKp10');
    const frame11 = document.getElementById('pangkatPreviewFrameKp11');
    const frame12 = document.getElementById('pangkatPreviewFrameKp12');
    const dl10 = document.getElementById('btnDownloadPangkatKp10');
    const dl11 = document.getElementById('btnDownloadPangkatKp11');
    const dl12 = document.getElementById('btnDownloadPangkatKp12');
    const retComp = document.getElementById('btnPangkatOfficersReturnComp');
    const retResp = document.getElementById('btnPangkatOfficersReturnResp');
    const retFull = document.getElementById('btnPangkatOfficerReturnFull');

    const baseApi = `../../../backend/api/documents/kp-form.php`;

    if (frame10) frame10.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+10&preview=1&bare=1&_t=${Date.now()}`;
    if (frame11) frame11.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+11&preview=1&bare=1&_t=${Date.now()}`;
    if (frame12) frame12.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+12&preview=1&bare=1&_t=${Date.now()}`;

    if (dl10) dl10.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+10&pdf=1`;
    if (dl11) dl11.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+11&pdf=1`;
    if (dl12) dl12.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+12&pdf=1`;

    const gpsUrl = (party) => `../gps/proof-service.php?case_id=${encodeURIComponent(activeCaseId)}&complaint_id=${encodeURIComponent(complaintId)}&stage=Conciliation&form=KP12${party ? `&party=${encodeURIComponent(party)}` : ''}`;
    if (retComp) retComp.href = gpsUrl('Complainant');
    if (retResp) retResp.href = gpsUrl('Respondent');
    if (retFull) retFull.href = gpsUrl();

    modal.style.display = 'flex';
}
window.openTriPanelPangkatModal = openTriPanelPangkatModal;

function closeTriPanelPangkatModal() {
    const modal = document.getElementById('pangkatNoticesModal');
    if (modal) modal.style.display = 'none';
}
window.closeTriPanelPangkatModal = closeTriPanelPangkatModal;

function printPangkatFrame(which) {
    let frameId = 'pangkatPreviewFrameKp10';
    if (which === 'kp11') frameId = 'pangkatPreviewFrameKp11';
    else if (which === 'kp12') frameId = 'pangkatPreviewFrameKp12';
    const iframe = document.getElementById(frameId);
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}
window.printPangkatFrame = printPangkatFrame;

function printAllPangkatNotices() {
    ['kp10', 'kp11', 'kp12'].forEach((which, i) => {
        setTimeout(() => printPangkatFrame(which), i * 1500);
    });
}
window.printAllPangkatNotices = printAllPangkatNotices;

function closeKpDocumentPreviewModal() {
    const modal = document.getElementById('kpDocumentPreviewModal');
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (iframe) iframe.src = 'about:blank';
    if (modal) modal.style.display = 'none';
}
window.closeKpDocumentPreviewModal = closeKpDocumentPreviewModal;

function printKpDocIframe() {
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}
window.printKpDocIframe = printKpDocIframe;

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
                            Download
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
    if (!document.getElementById('tab-summary')) return;
    const caseWorkspace = data.case_workspace || {};
    const caseItem = caseWorkspace.case || {};
    const hearings = caseWorkspace.hearings || [];
    const documents = caseWorkspace.documents || [];
    const parties = data.parties || [];

    // Title & Header
    const isPaanyayaCase = data.status === 'Pre-Docketed (Paanyaya)' || (!caseItem.case_id && !data.case_id);
    const formatDocketDisplay = (num, id, dt) => {
        if (num && String(num).startsWith('P-')) return String(num);
        if (num && !String(num).startsWith('CMP-')) return String(num);
        const d = dt ? new Date(dt) : new Date();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const ss = String(id || 1).padStart(2, '0');
        const yy = d.getFullYear() || 2026;
        return isPaanyayaCase ? `P-${mm}-${ss}-${yy}` : `${mm}-${ss}-${yy}`;
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
    const deadlineText = 'Active Proceeding';
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
                        <span>${escapeHtml(hDate)}</span> &bull; <span>${escapeHtml(h.venue || 'Barangay Hall')}</span>
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
    html = html.replace(/^###\s*(.+)$/gm, '<strong class="brief-heading">$1</strong>');
    html = html.replace(/^##\s*(.+)$/gm, '<strong class="brief-heading">$1</strong>');
    html = html.replace(/^([0-9]+\.\s+[A-Z\s&]+)$/gm, '<strong class="brief-heading">$1</strong>');
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
                        <span class="ai-brief-tag">${result.is_fallback ? 'Structured Case Brief' : 'Gemini AI Legal Briefing'}</span>
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
    const inviteeInput = document.getElementById('modalPaanyayaInvitee');
    const sigInput = document.getElementById('modalPaanyayaHasSig');

    if (inviteeInput) {
        inviteeInput.value = p.invitee_name || window.complaintData?.respondent_name || window.complaintData?.respondent || '';
    }
    if (dateInput) {
        dateInput.value = p.invitation_date || new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);
    }
    if (timeInput) {
        timeInput.value = p.invitation_time ? p.invitation_time.slice(0, 5) : '14:00';
    }
    if (venueInput) {
        venueInput.value = p.venue || 'Tanggapan ng Barangay Tumana';
    }
    if (sigInput) {
        sigInput.value = '1';
        sigInput.checked = true;
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
    const sigInputEl = form.querySelector('input[name="has_e_signature"]');
    const payload = {
        complaint_id: formData.get('complaint_id'),
        invitee_name: formData.get('invitee_name'),
        invitation_date: formData.get('invitation_date'),
        invitation_time: formData.get('invitation_time'),
        venue: formData.get('venue'),
        has_e_signature: sigInputEl ? (sigInputEl.type === 'checkbox' ? (sigInputEl.checked ? 1 : 0) : 1) : 1
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
            if (window.agapNotify) window.agapNotify(err.message || 'Error scheduling Paanyaya.', 'error');
            else alert(err.message || 'Error scheduling Paanyaya.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Issue Paanyaya';
        }
    }
};

// =========================================================
// Elastic Duration Docketing & Daily Capacity Engine
// =========================================================
window.initMediationDocketScheduler = function(options) {
    function esc(s) {
        return String(s ?? '').replace(/[&<>'"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c] || c));
    }

    const root = options.root || document;
    const dateInput = typeof options.dateInput === 'string' ? root.querySelector(options.dateInput) || document.querySelector(options.dateInput) : options.dateInput;
    const timeInput = typeof options.timeInput === 'string' ? root.querySelector(options.timeInput) || document.querySelector(options.timeInput) : options.timeInput;
    const badgeEl = typeof options.badgeEl === 'string' ? root.querySelector(options.badgeEl) || document.querySelector(options.badgeEl) : options.badgeEl;
    const docketEl = typeof options.docketEl === 'string' ? root.querySelector(options.docketEl) || document.querySelector(options.docketEl) : options.docketEl;
    const docketCountEl = typeof options.docketCountEl === 'string' ? root.querySelector(options.docketCountEl) || document.querySelector(options.docketCountEl) : options.docketCountEl;
    const alertEl = typeof options.alertEl === 'string' ? root.querySelector(options.alertEl) || document.querySelector(options.alertEl) : options.alertEl;
    const submitBtn = typeof options.submitBtn === 'string' ? root.querySelector(options.submitBtn) || document.querySelector(options.submitBtn) : options.submitBtn;
    const getComplaintId = options.getComplaintId || (() => window.complaintData?.complaint_id || (new URLSearchParams(window.location.search).get('id')));
    const getCaseId = options.getCaseId || (() => null);

    if (!dateInput) return null;

    const dayMap = { 'Sun': 0, 'Mon': 1, 'Tue': 2, 'Wed': 3, 'Thu': 4, 'Fri': 5, 'Sat': 6 };
    let allowedDayNums = window.AGAP_SETTINGS?.mediation_day_numbers;
    if (!allowedDayNums && window.AGAP_SETTINGS?.mediation_days) {
        allowedDayNums = window.AGAP_SETTINGS.mediation_days.map(d => dayMap[d]).filter(n => n !== undefined);
    }
    if (!allowedDayNums || !allowedDayNums.length) {
        allowedDayNums = [2];
    }
    let medDaysLabel = window.AGAP_SETTINGS?.mediation_days_label || 'Tuesdays';

    function getNextMediationDayStr() {
        const d = new Date();
        d.setDate(d.getDate() + 1);
        let loops = 0;
        while (!allowedDayNums.includes(d.getDay()) && loops < 14) {
            d.setDate(d.getDate() + 1);
            loops++;
        }
        return d.toISOString().slice(0, 10);
    }

    if (!dateInput.value) {
        dateInput.value = getNextMediationDayStr();
    }
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    dateInput.min = tomorrow.toISOString().slice(0, 10);

    if (timeInput && !timeInput.value) {
        timeInput.value = window.AGAP_SETTINGS?.mediation_hours_start || '13:00';
    }

    async function checkSchedule() {
        if (!dateInput.value) return;
        const dateVal = dateInput.value;
        const timeVal = (timeInput && timeInput.value) ? timeInput.value : (window.AGAP_SETTINGS?.mediation_hours_start || '13:00');
        const compId = getComplaintId();
        const cId = getCaseId();

        const parts = dateVal.split('-');
        let isAllowedMedDay = false;
        if (parts.length === 3) {
            const dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
            isAllowedMedDay = allowedDayNums.includes(dt.getDay());
        }

        if (!isAllowedMedDay) {
            if (badgeEl) {
                badgeEl.textContent = 'Non-Mediation Day (Blocked)';
                badgeEl.style.background = '#fef3c7';
                badgeEl.style.color = '#92400e';
                badgeEl.style.border = '1px solid #fcd34d';
            }
            if (alertEl) {
                alertEl.textContent = `Mediation hearings can only be held on configured mediation days (${medDaysLabel}).`;
                alertEl.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = `Please select a configured mediation day (${medDaysLabel}).`;
            }
            if (docketEl) {
                docketEl.innerHTML = `<span style="color: #991b1b; font-size: 0.82rem;">Mediation sessions are held on ${medDaysLabel} only.</span>`;
            }
            return;
        }

        try {
            const params = new URLSearchParams({
                date: dateVal,
                time: timeVal,
                venue: 'Lupon Court Room',
                hearing_type: 'Mediation'
            });
            if (compId) params.set('complaint_id', String(compId));
            if (cId) params.set('case_id', String(cId));

            const res = await fetch(`../../../backend/api/summons/check-schedule.php?${params.toString()}`);
            const data = await res.json();

            const booked = data.total_booked ?? 0;
            const max = data.max_slots ?? 8;
            const isFull = data.is_fully_booked || booked >= max;

            // 1. Capacity Badge
            if (badgeEl) {
                badgeEl.textContent = `${booked} of ${max} daily mediation slots booked` + (isFull ? ' — Date Fully Booked' : '');
                if (isFull) {
                    badgeEl.style.background = '#fee2e2';
                    badgeEl.style.color = '#991b1b';
                    badgeEl.style.border = '1px solid #fca5a5';
                } else if (booked >= 5) {
                    badgeEl.style.background = '#fef3c7';
                    badgeEl.style.color = '#92400e';
                    badgeEl.style.border = '1px solid #fcd34d';
                } else {
                    badgeEl.style.background = '#dcfce7';
                    badgeEl.style.color = '#166534';
                    badgeEl.style.border = '1px solid #86efac';
                }
            }

            // 2. Docket Peek
            if (docketCountEl) {
                docketCountEl.textContent = `${booked} of ${max} slots`;
            }
            if (docketEl) {
                const sessions = data.scheduled_mediations || [];
                if (sessions.length === 0) {
                    docketEl.innerHTML = `<span style="color: #166534; font-weight: 500; font-size: 0.82rem;">✓ All 8 session slots available on this date (${dateVal}).</span>`;
                } else {
                    docketEl.innerHTML = sessions.map(s => {
                        const qStatus = s.queue_status || s.status || 'Waiting';
                        let pillBg = '#eff6ff', pillColor = '#1e40af', pillBorder = '#bfdbfe';
                        if (qStatus === 'In_Session') {
                            pillBg = '#ecfdf5'; pillColor = '#047857'; pillBorder = '#6ee7b7';
                        } else if (qStatus === 'Concluded' || qStatus === 'Completed') {
                            pillBg = '#f1f5f9'; pillColor = '#475569'; pillBorder = '#cbd5e1';
                        }
                        const tDisplay = s.start_time || '';
                        return `
                            <div style="margin-top: 4px; padding: 4px 8px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                <div>
                                    <strong style="color: #0f172a; font-size: 0.84rem;">${esc(tDisplay)}</strong>
                                    <span style="color: #64748b; font-size: 0.78rem; margin-left: 6px;">Case ${esc(s.case_number || 'N/A')}</span>
                                </div>
                                <span style="font-size: 0.7rem; color: ${pillColor}; background: ${pillBg}; border: 1px solid ${pillBorder}; padding: 1px 7px; border-radius: 9999px; font-weight: 700;">
                                    ${esc(qStatus)}
                                </span>
                            </div>
                        `;
                    }).join('');
                }
            }

            // 3. Conflict / Capacity Blocking
            if (isFull) {
                if (alertEl) {
                    alertEl.textContent = `Daily capacity limit reached: All ${max} mediation slots are fully booked for this date (${dateVal}). Please select another date.`;
                    alertEl.style.display = 'block';
                }
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.title = 'Cannot book: Daily capacity ceiling of 8 mediation hearings reached.';
                }
                return;
            }

            if (data.has_conflict && data.conflict) {
                const msg = data.conflict.message || 'Schedule conflict with an active session in the Lupon Court Room.';
                if (alertEl) {
                    alertEl.textContent = msg.startsWith('⚠️') ? msg : `⚠️ Schedule Conflict: ${msg}`;
                    alertEl.style.display = 'block';
                }
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.title = 'Cannot book: Schedule conflict detected.';
                }
            } else {
                if (alertEl) {
                    alertEl.textContent = '';
                    alertEl.style.display = 'none';
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.title = '';
                }
            }
        } catch (err) {
            console.warn('Mediation scheduler check failed:', err);
        }
    }

    dateInput.onchange = checkSchedule;
    if (timeInput) {
        timeInput.onchange = checkSchedule;
        timeInput.oninput = checkSchedule;
    }

    checkSchedule();

    return {
        checkSchedule,
        setTargetTime: (timeStr) => {
            if (timeInput) {
                timeInput.value = timeStr;
                checkSchedule();
            }
        }
    };
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
            submitBtn.disabled = false;
        }
    } else {
        if (modalTitle) modalTitle.textContent = 'Escalate to Formal Docketing (KP Form 7 & 9)';
        if (modalIcon) modalIcon.textContent = '';
        if (resolvedNotice) resolvedNotice.style.display = 'none';
        if (escalateNotice) escalateNotice.style.display = 'block';
        if (escalationSection) escalationSection.style.display = 'block';
        if (submitBtn) {
            submitBtn.textContent = 'Escalate & Issue Summons';
            submitBtn.style.background = '#dc2626';
            submitBtn.style.borderColor = '#dc2626';
        }

        // Initialize Elastic Duration Docketing Scheduler for 1st Mediation
        window.initMediationDocketScheduler({
            dateInput: '#escalationMedDate',
            timeInput: '#escalationMedTime',
            badgeEl: '#escalationCapacityBadge',
            docketEl: '#escalationDocketList',
            docketCountEl: '#escalationDocketCount',
            alertEl: '#escalationConflictAlert',
            submitBtn: '#btnSubmitPaanyayaOutcome',
            getComplaintId: () => compId
        });
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
            if (window.agapNotify) window.agapNotify(err.message || 'Error recording outcome.', 'error');
            else alert(err.message || 'Error recording outcome.');
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
            `<button type="button" class="btn-case-outline" style="padding: 3px 8px; font-size: 0.76rem;" onclick="openPaanyayaProofLightbox('${esc(att.proof_file_path)}')">View</button>` :
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
                        Return
                    </button>
                </td>
                <td style="padding: 10px 12px; color: #64748b; font-size: 0.78rem; white-space: nowrap;">
                    ${esc(att.recorded_by_name || 'Staff')}
                </td>
                <td style="padding: 10px 12px; text-align: right; white-space: nowrap;">
                    <button type="button" class="btn-case-outline" style="padding: 3px 8px; font-size: 0.76rem;" onclick="openEditOfficersReturnModal(${att.id})" title="Permitted administrative correction">
                        Edit
                    </button>
                </td>
            </tr>
        `;
    }).join('');
};

window.openIssuePaanyayaModal = function(stageType) {
    if (stageType === 'Paanyaya 2') {
        const hasMedHearings = (window.embeddedHearingsState?.mediation_sessions?.length > 0) ||
                               (window.complaintData?.hearings?.some(h => (h.hearing_type || '').toLowerCase() === 'mediation'));
        const isMediationGraduated = ['Mediation', 'Conciliation', 'Arbitration', 'Referred to Lupon'].includes(window.complaintData?.status || '') || hasMedHearings;
        if (isMediationGraduated) {
            if (typeof showToast === 'function') {
                showToast('Cannot issue Paanyaya 2: Case has already graduated to formal Mediation proceedings.', 'error');
            } else {
                alert('Cannot issue Paanyaya 2: Case has already graduated to formal Mediation proceedings.');
            }
            return;
        }
    }
    const modal = document.getElementById('issuePaanyayaStageModal');
    if (!modal) return;

    const inputType = document.getElementById('issueStageType');
    const heading = document.getElementById('issueStageModalHeading');
    const sub = document.getElementById('issueStageModalSub');
    const dateInput = document.getElementById('issueStageDate');
    const timeInput = document.getElementById('issueStageTime');
    const venueInput = document.getElementById('issueStageVenue');
    const inviteeInput = document.getElementById('issueStageInvitee');
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
    if (inviteeInput) inviteeInput.value = window.complaintData?.respondent_name || window.complaintData?.respondent || '';
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
    const hasSigInput = form.querySelector('input[name="has_e_signature"]');
    const hasSig = hasSigInput ? (hasSigInput.type === 'checkbox' ? (hasSigInput.checked ? 1 : 0) : parseInt(hasSigInput.value, 10) || 1) : 1;

    const payload = {
        complaint_id: parseInt(formData.get('complaint_id'), 10),
        stage_type: formData.get('stage_type'),
        invitee_name: formData.get('invitee_name'),
        invitation_date: formData.get('invitation_date'),
        invitation_time: formData.get('invitation_time'),
        venue: formData.get('venue'),
        has_e_signature: hasSig
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
            if (window.agapNotify) window.agapNotify(err.message || 'Error issuing invitation.', 'error');
            else alert(err.message || 'Error issuing invitation.');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Issue Invitation & Generate Letter';
        }
    }
};


window.openDocOfficerReturn = function(docId, caseId, templateName = '') {
    const cId = caseId || window.complaintData?.case_id || (new URLSearchParams(window.location.search)).get('case_id');
    const compId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search)).get('id');
    const tNameLower = String(templateName || '').toLowerCase();

    const isConciliation = tNameLower.includes('kp form 12') 
        || tNameLower.includes('blg. 12') 
        || tNameLower.includes('pangkat');

    const stageParam = isConciliation ? '&stage=Conciliation&form=KP12' : '';

    if (cId) {
        window.location.href = `../gps/proof-service.php?case_id=${encodeURIComponent(cId)}${stageParam}` 
            + (docId ? `&document_id=${encodeURIComponent(docId)}` : '');
    } else if (compId) {
        window.location.href = `../gps/proof-service.php?complaint_id=${encodeURIComponent(compId)}${stageParam}`
            + (docId ? `&document_id=${encodeURIComponent(docId)}` : '');
    } else {
        window.location.href = `../gps/proof-service.php?${stageParam.replace('&', '')}`;
    }
};

window.openRecordReturnModal = function(stageType = 'Paanyaya 1') {
    const modal = document.getElementById('recordOfficersReturnModal');
    if (!modal) {
        const params = new URLSearchParams(window.location.search);
        const complaintId = params.get('id') || params.get('complaint_id') || window.complaintData?.complaint_id;
        if (complaintId) {
            window.location.href = `../gps/proof-service.php?complaint_id=${encodeURIComponent(complaintId)}&stage=${encodeURIComponent(stageType || 'Paanyaya 1')}&open_return=1`;
        }
        return;
    }

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
    if (officerSelect) {
        if (targetStage?.details?.assigned_officer_id) {
            officerSelect.value = targetStage.details.assigned_officer_id;
        } else if (!officerSelect.value && officerSelect.options.length > 1) {
            officerSelect.selectedIndex = 1;
        }
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
                gpsStatus.textContent = `Captured: ${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)} (Accuracy ±${Math.round(pos.coords.accuracy)}m)`;
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
            if (window.agapNotify) window.agapNotify(err.message || 'Error recording return.', 'error');
            else alert(err.message || 'Error recording return.');
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
                    View Attached Proof Document
                </button>
            </div>
        ` : '<span style="color: #94a3b8; font-size: 0.84rem;">No proof file attached</span>';

        const gpsSection = (d.latitude && d.longitude) ? `
            <span>${esc(d.latitude)}, ${esc(d.longitude)} &bull; <a href="https://www.google.com/maps?q=${esc(d.latitude)},${esc(d.longitude)}" target="_blank" style="color: #2563eb; text-decoration: underline;">View on Map</a></span>
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
            if (window.agapNotify) window.agapNotify(err.message || 'Error saving correction.', 'error');
            else alert(err.message || 'Error saving correction.');
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
            if (window.agapNotify) window.agapNotify(err.message || 'Error recording review decision.', 'error');
            else alert(err.message || 'Error recording review decision.');
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
};

// ===================================================================
// TEMPORARY MINUTES OF THE MEETING & COMPLAINANT DECISION HANDLERS
// ===================================================================

window.setMinutesActualStartNow = function() {
    const el = document.getElementById('minutesActualStartTime');
    if (!el) return;
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    el.value = `${hh}:${mm}`;
};

window.applyDelayRemark = function(remark) {
    const el = document.getElementById('minutesSessionRemarks');
    if (!el) return;
    el.value = remark;
    el.focus();
};

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
        const actualStart = document.getElementById('minutesActualStartTime');
        const sessionRemarks = document.getElementById('minutesSessionRemarks');
        if (summary) summary.value = '';
        if (terms) terms.value = '';
        if (file) file.value = '';
        if (sessionRemarks) sessionRemarks.value = '';
        if (actualStart) {
            const now = new Date();
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            actualStart.value = `${hh}:${mm}`;
        }

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
    const agreedSection = document.getElementById('minutesAgreedTermsSection');
    const submitBtn = document.getElementById('btnSubmitPaanyayaMinutes');

    if (outcome === 'Settled') {
        if (agreedSection) agreedSection.style.display = 'block';
        if (choiceSection) choiceSection.style.display = 'none';
        const escSec = document.getElementById('minutesEscalationScheduleSection');
        if (escSec) escSec.style.display = 'none';
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Close Complaint (Nagkasundo)';
            submitBtn.style.background = '#16a34a';
            submitBtn.style.borderColor = '#16a34a';
        }
    } else {
        if (agreedSection) agreedSection.style.display = 'none';
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
            if (window.agapNotify) window.agapNotify(err.message || 'Error recording dialogue minutes.', 'error');
            else alert(err.message || 'Error recording dialogue minutes.');
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
            outcomeBadge = '<span class="outcome-badge outcome-delivered">Nagkasundo / Amicably Settled</span>';
        } else if (minutes.complainant_choice === 'Request_Paanyaya_2') {
            outcomeBadge = '<span class="outcome-badge outcome-unavailable">Walang Kasunduan – Humiling ng 2nd Paanyaya</span>';
        } else if (minutes.complainant_choice === 'Escalate_Mediation') {
            outcomeBadge = '<span class="outcome-badge outcome-refused">Inakyat sa Pormal na Mediation</span>';
        }

        let fileAttachmentHtml = '<span style="color: #94a3b8; font-style: italic;">Walang naka-attach na dokumento</span>';
        if (minutes.scanned_minutes_path) {
            fileAttachmentHtml = `<a href="../../../${esc(minutes.scanned_minutes_path)}" target="_blank" style="color: #2563eb; text-decoration: underline; font-weight: 600;">Tingnan ang Naka-attach na Katitikan / Larawan</a>`;
        }

        body.innerHTML = `
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 0.82rem; font-weight: 700; color: #1e293b;">${esc(minutes.stage_type)}</span>
                    <span style="font-size: 0.78rem; color: #64748b;">Itinala noong: ${formatDateReadable(minutes.dialogue_date || minutes.created_at)}</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.83rem;">
                    <div><strong>Nagpaanyaya:</strong> <span style="color: ${minutes.complainant_attended === 'Present' ? '#166534' : '#991b1b'};">${minutes.complainant_attended === 'Present' ? 'Dumalo' : 'Hindi Dumalo'}</span></div>
                    <div><strong>Inanyayahan:</strong> <span style="color: ${minutes.respondent_attended === 'Present' ? '#166534' : '#991b1b'};">${minutes.respondent_attended === 'Present' ? 'Dumalo' : 'Hindi Dumalo'}</span></div>
                    <div><strong>Nagtala:</strong> <span>${esc(minutes.recorded_by_name || 'Opisyal ng Barangay')}</span></div>
                    <div><strong>Kinalabasan:</strong> ${outcomeBadge}</div>
                    ${minutes.actual_start_time ? `<div><strong>Aktwal na Simula:</strong> <span style="font-weight: 600; color: #1e293b;">${esc(minutes.actual_start_time)}</span></div>` : ''}
                </div>
            </div>

            ${minutes.session_remarks ? `
            <div style="margin-bottom: 14px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 12px;">
                <strong style="font-size: 0.84rem; color: #92400e; display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                    <span>⚠️ Puna sa Oras ng Simula / Dahilan ng Pagkaantala:</span>
                </strong>
                <div style="font-size: 0.85rem; color: #78350f; font-style: italic;">“${esc(minutes.session_remarks)}”</div>
            </div>
            ` : ''}

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
// Paanyaya Dialogue Minutes & 2nd Paanyaya Inline Scheduling
// =========================================================
window.initP2ScheduleDefaults = function(form = null) {
    const root = form || document;
    const p2Date = root.querySelector('#minutesP2Date') || root.querySelector('input[name="p2_scheduled_date"]');
    const p2Time = root.querySelector('#minutesP2Time') || root.querySelector('input[name="p2_scheduled_time"]');

    if (p2Date) {
        const tom = new Date();
        tom.setDate(tom.getDate() + 1);
        p2Date.min = tom.toISOString().split('T')[0];

        if (!p2Date.value) {
            let d = new Date();
            let added = 0;
            while (added < 3) {
                d.setDate(d.getDate() + 1);
                if (d.getDay() !== 0 && d.getDay() !== 6) added++;
            }
            p2Date.value = d.toISOString().split('T')[0];
        }
    }
    if (p2Time && !p2Time.value) {
        p2Time.value = '14:00';
    }
};

window.toggleComplainantChoiceViews = function(contextForm = null) {
    const form = contextForm || document.getElementById('recordDialogueMinutesForm') || document.getElementById('modalRecordDialogueMinutesForm');
    if (!form) return;

    const outcome = form.querySelector('input[name="outcome"]:checked')?.value;
    const choice = form.querySelector('input[name="complainant_choice"]:checked')?.value || 'Request_Paanyaya_2';

    const agreedSec = form.querySelector('#minutesAgreedTermsSection') || form.querySelector('.modal-minutes-agreed-section');
    const choiceSec = form.querySelector('#minutesComplainantChoiceSection') || form.querySelector('.modal-minutes-choice-section');
    const p2Sec = form.querySelector('#minutesPaanyaya2ScheduleSection');
    const escSec = form.querySelector('#minutesEscalationScheduleSection');
    const submitBtn = form.querySelector('button[type="submit"]') || document.getElementById('btnSubmitMinutes');

    const p2DateInput = form.querySelector('input[name="p2_scheduled_date"]');
    const p2TimeInput = form.querySelector('input[name="p2_scheduled_time"]');
    const medDateInput = form.querySelector('input[name="mediation_date"]');

    if (outcome === 'Settled') {
        if (agreedSec) agreedSec.style.display = 'block';
        if (choiceSec) choiceSec.style.display = 'none';
        if (p2Sec) p2Sec.style.display = 'none';
        if (escSec) escSec.style.display = 'none';
        if (p2DateInput) p2DateInput.required = false;
        if (p2TimeInput) p2TimeInput.required = false;
        if (medDateInput) medDateInput.required = false;
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Close Complaint (Nagkasundo)';
            submitBtn.style.background = '#16a34a';
            submitBtn.style.borderColor = '#16a34a';
            submitBtn.disabled = false;
        }
        return;
    }

    if (agreedSec) agreedSec.style.display = 'none';
    if (choiceSec) choiceSec.style.display = 'block';

    if (choice === 'Request_Paanyaya_2') {
        if (p2Sec) {
            p2Sec.style.display = 'block';
            p2Sec.style.opacity = '1';
        }
        if (escSec) escSec.style.display = 'none';
        if (p2DateInput) p2DateInput.required = true;
        if (p2TimeInput) p2TimeInput.required = true;
        if (medDateInput) medDateInput.required = false;
        window.initP2ScheduleDefaults(form);
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Schedule 2nd Paanyaya';
            submitBtn.style.background = '#2563eb';
            submitBtn.style.borderColor = '#2563eb';
            submitBtn.disabled = false;
        }
    } else if (choice === 'Escalate_Mediation') {
        if (p2Sec) p2Sec.style.display = 'none';
        if (escSec) {
            escSec.style.display = 'block';
            escSec.style.opacity = '1';
        }
        if (p2DateInput) p2DateInput.required = false;
        if (p2TimeInput) p2TimeInput.required = false;
        if (medDateInput) medDateInput.required = true;
        if (submitBtn) {
            submitBtn.textContent = 'Save Minutes & Escalate to Mediation';
            submitBtn.style.background = '#dc2626';
            submitBtn.style.borderColor = '#dc2626';
        }
        if (typeof checkMinutesScheduleConflict === 'function') {
            checkMinutesScheduleConflict();
        }
    } else {
        if (p2Sec) p2Sec.style.display = 'none';
        if (escSec) escSec.style.display = 'none';
        if (p2DateInput) p2DateInput.required = false;
        if (p2TimeInput) p2TimeInput.required = false;
        if (medDateInput) medDateInput.required = false;
    }
};

window.openRecordDialogueMinutesModal = function(stageType = 'Paanyaya 1') {
    const cId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search)).get('id');
    if (cId) {
        window.location.href = `record-dialogue-minutes.php?id=${encodeURIComponent(cId)}&stage=${encodeURIComponent(stageType)}`;
        return;
    }
    const modal = document.getElementById('recordDialogueMinutesModal');
    if (!modal) return;
    const form = document.getElementById('modalRecordDialogueMinutesForm');
    if (form) {
        form.reset();
        const stageInput = form.querySelector('input[name="stage_type"]');
        if (stageInput) stageInput.value = stageType;

        const heading = document.getElementById('recordMinutesModalHeading');
        if (heading) heading.textContent = `Itala ang Katitikan ng Pag-uusap (${stageType})`;

        // Set default dialogue date to now
        const dtInput = form.querySelector('input[name="dialogue_date"]');
        if (dtInput) {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            dtInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
        }
        const actTime = form.querySelector('input[name="actual_start_time"]');
        if (actTime) {
            const now = new Date();
            actTime.value = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        }

        const p2ChoiceWrap = form.querySelector('#modalOptChoiceP2Wrap');
        if (p2ChoiceWrap) {
            p2ChoiceWrap.style.display = (stageType === 'Paanyaya 2') ? 'none' : 'flex';
            if (stageType === 'Paanyaya 2') {
                const escChoice = form.querySelector('input[name="complainant_choice"][value="Escalate_Mediation"]');
                if (escChoice) escChoice.checked = true;
            } else {
                const p2Choice = form.querySelector('input[name="complainant_choice"][value="Request_Paanyaya_2"]');
                if (p2Choice) p2Choice.checked = true;
            }
        }

        window.initP2ScheduleDefaults(form);

        if (window.paanyayaStagesData?.serving_officers) {
            populatePaanyayaOfficersSelect(window.paanyayaStagesData.serving_officers);
        }

        window.toggleComplainantChoiceViews(form);
    }
    modal.style.display = 'flex';
};

window.closeRecordDialogueMinutesModal = function() {
    const modal = document.getElementById('recordDialogueMinutesModal');
    if (modal) modal.style.display = 'none';
};

window.handleModalDialogueMinutesSubmit = async function(event) {
    event.preventDefault();
    const form = event.target;
    const btn = form.querySelector('button[type="submit"]');
    const errBox = form.querySelector('.modal-minutes-error');

    const outcome = form.querySelector('input[name="outcome"]:checked')?.value;
    const choice = form.querySelector('input[name="complainant_choice"]:checked')?.value;

    if (outcome !== 'Settled' && choice === 'Request_Paanyaya_2') {
        const p2Date = form.querySelector('input[name="p2_scheduled_date"]')?.value?.trim();
        const p2Time = form.querySelector('input[name="p2_scheduled_time"]')?.value?.trim();
        if (!p2Date || !p2Time) {
            if (errBox) {
                errBox.textContent = 'Please specify the scheduled date and time for the 2nd Paanyaya dialogue.';
                errBox.style.display = 'block';
            }
            return;
        }
    }

    const formData = new FormData(form);
    try {
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving dialogue minutes...';
        }
        if (errBox) errBox.style.display = 'none';

        const res = await fetch('../../../backend/api/paanyaya/record-minutes.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            window.agapNotify?.(data.message || 'Dialogue minutes recorded successfully!', 'success');
            window.closeRecordDialogueMinutesModal();
            if (typeof loadComplaintDetails === 'function') {
                await loadComplaintDetails();
            } else {
                window.location.reload();
            }
        } else {
            throw new Error(data.message || 'Failed to record dialogue minutes.');
        }
    } catch (err) {
        if (errBox) {
            errBox.textContent = err.message || 'An error occurred while saving minutes.';
            errBox.style.display = 'block';
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            window.toggleComplainantChoiceViews(form);
        }
    }
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
        if (window.agapNotify) window.agapNotify('Please describe the non-compliance reason before proceeding.', 'warning');
        else alert('Please describe the non-compliance reason before proceeding.');
        return;
    }

    const caseId = window._reopenCaseCaseId || window.currentCaseId || window.complaintData?.case_id || window.complaintData?.case_workspace?.case?.case_id;
    if (!caseId) {
        if (window.agapNotify) window.agapNotify('Case ID not found. Please reload the page.', 'error');
        else alert('Case ID not found. Please reload the page.');
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
            if (window.agapNotify) window.agapNotify(json.message || 'Unable to reopen case. Please try again.', 'error');
            else alert(json.message || 'Unable to reopen case. Please try again.');
            if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = 'Reopen &amp; Issue CFA'; }
            if (cancelBtn) cancelBtn.disabled = false;
        }
    } catch (err) {
        if (window.agapNotify) window.agapNotify('Network error. Please try again.', 'error');
        else alert('Network error. Please try again.');
        if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = 'Reopen &amp; Issue CFA'; }
        if (cancelBtn) cancelBtn.disabled = false;
    }
};


// Modal Helpers & Aliases for KP Forms & Multi-Panel Pangkat Preview
function openKpFormModal(caseId, formCode, hearingId = null, complaintId = null) {
    const modal = document.getElementById('kpDocumentPreviewModal');
    const iframe = document.getElementById('kpDocPreviewIframe');
    const heading = document.getElementById('kpDocModalHeading');
    const sub = document.getElementById('kpDocModalSub');
    const dlBtn = document.getElementById('btnDownloadKpDocPdf');

    const cData = window.complaintData || {};
    const ws = window.__agapComplaintWorkspace || cData.case_workspace || {};
    const activeComplaintId = complaintId || cData.complaint_id || cData.complaint?.complaint_id || (new URLSearchParams(window.location.search)).get('id') || '';
    const activeCaseId = caseId || ws.case_id || ws.case?.case_id || cData.case_id || cData.complaint?.case_id || window.currentCaseId || activeComplaintId;

    if (heading) heading.textContent = `${formCode} Preview`;
    if (sub) {
        if (activeCaseId && String(activeCaseId) !== String(activeComplaintId)) {
            sub.textContent = `Barangay Tumana Lupong Tagapamayapa · Case #${activeCaseId}`;
        } else if (cData.complaint_number || cData.complaint?.complaint_number) {
            sub.textContent = `Barangay Tumana Lupong Tagapamayapa · Complaint #${cData.complaint_number || cData.complaint?.complaint_number}`;
        } else {
            sub.textContent = `Barangay Tumana Lupong Tagapamayapa Official Katarungang Pambarangay Form`;
        }
    }

    const baseApi = `../../../backend/api/documents/kp-form.php`;
    let src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&complaint_id=${encodeURIComponent(activeComplaintId)}&form_code=${encodeURIComponent(formCode)}&preview=1&bare=1`;
    let dl = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&complaint_id=${encodeURIComponent(activeComplaintId)}&form_code=${encodeURIComponent(formCode)}&pdf=1`;
    if (hearingId) {
        src += `&hearing_id=${encodeURIComponent(hearingId)}`;
        dl += `&hearing_id=${encodeURIComponent(hearingId)}`;
    }

    if (iframe) iframe.src = src;
    if (dlBtn) dlBtn.href = dl;
    if (modal) modal.style.display = 'flex';
}
window.openKpFormModal = openKpFormModal;
window.openKPFormPreview = openKpFormModal;

function openTriPanelPangkatModal(caseId) {
    const activeCaseId = caseId || (window.complaintData ? (window.complaintData.case_id || window.complaintData.complaint?.case_id) : '');
    const modal = document.getElementById('pangkatNoticesModal');
    if (!modal) {
        console.warn('pangkatNoticesModal element not found in DOM.');
        return;
    }

    const complaintId = (window.complaintData ? (window.complaintData.complaint_id || window.complaintData.complaint?.complaint_id) : '') || '';
    const frame10 = document.getElementById('pangkatPreviewFrameKp10');
    const frame11 = document.getElementById('pangkatPreviewFrameKp11');
    const frame12 = document.getElementById('pangkatPreviewFrameKp12');
    const dl10 = document.getElementById('btnDownloadPangkatKp10');
    const dl11 = document.getElementById('btnDownloadPangkatKp11');
    const dl12 = document.getElementById('btnDownloadPangkatKp12');
    const retComp = document.getElementById('btnPangkatOfficersReturnComp');
    const retResp = document.getElementById('btnPangkatOfficersReturnResp');
    const retFull = document.getElementById('btnPangkatOfficerReturnFull');

    const baseApi = `../../../backend/api/documents/kp-form.php`;

    if (frame10) frame10.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+10&preview=1&bare=1`;
    if (frame11) frame11.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+11&preview=1&bare=1`;
    if (frame12) frame12.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+12&preview=1&bare=1`;

    if (dl10) dl10.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+10&pdf=1`;
    if (dl11) dl11.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+11&pdf=1`;
    if (dl12) dl12.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+12&pdf=1`;

    const gpsUrl = (party) => `../gps/proof-service.php?case_id=${encodeURIComponent(activeCaseId)}&complaint_id=${encodeURIComponent(complaintId)}&stage=Conciliation&form=KP12${party ? `&party=${encodeURIComponent(party)}` : ''}`;
    if (retComp) retComp.href = gpsUrl('Complainant');
    if (retResp) retResp.href = gpsUrl('Respondent');
    if (retFull) retFull.href = gpsUrl();

    modal.style.display = 'flex';
}
window.openTriPanelPangkatModal = openTriPanelPangkatModal;

function closeTriPanelPangkatModal() {
    const modal = document.getElementById('pangkatNoticesModal');
    if (modal) modal.style.display = 'none';
}
window.closeTriPanelPangkatModal = closeTriPanelPangkatModal;

function printPangkatFrame(which) {
    let frameId = 'pangkatPreviewFrameKp10';
    if (which === 'kp11') frameId = 'pangkatPreviewFrameKp11';
    else if (which === 'kp12') frameId = 'pangkatPreviewFrameKp12';
    const iframe = document.getElementById(frameId);
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}
window.printPangkatFrame = printPangkatFrame;

function printAllPangkatNotices() {
    ['kp10', 'kp11', 'kp12'].forEach((which, i) => {
        setTimeout(() => printPangkatFrame(which), i * 1500);
    });
}
window.printAllPangkatNotices = printAllPangkatNotices;

window.renderHeroKpFormsList = renderOfficialKpForms;



/* =========================================================================
 * AMICABLE SETTLEMENT & STATUTORY TRANSMITTAL WORKSPACE (RA 7160)
 * ========================================================================= */

function renderAmicableSettlementWorkspace(workspace, complaint) {
    const comp = complaint || window.complaintData || {};
    const ws = workspace || window.__agapComplaintWorkspace || comp.case_workspace || {};
    const caseId = comp.case_id || ws.case?.case_id || (new URLSearchParams(window.location.search)).get('case_id') || (new URLSearchParams(window.location.search)).get('id');
    const settlement = ws.settlement || {};
    const transmittal = ws.transmittal || {};

    const isRepudiated = settlement.repudiation_status === 'Repudiated' ||
                         transmittal.is_repudiated === true ||
                         comp.case_status === 'Repudiated' ||
                         ws.case?.case_status === 'Repudiated';

    const remainingDays = typeof transmittal.working_days_remaining !== 'undefined'
        ? Number(transmittal.working_days_remaining)
        : (typeof settlement.working_days_remaining !== 'undefined' ? Number(settlement.working_days_remaining) : 10);

    const isLocked = !isRepudiated && (Boolean(transmittal.is_locked) || remainingDays > 0);

    // 0. Update Settlement Header Card & Badge
    const settledBadge = document.getElementById('stageSettledBadge');
    const settledCard = document.getElementById('stageSettledCard');
    const settledTitle = document.getElementById('stageSettledTitle') || (settledCard ? settledCard.querySelector('h3') : null);

    if (isRepudiated) {
        if (settledBadge) {
            settledBadge.innerHTML = '⚠ REPUDIATED';
            settledBadge.style.background = '#fee2e2';
            settledBadge.style.color = '#b91c1c';
            settledBadge.style.border = '1px solid #fca5a5';
        }
        if (settledCard) {
            settledCard.style.borderColor = '#fca5a5';
            settledCard.style.background = 'linear-gradient(135deg, #fef2f2 0%, #fff1f2 100%)';
        }
        if (settledTitle) {
            settledTitle.textContent = 'Repudiated Settlement (KP Form 17 / CFA)';
            settledTitle.style.color = '#991b1b';
        }
    } else {
        if (settledBadge) {
            settledBadge.innerHTML = '✓ Amicably Settled';
            settledBadge.style.background = '#dcfce7';
            settledBadge.style.color = '#166534';
            settledBadge.style.border = '1px solid #86efac';
        }
        if (settledCard) {
            settledCard.style.borderColor = '#86efac';
            settledCard.style.background = 'linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%)';
        }
        if (settledTitle) {
            settledTitle.textContent = 'Amicable Settlement (Kasunduan)';
            settledTitle.style.color = '#064e3b';
        }
    }

    // 1. Metric: Execution Date
    const settleDateEl = document.getElementById('metricSettlementDate');
    if (settleDateEl) {
        settleDateEl.textContent = settlement.settlement_date ? formatDateReadable(settlement.settlement_date) : 'Recently Executed';
    }

    // 2. Metric: 10-Working-Day Expiry
    const deadlineEl = document.getElementById('metricRepudiationDeadline');
    if (deadlineEl) {
        const deadlineDate = transmittal.repudiation_deadline || settlement.repudiation_deadline;
        deadlineEl.textContent = deadlineDate ? formatDateReadable(deadlineDate) : '10 Working Days from execution';
    }

    // 3. Metric: Repudiation Status / Countdown
    const badgeEl = document.getElementById('metricCountdownBadge');
    if (badgeEl) {
        if (isRepudiated) {
            badgeEl.innerHTML = '<span class="status-pill" style="background: #fee2e2; color: #b91c1c; font-weight: 800; font-size: 0.8rem; padding: 2px 8px; border-radius: 9999px;">⚠️ Repudiated (Void)</span>';
        } else if (isLocked) {
            badgeEl.innerHTML = `<span class="status-pill" style="background: #fef3c7; color: #b45309; font-weight: 800; font-size: 0.8rem; padding: 2px 8px; border-radius: 9999px;">⏳ ${remainingDays} Working Days Left</span>`;
        } else {
            badgeEl.innerHTML = '<span class="status-pill" style="background: #dcfce7; color: #15803d; font-weight: 800; font-size: 0.8rem; padding: 2px 8px; border-radius: 9999px;">🔓 Statutory Window Expired</span>';
        }
    }

    // 4. Metric: Legal Force & Effect (if element exists)
    const enforceEl = document.getElementById('metricEnforceability');
    if (enforceEl) {
        if (isRepudiated) {
            enforceEl.innerHTML = '<span style="color: #b91c1c;">Null & Void (Repudiated)</span>';
        } else if (isLocked) {
            enforceEl.innerHTML = '<span style="color: #b45309;">Conditional (Sec. 416 Window)</span>';
        } else {
            enforceEl.innerHTML = '<span style="color: #15803d;">Final & Executory Judgment</span>';
        }
    }

    // 5. Repudiation Callout
    const repCallout = document.getElementById('calloutRepudiatedAlert');
    const repNotes = document.getElementById('calloutRepudiatedNotes');
    if (repCallout) {
        if (isRepudiated) {
            repCallout.style.display = 'block';
            if (repNotes) {
                const reason = settlement.repudiation_reason || 'Consent was vitiated by fraud, violence, or intimidation.';
                const dt = settlement.repudiation_date ? ` (${formatDateReadable(settlement.repudiation_date)})` : '';
                repNotes.textContent = `${reason}${dt} — Amicable settlement is invalidated. Case is routed to issue KP Form 20 (CFA).`;
            }
        } else {
            repCallout.style.display = 'none';
        }
    }

    // 6. Action Buttons
    const btnFileRep = document.getElementById('btnActionFileRepudiation');
    const btnIssueExec = document.getElementById('btnActionIssueExecution');
    const btnRecTrans = document.getElementById('btnActionRecordTransmittal');

    if (btnFileRep) {
        btnFileRep.style.display = (!isRepudiated && isLocked) ? 'inline-flex' : 'none';
    }
    if (btnIssueExec) {
        btnIssueExec.style.display = (!isRepudiated && !isLocked) ? 'inline-flex' : 'none';
    }
    if (btnRecTrans) {
        btnRecTrans.style.display = (!isRepudiated && !isLocked) ? 'inline-flex' : 'none';
    }

    // 7. Populate KP Form 16 Live Preview
    if (caseId) {
        const kp16Iframe = document.getElementById('dualKp16PreviewIframe');
        const dlKp16 = document.getElementById('btnDownloadDualKp16Pdf');
        const activeComplaintId = comp.complaint_id || (new URLSearchParams(window.location.search)).get('id') || caseId;
        const kp16Url = `../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(caseId)}&complaint_id=${encodeURIComponent(activeComplaintId)}&form_code=KP+Form+16&preview=1&bare=1`;
        if (kp16Iframe && (!kp16Iframe.src || kp16Iframe.src === 'about:blank' || !kp16Iframe.src.includes(`case_id=${caseId}`))) {
            kp16Iframe.src = kp16Url;
        }
        if (dlKp16) {
            dlKp16.href = `../../../backend/api/documents/kp-form.php?case_id=${encodeURIComponent(caseId)}&complaint_id=${encodeURIComponent(activeComplaintId)}&form_code=KP+Form+16&pdf=1`;
        }

        // Card 2: Court Transmittal Letter
        const transIframe = document.getElementById('dualTransmittalPreviewIframe');
        const btnPrintTrans = document.getElementById('btnDualPrintTransmittal');
        const dlTrans = document.getElementById('btnDownloadDualTransmittalPdf');
        const chipTransLock = document.getElementById('dualTransmittalCountdownChip');
        const badgeTrans = document.getElementById('dualTransmittalStatusBadge');
        const transUrl = `../../../backend/api/transmittals/preview.php?case_id=${encodeURIComponent(caseId)}&complaint_id=${encodeURIComponent(activeComplaintId)}&html=1`;

        if (transIframe && (!transIframe.src || transIframe.src === 'about:blank' || !transIframe.src.includes(`case_id=${caseId}`))) {
            transIframe.src = transUrl;
        }

        if (isRepudiated) {
            if (badgeTrans) {
                badgeTrans.textContent = '⚠️ Repudiated';
                badgeTrans.style.background = '#fee2e2';
                badgeTrans.style.color = '#b91c1c';
            }
            if (chipTransLock) chipTransLock.innerHTML = '<span style="color: #b91c1c; font-weight: 700;">⚠️ Cancelled (Repudiated)</span>';
            if (btnPrintTrans) {
                btnPrintTrans.disabled = true;
                btnPrintTrans.style.opacity = '0.5';
                btnPrintTrans.style.cursor = 'not-allowed';
                btnPrintTrans.title = 'Settlement Repudiated — Transmittal Cancelled';
            }
            if (dlTrans) {
                dlTrans.href = 'javascript:void(0)';
                dlTrans.style.opacity = '0.5';
                dlTrans.style.cursor = 'not-allowed';
                dlTrans.title = 'Settlement Repudiated — Transmittal Cancelled';
            }
        } else if (isLocked) {
            if (badgeTrans) {
                badgeTrans.textContent = '🔒 Locked for Printing';
                badgeTrans.style.background = '#fef3c7';
                badgeTrans.style.color = '#b45309';
            }
            if (chipTransLock) chipTransLock.textContent = `${remainingDays} working days remaining`;
            if (btnPrintTrans) {
                btnPrintTrans.disabled = true;
                btnPrintTrans.style.opacity = '0.5';
                btnPrintTrans.style.cursor = 'not-allowed';
                btnPrintTrans.title = 'Locked pursuant to RA 7160 Sec. 416 until repudiation period expires';
            }
            if (dlTrans) {
                dlTrans.href = 'javascript:void(0)';
                dlTrans.style.opacity = '0.5';
                dlTrans.style.cursor = 'not-allowed';
                dlTrans.title = 'Locked pursuant to RA 7160 Sec. 416 until repudiation period expires';
            }
        } else {
            if (badgeTrans) {
                badgeTrans.textContent = '🔓 Ready for Transmittal';
                badgeTrans.style.background = '#dcfce7';
                badgeTrans.style.color = '#15803d';
            }
            if (chipTransLock) chipTransLock.innerHTML = '<span style="color: #15803d; font-weight: 700;">Ready for Transmittal</span>';
            if (btnPrintTrans) {
                btnPrintTrans.disabled = false;
                btnPrintTrans.style.opacity = '1';
                btnPrintTrans.style.cursor = 'pointer';
                btnPrintTrans.title = 'Print Court Transmittal Letter';
            }
            if (dlTrans) {
                dlTrans.href = `../../../backend/api/transmittals/preview.php?case_id=${encodeURIComponent(caseId)}&complaint_id=${encodeURIComponent(activeComplaintId)}&pdf=1`;
                dlTrans.style.opacity = '1';
                dlTrans.style.cursor = 'pointer';
                dlTrans.title = 'Download Court Transmittal Letter PDF';
            }
        }
    }
}
window.renderAmicableSettlementWorkspace = renderAmicableSettlementWorkspace;

function printKp16PreviewIframe() {
    const iframe = document.getElementById('dualKp16PreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}
window.printKp16PreviewIframe = printKp16PreviewIframe;

function printDualTransmittalIframe() {
    const btn = document.getElementById('btnDualPrintTransmittal');
    if (btn && btn.disabled) {
        const msg = 'Printing is restricted during the 10-working-day repudiation window pursuant to RA 7160 Sec. 416.';
        if (typeof showToast === 'function') {
            showToast(msg, 'warning');
        } else {
            alert(msg);
        }
        return;
    }
    const iframe = document.getElementById('dualTransmittalPreviewIframe') || document.getElementById('transmittalLetterPreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}
window.printDualTransmittalIframe = printDualTransmittalIframe;

function openFileRepudiationModal() {
    const data = window.complaintData || {};
    const ws = window.__agapComplaintWorkspace || data.case_workspace || {};
    const settlement = ws.settlement || {};
    const modal = document.getElementById('modalFileRepudiation');
    if (!modal) return;

    const idInput = document.getElementById('repudiationSettlementId');
    if (idInput) {
        idInput.value = settlement.settlement_id || '';
    }

    const select = document.getElementById('repudiatingPartySelect');
    if (select) {
        select.innerHTML = '<option value="">— Select Party Filing Repudiation —</option>';
        const parties = data.parties || [];
        if (parties.length > 0) {
            parties.forEach(p => {
                const name = p.full_name || [p.first_name, p.middle_name, p.last_name].filter(Boolean).join(' ');
                const opt = document.createElement('option');
                opt.value = p.resident_id || p.party_id || '';
                opt.textContent = `${name} (${p.party_type || 'Party'})`;
                select.appendChild(opt);
            });
        }
    }

    const swornDateInput = document.getElementById('repudiationSwornDate');
    if (swornDateInput) {
        swornDateInput.value = new Date().toISOString().split('T')[0];
    }

    modal.style.display = 'flex';
}
window.openFileRepudiationModal = openFileRepudiationModal;

function closeFileRepudiationModal() {
    const modal = document.getElementById('modalFileRepudiation');
    if (modal) modal.style.display = 'none';
}
window.closeFileRepudiationModal = closeFileRepudiationModal;

function updateRepudiationGroundsText() {
    const checkboxes = document.querySelectorAll('#modalFileRepudiation input[name="ground"]:checked');
    const grounds = Array.from(checkboxes).map(c => c.value);
    const textarea = document.getElementById('repudiationReasonText');
    if (textarea && grounds.length > 0 && !textarea.value.includes('Grounds:')) {
        textarea.value = `Grounds: ${grounds.join('; ')}\n\nNarrative of Vitiated Consent: `;
    }
}
window.updateRepudiationGroundsText = updateRepudiationGroundsText;

async function handleRepudiationSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();
    const btn = document.getElementById('btnSubmitRepudiation');
    if (btn) btn.disabled = true;

    const settlementId = document.getElementById('repudiationSettlementId')?.value;
    const repudiatedBy = document.getElementById('repudiatingPartySelect')?.value;
    const reason = document.getElementById('repudiationReasonText')?.value;
    const swornDate = document.getElementById('repudiationSwornDate')?.value;

    const checkboxes = document.querySelectorAll('#modalFileRepudiation input[name="ground"]:checked');
    if (checkboxes.length === 0) {
        alert('Please check at least one statutory ground (Fraud, Violence, or Intimidation).');
        if (btn) btn.disabled = false;
        return;
    }

    if (!settlementId || !repudiatedBy || !reason) {
        alert('Please complete all required fields.');
        if (btn) btn.disabled = false;
        return;
    }

    try {
        const res = await fetch('../../../backend/api/settlements/repudiate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                settlement_id: Number(settlementId),
                repudiated_by: Number(repudiatedBy),
                repudiation_reason: reason,
                sworn_date: swornDate
            })
        });
        const json = await res.json();
        if (json.success) {
            closeFileRepudiationModal();
            const msg = json.message || 'Settlement has been repudiated and invalidated. Case is routed to issue KP Form 20 (CFA).';
            if (typeof showToast === 'function') {
                showToast(msg, 'success');
            } else {
                alert(msg);
            }

            const currentComplaintId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search)).get('id');
            if (currentComplaintId && typeof loadComplaintDetails === 'function') {
                await loadComplaintDetails(currentComplaintId);
            }

            const activeCaseId = window.complaintData?.case_id || (window.__agapComplaintWorkspace?.case?.case_id);
            if (activeCaseId) {
                setTimeout(() => {
                    if (confirm('Sworn Repudiation recorded. Would you like to view/print KP Form 17 (Repudiation) now?')) {
                        openKpFormModal(Number(activeCaseId), 'KP Form 17');
                    }
                }, 350);
            }
        } else {
            alert(json.message || 'Failed to submit repudiation.');
        }
    } catch (err) {
        console.error('handleRepudiationSubmit error:', err);
        alert('Network error submitting repudiation.');
    } finally {
        if (btn) btn.disabled = false;
    }
}
window.handleRepudiationSubmit = handleRepudiationSubmit;

function openEditSettlementTermsModal() {
    const data = window.complaintData || {};
    const ws = window.__agapComplaintWorkspace || data.case_workspace || {};
    const settlement = ws.settlement || {};
    const modal = document.getElementById('modalEditSettlementTerms');
    if (!modal) return;

    const caseId = data.case_id || ws.case?.case_id || (new URLSearchParams(window.location.search)).get('case_id') || (new URLSearchParams(window.location.search)).get('id') || '';
    const caseIdInput = document.getElementById('editSettleCaseId');
    if (caseIdInput) caseIdInput.value = caseId;

    const idInput = document.getElementById('editSettleId') || document.getElementById('editSettlementId');
    if (idInput) idInput.value = settlement.settlement_id || '';

    const dateInput = document.getElementById('editSettleDate') || document.getElementById('editSettlementDate');
    if (dateInput) {
        dateInput.value = settlement.settlement_date ? settlement.settlement_date.split(' ')[0] : new Date().toISOString().split('T')[0];
    }

    const termsInput = document.getElementById('editSettleTermsText') || document.getElementById('editSettlementTermsText');
    if (termsInput) {
        termsInput.value = settlement.agreement_details || settlement.terms_of_settlement || '';
    }

    const amountInput = document.getElementById('editSettleAmount');
    if (amountInput) {
        amountInput.value = settlement.total_amount ? Number(settlement.total_amount).toFixed(2) : '';
    }

    const respInput = document.getElementById('editSettleResponsible');
    if (respInput && settlement.responsible_party) {
        respInput.value = settlement.responsible_party;
    }

    modal.style.display = 'flex';
}
window.openEditSettlementTermsModal = openEditSettlementTermsModal;

function closeEditSettlementTermsModal() {
    const modal = document.getElementById('modalEditSettlementTerms');
    if (modal) modal.style.display = 'none';
}
window.closeEditSettlementTermsModal = closeEditSettlementTermsModal;

async function handleEditSettlementSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();
    const btn = document.getElementById('btnSubmitEditSettlement') || document.getElementById('btnSaveSettlementTerms');
    if (btn) btn.disabled = true;

    const data = window.complaintData || {};
    const caseId = document.getElementById('editSettleCaseId')?.value ||
                   data.case_id ||
                   window.__agapComplaintWorkspace?.case?.case_id ||
                   (new URLSearchParams(window.location.search)).get('case_id') ||
                   (new URLSearchParams(window.location.search)).get('id');

    const settlementId = document.getElementById('editSettleId')?.value || document.getElementById('editSettlementId')?.value;
    const settlementDate = document.getElementById('editSettleDate')?.value || document.getElementById('editSettlementDate')?.value;
    const terms = (document.getElementById('editSettleTermsText')?.value || document.getElementById('editSettlementTermsText')?.value || '').trim();
    const amount = document.getElementById('editSettleAmount')?.value;
    const responsible = document.getElementById('editSettleResponsible')?.value;

    if (!terms) {
        alert('Please fill out the settlement terms.');
        if (btn) btn.disabled = false;
        return;
    }

    try {
        const payload = {
            case_id: Number(caseId),
            settlement_date: settlementDate,
            agreement_details: terms,
            terms_of_settlement: terms
        };
        if (settlementId) {
            payload.settlement_id = Number(settlementId);
        }
        if (amount && !isNaN(Number(amount))) {
            payload.total_amount = Number(amount);
        }
        if (responsible) {
            payload.responsible_party = responsible;
        }

        const res = await fetch('../../../backend/api/settlements/save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            closeEditSettlementTermsModal();
            const msg = json.message || 'Settlement agreement terms updated successfully!';
            if (typeof showToast === 'function') {
                showToast(msg, 'success');
            } else {
                alert(msg);
            }

            const currentComplaintId = data.complaint_id || (new URLSearchParams(window.location.search)).get('id');
            if (currentComplaintId && typeof loadComplaintDetails === 'function') {
                await loadComplaintDetails(currentComplaintId);
            }

            // Immediately refresh the KP Form 16 preview iframe
            const iframe = document.getElementById('dualKp16PreviewIframe');
            if (iframe && iframe.src && iframe.src !== 'about:blank') {
                try {
                    const u = new URL(iframe.src, window.location.href);
                    u.searchParams.set('_t', Date.now());
                    iframe.src = u.toString();
                } catch (_) {
                    iframe.src = iframe.src.split('&_t=')[0] + '&_t=' + Date.now();
                }
            }
        } else {
            alert(json.message || 'Failed to update settlement terms.');
        }
    } catch (err) {
        console.error('handleEditSettlementSubmit error:', err);
        alert('Network error saving settlement terms.');
    } finally {
        if (btn) btn.disabled = false;
    }
}
window.handleEditSettlementSubmit = handleEditSettlementSubmit;

function openIssueExecutionModal() {
    const data = window.complaintData || {};
    const ws = window.__agapComplaintWorkspace || data.case_workspace || {};
    const settlement = ws.settlement || {};
    const modal = document.getElementById('modalIssueExecutionKp23');
    if (!modal) return;

    const idInput = document.getElementById('execSettlementId');
    if (idInput) idInput.value = settlement.settlement_id || '';

    const select = document.getElementById('execMovingPartySelect');
    if (select) {
        select.innerHTML = '<option value="">— Select Moving Party —</option>';
        const parties = data.parties || [];
        parties.forEach(p => {
            const name = p.full_name || [p.first_name, p.middle_name, p.last_name].filter(Boolean).join(' ');
            const opt = document.createElement('option');
            opt.value = p.resident_id || p.party_id || '';
            opt.textContent = `${name} (${p.party_type || 'Party'})`;
            select.appendChild(opt);
        });
    }

    const dateInput = document.getElementById('execMotionDate');
    if (dateInput) {
        dateInput.value = new Date().toISOString().split('T')[0];
    }

    modal.style.display = 'flex';
}
window.openIssueExecutionModal = openIssueExecutionModal;

function closeIssueExecutionModal() {
    const modal = document.getElementById('modalIssueExecutionKp23');
    if (modal) modal.style.display = 'none';
}
window.closeIssueExecutionModal = closeIssueExecutionModal;

async function handleIssueExecutionSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();
    const btn = document.getElementById('btnSubmitExecution');
    if (btn) btn.disabled = true;

    const settlementId = document.getElementById('execSettlementId')?.value;
    const movingParty = document.getElementById('execMovingPartySelect')?.value;
    const obligation = document.getElementById('execObligationText')?.value;
    const amountReq = document.getElementById('execAmountReq')?.value;
    const motionDate = document.getElementById('execMotionDate')?.value;

    if (!settlementId || !movingParty || !obligation || !amountReq) {
        alert('Please fill out all required execution motion fields.');
        if (btn) btn.disabled = false;
        return;
    }

    try {
        const res = await fetch('../../../backend/api/settlements/execute.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'file_motion',
                settlement_id: Number(settlementId),
                motion_filed_by: Number(movingParty),
                obligation_violated: obligation,
                amount_or_requirement: amountReq,
                motion_date: motionDate
            })
        });
        const json = await res.json();
        if (json.success) {
            closeIssueExecutionModal();
            const msg = json.message || 'Motion for Execution recorded successfully!';
            if (typeof showToast === 'function') {
                showToast(msg, 'success');
            } else {
                alert(msg);
            }

            const currentComplaintId = window.complaintData?.complaint_id || (new URLSearchParams(window.location.search)).get('id');
            if (currentComplaintId && typeof loadComplaintDetails === 'function') {
                await loadComplaintDetails(currentComplaintId);
            }

            const activeCaseId = window.complaintData?.case_id || (window.__agapComplaintWorkspace?.case?.case_id);
            if (activeCaseId) {
                setTimeout(() => {
                    openKpFormModal(Number(activeCaseId), 'KP Form 23');
                }, 350);
            }
        } else {
            alert(json.message || 'Failed to file motion for execution.');
        }
    } catch (err) {
        console.error('handleIssueExecutionSubmit error:', err);
        alert('Network error filing motion for execution.');
    } finally {
        if (btn) btn.disabled = false;
    }
}
window.handleIssueExecutionSubmit = handleIssueExecutionSubmit;

/**
 * =============================================================================
 * ARBITRATION 4-STEP PROGRESSIVE WORKSPACE (RA 7160 Sec. 413)
 * =============================================================================
 */
function renderArbitrationProgressiveWorkspace(workspace, complaint, caseId) {
    const container = document.getElementById('arbitrationProgressiveWorkspace');
    if (!container) return;

    const activeCaseId = Number(caseId || complaint?.case_id || workspace?.case?.case_id || window.currentCaseId || 0);
    const arb = workspace?.arbitration || complaint?.case_workspace?.arbitration || workspace?.stages?.arbitration?.arbitration_record || {};
    const agreementDate = arb.agreement_date || (new Date()).toISOString().slice(0, 10);
    const awardDate = arb.award_date || null;
    const awardDetails = arb.award_details || arb.award_terms || '';
    const hasAward = Boolean(awardDate);

    // Date calculations
    const d0 = new Date(agreementDate + 'T00:00:00');
    const now = new Date();
    const msPerDay = 86400000;
    const daysElapsed = Math.floor((new Date().setHours(0,0,0,0) - d0.getTime()) / msPerDay);
    const dayOf5 = Math.max(1, Math.min(5, daysElapsed + 1));
    const isRepudiationActive = daysElapsed <= 5;
    const repExpiryDate = new Date(d0.getTime() + 5 * msPerDay);
    const repExpiryFormatted = formatDateReadable(repExpiryDate);

    // Statutory award window (Day 6 to Day 15)
    const day6Date = new Date(d0.getTime() + 6 * msPerDay);
    const day15Date = new Date(d0.getTime() + 15 * msPerDay);
    const day6Formatted = formatDateReadable(day6Date);
    const day15Formatted = formatDateReadable(day15Date);

    // Hearings segregated as Arbitration Hearing
    const allHearings = complaint?.case_workspace?.hearings || workspace?.hearings || [];
    const arbHearings = allHearings.filter(h => h.hearing_type === 'Arbitration' && h.hearing_status_code !== 'Cancelled');
    const sessCount = arbHearings.length;

    let hearingsListHtml = '';
    if (sessCount === 0) {
        hearingsListHtml = `
            <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 8px; padding: 20px; text-align: center; color: #64748b; margin-top: 12px;">
                <p style="margin: 0 0 10px; font-size: 0.86rem; color: #475569;">No arbitration hearings have been scheduled yet. You may schedule a hearing for the same day or succeeding days.</p>
                <button type="button" class="btn-create" onclick="openEmbeddedScheduleModal('Arbitration')" style="background: #d97706; border-color: #d97706; font-size: 0.82rem; padding: 7px 16px;">
                    + Schedule 1st Arbitration Hearing
                </button>
            </div>
        `;
    } else {
        hearingsListHtml = `
            <div style="margin-top: 12px; overflow-x: auto;">
                <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.84rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1.5px solid #e2e8f0; text-align: left; color: #475569;">
                            <th style="padding: 10px 14px;">Hearing Session</th>
                            <th style="padding: 10px 14px;">Date &amp; Time</th>
                            <th style="padding: 10px 14px;">Venue</th>
                            <th style="padding: 10px 14px;">Attendance</th>
                            <th style="padding: 10px 14px;">Status</th>
                            <th style="padding: 10px 14px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${arbHearings.map((h, idx) => {
                            const ordinalLabel = (typeof getStageSessionOrdinalLabel === 'function')
                                ? getStageSessionOrdinalLabel('Arbitration', idx + 1)
                                : `${idx + 1}${idx === 0 ? 'st' : (idx === 1 ? 'nd' : (idx === 2 ? 'rd' : 'th'))} Arbitration Hearing`;
                            const hDate = formatDateReadable(h.hearing_date) + ' ' + (h.hearing_date.slice(11, 16) || '');
                            const compAtt = h.complainant_attendance || 'Pending';
                            const respAtt = h.respondent_attendance || 'Pending';
                            const isDone = h.hearing_status === 'Completed' || (h.attendance_count > 0 && h.situation);
                            return `
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px 14px; font-weight: 700; color: #0f172a;">${escapeHtml(ordinalLabel)}</td>
                                    <td style="padding: 10px 14px; color: #334155;">${escapeHtml(hDate)}</td>
                                    <td style="padding: 10px 14px; color: #64748b;">${escapeHtml(h.venue || 'Lupon Court Room')}</td>
                                    <td style="padding: 10px 14px;">
                                        <span style="font-size: 0.74rem; padding: 2px 6px; border-radius: 4px; background: #f1f5f9; color: #475569;">C: ${escapeHtml(compAtt)}</span>
                                        <span style="font-size: 0.74rem; padding: 2px 6px; border-radius: 4px; background: #f1f5f9; color: #475569; margin-left: 4px;">R: ${escapeHtml(respAtt)}</span>
                                    </td>
                                    <td style="padding: 10px 14px;">
                                        <span class="status-pill ${isDone ? 'status-active' : 'status-amber'}" style="font-size: 0.72rem; padding: 2px 8px; border-radius: 9999px;">
                                            ${escapeHtml(h.hearing_status || 'Scheduled')}
                                        </span>
                                    </td>
                                    <td style="padding: 10px 14px; text-align: right;">
                                        <a href="../cases/arbitration-workspace.php?case_id=${encodeURIComponent(activeCaseId)}&hearing_id=${encodeURIComponent(h.hearing_id)}#minutesSection" class="btn-case-outline" style="font-size: 0.76rem; padding: 4px 10px; text-decoration: none;">
                                            Conduct / Minutes &rarr;
                                        </a>
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 10px; display: flex; justify-content: flex-end;">
                <button type="button" class="btn-secondary" onclick="openEmbeddedScheduleModal('Arbitration')" style="font-size: 0.8rem; padding: 6px 14px;">
                    + Schedule Another Arbitration Hearing
                </button>
            </div>
        `;
    }

    container.innerHTML = `
        <div style="margin: 0 30px 24px;">
            <!-- Main Arbitration Stage Card -->
            <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 1.5px solid #fde68a; border-radius: 12px; padding: 20px 24px; box-shadow: 0 2px 8px rgba(217, 119, 6, 0.08); margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; background: #d97706; color: #fff; padding: 3px 8px; border-radius: 4px;">
                                RA 7160 SEC. 413
                            </span>
                            <h2 style="font-size: 1.25rem; font-weight: 800; color: #78350f; margin: 0;">
                                Voluntary Binding Arbitration (Paghahatol)
                            </h2>
                        </div>
                        <p style="margin: 6px 0 0; font-size: 0.84rem; color: #92400e; max-width: 780px; line-height: 1.5;">
                            Parties have mutually agreed in writing to submit their dispute to binding arbitration. The resulting arbitral award has the force and effect of a final judgment of a court after ten (10) days.
                        </p>
                    </div>
                    <div>
                        <span class="status-pill" style="background: #fde68a; color: #78350f; font-weight: 800; font-size: 0.78rem; padding: 5px 12px; border-radius: 9999px;">
                            ${hasAward ? '✓ Award Rendered' : '⚖️ Arbitration In Progress'}
                        </span>
                    </div>
                </div>
            </div>

            <!-- 4-STEP PROGRESSIVE TIMELINE -->
            <div style="display: flex; flex-direction: column; gap: 20px;">

                <!-- STEP 1: Agreement to Arbitrate (KP Form 14) -->
                <div class="operational-card" style="border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                    <div style="padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="background: #2563eb; color: #fff; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 800;">1</span>
                            <div>
                                <h3 style="margin: 0; font-size: 0.98rem; font-weight: 800; color: #0f172a;">Agreement to Arbitrate (KP Form 14)</h3>
                                <span style="font-size: 0.76rem; color: #64748b;">Kasunduan sa Pag-aarbitro &bull; Mutual agreement executed pursuant to Sec. 413</span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button" class="btn-secondary" onclick="openKpFormModal(${activeCaseId}, 'KP Form 14')" style="font-size: 0.78rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 5px;">
                                🔍 Preview KP Form 14
                            </button>
                            <button type="button" class="btn-create" onclick="printKpFormModalDirect(${activeCaseId}, 'KP Form 14')" style="font-size: 0.78rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 5px; background: #0284c7; border-color: #0284c7;">
                                🖨️ Print Form 14
                            </button>
                            <a href="../../../backend/api/documents/kp-form.php?action=pdf&case_id=${activeCaseId}&form_code=KP+Form+14" class="btn-secondary" download style="font-size: 0.78rem; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                📥 PDF
                            </a>
                        </div>
                    </div>
                    <div style="padding: 16px 20px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Date of Agreement (Day 0)</span>
                                <strong style="font-size: 0.95rem; color: #0f172a;">${escapeHtml(formatDateReadable(agreementDate))}</strong>
                            </div>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Agreement Status</span>
                                <strong style="font-size: 0.95rem; color: #16a34a;">✓ Executed &amp; Legally Binding</strong>
                            </div>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                                <span style="font-size: 0.72rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Arbitrating Body</span>
                                <strong style="font-size: 0.95rem; color: #0f172a;">Lupong Tagapamayapa</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: 5-Day Statutory Repudiation / Withdrawal Period & KP Form 17 -->
                <div class="operational-card" style="border: 1.5px solid ${isRepudiationActive ? '#fde68a' : '#cbd5e1'}; border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                    <div style="padding: 14px 20px; background: ${isRepudiationActive ? '#fffbeb' : '#f8fafc'}; border-bottom: 1px solid ${isRepudiationActive ? '#fde68a' : '#e2e8f0'}; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="background: ${isRepudiationActive ? '#d97706' : '#64748b'}; color: #fff; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 800;">2</span>
                            <div>
                                <h3 style="margin: 0; font-size: 0.98rem; font-weight: 800; color: #0f172a;">5-Day Statutory Repudiation / Withdrawal Period</h3>
                                <span style="font-size: 0.76rem; color: #64748b;">Right to withdraw under oath within 5 days on grounds of vitiated consent (RA 7160 Sec. 413)</span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            ${isRepudiationActive ? `
                                <span class="status-pill" style="background: #fef3c7; color: #92400e; font-weight: 800; border: 1.5px solid #fde68a; font-size: 0.8rem; padding: 4px 12px; border-radius: 9999px;">
                                    ⏳ Repudiation Window: Day ${dayOf5} of 5 (Active)
                                </span>
                            ` : `
                                <span class="status-pill" style="background: #ecfdf5; color: #065f46; font-weight: 800; border: 1.5px solid #a7f3d0; font-size: 0.8rem; padding: 4px 12px; border-radius: 9999px;">
                                    ✓ Repudiation Window Closed (Day 5 Expired on ${repExpiryFormatted})
                                </span>
                            `}
                        </div>
                    </div>
                    <div style="padding: 16px 20px;">
                        <p style="margin: 0 0 14px; font-size: 0.84rem; color: #475569; line-height: 1.5;">
                            Under RA 7160 Sec. 413, any party may repudiate the agreement to arbitrate within <strong>five (5) calendar days</strong> from the date thereof (expiry: <strong>${repExpiryFormatted}</strong>) by submitting a sworn statement to the Punong Barangay asserting that consent was vitiated by fraud, violence, or intimidation.
                        </p>
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 1.1rem;">📄</span>
                                <div>
                                    <strong style="font-size: 0.88rem; color: #0f172a;">KP Form 17 (Katibayan ng Pagsalungat / Repudiation of Agreement)</strong>
                                    <span style="font-size: 0.76rem; color: #64748b; display: block;">Official sworn statement format available for parties filing timely repudiation</span>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <button type="button" class="btn-secondary" onclick="openKpFormModal(${activeCaseId}, 'KP Form 17')" style="font-size: 0.78rem; padding: 6px 14px; border-color: #cbd5e1; color: #0f172a;">
                                    🔍 Preview KP Form 17
                                </button>
                                <button type="button" class="btn-secondary" onclick="printKpFormModalDirect(${activeCaseId}, 'KP Form 17')" style="font-size: 0.78rem; padding: 6px 14px; border-color: #cbd5e1; color: #0f172a;">
                                    🖨️ Print KP Form 17
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: Scheduling the Arbitration Hearings (Same Day or Next Days) -->
                <div class="operational-card" style="border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                    <div style="padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="background: #2563eb; color: #fff; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 800;">3</span>
                            <div>
                                <h3 style="margin: 0; font-size: 0.98rem; font-weight: 800; color: #0f172a;">Scheduling the Arbitration Hearings</h3>
                                <span style="font-size: 0.76rem; color: #64748b;">Flexible scheduling (same day or succeeding days) &bull; Segregated strictly as Arbitration Hearings</span>
                            </div>
                        </div>
                        <button type="button" class="btn-create" onclick="openEmbeddedScheduleModal('Arbitration')" style="background: #d97706; border-color: #d97706; font-size: 0.78rem; padding: 6px 14px; display: inline-flex; align-items: center; gap: 5px;">
                            + Schedule Arbitration Hearing
                        </button>
                    </div>
                    <div style="padding: 16px 20px;">
                        <p style="margin: 0 0 10px; font-size: 0.84rem; color: #475569; line-height: 1.5;">
                            Arbitration sessions are conducted specifically to take testimonies, receive evidence, and formulate the award. Depending on official availability, hearings may take place on the same day or within the succeeding days.
                        </p>
                        ${hearingsListHtml}
                    </div>
                </div>

                <!-- STEP 4: Issuing KP Form 15 (Arbitration Award / Gawad ng Arbitrasyon Modal) -->
                <div class="operational-card" style="border: 1.5px solid ${hasAward ? '#86efac' : '#93c5fd'}; border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                    <div style="padding: 14px 20px; background: ${hasAward ? '#f0fdf4' : '#eff6ff'}; border-bottom: 1px solid ${hasAward ? '#86efac' : '#bfdbfe'}; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="background: ${hasAward ? '#16a34a' : '#2563eb'}; color: #fff; width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 800;">4</span>
                            <div>
                                <h3 style="margin: 0; font-size: 0.98rem; font-weight: 800; color: #0f172a;">KP Form 15: Gawad ng Paghahatol (Arbitration Award)</h3>
                                <span style="font-size: 0.76rem; color: #64748b;">Official binding ruling rendered within mandatory statutory window (Days 6 to 15)</span>
                            </div>
                        </div>
                        <div>
                            ${hasAward ? `
                                <span class="status-pill status-active" style="font-size: 0.78rem; padding: 4px 12px; border-radius: 9999px;">
                                    ✓ Award Rendered on ${escapeHtml(formatDateReadable(awardDate))}
                                </span>
                            ` : `
                                <span class="status-pill status-amber" style="font-size: 0.78rem; padding: 4px 12px; border-radius: 9999px;">
                                    Pending Award Issuance
                                </span>
                            `}
                        </div>
                    </div>
                    <div style="padding: 18px 20px;">
                        <!-- Statutory Timeline Validation Rules Callout -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <span style="font-size: 1rem;">⚖️</span>
                                <strong style="font-size: 0.86rem; color: #1e3a8a;">Mandatory Statutory Date Enforcement (RA 7160 Sec. 413)</strong>
                            </div>
                            <div style="font-size: 0.82rem; color: #475569; line-height: 1.5;">
                                &bull; <strong>Cannot be issued before Day 6:</strong> The award cannot be handed down during the 5-day withdrawal period (earliest: <strong>${day6Formatted}</strong>).<br>
                                &bull; <strong>Must be rendered by Day 15:</strong> The award must be made within 15 calendar days of signing KP Form 14 (latest: <strong>${day15Formatted}</strong>).
                            </div>
                        </div>

                        ${hasAward ? `
                            <div style="background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; padding: 16px 18px; margin-bottom: 16px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; border-bottom: 1px solid #bbf7d0; padding-bottom: 8px;">
                                    <strong style="color: #166534; font-size: 0.92rem;">Formal Binding Award Resolution</strong>
                                    <span style="font-size: 0.8rem; color: #15803d; font-weight: 700;">Date Rendered: ${escapeHtml(formatDateReadable(awardDate))}</span>
                                </div>
                                <div style="font-size: 0.86rem; color: #0f172a; white-space: pre-wrap; line-height: 1.55;">${escapeHtml(awardDetails)}</div>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <button type="button" class="btn-create" onclick="openKpFormModal(${activeCaseId}, 'KP Form 15')" style="font-size: 0.82rem; padding: 7px 16px; background: #16a34a; border-color: #16a34a;">
                                        🔍 Preview KP Form 15
                                    </button>
                                    <button type="button" class="btn-secondary" onclick="printKpFormModalDirect(${activeCaseId}, 'KP Form 15')" style="font-size: 0.82rem; padding: 7px 16px; display: inline-flex; align-items: center; gap: 5px;">
                                        🖨️ Print KP Form 15
                                    </button>
                                    <a href="../../../backend/api/documents/kp-form.php?action=pdf&case_id=${activeCaseId}&form_code=KP+Form+15" class="btn-secondary" download style="font-size: 0.82rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                        📥 PDF
                                    </a>
                                </div>
                                <button type="button" class="btn-secondary" onclick="openIssueArbitrationAwardModal(${activeCaseId}, '${agreementDate}', true)" style="font-size: 0.82rem; padding: 7px 14px;">
                                    ✏️ Edit Award Terms
                                </button>
                            </div>
                        ` : `
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px 18px;">
                                <div>
                                    <strong style="color: #1e40af; font-size: 0.92rem; display: block;">Ready to Hand Down Arbitration Award?</strong>
                                    <span style="font-size: 0.82rem; color: #1e3a8a;">Once hearings are conducted, issue the official KP Form 15 ruling within the Day 6–15 statutory window.</span>
                                </div>
                                <button type="button" class="btn-create" onclick="openIssueArbitrationAwardModal(${activeCaseId}, '${agreementDate}')" style="background: #2563eb; border-color: #2563eb; font-size: 0.85rem; padding: 8px 20px; font-weight: 700;">
                                    Issue KP Form 15 (Arbitration Award) &rarr;
                                </button>
                            </div>
                        `}
                    </div>
                </div>

            </div>
        </div>
    `;
}
window.renderArbitrationProgressiveWorkspace = renderArbitrationProgressiveWorkspace;

function openIssueArbitrationAwardModal(caseId, agreementDate, isEdit = false) {
    const modal = document.getElementById('issueArbitrationAwardModal');
    if (!modal) return;

    const caseIdInput = document.getElementById('arbAwardCaseId');
    const agrDateInput = document.getElementById('arbAwardAgreementDate');
    const awardDateInput = document.getElementById('arbAwardDate');
    const awardTermsInput = document.getElementById('arbAwardTerms');
    const arbitratorInput = document.getElementById('arbArbitratorPresider');
    const helperEl = document.getElementById('arbAwardDateHelper');
    const errEl = document.getElementById('arbAwardDateError');
    if (errEl) errEl.style.display = 'none';

    if (caseIdInput) caseIdInput.value = caseId || '';
    if (agrDateInput) agrDateInput.value = agreementDate || (new Date()).toISOString().slice(0, 10);

    // Compute Day 6 and Day 15
    const d0 = new Date(agrDateInput.value + 'T00:00:00');
    const msPerDay = 86400000;
    const minD = new Date(d0.getTime() + 6 * msPerDay);
    const maxD = new Date(d0.getTime() + 15 * msPerDay);
    const minStr = minD.toISOString().slice(0, 10);
    const maxStr = maxD.toISOString().slice(0, 10);
    const minFormatted = formatDateReadable(minD);
    const maxFormatted = formatDateReadable(maxD);

    if (awardDateInput) {
        awardDateInput.min = minStr;
        awardDateInput.max = maxStr;
        if (!awardDateInput.value || !isEdit) {
            const todayStr = (new Date()).toISOString().slice(0, 10);
            if (todayStr >= minStr && todayStr <= maxStr) {
                awardDateInput.value = todayStr;
            } else {
                awardDateInput.value = minStr;
            }
        }
    }

    if (helperEl) {
        helperEl.textContent = `Mandatory statutory window: Day 6 to Day 15 (${minFormatted} to ${maxFormatted}). Award cannot be rendered before ${minFormatted}.`;
    }

    const arb = window.complaintData?.case_workspace?.arbitration || {};
    if (isEdit && arb) {
        if (awardDateInput && arb.award_date) awardDateInput.value = arb.award_date;
        if (awardTermsInput && (arb.award_details || arb.award_terms)) awardTermsInput.value = arb.award_details || arb.award_terms;
    } else if (!isEdit) {
        if (awardTermsInput) awardTermsInput.value = '';
    }

    if (arbitratorInput && !arbitratorInput.value) {
        arbitratorInput.value = 'HON. AKIKO LYNNE CENTENO (Punong Barangay)';
    }

    modal.style.display = 'flex';
}
window.openIssueArbitrationAwardModal = openIssueArbitrationAwardModal;

function closeIssueArbitrationAwardModal() {
    const modal = document.getElementById('issueArbitrationAwardModal');
    if (modal) modal.style.display = 'none';
}
window.closeIssueArbitrationAwardModal = closeIssueArbitrationAwardModal;

function validateArbitrationAwardDateInput() {
    const awardDateInput = document.getElementById('arbAwardDate');
    const errEl = document.getElementById('arbAwardDateError');
    if (!awardDateInput || !errEl) return true;

    const val = awardDateInput.value;
    const minStr = awardDateInput.min;
    const maxStr = awardDateInput.max;

    if (!val) {
        errEl.textContent = 'Award date is required.';
        errEl.style.display = 'block';
        return false;
    }

    if (minStr && val < minStr) {
        errEl.textContent = `Statutory Violation: Cannot issue before Day 6 (5-day repudiation window must expire). Earliest permitted date is ${formatDateReadable(minStr)}.`;
        errEl.style.display = 'block';
        return false;
    }

    if (maxStr && val > maxStr) {
        errEl.textContent = `Statutory Violation: Must be rendered within 15 calendar days from signing KP Form 14. Latest permitted date is ${formatDateReadable(maxStr)}.`;
        errEl.style.display = 'block';
        return false;
    }

    errEl.style.display = 'none';
    return true;
}
window.validateArbitrationAwardDateInput = validateArbitrationAwardDateInput;

async function submitArbitrationAward(e) {
    if (e && e.preventDefault) e.preventDefault();

    if (!validateArbitrationAwardDateInput()) {
        return;
    }

    const caseId = document.getElementById('arbAwardCaseId')?.value;
    const agreementDate = document.getElementById('arbAwardAgreementDate')?.value;
    const awardDate = document.getElementById('arbAwardDate')?.value;
    const awardDetails = document.getElementById('arbAwardTerms')?.value.trim();
    const arbitratorPresider = document.getElementById('arbArbitratorPresider')?.value.trim();

    if (!caseId || !awardDate || !awardDetails) {
        alert('Please fill out all required fields.');
        return;
    }

    const btn = document.getElementById('btnSubmitArbAward');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Rendering Award...';
    }

    try {
        const payload = {
            case_id: Number(caseId),
            agreement_date: agreementDate,
            award_date: awardDate,
            award_details: awardDetails,
            arbitrator_presider: arbitratorPresider
        };

        const res = await fetch('../../../backend/api/documents/arbitration.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            closeIssueArbitrationAwardModal();
            if (typeof showToast === 'function') {
                showToast(json.message || 'Arbitration award successfully rendered!', 'success');
            } else {
                alert(json.message || 'Arbitration award successfully rendered!');
            }
            if (typeof loadComplaintDetails === 'function') {
                await loadComplaintDetails();
            }
            // Auto open preview for KP Form 15
            setTimeout(() => {
                openKpFormModal(Number(caseId), 'KP Form 15');
            }, 300);
        } else {
            const errEl = document.getElementById('arbAwardDateError');
            if (errEl) {
                errEl.textContent = json.message || 'Unable to record arbitration award.';
                errEl.style.display = 'block';
            } else {
                alert(json.message || 'Unable to record arbitration award.');
            }
        }
    } catch (err) {
        console.error('submitArbitrationAward error:', err);
        alert('Network error while saving arbitration award.');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Confirm & Render Award (KP Form 15)';
        }
    }
}
window.submitArbitrationAward = submitArbitrationAward;

function printKpFormModalDirect(caseId, formCode) {
    if (typeof openKpFormModal === 'function') {
        openKpFormModal(caseId, formCode);
        setTimeout(() => {
            const iframe = document.getElementById('kpDocPreviewIframe');
            if (iframe && iframe.contentWindow) {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            }
        }, 600);
    }
}
window.printKpFormModalDirect = printKpFormModalDirect;
