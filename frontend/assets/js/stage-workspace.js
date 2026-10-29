
function getMinutesField(baseId, hearingId = null) {
    if (hearingId) {
        const el = document.getElementById(`${baseId}_${hearingId}`);
        if (el) return el;
    }
    if (stageWorkspaceState.activeSessionHearingId) {
        const el = document.getElementById(`${baseId}_${stageWorkspaceState.activeSessionHearingId}`);
        if (el) return el;
    }
    return document.getElementById(baseId);
}
window.getMinutesField = getMinutesField;

function insertAgreedClause(text, hearingId = null) {
    const ta = getMinutesField('minAgreements', hearingId);
    if (!ta) return;
    if (ta.value.trim() === '') {
        ta.value = text;
    } else {
        ta.value = ta.value.trim() + '\n\n' + text;
    }
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.dispatchEvent(new Event('change', { bubbles: true }));
    ta.focus();
}
window.insertAgreedClause = insertAgreedClause;

function setNowHearingEndTime(hearingId = null) {
    const input = getMinutesField('minActualEndTime', hearingId);
    if (!input) return;
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    input.value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
}
window.setNowHearingEndTime = setNowHearingEndTime;
window.setActualEndTimeNow = setNowHearingEndTime;

async function loadHearingMinutesDocuments(hearingId) {
    const container = document.getElementById(`minutesUploadedDocsContainer_${hearingId}`) || document.getElementById('minutesUploadedDocsContainer');
    if (!container || !hearingId) return;

    try {
        const res = await fetch(`../../../backend/api/hearings/upload-minutes-evidence.php?hearing_id=${encodeURIComponent(hearingId)}&_t=${Date.now()}`);
        const json = await res.json();
        if (!json.success) return;

        const docs = json.data || [];
        if (!docs.length) {
            container.innerHTML = '<span style="font-size: 0.78rem; color: #94a3b8; font-style: italic;">Walang nakalakip na dokumento o isinulat-kamay na katitikan sa kasalukuyan.</span>';
            return;
        }

        container.innerHTML = `
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <span style="font-size: 0.76rem; font-weight: 700; color: #475569; text-transform: uppercase;">Nakalakip na Dokumento (${docs.length}):</span>
                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    ${docs.map(d => {
                        const isHandwritten = d.document_type === 'handwritten_minutes';
                        const badgeColor = isHandwritten ? 'background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;' : 'background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;';
                        const typeLabel = isHandwritten ? 'Katitikan (Handwritten)' : 'Ebidensya (Evidence)';
                        const sizeKb = d.file_size ? `${Math.round(d.file_size / 1024)} KB` : '';
                        return `
                            <div style="display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 8px; ${badgeColor} font-size: 0.8rem; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                                <span>${isHandwritten ? '📄' : '📎'}</span>
                                <div style="max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <strong style="display: block; font-size: 0.82rem; color: inherit;">${escapeHtml(d.file_name)}</strong>
                                    <small style="font-size: 0.7rem; opacity: 0.85;">${typeLabel} ${sizeKb ? '· ' + sizeKb : ''}</small>
                                </div>
                                <div style="display: flex; align-items: center; gap: 4px; margin-left: 6px;">
                                    <a href="../../${escapeHtml(d.file_path)}" target="_blank" style="padding: 2px 7px; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 4px; color: #0284c7; text-decoration: none; font-size: 0.72rem; font-weight: 700;">
                                        Preview
                                    </a>
                                    <button type="button" onclick="deleteHearingMinutesDocument(${d.id}, ${d.hearing_id})" style="padding: 2px 6px; background: #fff; border: 1px solid #fca5a5; border-radius: 4px; color: #dc2626; cursor: pointer; font-size: 0.72rem; font-weight: 700;" title="Alisin ang attachment">
                                        &times;
                                    </button>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    } catch (e) {
        console.warn('Failed to load minutes documents:', e);
    }
}
window.loadHearingMinutesDocuments = loadHearingMinutesDocuments;

async function handleMinutesEvidenceUpload(input, documentType, targetHearingId = null) {
    if (!input.files || !input.files.length) return;
    const hearingId = targetHearingId || input.getAttribute('data-hearing-id') || document.getElementById('minHearingId')?.value || stageWorkspaceState.activeSessionHearingId;
    const caseId = stageWorkspaceState.caseId;
    if (!hearingId || !caseId) {
        alert('Please select an active hearing session first.');
        return;
    }

    const formData = new FormData();
    formData.append('hearing_id', hearingId);
    formData.append('case_id', caseId);
    formData.append('document_type', documentType);
    for (let i = 0; i < input.files.length; i++) {
        formData.append('file[]', input.files[i]);
    }

    try {
        const res = await fetch('../../../backend/api/hearings/upload-minutes-evidence.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if (json.success) {
            if (window.AgapDraftManager) {
                const draftKey = `agap_draft_${stageWorkspaceState.mode}_case_${stageWorkspaceState.caseId}_hearing_${hearingId}`;
                window.AgapDraftManager.markSubmitted(draftKey);
                window.AgapDraftManager.setIndicator('wsDraftIndicator', 'synced', '✓ All changes submitted');
            }
            loadHearingMinutesDocuments(hearingId);
        } else {
            alert(json.message || 'Failed to upload document.');
        }
    } catch (e) {
        alert('Network error while uploading: ' + e.message);
    } finally {
        input.value = '';
    }
}
window.handleMinutesEvidenceUpload = handleMinutesEvidenceUpload;

async function deleteHearingMinutesDocument(docId, hearingId) {
    if (!confirm('Sigurado ka bang nais mong tanggalin ang nakalakip na dokumentong ito?')) return;
    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('document_id', docId);
        const res = await fetch('../../../backend/api/hearings/upload-minutes-evidence.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if (json.success) {
            loadHearingMinutesDocuments(hearingId);
        } else {
            alert(json.message || 'Failed to delete document.');
        }
    } catch (e) {
        alert('Error removing document: ' + e.message);
    }
}
window.deleteHearingMinutesDocument = deleteHearingMinutesDocument;

function getStageSessionOrdinalLabel(stageType, index) {
    const idx = parseInt(index, 10) || 1;
    const s = ["th", "st", "nd", "rd"];
    const v = idx % 100;
    const ord = idx + (s[(v - 20) % 10] || s[v] || s[0]);
    const stage = (stageType === 'Conciliation') ? 'Conciliation' : ((stageType === 'Arbitration') ? 'Arbitration Hearing' : 'Mediation');
    return `${ord} ${stage}`;
}

/**
 * AGAP - Stage Workspaces (Mediation & Pangkat Conciliation)
 * File: frontend/assets/js/stage-workspace.js
 */

let stageWorkspaceState = {
    mode: 'Mediation', // 'Mediation', 'Conciliation', or 'Arbitration'
    caseId: null,
    complaintId: null,
    stageData: null,
    sessions: [],
    luponMembers: [],
    activeSessionHearingId: null,
    currentHearingId: null,
    currentMinutesRecord: null
};

/**
 * Base API URL resolver
 * Always resolves to ../../../backend/api/ from frontend/pages/cases/
 */
