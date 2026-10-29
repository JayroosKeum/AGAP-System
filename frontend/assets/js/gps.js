const proofApiUrl = '../../../backend/api/gps/proof-service.php';

function escapeGpsHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeGpsJsArgument(value) {
    return JSON.stringify(String(value ?? ''))
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

async function gpsApi(url) {
    const separator = url.includes('?') ? '&' : '?';

    const response = await fetch(
        `${url}${separator}_t=${Date.now()}`,
        {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json'
            }
        }
    );

    let data;

    try {
        data = await response.json();
    } catch (error) {
        throw new Error('The server returned an invalid response.');
    }

    if (!response.ok || data.success === false) {
        throw new Error(data.message || 'Unable to load proof-of-service details.');
    }

    return data;
}

function setGpsMessage(message = '', type = 'error') {
    const target = document.getElementById('proofMessage');
    if (!target) return;

    target.textContent = message;
    target.className = message
        ? `proof-message is-visible ${type === 'success' ? 'is-success' : 'is-error'}`
        : 'proof-message';

    if (message && typeof window.agapNotify === 'function') {
        window.agapNotify(message, type === 'success' ? 'success' : 'error', 'Proof of Service');
    }
}

function formatDateTime(value) {
    if (!value) return 'Not recorded';

    const normalized = String(value).includes('T')
        ? String(value)
        : String(value).replace(' ', 'T');

    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: 'numeric',
        minute: '2-digit'
    }).format(date);
}

function serviceResultClass(result) {
    const normalized = String(result || '').toLowerCase();
    if (normalized === 'served') return 'badge-served';
    if (normalized === 'not served' || normalized === 'unserved') return 'badge-not-served';
    if (normalized === 'refused') return 'badge-refused';
    if (normalized === 'respondent not found' || normalized === 'address problem') return 'badge-warning';
    return 'badge-other';
}

function serviceStatusClass(status) {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'served') return 'badge-served';
    if (normalized === 'service failed' || normalized === 'unserved') return 'badge-not-served';
    if (normalized === 'for service' || normalized === 'pending') return 'badge-warning';
    return 'badge-other';
}

function setEmptyState(tableId, colspan, message) {
    const table = document.getElementById(tableId);
    if (!table) return;

    table.innerHTML = `
        <tr>
            <td colspan="${colspan}" class="empty-state" style="padding: 30px; text-align: center; color: #94a3b8;">
                ${escapeGpsHtml(message)}
            </td>
        </tr>
    `;
}

function pluralize(count, singular, plural = `${singular}s`) {
    return `${count} ${count === 1 ? singular : plural}`;
}

function updateCounts(noticeCount = 0, historyCount = 0) {
    const notices = document.getElementById('noticeCount');
    const history = document.getElementById('historyCount');
    if (notices) notices.textContent = pluralize(noticeCount, 'notice');
    if (history) history.textContent = pluralize(historyCount, 'attempt');
}

function resetCaseView() {
    activeExpandedCaseId = null;
    updateCounts(0, 0);
}

/* ==========================================================================
   State Management & Metrics (Tasks 1, 2, 3, 8)
   ========================================================================== */

let allLoadedSummonCases = [];
let filteredSummonCases = [];
let activeFilterTab = 'all'; // 'all' | 'pending' | 'cfa_ready'
let activeExpandedCaseId = null;
const summonPageSize = 5;
let currentSummonPage = 1;
let activeSummonSortKey = 'hearing_date';
let activeSummonSortDir = 'asc';

function isCasePending(item) {
    const compStatus = String(item.complainant_service_status || 'Pending');
    const compServed = compStatus.startsWith('Served');
    const respServed = Number(item.respondent_served_attempts || 0) > 0 || String(item.respondent_service_status || '').startsWith('Served');
    const respFailed = Number(item.respondent_failed_attempts || 0);

    // Pending delivery if either party's service obligation is incomplete
    const maxAttempts = Number(window.AGAP_SETTINGS?.summons_max_attempts || 6);
    return (!compServed) || (!respServed && respFailed < maxAttempts);
}

function isCaseCfaReady(item) {
    if (item && item.is_paanyaya) return false;
    const respFailed = Number(item.respondent_failed_attempts || 0);
    const respServed = Number(item.respondent_served_attempts || 0);
    const respStatus = String(item.respondent_service_status || '');
    const caseStatus = String(item.case_status || '');

    const maxAttempts = Number(window.AGAP_SETTINGS?.summons_max_attempts || 6);
    return respFailed >= maxAttempts && respServed === 0 && respStatus === 'Unserved' && caseStatus !== 'Archived' && caseStatus !== 'Settled';
}

function updateSummaryMetrics() {
    const total = allLoadedSummonCases.length;
    const pendingCount = allLoadedSummonCases.filter(isCasePending).length;
    const servedCount = allLoadedSummonCases.filter(c => !isCasePending(c) && !isCaseCfaReady(c)).length;

    // Filter Tab Count Badges
    const countAll = document.getElementById('countAllCases');
    const countPending = document.getElementById('countPendingDelivery');
    const countServed = document.getElementById('countServedDelivery');

    if (countAll) countAll.textContent = total;
    if (countPending) countPending.textContent = pendingCount;
    if (countServed) countServed.textContent = servedCount;
}

function setProofFilter(filterKey) {
    activeFilterTab = (filterKey === 'pending' || filterKey === 'served' || filterKey === 'cfa_ready') ? filterKey : 'all';

    // Update Tab Buttons UI
    document.querySelectorAll('.proof-tab-btn').forEach(btn => {
        const isMatch = (btn.dataset.filter === activeFilterTab) ||
            (activeFilterTab === 'all' && btn.id === 'tabFilterAll') ||
            (activeFilterTab === 'pending' && btn.id === 'tabFilterPending') ||
            (activeFilterTab === 'served' && btn.id === 'tabFilterServed');
        btn.classList.toggle('active', isMatch);
        btn.setAttribute('aria-selected', isMatch ? 'true' : 'false');
    });

    currentSummonPage = 1;
    applySummonFilterAndRender();
}
window.setProofFilter = setProofFilter;

function setupProofFilterTabs() {
    document.querySelectorAll('.proof-tab-btn').forEach(btn => {
        if (btn.dataset.filterBound) return;
        btn.dataset.filterBound = '1';
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const filter = btn.dataset.filter || (btn.id === 'tabFilterPending' ? 'pending' : (btn.id === 'tabFilterServed' ? 'served' : 'all'));
            setProofFilter(filter);
        });
    });
}

function sortSummonCases(cases, key, dir) {
    const mult = (dir === 'asc') ? 1 : -1;
    cases.sort((a, b) => {
        switch (key) {
            case 'kp_number':
            case 'case_no': {
                const noA = String(a.case_number || a.case_id || '').toLowerCase();
                const noB = String(b.case_number || b.case_id || '').toLowerCase();
                if (!noA && noB) return 1;
                if (!noA && !noB) return -1;
                return noA.localeCompare(noB, undefined, { numeric: true, sensitivity: 'base' }) * mult;
            }
            case 'complainant': {
                const cA = String(a.complainant_names || '').toLowerCase();
                const cB = String(b.complainant_names || '').toLowerCase();
                if (!cA && cB) return 1;
                if (cA && !cB) return -1;
                return cA.localeCompare(cB) * mult;
            }
            case 'respondent': {
                const rA = String(a.respondent_names || '').toLowerCase();
                const rB = String(b.respondent_names || '').toLowerCase();
                if (!rA && rB) return 1;
                if (rA && !rB) return -1;
                return rA.localeCompare(rB) * mult;
            }
            case 'hearing_date': {
                const dA = a.hearing_date ? new Date(String(a.hearing_date).replace(' ', 'T')).getTime() : 0;
                const dB = b.hearing_date ? new Date(String(b.hearing_date).replace(' ', 'T')).getTime() : 0;
                if (!dA && dB) return 1;
                if (dA && !dB) return -1;
                if (dA === dB) {
                    return (Number(b.case_id || 0) - Number(a.case_id || 0)) * mult;
                }
                return (dA - dB) * mult;
            }
            case 'service_status': {
                const getStatusWeight = (item) => {
                    if (isCaseCfaReady(item)) return 1;
                    if (isCasePending(item)) return 2;
                    return 3; // Served
                };
                const wA = getStatusWeight(a);
                const wB = getStatusWeight(b);
                if (wA !== wB) return (wA - wB) * mult;
                return (Number(b.case_id || 0) - Number(a.case_id || 0)) * mult;
            }
            default:
                return 0;
        }
    });
}

function updateSummonSortHeadersUI() {
    const headers = document.querySelectorAll('#casesNeedingSummonsSection .sortable-th');
    headers.forEach(th => {
        const key = th.dataset.sortKey;
        const ind = document.getElementById(`sortInd_summon_${key}`);
        th.classList.remove('sort-active-asc', 'sort-active-desc');

        if (key === activeSummonSortKey) {
            th.classList.add(`sort-active-${activeSummonSortDir}`);
            if (ind) ind.textContent = (activeSummonSortDir === 'asc') ? '▲' : '▼';
        } else {
            if (ind) ind.textContent = '⇅';
        }
    });
}

function setupSummonTableSorting() {
    const headers = document.querySelectorAll('#casesNeedingSummonsSection .sortable-th');
    headers.forEach(th => {
        if (th.dataset.sortBound) return;
        th.dataset.sortBound = '1';

        th.addEventListener('click', () => {
            const key = th.dataset.sortKey;
            if (!key) return;

            if (activeSummonSortKey === key) {
                activeSummonSortDir = (activeSummonSortDir === 'asc') ? 'desc' : 'asc';
            } else {
                activeSummonSortKey = key;
                activeSummonSortDir = (key === 'kp_number') ? 'desc' : 'asc';
            }

            updateSummonSortHeadersUI();
            currentSummonPage = 1;
            applySummonFilterAndRender();
        });
    });
}

function renderSummonPaginationControls(total, totalPages, startIdx, endIdx) {
    const paginationContainer = document.getElementById('summonPagination');
    const paginationSummary = document.getElementById('summonPaginationSummary');
    const paginationControls = document.getElementById('summonPaginationControls');

    if (!paginationContainer || !paginationSummary || !paginationControls) return;

    if (total === 0) {
        paginationContainer.style.display = 'none';
        return;
    }

    paginationContainer.style.display = 'flex';
    const firstNum = total > 0 ? (startIdx + 1) : 0;
    const summaryText = `Showing ${firstNum}–${endIdx} of ${total} case${total === 1 ? '' : 's'}`;
    paginationSummary.textContent = summaryText;

    paginationControls.replaceChildren();

    if (totalPages <= 1) {
        return;
    }

    const createBtn = (label, pageNum, disabled = false, current = false, isNav = false) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `complaints-page-btn ${current ? 'active current' : ''} ${isNav ? 'complaints-page-nav-btn' : ''}`;
        btn.textContent = label;
        btn.disabled = disabled;
        if (current) btn.setAttribute('aria-current', 'page');
        btn.addEventListener('click', () => {
            if (currentSummonPage !== pageNum && !disabled) {
                currentSummonPage = pageNum;
                renderSummonCasesTable(filteredSummonCases);
                const tableWrap = document.getElementById('casesNeedingSummonsSection');
                if (tableWrap) {
                    tableWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
        });
        return btn;
    };

    // Previous button
    paginationControls.appendChild(createBtn('← Previous', currentSummonPage - 1, currentSummonPage <= 1, false, true));

    // Numeric buttons with ellipsis if many pages
    const maxButtons = 5;
    let startPage = Math.max(1, currentSummonPage - Math.floor(maxButtons / 2));
    let endPage = Math.min(totalPages, startPage + maxButtons - 1);
    if (endPage - startPage < maxButtons - 1) {
        startPage = Math.max(1, endPage - maxButtons + 1);
    }

    if (startPage > 1) {
        paginationControls.appendChild(createBtn('1', 1, false, currentSummonPage === 1));
        if (startPage > 2) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '…';
            paginationControls.appendChild(ellipsis);
        }
    }

    for (let p = startPage; p <= endPage; p++) {
        paginationControls.appendChild(createBtn(String(p), p, false, p === currentSummonPage));
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.textContent = '…';
            paginationControls.appendChild(ellipsis);
        }
        paginationControls.appendChild(createBtn(String(totalPages), totalPages, false, currentSummonPage === totalPages));
    }

    // Next button
    paginationControls.appendChild(createBtn('Next →', currentSummonPage + 1, currentSummonPage >= totalPages, false, true));
}

function applySummonFilterAndRender() {
    const searchInput = document.getElementById('summonCaseSearch');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const clearBtn = document.getElementById('clearSummonSearch');
    if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';

    let list = [...allLoadedSummonCases];

    // Apply Filter Tab
    if (activeFilterTab === 'pending') {
        list = list.filter(isCasePending);
    } else if (activeFilterTab === 'served') {
        list = list.filter(item => !isCasePending(item) && !isCaseCfaReady(item));
    } else if (activeFilterTab === 'cfa_ready') {
        list = list.filter(isCaseCfaReady);
    }

    // Apply Search Query
    if (query) {
        list = list.filter((item) => {
            const kp = String(item.case_number || '').toLowerCase();
            const comp = String(item.complainant_names || '').toLowerCase();
            const resp = String(item.respondent_names || '').toLowerCase();
            const title = String(item.complaint_title || '').toLowerCase();
            const cno = String(item.complaint_number || '').toLowerCase();
            const date = String(item.hearing_date || '').toLowerCase();
            return kp.includes(query) || comp.includes(query) || resp.includes(query) || title.includes(query) || cno.includes(query) || date.includes(query);
        });
    }

    filteredSummonCases = list;
    sortSummonCases(filteredSummonCases, activeSummonSortKey, activeSummonSortDir);
    renderSummonCasesTable(filteredSummonCases);
}

function renderTableServiceStatus(item) {
    if (isCaseCfaReady(item)) {
        const maxCfaAtt = Number(window.AGAP_SETTINGS?.summons_max_attempts || 6);
        return `<span class="service-status-pill badge-cfa-ready">CFA Ready (${maxCfaAtt}/${maxCfaAtt} Failed)</span>`;
    }

    if (item && item.is_paanyaya) {
        const respServed = Number(item.respondent_served_attempts || 0) > 0 || String(item.respondent_service_status || '').startsWith('Served');
        const respFailCount = Number(item.respondent_failed_attempts || 0);
        const stageLabel = escapeGpsHtml(item.paanyaya_stage_type || 'Paanyaya');
        if (respServed) {
            return `<span class="service-status-pill badge-served-mini" style="font-weight:700;">Delivered (${stageLabel})</span>`;
        }
        const maxPaanyayaAtt = Number(window.AGAP_SETTINGS?.paanyaya_max_attempts || 6);
        if (respFailCount >= maxPaanyayaAtt) {
            return `<span class="service-status-pill badge-failed-mini">${maxPaanyayaAtt} Attempts Exhausted (${stageLabel})</span>`;
        }
        const currentAtt = Math.min(maxPaanyayaAtt, respFailCount + 1);
        return `<span class="service-status-pill badge-pending-mini">Pending (${stageLabel} - Att. ${currentAtt} of ${maxPaanyayaAtt})</span>`;
    }

    const compStatus = String(item.complainant_service_status || 'Pending');
    const respStatus = String(item.respondent_service_status || 'Pending');
    const compServed = compStatus.startsWith('Served');
    const respServed = Number(item.respondent_served_attempts || 0) > 0 || respStatus.startsWith('Served');

    if (compServed && respServed) {
        return `<span class="service-status-pill badge-served-mini" style="font-weight:700;">Both Parties Served</span>`;
    }

    let compPill = '';
    if (compServed) {
        compPill = `<span class="service-status-pill badge-served-mini">Comp: Served</span>`;
    } else if (compStatus === 'Unserved') {
        compPill = `<span class="service-status-pill badge-failed-mini">Comp: Unserved</span>`;
    } else {
        compPill = `<span class="service-status-pill badge-pending-mini">Comp: Pending</span>`;
    }

    let respPill = '';
    const respAttCount = Number(item.respondent_total_attempts || 1);
    const respFailCount = Number(item.respondent_failed_attempts || 0);

    if (respServed) {
        respPill = `<span class="service-status-pill badge-served-mini">Resp: Served</span>`;
    } else if (respStatus === 'Unserved') {
        respPill = `<span class="service-status-pill badge-failed-mini">Resp: Unserved (Att. ${respFailCount || respAttCount})</span>`;
    } else {
        respPill = `<span class="service-status-pill badge-pending-mini">Resp: Pending (Att. ${respAttCount})</span>`;
    }

    return `<div class="service-status-dual">${compPill}${respPill}</div>`;
}

function renderSummonCasesTable(cases) {
    const tbody = document.getElementById('casesNeedingSummonsTable');
    const paginationContainer = document.getElementById('summonPagination');
    const paginationSummary = document.getElementById('summonPaginationSummary');
    const paginationControls = document.getElementById('summonPaginationControls');

    const total = cases.length;
    if (!tbody) return;

    if (!total) {
        let emptyDesc = 'No cases match the selected search or filter criteria.';
        if (activeFilterTab === 'pending') {
            emptyDesc = 'Great news! There are currently no pending summons deliveries awaiting service.';
        } else if (activeFilterTab === 'served') {
            emptyDesc = 'No fully served cases found matching your criteria.';
        }
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="empty-state" style="padding: 40px 16px; text-align: center; color: #64748b;">
                    
                    <div style="font-weight: 700; color: #1e293b; font-size: 0.95rem; margin-bottom: 4px;">No cases found</div>
                    <div style="font-size: 0.85rem; color: #64748b; max-width: 420px; margin: 0 auto;">${escapeGpsHtml(emptyDesc)}</div>
                    ${activeFilterTab !== 'all' ? '<button type="button" class="btn-secondary" style="margin-top: 14px; padding: 6px 14px; border-radius: 6px; font-size: 0.82rem; cursor: pointer; border: 1px solid #cbd5e1; background: #fff;" onclick="setProofFilter(\'all\')">View All Cases</button>' : ''}
                </td>
            </tr>
        `;
        if (paginationContainer) paginationContainer.style.display = 'none';
        if (paginationSummary) paginationSummary.textContent = 'Showing 0 cases';
        if (paginationControls) paginationControls.replaceChildren();
        return;
    }

    const totalPages = Math.ceil(total / summonPageSize) || 1;
    if (currentSummonPage > totalPages) currentSummonPage = totalPages;
    if (currentSummonPage < 1) currentSummonPage = 1;

    const startIdx = (currentSummonPage - 1) * summonPageSize;
    const endIdx = Math.min(startIdx + summonPageSize, total);
    const pageSlice = cases.slice(startIdx, endIdx);

    const rowsHtml = [];
    pageSlice.forEach((item) => {
        const caseId = Number(item.case_id);
        const isExpanded = String(caseId) === String(activeExpandedCaseId);
        const kpNumberRaw = item.case_number || item.complaint_number || (item.case_id ? `Case #${item.case_id}` : 'N/A');
        const userRoleId = Number(window.AGAP_USER_ROLE_ID || window.AGAP_SETTINGS?.user_role_id || 0);
        const canAccessCaseDetails = userRoleId !== 4; // Summons server (Role 4) cannot access full case details

        let kpNumber;
        if (canAccessCaseDetails) {
            const targetComplaintId = item.complaint_id || item.case_id;
            const caseDetailsUrl = item.complaint_id
                ? `../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}${item.is_paanyaya ? '&tab=paanyaya' : ''}`
                : `../cases/case-details.php?id=${encodeURIComponent(item.case_id)}`;

            const kpLinkHtml = `<a href="${caseDetailsUrl}" class="case-kp-link" onclick="event.stopPropagation();" title="View case details for ${escapeGpsHtml(kpNumberRaw)}" style="color:#0284c7; font-size:0.92rem; font-weight:700; text-decoration:none; cursor:pointer;" onmouseover="this.style.textDecoration='underline'; this.style.color='#0369a1';" onmouseout="this.style.textDecoration='none'; this.style.color='#0284c7';">${escapeGpsHtml(kpNumberRaw)}</a>`;

            kpNumber = item.is_paanyaya
                ? `<div style="display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;">${kpLinkHtml}<span style="font-size:0.72rem; padding:2px 7px; background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; border-radius:4px; font-weight:700;">${escapeGpsHtml(item.paanyaya_stage_type || item.hearing_type || 'Paanyaya')}</span></div>`
                : kpLinkHtml;
        } else {
            kpNumber = item.is_paanyaya
                ? `<div style="display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;"><span>${escapeGpsHtml(kpNumberRaw)}</span><span style="font-size:0.72rem; padding:2px 7px; background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; border-radius:4px; font-weight:700;">${escapeGpsHtml(item.paanyaya_stage_type || item.hearing_type || 'Paanyaya')}</span></div>`
                : escapeGpsHtml(kpNumberRaw);
        }
        const complainant = item.complainant_names || 'Not recorded';
        const respondent = item.respondent_names || 'Not recorded';
        const hearingDate = item.hearing_date
            ? `
                <div style="font-weight: 600; color: #0f172a; font-size: 0.88rem;">
                    ${escapeGpsHtml(formatDateTime(item.hearing_date))}
                </div>
                ${item.hearing_type ? `<span class="hearing-type-pill" style="font-size: 0.72rem; padding: 2px 7px; background: #e0f2fe; color: #0369a1; border-radius: 4px; display: inline-block; margin-top: 3px; font-weight: 600;">${escapeGpsHtml(item.hearing_type)}</span>` : ''}
            `
            : '<span style="color: #94a3b8; font-style: italic; font-size: 0.84rem;">Pending schedule</span>';

        // Main Case Row
        rowsHtml.push(`
            <tr id="case-row-${caseId}" class="case-main-row ${isExpanded ? 'is-expanded' : ''}" onclick="toggleExpandCase(${caseId}, event)">
                <td class="cell-expand" style="padding: 12px 14px; text-align: center;">
                    <button type="button" class="row-expand-btn ${isExpanded ? 'is-expanded' : ''}" aria-label="${isExpanded ? 'Collapse' : 'Expand'} details for ${escapeGpsHtml(kpNumberRaw)}" onclick="toggleExpandCase(${caseId}, event)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </button>
                </td>
                <td class="cell-kp" style="padding: 12px 16px;">
                    <strong style="color: #0284c7; font-size: 0.92rem; font-weight: 700;">
                        ${kpNumber}
                    </strong>
                    ${item.complaint_title ? `<div class="case-row-subtitle" title="${escapeGpsHtml(item.complaint_title)}">${escapeGpsHtml(item.complaint_title)}</div>` : ''}
                </td>
                <td class="party-name-cell cell-complainant" style="padding: 12px 16px;">
                    <span class="mobile-cell-label">Complainant</span>
                    <span class="mobile-cell-val">${escapeGpsHtml(complainant)}</span>
                </td>
                <td class="party-name-cell cell-respondent" style="padding: 12px 16px;">
                    <span class="mobile-cell-label">Respondent</span>
                    <span class="mobile-cell-val">${escapeGpsHtml(respondent)}</span>
                </td>
                <td class="cell-hearing" style="padding: 12px 16px;">
                    <span class="mobile-cell-label">Hearing</span>
                    <span class="mobile-cell-val">${hearingDate}</span>
                </td>
                <td class="cell-status" style="padding: 12px 16px;">
                    <span class="mobile-cell-label">Status</span>
                    <div class="mobile-cell-val mobile-status-wrap">
                        ${renderTableServiceStatus(item)}
                    </div>
                </td>
            </tr>
        `);

        // Inline Expanded Row Details (Tasks 3, 4, 5)
        if (isExpanded) {
            rowsHtml.push(`
                <tr id="expanded-row-${caseId}" class="expanded-row-container">
                    <td colspan="6" class="expanded-row-cell">
                        <div id="expanded-content-${caseId}" class="expanded-inline-panel">
                            <div style="padding: 24px; text-align: center; color: #64748b;">
                                <div style="display: inline-block; width: 20px; height: 20px; border: 2px solid #cbd5e1; border-top-color: #0284c7; border-radius: 50%; animation: spin 0.8s linear infinite; margin-bottom: 8px;"></div>
                                <div>Loading case service records...</div>
                            </div>
                        </div>
                    </td>
                </tr>
            `);
        }
    });

    tbody.innerHTML = rowsHtml.join('');
    renderSummonPaginationControls(total, totalPages, startIdx, endIdx);

    // If an expanded row is rendered, fetch and populate its details
    if (activeExpandedCaseId) {
        renderExpandedCaseView(activeExpandedCaseId);
    }
}