function getStageApiUrl(endpoint) {
    const clean = endpoint.replace(/^\/+/, '');
    return `../../../backend/api/${clean}`;
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatDateReadable(str) {
    if (!str) return '—';
    try {
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
        return str;
    }
}

function formatDateTimeReadable(str) {
    if (!str) return '—';
    try {
        const d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) +
            ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch (e) {
        return str;
    }
}

function unlockOutcomesTab(cId) {
    const compId = cId || stageWorkspaceState.complaintId || stageWorkspaceState.caseId;
    if (compId) {
        sessionStorage.setItem('agap_outcomes_unlocked_' + compId, '1');
    }
    sessionStorage.setItem('agap_outcomes_unlocked_global', '1');
    window.agapOutcomesUnlocked = true;
    try {
        if (window.opener && window.opener.updateStageTabsLockState) {
            if (compId) window.opener.sessionStorage.setItem('agap_outcomes_unlocked_' + compId, '1');
            window.opener.sessionStorage.setItem('agap_outcomes_unlocked_global', '1');
            window.opener.agapOutcomesUnlocked = true;
            window.opener.updateStageTabsLockState();
        }
        if (window.parent && window.parent.updateStageTabsLockState && window.parent !== window) {
            if (compId) window.parent.sessionStorage.setItem('agap_outcomes_unlocked_' + compId, '1');
            window.parent.sessionStorage.setItem('agap_outcomes_unlocked_global', '1');
            window.parent.agapOutcomesUnlocked = true;
            window.parent.updateStageTabsLockState();
        }
        if (typeof updateStageTabsLockState === 'function') {
            updateStageTabsLockState();
        }
    } catch (e) {
        // Cross-origin or window reference expired
    }
}
window.unlockOutcomesTab = unlockOutcomesTab;

function openKpFormModal(caseId, formCode, hearingId = null, proceedToOfficerLog = false) {
    const activeCaseId = caseId || stageWorkspaceState.caseId;
    if (!activeCaseId) {
        notifyUser('Case ID is required to preview document.', 'warning');
        return;
    }
    if (['KP Form 16', 'KP Form 20', 'KP Form 14', 'KP Form 15', 'Transmittal', 'Court Transmittal'].includes(formCode)) {
        unlockOutcomesTab(activeCaseId);
    }

    const modal = document.getElementById('kpDocumentPreviewModal');
    const iframe = document.getElementById('kpDocPreviewIframe');
    const heading = document.getElementById('kpDocModalHeading');
    const sub = document.getElementById('kpDocModalSub');
    const dlBtn = document.getElementById('btnDownloadKpDocPdf');

    const baseApi = (typeof getStageApiUrl === 'function')
        ? getStageApiUrl('documents/kp-form.php')
        : `../../../backend/api/documents/kp-form.php`;

    let previewUrl = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=${encodeURIComponent(formCode)}&preview=1&bare=1`;
    let pdfUrl = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=${encodeURIComponent(formCode)}&pdf=1`;
    if (hearingId) {
        previewUrl += `&hearing_id=${encodeURIComponent(hearingId)}`;
        pdfUrl += `&hearing_id=${encodeURIComponent(hearingId)}`;
    }

    // Set officer log destination URL for proof of service
    const officerLogUrl = `../gps/proof-service.php?case_id=${encodeURIComponent(activeCaseId)}${hearingId ? `&hearing_id=${encodeURIComponent(hearingId)}` : ''}`;
    if (proceedToOfficerLog) {
        window._kpPreviewProceedUrl = officerLogUrl;
    } else {
        window._kpPreviewProceedUrl = null;
    }

    const btnProceed = document.getElementById('btnKpDocProceedOfficerLog');
    if (btnProceed) {
        btnProceed.href = officerLogUrl;
        btnProceed.style.display = 'inline-flex';
    }
    const btnHeaderProceed = document.getElementById('btnHeaderProceedOfficerLog');
    if (btnHeaderProceed) {
        btnHeaderProceed.href = officerLogUrl;
        btnHeaderProceed.style.display = 'inline-flex';
    }

    if (modal && iframe) {
        if (heading) heading.textContent = `${formCode} Preview`;
        if (sub) sub.textContent = `Barangay Tumana Lupong Tagapamayapa · Case #${activeCaseId}`;
        iframe.src = previewUrl;
        if (dlBtn) dlBtn.href = pdfUrl;
        modal.style.display = 'flex';
    } else {
        window.open(previewUrl.replace('&bare=1', ''), '_blank');
    }
}
window.openKpFormModal = openKpFormModal;
window.openKPFormPreview = openKpFormModal;

function closeKpDocumentPreviewModal() {
    const modal = document.getElementById('kpDocumentPreviewModal');
    const iframe = document.getElementById('kpDocPreviewIframe');
    if (iframe) iframe.src = 'about:blank';
    if (modal) modal.style.display = 'none';
    if (window._kpPreviewProceedUrl) {
        const dest = window._kpPreviewProceedUrl;
        window._kpPreviewProceedUrl = null;
        window.location.href = dest;
    }
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

function openTriPanelPangkatModal(caseId) {
    const activeCaseId = caseId || stageWorkspaceState.caseId;
    if (!activeCaseId) {
        notifyUser('Case ID is required for Pangkat notices preview.', 'warning');
        return;
    }
    const modal = document.getElementById('pangkatNoticesModal');
    if (!modal) {
        console.warn('pangkatNoticesModal element not found in DOM.');
        return;
    }

    const complaintId = stageWorkspaceState.complaintId || (window.currentComplaintData ? window.currentComplaintData.complaint_id : '') || (window.complaintData ? window.complaintData.complaint_id : '');
    const frame10 = document.getElementById('pangkatPreviewFrameKp10');
    const frame11 = document.getElementById('pangkatPreviewFrameKp11');
    const frame12 = document.getElementById('pangkatPreviewFrameKp12');
    const dl10 = document.getElementById('btnDownloadPangkatKp10');
    const dl11 = document.getElementById('btnDownloadPangkatKp11');
    const dl12 = document.getElementById('btnDownloadPangkatKp12');
    const retComp = document.getElementById('btnPangkatOfficersReturnComp');
    const retResp = document.getElementById('btnPangkatOfficersReturnResp');
    const retFull = document.getElementById('btnPangkatOfficerReturnFull');

    const baseApi = (typeof getStageApiUrl === 'function')
        ? getStageApiUrl('documents/kp-form.php')
        : `../../../backend/api/documents/kp-form.php`;

    if (frame10) frame10.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+10&preview=1&bare=1`;
    if (frame11) frame11.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+11&preview=1&bare=1`;
    if (frame12) frame12.src = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+12&preview=1&bare=1`;

    if (dl10) dl10.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+10&pdf=1`;
    if (dl11) dl11.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+11&pdf=1`;
    if (dl12) dl12.href = `${baseApi}?case_id=${encodeURIComponent(activeCaseId)}&form_code=KP+Form+12&pdf=1`;

    const gpsUrl = (party) => `../../pages/gps/proof-service.php?case_id=${encodeURIComponent(activeCaseId)}&complaint_id=${encodeURIComponent(complaintId)}&stage=Conciliation&form=KP12${party ? `&party=${encodeURIComponent(party)}` : ''}`;
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

    const baseApi = (typeof getStageApiUrl === 'function')
        ? getStageApiUrl('documents/kp-form.php')
        : `../../../backend/api/documents/kp-form.php`;

    // Use kp-form.php: checks physical file first; if missing, dynamically renders clean, printable HTML
    let previewUrl = `${baseApi}?document_id=${encodeURIComponent(documentId)}&preview=1&bare=1&_t=${Date.now()}`;
    let downloadUrl = `${baseApi}?document_id=${encodeURIComponent(documentId)}&pdf=1`;

    // Fallback using case_id and form_code if document_id is missing
    const resolvedCaseId = caseId || stageWorkspaceState?.caseId || (new URLSearchParams(window.location.search)).get('case_id');
    if ((!documentId || Number(documentId) <= 0) && resolvedCaseId) {
        previewUrl = `${baseApi}?case_id=${encodeURIComponent(resolvedCaseId)}&form_code=${encodeURIComponent(tName)}&preview=1&bare=1&_t=${Date.now()}`;
        downloadUrl = `${baseApi}?case_id=${encodeURIComponent(resolvedCaseId)}&form_code=${encodeURIComponent(tName)}&pdf=1`;
    }

    iframe.src = previewUrl;

    if (dlBtn) {
        dlBtn.href = downloadUrl;
        dlBtn.style.display = 'inline-flex';
    }

    modal.style.display = 'flex';
}
window.openKpDocumentPreviewModal = openKpDocumentPreviewModal;

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

function legacyOpenKPFormPreview(caseId, formCode, hearingId = null) {
    if (!caseId) {
        notifyUser('Case ID is required to preview document.', 'warning');
        return;
    }
    if (['KP Form 16', 'KP Form 20', 'KP Form 14', 'KP Form 15', 'Transmittal', 'Court Transmittal'].includes(formCode)) {
        unlockOutcomesTab(caseId);
    }
    let url = getStageApiUrl(`documents/kp-form.php?case_id=${encodeURIComponent(caseId)}&form_code=${encodeURIComponent(formCode)}&preview=1`);
    if (hearingId) {
        url += `&hearing_id=${encodeURIComponent(hearingId)}`;
    }
    window.open(url, '_blank');
}
window.openKPFormPreview = openKpFormModal;

function notifyUser(message, type = 'info', title = null, duration = 4000) {
    if (window.agapNotify) {
        window.agapNotify(message, type, title, duration);
    } else {
        alert(message);
    }
}
window.notifyUser = notifyUser;

/**
 * Initialize Stage Workspace
 */
async function initStageWorkspace(options) {
    stageWorkspaceState.mode = options.mode || 'Mediation';
    stageWorkspaceState.caseId = Number(options.caseId);
    stageWorkspaceState.complaintId = Number(options.complaintId || 0);

    const urlParams = new URLSearchParams(window.location.search);
    const hParam = urlParams.get('hearing_id');
    if (hParam) {
        stageWorkspaceState.activeSessionHearingId = Number(hParam);
    }

    await loadStageData();
    await loadLuponMembers();
    checkUrlHashAction();

    // Hearings routes an elevation request here so there is only one
    // Mediation-to-Conciliation workflow and one set of safeguards.
    if (urlParams.get('open_referral') === '1' && stageWorkspaceState.mode === 'Mediation') {
        openReferPangkatModal();
    }
}

/**
 * Fetch stage overview and session lists
 */
async function loadStageData() {
    const caseId = stageWorkspaceState.caseId;
    if (!caseId) return;

    try {
        const res = await fetch(getStageApiUrl(`stages/details.php?case_id=${encodeURIComponent(caseId)}&_t=${Date.now()}`), { cache: 'no-store' });
        if (!res.ok) {
            throw new Error(`Server returned HTTP ${res.status}: ${res.statusText}`);
        }
        const json = await res.json();
        if (!json.success || !json.data) {
            throw new Error(json.message || 'Unable to load stage workspace details.');
        }

        stageWorkspaceState.stageData = json.data;
        if (!stageWorkspaceState.complaintId && json.data.case && json.data.case.complaint_id) {
            stageWorkspaceState.complaintId = Number(json.data.case.complaint_id);
        }

        const mode = stageWorkspaceState.mode;
        if (mode === 'Mediation') {
            stageWorkspaceState.sessions = json.data.mediation_sessions || [];
        } else if (mode === 'Conciliation') {
            stageWorkspaceState.sessions = json.data.conciliation_sessions || [];
        } else if (mode === 'Arbitration') {
            stageWorkspaceState.sessions = json.data.arbitration_sessions || [];
        } else {
            stageWorkspaceState.sessions = json.data.mediation_sessions || [];
        }

        // Set default active session if not selected
        if (!stageWorkspaceState.activeSessionHearingId && stageWorkspaceState.sessions.length > 0) {
            stageWorkspaceState.activeSessionHearingId = Number(stageWorkspaceState.sessions[0].hearing_id);
        }

        renderWorkspaceUI();
    } catch (err) {
        console.error('loadStageData error:', err);
        const overviewContainer = document.getElementById('wsStageOverviewGrid');
        if (overviewContainer) {
            overviewContainer.innerHTML = `
                <div class="overview-stat-card" style="border-color: #fca5a5; background: #fef2f2; grid-column: 1 / -1; padding: 18px;">
                    <span class="stat-label" style="color: #991b1b; font-weight: 700;">Error Loading Stage Data</span>
                    <strong class="stat-value" style="font-size: 0.95rem; color: #991b1b; margin-top: 4px;">${escapeHtml(err.message)}</strong>
                    <div style="margin-top: 10px;">
                        <button type="button" class="btn-secondary" onclick="loadStageData()" style="font-size: 0.8rem; padding: 5px 12px; cursor: pointer;">Retry Loading</button>
                    </div>
                </div>
            `;
        }
    }
}

/**
 * Fetch Lupon Members for Pangkat nominations
 */
async function loadLuponMembers() {
    try {
        const res = await fetch(getStageApiUrl('assignments/lupon-members.php'));
        const json = await res.json();
        stageWorkspaceState.luponMembers = Array.isArray(json) ? json : (json.data || []);
        populateLuponDropdowns();
    } catch (e) {
        console.warn('Could not load lupon members:', e);
    }
}

function populateLuponDropdowns() {
    const members = stageWorkspaceState.luponMembers || [];
    const selects = ['referChairmanId', 'referSecretaryId', 'referMemberId'];

    selects.forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const currentVal = sel.value;
        let opts = '<option value="">-- Select Lupon Member --</option>';
        members.forEach(m => {
            const name = `${m.last_name}, ${m.first_name} ${m.middle_name || ''}`.trim();
            opts += `<option value="${Number(m.member_id)}">${escapeHtml(name)}</option>`;
        });
        sel.innerHTML = opts;
        if (currentVal) sel.value = currentVal;
    });
}

/**
 * Check URL Hash / Query actions (e.g. #attendance, #minutes, #outcome, #referral)
 */
function checkUrlHashAction() {
    const urlParams = new URLSearchParams(window.location.search);
    const action = urlParams.get('action') || window.location.hash.replace('#', '');
    const hearingIdParam = urlParams.get('hearing_id');

    setTimeout(() => {
        if (hearingIdParam) {
            selectInlineMinutesSession(Number(hearingIdParam));
            return;
        }

        if (action === 'schedule') {
            openScheduleModal();
        } else if (action === 'outcome') {
            openRecordOutcomeModal();
        } else if (action === 'referral') {
            openReferPangkatModal();
        } else if (action === 'minutes' || action === 'minutesSection') {
            const firstSession = stageWorkspaceState.sessions[0];
            if (firstSession) selectInlineMinutesSession(firstSession.hearing_id);
        } else if (action === 'attendance') {
            const firstSession = stageWorkspaceState.sessions[0];
            if (firstSession) openAttendanceModal(firstSession.hearing_id);
        }
    }, 250);
}

/**
 * Main Render Dispatcher
 */
function renderWorkspaceUI() {
    const data = stageWorkspaceState.stageData;
    if (!data) return;

    const mode = stageWorkspaceState.mode;
    let stage = data.mediation;
    if (mode === 'Conciliation') stage = data.conciliation;
    else if (mode === 'Arbitration') stage = data.arbitration;
    if (!stage) stage = {};

    // Gate check for locked stages
    const lockedGateEl = document.getElementById('stageLockedGate');
    const contentWrapEl = document.getElementById('stageWorkspaceContent');

    if (stage.is_locked) {
        if (lockedGateEl) lockedGateEl.style.display = 'block';
        if (contentWrapEl) contentWrapEl.style.display = 'none';
        return;
    } else {
        if (lockedGateEl) lockedGateEl.style.display = 'none';
        if (contentWrapEl) contentWrapEl.style.display = 'block';
    }

    renderHeaderAndBreadcrumbs(data, stage);
    renderStageOverview(data, stage);
    if (mode === 'Conciliation') {
        renderPangkatPanelSection(data, stage);
    }
    renderSessionsList(data, stage);
    renderSessionNavigation(data, stage);
    renderAttendanceSection(data, stage);
    renderMinutesSection(data, stage);
    renderOutcomeAndReferralSection(data, stage);
}

function renderSessionNavigation(data, stage) {
    let navContainer = document.getElementById('wsSessionNavContainer');
    if (!navContainer) {
        const attSection = document.getElementById('attendanceSection');
        if (attSection && attSection.parentNode) {
            navContainer = document.createElement('div');
            navContainer.id = 'wsSessionNavContainer';
            navContainer.style.marginBottom = '20px';
            attSection.parentNode.insertBefore(navContainer, attSection);
        }
    }
    if (!navContainer) return;

    const sessions = stageWorkspaceState.sessions || [];
    const mode = stageWorkspaceState.mode || 'Mediation';
    if (!sessions.length) {
        navContainer.innerHTML = '';
        return;
    }

    if (!stageWorkspaceState.activeSessionHearingId) {
        stageWorkspaceState.activeSessionHearingId = Number(sessions[0].hearing_id);
    }
    const activeHearingId = Number(stageWorkspaceState.activeSessionHearingId);

    const sessionTabsHtml = sessions.map(s => {
        const hId = Number(s.hearing_id);
        const isActive = (hId === activeHearingId);
        const sIndex = Number(s.session_index || 1);
        const ordinal = getStageSessionOrdinalLabel(mode || stageWorkspaceState.stageType, sIndex);
        const hasMin = Boolean(s.minutes_id && s.minutes_status !== 'Not Recorded');
        const statusLabel = s.minutes_status || (hasMin ? 'Minutes Recorded' : 'Not Recorded');

        const activeStyles = isActive
            ? 'border: 2px solid #2563eb; background: #eff6ff; box-shadow: 0 2px 6px rgba(37,99,235,0.15);'
            : 'border: 1px solid #cbd5e1; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.03);';
        const titleColor = isActive ? '#1d4ed8' : '#0f172a';

        return `
            <button type="button" 
                    class="ws-session-page-tab ${isActive ? 'active' : ''}" 
                    onclick="switchActiveSession(${hId})"
                    style="border-radius: 8px; padding: 8px 16px; display: inline-flex; align-items: center; gap: 10px; font-family: inherit; transition: all 0.15s ease; cursor: pointer; text-align: left; ${activeStyles}">
                <span style="width: 8px; height: 8px; border-radius: 50%; background: ${isActive ? '#2563eb' : '#94a3b8'};"></span>
                <div>
                    <div style="font-size: 0.88rem; font-weight: 700; color: ${titleColor};">
                        #${sIndex} ${escapeHtml(ordinal)}
                    </div>
                    <div style="font-size: 0.74rem; color: #64748b; margin-top: 1px;">
                        ${formatDateTimeReadable(s.hearing_date)}
                    </div>
                </div>
                <span style="font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; font-weight: 700; background: ${hasMin ? '#dbeafe' : '#f1f5f9'}; color: ${hasMin ? '#1e40af' : '#64748b'};">
                    ${escapeHtml(statusLabel)}
                </span>
            </button>
        `;
    }).join('');

    navContainer.innerHTML = `
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <span style="font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #475569;">
                    Hearing Sessions:
                </span>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    ${sessionTabsHtml}
                </div>
            </div>
        </div>
    `;
}
window.renderSessionNavigation = renderSessionNavigation;

function switchActiveSession(hearingId) {
    const hId = Number(hearingId);
    stageWorkspaceState.activeSessionHearingId = hId;
    stageWorkspaceState.currentHearingId = hId;

    const data = stageWorkspaceState.stageData || {};
    const mode = stageWorkspaceState.mode || 'Mediation';
    const stage = mode === 'Conciliation' ? data.conciliation : (mode === 'Arbitration' ? data.arbitration : data.mediation);

    renderSessionNavigation(data, stage || {});
    openAttendanceModal(hId, false);
    renderMinutesSection(data, stage || {});
}
window.switchActiveSession = switchActiveSession;

function renderAttendanceSection(data, stage) {
    const cardBody = document.getElementById('wsAttCardBody');
    if (!cardBody) return;

    const sessions = stageWorkspaceState.sessions || [];
    if (!sessions.length) {
        cardBody.innerHTML = '<div class="empty-detail-state" style="text-align: center; padding: 24px 16px; color: #64748b;">No hearing sessions scheduled yet. Please schedule a session above to record attendance.</div>';
        return;
    }

    const targetHearingId = stageWorkspaceState.currentHearingId || stageWorkspaceState.activeSessionHearingId || sessions[0].hearing_id;
    openAttendanceModal(targetHearingId, false);
}

/**
 * Render Header & Breadcrumbs
 */
function renderHeaderAndBreadcrumbs(data, stage) {
    const caseData = data.case || {};
    const titleEl = document.getElementById('wsStageTitle');
    const badgeEl = document.getElementById('wsStageStatusBadge');
    const subtextEl = document.getElementById('wsCaseSubtext');
    const breadcrumbStage = document.getElementById('wsBreadcrumbStage');
    const backLinkEl = document.getElementById('wsBackToComplaintLink');

    if (titleEl) {
        if (stageWorkspaceState.mode === 'Mediation') {
            titleEl.textContent = 'Mediation';
            document.title = 'Mediation - AGAP';
        } else if (stageWorkspaceState.mode === 'Conciliation') {
            titleEl.textContent = 'Pangkat Conciliation';
            document.title = 'Pangkat Conciliation - AGAP';
        } else if (stageWorkspaceState.mode === 'Arbitration') {
            titleEl.textContent = 'Binding Arbitration';
            document.title = 'Binding Arbitration - AGAP';
        }
    }

    if (breadcrumbStage) {
        breadcrumbStage.textContent = (stageWorkspaceState.mode === 'Mediation') ? 'Mediation' : (stageWorkspaceState.mode === 'Conciliation' ? 'Pangkat Conciliation' : 'Binding Arbitration');
    }

    if (badgeEl) {
        badgeEl.textContent = stage.stage_status;
        let bg = '#f1f5f9', color = '#475569';
        const st = stage.stage_status;
        if (st === 'Completed' || st === 'Settled') { bg = '#dcfce7'; color = '#15803d'; }
        else if (st === 'In Progress') { bg = '#dbeafe'; color = '#1d4ed8'; }
        else if (st === 'Awaiting Outcome' || st === 'Ready for Referral') { bg = '#fef3c7'; color = '#b45309'; }
        else if (st === 'Referred to Pangkat') { bg = '#e0f2fe'; color = '#0369a1'; }
        badgeEl.style.background = bg;
        badgeEl.style.color = color;
    }

    if (subtextEl) {
        const compNames = caseData.complainant_names || caseData.complainant_name || '—';
        const respNames = caseData.respondent_names || caseData.respondent_name || '—';
        const compTitle = caseData.complaint_title || caseData.title || caseData.case_title || '';
        const titleStr = compTitle ? ` · "${escapeHtml(compTitle)}"` : '';
        subtextEl.innerHTML = `Case #${escapeHtml(caseData.case_number || 'N/A')} · <strong>${escapeHtml(compNames)}</strong> vs <strong>${escapeHtml(respNames)}</strong>${titleStr}`;
    }

    if (backLinkEl && stageWorkspaceState.complaintId) {
        backLinkEl.href = `../complaints/complaint-details.php?id=${encodeURIComponent(stageWorkspaceState.complaintId)}#caseWorkspaceSection`;
    }

    renderWorkspaceActions(stage);
}

function renderWorkspaceActions(stage) {
    const primary = document.getElementById('wsPrimaryAction');
    const more = document.getElementById('wsMoreActionsMenu');
    if (!primary || !more) return;

    const mode = stageWorkspaceState.mode;
    const actions = stage.documents_and_actions || {};
    const sessions = stageWorkspaceState.sessions || [];
    const unfinished = sessions.find((session) => !['Finalized', 'Completed'].includes(session.minutes_status || ''));
    let label = 'Schedule hearing';
    let handler = 'openScheduleModal()';

    if (unfinished) {
        label = 'Complete session closeout';
        handler = `openSessionCloseout(${Number(unfinished.hearing_id)})`;
    } else if (mode === 'Mediation' && actions.can_proceed_to_pangkat) {
        label = 'Constitute Pangkat';
        handler = 'openReferPangkatModal()';
    } else if (mode === 'Mediation' && actions.can_book_next_mediation) {
        label = `Book ${Number(actions.next_mediation_session_number || 2) === 2 ? '2nd' : '3rd'} Mediation`;
        handler = 'openScheduleModal()';
    } else if (mode === 'Conciliation' && actions.can_issue_cfa) {
        label = 'Issue CFA (KP Form 20)';
        handler = `window.location.href='../documents/cfa.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}'`;
    } else if (mode === 'Conciliation' && !sessions.length) {
        label = 'Schedule 1st Conciliation';
        handler = 'openScheduleModal()';
    }

    primary.innerHTML = `<button type="button" class="btn-secondary" onclick="openPrintBlankMinutes()" style="font-size:.82rem; padding:7px 14px; display:inline-flex; align-items:center; gap:5px; background:#fff; border:1px solid #cbd5e1; cursor:pointer; font-weight:600; color:#334155; margin-right:6px;" title="Print official blank ruled minutes sheet">🖨️ Print Blank Minutes Sheet</button><button type="button" class="btn-create" onclick="${handler}" style="font-size:.82rem; padding:7px 14px; display:inline-flex; align-items:center; gap:5px;">${escapeHtml(label)} &rarr;</button>`;

    const scheduleLabel = mode === 'Conciliation' ? 'Schedule Conciliation' : 'Schedule Mediation';
    const panelAction = mode === 'Conciliation'
        ? `<button type="button" class="btn-secondary" onclick="openPangkatSelectionModal()">Panel selection</button><button type="button" class="btn-secondary" onclick="openPangkatExtensionModal()">Grant extension</button>`
        : `<button type="button" class="btn-secondary" onclick="openReferPangkatModal()">Refer to Pangkat</button>`;
    const cfaAction = mode === 'Conciliation' ? `<a class="btn-secondary" href="../documents/cfa.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}">CFA workflow</a>` : '';
    const outcomeAction = (mode === 'Mediation') ? '' : `<button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()">Record stage outcome</button>`;
    more.innerHTML = `<button type="button" class="btn-secondary" onclick="openScheduleModal()">${scheduleLabel}</button>${outcomeAction}${panelAction}${cfaAction}`;
    more.querySelectorAll('.btn-secondary').forEach((button) => {
        button.style.cssText = 'display:block; width:100%; margin:2px 0; text-align:left; border:0; background:transparent; padding:8px 10px; cursor:pointer; text-decoration:none; color:#334155; font-size:.82rem;';
    });
}

function openSessionCloseout(hearingId) {
    selectInlineMinutesSession(hearingId);
    const session = stageWorkspaceState.sessions.find((item) => Number(item.hearing_id) === Number(hearingId)) || {};
    const attendanceRecorded = Boolean(session.attendance_recorded_at || session.complainant_attendance || session.respondent_attendance);
    if (!attendanceRecorded) {
        openAttendanceModal(hearingId);
        return;
    }
    document.getElementById('minutesSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
window.openSessionCloseout = openSessionCloseout;

/**
 * Render Stage Overview Panel
 */
function renderStageOverview(data, stage) {
    const caseData = data.case || {};
    const mode = stageWorkspaceState.mode;
    const overviewContainer = document.getElementById('wsStageOverviewGrid');
    if (!overviewContainer) return;

    let mediatorInfoHtml = '';
    if (mode === 'Mediation') {
        mediatorInfoHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">Presiding Mediator</span>
                <strong class="stat-value" style="font-size: 0.92rem;">${escapeHtml(stage.presiding_officer || 'Punong Barangay')}</strong>
                <span class="stat-sub">Barangay Captain / Mediator</span>
            </div>
        `;
    } else if (mode === 'Conciliation') {
        const pInfo = stage.pangkat_assignment || {};
        mediatorInfoHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">Pangkat Officers</span>
                <strong class="stat-value" style="font-size: 0.88rem;">${escapeHtml(pInfo.chairperson?.member_name || 'Pending')} (Chair)</strong>
                <span class="stat-sub">${escapeHtml(pInfo.secretary?.member_name || 'Pending')} (Sec) · ${escapeHtml(pInfo.member?.member_name || 'Pending')}</span>
            </div>
        `;
    } else if (mode === 'Arbitration') {
        mediatorInfoHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">Presiding Arbitrator</span>
                <strong class="stat-value" style="font-size: 0.92rem;">${escapeHtml(stage.presiding_officer || 'Punong Barangay')}</strong>
                <span class="stat-sub">Hearing Officer / Arbitrator</span>
            </div>
        `;
    }

    let deadlineBadgeHtml = '';
    if (mode === 'Mediation' && caseData.mediation_timer) {
        // 15-Day Mediation Limit badge removed
        deadlineBadgeHtml = '';
    } else if (mode === 'Conciliation' && stage.deadline) {
        const dl = stage.deadline;
        let conveningHtml = '';
        if (!dl.is_convened) {
            const isOverdue = !!dl.convening_overdue;
            const daysLeft = Number(dl.convening_days_left ?? 0);
            const badgeBg = isOverdue ? '#fee2e2' : '#fef3c7';
            const badgeColor = isOverdue ? '#991b1b' : '#92400e';
            const badgeText = isOverdue ? `Convening Overdue (${Math.abs(daysLeft)}d late)` : `Convene within ${daysLeft} day(s)`;
            conveningHtml = `
                <div class="overview-stat-card" style="border-left: 4px solid ${isOverdue ? '#ef4444' : '#f59e0b'};">
                    <span class="stat-label">3-Day Convening Requirement</span>
                    <span style="font-size: 0.8rem; padding: 3px 8px; border-radius: 4px; font-weight: 700; background: ${badgeBg}; color: ${badgeColor}; display: inline-flex; width: fit-content; margin: 3px 0;">
                        ${badgeText}
                    </span>
                    <span class="stat-sub">Deadline: ${formatDateReadable(dl.convening_deadline)} (RA 7160 §410c)</span>
                </div>
            `;
        } else {
            conveningHtml = `
                <div class="overview-stat-card" style="border-left: 4px solid #10b981;">
                    <span class="stat-label">Pangkat Convened</span>
                    <strong class="stat-value" style="font-size: 0.9rem; color: #15803d;">${formatDateReadable(dl.convened_date)}</strong>
                    <span class="stat-sub">Constituted: ${formatDateReadable(dl.formation_date)}</span>
                </div>
            `;
        }

        // 15-Day Conciliation Limit clock removed
        deadlineBadgeHtml = conveningHtml;
    } else if (mode === 'Arbitration') {
        const arbRec = stage.arbitration_record || {};
        deadlineBadgeHtml = `
            <div class="overview-stat-card">
                <span class="stat-label">Arbitration Protocol</span>
                <strong class="stat-value" style="font-size: 0.88rem; color: #1e40af;">${arbRec.agreement_date ? 'Agreement: ' + formatDateReadable(arbRec.agreement_date) : 'KP Form 14 Executed'}</strong>
                <span class="stat-sub">${arbRec.award_date ? 'Award Rendered: ' + formatDateReadable(arbRec.award_date) : 'Decision window: 10 calendar days'}</span>
            </div>
        `;
    }

    overviewContainer.innerHTML = `
        <div class="overview-stat-card">
            <span class="stat-label">Case Number</span>
            <strong class="stat-value">Case #${escapeHtml(caseData.case_number || 'N/A')}</strong>
            <span class="stat-sub">Type: ${escapeHtml(caseData.case_type || 'Civil')} · Status: ${escapeHtml(caseData.case_status || 'Docketed')}</span>
        </div>
        <div class="overview-stat-card">
            <span class="stat-label">Complainant(s)</span>
            <strong class="stat-value" style="font-size: 0.92rem;">${escapeHtml(caseData.complainant_names || '—')}</strong>
            <span class="stat-sub">Filing Date: ${formatDateReadable(caseData.filing_date)}</span>
        </div>
        <div class="overview-stat-card">
            <span class="stat-label">Respondent(s)</span>
            <strong class="stat-value" style="font-size: 0.92rem;">${escapeHtml(caseData.respondent_names || '—')}</strong>
            <span class="stat-sub">Barangay Tumana</span>
        </div>
        ${mediatorInfoHtml}
        ${deadlineBadgeHtml}
        <div class="overview-stat-card">
            <span class="stat-label">Total Sessions</span>
            <strong class="stat-value" style="font-size: 1.25rem; color: #0f172a;">${Number(stage.sessions_count || 0)}</strong>
            <span class="stat-sub">Latest: ${stage.latest_hearing_date ? formatDateReadable(stage.latest_hearing_date) : 'None'}</span>
        </div>
        <div class="overview-stat-card">
            <span class="stat-label">Current Stage Outcome</span>
            <strong class="stat-value" style="font-size: 0.92rem; color: ${stage.outcome === 'Settled' ? '#15803d' : '#b45309'};">${escapeHtml(stage.outcome || 'Pending')}</strong>
            <span class="stat-sub">${escapeHtml(stage.outcome_remarks || 'Awaiting formal hearing outcome')}</span>
        </div>
        ${stage.next_permitted_action ? `
        <div class="overview-stat-card" style="border-left: 4px solid #2563eb; background: #f8fafc;">
            <span class="stat-label">Next Permitted Action</span>
            <strong class="stat-value" style="font-size: 0.95rem; color: #1e40af;">${escapeHtml(stage.next_permitted_action.action)}</strong>
            <span class="stat-sub">${escapeHtml(stage.next_permitted_action.description)}</span>
        </div>
        ` : ''}
    `;
}

/**
 * Render Sessions List
 */
function renderSessionsList(data, stage) {
    const listContainer = document.getElementById('wsSessionsListContainer');
    const countBadge = document.getElementById('wsSessionsCountBadge');
    if (!listContainer) return;

    const sessions = stageWorkspaceState.sessions || [];
    if (countBadge) countBadge.textContent = `${sessions.length} session${sessions.length === 1 ? '' : 's'}`;

    if (!sessions.length) {
        listContainer.innerHTML = `
            <div class="empty-detail-state" style="text-align: center; padding: 36px 20px; background: #fff; border: 1px dashed #cbd5e1; border-radius: 8px;">
                <p style="margin: 0 0 12px; font-size: 0.95rem; color: #64748b;">
                    No ${escapeHtml(stageWorkspaceState.mode.toLowerCase())} hearing sessions scheduled yet.
                </p>
                <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.85rem; padding: 7px 16px; display: inline-flex; align-items: center; gap: 6px;">
                    Schedule ${escapeHtml(stageWorkspaceState.mode)} Hearing &rarr;
                </button>
            </div>
        `;
        return;
    }

    let cardsHtml = '';
    sessions.forEach(s => {
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
            queueBadgeHtml = `<span style="font-size: 0.74rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">In Session (Started: ${formatTimeOnly(s.actual_start_time)})</span>`;
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
                let icon = '';
                const st = p.attendance_status || 'Pending';
                if (st === 'Present') {
                    badgeStyle = 'background: #dcfce7; color: #15803d;';
                    icon = '';
                } else if (st === 'Late') {
                    badgeStyle = 'background: #dbeafe; color: #1e40af;';
                    icon = '';
                } else if (st === 'Excused') {
                    badgeStyle = 'background: #fef3c7; color: #b45309;';
                    icon = '';
                } else if (st === 'Absent') {
                    badgeStyle = 'background: #fee2e2; color: #991b1b;';
                    icon = '';
                }
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
                                #${s.session_index}
                            </span>
                            <h4 style="margin: 0; font-size: 1.05rem; color: #0f172a; font-weight: 700;">
                                ${escapeHtml(getStageSessionOrdinalLabel(stageWorkspaceState.mode || stageWorkspaceState.stageType, s.session_index || 1))}
                            </h4>
                            ${queueBadgeHtml}
                            <span class="status-pill status-${escapeHtml(s.status.toLowerCase())}" style="font-size: 0.74rem; padding: 2px 8px; border-radius: 4px; font-weight: 700;">
                                ${escapeHtml(s.status)}
                            </span>
                            ${s.attempt_exception_reason ? `
                                <span style="font-size: 0.72rem; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; padding: 2px 7px; border-radius: 4px; font-weight: 600;">
                                    Exception: ${escapeHtml(s.attempt_exception_reason)}
                                </span>
                            ` : ''}
                            ${s.cancellation_category ? `
                                <span style="font-size: 0.72rem; color: #dc2626; background: #fee2e2; border: 1px solid #fecaca; padding: 2px 7px; border-radius: 4px; font-weight: 600;">
                                    Emergency: ${escapeHtml(s.cancellation_category)}
                                </span>
                            ` : ''}
                        </div>
                        <div style="font-size: 0.84rem; color: #475569; display: flex; gap: 14px; flex-wrap: wrap;">
                            <span>Call Time: <strong>${formatDateTimeReadable(s.hearing_date)}</strong></span>
                            <span>Venue: <strong>${escapeHtml(s.venue || 'Lupon Court Room')}</strong></span>
                            ${s.presiding_officer_name ? `<span>Presider: <strong>${escapeHtml(s.presiding_officer_name)}</strong></span>` : ''}
                            ${s.actual_start_time ? `<span>Actual Start: <strong>${formatTimeOnly(s.actual_start_time)}</strong></span>` : ''}
                            ${s.actual_duration_minutes ? `<span>Duration: <strong>${s.actual_duration_minutes} mins</strong></span>` : ''}
                        </div>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                        ${!isConcluded && !isInSession ? `
                            ${isReady ? `
                            <button type="button" class="btn-create" onclick="handleDocketStartSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 12px; background: ${isReady ? '#16a34a' : '#2563eb'}; display: inline-flex; align-items: center; gap: 4px;" title="Out-of-order session activation: Call to session immediately if ready">
                                ▶️ Call to Session / Start Hearing
                            </button>
                            ` : ''}
                            <button type="button" class="btn-secondary" onclick="handleDocketStandby(${s.hearing_id}, ${isStandby ? 'false' : 'true'})" style="font-size: 0.78rem; padding: 5px 9px; display: inline-flex; align-items: center; gap: 4px; ${isStandby ? 'background: #fef3c7; color: #b45309; border-color: #fde68a; font-weight: 700;' : ''}" title="Put on standby to keep room available for other ready cases">
                                ${isStandby ? '▶️ Resume from Standby' : 'Put on Standby'}
                            </button>
                        ` : ''}
                        ${isInSession ? `
                            <button type="button" class="btn-create" onclick="handleDocketConcludeSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 12px; background: #b91c1c; display: inline-flex; align-items: center; gap: 4px;">
                                Conclude Session
                            </button>
                        ` : ''}
                        ${Number(s.session_index || 1) === 1 ? `
                            <button type="button" class="btn-secondary" onclick="openSummonsPreviewModal(${Number(stageWorkspaceState.caseId)}, null, ${Number(s.hearing_id)})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px; border-color: #0284c7; color: #0284c7; background: #f0f9ff;" title="Preview, print, or download KP Form No. 9 summons">
                                KP Form
                            </button>
                        ` : ''}
                        <button type="button" class="btn-secondary" onclick="openAttendanceModal(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;">
                            Detailed Attendance
                        </button>
                        <button type="button" class="btn-secondary" onclick="selectInlineMinutesSession(${s.hearing_id})" style="font-size: 0.78rem; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px; ${hasMinutes ? 'border-color: #86efac; background: #f0fdf4;' : ''}">
                            ${hasMinutes ? 'Edit Minutes' : 'Add Minutes'}
                        </button>
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
                                    <button type="button" class="btn-secondary" onclick="handleDocketStandby(${s.hearing_id}, true)" style="font-size: 0.74rem; padding: 4px 8px; background: #fff;">Move to Standby</button>
                                    <button type="button" class="btn-secondary" onclick="openAttendanceModal(${s.hearing_id})" style="font-size: 0.74rem; padding: 4px 8px; background: #fee2e2; border-color: #fca5a5; color: #991b1b;">Record Non-Appearance</button>
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
                                    <button type="button" class="btn-secondary" onclick="handleDocketStandby(${s.hearing_id}, true)" style="font-size: 0.74rem; padding: 4px 8px; background: #fff;" title="Put on standby to keep room available for other ready cases">Put on Standby</button>
                                </div>
                            </div>
                        `;
                    }
                    return '';
                })()}

                <!-- Daily Docket Live Arrival / Readiness Tracking Bar -->
                <div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="font-size: 0.76rem; font-weight: 700; color: #475569; text-transform: uppercase;">Party Arrival Check-In:</span>
                        ${(s.parties && s.parties.length > 2) ? s.parties.map(p => {
                            const isPIn = (p.attendance_status === 'Present' || p.attendance_status === 'Late');
                            return `
                                <button type="button" onclick="handleDocketCheckIn(${s.hearing_id}, '${p.party_type}', '${isPIn ? 'Waiting' : 'Checked In'}', ${p.resident_id})" style="font-size: 0.76rem; padding: 3px 8px; border-radius: 6px; border: 1px solid ${isPIn ? '#86efac' : '#cbd5e1'}; background: ${isPIn ? '#dcfce7' : '#fff'}; color: ${isPIn ? '#15803d' : '#475569'}; font-weight: 600; cursor: pointer;" title="Toggle arrival for ${escapeHtml(p.full_name)}">
                                    ${escapeHtml(p.party_type)} (${escapeHtml(p.full_name)}): [ ${isPIn ? 'Checked In' : 'Waiting'} ]
                                </button>
                            `;
                        }).join('') : `
                            <button type="button" onclick="handleDocketCheckIn(${s.hearing_id}, 'Complainant', '${compIn ? 'Waiting' : 'Checked In'}')" style="font-size: 0.78rem; padding: 3px 10px; border-radius: 6px; border: 1px solid ${compIn ? '#86efac' : '#cbd5e1'}; background: ${compIn ? '#dcfce7' : '#fff'}; color: ${compIn ? '#15803d' : '#475569'}; font-weight: 600; cursor: pointer;">
                                Complainant: [ ${compIn ? 'Checked In' : 'Waiting / In Transit'} ]
                            </button>
                            <button type="button" onclick="handleDocketCheckIn(${s.hearing_id}, 'Respondent', '${respIn ? 'Waiting' : 'Checked In'}')" style="font-size: 0.78rem; padding: 3px 10px; border-radius: 6px; border: 1px solid ${respIn ? '#86efac' : '#cbd5e1'}; background: ${respIn ? '#dcfce7' : '#fff'}; color: ${respIn ? '#15803d' : '#475569'}; font-weight: 600; cursor: pointer;">
                                Respondent: [ ${respIn ? 'Checked In' : 'Waiting / In Transit'} ]
                            </button>
                        `}
                    </div>
                    <div style="font-size: 0.8rem; font-weight: 600;">
                        ${isReady ? '<span style="color: #15803d;">Complete &amp; Ready for Hearing</span>' : '<span style="color: #64748b;">Waiting for parties to arrive</span>'}
                    </div>
                </div>

                <!-- Session Sub-Grid: Proof of Service & Attendance & Minutes Status -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.82rem;">
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">PROOF OF SERVICE</span>
                        <div style="margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 0.76rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; ${s.proof_of_service_status === 'Served' ? 'background: #dcfce7; color: #15803d;' : 'background: #fef3c7; color: #b45309;'}">
                                ${s.proof_of_service_status === 'Served' ? 'Notice Served' : 'Pending / Unserved'}
                            </span>
                        </div>
                    </div>
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">ATTENDANCE RECORD</span>
                        ${partyAttHtml}
                        ${s.attendance_notes ? `<div style="font-size: 0.75rem; color: #64748b; margin-top: 4px; font-style: italic;">Notes: ${escapeHtml(s.attendance_notes)}</div>` : ''}
                    </div>
                    <div>
                        <span style="color: #64748b; font-size: 0.74rem; display: block; font-weight: 600;">MINUTES OF THE MEETING</span>
                        <div style="margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 0.76rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; ${isFinalized ? 'background: #dcfce7; color: #15803d;' : (hasMinutes ? 'background: #dbeafe; color: #1e40af;' : 'background: #f1f5f9; color: #64748b;')}">
                                ${escapeHtml(s.minutes_status)}
                            </span>
                            ${s.session_outcome && s.session_outcome !== 'Pending' ? `<span style="font-size: 0.74rem; color: #475569;">(${escapeHtml(s.session_outcome)})</span>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    listContainer.innerHTML = cardsHtml;
}

function formatTimeOnly(dateStr) {
    if (!dateStr) return '—';
    try {
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch (e) {
        return dateStr;
    }
}

async function reloadWorkspace() {
    if (typeof loadStageData === 'function') {
        await loadStageData();
    }
}

async function handleDocketCheckIn(hearingId, partyType, status, residentId = null) {
    try {
        const payload = {
            action: 'check_in',
            hearing_id: hearingId,
            party_type: partyType,
            status: status
        };
        if (residentId) payload.resident_id = residentId;
        const res = await fetch(getStageApiUrl('hearings/queue.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            window.agapNotify?.(data.message, 'success');
            await reloadWorkspace();
        } else {
            notifyUser(data.message || 'Could not update party check-in.', 'error');
        }
    } catch (err) {
        console.error('Docket check-in error:', err);
        notifyUser('Server error updating party check-in.', 'error');
    }
}

async function handleDocketStandby(hearingId, standby) {
    try {
        const res = await fetch(getStageApiUrl('hearings/queue.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'standby',
                hearing_id: hearingId,
                standby: standby ? 1 : 0
            })
        });
        const data = await res.json();
        if (data.success) {
            window.agapNotify?.(data.message, 'info');
            await reloadWorkspace();
        } else {
            notifyUser(data.message || 'Could not update standby status.', 'error');
        }
    } catch (err) {
        console.error('Docket standby error:', err);
        notifyUser('Server error updating standby status.', 'error');
    }
}

async function handleDocketStartSession(hearingId) {
    if (!confirm('Call this hearing to session? Actual start time will be recorded now.')) {
        return;
    }
    try {
        const res = await fetch(getStageApiUrl('hearings/queue.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'start_session',
                hearing_id: hearingId
            })
        });
        const data = await res.json();
        if (data.success) {
            window.agapNotify?.(data.message, 'success');
            await reloadWorkspace();
        } else {
            notifyUser(data.message || 'Could not start session.', 'error');
        }
    } catch (err) {
        console.error('Docket start session error:', err);
        notifyUser('Server error starting session.', 'error');
    }
}

async function handleDocketConcludeSession(hearingId) {
    if (!confirm('Conclude this session? Actual end time and duration will be recorded.')) {
        return;
    }
    try {
        const res = await fetch(getStageApiUrl('hearings/queue.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'conclude_session',
                hearing_id: hearingId
            })
        });
        const data = await res.json();
        if (data.success) {
            window.agapNotify?.(data.message, 'success');
            await reloadWorkspace();
        } else {
            notifyUser(data.message || 'Could not conclude session.', 'error');
        }
    } catch (err) {
        console.error('Docket conclude session error:', err);
        notifyUser('Server error concluding session.', 'error');
    }
}

window.reloadWorkspace = reloadWorkspace;
window.handleDocketCheckIn = handleDocketCheckIn;
window.handleDocketStandby = handleDocketStandby;
window.handleDocketStartSession = handleDocketStartSession;
window.handleDocketConcludeSession = handleDocketConcludeSession;
window.formatTimeOnly = formatTimeOnly;

/**
 * Render Hearing Session Minutes & Settlement Progress Section
 * Streamlined Option 2: 3 Chronological Sections
 * (Preliminaries -> Proceedings & Discussion -> Agreed Terms & Outcome)
 */
/**
 * Dynamic Session-Specific Outcome Options (RA 7160 Sec. 410)
 */
function getOutcomeOptionsForSession(session, mode) {
    const curOutcome = (session && session.session_outcome) || 'Pending';
    const sessionIndex = session ? Number(session.session_index || 1) : 1;
    if (mode === 'Mediation') {
        const opts = [
            { val: 'Pending', label: 'Pending Evaluation' },
            { val: 'Settled', label: 'Amicably Settled / Nagkasundo (KP Form 16)' }
        ];
        if (sessionIndex === 1) {
            // 1st Mediation only
            opts.push({ val: 'Continue Mediation', label: 'Unresolved / Book 2nd Mediation' });
        } else {
            // 2nd Mediation (or higher): elevation unlocked, remove book 2nd mediation
            opts.push({ val: 'Elevate to Pangkat', label: 'Failure of Mediation / Endorse to Pangkat (KP Form 10)' });
        }
        // An agreement to arbitrate is distinct from the later award.  The
        // former must create KP Form 14 and offer the arbitration scheduler.
        opts.push({ val: 'Arbitration', label: 'Submit to Voluntary Arbitration (KP Form 14)' });
        const caseStatusMed = String(stageWorkspaceState.stageData?.case?.status || '').toLowerCase();
        if (caseStatusMed.includes('settled') || curOutcome === 'Repudiated') {
            opts.push({ val: 'Repudiated', label: 'Repudiated Settlement' });
        }
        opts.push({ val: 'Party Absent', label: 'Non-Appearance / Certified for Show Cause' });
        opts.push({ val: 'Dismissed', label: 'Dismiss Complaint — Complainant Absent / Non-Appearance (KP Rules)' });
        opts.push({ val: 'Rescheduled', label: 'Rescheduled — Session Reset' });
        return opts.map(o => `<option value="${o.val}" ${curOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    } else if (mode === 'Conciliation') {
        const opts = [
            { val: 'Pending', label: 'Pending Evaluation' },
            { val: 'Settled', label: 'Amicably Settled / Nagkasundo (KP Form 16)' }
        ];
        if (sessionIndex === 1) {
            // 1st Conciliation: can book 2nd conciliation, NO CFA yet
            opts.push({ val: 'Continue Conciliation', label: 'Unresolved / Book 2nd Conciliation' });
        } else if (sessionIndex === 2) {
            // 2nd Conciliation: can book 3rd conciliation OR issue CFA
            opts.push({ val: 'Continue Conciliation', label: 'Unresolved / Book 3rd Conciliation' });
            opts.push({ val: 'Pending CFA', label: 'Conciliation Failed — Ready for CFA (KP Form 20)' });
        } else {
            // 3rd Conciliation (or final): max conciliation sessions reached, issue CFA
            opts.push({ val: 'Pending CFA', label: 'Conciliation Failed — Ready for CFA (KP Form 20)' });
        }
        opts.push({ val: 'Arbitration', label: 'Submit to Voluntary Arbitration (KP Form 14)' });
        const caseStatusConc = String(stageWorkspaceState.stageData?.case?.status || '').toLowerCase();
        if (caseStatusConc.includes('settled') || curOutcome === 'Repudiated') {
            opts.push({ val: 'Repudiated', label: 'Repudiated Settlement' });
        }
        opts.push({ val: 'Party Absent', label: 'Party Absent / Non-appearance Recorded' });
        opts.push({ val: 'Dismissed', label: 'Dismiss Complaint — Complainant Absent / Non-Appearance (KP Rules)' });
        opts.push({ val: 'Rescheduled', label: 'Rescheduled — Session Reset' });
        return opts.map(o => `<option value="${o.val}" ${curOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    } else if (mode === 'Arbitration') {
        const opts = [
            { val: 'Continue Arbitration', label: 'Continue Arbitration Hearing' },
            { val: 'Arbitration Award', label: 'Render Arbitration Award (KP Form 15)' },
            { val: 'Settled', label: 'Settled — Parties Reached Settlement During Hearing' },
            { val: 'Party Absent', label: 'Party Absent' },
            { val: 'Rescheduled', label: 'Rescheduled' },
            { val: 'Failed', label: 'Dismissed / Repudiated Arbitration Agreement' },
            { val: 'Pending', label: 'Pending Evaluation' }
        ];
        return opts.map(o => `<option value="${o.val}" ${curOutcome === o.val ? 'selected' : ''}>${escapeHtml(o.label)}</option>`).join('');
    }
    return '';
}
window.getOutcomeOptionsForSession = getOutcomeOptionsForSession;

function renderSingleSessionMinutesCard(session, mode, allSessions) {
    const hId = session.hearing_id;
    const sessionIndex = Number(session.session_index || 1);
    const sessionLabel = getStageSessionOrdinalLabel(mode || stageWorkspaceState.mode || stageWorkspaceState.stageType, sessionIndex);
    const isFinalized = (session.minutes_status === 'Finalized');
    const readonlyAttr = ''; // Lupon clerk can continuously edit the contents of the minutes
    let curOutcome = session.session_outcome || 'Pending';
    const outcomeOptions = getOutcomeOptionsForSession(session, mode);

    const compAtt = session.complainant_attendance || 'Pending';
    const respAtt = session.respondent_attendance || 'Pending';
    const compAttBadge = compAtt === 'Present' ? 'background: #dcfce7; color: #15803d;' : (compAtt === 'Absent' ? 'background: #fee2e2; color: #991b1b;' : 'background: #f1f5f9; color: #64748b;');
    const respAttBadge = respAtt === 'Present' ? 'background: #dcfce7; color: #15803d;' : (respAtt === 'Absent' ? 'background: #fee2e2; color: #991b1b;' : 'background: #f1f5f9; color: #64748b;');

    let presiderDisplayHtml = '';
    if (session.substitute_presider_name) {
        presiderDisplayHtml = `
            <div style="font-size: 0.78rem; margin-bottom: 7px; padding: 5px 9px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: #475569; font-weight: 600;">Presider:</span>
                    <span style="font-size: 0.7rem; font-weight: 700; padding: 1px 5px; border-radius: 4px; background: #fee2e2; color: #991b1b;">Absent</span>
                </div>
                <div style="color: #0f172a; font-weight: 600; font-size: 0.8rem;">
                    ${escapeHtml(session.presiding_officer_name || 'Punong Barangay')}
                </div>
                <div style="margin-top: 3px; font-size: 0.75rem; color: #2563eb; font-weight: 600;">
                    ↳ Substitute: ${escapeHtml(session.substitute_presider_name)} (Consent: Verified)
                </div>
                ${session.substitute_reason ? `<div style="font-size: 0.71rem; color: #64748b; margin-top: 2px;">Reason: ${escapeHtml(session.substitute_reason)}</div>` : ''}
            </div>
        `;
    } else {
        presiderDisplayHtml = `
            <div style="font-size: 0.78rem; margin-bottom: 7px; padding: 5px 9px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="color: #475569; font-weight: 600;">Presider:</span>
                    <span style="color: #0f172a; font-weight: 600; margin-left: 4px;">${escapeHtml(session.presiding_officer_name || (mode === 'Conciliation' ? 'Pangkat Chairman' : 'Punong Barangay / Lupon Chairman'))}</span>
                </div>
                <span style="font-size: 0.7rem; font-weight: 700; padding: 1px 5px; border-radius: 4px; background: #dcfce7; color: #15803d;">Present</span>
            </div>
        `;
    }

    const isSecondOrLater = (sessionIndex >= 2 || !['Initial Hearing', 'Mediation'].includes(session.hearing_type));
    let compConsequenceHtml = '';
    if (compAtt === 'Absent') {
        if (isSecondOrLater) {
            compConsequenceHtml = `
                <div style="margin-top: 5px; font-size: 0.74rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 5px 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px; flex-wrap: wrap;">
                        <span style="color: #991b1b; font-weight: 700;">Complainant Non-appearance</span>
                        <button type="button" class="btn-secondary" onclick="openKPFormPreview(${stageWorkspaceState.caseId}, 'KP Form 18', ${hId})" style="font-size: 0.7rem; padding: 2px 7px; background: #fff; border-color: #fca5a5; color: #991b1b; cursor: pointer;">
                            Preview KP Form 18
                        </button>
                    </div>
                    <div style="color: #64748b; font-size: 0.71rem; margin-top: 3px;">
                        KP Form 21 (Bar Action) locked pending show-cause evaluation.
                    </div>
                </div>
            `;
        } else {
            compConsequenceHtml = `
                <div style="margin-top: 5px; font-size: 0.74rem; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 5px 8px;">
                    <span style="color: #475569; font-weight: 600;">Complainant Non-appearance (${escapeHtml(sessionLabel)}):</span>
                    <span style="color: #64748b; margin-left: 4px;">Proceed to book 2nd session. KP Form 18 notice unlocks upon non-appearance in 2nd session.</span>
                </div>
            `;
        }
    }

    let respConsequenceHtml = '';
    if (respAtt === 'Absent') {
        if (isSecondOrLater) {
            respConsequenceHtml = `
                <div style="margin-top: 5px; font-size: 0.74rem; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 5px 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 6px; flex-wrap: wrap;">
                        <span style="color: #991b1b; font-weight: 700;">Respondent Non-appearance</span>
                        <button type="button" class="btn-secondary" onclick="openKPFormPreview(${stageWorkspaceState.caseId}, 'KP Form 19', ${hId})" style="font-size: 0.7rem; padding: 2px 7px; background: #fff; border-color: #fca5a5; color: #991b1b; cursor: pointer;">
                            Preview KP Form 19
                        </button>
                    </div>
                    <div style="color: #64748b; font-size: 0.71rem; margin-top: 3px;">
                        KP Form 22 (Bar Counterclaim) locked pending show-cause evaluation.
                    </div>
                </div>
            `;
        } else {
            respConsequenceHtml = `
                <div style="margin-top: 5px; font-size: 0.74rem; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 5px 8px;">
                    <span style="color: #475569; font-weight: 600;">Respondent Non-appearance (${escapeHtml(sessionLabel)}):</span>
                    <span style="color: #64748b; margin-left: 4px;">Proceed to book 2nd session. KP Form 19 notice unlocks upon non-appearance in 2nd session.</span>
                </div>
            `;
        }
    }

    const cardBorder = sessionIndex === 1 ? '#cbd5e1' : (sessionIndex === 2 ? '#93c5fd' : '#c084fc');
    const badgeColor = session.minutes_id ? 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;' : 'background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0;';

    const isActiveCard = (Number(session.hearing_id) === Number(stageWorkspaceState.activeSessionHearingId));
    const cardDisplay = isActiveCard ? 'block' : 'none';

    return `
        <div class="inline-minutes-card" id="minutesCard_${hId}" style="display: ${cardDisplay}; background: #fff; border: 1.5px solid ${cardBorder}; border-radius: 12px; padding: 22px 24px; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
            <!-- Card Header -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid #e2e8f0;">
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="background: ${sessionIndex === 1 ? '#2563eb' : (sessionIndex === 2 ? '#0284c7' : '#7c3aed')}; color: #fff; font-size: 0.76rem; font-weight: 800; padding: 2px 9px; border-radius: 6px;">
                            ${escapeHtml(mode.toUpperCase())} #${sessionIndex}
                        </span>
                        <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #0f172a;">
                            Minutes of the Meeting &amp; Hearing: ${escapeHtml(sessionLabel)}
                        </h3>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <span style="font-size: 0.78rem; padding: 4px 10px; border-radius: 9999px; font-weight: 700; ${badgeColor}">
                        ${session.minutes_id ? 'SAVED MINUTES' : 'NOT YET RECORDED'}
                    </span>
                    <button type="button" class="btn-secondary" onclick="openPrintBlankMinutes()" style="font-size: 0.78rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;" title="Print official blank ruled minutes sheet">
                        🖨️ Print Blank Minutes Sheet
                    </button>
                    <button type="button" class="btn-secondary" onclick="openPreviewMinutesModal(${hId})" style="font-size: 0.78rem; padding: 4px 10px;">
                        Preview Letterhead
                    </button>
                    <button type="button" class="btn-secondary" onclick="printHearingMinutes(${hId})" style="font-size: 0.78rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                        🖨️ Print Minutes
                    </button>
                </div>
            </div>


            <!-- Form -->
            <form id="inlineMinutesForm_${hId}" data-hearing-id="${hId}" onsubmit="event.preventDefault();">
                <input type="hidden" id="minHearingId_${hId}" name="hearing_id" value="${hId}">

                <!-- ============================================================== -->
                <!-- SECTION 1: SALAYSAY, BUOD NG USAPAN AT TALAKAYAN              -->
                <!-- ============================================================== -->
                <div class="minutes-flow-step" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: #2563eb; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 700;">1</span>
                            <div>
                                <h4 style="margin: 0; font-size: 0.96rem; font-weight: 700; color: #0f172a;">Salaysay, Buod ng Usapan at Talakayan (Proceedings &amp; Discussion)</h4>
                                <span style="font-size: 0.76rem; color: #64748b;">Itala ang buod ng alitan, mga salaysay, talakayan sa pagdinig, at kalakip na ebidensya</span>
                            </div>
                        </div>
                    </div>

                    <!-- Dispute Focus / Main Issue -->
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="minDisputeSummary_${hId}" style="font-weight: 700; font-size: 0.82rem; color: #1e293b; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center;">
                            <span>Pangunahing Usapin (Dispute Focus / Main Issue Identified)</span>
                            <span style="font-size: 0.72rem; color: #64748b; font-weight: normal;">Buod ng hidwaan</span>
                        </label>
                        <input type="text" id="minDisputeSummary_${hId}" name="minDisputeSummary" maxlength="1000" placeholder="Halimbawa: Paniningil ng utang, alitan sa boundary o bakod, panggugulo sa oras ng pahinga..." value="${escapeHtml(session.main_dispute_identified || '')}" ${readonlyAttr} style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>

                    <!-- Voice STT & Notes OCR Toolbar directly above Salaysay -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; flex-wrap: wrap; gap: 8px;">
                        <label for="session_minutes_${hId}" style="font-weight: 700; font-size: 0.84rem; color: #1e293b; margin-bottom: 0;">
                            Salaysay ng Magkabilang Panig at Buod ng Talakayan (Discussion Summary &amp; Statements) <span style="color: #dc2626;">*</span>
                        </label>
                            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <label style="font-size: 0.74rem; color: #475569; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="checkbox" id="stageAiConsentCheckbox_${hId}" checked style="accent-color: #2563eb; cursor: pointer;">
                                    <span>Consent to Gemini AI</span>
                                </label>
                                <button type="button" class="btn-secondary" id="btnStageRecordVoice_${hId}" onclick="toggleStageVoiceRecording(${hId})" style="font-size: 0.76rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                    <span id="stageRecordVoiceText_${hId}">🎙️ Record Voice (STT)</span>
                                </button>
                                <button type="button" class="btn-secondary" id="btnStageUploadNotes_${hId}" onclick="triggerStageNotesUpload(${hId})" style="font-size: 0.76rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                    📷 Upload Notes (OCR)
                                </button>
                                <input type="file" id="stageNotesFileInput_${hId}" accept="image/*" style="display: none;" onchange="handleStageNotesFileSelected(this, ${hId})">
                            </div>
                    </div>

                    <!-- AI Status Banner -->
                    <div id="stageAiStatusBanner_${hId}" style="display: none; padding: 8px 12px; margin-bottom: 10px; border-radius: 6px; font-size: 0.82rem; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af;"></div>

                    <!-- Central Discussion Textarea -->
                    <textarea id="session_minutes_${hId}" name="session_minutes" rows="5" placeholder="Detalyadong buod ng mga naging salaysay, paliwanag ng mga partido, at talakayan sa pagdinig..." ${readonlyAttr} required style="width: 100%; padding: 10px 12px; font-size: 0.86rem; line-height: 1.5; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; resize: vertical;">${escapeHtml(session.settlement_discussion_notes || session.session_minutes || '')}</textarea>

                    <!-- Kopya ng Isinulat-Kamay na Katitikan at Ebidensya -->
                    <div style="margin-top: 14px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <div style="margin-bottom: 10px;">
                            <h5 style="margin: 0 0 3px; font-size: 0.88rem; font-weight: 700; color: #0f172a;">
                                Kopya ng Isinulat-Kamay na Katitikan at Ebidensya (Written Minutes &amp; Evidences)
                            </h5>
                            <span style="font-size: 0.76rem; color: #64748b; display: block;">
                                I-upload ang litrato o scan ng isinulat-kamay na katitikan, lagda ng mga partido, at mga kalakip na ebidensya para sa sesyong ito.
                            </span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                            <div class="minutes-dropzone" style="padding: 12px 14px;">
                                <div style="font-size: 1.4rem; margin-bottom: 2px;">📄</div>
                                <strong style="display: block; font-size: 0.82rem; color: #0f172a; margin-bottom: 2px;">Upload Scanned / Written Minutes</strong>
                                <span style="display: block; font-size: 0.72rem; color: #64748b; margin-bottom: 8px;">PDF o larawan ng nilagdaang katitikan</span>
                                <input type="file" id="uploadHandwrittenMinutesInput_${hId}" data-hearing-id="${hId}" accept="image/*,.pdf" style="display: none;" onchange="handleMinutesEvidenceUpload(this, 'handwritten_minutes', ${hId})">
                                <button type="button" class="btn-secondary" onclick="document.getElementById('uploadHandwrittenMinutesInput_${hId}').click()" style="font-size: 0.75rem; padding: 5px 10px;" ${readonlyAttr}>
                                    📄 Upload Scanned / Written Minutes
                                </button>
                            </div>
                            <div class="minutes-dropzone" style="padding: 12px 14px;">
                                <div style="font-size: 1.4rem; margin-bottom: 2px;">📎</div>
                                <strong style="display: block; font-size: 0.82rem; color: #0f172a; margin-bottom: 2px;">Attach Evidences &amp; Photos</strong>
                                <span style="display: block; font-size: 0.72rem; color: #64748b; margin-bottom: 8px;">Larawan, resibo, o iba pang kasulatan</span>
                                <input type="file" id="uploadEvidenceAttachmentsInput_${hId}" data-hearing-id="${hId}" accept="image/*,.pdf,.doc,.docx" multiple style="display: none;" onchange="handleMinutesEvidenceUpload(this, 'evidence', ${hId})">
                                <button type="button" class="btn-secondary" onclick="document.getElementById('uploadEvidenceAttachmentsInput_${hId}').click()" style="font-size: 0.75rem; padding: 5px 10px;" ${readonlyAttr}>
                                    📎 Attach Evidences / Photos / Receipts
                                </button>
                            </div>
                        </div>
                        <div id="minutesUploadedDocsContainer_${hId}">
                            <span style="font-size: 0.78rem; color: #94a3b8; font-style: italic;">Walang nakalakip na dokumento o isinulat-kamay na katitikan sa kasalukuyan.</span>
                        </div>
                    </div>
                </div>

                <!-- ============================================================== -->
                <!-- SECTION 2: MGA MUNGKAHI, ALOK AT NAPAGKASUNDUANG KONDISYON   -->
                <!-- ============================================================== -->
                <div class="minutes-flow-step" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: #16a34a; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 700;">2</span>
                            <div>
                                <h4 style="margin: 0; font-size: 0.96rem; font-weight: 700; color: #0f172a;">Mga Mungkahi, Alok at Napagkasunduang Kondisyon (Agreed Terms &amp; Settlement)</h4>
                                <span style="font-size: 0.76rem; color: #64748b;">Itala ang mga napagkasunduan, plano ng pagbabayad o pagtupad, o mga puntong hindi pa nareresolba</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Pre-drafted Clause Chips -->
                    <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; align-items: center;">
                        <span style="font-size: 0.75rem; font-weight: 600; color: #475569;">Quick Insert:</span>
                        <button type="button" class="btn-secondary" onclick="insertAgreedClause('Nagkasundo ang magkabilang panig na ayusin ang alitan sa pamamagitan ng...', ${hId})" style="font-size: 0.72rem; padding: 2px 8px; border-radius: 12px; background: #f8fafc;" ${readonlyAttr}>
                            + Kasunduan sa Pag-aayos
                        </button>
                        <button type="button" class="btn-secondary" onclick="insertAgreedClause('Nangangako ang ipinagsumbong na magbabayad ng halagang ₱________ sa o bago sumapit ang petsang ________.', ${hId})" style="font-size: 0.72rem; padding: 2px 8px; border-radius: 12px; background: #f8fafc;" ${readonlyAttr}>
                            + Plano ng Pagbabayad
                        </button>
                        <button type="button" class="btn-secondary" onclick="insertAgreedClause('Pumapayag ang may sumbong na bigyan ng palugit ang ipinagsumbong hanggang ________ upang tuparin ang napag-usapan.', ${hId})" style="font-size: 0.72rem; padding: 2px 8px; border-radius: 12px; background: #f8fafc;" ${readonlyAttr}>
                            + Takdang Araw ng Pagtupad
                        </button>
                    </div>

                    <!-- Agreed Terms Textarea -->
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="minAgreements_${hId}" style="font-weight: 700; font-size: 0.84rem; color: #166534; margin-bottom: 4px; display: block;">
                            Napagkasunduang Kondisyon at Kasunduan (Agreed Terms / Action Items)
                        </label>
                        <textarea id="minAgreements_${hId}" name="minAgreements" rows="4" maxlength="4000" placeholder="Mga partikular na kondisyong pinagkasunduan, halaga ng bayarin, iskedyul ng pagbabayad, o mga gagawin..." ${readonlyAttr} style="width: 100%; padding: 10px 12px; font-size: 0.86rem; line-height: 1.5; border: 1px solid #86efac; background: #f0fdf4; border-radius: 6px; box-sizing: border-box; resize: vertical;">${escapeHtml(session.agreements_action_items || '')}</textarea>
                    </div>

                    <!-- Contentious / Unresolved items -->
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="minUnresolved_${hId}" style="font-weight: 600; font-size: 0.82rem; color: #991b1b; margin-bottom: 4px; display: block;">
                            Usaping Hindi Napagkasunduan (Issues Remaining Unresolved)
                        </label>
                        <textarea id="minUnresolved_${hId}" name="minUnresolved" rows="2" maxlength="3000" placeholder="Mga puntong hindi nagkasundo ang mga partido..." ${readonlyAttr} style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #fca5a5; background: #fef2f2; border-radius: 6px; box-sizing: border-box; resize: vertical;">${escapeHtml(session.unresolved_issues || '')}</textarea>
                    </div>
                </div>

                <!-- ============================================================== -->
                <!-- SECTION 3: KINALABASAN NG SESYON (Outcome Determination)        -->
                <!-- ============================================================== -->
                <div class="minutes-flow-step" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background: #7c3aed; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 700;">3</span>
                            <div>
                                <h4 style="margin: 0; font-size: 0.96rem; font-weight: 700; color: #0f172a;">Kinalabasan ng Sesyon (Outcome Determination)</h4>
                                <span style="font-size: 0.76rem; color: #64748b;">Itala ang opisyal na resulta ng pagdinig, oras ng pagtatapos, at susunod na legal na hakbang</span>
                            </div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1.2fr 1.1fr 1.3fr; gap: 14px; margin-bottom: 14px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minOutcome_${hId}" style="font-weight: 700; font-size: 0.82rem; color: #0f172a; margin-bottom: 4px; display: block;">
                                Kinalabasan ng Pagdinig (Session Outcome) <span style="color: #dc2626;">*</span>
                            </label>
                            <select id="minOutcome_${hId}" name="minOutcome" onchange="handleMinutesOutcomeChange(this.value, true, ${hId})" ${readonlyAttr} required style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1.5px solid #2563eb; border-radius: 6px; background: #fff; font-weight: 600; color: #0f172a;">
                                ${outcomeOptions}
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <label for="minActualEndTime_${hId}" style="font-weight: 700; font-size: 0.82rem; color: #0f172a; margin: 0;">
                                    Aktwal na Oras ng Pagtatapos
                                </label>
                                ${!readonlyAttr ? `
                                    <button type="button" onclick="setNowHearingEndTime(${hId})" style="background: none; border: none; color: #2563eb; font-size: 0.74rem; font-weight: 600; cursor: pointer; padding: 0;">
                                        Set Now
                                    </button>
                                ` : ''}
                            </div>
                            <input type="time" id="minActualEndTime_${hId}" name="minActualEndTime" value="${escapeHtml(formatForTimeInput(session.actual_end_time))}" ${readonlyAttr} style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="minOutcomeRemarks_${hId}" style="font-weight: 700; font-size: 0.82rem; color: #0f172a; margin-bottom: 4px; display: block;">
                                Opisyal na Tala / Remarks
                            </label>
                            <input type="text" id="minOutcomeRemarks_${hId}" name="minOutcomeRemarks" placeholder="Mga tagubilin, referral, o kasunduan..." value="${escapeHtml(session.outcome_remarks || '')}" ${readonlyAttr} style="width: 100%; padding: 8px 12px; font-size: 0.85rem; border: 1px solid #cbd5e1; border-radius: 6px;">
                        </div>
                    </div>

                    <!-- Dynamic Next-Step Action Banner -->
                    <div id="minStageActionBanner_${hId}" style="margin-top: 14px;"></div>
                </div>

                <!-- Actions Footer -->
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-secondary" onclick="openPrintBlankMinutes()" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                            🖨️ Print Blank Minutes Sheet
                        </button>
                        <button type="button" class="btn-secondary" onclick="openPreviewMinutesModal(${hId})" style="font-size: 0.84rem; padding: 7px 14px;">
                            Preview Letterhead
                        </button>
                        <button type="button" class="btn-secondary" onclick="printHearingMinutes(${hId})" style="font-size: 0.84rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                            🖨️ Print Minutes
                        </button>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-secondary" onclick="renderMinutesSection(stageWorkspaceState.stageData, stageWorkspaceState.stageData?.mediation || {})" style="font-size: 0.84rem; padding: 7px 14px;">
                            Reset
                        </button>
                        <button type="button" class="btn-create" id="btnSaveMinutesDraft_${hId}" onclick="saveHearingMinutes('Draft', ${hId})" style="font-size: 0.84rem; padding: 7px 16px; background: #0284c7; border-color: #0284c7;">
                            Save #${sessionIndex} Draft
                        </button>
                    </div>
                </div>
            </form>
        </div>
    `;
}

function renderMinutesSection(data, stage) {
    const minContainer = document.getElementById('wsMinutesSectionContainer');
    if (!minContainer) return;

    const sessions = stageWorkspaceState.sessions || [];
    const mode = stageWorkspaceState.mode;

    if (!sessions.length) {
        minContainer.innerHTML = `
            <div style="text-align: center; padding: 36px 20px; color: #64748b; background: #fff; border: 1px dashed #cbd5e1; border-radius: 10px;">
                <p style="margin: 0 0 10px; font-size: 0.95rem; color: #475569;">No ${escapeHtml(mode.toLowerCase())} sessions scheduled yet.</p>
                <small style="display: block; margin-bottom: 14px; color: #94a3b8;">Schedule a session first to record hearing minutes, statements, and settlement progress.</small>
                <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.84rem; padding: 7px 16px;">
                    Schedule ${escapeHtml(mode)} Hearing &rarr;
                </button>
            </div>
        `;
        return;
    }

    if (!stageWorkspaceState.activeSessionHearingId) {
        stageWorkspaceState.activeSessionHearingId = Number(sessions[0].hearing_id);
    }
    const activeHearingId = Number(stageWorkspaceState.activeSessionHearingId);
    const activeSession = sessions.find(s => Number(s.hearing_id) === activeHearingId) || sessions[0];

    // Render ONLY the active session card (distinct page view per session with independent attendance)
    minContainer.innerHTML = renderSingleSessionMinutesCard(activeSession, mode, sessions);

    // Teardown previous draft manager
    if (window.stageDraftManager && typeof window.stageDraftManager.destroy === 'function') {
        try { window.stageDraftManager.destroy(); } catch (e) {}
        window.stageDraftManager = null;
    }

    // Initialize documents, outcome banners, and draft managers for all rendered session cards
    sessions.forEach(s => {
        loadHearingMinutesDocuments(s.hearing_id);
        const curOutcome = s.session_outcome || 'Pending';
        handleMinutesOutcomeChange(curOutcome, false, s.hearing_id);

        if (window.AgapDraftManager) {
            const draftKey = `agap_draft_${stageWorkspaceState.mode}_case_${stageWorkspaceState.caseId}_hearing_${s.hearing_id}`;
            const minutesForm = document.getElementById(`inlineMinutesForm_${s.hearing_id}`);
            if (minutesForm) {
                window.AgapDraftManager.init(minutesForm, draftKey, {
                    indicatorId: `wsDraftIndicator_${s.hearing_id}`,
                    bannerMount: `#minutesCard_${s.hearing_id}`,
                    isReadOnly: s.minutes_status === 'Finalized',
                    onRestored: () => {
                        const outcomeEl = document.getElementById(`minOutcome_${s.hearing_id}`);
                        if (outcomeEl) {
                            handleMinutesOutcomeChange(outcomeEl.value, false, s.hearing_id);
                        }
                    }
                });
            }
        }
    });
}