function toggleExpandCase(caseId, event) {
    if (event) event.stopPropagation();
    if (!caseId) return;

    const strId = String(caseId);
    if (activeExpandedCaseId === strId) {
        // Collapse
        activeExpandedCaseId = null;
    } else {
        // Expand
        activeExpandedCaseId = strId;

        // Switch to case's page if not on it
        const foundIdx = filteredSummonCases.findIndex(item => Number(item.case_id) === Number(caseId));
        if (foundIdx !== -1) {
            const targetPage = Math.floor(foundIdx / summonPageSize) + 1;
            if (targetPage !== currentSummonPage) {
                currentSummonPage = targetPage;
            }
        }
    }

    renderSummonCasesTable(filteredSummonCases);

    if (activeExpandedCaseId) {
        const row = document.getElementById(`case-row-${caseId}`);
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
}
window.toggleExpandCase = toggleExpandCase;

function selectSummonCase(caseId) {
    if (!caseId) return;
    toggleExpandCase(caseId);
}
window.selectSummonCase = selectSummonCase;

/* ==========================================================================
   Task 4 & 5: Expanded Row Details & Horizontal Attempt Stepper
   ========================================================================== */

async function renderExpandedCaseView(caseId) {
    const container = document.getElementById(`expanded-content-${caseId}`);
    if (!container) return;

    try {
        const currentCaseItem = allLoadedSummonCases.find(c => Number(c.case_id) === Number(caseId)) || {};
        const targetHearingParam = currentCaseItem.hearing_id ? `&hearing_id=${encodeURIComponent(currentCaseItem.hearing_id)}` : '';
        const targetStageParam = (currentCaseItem.hearing_type || currentCaseItem.case_status) ? `&stage=${encodeURIComponent(currentCaseItem.hearing_type || currentCaseItem.case_status)}` : '';
        const result = await gpsApi(`${proofApiUrl}?case_id=${encodeURIComponent(caseId)}&mode=deliveries${targetHearingParam}${targetStageParam}`);
        const data = result.data || {};

        if (!data.has_hearings || !Array.isArray(data.hearings) || data.hearings.length === 0) {
            container.innerHTML = `
                <div style="padding: 24px; text-align: center; color: #64748b; background: #fff; border: 1px dashed #cbd5e1; border-radius: 8px;">
                    <div style="font-size: 1.1rem; font-weight: 600; color: #1e293b; margin-bottom: 4px;">No Scheduled Hearings Found</div>
                    <p style="margin: 0; font-size: 0.86rem;">No hearing has been scheduled yet for this case. Issue a summons/notice to establish hearing date and delivery tracking.</p>
                </div>
            `;
            return;
        }

        const activeHearing = data.hearings[0];
        window.activeExpandedHearingData = activeHearing;
        const isPaanyayaCase = Boolean(currentCaseItem.is_paanyaya || activeHearing.is_paanyaya);
        const isConciliation = (activeHearing.hearing_type === 'Conciliation' || currentCaseItem.case_status === 'Conciliation');
        const deliveries = activeHearing.deliveries || [];
        const respAttempts = activeHearing.respondent_attempts || [];
        const compAttempts = activeHearing.complainant_attempts || [];

        const maxAllowedAttempts = isPaanyayaCase
            ? Number(activeHearing.max_attempts || window.AGAP_SETTINGS?.paanyaya_max_attempts || 6)
            : Number(activeHearing.max_attempts || window.AGAP_SETTINGS?.summons_max_attempts || 6);

        const cfaElig = data.cfa_eligibility || activeHearing.cfa_eligibility || {
            eligible: false,
            failed_count: 0,
            attempts_count: 0,
            reason: `CFA is locked until all ${maxAllowedAttempts} summons attempts have failed.`
        };
        const failedCount = Number(cfaElig.failed_count || 0);
        const isCfaEligible = Boolean(cfaElig.eligible);

        const kpNumber = currentCaseItem.case_number || `Case #${caseId}`;
        const complaintTitle = currentCaseItem.complaint_title || 'Barangay Grievance Case';
        const hearingDateFormatted = activeHearing.hearing_date ? formatDateTime(activeHearing.hearing_date) : `${activeHearing.hearing_type || 'Hearing'} Session`;
        const venue = activeHearing.venue || 'Barangay Tumana Lupon Office';
        const ordinals = ['', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th'];

        // 1. Complainant Party Details & Status
        const compDeliv = deliveries.find(d => d.party_type === 'Complainant') || activeHearing.complainant_delivery || null;
        const compProfile = compDeliv?.party_profile || (compAttempts[0]?.party_profile) || {};
        const compName = compDeliv?.recipient_name || compProfile.resident_name || (compAttempts[0]?.recipient_name) || currentCaseItem.complainant_names || 'Complainant';
        const compResidentId = compDeliv?.resident_id || compProfile.resident_id || (compAttempts[0]?.resident_id) || 0;

        const isMediation = (activeHearing.hearing_type === 'Mediation');
        const anyCompServed = compAttempts.some(a => a.is_served) || (!isConciliation && Boolean(compDeliv?.delivery_status?.startsWith('Served')));
        const compFailedCount = compAttempts.filter(a => a.is_failed).length;
        const canNextComp = Boolean(activeHearing.can_initiate_next_comp_attempt) && !anyCompServed;
        const nextCompNum = activeHearing.next_comp_attempt_number || (compAttempts.length + 1);
        const nextCompOrd = ordinals[nextCompNum] || nextCompNum + 'th';
        const activePendingComp = compAttempts.find(a => a.is_pending) || null;
        const targetCompAttemptForReturn = anyCompServed ? null : (activePendingComp || (!anyCompServed && compDeliv ? compDeliv : null));

        let compBadgeHtml = '';
        if (anyCompServed) {
            compBadgeHtml = `<span class="service-result-badge badge-served">Served</span>`;
        } else if (compAttempts.length >= maxAllowedAttempts && compFailedCount >= maxAllowedAttempts) {
            compBadgeHtml = `<span class="service-result-badge badge-not-served">${maxAllowedAttempts} Attempts Failed - Max Reached (Unserved)</span>`;
        } else if (compFailedCount > 0) {
            compBadgeHtml = `<span class="service-result-badge badge-not-served">Attempt #${compFailedCount} Failed</span>`;
        } else {
            compBadgeHtml = `<span class="service-result-badge badge-warning">Pending Service</span>`;
        }

        const compFormTitle = isConciliation
            ? `KP Form No. 12 – Notice of Hearing (Pangkat) (${maxAllowedAttempts} Attempts Max)`
            : 'KP Form No. 8 – Notice of Hearing';

        // 2. Respondent Party Details & Status
        const respDeliv = deliveries.find(d => d.party_type === 'Respondent') || null;
        const respProfile = respDeliv?.party_profile || (respAttempts[0]?.party_profile) || {};
        const respName = respDeliv?.recipient_name || respProfile.resident_name || (respAttempts[0]?.recipient_name) || currentCaseItem.respondent_names || 'Respondent';
        const respResidentId = respDeliv?.resident_id || respProfile.resident_id || (respAttempts[0]?.resident_id) || 0;

        const anyRespServed = respAttempts.some(a => a.is_served);
        const canNextResp = Boolean(activeHearing.can_initiate_next_attempt) && !anyRespServed;
        const nextRespNum = activeHearing.next_attempt_number || (respAttempts.length + 1);
        const nextRespOrd = ordinals[nextRespNum] || nextRespNum + 'th';
        const activePendingResp = respAttempts.find(a => a.is_pending) || null;
        const targetRespAttemptForReturn = anyRespServed ? null : activePendingResp;

        let respBadgeHtml = '';
        if (anyRespServed) {
            respBadgeHtml = `<span class="service-result-badge badge-served">Served</span>`;
        } else if (respAttempts.length >= maxAllowedAttempts && failedCount >= maxAllowedAttempts) {
            respBadgeHtml = `<span class="service-result-badge badge-not-served">${maxAllowedAttempts} Attempts Failed - Max Reached (Unserved)</span>`;
        } else if (failedCount > 0) {
            respBadgeHtml = `<span class="service-result-badge badge-not-served">Attempt #${failedCount} Failed</span>`;
        } else {
            respBadgeHtml = `<span class="service-result-badge badge-warning">Pending Service</span>`;
        }

        const respFormTitle = isPaanyayaCase
            ? `${escapeGpsHtml(activeHearing.hearing_type || 'Paanyaya')} – Preliminary Invitation Tracking (${maxAllowedAttempts} Attempts Max)`
            : (isConciliation
                ? `KP Form No. 12 – Notice of Hearing (Pangkat) (${maxAllowedAttempts} Attempts Max)`
                : `KP Form No. 9 – Summons Delivery Tracking (${maxAllowedAttempts} Attempts Max)`);

        // Helper: Party Stepper Node (Complainant or Respondent)
        const renderPartyStepperNode = (num, partyType) => {
            const ord = ordinals[num] || num + 'th';
            const attemptsList = (partyType === 'Complainant') ? compAttempts : respAttempts;
            const anyServed = (partyType === 'Complainant') ? anyCompServed : anyRespServed;
            const targetResId = (partyType === 'Complainant') ? compResidentId : respResidentId;
            const targetPartyName = (partyType === 'Complainant') ? compName : respName;

            const att = attemptsList.find(a => a.attempt_number === num) || null;
            let circleClass = 'unissued';
            let iconText = num;
            let statusText = 'Not Issued';
            let subText = 'Unissued';
            let clickAttr = '';
            let stepCursor = 'default';

            if (att) {
                const resIdVal = Number(att.resident_id || targetResId) || 0;
                const deliveryId = Number(att.delivery_id);
                const attDelivId = (Number.isSafeInteger(deliveryId) && deliveryId > 0) ? deliveryId : 'null';
                if (att.is_pending && anyServed) {
                    return `
                        <div class="stepper-step" style="cursor: default;" title="${ord} Attempt: Cancelled (Notice already served)">
                            <div class="step-circle pending">✓</div>
                            <span class="step-label">${ord} Attempt</span>
                            <span class="step-subtext">Notice Served</span>
                        </div>
                    `;
                }

                if (att.is_served) {
                    circleClass = 'served';
                    iconText = '✓';
                    statusText = 'Served';
                    subText = att.attempted_at ? formatDateTime(att.attempted_at) : (att.served_at ? formatDateTime(att.served_at) : 'Personal');
                } else if (att.is_failed) {
                    circleClass = 'failed';
                    iconText = '✕';
                    statusText = 'Failed';
                    subText = att.unserved_reason || 'Unserved';
                } else {
                    circleClass = 'pending';
                    iconText = '⏳';
                    statusText = 'Pending';
                    subText = 'Awaiting Return';
                }

                clickAttr = `onclick="openOfficerReturnModal('${partyType}', ${resIdVal}, ${Number(activeHearing.hearing_id)}, ${caseId}, ${escapeGpsJsArgument(att.delivery_status || '')}, ${escapeGpsJsArgument(att.recipient_name || targetPartyName)}, ${escapeGpsJsArgument(att.relationship || '')}, ${escapeGpsJsArgument(att.remarks || '')}, ${escapeGpsJsArgument(att.unserved_reason || '')}, ${num}, ${attDelivId})"`;
                stepCursor = 'pointer';
            }

            return `
                <button type="button" class="stepper-step" ${clickAttr} title="${ord} Attempt: ${statusText} (${subText}) — Click to view / edit return" style="cursor: ${stepCursor};">
                    <div class="step-circle ${circleClass}">${iconText}</div>
                    <span class="step-label">${ord} Attempt</span>
                    <span class="step-subtext">${escapeGpsHtml(subText)}</span>
                </button>
            `;
        };

        const renderCfaStepperNode = () => {
            if (isPaanyayaCase) return '';
            let circleClass = isCfaEligible ? 'cfa-ready' : 'cfa-locked';
            let iconText = isCfaEligible ? 'CFA' : '—';
            let statusText = isCfaEligible ? 'CFA Ready' : 'CFA Locked';
            let subText = isCfaEligible ? 'Eligible' : `${failedCount}/${maxAllowedAttempts} Failed`;
            let clickAction = isCfaEligible
                ? `onclick="window.location.href='../documents/cfa.php?case_id=${encodeURIComponent(caseId)}'"`
                : `onclick="window.agapNotify ? window.agapNotify('${escapeGpsHtml(cfaElig.reason || `CFA is locked until all ${maxAllowedAttempts} summons attempts have failed.`)}', 'warning', 'CFA Locked') : alert('${escapeGpsHtml(cfaElig.reason || `CFA is locked until all ${maxAllowedAttempts} summons attempts have failed.`)}')`;

            return `
                <button type="button" class="stepper-step" ${clickAction} title="${statusText} — ${escapeGpsHtml(cfaElig.reason || '')}" style="${!isCfaEligible ? 'cursor: not-allowed;' : ''}">
                    <div class="step-circle ${circleClass}">${iconText}</div>
                    <span class="step-label">${statusText}</span>
                    <span class="step-subtext">${subText}</span>
                </button>
            `;
        };

        // Helper: Party Details List (Attempts Cards)
        const renderPartyDetailsList = (attemptsList, partyType, fallbackDeliv) => {
            const targetResId = (partyType === 'Complainant') ? compResidentId : respResidentId;
            const targetPartyName = (partyType === 'Complainant') ? compName : respName;

            if (!attemptsList || attemptsList.length === 0) {
                if (fallbackDeliv) {
                    const fallbackDelivId = (Number.isSafeInteger(Number(fallbackDeliv?.delivery_id)) && Number(fallbackDeliv?.delivery_id) > 0) ? Number(fallbackDeliv.delivery_id) : 'null';
                    const isServed = Boolean(fallbackDeliv.delivery_status?.startsWith('Served'));
                    const isFailed = fallbackDeliv.delivery_status === 'Unserved';
                    const statusClass = isServed ? 'badge-served' : (isFailed ? 'badge-not-served' : 'badge-warning');
                    const statusLabel = isServed ? (fallbackDeliv.delivery_status || 'Served') : (isFailed ? 'Unserved / Failed' : 'Pending Service');

                    return `
                        <div class="respondent-attempt-block">
                            <div style="font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                                <span>1st Attempt</span>
                                <div style="display: inline-flex; align-items: center; gap: 8px;">
                                    <span class="service-result-badge ${statusClass}" style="font-size: 0.72rem; padding: 2px 7px;">${escapeGpsHtml(statusLabel)}</span>
                                    <button type="button" class="btn-secondary" style="font-size: 0.72rem; padding: 2px 8px; border-radius: 4px; cursor: pointer; border: 1px solid #cbd5e1; background: #fff;" onclick="openOfficerReturnModal('${partyType}', ${Number(fallbackDeliv.resident_id || targetResId) || 0}, ${Number(activeHearing.hearing_id)}, ${caseId}, ${escapeGpsJsArgument(fallbackDeliv.delivery_status || '')}, ${escapeGpsJsArgument(fallbackDeliv.recipient_name || targetPartyName)}, ${escapeGpsJsArgument(fallbackDeliv.relationship || '')}, ${escapeGpsJsArgument(fallbackDeliv.remarks || '')}, ${escapeGpsJsArgument(fallbackDeliv.unserved_reason || '')}, 1, ${fallbackDelivId})">
                                        ${fallbackDeliv.delivery_status === 'Pending' ? '📝 Log Return' : '✏️ Edit Return'}
                                    </button>
                                </div>
                            </div>
                            <ul class="delivery-detail-list">
                                <li>
                                    <span class="delivery-detail-label">Service Source / Server</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(fallbackDeliv.served_by_name || 'Lupon Office (Personal Notice)')}</span>
                                </li>
                                ${fallbackDeliv.served_at ? `
                                    <li>
                                        <span class="delivery-detail-label">Date/Time Served</span>
                                        <span class="delivery-detail-val">${escapeGpsHtml(formatDateTime(fallbackDeliv.served_at))}</span>
                                    </li>
                                ` : ''}
                                <li>
                                    <span class="delivery-detail-label">Service Method</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(fallbackDeliv.service_method || 'Personal Notice')}</span>
                                </li>
                                ${fallbackDeliv.remarks ? `
                                    <li>
                                        <span class="delivery-detail-label">Remarks</span>
                                        <span class="delivery-detail-val">${escapeGpsHtml(fallbackDeliv.remarks)}</span>
                                    </li>
                                ` : ''}
                            </ul>
                        </div>
                    `;
                }

                return `
                    <ul class="delivery-detail-list">
                        <li>
                            <span class="delivery-detail-label">Service Status</span>
                            <span class="delivery-detail-val"><span class="service-result-badge badge-warning">Pending Service</span></span>
                        </li>
                        <li>
                            <span class="delivery-detail-label">Service Source / Server</span>
                            <span class="delivery-detail-val">Unassigned</span>
                        </li>
                    </ul>
                `;
            }

            return attemptsList.map((att, idx) => {
                const ord = att.attempt_ordinal || ((ordinals[att.attempt_number] || att.attempt_number + 'th') + ' Attempt');
                const proofPath = att.proof_file_path || att.attachment_path;
                const isServed = Boolean(att.is_served);
                const isFailed = Boolean(att.is_failed);
                const timeVal = att.served_at || att.attempted_at;
                const timeLabel = isServed ? 'Date/Time Served' : (isFailed ? 'Date/Time Attempted' : 'Date/Time Issued');

                let statusBadge = '';
                if (isServed) {
                    statusBadge = `<span class="service-result-badge badge-served" style="font-size: 0.72rem; padding: 2px 7px;">${escapeGpsHtml(att.delivery_status || 'Served')}</span>`;
                } else if (isFailed) {
                    statusBadge = `<span class="service-result-badge badge-not-served" style="font-size: 0.72rem; padding: 2px 7px;">Unserved / Failed</span>`;
                } else {
                    statusBadge = `<span class="service-result-badge badge-warning" style="font-size: 0.72rem; padding: 2px 7px;">Pending Service</span>`;
                }

                const attDelivId = (Number.isSafeInteger(Number(att.delivery_id)) && Number(att.delivery_id) > 0) ? Number(att.delivery_id) : 'null';
                const editBtnText = att.is_pending ? 'Log Return' : 'Edit Return';
                const editBtnIcon = att.is_pending ? '📝' : '✏️';

                return `
                    <div class="respondent-attempt-block" style="${idx > 0 ? 'margin-top: 10px; padding-top: 8px; border-top: 1px dashed #e2e8f0;' : ''}">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                            <span>${escapeGpsHtml(ord)}</span>
                            <div style="display: inline-flex; align-items: center; gap: 8px;">
                                <span>${statusBadge}</span>
                                <button type="button" class="btn-secondary" style="font-size: 0.72rem; padding: 2px 8px; border-radius: 4px; cursor: pointer; border: 1px solid #cbd5e1; background: #fff;" onclick="openOfficerReturnModal('${partyType}', ${Number(att.resident_id || targetResId) || 0}, ${Number(activeHearing.hearing_id)}, ${caseId}, ${escapeGpsJsArgument(att.delivery_status || '')}, ${escapeGpsJsArgument(att.recipient_name || targetPartyName)}, ${escapeGpsJsArgument(att.relationship || '')}, ${escapeGpsJsArgument(att.remarks || '')}, ${escapeGpsJsArgument(att.unserved_reason || '')}, ${att.attempt_number || (idx + 1)}, ${attDelivId})">
                                    ${editBtnIcon} ${editBtnText}
                                </button>
                            </div>
                        </div>
                        <ul class="delivery-detail-list">
                            ${timeVal ? `
                                <li>
                                    <span class="delivery-detail-label">${timeLabel}</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(formatDateTime(timeVal))}</span>
                                </li>
                            ` : ''}
                            <li>
                                <span class="delivery-detail-label">Service Source / Server</span>
                                <span class="delivery-detail-val">${escapeGpsHtml(att.served_by_name || 'Unassigned')}</span>
                            </li>
                            ${isFailed && att.unserved_reason ? `
                                <li>
                                    <span class="delivery-detail-label">Failure Reason</span>
                                    <span class="delivery-detail-val" style="color: #b91c1c; font-weight: 600;">${escapeGpsHtml(att.unserved_reason)}</span>
                                </li>
                            ` : ''}
                            ${att.service_method ? `
                                <li>
                                    <span class="delivery-detail-label">Service Method</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(att.service_method)}</span>
                                </li>
                            ` : ''}
                            ${att.remarks ? `
                                <li>
                                    <span class="delivery-detail-label">Remarks</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(att.remarks)}</span>
                                </li>
                            ` : ''}
                            ${proofPath ? `
                                <li>
                                    <span class="delivery-detail-label">Proof / Attachment</span>
                                    <span class="delivery-detail-val">
                                        <a href="../../${escapeGpsHtml(proofPath)}" target="_blank" style="color: #0284c7; font-weight: 600; text-decoration: underline; display: inline-flex; align-items: center; gap: 3px;">
                                            📎 View Proof
                                        </a>
                                    </span>
                                </li>
                            ` : ''}
                        </ul>
                    </div>
                `;
            }).join('');
        };

        // Complainant Action Buttons
        let compActionBtnHtml = '';
        if (canNextComp && nextCompNum <= 6) {
            compActionBtnHtml = `
                <button type="button" class="btn-create" onclick="initiateNextSummonAttempt(${caseId}, ${activeHearing.hearing_id}, 'Complainant')" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.82rem; padding: 6px 14px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Issue Notice ${nextCompOrd} Attempt
                </button>
            `;
        } else if (anyCompServed) {
            compActionBtnHtml = `<span style="background: #dcfce7; color: #166534; font-weight: 700; font-size: 0.8rem; padding: 6px 12px; border-radius: 6px; border: 1px solid #bbf7d0; display: inline-flex; align-items: center; gap: 4px;">✓ Successfully Served</span>`;
        } else if (compAttempts.length >= maxAllowedAttempts && compFailedCount >= maxAllowedAttempts) {
            compActionBtnHtml = `<span style="background: #fee2e2; color: #991b1b; font-weight: 700; font-size: 0.8rem; padding: 6px 12px; border-radius: 6px; border: 1px solid #fecaca; display: inline-flex; align-items: center; gap: 4px;">${maxAllowedAttempts} Attempts Failed - Max Reached (Unserved)</span>`;
        }

        // Respondent Action Buttons
        let respActionBtnHtml = '';
        if (canNextResp && nextRespNum <= 6) {
            respActionBtnHtml = `
                <button type="button" class="btn-create" onclick="initiateNextSummonAttempt(${caseId}, ${activeHearing.hearing_id}, 'Respondent')" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.82rem; padding: 6px 14px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    ${isConciliation ? 'Issue Notice' : 'Issue Summon'} ${nextRespOrd} Attempt
                </button>
            `;
        } else if (anyRespServed) {
            respActionBtnHtml = `<span style="background: #dcfce7; color: #166534; font-weight: 700; font-size: 0.8rem; padding: 6px 12px; border-radius: 6px; border: 1px solid #bbf7d0; display: inline-flex; align-items: center; gap: 4px;">✓ Successfully Served</span>`;
        } else if (respAttempts.length >= maxAllowedAttempts && failedCount >= maxAllowedAttempts) {
            respActionBtnHtml = `<span style="background: #fee2e2; color: #991b1b; font-weight: 700; font-size: 0.8rem; padding: 6px 12px; border-radius: 6px; border: 1px solid #fecaca; display: inline-flex; align-items: center; gap: 4px;">${maxAllowedAttempts} Attempts Failed - Max Reached (Unserved)</span>`;
        }

        let cfaBtnHtml = '';
        if (isCfaEligible) {
            cfaBtnHtml = `
                <a href="../documents/cfa.php?case_id=${encodeURIComponent(caseId)}" class="btn-create" style="display: inline-flex; align-items: center; gap: 6px; background: #16a34a; color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 6px; font-weight: 600; font-size: 0.82rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    Print CFA (KP Form 20) &rarr;
                </a>
            `;
        }

        // Render complete expanded panel
        container.innerHTML = `
            <!-- Two-Column Side-by-Side Service Records Grid -->
            <div class="expanded-columns-grid" ${isPaanyayaCase ? 'style="grid-template-columns: 1fr;"' : ''}>
                <!-- Left Column: Complainant Notice (KP Form 12 for Conciliation / KP Form 8 for Mediation) -->
                <div class="delivery-card" style="margin: 0; display: ${isPaanyayaCase ? 'none' : 'flex'}; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div class="delivery-card-header">
                            <div>
                                <span class="delivery-party-role">Complainant</span>
                                <div class="delivery-party-name">
                                    <span>${escapeGpsHtml(compName)}</span>
                                </div>
                                <span class="delivery-form-type">${escapeGpsHtml(compFormTitle)}</span>
                            </div>
                            <div>
                                ${compBadgeHtml}
                            </div>
                        </div>

                        <div class="delivery-card-body">
                            <div style="font-size: 0.82rem; color: #475569; margin-bottom: 8px;">
                                Hearing: <strong>${escapeGpsHtml(activeHearing.hearing_type)}</strong> (${escapeGpsHtml(hearingDateFormatted)})
                            </div>

                            ${isConciliation || compAttempts.length > 0 ? `
                                <!-- Horizontal Stepper Tracker for Complainant -->
                                <div class="summon-stepper-wrap">
                                    <div class="summon-stepper">
                                        ${Array.from({length: maxAllowedAttempts}, (_, i) => renderPartyStepperNode(i + 1, 'Complainant')).join('')}
                                    </div>
                                </div>

                                ${(compAttempts.length >= maxAllowedAttempts && compFailedCount >= maxAllowedAttempts) ? `
                                    <div style="background: #fef2f2; border: 1px solid #f87171; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; color: #991b1b; font-size: 0.82rem; line-height: 1.45; display: flex; align-items: flex-start; gap: 8px;">
                                        <span style="font-size: 1rem;">⚠️</span>
                                        <div>
                                            <strong>${maxAllowedAttempts} Attempts Failed - Max Reached (Unserved):</strong> Complainant notice delivery could not be completed after ${maxAllowedAttempts} attempts.
                                        </div>
                                    </div>
                                ` : ''}

                                ${renderPartyDetailsList(compAttempts, 'Complainant', compDeliv)}
                            ` : `
                                <ul class="delivery-detail-list">
                                    <li>
                                        <span class="delivery-detail-label">Date/Time Served</span>
                                        <span class="delivery-detail-val">${escapeGpsHtml(formatDateTime(compDeliv?.served_at || compDeliv?.created_at || activeHearing.hearing_date || new Date()))}</span>
                                    </li>
                                    <li>
                                        <span class="delivery-detail-label">Service Source / Server</span>
                                        <span class="delivery-detail-val">${escapeGpsHtml(compDeliv?.served_by_name || 'Lupon Office (Personal Notice)')}</span>
                                    </li>
                                    <li>
                                        <span class="delivery-detail-label">Service Method</span>
                                        <span class="delivery-detail-val">${escapeGpsHtml(compDeliv?.service_method || 'Personal Notice')}</span>
                                    </li>
                                    <li>
                                        <span class="delivery-detail-label">Remarks</span>
                                        <span class="delivery-detail-val">${escapeGpsHtml(compDeliv?.remarks || 'Personal notice served to complainant upon issuance of mediation summons.')}</span>
                                    </li>
                                </ul>
                            `}
                        </div>
                    </div>

                    <div class="delivery-card-footer" style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; align-items: center;">
                        ${targetCompAttemptForReturn ? `
                            <button type="button" class="btn-log-return" style="font-size: 0.82rem; padding: 6px 12px;" onclick="openOfficerReturnModal(
                                'Complainant',
                                ${compResidentId},
                                ${activeHearing.hearing_id},
                                ${caseId},
                                ${escapeGpsJsArgument(targetCompAttemptForReturn.delivery_status || '')},
                                ${escapeGpsJsArgument(targetCompAttemptForReturn.recipient_name || compName)},
                                ${escapeGpsJsArgument(targetCompAttemptForReturn.relationship || '')},
                                ${escapeGpsJsArgument(targetCompAttemptForReturn.remarks || '')},
                                ${escapeGpsJsArgument(targetCompAttemptForReturn.unserved_reason || '')},
                                ${targetCompAttemptForReturn.attempt_number || 1},
                                ${targetCompAttemptForReturn.delivery_id || 'null'}
                            )">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                Log Officer's Return
                            </button>
                        ` : ''}
                        ${compActionBtnHtml}
                    </div>
                </div>

                <!-- Right Column: Respondent Summons / Notice (KP Form 12 for Conciliation / KP Form 9 for Mediation) -->
                <div class="delivery-card" style="margin: 0; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div class="delivery-card-header">
                            <div>
                                <span class="delivery-party-role" style="color: #ea580c;">Respondent ${isPaanyayaCase ? '(Intended Recipient Visited)' : ''}</span>
                                <div class="delivery-party-name">
                                    <span>${escapeGpsHtml(respName)}</span>
                                </div>
                                <span class="delivery-form-type">${escapeGpsHtml(respFormTitle)}</span>
                            </div>
                            <div>
                                ${respBadgeHtml}
                            </div>
                        </div>

                        <div class="delivery-card-body">
                            <!-- Horizontal Stepper Tracker -->
                            <div class="summon-stepper-wrap">
                                <div class="summon-stepper">
                                    ${Array.from({length: maxAllowedAttempts}, (_, i) => renderPartyStepperNode(i + 1, 'Respondent')).join('')}
                                    ${renderCfaStepperNode()}
                                </div>
                            </div>

                            ${(respAttempts.length >= maxAllowedAttempts && failedCount >= maxAllowedAttempts) ? `
                                <div style="background: #fef2f2; border: 1px solid #f87171; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; color: #991b1b; font-size: 0.82rem; line-height: 1.45; display: flex; align-items: flex-start; gap: 8px;">
                                    <span style="font-size: 1rem;">⚠️</span>
                                    <div>
                                        <strong>${maxAllowedAttempts} Attempts Failed - Max Reached (Unserved):</strong> The hearing schedule has been halted. Please verify the respondent's address with the complainant or refer the case for lack of jurisdiction / non-residence.
                                    </div>
                                </div>
                            ` : ''}

                            <!-- Delivery Details List -->
                            ${renderPartyDetailsList(respAttempts, 'Respondent', respDeliv)}
                        </div>
                    </div>

                    <div class="delivery-card-footer" style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; align-items: center;">
                        ${targetRespAttemptForReturn ? `
                            <button type="button" class="btn-log-return" style="font-size: 0.82rem; padding: 6px 12px;" onclick="openOfficerReturnModal(
                                'Respondent',
                                ${respResidentId},
                                ${activeHearing.hearing_id},
                                ${caseId},
                                ${escapeGpsJsArgument(targetRespAttemptForReturn.delivery_status || '')},
                                ${escapeGpsJsArgument(targetRespAttemptForReturn.recipient_name || respName)},
                                ${escapeGpsJsArgument(targetRespAttemptForReturn.relationship || '')},
                                ${escapeGpsJsArgument(targetRespAttemptForReturn.remarks || '')},
                                ${escapeGpsJsArgument(targetRespAttemptForReturn.unserved_reason || '')},
                                ${targetRespAttemptForReturn.attempt_number || 1},
                                ${targetRespAttemptForReturn.delivery_id || 'null'}
                            )">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                Log Officer's Return
                            </button>
                        ` : ''}
                        ${respActionBtnHtml}
                        ${cfaBtnHtml}
                    </div>
                </div>
            </div>
        `;

    } catch (err) {
        console.error('Failed to load hearing deliveries for case', caseId, err);
        container.innerHTML = `
            <div style="padding: 20px; text-align: center; color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
                Failed to load service records: ${escapeGpsHtml(err.message || 'Unknown error')}
            </div>
        `;
    }
}
window.renderExpandedCaseView = renderExpandedCaseView;

// Backwards-compatible loadHearingDeliveries
async function loadHearingDeliveries(caseId) {
    if (activeExpandedCaseId === String(caseId)) {
        await renderExpandedCaseView(caseId);
    }
}
window.loadHearingDeliveries = loadHearingDeliveries;

/* ==========================================================================
   Case List Loader
   ========================================================================== */

function setupSummonCaseSearch() {
    const searchInput = document.getElementById('summonCaseSearch');
    const clearBtn = document.getElementById('clearSummonSearch');
    if (!searchInput || searchInput.dataset.bound) return;
    searchInput.dataset.bound = '1';

    const handleFilter = () => {
        currentSummonPage = 1;
        applySummonFilterAndRender();
    };

    searchInput.addEventListener('input', handleFilter);

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            currentSummonPage = 1;
            applySummonFilterAndRender();
            searchInput.focus();
        });
    }
}

async function loadCases(preselectedCaseId = '') {
    const select = document.getElementById('proofCaseId');
    const result = await gpsApi(proofApiUrl);

    allLoadedSummonCases = Array.isArray(result.data) ? result.data : [];

    if (select) {
        select.innerHTML =
            '<option value="">Select a case</option>' +
            allLoadedSummonCases
                .map((item) => {
                    const caseNumber = item.case_number || `Case ${item.case_id}`;
                    const complaintTitle = item.complaint_title || 'Untitled complaint';
                    const label = `${caseNumber} - ${complaintTitle}`;
                    return `<option value="${Number(item.case_id)}">${escapeGpsHtml(label)}</option>`;
                })
                .join('');
    }

    updateSummaryMetrics();
    setupProofFilterTabs();
    setupSummonCaseSearch();
    setupSummonTableSorting();
    updateSummonSortHeadersUI();

    applySummonFilterAndRender();

    const validPreselectedCase =
        preselectedCaseId &&
        allLoadedSummonCases.some((item) => Number(item.case_id) === Number(preselectedCaseId));

    if (validPreselectedCase) {
        toggleExpandCase(preselectedCaseId);
    }
}
window.loadCases = loadCases;