function handleMinutesOutcomeChange(outcomeVal, isUserChange = false, hearingId = null) {
    const activeHearingId = hearingId || stageWorkspaceState.activeSessionHearingId;
    const bannerEl = document.getElementById(`minStageActionBanner_${activeHearingId}`) || document.getElementById('minStageActionBanner');
    if (!bannerEl) return;
    const mode = stageWorkspaceState.mode;
    const caseId = stageWorkspaceState.caseId;
    const activeSession = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(activeHearingId)) || {};
    const sessionIndex = Number(activeSession.session_index || 1);

    if (outcomeVal === 'Settled') {
        unlockOutcomesTab();
        bannerEl.innerHTML = `
            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="color: #166534; font-size: 0.85rem;">
                    <strong style="font-size: 0.95rem; color: #14532d; display: block;">Amicable Settlement Reached (Kasunduan)</strong>
                    <div style="font-size: 0.82rem; color: #15803d; margin-top: 3px;">Parties agreed on settlement terms. Click below to record settlement and proceed to KP Form 16.</div>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-create" onclick="issueSettledOutcome(${Number(activeHearingId || 0)})" style="font-size: 0.82rem; padding: 7px 16px; display: inline-flex; align-items: center; gap: 5px; background: #16a34a; border-color: #16a34a; color: #ffffff; border-radius: 6px; font-weight: 700; cursor: pointer;">
                        Open Amicable Settlement &rarr;
                    </button>
                    <button type="button" class="btn-secondary" onclick="openKpFormModal(${Number(caseId)}, 'KP Form 16', ${Number(activeHearingId || 0)})" style="font-size: 0.82rem; padding: 7px 14px; background: #fff; border-color: #86efac; color: #166534; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                        Preview / Print KP Form 16
                    </button>
                </div>
            </div>
        `;
    } else if (outcomeVal === 'Elevate to Pangkat') {
        const isSession1 = (sessionIndex === 1);
        if (mode === 'Mediation' && isSession1) {
            bannerEl.innerHTML = `
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #92400e; font-size: 0.85rem;">
                        <strong style="font-size: 0.92rem; display: block; color: #78350f;">1st Mediation Mandatory Safeguard (RA 7160 Sec. 410)</strong>
                        Mediation requires 2 sessions before formal elevation to Pangkat. Book 2nd Mediation session below.
                    </div>
                    <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                        Book 2nd Mediation &rarr;
                    </button>
                </div>
            `;
        } else {
            bannerEl.innerHTML = `
                <div style="background: #f8fafc; border: 1.5px solid #94a3b8; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #1e293b; font-size: 0.85rem;">
                        <strong style="font-size: 0.92rem; display: block; color: #0f172a;">Failure of Mediation Â· Elevate to Pangkat ng Tagapagkasundo</strong>
                        2nd Mediation session concluded without agreement. The dispute elevates to the 3-member Pangkat panel (KP Form 10, 11, and 12).
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openTriPanelPangkatModal(${Number(caseId)})" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                            Preview Pangkat Notices (KP 10, 11, 12)
                        </button>
                        <button type="button" class="btn-create" onclick="openReferPangkatModal()" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; background: #0f172a;">
                            Constitute Pangkat (KP Form 10) &rarr;
                        </button>
                    </div>
                </div>
            `;
        }
    } else if (outcomeVal === 'Continue Mediation' || outcomeVal === 'Continue Conciliation') {
        const isMed = (mode === 'Mediation');
        const nextIdx = sessionIndex + 1;
        const nextOrd = nextIdx === 2 ? '2nd' : (nextIdx === 3 ? '3rd' : `${nextIdx}th`);
        const hasNext = stageWorkspaceState.sessions.some(s => Number(s.session_index) === nextIdx);

        if (hasNext) {
            bannerEl.innerHTML = `
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #1e40af; font-size: 0.85rem;">
                        <strong style="font-size: 0.92rem; display: block; color: #1e3a8a;">${nextOrd} ${escapeHtml(mode)} Session Scheduled</strong>
                        The next hearing session is scheduled below. Finalize this session minutes to record proceedings.
                    </div>
                </div>
            `;
        } else {
            bannerEl.innerHTML = `
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="color: #1e40af; font-size: 0.85rem;">
                        <strong style="font-size: 0.92rem; display: block; color: #1e3a8a;">Unresolved Â· Schedule ${nextOrd} ${escapeHtml(mode)}</strong>
                        Parties were notified in person. Schedule the ${nextOrd} ${escapeHtml(mode)} session directly.
                    </div>
                    <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px;">
                        Schedule ${nextOrd} ${escapeHtml(mode)} Hearing &rarr;
                    </button>
                </div>
            `;
        }
    } else if (outcomeVal === 'Arbitration' || outcomeVal === 'Submit to Arbitration' || outcomeVal === 'Submit to Voluntary Arbitration (KP Form 14)') {
        unlockOutcomesTab();
        bannerEl.innerHTML = `
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin-top: 10px;">
                <strong style="color: #92400e; font-size: 0.95rem; display: block;">Parties Agreed to Voluntary Arbitration (KP Form 14)</strong>
                <p style="margin: 4px 0 10px; font-size: 0.84rem; color: #78350f;">
                    Both parties have voluntarily agreed in writing to submit their dispute to binding arbitration under RA 7160 Sec. 413.
                </p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-create" onclick="issueArbitrationOutcome(${Number(activeHearingId || 0)})" style="background: #d97706; border-color: #d97706; font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                        Issue Arbitration &rarr;
                    </button>
                </div>
            </div>
        `;
    } else if (outcomeVal === 'Repudiated' || outcomeVal === 'Repudiated Settlement') {
        unlockOutcomesTab();
        bannerEl.innerHTML = `
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="color: #991b1b; font-size: 0.85rem;">
                    <strong style="font-size: 0.92rem; display: block; color: #7f1d1d;">Settlement Repudiated Â· Unlocked KP Form 17 &amp; CFA</strong>
                    A party filed repudiation under oath. Proceed with KP Form 17 and issuance of Certificate to File Action.
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" onclick="openKpFormModal(${Number(caseId)}, 'KP Form 17', ${Number(activeHearingId)})" style="font-size: 0.82rem; padding: 7px 14px; background: #fff; border-color: #fca5a5; color: #991b1b; display: inline-flex; align-items: center; gap: 5px;">
                        Preview KP Form 17
                    </button>
                    <button type="button" class="btn-secondary" onclick="openKpFormModal(${Number(caseId)}, 'KP Form 20', ${Number(activeHearingId)})" style="font-size: 0.82rem; padding: 7px 14px; background: #fff; border-color: #fca5a5; color: #991b1b; display: inline-flex; align-items: center; gap: 5px;">
                        Preview CFA (KP Form 20)
                    </button>
                    <a href="../documents/cfa.php?case_id=${encodeURIComponent(caseId)}" class="btn-create" style="font-size: 0.82rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: #b91c1c;">
                        Issue CFA &rarr;
                    </a>
                </div>
            </div>
        `;
    } else if (outcomeVal === 'Pending CFA') {
        unlockOutcomesTab();
        bannerEl.innerHTML = `
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="color: #991b1b; font-size: 0.85rem;">
                    <strong style="font-size: 0.92rem; display: block; color: #7f1d1d;">Conciliation Concluded Â· Ready for CFA (KP Form 20)</strong>
                    Proceedings have failed to reach an amicable settlement. Eligible for Certificate to File Action. Outcomes tab is unlocked.
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" onclick="openKpFormModal(${Number(caseId)}, 'KP Form 20', ${Number(activeHearingId)})" style="font-size: 0.82rem; padding: 7px 14px; background: #fff; border-color: #fca5a5; color: #991b1b; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                        Preview KP Form 20
                    </button>
                    <a href="../documents/cfa.php?case_id=${encodeURIComponent(caseId)}" class="btn-create" style="font-size: 0.82rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: #b91c1c;">
                        Issue CFA &rarr;
                    </a>
                </div>
            </div>
        `;
    } else if (outcomeVal === 'Dismissed') {
        bannerEl.innerHTML = `
            <div style="background: #fef2f2; border: 1.5px solid #fca5a5; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="color: #991b1b; font-size: 0.85rem;">
                    <strong style="font-size: 0.92rem; display: block; color: #7f1d1d;">Dismiss Complaint / I-dismiss ang Kaso (Complainant Absent)</strong>
                    Hindi dumalo ang nagreklamo sa takdang oras ng pagdinig. Kapag pinalisa ang katitikan, ang kaso at reklamo ay mamarkahang <strong>Dismissed</strong> alinsunod sa Katarungang Pambarangay rules (RA 7160 Sec. 410 / Sec. 412).
                </div>
            </div>
        `;
    } else {
        bannerEl.innerHTML = '';
    }
}