/* ==========================================================================
   Summon Actions & Document Integration (Task 7)
   ========================================================================== */

async function initiateNextSummonAttempt(caseId, hearingId, partyType = 'Respondent') {
    if (!caseId) return;
    const partyLabel = partyType === 'Complainant' ? 'complainant' : 'respondent';
    const noticeLabel = partyType === 'Complainant' ? 'notice' : 'summons/notice';
    if (!window.confirm(`Do you want to initiate the next ${noticeLabel} delivery attempt for the ${partyLabel}?`)) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('case_id', caseId);
        if (hearingId) formData.append('hearing_id', hearingId);
        formData.append('party_type', partyType);
        formData.append('action', 'next_attempt');

        const res = await fetch('../../../backend/api/summons/next-attempt.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Failed to initiate summons/notice attempt.');
        }

        window.agapNotify?.(data.message || 'Next delivery attempt initiated successfully.', 'success', 'Delivery Attempt');
        await loadCases();
        if (activeExpandedCaseId) {
            await renderExpandedCaseView(activeExpandedCaseId);
        }
    } catch (err) {
        window.agapNotify?.(err.message, 'error', 'Delivery Attempt Failed');
    }
}
window.initiateNextSummonAttempt = initiateNextSummonAttempt;

async function printKp9Notice(caseId, hearingId) {
    if (typeof window.openSummonsPreviewModal === 'function') {
        window.openSummonsPreviewModal(caseId, null, hearingId, false);
        return;
    }
    window.location.href = `../documents/kp-form-9.php?case_id=${encodeURIComponent(caseId)}`;
}
window.printKp9Notice = printKp9Notice;