function selectInlineMinutesSession(hearingId) {
    const targetId = Number(hearingId);
    stageWorkspaceState.activeSessionHearingId = targetId;
    stageWorkspaceState.currentHearingId = targetId;

    // 1. Update Tab Buttons styling
    const allTabBtns = document.querySelectorAll('.session-minutes-tab');
    allTabBtns.forEach(btn => {
        const btnHearingId = Number(btn.getAttribute('data-hearing-id'));
        const isActive = (btnHearingId === targetId);
        if (isActive) {
            btn.classList.add('active');
            btn.style.border = '2px solid #2563eb';
            btn.style.background = '#eff6ff';
            btn.style.boxShadow = '0 2px 5px rgba(37,99,235,0.18)';
            btn.style.transform = 'translateY(-1px)';
            const title = btn.querySelector('.tab-session-title');
            if (title) title.style.color = '#1d4ed8';
            const date = btn.querySelector('.tab-session-date');
            if (date) date.style.color = '#2563eb';
        } else {
            btn.classList.remove('active');
            btn.style.border = '1.5px solid #cbd5e1';
            btn.style.background = '#fff';
            btn.style.boxShadow = 'none';
            btn.style.transform = 'none';
            const title = btn.querySelector('.tab-session-title');
            if (title) title.style.color = '#0f172a';
            const date = btn.querySelector('.tab-session-date');
            if (date) date.style.color = '#64748b';
        }
    });

    // 2. Switch card visibility (Tabs behavior: show ONLY the selected tab session)
    const allCards = document.querySelectorAll('.inline-minutes-card');
    allCards.forEach(card => {
        if (card.id === `minutesCard_${targetId}`) {
            card.style.display = 'block';
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            card.style.display = 'none';
        }
    });

    // 3. Switch "Need an Additional Session?" prompt visibility
    const bookingBox = document.getElementById('wsNextSessionBookingBox');
    if (bookingBox) {
        const sessions = stageWorkspaceState.sessions || [];
        const lastSession = sessions[sessions.length - 1];
        if (lastSession && Number(lastSession.hearing_id) === targetId) {
            bookingBox.style.display = 'block';
        } else {
            bookingBox.style.display = 'none';
        }
    }

    // 4. Synchronize attendance section above
    if (document.getElementById('wsAttCardBody')) {
        openAttendanceModal(targetId, false);
    }
}
window.selectInlineMinutesSession = selectInlineMinutesSession;
window.openMinutesModal = selectInlineMinutesSession;
window.closeMinutesModal = function() {};

async function saveHearingMinutes(status = 'Draft', targetHearingId = null, skipConfirm = false) {
    const hearingId = targetHearingId || document.getElementById('minHearingId')?.value || stageWorkspaceState.activeSessionHearingId;
    if (!hearingId) {
        notifyUser('No hearing session selected.', 'warning');
        return;
    }

    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    if (!session) {
        notifyUser('Session record not found.', 'warning');
        return;
    }

    const outcomeEl = getMinutesField('minOutcome', hearingId);
    const outcomeVal = outcomeEl?.value;
    if (!outcomeVal) {
        notifyUser('Please select a session outcome.', 'warning');
        outcomeEl?.focus();
        return;
    }


    const draftBtn = document.getElementById(`btnSaveMinutesDraft_${hearingId}`) || document.getElementById('btnSaveMinutesDraft');
    const finalizeBtn = document.getElementById(`btnSaveMinutesFinalize_${hearingId}`) || document.getElementById('btnSaveMinutesFinalize');
    if (draftBtn) draftBtn.disabled = true;
    if (finalizeBtn) finalizeBtn.disabled = true;

    let sessionType = '1st Mediation';
    if (stageWorkspaceState.mode === 'Mediation') {
        const idx = Number(session.session_index || 1);
        sessionType = idx === 1 ? '1st Mediation' : (idx === 2 ? '2nd Mediation' : '3rd Mediation');
    } else if (stageWorkspaceState.mode === 'Conciliation') {
        const idx = Number(session.session_index || 1);
        sessionType = idx === 1 ? '1st Conciliation' : (idx === 2 ? '2nd Conciliation' : '3rd Conciliation');
    } else if (stageWorkspaceState.mode === 'Arbitration') {
        sessionType = 'Arbitration';
    }

    if (['Settled', 'Pending CFA', 'Arbitration Award'].includes(outcomeVal)) {
        unlockOutcomesTab();
    }

    const getVal = (baseId) => (document.getElementById(`${baseId}_${hearingId}`) || document.getElementById(baseId))?.value || '';
    const getChecked = (baseId) => (document.getElementById(`${baseId}_${hearingId}`) || document.getElementById(baseId))?.checked ? 1 : 0;

    const payload = {
        hearing_id: Number(hearingId),
        session_type: sessionType,
        opening_conducted: getChecked('minOpening'),
        identity_verified: getChecked('minIdentity'),
        complaint_reviewed: getChecked('minReviewed'),
        complainant_statement: '',
        respondent_statement: '',
        main_dispute_identified: getVal('minDisputeSummary'),
        agenda_topics: '',
        new_proposal: '',
        counteroffer: '',
        caucus_conducted: 0,
        caucus_notes: '',
        agreements_action_items: getVal('minAgreements'),
        unresolved_issues: getVal('minUnresolved'),
        session_outcome: outcomeVal,
        actual_end_time: getVal('minActualEndTime') || null,
        outcome_remarks: getVal('minOutcomeRemarks'),
        settlement_discussion_notes: getVal('session_minutes'),
        session_minutes: getVal('session_minutes'),
        status: 'Draft',
        force_edit: 1
    };

    const isMediation = stageWorkspaceState.mode === 'Mediation';
    const sessionIndex = Number(session.session_index || 1);
    const nextSessionIndex = sessionIndex + 1;
    const hasNextSession = stageWorkspaceState.sessions.some(s => Number(s.session_index) === nextSessionIndex);

    if (outcomeVal === 'Continue Mediation' && isMediation && !hasNextSession && sessionIndex < 3) {
        const nextDate = getVal('minNextHearingDate')?.trim();
        const nextTime = getVal('minNextHearingTime')?.trim();
        const nextVenue = getVal('minNextHearingVenue')?.trim() || 'Lupon Court Room';
        if (nextDate && nextTime) {
            payload.book_next_hearing = 1;
            payload.next_hearing_date = nextDate;
            payload.next_hearing_time = nextTime;
            payload.next_hearing_venue = nextVenue;
            payload.next_hearing_presider_id = session.presiding_officer_id || null;
        }
    }

    try {
        const res = await fetch(getStageApiUrl('hearings/minutes.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            const draftKey = `agap_draft_${stageWorkspaceState.mode}_case_${stageWorkspaceState.caseId}_hearing_${hearingId}`;
            if (window.AgapDraftManager) {
                window.AgapDraftManager.markSubmitted(draftKey);
                window.AgapDraftManager.clear(draftKey);
            }
            await loadStageData();

            // Determine target generated KP form based on session outcome and attendance
            const sessionItem = (stageWorkspaceState.sessions || []).find(s => Number(s.hearing_id) === Number(hearingId)) || session || {};
            const compAtt = sessionItem.complainant_attendance || '';
            const respAtt = sessionItem.respondent_attendance || '';

            let targetKpForm = null;
            if (outcomeVal === 'Settled' || outcomeVal === 'Amicably Settled') {
                targetKpForm = 'KP Form 16';
            } else if (outcomeVal === 'Party Absent' || outcomeVal === 'Non-Appearance') {
                if (compAtt === 'Absent' && respAtt !== 'Absent') {
                    targetKpForm = 'KP Form 18';
                } else if (respAtt === 'Absent' && compAtt !== 'Absent') {
                    targetKpForm = 'KP Form 19';
                } else {
                    targetKpForm = 'KP Form 18';
                }
            } else if (outcomeVal === 'Elevate to Pangkat' || outcomeVal === 'Failed') {
                targetKpForm = 'KP Form 10';
            } else if (outcomeVal === 'Arbitration' || outcomeVal === 'Arbitration Award') {
                targetKpForm = 'KP Form 14';
            } else if (outcomeVal === 'Dismissed') {
                targetKpForm = 'KP Form 21';
            } else if (outcomeVal === 'Rescheduled') {
                targetKpForm = (respAtt === 'Absent' || !respAtt) ? 'KP Form 9' : 'KP Form 8';
            } else if (outcomeVal === 'Pending CFA') {
                targetKpForm = 'KP Form 20';
            } else if (outcomeVal === 'Repudiated') {
                targetKpForm = 'KP Form 17';
            }

            if (targetKpForm) {
                notifyUser(`Session minutes saved successfully. Opening ${targetKpForm} printable preview...`, 'success', 'Session Minutes Saved', 2500);
                openKpFormModal(stageWorkspaceState.caseId, targetKpForm, hearingId, true);
            } else {
                if (json.next_hearing_booked && json.next_hearing) {
                    const seqLabel = json.next_hearing?.sequence === 2 ? '2nd' : (json.next_hearing?.sequence === 3 ? '3rd' : 'Next');
                    notifyUser(`Session minutes saved successfully!\n${seqLabel} Mediation hearing booked for ${json.next_hearing.hearing_date} at ${json.next_hearing.venue || 'Barangay Hall'}.`, 'success', 'Session Minutes Saved', 5000);
                } else {
                    notifyUser('Session minutes draft saved successfully.', 'success');
                }
            }
        } else {
            notifyUser(json.message || 'Unable to save session minutes.', 'error');
        }
    } catch (err) {
        console.error('saveHearingMinutes error:', err);
        notifyUser('A network error occurred while saving minutes.', 'error');
    } finally {
        if (draftBtn) draftBtn.disabled = false;
        if (finalizeBtn) finalizeBtn.disabled = false;
    }
}
window.saveHearingMinutes = saveHearingMinutes;
window.saveInlineMinutes = saveHearingMinutes;
window.saveMinutes = saveHearingMinutes;


function showCloseoutFollowUp() {
    const data = stageWorkspaceState.stageData || {};
    const stage = stageWorkspaceState.mode === 'Conciliation' ? data.conciliation : data.mediation;
    const actions = stage?.documents_and_actions || {};
    let text = 'Session minutes were finalized. Review the next required action above.';
    let action = '';
    if (stageWorkspaceState.mode === 'Mediation' && (actions.can_proceed_to_pangkat || data.case?.case_status === 'Conciliation')) {
        text = 'Session minutes were finalized and elevated to Pangkat Conciliation. Constitute Pangkat (KP Form 10) to convene proceedings.';
        action = '<button type="button" class="btn-create" onclick="openReferPangkatModal()">Constitute Pangkat &rarr;</button>';
    } else if (stageWorkspaceState.mode === 'Mediation' && actions.can_book_next_mediation) {
        text = 'Session minutes were finalized. The next required step is to book the next Mediation.';
        action = '<button type="button" class="btn-create" onclick="openScheduleModal()">Book next Mediation &rarr;</button>';
    } else if (stageWorkspaceState.mode === 'Conciliation' && actions.can_issue_cfa) {
        text = 'Session minutes were finalized. Conciliation is eligible for a Certificate to File Action.';
        action = `<a class="btn-create" href="../documents/cfa.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}">Issue CFA &rarr;</a>`;
    }
    document.getElementById('wsCloseoutFollowUp')?.remove();
    const box = document.createElement('div');
    box.id = 'wsCloseoutFollowUp';
    box.style.cssText = 'position:fixed; right:24px; bottom:24px; z-index:1000; max-width:380px; padding:16px; background:#fff; border:1px solid #86efac; border-radius:10px; box-shadow:0 12px 30px rgba(15,23,42,.18); color:#14532d;';
    box.innerHTML = `<strong style="display:block; margin-bottom:6px;">Session closeout complete</strong><span style="display:block; font-size:.86rem; line-height:1.45; margin-bottom:12px;">${escapeHtml(text)}</span><div style="display:flex; gap:8px; align-items:center;">${action}<button type="button" class="btn-secondary" onclick="this.closest('#wsCloseoutFollowUp').remove()">Done</button></div>`;
    document.body.appendChild(box);
}

/**
 * Render Outcome & Progression Section
 */
function renderOutcomeAndReferralSection(data, stage) {
    const outcomeBox = document.getElementById('wsOutcomeActionBox');
    if (!outcomeBox) return;

    const mode = stageWorkspaceState.mode;
    const outcome = stage.outcome || 'Pending';
    const canRefer = stage.documents_and_actions?.can_proceed_to_pangkat;

    if (outcome === 'Settled' || stage.outcome === 'Settled' || stage.stage_status === 'Settled') {
        outcomeBox.innerHTML = `
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; color: #166534; margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 1rem; color: #14532d; font-weight: 700;">
                            Amicable Settlement Reached (Kasunduan)
                        </h4>
                        <p style="margin: 0; font-size: 0.85rem; color: #166534;">
                            The parties reached an amicable settlement. Formal settlement terms are active and enforceable.
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openKPFormPreview(${Number(stageWorkspaceState.caseId)}, 'KP Form 16')" style="font-size: 0.82rem; padding: 6px 14px; background: #fff; border-color: #86efac; color: #166534; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            Preview / Print KP Form 16
                        </button>
                        <a href="../settlements/settlements.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}" class="btn-create" style="font-size: 0.82rem; padding: 6px 14px; text-decoration: none;">
                            View Amicable Settlement &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Court & DILG Transmittal Module (RA 7160 Sec. 411) -->
            <div id="wsTransmittalCardContainer">
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; color: #64748b; font-size: 0.85rem;">
                    Loading Transmittal &amp; Repudiation status...
                </div>
            </div>
        `;

        loadTransmittalCard(stageWorkspaceState.caseId);
    } else if (mode === 'Mediation') {
        if (stage.stage_status === 'Referred to Pangkat') {
            outcomeBox.innerHTML = `
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; color: #166534;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h4 style="margin: 0 0 4px; font-size: 1rem; color: #14532d; font-weight: 700;">
                                Case Referred to Pangkat ng Tagapagkasundo
                            </h4>
                            <p style="margin: 0; font-size: 0.84rem; color: #166534;">
                                Mediation concluded. KP Form 10 issued. Case is active in Pangkat Conciliation.
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <a href="pangkat-workspace.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}" class="btn-secondary" style="font-size: 0.82rem; padding: 7px 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                                Open Pangkat Conciliation &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            `;
        } else {
            const completedCount = Number(stage.completed_mediation_count || 0);
            const canBookNext = Boolean(stage.documents_and_actions?.can_book_next_mediation);
            const nextSessionIndex = Math.min(3, completedCount + 1);
            const pangkatLockReason = stage.documents_and_actions?.pangkat_lock_reason || 'Barangay Tumana policy requires 2 mediation sessions before elevation.';

            outcomeBox.innerHTML = `
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <h4 style="margin: 0 0 4px; font-size: 0.95rem; color: #0f172a; font-weight: 700;">
                                Mediation Stage Actions &amp; Progression
                            </h4>
                            <p style="margin: 0; font-size: 0.82rem; color: #64748b;">
                                Progress: <strong>${escapeHtml(completedCount)} of 2 Mediation Sessions Completed</strong> · Current Outcome: <strong>${escapeHtml(outcome)}</strong>
                            </p>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            ${canBookNext ? `
                                <button type="button" class="btn-create" onclick="openScheduleModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #2563eb; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                    Book 2nd Mediation Hearing &rarr;
                                </button>
                            ` : ''}
                            <button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()" style="font-size: 0.82rem; padding: 6px 12px; cursor: pointer;">
                                Record Mediation Outcome &rarr;
                            </button>
                            ${canRefer ? `
                                <button type="button" class="btn-create" onclick="openReferPangkatModal()" style="font-size: 0.82rem; padding: 6px 14px; background: #0369a1; cursor: pointer;">
                                    Refer to Pangkat (KP Form 10) &rarr;
                                </button>
                            ` : `
                                <button type="button" class="btn-secondary" disabled title="${escapeHtml(pangkatLockReason)}" style="font-size: 0.82rem; padding: 6px 12px; opacity: 0.55; cursor: not-allowed;">
                                    Refer to Pangkat
                                </button>
                            `}
                            <a href="../cases/case-list.php?assign_case_id=${encodeURIComponent(stageWorkspaceState.caseId)}#caseAssignments" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                Cases &amp; Assignments &rarr;
                            </a>
                        </div>
                    </div>
                    ${!canRefer && pangkatLockReason ? `
                        <div style="font-size: 0.77rem; color: #64748b; margin-top: 10px; border-top: 1px dashed #e2e8f0; padding-top: 8px;">
                            <strong>Why Pangkat referral is locked:</strong> ${escapeHtml(pangkatLockReason)}
                        </div>
                    ` : ''}
                </div>
            `;
        }
    } else if (mode === 'Conciliation') {
        const canCfa = stage.documents_and_actions?.can_issue_cfa;
        outcomeBox.innerHTML = `
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 0.95rem; color: #0f172a; font-weight: 700;">
                            Conciliation Outcome &amp; Case Disposition
                        </h4>
                        <p style="margin: 0; font-size: 0.82rem; color: #64748b;">
                            Current Outcome: <strong>${escapeHtml(outcome)}</strong>. Record conciliation result or initiate Certificate to File Action (CFA).
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()" style="font-size: 0.82rem; padding: 6px 12px;">
                            Record Outcome &rarr;
                        </button>
                        <a href="../documents/cfa.php?case_id=${encodeURIComponent(stageWorkspaceState.caseId)}" class="btn-secondary" style="font-size: 0.82rem; padding: 6px 12px; text-decoration: none; ${!canCfa ? 'opacity: 0.5; pointer-events: none;' : ''}">
                            Issue CFA (KP Form 20) &rarr;
                        </a>
                    </div>
                </div>
            </div>
        `;
    } else if (mode === 'Arbitration') {
        outcomeBox.innerHTML = `
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h4 style="margin: 0 0 4px; font-size: 0.95rem; color: #0f172a; font-weight: 700;">
                            Arbitration Outcome &amp; Binding Award
                        </h4>
                        <p style="margin: 0; font-size: 0.82rem; color: #64748b;">
                            Current Outcome: <strong>${escapeHtml(outcome)}</strong>. Record binding arbitration determination or render arbitration award.
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="openRecordOutcomeModal()" style="font-size: 0.82rem; padding: 6px 12px;">
                            Record Outcome &rarr;
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Schedule Session Modal
 * -----------------------------------------------------------------------------
 */
let currentWsWindowMeta = null;
let wsConflictCheckDebounceTimer = null;

async function openScheduleModal(hearingId = null, targetStageType = null) {
    const modal = document.getElementById('wsScheduleModal');
    if (!modal) {
        const urlParams = new URLSearchParams(window.location.search);
        const caseId = stageWorkspaceState.caseId || urlParams.get('case_id');
        const hId = hearingId || stageWorkspaceState.activeSessionHearingId || urlParams.get('hearing_id');
        let url = `../hearings/schedules.php?case_id=${encodeURIComponent(caseId || '')}`;
        if (hId) url += `&hearing_id=${encodeURIComponent(hId)}`;
        window.location.href = url;
        return;
    }

    modal.style.display = 'flex';

    if (!hearingId && targetStageType !== 'Arbitration' && (stageWorkspaceState.mode === 'Mediation' || stageWorkspaceState.stageType === 'Mediation')) {
        const existing = stageWorkspaceState.sessions || [];
        if (existing.length >= 1) {
            const s1 = existing[0];
            const s1Status = s1.status || '';
            const s1Queue = s1.queue_status || '';
            const s1Outcome = (s1.session_outcome || s1.outcome || '').toLowerCase();
            const liveOutcome = (document.getElementById('minOutcome')?.value || '').toLowerCase();
            const isSettled = s1Outcome.includes('settle') || liveOutcome.includes('settle');
            if (isSettled) {
                const msg = 'Cannot schedule 2nd Mediation: 1st Mediation concluded with an Amicable Settlement.';
                if (typeof notifyUser === 'function') notifyUser(msg, 'error');
                else alert(msg);
                modal.style.display = 'none';
                return;
            }

            const alreadyHas2nd = existing.some(s => Number(s.session_index || 1) >= 2 || (s.hearing_type || '').includes('2nd') || Number(s.mediation_attempt) >= 2);
            if (alreadyHas2nd) {
                const msg = '2nd Mediation hearing is already scheduled for this case.';
                if (typeof notifyUser === 'function') notifyUser(msg, 'info');
                else alert(msg);
                modal.style.display = 'none';
                return;
            }

            if (existing.length >= 3) {
                const msg = 'Mediation is limited to a maximum of 3 sessions under Katarungang Pambarangay guidelines.';
                if (typeof notifyUser === 'function') notifyUser(msg, 'error');
                else alert(msg);
                modal.style.display = 'none';
                return;
            }
        }
    }

    if (!hearingId && targetStageType !== 'Arbitration' && (stageWorkspaceState.mode === 'Conciliation' || stageWorkspaceState.stageType === 'Conciliation')) {
        const existing = stageWorkspaceState.sessions || [];
        if (existing.length >= 1) {
            const curCount = existing.length;
            const nextIndex = curCount + 1;
            const nextOrdinal = nextIndex === 2 ? '2nd' : (nextIndex === 3 ? '3rd' : `${nextIndex}th`);
            const curOrdinal = curCount === 1 ? '1st' : (curCount === 2 ? '2nd' : `${curCount}th`);

            const isSettled = existing.some(s => (s.session_outcome || s.outcome || '').toLowerCase().includes('settle')) ||
                              (document.getElementById('minOutcome')?.value || '').toLowerCase().includes('settle');
            if (isSettled) {
                const msg = `Cannot schedule ${nextOrdinal} Conciliation: Conciliation concluded with an Amicable Settlement.`;
                if (typeof notifyUser === 'function') notifyUser(msg, 'error');
                else alert(msg);
                modal.style.display = 'none';
                return;
            }

            const isArbitration = existing.some(s => (s.session_outcome || s.outcome || '').toLowerCase().includes('arbitrat')) ||
                                  (document.getElementById('minOutcome')?.value || '').toLowerCase().includes('arbitrat');
            if (isArbitration) {
                const msg = `Cannot schedule ${nextOrdinal} Conciliation: The dispute has been submitted to Arbitration.`;
                if (typeof notifyUser === 'function') notifyUser(msg, 'error');
                else alert(msg);
                modal.style.display = 'none';
                return;
            }

            if (existing.length >= 3) {
                const msg = 'Conciliation is limited to a maximum of 3 sessions under Katarungang Pambarangay guidelines.';
                if (typeof notifyUser === 'function') notifyUser(msg, 'error');
                else alert(msg);
                modal.style.display = 'none';
                return;
            }

            const lastSession = existing[existing.length - 1];
            const alreadyHasNext = existing.some(s => Number(s.session_index || 1) === nextIndex || (s.hearing_type || '').includes(nextOrdinal));
            if (alreadyHasNext) {
                const msg = `${nextOrdinal} Conciliation hearing is already scheduled for this case.`;
                if (typeof notifyUser === 'function') notifyUser(msg, 'info');
                else alert(msg);
                modal.style.display = 'none';
                return;
            }

            const lastDate = new Date(lastSession.hearing_date);
            const isFuture = !isNaN(lastDate.getTime()) && lastDate.getTime() > Date.now();
            const lastOutcome = (lastSession.session_outcome || '').toLowerCase();
            const liveOutcome = (document.getElementById('minOutcome')?.value || '').toLowerCase();
            const isPendingOutcome = (!lastOutcome || lastOutcome === 'pending') && (!liveOutcome || liveOutcome === 'pending');
            const hasNoMinutes = !lastSession.minutes_status || lastSession.minutes_status === 'Not Recorded';

            if (isFuture || (isPendingOutcome && hasNoMinutes)) {
                const msg = `Cannot schedule ${nextOrdinal} Conciliation: The ${curOrdinal} Conciliation session is currently ongoing. Please conduct the ${curOrdinal} session and record its outcome/minutes before booking the ${nextOrdinal} session.`;
                if (typeof notifyUser === 'function') notifyUser(msg, 'warning', `${curOrdinal} Conciliation Ongoing`);
                else alert(msg);
                modal.style.display = 'none';
                return;
            }
        }
    }

    // Title / Subtitle customization
    const titleEl = document.getElementById('wsSchedModalTitle');
    const subtitleEl = document.getElementById('wsSchedModalSubtitle');
    const dateLabelEl = document.getElementById('schedDateLabel');
    const timeLabelEl = document.getElementById('schedTimeLabel');
    const venueLabelEl = document.getElementById('schedVenueLabel');
    const remarksLabelEl = document.getElementById('schedRemarksLabel');
    const presiderGroupEl = document.getElementById('schedPresidingOfficerGroup');
    const presiderLabelEl = document.getElementById('schedPresidingOfficerLabel');
    const presiderInputEl = document.getElementById('schedPresidingOfficer');
    const conflictAlert = document.getElementById('schedScheduleConflictAlert');
    const submitBtn = document.getElementById('submitSchedBtn');
    const dateInput = document.getElementById('schedDate');
    const timeInput = document.getElementById('schedTime');

    if (conflictAlert) {
        conflictAlert.textContent = '';
        conflictAlert.style.display = 'none';
    }
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.title = '';
    }

    const isConciliation = (stageWorkspaceState.mode === 'Conciliation');
    if (targetStageType === 'Arbitration' || stageWorkspaceState.mode === 'Arbitration') {
        if (titleEl) titleEl.textContent = 'Schedule Arbitration Session';
        if (dateLabelEl) dateLabelEl.textContent = 'Petsa ng Pagdinig (Hearing Date)';
        if (timeLabelEl) timeLabelEl.textContent = 'Oras ng Pagdinig (Hearing Time)';
        if (subtitleEl) {
            subtitleEl.innerHTML = 'Enter date and time for voluntary binding arbitration session under <strong>RA 7160 Sec. 413</strong>.';
        }
        const typeInput = document.getElementById('schedHearingType');
        if (typeInput) typeInput.value = 'Arbitration';
        const venueInput = document.getElementById('schedVenue');
        if (venueInput && !venueInput.value) venueInput.value = 'Lupon Court Room';
        if (venueLabelEl) venueLabelEl.textContent = 'Lugar / Venue';
        if (remarksLabelEl) remarksLabelEl.textContent = 'Hearing Notes';
        if (presiderGroupEl) presiderGroupEl.style.display = 'block';
        if (presiderLabelEl) presiderLabelEl.textContent = 'Presiding Arbitrator (Punong Barangay / Pangkat Chairman)';
        if (presiderInputEl) {
            const panelChair = stageWorkspaceState.stageData?.conciliation?.pangkat_assignment?.chairperson?.member_name;
            presiderInputEl.value = panelChair || stageWorkspaceState.stageData?.arbitration?.presiding_officer || 'Punong Barangay / Pangkat Chairman';
        }
        if (submitBtn) submitBtn.textContent = 'Schedule Arbitration Session';
    } else if (stageWorkspaceState.mode === 'Mediation') {
        if (presiderGroupEl) presiderGroupEl.style.display = 'none';
        const activeHearingId = stageWorkspaceState.activeSessionHearingId;
        const activeSession = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(activeHearingId)) || {};
        const curSeq = Number(activeSession.session_index || 1);
        const nextSeq = curSeq + 1;
        const nextSeqOrdinal = nextSeq === 2 ? '2nd' : (nextSeq === 3 ? '3rd' : `${nextSeq}th`);

        if (titleEl) titleEl.textContent = `Schedule ${nextSeqOrdinal} Mediation Session`;
        if (dateLabelEl) dateLabelEl.textContent = `${nextSeqOrdinal} Mediation Date`;
        if (timeLabelEl) timeLabelEl.textContent = `${nextSeqOrdinal} Mediation Time`;
        if (subtitleEl) {
            subtitleEl.innerHTML = `Enter the date and time to schedule the ${nextSeqOrdinal} mediation session immediately. <strong>RA 7160 Sec. 410 Notice Exemption:</strong> Because both parties are present in this session and notified, prior personal notice is legally recognized and no redundant summons (KP Form 9) is generated.`;
        }
        const deckTitle = document.getElementById('schedVisualDeckTitle');
        if (deckTitle) deckTitle.textContent = 'AVAILABLE MEDIATION DAYS (MAX 8 HEARINGS/DAY):';
        const schedDayTitle = document.getElementById('schedDaySchedulesTitle');
        if (schedDayTitle) schedDayTitle.textContent = 'Scheduled Mediations on this Date:';
        if (submitBtn) submitBtn.textContent = `Schedule ${nextSeqOrdinal} Mediation`;
    } else if (isConciliation) {
        if (presiderGroupEl) presiderGroupEl.style.display = 'none';
        const conciliationCount = stageWorkspaceState.sessions ? stageWorkspaceState.sessions.length : 0;
        const nextSeq = conciliationCount + 1;
        const nextSeqOrdinal = nextSeq === 1 ? '1st' : (nextSeq === 2 ? '2nd' : (nextSeq === 3 ? '3rd' : `${nextSeq}th`));

        if (titleEl) titleEl.textContent = `Schedule ${nextSeqOrdinal} Conciliation Session`;
        if (dateLabelEl) dateLabelEl.textContent = `${nextSeqOrdinal} Conciliation Date`;
        if (timeLabelEl) timeLabelEl.textContent = `${nextSeqOrdinal} Conciliation Time`;
        if (subtitleEl) {
            subtitleEl.innerHTML = `In accordance with KP rules, scheduling a conciliation hearing registers the session in the central calendar and generates <strong>KP Form 11 (Notice of Hearing before the Pangkat)</strong>.`;
        }
        const deckTitle = document.getElementById('schedVisualDeckTitle');
        if (deckTitle) deckTitle.textContent = 'AVAILABLE CONCILIATION DAYS (MON, TUE AM, WED, THU, FRI):';
        const schedDayTitle = document.getElementById('schedDaySchedulesTitle');
        if (schedDayTitle) schedDayTitle.textContent = 'Scheduled Conciliations on this Date:';
        const timeHint = document.getElementById('schedTimeHint');
        if (timeHint) {
            timeHint.textContent = 'Regular Conciliation hours: Mon/Wed/Thu/Fri 8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM; Tuesday 8:00 AM – 12:00 PM (Morning only). Standard duration is 1 hour.';
        }
        if (submitBtn) submitBtn.textContent = `Schedule ${nextSeqOrdinal} Conciliation`;
    }

    // Set up listeners for date/time
    if (dateInput) {
        dateInput.onchange = () => {
            if (currentWsWindowMeta) {
                renderWorkspaceVisualDatePicker(currentWsWindowMeta, dateInput.value);
            }
            checkWorkspaceScheduleConflict();
        };
    }
    if (timeInput) {
        timeInput.oninput = debouncedCheckWorkspaceScheduleConflict;
        timeInput.onchange = checkWorkspaceScheduleConflict;
    }

    // Load statutory window and available slots
    const caseId = stageWorkspaceState.caseId;
    const complaintId = document.getElementById('schedComplaintId')?.value || '';
    try {
        const hearingTypeParam = (stageWorkspaceState.mode === 'Conciliation') ? 'Conciliation' : 'Mediation';
        const res = await fetch(getStageApiUrl(`summons/check-schedule.php?case_id=${encodeURIComponent(caseId)}&complaint_id=${encodeURIComponent(complaintId)}&hearing_type=${encodeURIComponent(hearingTypeParam)}&get_window=1`));
        const json = await res.json();
        if (json.window) {
            currentWsWindowMeta = json.window;
            let targetDate = dateInput ? dateInput.value : '';
            if (!targetDate && json.window.daily_slots) {
                // Pick first valid open working day
                const firstOpen = Object.values(json.window.daily_slots).find(d => !d.is_past && !d.is_outside_window && !d.is_fully_booked && !d.is_weekend && !d.is_non_working_day);
                if (firstOpen) {
                    targetDate = firstOpen.date;
                    if (dateInput) dateInput.value = targetDate;
                }
            }
            renderWorkspaceVisualDatePicker(json.window, targetDate);
            if (targetDate) {
                checkWorkspaceScheduleConflict();
            }
        } else {
            const gridEl = document.getElementById('schedVisualDayGrid');
            if (gridEl) {
                gridEl.innerHTML = `<div style="color: #dc2626; font-size: 0.78rem; grid-column: 1 / -1; text-align: center; padding: 10px;">${escapeHtml(json.message || 'Unable to load scheduling window.')}</div>`;
            }
        }
    } catch (e) {
        console.warn('Failed to load window metadata for workspace scheduler:', e);
        const gridEl = document.getElementById('schedVisualDayGrid');
        if (gridEl) {
            gridEl.innerHTML = `<div style="color: #dc2626; font-size: 0.78rem; grid-column: 1 / -1; text-align: center; padding: 10px;">Could not connect to schedule server. Please check connection.</div>`;
        }
    }
}
window.openScheduleModal = openScheduleModal;

function closeScheduleModal() {
    const modal = document.getElementById('wsScheduleModal');
    if (modal) modal.style.display = 'none';
}
window.closeScheduleModal = closeScheduleModal;

function renderWorkspaceVisualDatePicker(windowMeta, selectedDate) {
    const gridEl = document.getElementById('schedVisualDayGrid');
    const noticeEl = document.getElementById('schedVisualDateStatusNotice');
    const badgeEl = document.getElementById('schedWindowStatutoryBadge');
    if (!gridEl || !windowMeta || !windowMeta.daily_slots) return;

    if (badgeEl) { badgeEl.style.display = 'none'; }

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

        const isConciliationMode = (stageWorkspaceState.mode === 'Conciliation' || windowMeta.hearing_type === 'Conciliation');
        if (d.is_past) {
            badgeText = 'Past';
            badgeBg = '#f1f5f9';
            badgeColor = '#94a3b8';
            cardStyle = 'border: 1px solid #e2e8f0; background: #f8fafc; color: #94a3b8; cursor: not-allowed; opacity: 0.5;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Past date cannot be scheduled.`;
        } else if (d.is_weekend || d.is_non_working_day) {
            badgeText = d.is_non_working_day ? 'Holiday' : 'Weekend';
            badgeBg = '#f1f5f9';
            badgeColor = '#64748b';
            cardStyle = 'border: 1px dashed #cbd5e1; background: #f8fafc; color: #64748b; cursor: not-allowed; opacity: 0.55;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: ${d.is_non_working_day ? 'Official holiday' : 'Weekend'}. Closed for ${isConciliationMode ? 'conciliation' : 'mediation'}.`;
        } else if (d.is_fully_booked) {
            badgeText = `${d.booked}/${d.max} FULL`;
            badgeBg = '#fee2e2';
            badgeColor = '#b91c1c';
            cardStyle = 'border: 1px solid #fecaca; background: #fff1f2; color: #881337; cursor: not-allowed; opacity: 0.65;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Fully booked! All ${d.max} sessions occupied for this date.`;
        } else if (isConciliationMode && (d.day_name === 'Tue' || d.is_tuesday_morning_only)) {
            badgeText = `${d.available} AM`;
            badgeBg = '#e0f2fe';
            badgeColor = '#0369a1';
            cardStyle = 'border: 1px solid #7dd3fc; background: #f0f9ff; color: #0c4a6e; cursor: pointer;';
            tooltip = `${d.date}: Tuesday Morning Only (8:00 AM – 12:00 PM, ${d.available} of 4 slots open). Tuesday afternoons reserved for Formal Mediation.`;
        }

        if (isSelected) {
            cardStyle = 'border: 2px solid #2563eb; background: #eff6ff; color: #1e3a8a; box-shadow: 0 0 0 2px rgba(37,99,235,0.25); font-weight: 700; cursor: pointer;';
            badgeBg = '#2563eb';
            badgeColor = '#ffffff';
        }

        return `
            <div class="ws-day-card ${isSelected ? 'active' : ''}"
                 onclick="${disabledAttr ? '' : `selectWorkspaceVisualDate('${d.date}')`}"
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
        if (selectedDate && windowMeta.daily_slots[selectedDate]) {
            const selectedDayData = windowMeta.daily_slots[selectedDate];
            if (selectedDayData.is_fully_booked) {
                noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">Selected date is fully booked (8 of 8 daily mediation sessions).</span>`;
            } else {
                noticeEl.innerHTML = `<span style="color: #15803d; font-weight: 600;">Date selected: ${selectedDayData.available} slots remaining.</span>`;
            }
        } else {
            noticeEl.textContent = 'Click any available day card above to select. Fully booked dates are disabled.';
        }
    }
}
window.renderWorkspaceVisualDatePicker = renderWorkspaceVisualDatePicker;

function selectWorkspaceVisualDate(dateStr) {
    const dateInput = document.getElementById('schedDate');
    if (!dateInput) return;
    dateInput.value = dateStr;

    // Apply Tuesday morning time restrictions if in Conciliation mode
    const isConciliationMode = (stageWorkspaceState.mode === 'Conciliation' || currentWsWindowMeta?.hearing_type === 'Conciliation');
    const timeInput = document.getElementById('schedTime');
    const timeHint = document.getElementById('schedTimeHint');
    if (isConciliationMode && dateStr) {
        const dObj = new Date(dateStr + 'T00:00:00');
        const isTue = (dObj.getDay() === 2);
        if (isTue) {
            if (timeInput) {
                timeInput.min = '08:00';
                timeInput.max = '11:30';
                if (timeInput.value >= '12:00') {
                    timeInput.value = '09:00';
                }
            }
            if (timeHint) {
                timeHint.innerHTML = '<span style="color: #0369a1; font-weight: 600;">ℹ️ Tuesday Conciliation is held in the MORNING ONLY (8:00 AM – 12:00 PM). Tuesday afternoons are exclusively reserved for Formal Mediation.</span>';
            }
        } else {
            if (timeInput) {
                timeInput.min = '08:00';
                timeInput.max = '16:30';
            }
            if (timeHint) {
                timeHint.textContent = 'Regular Conciliation hours: Mon/Wed/Thu/Fri 8:00 AM – 12:00 PM & 1:00 PM – 5:00 PM (12–1 PM lunch break closed). Standard duration is 1 hour.';
            }
        }
    }

    if (currentWsWindowMeta) {
        renderWorkspaceVisualDatePicker(currentWsWindowMeta, dateStr);
    }
    checkWorkspaceScheduleConflict();
}
window.selectWorkspaceVisualDate = selectWorkspaceVisualDate;

async function checkWorkspaceScheduleConflict() {
    const dateInput = document.getElementById('schedDate');
    const timeInput = document.getElementById('schedTime');
    const venueInput = document.getElementById('schedVenue');
    const caseIdInput = document.getElementById('schedCaseId');
    const conflictAlert = document.getElementById('schedScheduleConflictAlert');
    const submitBtn = document.getElementById('submitSchedBtn');
    const schedulesWrap = document.getElementById('schedDaySchedulesWrap');
    const slotsCount = document.getElementById('schedDaySlotsCount');
    const slotsList = document.getElementById('schedDaySlotsList');

    if (!dateInput) return;

    const dateVal = dateInput.value;
    const timeVal = timeInput ? timeInput.value : '';
    const venueVal = venueInput ? venueInput.value : 'Lupon Court Room';
    const caseIdVal = caseIdInput ? caseIdInput.value : (stageWorkspaceState.caseId || '');

    if (!dateVal) {
        if (schedulesWrap) schedulesWrap.style.display = 'none';
        return;
    }

    const typeVal = document.getElementById('schedHearingType')?.value || (stageWorkspaceState.mode === 'Conciliation' ? 'Conciliation' : 'Mediation');
    const params = new URLSearchParams();
    params.set('date', dateVal);
    if (timeVal) params.set('time', timeVal);
    if (venueVal) params.set('venue', venueVal);
    if (caseIdVal) params.set('case_id', caseIdVal);
    params.set('hearing_type', typeVal);
    params.set('get_window', '1');

    try {
        const response = await fetch(getStageApiUrl(`summons/check-schedule.php?${params.toString()}`));
        const result = await response.json();

        if (result.window) {
            currentWsWindowMeta = result.window;
            renderWorkspaceVisualDatePicker(result.window, dateVal);
        }

        if (!response.ok || !result.success) {
            if (conflictAlert && result.message) {
                conflictAlert.textContent = `Notice: ${result.message}`;
                conflictAlert.style.display = 'block';
                if (submitBtn) submitBtn.disabled = true;
            }
            return;
        }

        // 15-working-day statutory window check removed

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
        console.warn('Failed to check workspace mediation schedule:', err);
    }
}
window.checkWorkspaceScheduleConflict = checkWorkspaceScheduleConflict;

function debouncedCheckWorkspaceScheduleConflict() {
    clearTimeout(wsConflictCheckDebounceTimer);
    wsConflictCheckDebounceTimer = setTimeout(checkWorkspaceScheduleConflict, 200);
}
window.debouncedCheckWorkspaceScheduleConflict = debouncedCheckWorkspaceScheduleConflict;

async function submitSchedule(e) {
    e.preventDefault();
    const btn = document.getElementById('submitSchedBtn');
    if (btn) btn.disabled = true;

    const dateVal = document.getElementById('schedDate').value;
    const timeVal = document.getElementById('schedTime').value;
    const venueVal = document.getElementById('schedVenue').value;
    const remarksVal = document.getElementById('schedRemarks').value;
    const pastDateExceptionReason = document.getElementById('schedPastDateExceptionReason')?.value.trim() || '';
    const typeVal = document.getElementById('schedHearingType').value || (stageWorkspaceState.mode === 'Mediation' ? 'Mediation' : 'Conciliation');

    if (!dateVal || !timeVal) {
        notifyUser('Please specify the hearing date and select an available time slot.', 'warning');
        if (btn) btn.disabled = false;
        return;
    }

    const d = new Date(dateVal + 'T' + timeVal + ':00');
    const day = d.getDay();
    const hour = d.getHours();

    if (typeVal === 'Mediation') {
        if (day === 0 || day === 6) {
            notifyUser('Mediation hearings can only be scheduled on working days (Monday to Friday).', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
        if ((hour < 8 || hour >= 17) || (hour >= 12 && hour < 13)) {
            notifyUser('Mediation hearings must be during regular office hours (8:00 AM – 5:00 PM, excluding 12–1 PM lunch break).', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
    } else if (typeVal === 'Conciliation') {
        const existing = stageWorkspaceState.sessions || [];
        if (existing.length >= 1) {
            const curCount = existing.length;
            const nextIndex = curCount + 1;
            const nextOrdinal = nextIndex === 2 ? '2nd' : (nextIndex === 3 ? '3rd' : `${nextIndex}th`);
            const curOrdinal = curCount === 1 ? '1st' : (curCount === 2 ? '2nd' : `${curCount}th`);

            const isSettled = existing.some(s => (s.session_outcome || s.outcome || '').toLowerCase().includes('settle')) ||
                              (document.getElementById('minOutcome')?.value || '').toLowerCase().includes('settle');
            if (isSettled) {
                notifyUser(`Cannot schedule ${nextOrdinal} Conciliation: Conciliation concluded with an Amicable Settlement.`, 'error');
                if (btn) btn.disabled = false;
                return;
            }
            const isArbitration = existing.some(s => (s.session_outcome || s.outcome || '').toLowerCase().includes('arbitrat')) ||
                                  (document.getElementById('minOutcome')?.value || '').toLowerCase().includes('arbitrat');
            if (isArbitration) {
                notifyUser(`Cannot schedule ${nextOrdinal} Conciliation: The dispute has been submitted to Arbitration.`, 'error');
                if (btn) btn.disabled = false;
                return;
            }

            if (existing.length >= 3) {
                notifyUser('Conciliation is limited to a maximum of 3 sessions under Katarungang Pambarangay guidelines.', 'error');
                if (btn) btn.disabled = false;
                return;
            }

            const lastSession = existing[existing.length - 1];
            const alreadyHasNext = existing.some(s => Number(s.session_index || 1) === nextIndex || (s.hearing_type || '').includes(nextOrdinal));
            if (alreadyHasNext) {
                notifyUser(`${nextOrdinal} Conciliation hearing is already scheduled for this case.`, 'info');
                if (btn) btn.disabled = false;
                return;
            }

            const lastDate = new Date(lastSession.hearing_date);
            const isFuture = !isNaN(lastDate.getTime()) && lastDate.getTime() > Date.now();
            const lastOutcome = (lastSession.session_outcome || '').toLowerCase();
            const liveOutcome = (document.getElementById('minOutcome')?.value || '').toLowerCase();
            const isPendingOutcome = (!lastOutcome || lastOutcome === 'pending') && (!liveOutcome || liveOutcome === 'pending');
            const hasNoMinutes = !lastSession.minutes_status || lastSession.minutes_status === 'Not Recorded';

            if (isFuture || (isPendingOutcome && hasNoMinutes)) {
                notifyUser(`Cannot schedule ${nextOrdinal} Conciliation: The ${curOrdinal} Conciliation session is currently ongoing. Please conduct the ${curOrdinal} session and record its outcome/minutes before booking the ${nextOrdinal} session.`, 'warning');
                if (btn) btn.disabled = false;
                return;
            }
        }

        if (day === 0 || day === 6) {
            notifyUser('Conciliation hearings cannot be scheduled on weekends.', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
        if (day === 2 && hour >= 12) {
            notifyUser('Conciliation on Tuesdays is permitted only in the morning (8:00 AM – 12:00 PM). Tuesday afternoons are reserved for Formal Mediation.', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
        if ((hour < 8 || hour >= 17) || (hour >= 12 && hour < 13)) {
            notifyUser('Conciliation hearings must be during regular office hours (excluding 12–1 PM lunch break).', 'warning');
            if (btn) btn.disabled = false;
            return;
        }
    }

    const payload = new FormData();
    payload.append('case_id', stageWorkspaceState.caseId);
    payload.append('hearing_type', typeVal);
    payload.append('hearing_date', `${dateVal} ${timeVal}:00`);
    payload.append('duration_minutes', '60');
    payload.append('venue', venueVal || 'Lupon Court Room');
    payload.append('remarks', remarksVal);
    payload.append('schedule_reviewed', '1');
    if (pastDateExceptionReason) {
        payload.append('past_date_exception_reason', pastDateExceptionReason);
    }

    try {
        const res = await fetch(getStageApiUrl('hearings/create.php'), {
            method: 'POST',
            body: payload
        });
        const json = await res.json();
        if (json.success) {
            closeScheduleModal();
            await loadStageData();
            if (typeVal === 'Arbitration') unlockOutcomesTab(stageWorkspaceState.caseId);
            const schedSeq = Number(json.sequence || 0);
            if (typeof openSummonsPreviewModal === 'function' && stageWorkspaceState.mode === 'Mediation' && schedSeq <= 1) {
                openSummonsPreviewModal(stageWorkspaceState.caseId, null, json.hearing_id, true);
            } else {
                notifyUser(json.message || 'Hearing scheduled successfully! Subsequent mediation booked without re-issuing summons.', 'success');
            }
        } else if (json.requires_convening_justification) {
            const justification = prompt(
                `${json.message}\n\nPlease enter the administrative justification for scheduling past the statutory 3-day convening deadline (e.g., notice lead time, parties requested schedule, non-working days):`
            );
            if (justification && justification.trim()) {
                payload.append('convening_delay_justification', justification.trim());
                payload.append('force_schedule', '1');
                const retryRes = await fetch(getStageApiUrl('hearings/create.php'), {
                    method: 'POST',
                    body: payload
                });
                const retryJson = await retryRes.json();
                if (retryJson.success) {
                    closeScheduleModal();
                    await loadStageData();
                    const retrySeq = Number(retryJson.sequence || 0);
                    if (typeof openSummonsPreviewModal === 'function' && stageWorkspaceState.mode === 'Mediation' && retrySeq <= 1) {
                        openSummonsPreviewModal(stageWorkspaceState.caseId, null, retryJson.hearing_id, true);
                    } else {
                        notifyUser(retryJson.message || 'Hearing scheduled successfully with recorded administrative justification.', 'success');
                    }
                    return;
                } else {
                    notifyUser(retryJson.message || 'Unable to schedule hearing.', 'error');
                }
            }
        } else if (json.alternative_slots && json.alternative_slots.length) {
            let altMsg = `${json.message}\n\nSuggested Available Alternative Slots:\n`;
            json.alternative_slots.forEach((slot, idx) => {
                altMsg += `${idx + 1}. ${slot.formatted_date} (${slot.start_time} - ${slot.end_time})\n`;
            });
            altMsg += `\nWould you like to schedule slot #1 (${json.alternative_slots[0].formatted_date} ${json.alternative_slots[0].start_time})?`;
            if (confirm(altMsg)) {
                const chosen = json.alternative_slots[0];
                const dt = chosen.datetime.split(' ');
                document.getElementById('schedDate').value = dt[0];
                document.getElementById('schedTime').value = dt[1].substring(0, 5);
                submitSchedule(e);
                return;
            }
        } else if (json.message && (json.message.includes('Maximum 2 mediation attempts') || json.message.includes('Two mediation attempts') || json.message.includes('more than 2 mediation') || json.message.includes('Maximum 3 mediation attempts') || json.message.includes('more than 3 mediation'))) {
            const excReason = prompt(`${json.message}\n\nIf an authorized rule or exception explicitly permits an additional mediation session, please enter the exception justification:`);
            if (excReason && excReason.trim()) {
                payload.append('authorized_exception', '1');
                payload.append('attempt_exception_reason', excReason.trim());
                const retryRes = await fetch(getStageApiUrl('hearings/create.php'), {
                    method: 'POST',
                    body: payload
                });
                const retryJson = await retryRes.json();
                if (retryJson.success) {
                    closeScheduleModal();
                    await loadStageData();
                    notifyUser(retryJson.message || 'Hearing scheduled successfully with recorded exception.', 'success');
                    return;
                } else {
                    notifyUser(retryJson.message || 'Unable to schedule hearing.', 'error');
                }
            }
        } else {
            notifyUser(json.message || 'Unable to schedule hearing session.', 'error');
        }
    } catch (err) {
        console.error('submitSchedule error:', err);
        notifyUser('A network error occurred while scheduling hearing.', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Attendance Recording & Proof of Service Verification
 * -----------------------------------------------------------------------------
 */
async function openAttendanceModal(hearingId, shouldScroll = true) {
    const cardBody = document.getElementById('wsAttCardBody');
    const modal = document.getElementById('wsAttendanceModal');
    if (!cardBody && !modal) return;
    stageWorkspaceState.currentHearingId = Number(hearingId);

    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));

    if (cardBody) {
        const titleEl = document.getElementById('wsAttCardTitle');
        const subTitleEl = document.getElementById('wsAttCardSubtitle');
        if (titleEl) {
            titleEl.textContent = 'Hearing Session Attendance';
        }
        if (subTitleEl && session) {
            subTitleEl.textContent = `Scheduled: ${formatDateTimeReadable(session.hearing_date)} · Venue: ${escapeHtml(session.venue || 'Barangay Hall')}`;
        }
        cardBody.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;">Loading parties and service records...</div>';
        if (shouldScroll) {
            document.getElementById('attendanceSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    } else if (modal) {
        const titleEl = document.getElementById('wsAttModalTitle');
        if (titleEl) {
            titleEl.textContent = `Record Attendance: ${session ? session.session_number : `Hearing #${hearingId}`}`;
        }
        const bodyEl = document.getElementById('wsAttModalBody');
        if (bodyEl) {
            bodyEl.innerHTML = '<div style="text-align: center; padding: 20px;">Loading parties and service records...</div>';
        }
        modal.style.display = 'flex';
    }

    try {
        const res = await fetch(getStageApiUrl(`hearings/attendance.php?hearing_id=${encodeURIComponent(hearingId)}`));
        const json = await res.json();
        const targetBody = cardBody || document.getElementById('wsAttModalBody');
        if (!json.success || !json.data) {
            if (targetBody) {
                targetBody.innerHTML = `<div class="alert alert-danger" style="margin: 10px 0;">${escapeHtml(json.message || 'Could not load attendance details.')}</div>`;
            }
            return;
        }

        renderAttendanceModalForm(json.data);
    } catch (err) {
        console.error('openAttendanceModal error:', err);
        const targetBody = cardBody || document.getElementById('wsAttModalBody');
        if (targetBody) {
            targetBody.innerHTML = '<div class="alert alert-danger" style="margin: 10px 0;">Network error loading attendance.</div>';
        }
    }
}

function closeAttendanceModal() {
    const modal = document.getElementById('wsAttendanceModal');
    if (modal) modal.style.display = 'none';
}

function formatForDatetimeLocal(dtString) {
    if (!dtString) return '';
    try {
        const d = new Date(dtString.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    } catch {
        return '';
    }
}

function formatForTimeInput(timeString) {
    if (!timeString) return '';
    const parts = String(timeString).split(/[ T]/);
    const timePart = parts.length > 1 ? parts[1] : parts[0];
    const match = timePart.match(/(\d{1,2}):(\d{2})/);
    if (!match) return '';
    let hours = parseInt(match[1], 10);
    const mins = match[2];
    if (/pm/i.test(timeString) && hours < 12) hours += 12;
    if (/am/i.test(timeString) && hours === 12) hours = 0;
    return `${String(hours).padStart(2, '0')}:${mins}`;
}

function setAttendanceActualStartTimeNow() {
    const input = document.getElementById('attActualStartTime');
    if (!input) return;
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    input.value = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
}
window.setAttendanceActualStartTimeNow = setAttendanceActualStartTimeNow;

function appendDelayRemark(remark) {
    const textarea = document.getElementById('attDelayRemarks');
    if (!textarea) return;
    const current = textarea.value.trim();
    if (!current) {
        textarea.value = remark;
    } else if (!current.includes(remark)) {
        textarea.value = current.replace(/[.,;]?$/, '') + '. ' + remark;
    }
    textarea.focus();
}
window.appendDelayRemark = appendDelayRemark;

async function saveStreamlinedAttendance(silent = false) {
    const hearingId = stageWorkspaceState.currentHearingId || stageWorkspaceState.activeSessionHearingId;
    if (!hearingId) return;

    const schedDate = document.getElementById('attScheduledDateTime')?.value;
    const delayRemarks = document.getElementById('attDelayRemarks')?.value.trim() || '';

    const currentData = stageWorkspaceState.lastAttendanceData || {};
    const parties = currentData.parties || [];
    let compAtt = 'Present';
    let respAtt = 'Present';

    const partyRecords = parties.map(p => {
        const partyId = p.resident_id || p.party_id;
        const rad = document.querySelector(`input[name="att_party_${partyId}"]:checked`);
        const st = rad ? rad.value : (p.attendance_status || 'Present');
        if (p.party_type === 'Complainant') {
            if (st === 'Absent') compAtt = 'Absent';
            else if (st === 'Late' && compAtt !== 'Absent') compAtt = 'Late';
        } else if (p.party_type === 'Respondent') {
            if (st === 'Absent') respAtt = 'Absent';
            else if (st === 'Late' && respAtt !== 'Absent') respAtt = 'Late';
        }
        return {
            resident_id: p.resident_id,
            party_type: p.party_type,
            attendance_status: st,
            is_justified: st === 'Present' ? 0 : (delayRemarks ? 1 : 0),
            justification_reason: st === 'Present' ? null : (delayRemarks || null),
            verification_status: st === 'Present' ? 'Verified Served' : 'Pending'
        };
    });

    const payload = {
        hearing_id: hearingId,
        scheduled_datetime: schedDate ? schedDate.replace('T', ' ') + ':00' : null,
        delay_remarks: delayRemarks,
        attendance_notes: delayRemarks,
        presider_attendance: 'Present',
        complainant_attendance: compAtt,
        complainant_explanation: compAtt === 'Absent' ? delayRemarks : '',
        respondent_attendance: respAtt,
        respondent_explanation: respAtt === 'Absent' ? delayRemarks : '',
        parties: partyRecords
    };

    try {
        const res = await fetch(getStageApiUrl('hearings/attendance.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            if (!silent) {
                notifyUser('Attendance records saved successfully.', 'success');
            }
            if (window.AgapDraftManager) {
                const draftKey = `agap_draft_${stageWorkspaceState.mode}_case_${stageWorkspaceState.caseId}_hearing_${hearingId}`;
                window.AgapDraftManager.markSubmitted(draftKey);
                window.AgapDraftManager.setIndicator('wsDraftIndicator', 'synced', '✓ Attendance submitted');
            }
        } else if (!silent) {
            notifyUser(json.message || 'Unable to save attendance.', 'error');
        }
    } catch (err) {
        console.error('saveStreamlinedAttendance error:', err);
        if (!silent) {
            notifyUser('Network error saving attendance.', 'error');
        }
    }
}
window.saveStreamlinedAttendance = saveStreamlinedAttendance;

function handleCompAttendanceRadioChange(val) {
    const notice = document.getElementById('wsCompAbsentNotice');
    if (notice) notice.style.display = (val === 'Absent') ? 'block' : 'none';
    saveStreamlinedAttendance(true);
}
window.handleCompAttendanceRadioChange = handleCompAttendanceRadioChange;

function renderAttendanceModalForm(data) {
    const isCard = !!document.getElementById('wsAttCardBody');
    const bodyEl = document.getElementById('wsAttCardBody') || document.getElementById('wsAttModalBody');
    if (!bodyEl) return;

    stageWorkspaceState.lastAttendanceData = data;
    const hearing = data.hearing || data;
    const parties = data.parties || [];
    const hearingId = Number(hearing.hearing_id || stageWorkspaceState.currentHearingId);

    const scheduledVal = formatForDatetimeLocal(hearing.hearing_date);
    const delayRemarksVal = hearing.attendance_notes || '';
    const isSaved = Boolean(hearing.attendance_recorded_at);

    // Build per-person party attendance cards
    const partiesHtml = parties.length ? parties.map(p => {
        const pAtt = p.attendance_status || (p.party_type === 'Complainant' ? (hearing.complainant_attendance || 'Present') : (hearing.respondent_attendance || 'Present'));
        const partyId = p.resident_id || p.party_id;
        const isComp = (p.party_type === 'Complainant');
        const rolePill = isComp
            ? '<span style="font-size: 0.76rem; font-weight: 700; padding: 3px 10px; border-radius: 9999px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">Nagpaanyaya (Complainant)</span>'
            : '<span style="font-size: 0.76rem; font-weight: 700; padding: 3px 10px; border-radius: 9999px; background: #fdf2f8; color: #be185d; border: 1px solid #fbcfe8;">Inanyayahan (Respondent)</span>';

        return `
            <div class="party-att-person-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 18px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <span style="color: #6366f1; font-size: 1.1rem;">👤</span>
                            <strong style="font-size: 0.96rem; color: #0f172a;">${escapeHtml(p.full_name || 'Party Member')}</strong>
                        </div>
                        <div style="font-size: 0.78rem; color: #64748b; margin-top: 3px; margin-left: 24px;">
                            📍 ${escapeHtml(p.address || 'Address on record')}
                        </div>
                    </div>
                    <div>${rolePill}</div>
                </div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-left: 24px;">
                    <label class="party-att-radio-pill ${pAtt === 'Present' ? 'selected' : ''}" style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1.5px solid ${pAtt === 'Present' ? '#86efac' : '#e2e8f0'}; border-radius: 8px; background: ${pAtt === 'Present' ? '#f0fdf4' : '#fff'}; transition: all 0.15s ease;">
                        <input type="radio" name="att_party_${partyId}" value="Present" ${pAtt === 'Present' ? 'checked' : ''} onchange="handlePartyAttendanceChange(${partyId}, 'Present', '${p.party_type}')" style="accent-color: #16a34a; width: 15px; height: 15px; cursor: pointer;">
                        <span style="font-size: 0.84rem; font-weight: 600; color: ${pAtt === 'Present' ? '#15803d' : '#334155'};">🟢 Dumalo sa Oras (Present)</span>
                    </label>
                    <label class="party-att-radio-pill ${pAtt === 'Late' ? 'selected' : ''}" style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1.5px solid ${pAtt === 'Late' ? '#fde68a' : '#e2e8f0'}; border-radius: 8px; background: ${pAtt === 'Late' ? '#fefce8' : '#fff'}; transition: all 0.15s ease;">
                        <input type="radio" name="att_party_${partyId}" value="Late" ${pAtt === 'Late' ? 'checked' : ''} onchange="handlePartyAttendanceChange(${partyId}, 'Late', '${p.party_type}')" style="accent-color: #d97706; width: 15px; height: 15px; cursor: pointer;">
                        <span style="font-size: 0.84rem; font-weight: 600; color: ${pAtt === 'Late' ? '#b45309' : '#334155'};">🟡 Nahuli / Late (Pasok sa 1-hour grace period)</span>
                    </label>
                    <label class="party-att-radio-pill ${pAtt === 'Absent' ? 'selected' : ''}" style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1.5px solid ${pAtt === 'Absent' ? '#fca5a5' : '#e2e8f0'}; border-radius: 8px; background: ${pAtt === 'Absent' ? '#fef2f2' : '#fff'}; transition: all 0.15s ease;">
                        <input type="radio" name="att_party_${partyId}" value="Absent" ${pAtt === 'Absent' ? 'checked' : ''} onchange="handlePartyAttendanceChange(${partyId}, 'Absent', '${p.party_type}')" style="accent-color: #dc2626; width: 15px; height: 15px; cursor: pointer;">
                        <span style="font-size: 0.84rem; font-weight: 600; color: ${pAtt === 'Absent' ? '#991b1b' : '#334155'};">🔴 Hindi Dumalo (Absent - Lampas 1 oras)</span>
                    </label>
                </div>
            </div>
        `;
    }).join('') : '<div style="color: #64748b; font-size: 0.85rem; padding: 10px;">Walang nakalistang partido sa sesyong ito.</div>';

    bodyEl.innerHTML = `
        <div class="streamlined-attendance-container" style="display: flex; flex-direction: column; gap: 16px;">
            ${isSaved ? `
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 10px 14px; font-size: 0.84rem; color: #166534; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <span style="font-weight: 600;">✓ Attendance details recorded and saved in database${hearing.attendance_recorded_at ? ' (' + formatDateTimeReadable(hearing.attendance_recorded_at) + ')' : ''}</span>
                    <span style="font-size: 0.78rem; background: #dcfce7; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">STATUS: ${escapeHtml(hearing.status || 'Attendance Recorded')}</span>
                </div>
            ` : ''}
            <!-- Row 1: Scheduled Date/Time Only -->
            <div>
                <label for="attScheduledDateTime" style="display: block; font-weight: 600; color: #0f172a; font-size: 0.88rem; margin-bottom: 6px;">
                    Petsa at Takdang Oras (Scheduled Date &amp; Time) <span style="color: #dc2626;">*</span>
                </label>
                <input type="datetime-local" id="attScheduledDateTime" value="${escapeHtml(scheduledVal)}" onchange="saveStreamlinedAttendance(true)" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 0.88rem; box-sizing: border-box; background: #fff; font-family: inherit; color: #1e293b;">
            </div>

            <!-- Row 2: Session Delay Remarks -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label for="attDelayRemarks" style="font-weight: 600; color: #0f172a; font-size: 0.88rem;">
                        Puna sa Oras ng Simula / Dahilan ng Pagkaantala (Session Delay Remarks)
                    </label>
                    <span style="color: #64748b; font-size: 0.8rem;">
                        (Kung sakaling late nag-umpisa ang session)
                    </span>
                </div>
                <textarea id="attDelayRemarks" rows="2" oninput="saveStreamlinedAttendance(true)" placeholder="Halimbawa: Naantala ang simula dahil may naunang kaso sa Lupon Court Room. Present ang mga partido." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; font-size: 0.86rem; box-sizing: border-box; font-family: inherit; resize: vertical; line-height: 1.45; color: #1e293b;">${escapeHtml(delayRemarksVal)}</textarea>
                
                <div style="display: flex; align-items: center; gap: 8px; margin-top: 8px; flex-wrap: wrap;">
                    <span style="font-size: 0.8rem; color: #64748b; font-weight: 500;">I-click para ilagay:</span>
                    <button type="button" class="delay-helper-pill" onclick="appendDelayRemark('Naunang kaso sa Court Room'); saveStreamlinedAttendance(true);">
                        • Naunang kaso sa Court Room
                    </button>
                    <button type="button" class="delay-helper-pill pill-warning" onclick="appendDelayRemark('Nahuli ang panig'); saveStreamlinedAttendance(true);">
                        • Nahuli ang panig
                    </button>
                    <button type="button" class="delay-helper-pill pill-warning" style="background: #fef2f2; color: #991b1b; border-color: #fca5a5;" onclick="appendDelayRemark('Lumampas sa 1-hour grace period / Hindi sumipot'); saveStreamlinedAttendance(true);">
                        • Lumampas sa 1-hour grace period
                    </button>
                    <button type="button" class="delay-helper-pill pill-success" onclick="appendDelayRemark('Nasa oras'); saveStreamlinedAttendance(true);">
                        • Nasa oras
                    </button>
                </div>
            </div>

            <!-- Row 3: Per-Person Attendance -->
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 6px;">
                    <span style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">
                        Pagdalo ng Bawat Panig (Per-Person Attendance) <span style="color: #dc2626;">*</span>
                    </span>
                    <span style="font-size: 0.8rem; color: #64748b;">Itala ang pagdalo ayon sa pangalan</span>
                </div>

                <div id="wsAttPartiesContainer" style="display: flex; flex-direction: column; gap: 10px;">
                    ${partiesHtml}
                </div>
            </div>
        </div>
    `;
}

function handlePartyAttendanceChange(partyId, status, partyType) {
    saveStreamlinedAttendance(true);
}
window.handlePartyAttendanceChange = handlePartyAttendanceChange;

/**
 * -----------------------------------------------------------------------------
 * GEMINI 1.5 FLASH: SPEECH-TO-TEXT (STT) & IMAGE OCR
 * -----------------------------------------------------------------------------
 */
let stageMediaRecorder = null;
let stageAudioChunks = [];
let isStageVoiceRecording = false;
let stageRecordingTimerInterval = null;
let stageRecordingSeconds = 0;

function toggleCaucusNotesVisibility(checkbox) {
    const wrap = document.getElementById('minCaucusNotesWrap');
    if (wrap) {
        wrap.style.display = checkbox.checked ? 'block' : 'none';
    }
}

async function toggleStageVoiceRecording() {
    const btn = document.getElementById('btnStageRecordVoice');
    const textSpan = document.getElementById('stageRecordVoiceText');
    const statusBanner = document.getElementById('stageAiStatusBanner');
    const consentBox = document.getElementById('stageAiConsentCheckbox');

    if (!isStageVoiceRecording) {
        if (consentBox && !consentBox.checked) {
            if (confirm('Google Gemini AI is used to transcribe this voice recording. Do you confirm consent to send the audio to Google Gemini?')) {
                consentBox.checked = true;
            } else {
                if (statusBanner) {
                    statusBanner.style.display = 'block';
                    statusBanner.innerHTML = '<span style="color:#b91c1c;">Please check the confirmation box "Consent to Google Gemini AI" before recording.</span>';
                }
                return;
            }
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Microphone recording is not supported in this browser environment.');
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            stageAudioChunks = [];
            stageRecordingSeconds = 0;
            stageMediaRecorder = new MediaRecorder(stream);

            stageMediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    stageAudioChunks.push(event.data);
                }
            };

            stageMediaRecorder.onstop = async () => {
                stream.getTracks().forEach(track => track.stop());
                clearInterval(stageRecordingTimerInterval);
                const audioBlob = new Blob(stageAudioChunks, { type: 'audio/webm' });
                await sendStageAudioToGemini(audioBlob);
            };

            stageMediaRecorder.start();
            isStageVoiceRecording = true;
            if (btn) {
                btn.classList.add('recording');
                btn.style.background = '#ef4444';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#dc2626';
            }
            if (textSpan) textSpan.textContent = 'Stop Recording (00:00)';
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '<strong>Recording audio from microphone...</strong> Speak clearly. Click "Stop Recording" when finished.';
            }

            stageRecordingTimerInterval = setInterval(() => {
                stageRecordingSeconds++;
                const mins = String(Math.floor(stageRecordingSeconds / 60)).padStart(2, '0');
                const secs = String(stageRecordingSeconds % 60).padStart(2, '0');
                if (textSpan) textSpan.textContent = `Stop Recording (${mins}:${secs})`;
            }, 1000);
        } catch (err) {
            console.error('Microphone access denied:', err);
            alert('Unable to access microphone: ' + err.message);
        }
    } else {
        if (stageMediaRecorder && stageMediaRecorder.state !== 'inactive') {
            stageMediaRecorder.stop();
        }
        isStageVoiceRecording = false;
        clearInterval(stageRecordingTimerInterval);
        if (btn) {
            btn.classList.remove('recording');
            btn.style.background = '';
            btn.style.color = '';
            btn.style.borderColor = '';
            btn.disabled = true;
        }
        if (textSpan) textSpan.textContent = 'Transcribing Voice...';
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = '<strong>Transcribing voice with Gemini 1.5 Flash...</strong> Please wait.';
        }
    }
}

async function sendStageAudioToGemini(audioBlob) {
    const btn = document.getElementById('btnStageRecordVoice');
    const textSpan = document.getElementById('stageRecordVoiceText');
    const statusBanner = document.getElementById('stageAiStatusBanner');
    const consentBox = document.getElementById('stageAiConsentCheckbox');

    try {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'stage_session_recording.webm');
        const hasConsent = (consentBox ? consentBox.checked : true);
        formData.append('external_ai_consent', hasConsent ? '1' : '0');

        const response = await fetch(getStageApiUrl('ai/transcribe-audio.php'), {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success && result.text) {
            appendGeminiTextToMinutes(result.text, 'Voice Transcription');
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '<strong>Voice transcription appended successfully.</strong>';
                setTimeout(() => { if (statusBanner) statusBanner.style.display = 'none'; }, 4000);
            }
        } else {
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = `<span style="color:#b91c1c;">Transcription error: ${escapeHtml(result.message || 'Failed to process audio.')}</span>`;
            }
        }
    } catch (error) {
        console.error('Audio transcription error:', error);
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = `<span style="color:#b91c1c;">Network error during transcription: ${escapeHtml(error.message)}</span>`;
        }
    } finally {
        if (btn) btn.disabled = false;
        if (textSpan) textSpan.textContent = 'Record Voice (STT)';
    }
}

function triggerStageNotesUpload(hearingId = null) {
    const fileInput = getMinutesField('stageNotesFileInput', hearingId);
    if (fileInput) fileInput.click();
}
window.triggerStageNotesUpload = triggerStageNotesUpload;

async function handleStageNotesFileSelected(input, hearingId = null) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const statusBanner = getMinutesField('stageAiStatusBanner', hearingId);
    const uploadBtn = getMinutesField('btnStageUploadNotes', hearingId);
    const consentBox = getMinutesField('stageAiConsentCheckbox', hearingId);

    if (consentBox && !consentBox.checked) {
        if (confirm('Google Gemini AI is used to extract text from handwritten/printed notes. Do you confirm consent to send this document to Google Gemini?')) {
            consentBox.checked = true;
        } else {
            input.value = '';
            if (uploadBtn) uploadBtn.disabled = false;
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '<span style="color:#b91c1c;">Please check the confirmation box "Consent to Google Gemini AI" before uploading notes.</span>';
            }
            return;
        }
    }

    if (uploadBtn) uploadBtn.disabled = true;
    if (statusBanner) {
        statusBanner.style.display = 'block';
        statusBanner.innerHTML = '<strong>Extracting handwritten/printed notes with Gemini 1.5 Flash OCR...</strong> Please wait.';
    }

    try {
        const formData = new FormData();
        formData.append('image', file);
        const hasConsent = (consentBox ? consentBox.checked : true);
        formData.append('external_ai_consent', hasConsent ? '1' : '0');

        const response = await fetch(getStageApiUrl('ai/ocr-notes.php'), {
            method: 'POST',
            body: formData
        });

        const result = await response.json();
        if (result.success && result.text) {
            appendGeminiTextToMinutes(result.text, 'Notes OCR Extraction', hearingId);
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = '<strong>Notes extracted and appended successfully.</strong>';
                setTimeout(() => { if (statusBanner) statusBanner.style.display = 'none'; }, 4000);
            }
        } else {
            if (statusBanner) {
                statusBanner.style.display = 'block';
                statusBanner.innerHTML = `<span style="color:#b91c1c;">OCR error: ${escapeHtml(result.message || 'Failed to extract text.')}</span>`;
            }
        }
    } catch (error) {
        console.error('OCR Extraction error:', error);
        if (statusBanner) {
            statusBanner.style.display = 'block';
            statusBanner.innerHTML = `<span style="color:#b91c1c;">Network error during OCR: ${escapeHtml(error.message)}</span>`;
        }
    } finally {
        if (uploadBtn) uploadBtn.disabled = false;
        input.value = '';
    }
}
window.handleStageNotesFileSelected = handleStageNotesFileSelected;

function appendGeminiTextToMinutes(newText, sourceLabel, hearingId = null) {
    const textarea = getMinutesField('session_minutes', hearingId);
    if (!textarea) return;

    const trimmed = newText.trim();
    if (!trimmed) return;

    const formattedSnippet = `\n\n[${sourceLabel} - ${new Date().toLocaleTimeString()}]:\n${trimmed}`;
    if (textarea.value.trim() === '') {
        textarea.value = trimmed;
    } else {
        textarea.value = textarea.value.trim() + formattedSnippet;
    }

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
    textarea.scrollTop = textarea.scrollHeight;
}

function getActiveMinutesSnapshot(hearingId) {
    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId)) || {};
    const form = document.getElementById(`inlineMinutesForm_${hearingId}`) || document.getElementById('inlineMinutesForm');

    if (form) {
        const getVal = (baseId) => (document.getElementById(`${baseId}_${hearingId}`) || document.getElementById(baseId))?.value;
        const getChecked = (baseId) => (document.getElementById(`${baseId}_${hearingId}`) || document.getElementById(baseId))?.checked ? 1 : 0;

        return {
            ...session,
            opening_conducted: getChecked('minOpening'),
            identity_verified: getChecked('minIdentity'),
            complaint_reviewed: getChecked('minReviewed'),
            complainant_statement: getVal('minCompStmt') || session.complainant_statement || '',
            respondent_statement: getVal('minRespStmt') || session.respondent_statement || '',
            main_dispute_identified: getVal('minDisputeSummary') || session.main_dispute_identified || '',
            agenda_topics: getVal('minAgenda') || session.agenda_topics || '',
            new_proposal: getVal('minNewProposal') || session.new_proposal || '',
            counteroffer: getVal('minCounterOffer') || session.counteroffer || '',
            caucus_conducted: getChecked('minCaucus'),
            caucus_notes: getVal('minCaucusNotes') || session.caucus_notes || '',
            agreements_action_items: getVal('minAgreements') || session.agreements_action_items || '',
            unresolved_issues: getVal('minUnresolved') || session.unresolved_issues || '',
            session_outcome: getVal('minOutcome') || session.session_outcome || 'Pending',
            actual_end_time: getVal('minActualEndTime') || session.actual_end_time || null,
            outcome_remarks: getVal('minOutcomeRemarks') || session.outcome_remarks || '',
            settlement_discussion_notes: getVal('session_minutes') || session.settlement_discussion_notes || ''
        };
    }
    return session;
}