async function printKp8Notice(caseId, hearingId, documentId) {
    if (typeof window.openSummonsPreviewModal === 'function') {
        window.openSummonsPreviewModal(caseId, documentId, hearingId, false);
        return;
    }
    window.location.href = `../complaints/complaint-details.php?id=${encodeURIComponent(caseId)}#print-summons`;
}
window.printKp8Notice = printKp8Notice;

/* ==========================================================================
   Task 6: Slide-in Officer's Return Drawer Implementation
   ========================================================================== */

function openOfficerReturnModal(partyType, residentId, hearingId, caseId, currentStatus, currentRecipient, currentRelationship, currentNotes, currentUnservedReason, attemptNumber = 1, deliveryId = null) {
    const modal = document.getElementById('officerReturnModal');
    const overlay = document.getElementById('officerReturnDrawerOverlay');
    if (!modal) return;

    document.getElementById('returnCaseId').value = caseId || '';
    document.getElementById('returnHearingId').value = hearingId || '';
    document.getElementById('returnPartyType').value = partyType || 'Respondent';
    document.getElementById('returnResidentId').value = residentId || '';

    const attemptInput = document.getElementById('returnAttemptNumber');
    if (attemptInput) attemptInput.value = attemptNumber || 1;

    const deliveryIdInput = document.getElementById('returnDeliveryId');
    const isEditMode = Boolean(deliveryId && deliveryId !== 'null' && Number(deliveryId) > 0);
    if (deliveryIdInput) deliveryIdInput.value = isEditMode ? deliveryId : '';

    // Header Badges & Titles
    const caseItem = allLoadedSummonCases.find(c => Number(c.case_id) === Number(caseId)) || {};
    const kpNum = caseItem.case_number || (caseId ? `Case #${caseId}` : 'Case');
    const isPaanyaya = Boolean(caseItem.is_paanyaya);
    const paaFlagEl = document.getElementById('returnIsPaanyaya');
    if (paaFlagEl) paaFlagEl.value = isPaanyaya ? '1' : '0';
    const paaStageEl = document.getElementById('returnStageType');
    const qStage = (new URLSearchParams(window.location.search)).get('stage');
    if (paaStageEl) paaStageEl.value = isPaanyaya ? (qStage || caseItem.paanyaya_stage_type || 'Paanyaya 1') : '';
    const paaCompIdEl = document.getElementById('returnComplaintId');
    if (paaCompIdEl) paaCompIdEl.value = isPaanyaya ? (caseItem.complaint_id || caseId) : '';

    const caseBadge = document.getElementById('drawerCaseBadge');
    if (caseBadge) caseBadge.textContent = kpNum;

    const partyBadge = document.getElementById('drawerPartyBadge');
    const docBadge = document.getElementById('drawerDocBadge');
    const titleEl = document.getElementById('officerReturnModalTitle');
    const subTitleEl = document.getElementById('officerReturnModalSubtitle');
    const ordinals = ['', '1st', '2nd', '3rd', '4th', '5th', '6th', '7th'];
    const ord = ordinals[attemptNumber] || attemptNumber + 'th';
    const displayParty = (partyType === 'Respondent' && attemptNumber) ? `Respondent (${ord} Attempt)` : partyType;

    const activeExpandedH = window.activeExpandedHearingData || {};
    const isConciliationStage = (activeExpandedH.hearing_type === 'Conciliation' || caseItem.case_status === 'Conciliation');
    const isMediationStage = !isPaanyaya && !isConciliationStage;
    const maxAtt = isPaanyaya ? (window.AGAP_SETTINGS?.paanyaya_max_attempts || 6) : (window.AGAP_SETTINGS?.summons_max_attempts || 6);

    let stageBadgeText = '';
    let docBadgeText = '';

    if (isPaanyaya) {
        docBadgeText = `${caseItem.paanyaya_stage_type || 'Paanyaya'} Invitation`;
        stageBadgeText = `Paanyaya Stage · Invitation Attempt ${attemptNumber} of ${maxAtt}`;
    } else if (isConciliationStage) {
        docBadgeText = 'KP Form No. 12 – Notice of Hearing (Conciliation)';
        stageBadgeText = `Conciliation Stage · KP Form 12 Notice Attempt ${attemptNumber} of ${maxAtt}`;
    } else {
        docBadgeText = (partyType === 'Respondent') ? 'KP Form No. 9 – Summons' : 'KP Form No. 8 – Notice of Hearing';
        stageBadgeText = (partyType === 'Respondent')
            ? `Mediation Stage · KP Form 9 Summons Attempt ${attemptNumber} of ${maxAtt}`
            : 'Mediation Stage · KP Form 8 Complainant Notice';
    }

    if (partyBadge) {
        partyBadge.textContent = stageBadgeText;
        partyBadge.className = partyType === 'Respondent' ? 'drawer-badge drawer-badge-party-resp' : 'drawer-badge drawer-badge-party-comp';
    }
    if (docBadge) {
        docBadge.textContent = docBadgeText;
    }
    if (titleEl) {
        titleEl.textContent = isEditMode ? `Edit Officer's Return` : `Log Officer's Return`;
    }
    if (subTitleEl) {
        subTitleEl.textContent = isEditMode
            ? `Update delivery outcome, remarks, and details for ${displayParty}`
            : `Record delivery result and proof of service for ${displayParty}`;
    }

    const submitBtn = document.getElementById('submitOfficerReturnBtn');
    if (submitBtn) {
        submitBtn.innerHTML = isEditMode
            ? `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Update Officer's Return`
            : `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Save Officer's Return`;
    }

    const statusSelect = document.getElementById('returnDeliveryStatus');
    if (statusSelect) {
        const rawStat = String(currentStatus || '').trim();
        if (['Served Personal', 'Served Substituted', 'Served Refused', 'Unserved'].includes(rawStat)) {
            statusSelect.value = rawStat;
        } else if (rawStat.toLowerCase().startsWith('unserved')) {
            statusSelect.value = 'Unserved';
        } else if (rawStat.toLowerCase().startsWith('served')) {
            statusSelect.value = 'Served Personal';
        } else {
            statusSelect.value = 'Served Personal';
        }
    }

    const nowLocal = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    const servedAtInput = document.getElementById('returnServedAt');
    if (servedAtInput) {
        servedAtInput.value = nowLocal;
        servedAtInput.max = nowLocal;
    }

    const recipientInput = document.getElementById('returnRecipientName');
    if (recipientInput) {
        recipientInput.value = currentRecipient || '';
    }

    const relInput = document.getElementById('returnRelationship');
    if (relInput) {
        relInput.value = currentRelationship || '';
    }

    const remarksInput = document.getElementById('returnRemarks');
    if (remarksInput) {
        remarksInput.value = currentNotes || '';
    }

    const unservedReasonInput = document.getElementById('returnUnservedReason');
    if (unservedReasonInput) {
        if (currentStatus === 'Served Refused') {
            unservedReasonInput.value = 'Refused';
        } else if (currentStatus === 'Unserved' || String(currentStatus || '').toLowerCase().startsWith('unserved')) {
            unservedReasonInput.value = currentUnservedReason || 'Moved Out';
        } else {
            unservedReasonInput.value = '';
        }
    }

    const failNotesInput = document.getElementById('returnFailureNotes');
    if (failNotesInput) {
        failNotesInput.value = (String(currentStatus || '').toLowerCase().startsWith('unserved') ? (currentNotes || '') : '');
    }

    const proofImgInput = document.getElementById('returnProofImage');
    if (proofImgInput) proofImgInput.value = '';

    handleDeliveryStatusChange();

    modal.classList.add('is-open');
    if (overlay) overlay.classList.add('is-open');
}
window.openOfficerReturnModal = openOfficerReturnModal;