function openPreviewMinutesModal(hearingId) {
    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId));
    const caseData = stageWorkspaceState.stageData?.case || {};
    const min = getActiveMinutesSnapshot(hearingId);

    let previewModal = document.getElementById('wsPreviewMinutesModal');
    if (!previewModal) {
        previewModal = document.createElement('div');
        previewModal.id = 'wsPreviewMinutesModal';
        previewModal.className = 'modal';
        previewModal.style.display = 'none';
        document.body.appendChild(previewModal);
    }

    previewModal.innerHTML = `
        <div class="modal-content" style="max-width: 840px; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h2>Official Minutes Preview: ${escapeHtml(session?.session_number || `Hearing #${hearingId}`)}</h2>
                <button type="button" class="close-btn" onclick="closePreviewMinutesModal()">&times;</button>
            </div>
            <div style="padding: 16px 20px; font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.5; color: #1e293b;">
                <div style="text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px;">
                    <h3 style="margin: 0; font-size: 1.15rem; text-transform: uppercase;">Republic of the Philippines · Barangay Tumana</h3>
                    <h4 style="margin: 3px 0 0; font-size: 0.95rem; color: #475569;">Lupong Tagapamayapa · Formal Session Minutes of the Meeting</h4>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.85rem; margin-bottom: 16px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <div><strong>Case Number:</strong> Case #${escapeHtml(caseData.case_number || 'N/A')}</div>
                    <div><strong>Hearing Schedule:</strong> ${formatDateTimeReadable(session?.hearing_date)}</div>
                    <div><strong>Complainant(s):</strong> ${escapeHtml(caseData.complainant_names || '—')}</div>
                    <div><strong>Venue:</strong> ${escapeHtml(session?.venue || 'Barangay Hall')}</div>
                    <div><strong>Respondent(s):</strong> ${escapeHtml(caseData.respondent_names || '—')}</div>
                    <div><strong>Presiding Officer:</strong> ${escapeHtml(session?.presider_name || 'Punong Barangay')}</div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">1. Procedural Opening &amp; Identity Verification</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px;">
                        Opening Statement: <strong>${min.opening_conducted ? 'Conducted' : 'Not Recorded'}</strong> · 
                        Parties Identity: <strong>${min.identity_verified ? 'Verified' : 'Not Recorded'}</strong> · 
                        Complaint Read &amp; Reviewed: <strong>${min.complaint_reviewed ? 'Reviewed' : 'Not Recorded'}</strong>
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">2. Main Dispute &amp; Statements</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px;"><strong>Core Dispute:</strong> ${escapeHtml(min.main_dispute_identified || 'None recorded')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Complainant Summary:</strong> ${escapeHtml(min.complainant_statement || 'None recorded')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Respondent Summary:</strong> ${escapeHtml(min.respondent_statement || 'None recorded')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Agenda Topics:</strong> ${escapeHtml(min.agenda_topics || 'None recorded')}</div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">3. Negotiation, Proposals &amp; Caucus</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px;"><strong>Proposal / Offer:</strong> ${escapeHtml(min.new_proposal || 'None submitted')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Counteroffer / Response:</strong> ${escapeHtml(min.counteroffer || 'None recorded')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Private Caucus:</strong> ${min.caucus_conducted ? `Conducted — ${escapeHtml(min.caucus_notes || 'Confidential')}` : 'None held'}</div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">4. Formal Proceedings / Minutes Transcript</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px; white-space: pre-wrap; background: #fff; padding: 10px; border: 1px solid #e2e8f0; border-radius: 4px;">${escapeHtml(min.settlement_discussion_notes || 'None recorded')}</div>
                </div>

                <div style="margin-bottom: 12px;">
                    <strong style="color: #0f172a; font-size: 0.88rem; border-bottom: 1px solid #cbd5e1; display: block; padding-bottom: 3px;">5. Agreed Terms &amp; Procedural Determination</strong>
                    <div style="font-size: 0.84rem; margin-top: 6px;"><strong>Agreed Terms:</strong> ${escapeHtml(min.agreements_action_items || 'None')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Unresolved Issues:</strong> ${escapeHtml(min.unresolved_issues || 'None')}</div>
                    <div style="font-size: 0.84rem; margin-top: 4px;"><strong>Session Outcome:</strong> <strong style="color: #15803d;">${escapeHtml(min.session_outcome || 'Pending')}</strong></div>
                    ${min.actual_end_time ? `<div style="font-size: 0.84rem; margin-top: 4px;"><strong>Actual End Time:</strong> ${formatDateTimeReadable(min.actual_end_time)}</div>` : ''}
                    ${min.outcome_remarks ? `<div style="font-size: 0.84rem; margin-top: 4px;"><strong>Remarks:</strong> ${escapeHtml(min.outcome_remarks)}</div>` : ''}
                </div>
            </div>
            <div class="modal-actions" style="margin-top: 10px;">
                <button type="button" class="btn-secondary" onclick="closePreviewMinutesModal()">Close</button>
                <button type="button" class="btn-create" onclick="printHearingMinutes(${hearingId})" style="background: #0369a1;">Print Official Minutes</button>
            </div>
        </div>
    `;
    previewModal.style.display = 'flex';
}

function closePreviewMinutesModal() {
    const modal = document.getElementById('wsPreviewMinutesModal');
    if (modal) modal.style.display = 'none';
}

/**
 * -----------------------------------------------------------------------------
 * PRINT: Official Hearing Minutes Document
 * -----------------------------------------------------------------------------
 */
function printHearingMinutes(hearingId) {
    const session = stageWorkspaceState.sessions.find(s => Number(s.hearing_id) === Number(hearingId)) || {};
    const caseData = stageWorkspaceState.stageData?.case || {};
    const min = getActiveMinutesSnapshot(hearingId);
    const sessionLabel = getStageSessionOrdinalLabel(stageWorkspaceState.mode || stageWorkspaceState.stageType, session.session_index || 1);

    const compAtt = session.complainant_attendance || 'Present';
    const respAtt = session.respondent_attendance || 'Present';
    const presiderName = session.substitute_presider_name || session.presiding_officer_name || (stageWorkspaceState.mode === 'Conciliation' ? 'Pangkat Chairman' : 'Hon. Akiko Lynne Centeno');

    const printWindow = window.open('', '_blank', 'width=850,height=950');
    if (!printWindow) {
        alert('Please allow popups to print the minutes.');
        return;
    }

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Minutes of Meeting - Case #${escapeHtml(caseData.case_number || '')}</title>
            <style>
                @page { size: A4 portrait; margin: 20mm; }
                body { font-family: 'Times New Roman', Times, serif; color: #000; margin: 0; padding: 25px 35px; line-height: 1.5; font-size: 11pt; }
                .letterhead { text-align: center; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 20px; }
                .letterhead p { margin: 2px 0; font-size: 10pt; }
                .letterhead h2 { margin: 6px 0 2px; font-size: 14pt; letter-spacing: 1px; text-transform: uppercase; }
                .letterhead h3 { margin: 2px 0; font-size: 12pt; font-weight: 700; text-transform: uppercase; color: #1e3a8a; }
                .title-block { text-align: center; margin: 18px 0 16px; }
                .title-block h1 { margin: 0; font-size: 13pt; text-transform: uppercase; text-decoration: underline; font-weight: bold; }
                .title-block span { font-size: 10.5pt; font-style: italic; }
                .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 10.5pt; }
                .meta-table td { padding: 4px 6px; vertical-align: top; }
                .attendance-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10pt; border: 1px solid #000; }
                .attendance-table th, .attendance-table td { border: 1px solid #000; padding: 5px 8px; text-align: left; }
                .attendance-table th { background: #f1f5f9; font-weight: bold; text-transform: uppercase; font-size: 9.5pt; }
                .section-heading { font-weight: bold; font-size: 11pt; text-transform: uppercase; margin: 16px 0 6px; border-bottom: 1px solid #94a3b8; padding-bottom: 2px; }
                .section-body { margin-bottom: 14px; text-align: justify; white-space: pre-wrap; font-size: 10.5pt; line-height: 1.55; }
                .signatures { width: 100%; margin-top: 36px; border-collapse: collapse; font-size: 10pt; page-break-inside: avoid; }
                .signatures td { vertical-align: top; text-align: center; width: 33.33%; padding: 0 10px; }
                .sig-line { margin-top: 45px; border-top: 1px solid #000; padding-top: 4px; font-weight: bold; text-transform: uppercase; }
                @media print {
                    body { padding: 0; }
                    .no-print { display: none !important; }
                }
            </style>
        </head>
        <body>
            <div class="letterhead">
                <p>Republic of the Philippines</p>
                <p>National Capital Judicial Region</p>
                <p>City of Marikina</p>
                <h2>Barangay Tumana</h2>
                <h3>Tanggapan ng Lupong Tagapamayapa</h3>
            </div>

            <div class="title-block">
                <h1>Katitikan ng Pagdinig (Minutes of the Meeting)</h1>
                <span>${escapeHtml(sessionLabel)} &bull; ${formatDateTimeReadable(session?.hearing_date)}</span>
            </div>

            <table class="meta-table">
                <tr>
                    <td style="width: 50%;"><strong>Usaping Barangay Blg. (Case No.):</strong> KP #${escapeHtml(caseData.case_number || 'N/A')}</td>
                    <td style="width: 50%;"><strong>Petsa at Oras (Date &amp; Time):</strong> ${formatDateTimeReadable(session?.hearing_date)}</td>
                </tr>
                <tr>
                    <td><strong>May Sumbong (Complainant/s):</strong> ${escapeHtml(caseData.complainant_names || '—')}</td>
                    <td><strong>Lugar ng Pagpupulong (Venue):</strong> ${escapeHtml(session?.venue || 'Tanggapan ng Barangay Tumana')}</td>
                </tr>
                <tr>
                    <td><strong>Ipinagsumbong (Respondent/s):</strong> ${escapeHtml(caseData.respondent_names || '—')}</td>
                    <td><strong>Ukol Sa (Complaint Title / Nature):</strong> ${escapeHtml(caseData.complaint_title || min.main_dispute_identified || 'Usaping Pambarangay')}</td>
                </tr>
            </table>

            <table class="attendance-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Panig (Party)</th>
                        <th style="width: 45%;">Pangalan (Name)</th>
                        <th style="width: 30%;">Katayuan (Appearance)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Punong Barangay / Tagapangulo</strong></td>
                        <td>${escapeHtml(presiderName)}</td>
                        <td>Dumalo (Present)</td>
                    </tr>
                    <tr>
                        <td><strong>Nagsusumbong (Complainant)</strong></td>
                        <td>${escapeHtml(caseData.complainant_names || '—')}</td>
                        <td>${compAtt === 'Late' ? 'Nahuli (Late)' : (compAtt === 'Present' ? 'Dumalo (Present)' : 'Hindi Dumalo (Absent)')}</td>
                    </tr>
                    <tr>
                        <td><strong>Ipinagsusumbong (Respondent)</strong></td>
                        <td>${escapeHtml(caseData.respondent_names || '—')}</td>
                        <td>${respAtt === 'Late' ? 'Nahuli (Late)' : (respAtt === 'Present' ? 'Dumalo (Present)' : 'Hindi Dumalo (Absent)')}</td>
                    </tr>
                </tbody>
            </table>

            <div class="section-heading">I. Salaysay at Buod ng Usapan (Proceedings &amp; Discussion)</div>
            <div class="section-body">${escapeHtml(min.settlement_discussion_notes || min.main_dispute_identified || 'Walang naitalang buod ng talakayan.')}</div>

            <div class="section-heading">II. Mga Mungkahi at Napagkasunduang Kondisyon (Agreed Terms &amp; Settlement)</div>
            <div class="section-body">${escapeHtml(min.agreements_action_items || 'Walang pormal na kasunduang napagtibay sa sesyong ito.')}</div>

            <div class="section-heading">III. Kinalabasan ng Pagdinig at Susunod na Hakbang (Session Outcome)</div>
            <div class="section-body"><strong>Kinalabasan (Outcome):</strong> ${escapeHtml(min.session_outcome || 'Pending Evaluation')}${min.outcome_remarks ? ' — ' + escapeHtml(min.outcome_remarks) : ''}${min.actual_end_time ? ' (Natapos: ' + formatDateTimeReadable(min.actual_end_time) + ')' : ''}</div>

            <table class="signatures">
                <tr>
                    <td>
                        <div class="sig-line">${escapeHtml(caseData.complainant_names || 'May Sumbong')}</div>
                        <span>Lagda ng Nagsumbong</span>
                    </td>
                    <td>
                        <div class="sig-line">${escapeHtml(caseData.respondent_names || 'Ipinagsumbong')}</div>
                        <span>Lagda ng Ipinagsumbong</span>
                    </td>
                    <td>
                        <div class="sig-line">${escapeHtml(presiderName)}</div>
                        <span>${stageWorkspaceState.mode === 'Conciliation' ? 'Pangkat Chairman' : 'Punong Barangay / Tagapamagitan'}</span>
                    </td>
                </tr>
            </table>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => { printWindow.print(); }, 500);
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Record Stage Outcome (Settled, Unsuccessful, Repudiated, Dismissed)
 * -----------------------------------------------------------------------------
 */
function openRecordOutcomeModal() {
    const modal = document.getElementById('wsRecordOutcomeModal');
    if (modal) modal.style.display = 'flex';
}

function closeRecordOutcomeModal() {
    const modal = document.getElementById('wsRecordOutcomeModal');
    if (modal) modal.style.display = 'none';
}

// The compact outcome modal shares the arbitration callout used by the
// embedded complaint workspace. Keep this small adapter here because case
// workspaces load stage-workspace.js directly.
function handleEmbeddedStageOutcomeChange(outcome) {
    const banner = document.getElementById('stageOutcomeArbitrationBanner');
    if (banner) banner.style.display = outcome === 'Arbitration' ? 'block' : 'none';
}
window.handleEmbeddedStageOutcomeChange = handleEmbeddedStageOutcomeChange;


/**
 * Issue Arbitration from Mediation or Conciliation Hearing Minutes
 * Automatically records/saves the hearing outcome as Voluntary Arbitration,
 * unlocks the Outcomes / Arbitration Tab, and immediately redirects the user.
 */
async function issueArbitrationOutcome(hearingId = null) {
    const hId = hearingId || stageWorkspaceState.activeSessionHearingId;
    const caseId = stageWorkspaceState.caseId;
    let complaintId = stageWorkspaceState.complaintId;
    const mode = stageWorkspaceState.mode || 'Mediation';
    const remarks = (document.getElementById(`minOutcomeRemarks_${hId}`) || document.getElementById('minOutcomeRemarks') || document.getElementById('stageOutcomeRemarks'))?.value || 'Parties voluntarily agreed in writing to submit their dispute to binding arbitration under RA 7160 Sec. 413.';

    try {
        const payload = {
            case_id: Number(caseId),
            stage_type: mode,
            outcome: 'Arbitration',
            remarks: remarks
        };
        const res = await fetch(getStageApiUrl('stages/outcome.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (!json.success) {
            notifyUser(json.message || 'Unable to record arbitration outcome.', 'error');
            return;
        }

        if (json.complaint_id) {
            complaintId = json.complaint_id;
        }

        // Also save hearing minutes if session is active
        if (hId) {
            const outcomeEl = getMinutesField('minOutcome', hId);
            if (outcomeEl) outcomeEl.value = 'Arbitration';
            try {
                if (typeof saveHearingMinutes === 'function') {
                    await saveHearingMinutes('Finalized', hId, true);
                }
            } catch (minErr) {
                console.warn('saveHearingMinutes note:', minErr);
            }
        }

        // Unlock Outcomes tab
        unlockOutcomesTab(caseId);

        // Redirect immediately to the Outcomes / Arbitration tab (tab-arbitration)
        if (complaintId) {
            window.location.href = `../complaints/complaint-details.php?id=${encodeURIComponent(complaintId)}&tab=tab-arbitration#caseWorkspaceSection`;
        } else {
            window.location.href = `../complaints/complaint-details.php?tab=tab-arbitration`;
        }
    } catch (err) {
        console.error('issueArbitrationOutcome error:', err);
        notifyUser('A network error occurred while issuing arbitration.', 'error');
    }
}
window.issueArbitrationOutcome = issueArbitrationOutcome;

/**
 * Issue Settled Outcome from Mediation or Conciliation Hearing Minutes
 * Automatically records/saves the hearing outcome as Settled,
 * unlocks the Outcomes Tab, and immediately redirects the user to Amicable Settlement.
 */
async function issueSettledOutcome(hearingId = null) {
    const hId = hearingId || stageWorkspaceState.activeSessionHearingId;
    const caseId = stageWorkspaceState.caseId;
    let complaintId = stageWorkspaceState.complaintId || new URLSearchParams(window.location.search).get('complaint_id');
    const mode = stageWorkspaceState.mode || 'Mediation';
    const remarks = (document.getElementById(`minOutcomeRemarks_${hId}`) || document.getElementById('minOutcomeRemarks') || document.getElementById('stageOutcomeRemarks'))?.value || 'Parties reached an amicable settlement (Kasunduan).';

    try {
        const payload = {
            case_id: Number(caseId),
            stage_type: mode,
            outcome: 'Settled',
            remarks: remarks
        };
        const res = await fetch(getStageApiUrl('stages/outcome.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (!json.success) {
            notifyUser(json.message || 'Unable to record amicable settlement outcome.', 'error');
            return;
        }

        if (json.complaint_id) {
            complaintId = json.complaint_id;
        }

        // Also save hearing minutes if session is active
        if (hId) {
            const outcomeEl = getMinutesField('minOutcome', hId);
            if (outcomeEl) outcomeEl.value = 'Settled';
            try {
                if (typeof saveHearingMinutes === 'function') {
                    await saveHearingMinutes('Finalized', hId, true);
                }
            } catch (minErr) {
                console.warn('saveHearingMinutes note:', minErr);
            }
        }

        // Unlock Outcomes tab in session
        unlockOutcomesTab(caseId);
        if (complaintId) {
            sessionStorage.setItem('agap_outcomes_unlocked_' + complaintId, '1');
        }

        // Redirect immediately to the Outcomes tab (tab-arbitration)
        if (complaintId) {
            window.location.href = `../complaints/complaint-details.php?id=${encodeURIComponent(complaintId)}&tab=tab-arbitration#caseWorkspaceSection`;
        } else {
            window.location.href = `../complaints/complaint-details.php?tab=tab-arbitration`;
        }
    } catch (err) {
        console.error('issueSettledOutcome error:', err);
        notifyUser('A network error occurred while opening amicable settlement.', 'error');
    }
}
window.issueSettledOutcome = issueSettledOutcome;

async function submitRecordOutcome(e) {
    e.preventDefault();
    const btn = document.getElementById('submitOutcomeBtn');
    if (btn) btn.disabled = true;

    const outcomeVal = document.getElementById('stageOutcomeSelect').value;
    const remarksVal = document.getElementById('stageOutcomeRemarks').value;

    const payload = {
        case_id: stageWorkspaceState.caseId,
        stage_type: stageWorkspaceState.mode,
        outcome: outcomeVal,
        remarks: remarksVal
    };

    try {
        const res = await fetch(getStageApiUrl('stages/outcome.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closeRecordOutcomeModal();
            await loadStageData();
            if (outcomeVal === 'Arbitration') {
                unlockOutcomesTab(stageWorkspaceState.caseId);
                const compId = json.complaint_id || stageWorkspaceState.complaintId;
                if (compId) {
                    window.location.href = `../complaints/complaint-details.php?id=${encodeURIComponent(compId)}&tab=tab-arbitration#caseWorkspaceSection`;
                } else {
                    window.location.href = `../complaints/complaint-details.php?tab=tab-arbitration`;
                }
            } else if (outcomeVal === 'Settled') {
                openKpFormModal(stageWorkspaceState.caseId, 'KP Form 16');
            } else if (outcomeVal === 'Repudiated') {
                openKpFormModal(stageWorkspaceState.caseId, 'KP Form 17');
            }
        } else {
            alert(json.message || 'Unable to record outcome.');
        }
    } catch (err) {
        console.error('submitRecordOutcome error:', err);
        alert('Network error recording outcome.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Refer to Pangkat ng Tagapagkasundo (KP Form 10)
 * -----------------------------------------------------------------------------
 */
let referCalendarWindowMeta = null;

function initReferCalendar() {
    const dateInput = document.getElementById('referConciliationDate');
    const today = new Date();
    
    // Set min date to tomorrow
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    const minDateStr = tomorrow.toISOString().split('T')[0];
    if (dateInput) {
        dateInput.min = minDateStr;
        if (!dateInput.value) {
            // Pick next non-Tuesday weekday (Mon=1, Wed=3, Thu=4, Fri=5)
            let cur = new Date(tomorrow);
            while (cur.getDay() === 0 || cur.getDay() === 6 || cur.getDay() === 2) {
                cur.setDate(cur.getDate() + 1);
            }
            dateInput.value = cur.toISOString().split('T')[0];
        }
    }

    const timeInput = document.getElementById('referConciliationTime');
    if (timeInput && !timeInput.value) {
        timeInput.value = '09:00';
    }

    checkReferScheduleConflict();
}

function handleReferDateInputChange() {
    const dateInput = document.getElementById('referConciliationDate');
    const conflictAlert = document.getElementById('referScheduleConflictAlert');
    if (!dateInput || !dateInput.value) return;

    const selectedDate = new Date(dateInput.value + 'T00:00:00');
    const dow = selectedDate.getDay();

    if (dow === 0 || dow === 6) {
        if (conflictAlert) {
            conflictAlert.textContent = 'Conciliation hearings cannot be scheduled on weekends.';
            conflictAlert.style.display = 'block';
        }
        return;
    }

    if (dow === 2) {
        if (conflictAlert) {
            conflictAlert.textContent = 'Tuesdays reserved exclusively for Punong Barangay Mediation. Conciliation hearings must be scheduled on non-Tuesday weekdays (Mondays, Wednesdays, Thursdays, or Fridays).';
            conflictAlert.style.display = 'block';
        }
        return;
    }

    if (conflictAlert) conflictAlert.style.display = 'none';

    if (referCalendarWindowMeta) {
        renderReferVisualDays(referCalendarWindowMeta, dateInput.value);
    }
    checkReferScheduleConflict();
}

function selectReferConciliationSlot(timeStr) {
    const timeInput = document.getElementById('referConciliationTime');
    if (timeInput) timeInput.value = timeStr;

    document.querySelectorAll('#referSlotBtnGroup .refer-slot-btn').forEach(btn => {
        if (btn.dataset.time === timeStr) {
            btn.classList.add('active');
            btn.style.borderColor = '#0284c7';
            btn.style.background = '#e0f2fe';
            btn.style.color = '#0369a1';
            btn.style.borderWidth = '1.5px';
        } else {
            btn.classList.remove('active');
            btn.style.borderColor = '#cbd5e1';
            btn.style.background = '#ffffff';
            btn.style.color = '#334155';
            btn.style.borderWidth = '1px';
        }
    });

    checkReferScheduleConflict();
}

function selectReferVisualDate(dateStr) {
    const dateInput = document.getElementById('referConciliationDate');
    if (!dateInput) return;
    dateInput.value = dateStr;

    if (referCalendarWindowMeta) {
        renderReferVisualDays(referCalendarWindowMeta, dateStr);
    }
    checkReferScheduleConflict();
}

function renderReferVisualDays(windowMeta, selectedDate) {
    const gridEl = document.getElementById('referVisualDayGrid');
    const noticeEl = document.getElementById('referVisualDateStatusNotice');
    const monthLabelEl = document.getElementById('referCalendarMonthLabel');
    const capacityBadge = document.getElementById('referCourtroomCapacityBadge');
    if (!gridEl) return;

    const days = windowMeta?.daily_slots ? Object.values(windowMeta.daily_slots) : [];
    if (!days.length) {
        gridEl.innerHTML = '<div style="color: #64748b; font-size: 0.78rem; grid-column: 1 / -1; text-align: center; padding: 12px;">No available conciliation slots.</div>';
        return;
    }

    if (monthLabelEl && days.length > 0) {
        const firstDay = days[0];
        monthLabelEl.textContent = `· ${firstDay.month_name || ''} ${new Date(firstDay.date).getFullYear()}`;
    }

    gridEl.innerHTML = days.map(d => {
        const isSelected = (d.date === selectedDate);
        const dayDate = new Date(d.date + 'T00:00:00');
        const isTuesday = (dayDate.getDay() === 2);
        const isWeekend = (d.is_weekend || dayDate.getDay() === 0 || dayDate.getDay() === 6);

        let badgeText = `${d.available ?? 4} open`;
        let badgeBg = '#dcfce7';
        let badgeColor = '#15803d';
        let cardStyle = 'border: 1px solid #cbd5e1; background: #ffffff; color: #1e293b; cursor: pointer;';
        let disabledAttr = '';
        let tooltip = `${d.date}: ${d.available ?? 4} of ${d.max ?? 4} conciliation slots open.`;

        if (d.is_past) {
            badgeText = 'Past';
            badgeBg = '#f1f5f9';
            badgeColor = '#94a3b8';
            cardStyle = 'border: 1px solid #e2e8f0; background: #f8fafc; color: #94a3b8; cursor: not-allowed; opacity: 0.5;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Past date cannot be scheduled.`;
        } else if (isWeekend || d.is_non_working_day) {
            badgeText = d.is_non_working_day ? 'Holiday' : 'Weekend';
            badgeBg = '#f1f5f9';
            badgeColor = '#64748b';
            cardStyle = 'border: 1px dashed #cbd5e1; background: #f8fafc; color: #64748b; cursor: not-allowed; opacity: 0.5;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: ${d.is_non_working_day ? 'Holiday' : 'Weekend'}. Closed for conciliation.`;
        } else if (isTuesday) {
            badgeText = 'PB Only';
            badgeBg = '#fee2e2';
            badgeColor = '#991b1b';
            cardStyle = 'border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; cursor: not-allowed; opacity: 0.7;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Tuesdays reserved exclusively for Punong Barangay Mediation. Conciliation hearings must be scheduled on non-Tuesday weekdays (Mondays, Wednesdays, Thursdays, or Fridays).`;
        } else if (d.is_fully_booked || (d.available !== undefined && d.available <= 0)) {
            badgeText = 'Full';
            badgeBg = '#fee2e2';
            badgeColor = '#b91c1c';
            cardStyle = 'border: 1px solid #fecaca; background: #fff1f2; color: #881337; cursor: not-allowed; opacity: 0.65;';
            disabledAttr = 'disabled';
            tooltip = `${d.date}: Fully booked.`;
        }

        if (isSelected && !disabledAttr) {
            cardStyle = 'border: 2px solid #0284c7; background: #e0f2fe; color: #0369a1; box-shadow: 0 0 0 2px rgba(2,132,199,0.25); font-weight: 700; cursor: pointer;';
            badgeBg = '#0284c7';
            badgeColor = '#ffffff';
        }

        return `
            <div class="refer-day-card ${isSelected ? 'active' : ''}"
                 onclick="${disabledAttr ? '' : `selectReferVisualDate('${d.date}')`}"
                 title="${escapeHtml(tooltip)}"
                 style="border-radius: 6px; padding: 6px 4px; text-align: center; transition: all 0.15s; user-select: none; ${cardStyle}">
                <div style="font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em;">
                    ${escapeHtml(d.day_name || '')}
                </div>
                <div style="font-size: 0.85rem; font-weight: 800; margin: 2px 0;">
                    ${d.day_num ?? ''} ${escapeHtml(d.month_name || '')}
                </div>
                <div style="font-size: 0.65rem; font-weight: 700; padding: 1px 4px; border-radius: 4px; background: ${badgeBg}; color: ${badgeColor}; display: inline-block;">
                    ${badgeText}
                </div>
            </div>
        `;
    }).join('');

    if (noticeEl && selectedDate) {
        const selDay = days.find(d => d.date === selectedDate);
        if (selDay) {
            const dayDate = new Date(selectedDate + 'T00:00:00');
            if (dayDate.getDay() === 2) {
                noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">Tuesdays are reserved exclusively for PB Mediation. Select Mon, Wed, Thu, or Fri.</span>`;
            } else if (selDay.is_fully_booked || selDay.available <= 0) {
                noticeEl.innerHTML = `<span style="color: #dc2626; font-weight: 600;">Selected date is fully booked. Choose another conciliation day.</span>`;
            } else {
                noticeEl.innerHTML = `<span style="color: #15803d; font-weight: 600;">Conciliation date selected: ${selDay.available ?? 4} slots open.</span>`;
            }
        }
    }
}

async function checkReferScheduleConflict() {
    const dateInput = document.getElementById('referConciliationDate');
    const timeInput = document.getElementById('referConciliationTime');
    const venueInput = document.getElementById('referConciliationVenue');
    const conflictAlert = document.getElementById('referScheduleConflictAlert');
    const capacityBadge = document.getElementById('referCourtroomCapacityBadge');
    const caseId = stageWorkspaceState.caseId;

    if (!dateInput || !dateInput.value) return;

    const dateVal = dateInput.value;
    const timeVal = timeInput ? timeInput.value : '09:00';
    const venueVal = venueInput ? venueInput.value : 'Lupon Court Room';

    const params = new URLSearchParams();
    params.set('date', dateVal);
    params.set('time', timeVal);
    params.set('venue', venueVal);
    if (caseId) params.set('case_id', caseId);
    params.set('hearing_type', 'Conciliation');
    params.set('get_window', '1');

    try {
        const response = await fetch(getStageApiUrl(`summons/check-schedule.php?${params.toString()}`));
        const result = await response.json();

        if (result.window) {
            referCalendarWindowMeta = result.window;
            renderReferVisualDays(result.window, dateVal);
        }

        if (!response.ok || !result.success) {
            if (conflictAlert && result.message) {
                conflictAlert.textContent = `Schedule Notice: ${result.message}`;
                conflictAlert.style.display = 'block';
            }
            if (capacityBadge) {
                capacityBadge.style.background = '#fee2e2';
                capacityBadge.style.color = '#991b1b';
                capacityBadge.textContent = 'Conflict / Blocked';
            }
            return;
        }

        if (conflictAlert) {
            conflictAlert.style.display = 'none';
        }

        if (capacityBadge) {
            const avail = result.available_slots !== undefined ? result.available_slots : (4 - (result.total_booked || 0));
            const max = result.max_slots || 4;
            capacityBadge.style.background = avail > 0 ? '#dcfce7' : '#fee2e2';
            capacityBadge.style.color = avail > 0 ? '#15803d' : '#991b1b';
            capacityBadge.textContent = `${avail} of ${max} slots available`;
        }
    } catch (err) {
        console.warn('checkReferScheduleConflict error:', err);
    }
}

function openReferPangkatModal() {
    const modal = document.getElementById('wsReferPangkatModal');
    if (!modal) return;
    const dateInput = document.getElementById('referDate');
    if (dateInput && !dateInput.value) {
        dateInput.value = new Date().toISOString().split('T')[0];
    }
    populateLuponDropdowns();
    initReferCalendar();
    goReferralStep(1);
    modal.style.display = 'flex';
}

function closeReferPangkatModal() {
    const modal = document.getElementById('wsReferPangkatModal');
    if (modal) modal.style.display = 'none';
}

function goReferralStep(step) {
    if (step === 2) {
        const reason = document.getElementById('referReason')?.value.trim();
        const date = document.getElementById('referDate')?.value;
        const concDate = document.getElementById('referConciliationDate')?.value;
        const concTime = document.getElementById('referConciliationTime')?.value;
        const venue = document.getElementById('referConciliationVenue')?.value.trim();

        if (!date || !reason) {
            alert('Enter the referral date and authorized referral justification before continuing.');
            return;
        }
        if (!concDate) {
            alert('Please select a 1st Conciliation Hearing date.');
            return;
        }
        const dObj = new Date(concDate + 'T00:00:00');
        const dow = dObj.getDay();
        if (dow === 0 || dow === 6) {
            alert('Conciliation hearings cannot be scheduled on weekends.');
            return;
        }
        if (dow === 2) {
            alert('Tuesdays reserved exclusively for Punong Barangay Mediation. Conciliation hearings must be scheduled on non-Tuesday weekdays (Mondays, Wednesdays, Thursdays, or Fridays).');
            return;
        }
        if (!concTime) {
            alert('Please select a Conciliation time slot.');
            return;
        }
        if (!venue) {
            alert('Please provide a hearing venue.');
            return;
        }
    }

    if (step === 3) {
        const quorumSize = Number(document.getElementById('referQuorumSize')?.value || 3);
        const chairmanId = document.getElementById('referChairmanId')?.value;
        const secretaryId = document.getElementById('referSecretaryId')?.value;
        const memberId = document.getElementById('referMemberId')?.value;
        const quorumReason = document.getElementById('referQuorumReason')?.value.trim();

        if (quorumSize === 2) {
            if (!chairmanId || !secretaryId) {
                alert('Choose both a Chairperson and a Secretary for the 2-member quorum.');
                return;
            }
            if (chairmanId === secretaryId) {
                alert('Chairperson and Secretary must be different Lupon members.');
                return;
            }
            if (!quorumReason) {
                alert('Please provide a justification for the 2-member quorum exception.');
                return;
            }
        } else {
            const ids = [chairmanId, secretaryId, memberId];
            if (ids.some(id => !id) || new Set(ids).size !== 3) {
                alert('Choose three different active Lupon Members before reviewing the referral.');
                return;
            }
        }

        const nameFor = (id) => document.getElementById(id)?.selectedOptions?.[0]?.textContent || 'Not selected';
        const summary = document.getElementById('referReviewSummary');
        const concDate = document.getElementById('referConciliationDate')?.value || '';
        const concTime = document.getElementById('referConciliationTime')?.value || '';
        const venue = document.getElementById('referConciliationVenue')?.value || 'Lupon Court Room';
        const refDate = document.getElementById('referDate')?.value || '';
        const refReason = document.getElementById('referReason')?.value || '';
        const selMethod = document.getElementById('referSelectionMethod')?.value || 'Party Agreement';

        if (summary) {
            summary.innerHTML = `
                <div style="font-weight: 800; font-size: 0.95rem; margin-bottom: 8px; color: #1e3a8a;">
                    Step 3 of 3: Review Referral &amp; 1st Conciliation Booking
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">1st Conciliation Hearing</div>
                        <div style="font-weight: 700; color: #0f172a;">📅 ${escapeHtml(concDate)} at ${escapeHtml(concTime)}</div>
                        <div style="font-size: 0.8rem; color: #475569;">📍 ${escapeHtml(venue)}</div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Referral Parameters</div>
                        <div style="font-weight: 600; color: #0f172a;">Date: ${escapeHtml(refDate)}</div>
                        <div style="font-size: 0.8rem; color: #475569;">Method: ${escapeHtml(selMethod)}</div>
                    </div>
                </div>
                <div style="border-top: 1px solid #bfdbfe; padding-top: 8px; margin-top: 6px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700;">Pangkat Tagapagkasundo Panel (${quorumSize} Members)</div>
                    <div><strong>Head / Chair:</strong> ${escapeHtml(nameFor('referChairmanId'))}</div>
                    <div><strong>Secretary:</strong> ${escapeHtml(nameFor('referSecretaryId'))}</div>
                    <div><strong>Member:</strong> ${memberId ? escapeHtml(nameFor('referMemberId')) : '<em style="color:#64748b;">(Quorum Exception — 2 members)</em>'}</div>
                    ${quorumSize === 2 ? `<div style="font-size: 0.78rem; color: #b45309; margin-top: 3px;"><strong>Exception Justification:</strong> ${escapeHtml(quorumReason)}</div>` : ''}
                </div>
                <div style="font-size: 0.78rem; color: #475569; margin-top: 8px; font-style: italic;">
                    <strong>Statutory Note:</strong> Upon confirmation, case advances to Conciliation, KP Forms 10, 11, and 12 are generated, and Officer's Return records are registered for KP Form 12.
                </div>
            `;
        }
    }

    [1, 2, 3].forEach(pStep => {
        const pill = document.getElementById(`referStepPill${pStep}`);
        if (!pill) return;
        if (pStep === step) {
            pill.style.background = '#0284c7';
            pill.style.color = '#ffffff';
        } else if (pStep < step) {
            pill.style.background = '#e0f2fe';
            pill.style.color = '#0369a1';
        } else {
            pill.style.background = '#f1f5f9';
            pill.style.color = '#64748b';
        }
    });

    document.querySelectorAll('.referral-step').forEach((element) => {
        element.style.display = Number(element.dataset.referralStep) === Number(step) ? 'block' : 'none';
    });
}
window.goReferralStep = goReferralStep;

async function submitReferPangkat(e) {
    e.preventDefault();
    const btn = document.getElementById('submitReferBtn');
    if (btn) btn.disabled = true;

    const chairmanId = document.getElementById('referChairmanId')?.value;
    const secretaryId = document.getElementById('referSecretaryId')?.value;
    const memberId = document.getElementById('referMemberId')?.value;
    const quorumSize = Number(document.getElementById('referQuorumSize')?.value || 3);
    const quorumReason = document.getElementById('referQuorumReason')?.value.trim();

    if (quorumSize === 2) {
        if (!chairmanId || !secretaryId) {
            alert('Please select both Chairperson and Secretary.');
            if (btn) btn.disabled = false;
            return;
        }
        if (chairmanId === secretaryId) {
            alert('Chairperson and Secretary must be different Lupon members.');
            if (btn) btn.disabled = false;
            return;
        }
    } else {
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
    }

    const concDate = document.getElementById('referConciliationDate')?.value;
    const concTime = document.getElementById('referConciliationTime')?.value;
    const venue = document.getElementById('referConciliationVenue')?.value || 'Lupon Court Room';

    if (concDate) {
        const dObj = new Date(concDate + 'T00:00:00');
        if (dObj.getDay() === 2) {
            alert('Tuesdays reserved exclusively for Punong Barangay Mediation. Conciliation hearings must be scheduled on non-Tuesday weekdays (Mondays, Wednesdays, Thursdays, or Fridays).');
            if (btn) btn.disabled = false;
            return;
        }
        if (dObj.getDay() === 0 || dObj.getDay() === 6) {
            alert('Conciliation hearings cannot be scheduled on weekends.');
            if (btn) btn.disabled = false;
            return;
        }
    }

    const payload = {
        case_id: stageWorkspaceState.caseId,
        referral_date: document.getElementById('referDate')?.value || new Date().toISOString().split('T')[0],
        referral_reason: document.getElementById('referReason')?.value || 'Mediation before the Punong Barangay failed. Elevated to Pangkat Conciliation.',
        selection_method: document.getElementById('referSelectionMethod')?.value || 'Party Agreement',
        selection_notes: document.getElementById('referSelectionNotes')?.value || '',
        quorum_size: quorumSize,
        quorum_exception_reason: quorumReason,
        chairman_id: Number(chairmanId),
        secretary_id: Number(secretaryId),
        member_id: memberId ? Number(memberId) : null,
        conciliation_date: concDate || null,
        conciliation_time: concTime || null,
        venue: venue
    };

    try {
        const res = await fetch(getStageApiUrl('stages/refer-pangkat.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            notifyUser('Case successfully referred to Pangkat ng Tagapagkasundo. 1st Conciliation booked & KP Forms 10, 11, and 12 generated.', 'success');
            closeReferPangkatModal();
            setTimeout(() => {
                if (typeof openTriPanelPangkatModal === 'function') {
                    openTriPanelPangkatModal(stageWorkspaceState.caseId);
                }
                loadStageData();
            }, 300);
        } else {
            notifyUser(json.message || 'Unable to refer case to Pangkat.', 'error');
        }
    } catch (err) {
        console.error('submitReferPangkat error:', err);
        notifyUser('Network error during referral.', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * SECTION: Pangkat ng Tagapagkasundo Governance & Statutory Panel
 * -----------------------------------------------------------------------------
 */
function renderPangkatPanelSection(data, stage) {
    const container = document.getElementById('wsPangkatPanelContainer');
    if (!container) return;

    const pInfo = stage.pangkat_assignment || {};
    const caseId = stageWorkspaceState?.caseId || data?.case?.case_id || (new URLSearchParams(window.location.search)).get('case_id') || 0;
    const caseNumber = stageWorkspaceState?.caseNumber || data?.case?.case_number || (data?.case?.complaint_number || '');
    const members = pInfo.members || [];
    const challenges = pInfo.challenges || [];
    const extensions = pInfo.extensions || [];
    const dl = stage.deadline || {};

    const selectionMethod = pInfo.selection_method || 'Party Agreement';
    const isAgreementFailed = !!pInfo.party_agreement_failed;
    const failureReason = pInfo.party_agreement_failure_reason || '';

    // Selection method badge
    let selectionBadge = `<span style="background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 0.78rem;">Chosen by Parties</span>`;
    if (isAgreementFailed || selectionMethod === 'PB Assignment' || selectionMethod === 'Raffle Draw') {
        const methodLabel = selectionMethod === 'Raffle Draw' ? 'PB Drawing of Lots (Raffle)' : 'Punong Barangay Appointment';
        selectionBadge = `<span style="background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 0.78rem;">${escapeHtml(methodLabel)}</span>`;
    }

    // Pending challenges banner
    const pendingChallenges = challenges.filter(c => c.status === 'Pending');
    let pendingChalBanner = '';
    if (pendingChallenges.length > 0) {
        const pch = pendingChallenges[0];
        pendingChalBanner = `
            <div style="background: #fffbeb; border: 1px solid #fde68a; border-left: 5px solid #d97706; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <strong style="color: #92400e; font-size: 0.92rem; display: flex; align-items: center; gap: 6px;">
                            Active Challenge Against Pangkat Member Pending
                        </strong>
                        <p style="margin: 4px 0 0; font-size: 0.84rem; color: #78350f;">
                            <strong>${escapeHtml(pch.challenged_name || 'Member')}</strong> was challenged by ${escapeHtml(pch.challenger_party || 'Party')} on grounds of <strong>${escapeHtml(pch.grounds)}</strong>.
                            Under RA 7160 Sec. 404(d), this challenge must be decided by majority vote of the Pangkat panel.
                        </p>
                    </div>
                    <button type="button" class="btn-create" onclick="openResolveChallengeModal(${Number(pch.challenge_id)})" style="font-size: 0.8rem; padding: 7px 14px; background: #d97706;">
                        Deliberate &amp; Resolve Challenge &rarr;
                    </button>
                </div>
            </div>
        `;
    }

    // Render Members Cards
    let membersHtml = '';
    if (members.length) {
        membersHtml = members.map(m => {
            const isChallenged = m.status === 'Challenged';
            const isDisqualified = m.status === 'Disqualified';
            let statusBadge = `<span style="background: #dcfce7; color: #15803d; padding: 2px 7px; border-radius: 4px; font-size: 0.72rem; font-weight: 700;">Active</span>`;
            if (isChallenged) {
                statusBadge = `<span style="background: #fef3c7; color: #b45309; padding: 2px 7px; border-radius: 4px; font-size: 0.72rem; font-weight: 700;">Challenged</span>`;
            } else if (isDisqualified) {
                statusBadge = `<span style="background: #fee2e2; color: #991b1b; padding: 2px 7px; border-radius: 4px; font-size: 0.72rem; font-weight: 700;">Disqualified</span>`;
            }

            return `
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; flex: 1; min-width: 220px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;">
                                ${escapeHtml(m.position)}
                            </span>
                            ${statusBadge}
                        </div>
                        <strong style="font-size: 0.96rem; color: #0f172a; display: block;">${escapeHtml(m.member_name)}</strong>
                        <span style="font-size: 0.78rem; color: #64748b;">${escapeHtml(m.contact_number || m.email || 'Lupon Tagapagkasundo')}</span>
                    </div>
                    <div style="margin-top: 12px; padding-top: 8px; border-top: 1px dashed #e2e8f0; display: flex; justify-content: flex-end;">
                        ${!isDisqualified ? `
                            <button type="button" class="btn-secondary" onclick="openChallengeMemberModal(${Number(m.member_id)}, '${escapeHtml(m.member_name)}')" style="font-size: 0.74rem; padding: 4px 10px; color: #64748b;" title="Challenge this member for bias, interest, or relationship">
                                Challenge Member
                            </button>
                        ` : ''}
                    </div>
                </div>
            `;
        }).join('');
    } else {
        membersHtml = '<div style="color: #64748b; font-size: 0.86rem; padding: 10px 0;">No panel members assigned yet.</div>';
    }

    // Render Extensions List (if any)
    let extensionsHtml = '';
    if (extensions.length) {
        extensionsHtml = `
            <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9;">
                <span style="font-size: 0.74rem; font-weight: 800; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; display: block; margin-bottom: 8px;">
                    Controlled Extension Records (RA 7160 §410c)
                </span>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    ${extensions.map(e => `
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; font-size: 0.82rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                            <div>
                                <span style="font-weight: 700; color: #0369a1;">+${e.days_extended} Days (${escapeHtml(e.extension_type)})</span>
                                &middot; Extended from <strong>${formatDateReadable(e.previous_deadline)}</strong> to <strong style="color: #0f172a;">${formatDateReadable(e.new_deadline)}</strong>
                                <div style="margin-top: 3px; color: #475569;">
                                    <em>Justification:</em> "${escapeHtml(e.justification)}"
                                    ${e.meritorious_basis ? `<br><em>Statutory Basis:</em> "${escapeHtml(e.meritorious_basis)}"` : ''}
                                </div>
                            </div>
                            <span style="font-size: 0.76rem; color: #64748b;">Approved by ${escapeHtml(e.approver_name || 'Lupon')} on ${formatDateReadable(e.granted_at)}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    container.innerHTML = `
        <div class="stage-section-header">
            <div>
                <h3>Pangkat ng Tagapagkasundo &amp; Panel Governance</h3>
                <p>3-member panel chosen from the Lupon roster pursuant to RA 7160 Sections 404 &amp; 410</p>
            </div>
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                ${selectionBadge}
                <button type="button" class="btn-secondary" onclick="openPangkatSelectionModal()" style="font-size: 0.78rem; padding: 5px 12px; display: inline-flex; align-items: center; gap: 4px;">
                    Selection &amp; Replacement
                </button>
                <button type="button" class="btn-create" onclick="openPangkatExtensionModal()" style="font-size: 0.78rem; padding: 5px 12px; background: #0369a1; display: inline-flex; align-items: center; gap: 4px;">
                    Grant Extension
                </button>
            </div>
        </div>

        ${pendingChalBanner}

        ${isAgreementFailed ? `
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #f59e0b; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; font-size: 0.82rem;">
                <strong style="color: #b45309;">Party Agreement Impasse:</strong> Parties failed to reach agreement on Pangkat membership. The Punong Barangay determined membership via <strong>${escapeHtml(selectionMethod)}</strong> pursuant to RA 7160 Sec. 404(a).
                ${failureReason ? `<br><span style="color: #64748b;">Record: ${escapeHtml(failureReason)}</span>` : ''}
            </div>
        ` : ''}

        <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 4px;">
            ${membersHtml}
        </div>

        ${extensionsHtml}

        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                <span style="font-size: 0.74rem; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em;">Official Notices:</span>
                <button type="button" class="btn-secondary" onclick="openKpDocumentPreviewModal(${pInfo.kp10_document_id || 'null'}, 'KP Form 10', '${escapeHtml(caseNumber)}', ${caseId})" style="font-size: 0.74rem; padding: 4px 10px;" title="Preview KP Form 10 (Notice for Constitution of Pangkat)">
                    Preview KP Form 10
                </button>
                <button type="button" class="btn-secondary" onclick="openKpDocumentPreviewModal(${pInfo.kp11_document_id || 'null'}, 'KP Form 11', '${escapeHtml(caseNumber)}', ${caseId})" style="font-size: 0.74rem; padding: 4px 10px;" title="Preview KP Form 11 (Notice to Chosen Pangkat Members)">
                    Preview KP Form 11
                </button>
                <button type="button" class="btn-secondary" onclick="openKpDocumentPreviewModal(${pInfo.kp12_document_id || 'null'}, 'KP Form 12', '${escapeHtml(caseNumber)}', ${caseId})" style="font-size: 0.74rem; padding: 4px 10px;" title="Preview KP Form 12 (Notice of Conciliation Hearing)">
                    Preview KP Form 12
                </button>
            </div>
            <div>
                <button type="button" class="btn-secondary" onclick="openTriPanelPangkatModal(${caseId})" style="font-size: 0.74rem; padding: 4px 12px; font-weight: 600; color: #4f46e5; border-color: #c7d2fe; display: inline-flex; align-items: center; gap: 4px;">
                    Preview All Pangkat Notices (KP 10, 11, 12)
                </button>
            </div>
        </div>
    `;
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Pangkat Extension (Discretionary vs Clearly Meritorious)
 * -----------------------------------------------------------------------------
 */
function openPangkatExtensionModal() {
    const modal = document.getElementById('wsPangkatExtensionModal');
    if (!modal) return;

    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const dl = stage.deadline || {};
    const currDlEl = document.getElementById('extCurrentDeadline');
    if (currDlEl) {
        currDlEl.textContent = dl.target_date ? formatDateReadable(dl.target_date) : 'Pending Convening';
    }

    const daysInput = document.getElementById('extDays');
    if (daysInput) daysInput.value = '15';

    toggleExtensionType('Discretionary');
    updateProjectedDeadline();
    modal.style.display = 'flex';
}

function closePangkatExtensionModal() {
    const modal = document.getElementById('wsPangkatExtensionModal');
    if (modal) modal.style.display = 'none';
}

function toggleExtensionType(type) {
    const basisWrap = document.getElementById('extMeritoriousWrap');
    const noteEl = document.getElementById('extTypeNote');
    const daysInput = document.getElementById('extDays');

    if (type === 'Clearly Meritorious') {
        if (basisWrap) basisWrap.style.display = 'block';
        if (noteEl) noteEl.textContent = 'Under RA 7160 Sec. 410(c), extensions beyond 15 days require explicit clearly meritorious grounds (e.g. active boundary survey, pending court transmittal, extraordinary difficulty).';
    } else {
        if (basisWrap) basisWrap.style.display = 'none';
        if (noteEl) noteEl.textContent = 'Under RA 7160 Sec. 410(c), discretionary extension by the Pangkat cannot exceed fifteen (15) calendar days.';
        if (daysInput && Number(daysInput.value) > 15) daysInput.value = '15';
    }
    updateProjectedDeadline();
}

function updateProjectedDeadline() {
    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const dl = stage.deadline || {};
    const daysInput = document.getElementById('extDays');
    const projEl = document.getElementById('extProjectedDeadline');
    if (!daysInput || !projEl) return;

    const days = parseInt(daysInput.value, 10) || 0;
    const baseDateStr = dl.target_date || new Date().toISOString().split('T')[0];
    const baseDate = new Date(baseDateStr);
    baseDate.setDate(baseDate.getDate() + days);
    projEl.textContent = baseDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

async function submitPangkatExtension(e) {
    e.preventDefault();
    const btn = document.getElementById('submitExtBtn');
    if (btn) btn.disabled = true;

    const extType = document.querySelector('input[name="ext_type"]:checked')?.value || 'Discretionary';
    const days = parseInt(document.getElementById('extDays')?.value, 10) || 15;
    const justification = document.getElementById('extJustification')?.value || '';
    const meritoriousBasis = document.getElementById('extMeritoriousBasis')?.value || '';

    const payload = {
        case_id: stageWorkspaceState.caseId,
        extension_type: extType,
        days_extended: days,
        justification: justification,
        meritorious_basis: meritoriousBasis
    };

    try {
        const res = await fetch(getStageApiUrl('pangkat/extend.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closePangkatExtensionModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to grant extension.');
        }
    } catch (err) {
        console.error('submitPangkatExtension error:', err);
        alert('Network error while processing extension.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: File Challenge Against Member (RA 7160 §404d)
 * -----------------------------------------------------------------------------
 */
function openChallengeMemberModal(memberId, memberName) {
    const modal = document.getElementById('wsChallengeMemberModal');
    if (!modal) return;

    const select = document.getElementById('chalMemberId');
    if (select) {
        const stage = stageWorkspaceState.stageData?.conciliation || {};
        const members = stage.pangkat_assignment?.members || [];
        select.innerHTML = members.map(m => `
            <option value="${Number(m.member_id)}" ${memberId && Number(m.member_id) === Number(memberId) ? 'selected' : ''}>
                ${escapeHtml(m.member_name)} (${escapeHtml(m.position)})
            </option>
        `).join('');
    }

    const dateInput = document.getElementById('chalDate');
    if (dateInput && !dateInput.value) {
        dateInput.value = new Date().toISOString().split('T')[0];
    }

    modal.style.display = 'flex';
}

function closeChallengeMemberModal() {
    const modal = document.getElementById('wsChallengeMemberModal');
    if (modal) modal.style.display = 'none';
}

async function submitChallengeMember(e) {
    e.preventDefault();
    const btn = document.getElementById('submitChalBtn');
    if (btn) btn.disabled = true;

    const memberId = document.getElementById('chalMemberId')?.value;
    const party = document.getElementById('chalParty')?.value || 'Complainant';
    const name = document.getElementById('chalPartyName')?.value || '';
    const grounds = document.getElementById('chalGrounds')?.value || 'Bias or Partiality';
    const details = document.getElementById('chalDetails')?.value || '';
    const date = document.getElementById('chalDate')?.value || new Date().toISOString().split('T')[0];

    const payload = {
        case_id: stageWorkspaceState.caseId,
        challenged_member_id: Number(memberId),
        challenger_party: party,
        challenger_name: name,
        grounds: grounds,
        ground_details: details,
        challenge_date: date
    };

    try {
        const res = await fetch(getStageApiUrl('pangkat/challenge.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closeChallengeMemberModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to record challenge.');
        }
    } catch (err) {
        console.error('submitChallengeMember error:', err);
        alert('Network error while filing challenge.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Resolve Member Challenge (Pangkat Majority Vote)
 * -----------------------------------------------------------------------------
 */
function openResolveChallengeModal(challengeId) {
    const modal = document.getElementById('wsResolveChallengeModal');
    if (!modal) return;

    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const challenges = stage.pangkat_assignment?.challenges || [];
    const chal = challenges.find(c => Number(c.challenge_id) === Number(challengeId));

    if (!chal) {
        alert('Challenge record not found.');
        return;
    }

    document.getElementById('resChallengeId').value = challengeId;
    const summaryEl = document.getElementById('resChalSummary');
    if (summaryEl) {
        summaryEl.innerHTML = `
            <strong>Challenged Member:</strong> ${escapeHtml(chal.challenged_name)}<br>
            <strong>Challenger:</strong> ${escapeHtml(chal.challenger_party)} ${chal.challenger_name ? `(${escapeHtml(chal.challenger_name)})` : ''}<br>
            <strong>Grounds:</strong> ${escapeHtml(chal.grounds)}<br>
            <strong>Particulars:</strong> ${escapeHtml(chal.ground_details)}
        `;
    }

    // Populate replacement dropdown with Lupon Members excluding existing active members
    const repSelect = document.getElementById('resReplacementId');
    if (repSelect) {
        const activeIds = new Set((stage.pangkat_assignment?.members || []).map(m => Number(m.member_id)));
        const availableLupon = (stageWorkspaceState.luponMembers || []).filter(u => !activeIds.has(Number(u.user_id || u.member_id)));
        repSelect.innerHTML = '<option value="">-- Choose Replacement Lupon Member --</option>' +
            availableLupon.map(u => `
                <option value="${Number(u.user_id || u.member_id)}">
                    ${escapeHtml(u.last_name || '')}, ${escapeHtml(u.first_name || '')}
                </option>
            `).join('');
    }

    toggleDecisionReplacement('Disqualify and Replace');
    modal.style.display = 'flex';
}

function closeResolveChallengeModal() {
    const modal = document.getElementById('wsResolveChallengeModal');
    if (modal) modal.style.display = 'none';
}

function toggleDecisionReplacement(decision) {
    const wrap = document.getElementById('resReplacementWrap');
    if (wrap) {
        wrap.style.display = (decision === 'Disqualify and Replace') ? 'block' : 'none';
    }
}

async function submitResolveChallenge(e) {
    e.preventDefault();
    const btn = document.getElementById('submitResBtn');
    if (btn) btn.disabled = true;

    const challengeId = document.getElementById('resChallengeId')?.value;
    const decision = document.querySelector('input[name="res_decision"]:checked')?.value || 'Disqualify and Replace';
    const notes = document.getElementById('resNotes')?.value || '';
    const voting = document.getElementById('resVoting')?.value || 'Panel Majority Decision';
    const repId = document.getElementById('resReplacementId')?.value;
    const repMethod = document.getElementById('resRepMethod')?.value || 'Drawing of Lots';

    if (decision === 'Disqualify and Replace' && !repId) {
        alert('Please designate a replacement Lupon Member when sustaining a disqualification challenge.');
        if (btn) btn.disabled = false;
        return;
    }

    const payload = {
        challenge_id: Number(challengeId),
        majority_decision: decision,
        majority_decision_notes: notes,
        voting_record: voting,
        replacement_member_id: repId ? Number(repId) : null,
        replacement_method: repMethod,
        resolution_date: new Date().toISOString().split('T')[0]
    };

    try {
        const res = await fetch(getStageApiUrl('pangkat/resolve-challenge.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closeResolveChallengeModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to resolve challenge.');
        }
    } catch (err) {
        console.error('submitResolveChallenge error:', err);
        alert('Network error while resolving challenge.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/**
 * -----------------------------------------------------------------------------
 * MODAL: Pangkat Selection & Impasse Modeling (RA 7160 §404)
 * -----------------------------------------------------------------------------
 */
function openPangkatSelectionModal() {
    const modal = document.getElementById('wsPangkatSelectionModal');
    if (!modal) return;

    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const pInfo = stage.pangkat_assignment || {};

    populateSelectionDropdowns();

    const failedCheck = document.getElementById('selAgreementFailed');
    if (failedCheck) {
        failedCheck.checked = !!pInfo.party_agreement_failed;
        togglePartyAgreementFailed(failedCheck);
    }

    const reasonEl = document.getElementById('selFailureReason');
    if (reasonEl) reasonEl.value = pInfo.party_agreement_failure_reason || '';

    const notesEl = document.getElementById('selNotes');
    if (notesEl) notesEl.value = pInfo.selection_notes || '';

    modal.style.display = 'flex';
}

function closePangkatSelectionModal() {
    const modal = document.getElementById('wsPangkatSelectionModal');
    if (modal) modal.style.display = 'none';
}

function togglePartyAgreementFailed(checkbox) {
    const wrap = document.getElementById('selFailureWrap');
    if (wrap) {
        wrap.style.display = checkbox.checked ? 'block' : 'none';
    }
}

function populateSelectionDropdowns() {
    const stage = stageWorkspaceState.stageData?.conciliation || {};
    const pInfo = stage.pangkat_assignment || {};
    const lupon = stageWorkspaceState.luponMembers || [];

    const selects = [
        { id: 'selChairmanId', current: pInfo.chairperson?.member_id },
        { id: 'selSecretaryId', current: pInfo.secretary?.member_id },
        { id: 'selMemberId', current: pInfo.member?.member_id }
    ];

    selects.forEach(({ id, current }) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = '<option value="">-- Select Lupon Member --</option>' +
            lupon.map(m => `
                <option value="${Number(m.user_id || m.member_id)}" ${current && Number(m.user_id || m.member_id) === Number(current) ? 'selected' : ''}>
                    ${escapeHtml(m.last_name || '')}, ${escapeHtml(m.first_name || '')}
                </option>
            `).join('');
    });
}

async function submitPangkatSelection(e) {
    e.preventDefault();
    const btn = document.getElementById('submitSelBtn');
    if (btn) btn.disabled = true;

    const chairmanId = document.getElementById('selChairmanId')?.value;
    const secretaryId = document.getElementById('selSecretaryId')?.value;
    const memberId = document.getElementById('selMemberId')?.value;

    if (!chairmanId || !secretaryId || !memberId) {
        alert('Please select all 3 panel positions (Chairman, Secretary, and Member).');
        if (btn) btn.disabled = false;
        return;
    }

    if (new Set([chairmanId, secretaryId, memberId]).size !== 3) {
        alert('The 3 panel members must all be distinct Lupon members.');
        if (btn) btn.disabled = false;
        return;
    }

    const failedAgreement = document.getElementById('selAgreementFailed')?.checked ? 1 : 0;
    const pbMethod = document.getElementById('selPbMethod')?.value || 'Drawing of Lots';
    const method = failedAgreement ? (pbMethod === 'Drawing of Lots' ? 'Raffle Draw' : 'PB Assignment') : 'Party Agreement';

    const payload = {
        case_id: stageWorkspaceState.caseId,
        selection_method: method,
        selection_stage: failedAgreement ? (pbMethod === 'Drawing of Lots' ? 'Failed Agreement - PB Lots' : 'Failed Agreement - PB Appointment') : 'Party Choice',
        party_agreement_failed: failedAgreement,
        party_agreement_failure_reason: document.getElementById('selFailureReason')?.value || '',
        selection_notes: document.getElementById('selNotes')?.value || '',
        chairman_id: Number(chairmanId),
        secretary_id: Number(secretaryId),
        member_id: Number(memberId),
        formation_date: new Date().toISOString().split('T')[0]
    };

    try {
        const res = await fetch(getStageApiUrl('pangkat/selection.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.success) {
            alert(json.message);
            closePangkatSelectionModal();
            await loadStageData();
        } else {
            alert(json.message || 'Unable to save Pangkat selection.');
        }
    } catch (err) {
        console.error('submitPangkatSelection error:', err);
        alert('Network error while saving selection.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// Global window exposure for inline and modal DOM events
window.toggleStageVoiceRecording = toggleStageVoiceRecording;
window.triggerStageNotesUpload = triggerStageNotesUpload;
window.handleStageNotesFileSelected = handleStageNotesFileSelected;
window.openPreviewMinutesModal = openPreviewMinutesModal;
window.closePreviewMinutesModal = closePreviewMinutesModal;
window.printHearingMinutes = printHearingMinutes;
window.openScheduleModal = openScheduleModal;
window.closeScheduleModal = closeScheduleModal;
if (typeof initWorkspaceScheduleModal !== 'undefined') window.initWorkspaceScheduleModal = initWorkspaceScheduleModal;
if (typeof loadStageSlots !== 'undefined') window.loadStageSlots = loadStageSlots;
window.openAttendanceModal = openAttendanceModal;
window.closeAttendanceModal = closeAttendanceModal;
if (typeof openMinutesModal !== 'undefined') window.openMinutesModal = openMinutesModal;
if (typeof closeMinutesModal !== 'undefined') window.closeMinutesModal = closeMinutesModal;
if (typeof saveMinutes !== 'undefined') window.saveMinutes = saveMinutes;
window.saveHearingMinutes = saveHearingMinutes;
window.saveInlineMinutes = saveHearingMinutes;
window.openRecordOutcomeModal = openRecordOutcomeModal;
window.closeRecordOutcomeModal = closeRecordOutcomeModal;
window.openReferPangkatModal = openReferPangkatModal;
window.closeReferPangkatModal = closeReferPangkatModal;
window.initReferCalendar = initReferCalendar;
window.handleReferDateInputChange = handleReferDateInputChange;
window.selectReferConciliationSlot = selectReferConciliationSlot;
window.selectReferVisualDate = selectReferVisualDate;
window.renderReferVisualDays = renderReferVisualDays;
window.checkReferScheduleConflict = checkReferScheduleConflict;
window.goReferralStep = goReferralStep;
window.submitReferPangkat = submitReferPangkat;

// Pangkat statutory extensions and challenges exposure
window.openPangkatExtensionModal = openPangkatExtensionModal;
window.closePangkatExtensionModal = closePangkatExtensionModal;
window.toggleExtensionType = toggleExtensionType;
window.updateProjectedDeadline = updateProjectedDeadline;
window.submitPangkatExtension = submitPangkatExtension;
window.openChallengeMemberModal = openChallengeMemberModal;
window.closeChallengeMemberModal = closeChallengeMemberModal;
window.submitChallengeMember = submitChallengeMember;
window.openResolveChallengeModal = openResolveChallengeModal;
window.closeResolveChallengeModal = closeResolveChallengeModal;
window.toggleDecisionReplacement = toggleDecisionReplacement;
window.submitResolveChallenge = submitResolveChallenge;
window.openPangkatSelectionModal = openPangkatSelectionModal;
window.closePangkatSelectionModal = closePangkatSelectionModal;
window.togglePartyAgreementFailed = togglePartyAgreementFailed;
window.submitPangkatSelection = submitPangkatSelection;

// Attendance & Form preview exposure
window.openKPFormPreview = openKpFormModal;
if (typeof togglePresiderSubstituteSection !== 'undefined') window.togglePresiderSubstituteSection = togglePresiderSubstituteSection;
if (typeof handlePresiderAbsenceChoiceChange !== 'undefined') window.handlePresiderAbsenceChoiceChange = handlePresiderAbsenceChoiceChange;
if (typeof executeEmergencyReschedule !== 'undefined') window.executeEmergencyReschedule = executeEmergencyReschedule;
if (typeof getSuggestedRescheduleDateTime !== 'undefined') window.getSuggestedRescheduleDateTime = getSuggestedRescheduleDateTime;
if (typeof submitAttendance !== 'undefined') window.submitAttendance = submitAttendance;

// ========================================================
// COURT & DILG TRANSMITTAL MODULE (RA 7160 SEC. 411)
// ========================================================

async function loadTransmittalCard(caseId) {
    const container = document.getElementById('wsTransmittalCardContainer');
    if (!container) return;

    try {
        const res = await fetch(`../../../backend/api/transmittals/status.php?case_id=${encodeURIComponent(caseId)}`);
        const json = await res.json();
        if (!res.ok || !json.success) {
            container.innerHTML = `
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; font-size: 0.84rem; color: #64748b;">
                    Unable to load Court Transmittal status.
                </div>
            `;
            return;
        }

        const data = json.data || {};
        stageWorkspaceState.transmittalData = data;

        let badgeStyle = 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;';
        if (data.status === 'Transmitted') {
            badgeStyle = 'background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;';
        } else if (data.status === 'Acknowledged') {
            badgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;';
        } else if (data.is_repudiation_window_open) {
            badgeStyle = 'background: #fef3c7; color: #92400e; border: 1px solid #fde68a;';
        } else {
            badgeStyle = 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;';
        }

        const receivingCopyHtml = data.receiving_copy_path ? `
            <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #cbd5e1; font-size: 0.82rem; color: #1e40af;">
                <strong>Stamped Receiving Copy on File:</strong> 
                <a href="../../../${escapeHtml(data.receiving_copy_path)}" target="_blank" style="color: #0369a1; text-decoration: underline; font-weight: 600;">
                    View Stamped Court / DILG Copy &rarr;
                </a>
            </div>
        ` : '';

        const receiverDetailsHtml = (data.status === 'Transmitted' || data.status === 'Acknowledged') ? `
            <div style="margin-top: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; font-size: 0.82rem; color: #334155; line-height: 1.55;">
                <div style="margin-bottom: 2px;">
                    <strong>Transmitted on:</strong> ${escapeHtml(data.transmittal_date || 'Logged')} &bull; 
                    <strong>Target:</strong> ${escapeHtml(data.target_agency_label || 'Both MTC & DILG')}
                </div>
                ${data.mtc_receiver_name ? `<div><strong>MTCC Receiver:</strong> ${escapeHtml(data.mtc_receiver_name)} ${data.mtc_received_date ? `(Received: ${escapeHtml(data.mtc_received_date)})` : ''}</div>` : ''}
                ${data.dilg_receiver_name ? `<div><strong>DILG Receiver:</strong> ${escapeHtml(data.dilg_receiver_name)} ${data.dilg_received_date ? `(Received: ${escapeHtml(data.dilg_received_date)})` : ''}</div>` : ''}
                ${data.remarks ? `<div style="margin-top: 3px; color: #64748b; font-style: italic;">"${escapeHtml(data.remarks)}"</div>` : ''}
            </div>
        ` : '';

        container.innerHTML = `
            <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 18px; margin-top: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <h4 style="margin: 0; font-size: 0.98rem; color: #0f172a; font-weight: 700;">
                                MTC &amp; DILG Court Transmittal Module
                            </h4>
                            <span style="font-size: 0.76rem; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; font-weight: 600;">
                                Ref: ${escapeHtml(data.transmittal_number || 'TR')}
                            </span>
                        </div>
                        <p style="margin: 3px 0 0; font-size: 0.8rem; color: #64748b;">
                            Statutory transmittal to Clerk of Court (MTCC Marikina) and DILG Field Office pursuant to RA 7160 Sec. 411.
                        </p>
                    </div>
                    <div>
                        <span style="display: inline-block; padding: 4px 10px; border-radius: 9999px; font-weight: 700; font-size: 0.78rem; ${badgeStyle}">
                            ${escapeHtml(data.badge_label || data.status)}
                        </span>
                    </div>
                </div>

                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; font-size: 0.84rem; color: #334155; line-height: 1.55;">
                    <div>
                        Settlement signed on <strong>${escapeHtml(data.settlement_date_formatted || data.settlement_date)}</strong>. 
                        10-day working period ends on <strong>${escapeHtml(data.ten_day_expiry_date_formatted || data.ten_day_expiry_date)}</strong> 
                        <span style="color: #64748b;">(excluding weekends &amp; statutory Philippine holidays)</span>.
                    </div>
                    ${data.is_repudiation_window_open ? `
                        <div style="margin-top: 5px; color: #b45309; font-weight: 600;">
                            Repudiation countdown: ${data.working_days_remaining} working day${data.working_days_remaining === 1 ? '' : 's'} remaining before formal transmittal is prescribed.
                        </div>
                    ` : `
                        <div style="margin-top: 5px; color: #15803d; font-weight: 600;">
                            10 working days elapsed with no repudiation filed. Amicable settlement now carries the force and effect of a final court judgment under Section 411.
                        </div>
                    `}
                </div>

                ${receiverDetailsHtml}
                ${receivingCopyHtml}

                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" onclick="openPreviewTransmittalModal(${Number(caseId)})" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer;">
                        Preview / Print Transmittal Letter
                    </button>
                    <button type="button" class="btn-create" onclick="openRecordTransmittalModal(${Number(caseId)})" style="font-size: 0.82rem; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; background: #0f766e;">
                        Record Court / DILG Submission
                    </button>
                </div>
            </div>
        `;
    } catch (err) {
        console.error('Failed to load transmittal card:', err);
    }
}

function openPreviewTransmittalModal(caseId) {
    if (typeof unlockOutcomesTab === 'function') unlockOutcomesTab(caseId);
    const modal = document.getElementById('previewTransmittalModal');
    const iframe = document.getElementById('transmittalLetterPreviewIframe');
    const downloadBtn = document.getElementById('btnDownloadTransmittalPdf');
    if (!modal) return;

    if (iframe) {
        iframe.src = `../../../backend/api/transmittals/preview.php?case_id=${encodeURIComponent(caseId)}&html=1`;
    }
    if (downloadBtn) {
        downloadBtn.href = `../../../backend/api/transmittals/preview.php?case_id=${encodeURIComponent(caseId)}&download=1`;
    }

    modal.style.display = 'flex';
}

function closePreviewTransmittalModal() {
    const modal = document.getElementById('previewTransmittalModal');
    if (modal) modal.style.display = 'none';
    const iframe = document.getElementById('transmittalLetterPreviewIframe');
    if (iframe) iframe.src = 'about:blank';
}

function printTransmittalIframe() {
    const iframe = document.getElementById('transmittalLetterPreviewIframe');
    if (iframe && iframe.contentWindow) {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }
}

function openRecordTransmittalModal(caseId) {
    const modal = document.getElementById('recordTransmittalModal');
    if (!modal) return;

    const caseIdInput = document.getElementById('transmittalCaseId');
    if (caseIdInput) caseIdInput.value = caseId;

    const data = stageWorkspaceState.transmittalData || {};
    const alertBox = document.getElementById('transmittalModalRepudiationAlert');
    if (alertBox) {
        if (data.is_repudiation_window_open) {
            alertBox.innerHTML = `
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 12px; font-size: 0.82rem; color: #92400e; line-height: 1.45;">
                    <strong>Notice (RA 7160 Sec. 411):</strong> The 10-working-day repudiation window is currently active 
                    (<strong>${data.working_days_remaining} working day${data.working_days_remaining === 1 ? '' : 's'} remaining</strong> until ${data.ten_day_expiry_date_formatted}). 
                    Formal transmittal is legally prescribed upon lapse of this period without repudiation.
                </div>
            `;
            alertBox.style.display = 'block';
        } else {
            alertBox.innerHTML = `
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 12px; font-size: 0.82rem; color: #166534; line-height: 1.45;">
                    <strong>Statutory Repudiation Window Elapsed:</strong> No repudiation has been recorded. This settlement carries the force and effect of a final court judgment and is ready for court &amp; DILG transmittal.
                </div>
            `;
            alertBox.style.display = 'block';
        }
    }

    const dateInput = document.getElementById('transmittalDate');
    if (dateInput) dateInput.value = data.transmittal_date || new Date().toISOString().slice(0, 10);

    const agencySelect = document.getElementById('transmittalTargetAgency');
    if (agencySelect) agencySelect.value = data.target_agency || 'Both';

    const mtcReceiver = document.getElementById('transmittalMtcReceiver');
    if (mtcReceiver) mtcReceiver.value = data.mtc_receiver_name || '';

    const mtcDate = document.getElementById('transmittalMtcDate');
    if (mtcDate) mtcDate.value = data.mtc_received_date || (data.transmittal_date || new Date().toISOString().slice(0, 10));

    const dilgReceiver = document.getElementById('transmittalDilgReceiver');
    if (dilgReceiver) dilgReceiver.value = data.dilg_receiver_name || '';

    const dilgDate = document.getElementById('transmittalDilgDate');
    if (dilgDate) dilgDate.value = data.dilg_received_date || (data.transmittal_date || new Date().toISOString().slice(0, 10));

    const remarksText = document.getElementById('transmittalRemarks');
    if (remarksText) remarksText.value = data.remarks || '';

    const badge = document.getElementById('transmittalCurrentFileBadge');
    if (badge) {
        if (data.receiving_copy_path) {
            badge.innerHTML = `Current Stamped File: <a href="../../../${escapeHtml(data.receiving_copy_path)}" target="_blank" style="text-decoration: underline;">View Uploaded Copy</a>`;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    }

    modal.style.display = 'flex';
}

function closeRecordTransmittalModal() {
    const modal = document.getElementById('recordTransmittalModal');
    if (modal) modal.style.display = 'none';
}

async function handleRecordTransmittalSubmit(event) {
    event.preventDefault();
    const form = document.getElementById('recordTransmittalForm');
    const submitBtn = document.getElementById('btnSubmitTransmittal');
    if (!form) return;

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
    }

    const formData = new FormData(form);

    try {
        const res = await fetch('../../../backend/api/transmittals/submit.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Failed to record transmittal.');
        }

        notifyUser(json.message || 'Court & DILG transmittal recorded successfully!', 'success');
        closeRecordTransmittalModal();
        if (stageWorkspaceState.caseId) {
            loadTransmittalCard(stageWorkspaceState.caseId);
        }
    } catch (err) {
        alert('Error: ' + err.message);
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Transmittal Record';
        }
    }
}

window.loadTransmittalCard = loadTransmittalCard;
window.openPreviewTransmittalModal = openPreviewTransmittalModal;
window.closePreviewTransmittalModal = closePreviewTransmittalModal;
window.printTransmittalIframe = printTransmittalIframe;
window.openRecordTransmittalModal = openRecordTransmittalModal;
window.closeRecordTransmittalModal = closeRecordTransmittalModal;
window.handleRecordTransmittalSubmit = handleRecordTransmittalSubmit;


function openPrintBlankMinutes(caseId, complaintId) {
    const csId = caseId || stageWorkspaceState.caseId || 0;
    const cmpId = complaintId || stageWorkspaceState.complaintId || 0;
    window.open(`../../../frontend/pages/complaints/print-blank-minutes.php?case_id=${encodeURIComponent(csId)}&id=${encodeURIComponent(cmpId)}`, '_blank');
}
window.openPrintBlankMinutes = openPrintBlankMinutes;

function openPrintTransmittal(caseId) {
    const csId = caseId || stageWorkspaceState.caseId || 0;
    const cmpId = stageWorkspaceState.complaintId || 0;
    window.open(`../../../frontend/pages/cases/transmittal-print.php?case_id=${encodeURIComponent(csId)}&complaint_id=${encodeURIComponent(cmpId)}`, '_blank');
}
window.openPrintTransmittal = openPrintTransmittal;

window.openEmbeddedScheduleModal = function(stageType = 'Mediation') {
    if (typeof openScheduleModal === 'function') {
        openScheduleModal(null, stageType);
    }
};