function closeOfficerReturnModal() {
    const modal = document.getElementById('officerReturnModal');
    const overlay = document.getElementById('officerReturnDrawerOverlay');
    if (modal) modal.classList.remove('is-open');
    if (overlay) overlay.classList.remove('is-open');
}
window.closeOfficerReturnModal = closeOfficerReturnModal;

function handleDeliveryStatusChange() {
    const status = document.getElementById('returnDeliveryStatus')?.value;
    const servedAtGroup = document.getElementById('servedAtGroup');
    const recipientGroup = document.getElementById('recipientNameGroup');
    const relGroup = document.getElementById('relationshipGroup');
    const unservedReasonGroup = document.getElementById('unservedReasonGroup');
    const failNotesGroup = document.getElementById('failureNotesGroup');
    const recipientReqStar = document.getElementById('recipientReqStar');
    const unservedReasonEl = document.getElementById('returnUnservedReason');

    if (status === 'Unserved') {
        if (servedAtGroup) servedAtGroup.style.display = 'none';
        if (recipientGroup) recipientGroup.style.display = 'none';
        if (relGroup) relGroup.style.display = 'none';
        if (unservedReasonGroup) unservedReasonGroup.style.display = 'block';
        if (failNotesGroup) failNotesGroup.style.display = 'block';
        if (unservedReasonEl && (!unservedReasonEl.value || unservedReasonEl.value === 'Refused')) {
            unservedReasonEl.value = 'Moved Out';
        }
    } else if (status === 'Served Substituted') {
        if (servedAtGroup) servedAtGroup.style.display = 'block';
        if (recipientGroup) recipientGroup.style.display = 'block';
        if (relGroup) relGroup.style.display = 'block';
        if (unservedReasonGroup) unservedReasonGroup.style.display = 'none';
        if (failNotesGroup) failNotesGroup.style.display = 'none';
        if (recipientReqStar) recipientReqStar.style.display = 'inline';
        if (unservedReasonEl) unservedReasonEl.value = '';
    } else if (status === 'Served Refused') {
        if (servedAtGroup) servedAtGroup.style.display = 'block';
        if (recipientGroup) recipientGroup.style.display = 'block';
        if (relGroup) relGroup.style.display = 'none';
        if (unservedReasonGroup) unservedReasonGroup.style.display = 'none';
        if (failNotesGroup) failNotesGroup.style.display = 'none';
        if (recipientReqStar) recipientReqStar.style.display = 'none';
        if (unservedReasonEl) unservedReasonEl.value = 'Refused';
    } else {
        // Served Personal
        if (servedAtGroup) servedAtGroup.style.display = 'block';
        if (recipientGroup) recipientGroup.style.display = 'block';
        if (relGroup) relGroup.style.display = 'none';
        if (unservedReasonGroup) unservedReasonGroup.style.display = 'none';
        if (failNotesGroup) failNotesGroup.style.display = 'none';
        if (recipientReqStar) recipientReqStar.style.display = 'inline';
        if (unservedReasonEl) unservedReasonEl.value = '';
    }
}
window.handleDeliveryStatusChange = handleDeliveryStatusChange;

async function submitOfficerReturn(event) {
    if (event) event.preventDefault();
    const form = document.getElementById('officerReturnForm');
    const submitBtn = document.getElementById('submitOfficerReturnBtn');
    if (!form) return;

    const status = document.getElementById('returnDeliveryStatus')?.value;
    const recipientName = document.getElementById('returnRecipientName')?.value.trim();
    const relationship = document.getElementById('returnRelationship')?.value.trim();
    const failureNotes = document.getElementById('returnFailureNotes')?.value.trim();
    const servedAt = document.getElementById('returnServedAt')?.value;

    if (status === 'Served Personal' && !recipientName) {
        window.agapNotify?.('Please enter the recipient name for personal service.', 'error');
        return;
    }
    if (status === 'Served Substituted') {
        if (!recipientName) {
            window.agapNotify?.('Recipient name is required for substituted service.', 'error');
            return;
        }
        if (!relationship) {
            window.agapNotify?.('Relationship to the party is required for substituted service.', 'error');
            return;
        }
    }
    if (status === 'Unserved' && !failureNotes) {
        window.agapNotify?.('Please provide detailed failure notes for unserved summon.', 'error');
        return;
    }
    if (status !== 'Unserved' && servedAt) {
        const servedDateObj = new Date(servedAt);
        if (servedDateObj > new Date()) {
            window.agapNotify?.('Service date and time cannot be in the future.', 'error');
            return;
        }
    }

    const isEditMode = Boolean(document.getElementById('returnDeliveryId')?.value);
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = isEditMode ? 'Updating Officer\'s Return...' : 'Saving Officer\'s Return...';
    }

    try {
        const formData = new FormData(form);
        if (status === 'Served Refused') {
            formData.set('unserved_reason', 'Refused');
        } else if (status !== 'Unserved') {
            formData.set('unserved_reason', '');
        }

        const response = await fetch(proofApiUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Failed to record officer return.');
        }

        if (result.max_attempts_reached) {
            const maxAttVal = Number(window.AGAP_SETTINGS?.summons_max_attempts || 6);
            window.agapNotify?.(result.escalation_prompt || `${maxAttVal} Attempts Failed - Max Reached (Unserved). The hearing schedule has been halted. Please verify the respondent's address with the complainant or refer the case for lack of jurisdiction / non-residence.`, 'warning', `Max Attempts Reached (${maxAttVal}/${maxAttVal} Failed)`, 12000);
        } else {
            window.agapNotify?.(result.message || 'Officer return recorded successfully.', 'success');
        }
        closeOfficerReturnModal();

        const caseId = document.getElementById('returnCaseId')?.value;
        await loadCases();
        if (caseId && activeExpandedCaseId === String(caseId)) {
            await renderExpandedCaseView(caseId);
        }
    } catch (err) {
        window.agapNotify?.(err.message, 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> ${document.getElementById('returnDeliveryId')?.value ? 'Update Officer\'s Return' : 'Save Officer\'s Return'}`;
        }
    }
}
window.submitOfficerReturn = submitOfficerReturn;

/* ==========================================================================
   Page Initialization
   ========================================================================== */

document.addEventListener('DOMContentLoaded', async () => {
    setupProofFilterTabs();
    const refreshBtn = document.getElementById('refreshCasesBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', async () => {
            refreshBtn.disabled = true;
            try {
                await loadCases();
                if (activeExpandedCaseId) {
                    await renderExpandedCaseView(activeExpandedCaseId);
                }
                window.agapNotify?.('Case records refreshed successfully.', 'success', 'Proof of Service');
            } catch (e) {
                window.agapNotify?.(e.message || 'Failed to refresh records.', 'error');
            } finally {
                refreshBtn.disabled = false;
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    const requestedCaseId = urlParams.get('case_id') || '';
    const requestedComplaintId = urlParams.get('complaint_id') || '';
    const shouldOpenReturn = urlParams.get('open_return') === '1';

    try {
        await loadCases(requestedCaseId);

        let targetCase = null;
        if (requestedCaseId) {
            targetCase = allLoadedSummonCases.find(c => Number(c.case_id) === Number(requestedCaseId));
        }
        if (!targetCase && requestedComplaintId) {
            targetCase = allLoadedSummonCases.find(c => Number(c.complaint_id) === Number(requestedComplaintId) || Number(c.case_id) === Number(requestedComplaintId));
        }

        if (targetCase) {
            // Setup Back Link
            const backLink = document.getElementById('backLink');
            const backLinkLabel = document.getElementById('backLinkLabel');
            const userRoleId = Number(window.AGAP_USER_ROLE_ID || window.AGAP_SETTINGS?.user_role_id || 0);
            if (backLink && userRoleId !== 4) {
                backLink.href = `../complaints/complaint-details.php?id=${encodeURIComponent(targetCase.complaint_id || targetCase.case_id)}&tab=paanyaya`;
                if (backLinkLabel) {
                    backLinkLabel.textContent = `Back to Case Details (${targetCase.case_number || targetCase.complaint_number || 'Paanyaya'})`;
                }
                backLink.hidden = false;
            } else if (backLink) {
                backLink.hidden = true;
            }

            if (String(activeExpandedCaseId) !== String(targetCase.case_id)) {
                await toggleExpandCase(targetCase.case_id);
            }

                        if (shouldOpenReturn) {
                setTimeout(() => {
                    const activeHearing = window.activeExpandedHearingData || {};
                    const respAttempts = activeHearing.respondent_attempts || [];
                    const anyRespServed = respAttempts.some(a => a.is_served);
                    if (anyRespServed) {
                        return; // Prevent opening return modal if already served
                    }
                    const pendingAttempt = respAttempts.find(a => a.is_pending) || null;
                    const nextAttemptNum = pendingAttempt ? pendingAttempt.attempt_number : (respAttempts.length + 1);
                    const respResidentId = Number(activeHearing.deliveries?.find(d => d.party_type === 'Respondent')?.resident_id || 0);
                    const respName = activeHearing.deliveries?.find(d => d.party_type === 'Respondent')?.recipient_name || targetCase.respondent_names || 'Respondent';
                    openOfficerReturnModal(
                        'Respondent',
                        respResidentId,
                        activeHearing.hearing_id,
                        targetCase.case_id,
                        'Pending',
                        respName,
                        '',
                        '',
                        '',
                        nextAttemptNum,
                        null
                    );
                }, 350);
            }
        }
    } catch (error) {
        resetCaseView();
        setGpsMessage(error.message || 'Unable to load available cases.');
    }
});

/* ==========================================================================
   Case Service History & Multi-Form Delivered Timeline
   ========================================================================== */

async function renderCaseServiceTimeline(caseId) {
    const section = document.getElementById('caseServiceTimelineSection');
    const container = document.getElementById('caseServiceTimelineContainer');
    const subtitle = document.getElementById('timelineCaseSubtitle');
    if (!section || !container) return;

    section.style.display = 'block';
    container.innerHTML = '<div style="text-align: center; color: #64748b; padding: 24px;"><div style="display: inline-block; width: 20px; height: 20px; border: 2px solid #0284c7; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite;"></div> Loading case service history & delivered forms timeline...</div>';

    try {
        const res = await gpsApi(`${proofApiUrl}?case_id=${encodeURIComponent(caseId)}&mode=timeline`);
        if (!res.success || !res.stages) {
            container.innerHTML = `<div style="text-align: center; color: #dc2626; padding: 16px;">${escapeGpsHtml(res.message || 'Unable to load service timeline.')}</div>`;
            return;
        }

        if (subtitle) {
            subtitle.textContent = `Chronological stream of all statutory invitations, notices, summons, and officer returns for Case ${res.case_number} (${res.case_status})`;
        }

        let html = '';
        res.stages.forEach(stg => {
            const items = stg.items || [];
            let badgeStyle = 'background: #f1f5f9; color: #64748b;';
            if (stg.badge === 'In Progress') badgeStyle = 'background: #e0f2fe; color: #0369a1;';
            else if (stg.badge === 'Concluded' || stg.badge === 'Completed') badgeStyle = 'background: #dcfce7; color: #15803d;';

            html += `
                <div class="timeline-stage-group" style="margin-bottom: 22px; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; background: #fff;">
                    <div style="background: #f8fafc; padding: 12px 18px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <strong style="font-size: 0.95rem; color: #0f172a;">${escapeGpsHtml(stg.stage_name)}</strong>
                            <span style="font-size: 0.8rem; color: #64748b; margin-left: 8px;">${escapeGpsHtml(stg.stage_title)}</span>
                        </div>
                        <span style="${badgeStyle} font-size: 0.75rem; font-weight: 700; padding: 3px 10px; border-radius: 9999px;">
                            ${escapeGpsHtml(stg.badge)}
                        </span>
                    </div>
                    <div style="padding: 14px 18px;">
            `;

            if (items.length === 0) {
                html += `
                    <div style="color: #94a3b8; font-size: 0.84rem; font-style: italic; padding: 8px 0;">
                        No statutory documents or service attempts recorded for this stage.
                    </div>
                `;
            } else {
                html += `
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                `;
                items.forEach((item, idx) => {
                    let pillBg = '#f1f5f9';
                    let pillColor = '#475569';
                    let pillIcon = '⚪';
                    if (item.status_pill === 'served-personal') {
                        pillBg = '#dcfce7'; pillColor = '#15803d'; pillIcon = '🟢';
                    } else if (item.status_pill === 'served-substituted') {
                        pillBg = '#e0f2fe'; pillColor = '#0369a1'; pillIcon = '🔵';
                    } else if (item.status_pill === 'refused') {
                        pillBg = '#fef3c7'; pillColor = '#b45309'; pillIcon = '🟡';
                    } else if (item.status_pill === 'unserved') {
                        pillBg = '#fee2e2'; pillColor = '#b91c1c'; pillIcon = '🔴';
                    }

                    const formattedDate = item.service_datetime ? formatDateTime(item.service_datetime) : 'Pending / Not Recorded';

                    html += `
                        <div style="display: flex; gap: 14px; align-items: flex-start; padding: 12px 14px; background: #fafafa; border: 1px solid #eef2f6; border-radius: 8px;">
                            <div style="flex-shrink: 0; width: 40px; height: 40px; border-radius: 8px; background: #fff; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                                ${item.document_code.includes('10') ? '👥' : (item.document_code.includes('11') ? '📜' : (item.document_code.includes('12') ? '⚖️' : (item.document_code.includes('9') ? '📩' : (item.document_code.includes('8') ? '📋' : '✉️'))))}
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 4px;">
                                    <div>
                                        <strong style="color: #0f172a; font-size: 0.9rem;">${escapeGpsHtml(item.document_code)}</strong>
                                        <span style="font-size: 0.8rem; color: #475569; margin-left: 6px;">${escapeGpsHtml(item.document_title)}</span>
                                        ${item.attempt_label ? `<span style="margin-left: 6px; background: #f1f5f9; color: #334155; font-size: 0.72rem; font-weight: 700; padding: 1px 6px; border-radius: 4px;">${escapeGpsHtml(item.attempt_label)}</span>` : ''}
                                    </div>
                                    <span style="background: ${pillBg}; color: ${pillColor}; font-size: 0.74rem; font-weight: 700; padding: 2px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;">
                                        ${pillIcon} ${escapeGpsHtml(item.delivery_status)}
                                    </span>
                                </div>
                                <div style="font-size: 0.82rem; color: #475569; margin-bottom: 6px; display: flex; flex-wrap: wrap; gap: 14px;">
                                    <span>Recipient: <strong>${escapeGpsHtml(item.recipient_name)}</strong> (${escapeGpsHtml(item.party_type)})</span>
                                    <span>Server: <strong>${escapeGpsHtml(item.served_by_name)}</strong></span>
                                    <span>Timestamp: <strong>${escapeGpsHtml(formattedDate)}</strong></span>
                                    ${item.unserved_reason ? `<span style="color: #dc2626;">Reason: <strong>${escapeGpsHtml(item.unserved_reason)}</strong></span>` : ''}
                                </div>
                                ${item.remarks ? `<div style="font-size: 0.78rem; color: #64748b; font-style: italic; margin-bottom: 6px;">Notes: ${escapeGpsHtml(item.remarks)}</div>` : ''}
                                <div style="display: flex; gap: 10px; align-items: center; font-size: 0.8rem;">
                                    ${item.preview_url ? `
                                        <a href="${item.preview_url}" target="_blank" style="color: #0284c7; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                            📄 View Document
                                        </a>
                                    ` : ''}
                                    ${item.proof_file_path ? `
                                        <a href="../../${escapeGpsHtml(item.proof_file_path)}" target="_blank" style="color: #16a34a; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                            📷 View Photo Proof
                                        </a>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }

            html += `</div></div>`;
        });

        container.innerHTML = html;
    } catch (e) {
        console.error('renderCaseServiceTimeline error:', e);
        container.innerHTML = `<div style="text-align: center; color: #dc2626; padding: 16px;">Failed to load timeline: ${escapeGpsHtml(e.message)}</div>`;
    }
}
window.renderCaseServiceTimeline = renderCaseServiceTimeline;

function toggleTimelineVisibility() {
    const container = document.getElementById('caseServiceTimelineContainer');
    const btn = document.getElementById('toggleTimelineBtn');
    if (!container || !btn) return;
    if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.textContent = 'Collapse Timeline';
    } else {
        container.style.display = 'none';
        btn.textContent = 'Expand Timeline';
    }
}
window.toggleTimelineVisibility = toggleTimelineVisibility;