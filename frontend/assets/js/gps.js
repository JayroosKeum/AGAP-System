const proofApiUrl = '../../../backend/api/gps/proof-service.php';

function escapeGpsHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
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
        throw new Error(
            'The server returned an invalid response.'
        );
    }

    if (!response.ok || data.success === false) {
        throw new Error(
            data.message ||
            'Unable to load proof-of-service details.'
        );
    }

    return data;
}

function setGpsMessage(message = '', type = 'error') {
    const target =
        document.getElementById('proofMessage');

    if (!target) {
        return;
    }

    target.textContent = message;

    target.className = message
        ? `proof-message is-visible ${
            type === 'success'
                ? 'is-success'
                : 'is-error'
        }`
        : 'proof-message';

    if (
        message &&
        typeof window.agapNotify === 'function'
    ) {
        window.agapNotify(
            message,
            type === 'success'
                ? 'success'
                : 'error',
            'Proof of Service'
        );
    }
}

function formatDateTime(value) {
    if (!value) {
        return 'Not recorded';
    }

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
    const normalized = String(
        result || ''
    ).toLowerCase();

    if (normalized === 'served') {
        return 'badge-served';
    }

    if (normalized === 'not served') {
        return 'badge-not-served';
    }

    if (normalized === 'refused') {
        return 'badge-refused';
    }

    if (
        normalized === 'respondent not found' ||
        normalized === 'address problem'
    ) {
        return 'badge-warning';
    }

    return 'badge-other';
}

function serviceStatusClass(status) {
    const normalized = String(
        status || ''
    ).toLowerCase();

    if (normalized === 'served') {
        return 'badge-served';
    }

    if (normalized === 'service failed') {
        return 'badge-not-served';
    }

    if (normalized === 'for service') {
        return 'badge-warning';
    }

    return 'badge-other';
}

function setEmptyState(
    tableId,
    colspan,
    message
) {
    const table =
        document.getElementById(tableId);

    if (!table) {
        return;
    }

    table.innerHTML = `
        <tr>
            <td
                colspan="${colspan}"
                class="empty-state"
            >
                ${escapeGpsHtml(message)}
            </td>
        </tr>
    `;
}

function pluralize(
    count,
    singular,
    plural = `${singular}s`
) {
    return `${count} ${
        count === 1 ? singular : plural
    }`;
}

function updateCounts(
    noticeCount = 0,
    historyCount = 0
) {
    const notices =
        document.getElementById('noticeCount');

    const history =
        document.getElementById('historyCount');

    if (notices) {
        notices.textContent = pluralize(
            noticeCount,
            'notice'
        );
    }

    if (history) {
        history.textContent = pluralize(
            historyCount,
            'attempt'
        );
    }
}

function resetCaseView() {
    const summary =
        document.getElementById(
            'caseSummaryCard'
        );

    const backLink =
        document.getElementById('backLink');

    const delivSection =
        document.getElementById('hearingDeliveriesSection');

    if (summary) {
        summary.hidden = true;
    }

    if (backLink) {
        backLink.hidden = true;
    }

    if (delivSection) {
        delivSection.hidden = true;
    }

    updateCounts(0, 0);

    setEmptyState(
        'summonsNoticesTable',
        5,
        'Select a case to view its summons notices.'
    );

    setEmptyState(
        'proofHistoryTable',
        7,
        'Select a case to view its service history.'
    );
}

let allLoadedSummonCases = [];
let activeSelectedCaseId = '';

function renderSummonCasesTable(cases, selectedCaseId = '') {
    const tbody = document.getElementById('casesNeedingSummonsTable');
    const countBadge = document.getElementById('casesNeedingSummonsCount');

    if (countBadge) {
        countBadge.textContent = pluralize(cases.length, 'case');
    }

    if (!tbody) return;

    if (!cases.length) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="empty-state" style="padding: 30px; text-align: center; color: #94a3b8;">
                    No cases requiring summons delivery found.
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = cases
        .map((item) => {
            const caseId = Number(item.case_id);
            const isSelected = String(caseId) === String(selectedCaseId || activeSelectedCaseId);
            const selectedClass = isSelected ? 'case-table-row-selected' : '';
            const kpNumber = item.case_number || (item.case_id ? `Case #${item.case_id}` : 'N/A');
            const complainant = item.complainant_names || 'Not recorded';
            const respondent = item.respondent_names || 'Not recorded';
            const hearingDate = item.hearing_date
                ? `
                    <div style="font-weight: 600; color: #0f172a; font-size: 0.88rem;">
                        ${escapeGpsHtml(formatDateTime(item.hearing_date))}
                    </div>
                    ${item.hearing_type ? `<span style="font-size: 0.72rem; padding: 2px 7px; background: #e0f2fe; color: #0369a1; border-radius: 4px; display: inline-block; margin-top: 3px; font-weight: 600;">${escapeGpsHtml(item.hearing_type)}</span>` : ''}
                `
                : '<span style="color: #94a3b8; font-style: italic; font-size: 0.84rem;">Pending schedule</span>';

            return `
                <tr id="summon-row-${caseId}" class="${selectedClass}" style="cursor: pointer; transition: background 0.15s ease;" onclick="selectSummonCase(${caseId})">
                    <td style="padding: 12px 16px;">
                        <strong style="color: #2563eb; font-size: 0.92rem;">
                            ${escapeGpsHtml(kpNumber)}
                        </strong>
                        ${item.complaint_title ? `<div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">${escapeGpsHtml(item.complaint_title)}</div>` : ''}
                    </td>
                    <td style="padding: 12px 16px; font-weight: 500; color: #1e293b;">
                        ${escapeGpsHtml(complainant)}
                    </td>
                    <td style="padding: 12px 16px; font-weight: 500; color: #1e293b;">
                        ${escapeGpsHtml(respondent)}
                    </td>
                    <td style="padding: 12px 16px;">
                        ${hearingDate}
                    </td>
                    <td style="padding: 12px 16px; text-align: center;" onclick="event.stopPropagation();">
                        <button
                            type="button"
                            class="btn-table-action"
                            onclick="selectSummonCase(${caseId})"
                            style="background: #2563eb; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 0.82rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: background 0.15s ease;"
                            onmouseover="this.style.background='#1d4ed8'"
                            onmouseout="this.style.background='#2563eb'"
                            title="Inspect and record summon delivery for ${escapeGpsHtml(kpNumber)}"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                            Deliver Summon
                        </button>
                    </td>
                </tr>
            `;
        })
        .join('');
}

function selectSummonCase(caseId) {
    if (!caseId) return;
    activeSelectedCaseId = String(caseId);

    // Highlight row
    const tbody = document.getElementById('casesNeedingSummonsTable');
    if (tbody) {
        tbody.querySelectorAll('tr').forEach((tr) => tr.classList.remove('case-table-row-selected'));
        const targetRow = document.getElementById(`summon-row-${caseId}`);
        if (targetRow) targetRow.classList.add('case-table-row-selected');
    }

    const select = document.getElementById('proofCaseId');
    if (select) select.value = String(caseId);

    loadCaseView(caseId);

    const delivSection = document.getElementById('hearingDeliveriesSection');
    if (delivSection) {
        delivSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}
window.selectSummonCase = selectSummonCase;

function setupSummonCaseSearch() {
    const searchInput = document.getElementById('summonCaseSearch');
    const clearBtn = document.getElementById('clearSummonSearch');
    if (!searchInput || searchInput.dataset.bound) return;
    searchInput.dataset.bound = '1';

    const handleFilter = () => {
        const query = searchInput.value.trim().toLowerCase();
        if (clearBtn) clearBtn.style.display = query ? 'block' : 'none';

        if (!query) {
            renderSummonCasesTable(allLoadedSummonCases, activeSelectedCaseId);
            return;
        }

        const filtered = allLoadedSummonCases.filter((item) => {
            const kp = String(item.case_number || '').toLowerCase();
            const comp = String(item.complainant_names || '').toLowerCase();
            const resp = String(item.respondent_names || '').toLowerCase();
            const title = String(item.complaint_title || '').toLowerCase();
            const date = String(item.hearing_date || '').toLowerCase();
            return kp.includes(query) || comp.includes(query) || resp.includes(query) || title.includes(query) || date.includes(query);
        });

        renderSummonCasesTable(filtered, activeSelectedCaseId);
    };

    searchInput.addEventListener('input', handleFilter);

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            searchInput.value = '';
            handleFilter();
            searchInput.focus();
        });
    }
}

async function loadCases(
    preselectedCaseId = ''
) {
    const select =
        document.getElementById('proofCaseId');

    const result =
        await gpsApi(proofApiUrl);

    allLoadedSummonCases =
        Array.isArray(result.data)
            ? result.data
            : [];

    if (select) {
        select.innerHTML =
            '<option value="">Select a case</option>' +
            allLoadedSummonCases
                .map((item) => {
                    const caseNumber =
                        item.case_number ||
                        `Case ${item.case_id}`;

                    const complaintTitle =
                        item.complaint_title ||
                        'Untitled complaint';

                    const label =
                        `${caseNumber} - ${complaintTitle}`;

                    return `
                        <option value="${Number(item.case_id)}">
                            ${escapeGpsHtml(label)}
                        </option>
                    `;
                })
                .join('');
    }

    setupSummonCaseSearch();

    renderSummonCasesTable(allLoadedSummonCases, preselectedCaseId);

    const validPreselectedCase =
        preselectedCaseId &&
        allLoadedSummonCases.some(
            (item) =>
                Number(item.case_id) ===
                Number(preselectedCaseId)
        );

    if (validPreselectedCase) {
        selectSummonCase(preselectedCaseId);
    }
}

async function loadCaseSummary(caseId) {
    const result = await gpsApi(
        `${proofApiUrl}?case_id=${
            encodeURIComponent(caseId)
        }&mode=summary`
    );

    const data = result.data || {};

    const caseNumberElement =
        document.getElementById(
            'summaryCaseNumber'
        );

    const complaintNumberElement =
        document.getElementById(
            'summaryComplaintNumber'
        );

    const complaintTitleElement =
        document.getElementById(
            'summaryComplaintTitle'
        );

    const complainantsElement =
        document.getElementById(
            'summaryComplainants'
        );

    const respondentsElement =
        document.getElementById(
            'summaryRespondents'
        );

    const caseStatusElement =
        document.getElementById(
            'summaryCaseStatus'
        );

    const complaintStatusElement =
        document.getElementById(
            'summaryComplaintStatus'
        );

    if (caseNumberElement) {
        caseNumberElement.textContent =
            data.case_number
                ? `Case ${data.case_number}`
                : `Case ${caseId}`;
    }

    if (complaintNumberElement) {
        complaintNumberElement.textContent =
            data.complaint_number ||
            `Complaint ${data.complaint_id || ''}`;
    }

    if (complaintTitleElement) {
        complaintTitleElement.textContent =
            data.complaint_title ||
            'Untitled Complaint';
    }

    if (complainantsElement) {
        complainantsElement.textContent =
            Array.isArray(data.complainants) &&
            data.complainants.length
                ? data.complainants.join(', ')
                : 'None listed';
    }

    if (respondentsElement) {
        respondentsElement.textContent =
            Array.isArray(data.respondents) &&
            data.respondents.length
                ? data.respondents.join(', ')
                : 'None listed';
    }

    if (caseStatusElement) {
        caseStatusElement.textContent =
            data.case_status ||
            'Not available';
    }

    if (complaintStatusElement) {
        complaintStatusElement.textContent =
            data.complaint_status ||
            'Not available';
    }

    const complaintId =
        Number(data.complaint_id) || 0;

    const complaintLink =
        document.getElementById(
            'viewComplaintLink'
        );

    const backLink =
        document.getElementById(
            'backLink'
        );

    if (complaintId) {
        const complaintUrl =
            `../complaints/complaint-details.php` +
            `?id=${complaintId}`;

        if (complaintLink) {
            complaintLink.href =
                complaintUrl;
        }

        if (backLink) {
            backLink.href =
                complaintUrl;

            backLink.hidden = false;
        }
    } else {
        if (complaintLink) {
            complaintLink.href =
                '../complaints/complaint-list.php';
        }

        if (backLink) {
            backLink.hidden = true;
        }
    }

    const summaryCard =
        document.getElementById(
            'caseSummaryCard'
        );

    if (summaryCard) {
        summaryCard.hidden = false;
    }
}

async function loadSummonsNotices(caseId) {
    const table =
        document.getElementById(
            'summonsNoticesTable'
        );

    if (!table) {
        return;
    }

    const result = await gpsApi(
        `${proofApiUrl}?case_id=${
            encodeURIComponent(caseId)
        }&mode=notices`
    );

    const rows =
        Array.isArray(result.data)
            ? result.data
            : [];

    const noticeCount =
        document.getElementById(
            'noticeCount'
        );

    if (noticeCount) {
        noticeCount.textContent =
            pluralize(
                rows.length,
                'notice'
            );
    }

    if (!rows.length) {
        setEmptyState(
            'summonsNoticesTable',
            5,
            'No summons notices have been issued for this case.'
        );

        return;
    }

    if (!table) {
        return;
    }

    table.innerHTML = rows
        .map((item) => {
            const documentId =
                Number(item.document_id) || 0;

            const attempts =
                Number(item.attempt_count) || 0;

            const status =
                item.service_status ||
                'Generated';

            const lastAttempt =
                item.last_attempt_at
                    ? `
                        <span class="table-subtext">
                            Last:
                            ${
                                escapeGpsHtml(
                                    formatDateTime(
                                        item.last_attempt_at
                                    )
                                )
                            }
                        </span>
                    `
                    : `
                        <span class="table-subtext">
                            No recorded attempt
                        </span>
                    `;

            const documentLink =
                documentId > 0
                    ? `
                        <a
                            class="btn-table-link"
                            href="../../../backend/api/documents/download.php?id=${documentId}"
                            target="_blank"
                            rel="noopener"
                        >
                            View PDF
                        </a>
                    `
                    : `
                        <span class="muted-value">
                            Unavailable
                        </span>
                    `;

            return `
                <tr>
                    <td>
                        ${
                            escapeGpsHtml(
                                formatDateTime(
                                    item.generated_at
                                )
                            )
                        }
                    </td>

                    <td>
                        <strong>
                            ${
                                escapeGpsHtml(
                                    item.template_name ||
                                    'Summons Notice'
                                )
                            }
                        </strong>

                        <span class="table-subtext">
                            ${
                                documentId > 0
                                    ? `Document #${documentId}`
                                    : 'Document ID unavailable'
                            }
                        </span>
                    </td>

                    <td>
                        <span
                            class="service-result-badge ${
                                serviceStatusClass(status)
                            }"
                        >
                            ${escapeGpsHtml(status)}
                        </span>
                    </td>

                    <td>
                        <strong>${attempts}</strong>
                        ${lastAttempt}
                    </td>

                    <td>
                        ${documentLink}
                    </td>
                </tr>
            `;
        })
        .join('');
}

async function loadProofs(caseId) {
    const table =
        document.getElementById(
            'proofHistoryTable'
        );

    const result = await gpsApi(
        `${proofApiUrl}?case_id=${
            encodeURIComponent(caseId)
        }`
    );

    const rows =
        Array.isArray(result.data)
            ? result.data
            : [];

    const historyCount =
        document.getElementById(
            'historyCount'
        );

    if (historyCount) {
        historyCount.textContent =
            pluralize(
                rows.length,
                'attempt'
            );
    }

    if (!rows.length) {
        setEmptyState(
            'proofHistoryTable',
            7,
            'No proof-of-service attempts have been recorded for this case.'
        );

        return;
    }

    if (!table) {
        return;
    }

    table.innerHTML = rows
        .map((item, index) => {
            const proofId =
                Number(item.proof_id) || 0;

            const attemptNumber =
                rows.length - index;

            const resultLabel =
                item.service_result ||
                'Not recorded';

            const documentId =
                Number(item.document_id) || 0;

            const documentLabel =
                documentId > 0
                    ? `Document #${documentId}`
                    : 'Document not recorded';

            const proofLink =
                item.image_path &&
                proofId > 0
                    ? `
                        <a
                            class="proof-image-link"
                            href="../../../backend/api/gps/proof-image.php?id=${proofId}"
                            target="_blank"
                            rel="noopener"
                        >
                            <img
                                class="proof-image"
                                src="../../../backend/api/gps/proof-image.php?id=${proofId}"
                                alt="Proof image for attempt ${attemptNumber}"
                                loading="lazy"
                            >

                            <span>Open image</span>
                        </a>
                    `
                    : `
                        <span class="muted-value">
                            No image
                        </span>
                    `;

            const remarks =
                item.remarks
                    ? escapeGpsHtml(
                        item.remarks
                    )
                    : `
                        <span class="muted-value">
                            No remarks
                        </span>
                    `;

            return `
                <tr>
                    <td>
                        <span class="attempt-chip">
                            #${attemptNumber}
                        </span>
                    </td>

                    <td>
                        <strong>
                            ${
                                escapeGpsHtml(
                                    item.template_name ||
                                    'Summons Notice'
                                )
                            }
                        </strong>

                        <span class="table-subtext">
                            ${escapeGpsHtml(documentLabel)}
                        </span>
                    </td>

                    <td>
                        ${
                            escapeGpsHtml(
                                formatDateTime(
                                    item.served_date
                                )
                            )
                        }
                    </td>

                    <td>
                        ${
                            escapeGpsHtml(
                                item.served_by_name ||
                                'Not recorded'
                            )
                        }
                    </td>

                    <td>
                        <span
                            class="service-result-badge ${
                                serviceResultClass(
                                    resultLabel
                                )
                            }"
                        >
                            ${
                                escapeGpsHtml(
                                    resultLabel
                                )
                            }
                        </span>
                    </td>

                    <td class="remarks-cell">
                        ${remarks}
                    </td>

                    <td>
                        ${proofLink}
                    </td>
                </tr>
            `;
        })
        .join('');
}

async function loadCaseView(caseId) {
    if (!caseId) {
        resetCaseView();

        const resetUrl =
            new URL(
                window.location.href
            );

        resetUrl.searchParams.delete(
            'case_id'
        );

        window.history.replaceState(
            {},
            '',
            resetUrl
        );

        return;
    }

    setGpsMessage('');

    const summaryCard =
        document.getElementById(
            'caseSummaryCard'
        );

    if (summaryCard) {
        summaryCard.hidden = true;
    }

    setEmptyState(
        'summonsNoticesTable',
        5,
        'Loading summons notices...'
    );

    setEmptyState(
        'proofHistoryTable',
        7,
        'Loading service history...'
    );

    try {
        await Promise.all([
            loadCaseSummary(caseId),
            loadHearingDeliveries(caseId),
            loadSummonsNotices(caseId),
            loadProofs(caseId)
        ]);

        const url =
            new URL(
                window.location.href
            );

        url.searchParams.set(
            'case_id',
            caseId
        );

        window.history.replaceState(
            {},
            '',
            url
        );
    } catch (error) {
        resetCaseView();

        setGpsMessage(
            error.message ||
            'Unable to load the selected case.'
        );
    }
}

async function loadHearingDeliveries(caseId) {
    const section = document.getElementById('hearingDeliveriesSection');
    const container = document.getElementById('hearingDeliveriesContainer');
    const alertBanner = document.getElementById('serviceStatusAlert');

    if (!section || !container) return;

    try {
        const result = await gpsApi(`${proofApiUrl}?case_id=${encodeURIComponent(caseId)}&mode=deliveries`);
        const data = result.data || {};

        if (!data.has_hearings || !Array.isArray(data.hearings) || data.hearings.length === 0) {
            section.hidden = false;
            if (alertBanner) {
                alertBanner.className = 'service-status-banner status-banner-pending';
                alertBanner.innerHTML = '<span>ℹ️ No scheduled hearings found for this case. Issue a summons to schedule 1st Mediation.</span>';
                alertBanner.style.display = 'flex';
            }
            container.innerHTML = `
                <div style="grid-column: 1 / -1; padding: 24px; text-align: center; color: #64748b; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px;">
                    No hearings or summon deliveries have been scheduled for this case yet.
                </div>
            `;
            return;
        }

        section.hidden = false;

        const activeHearing = data.hearings[0];
        const deliveries = activeHearing.deliveries || [];
        const respAttempts = activeHearing.respondent_attempts || [];
        const cfaElig = data.cfa_eligibility || activeHearing.cfa_eligibility || {
            eligible: false,
            failed_count: 0,
            attempts_count: 0,
            reason: 'CFA is locked until all 3 summons attempts have failed.'
        };
        const failedCount = Number(cfaElig.failed_count || 0);
        const isCfaEligible = Boolean(cfaElig.eligible);

        // Render attendance lock / unlock alert banner
        if (alertBanner) {
            const isUnlocked = activeHearing.attendance_unlocked;
            const noticeMsg = activeHearing.service_notice || '';
            const isUnserved = deliveries.some(d => d.delivery_status === 'Unserved');

            if (isUnlocked) {
                alertBanner.className = 'service-status-banner status-banner-served';
                alertBanner.innerHTML = `
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span><strong>Service Complete:</strong> All summons and notices served. Scheduled mediation hearing attendance tracking is unlocked.</span>
                `;
            } else if (isUnserved) {
                alertBanner.className = 'service-status-banner status-banner-unserved';
                alertBanner.innerHTML = `
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><strong>Delivery Failed (Paused):</strong> ${escapeGpsHtml(noticeMsg || 'One or more summons were marked Unserved. Mediation hearing and attendance are paused until service is confirmed or address corrected.')}</span>
                `;
            } else {
                alertBanner.className = 'service-status-banner status-banner-pending';
                alertBanner.innerHTML = `
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><strong>Service Pending:</strong> ${escapeGpsHtml(noticeMsg || 'Delivery is still pending. Record officer returns to confirm service before holding the mediation session.')}</span>
                `;
            }
            alertBanner.style.display = 'flex';
        }

        const hearingDateFormatted = activeHearing.hearing_date
            ? formatDateTime(activeHearing.hearing_date)
            : 'Mediation Session';

        // 1. CFA Status Card
        const att1 = respAttempts.find(a => a.attempt_number === 1) || null;
        const att2 = respAttempts.find(a => a.attempt_number === 2) || null;
        const att3 = respAttempts.find(a => a.attempt_number === 3) || null;

        const renderStepBadge = (att, num) => {
            const ordinals = ['', '1st', '2nd', '3rd'];
            const ord = ordinals[num] || num + 'th';
            if (!att) {
                return `
                    <div style="flex: 1; min-width: 150px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 1.1rem; color: #94a3b8;">⚪</span>
                            <div>
                                <strong style="font-size: 0.82rem; color: #64748b; display: block;">${ord} Attempt</strong>
                                <span style="font-size: 0.72rem; color: #94a3b8;">Not yet issued</span>
                            </div>
                        </div>
                    </div>
                `;
            }
            if (att.is_failed) {
                return `
                    <div style="flex: 1; min-width: 150px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 1.1rem; color: #dc2626;">❌</span>
                            <div>
                                <strong style="font-size: 0.82rem; color: #991b1b; display: block;">${ord} Attempt (Failed)</strong>
                                <span style="font-size: 0.72rem; color: #b91c1c; font-weight: 600;">${escapeGpsHtml(att.unserved_reason || 'Unserved')}</span>
                            </div>
                        </div>
                    </div>
                `;
            }
            if (att.is_served) {
                return `
                    <div style="flex: 1; min-width: 150px; padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 1.1rem; color: #16a34a;">✅</span>
                            <div>
                                <strong style="font-size: 0.82rem; color: #166534; display: block;">${ord} Attempt (Served)</strong>
                                <span style="font-size: 0.72rem; color: #15803d;">CFA Ineligible</span>
                            </div>
                        </div>
                    </div>
                `;
            }
            return `
                <div style="flex: 1; min-width: 150px; padding: 10px 14px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-size: 1.1rem; color: #d97706;">⏳</span>
                        <div>
                            <strong style="font-size: 0.82rem; color: #92400e; display: block;">${ord} Attempt (Pending)</strong>
                            <span style="font-size: 0.72rem; color: #b45309;">Awaiting return</span>
                        </div>
                    </div>
                </div>
            `;
        };

        let cfaActionHtml = '';
        if (isCfaEligible) {
            cfaActionHtml = `
                <a href="../documents/cfa.php?case_id=${encodeURIComponent(caseId)}" class="btn-create" style="display: inline-flex; align-items: center; gap: 6px; background: #16a34a; color: #fff; text-decoration: none; padding: 9px 18px; border-radius: 8px; font-weight: 600; font-size: 0.88rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    Print Certificate to File Action (CFA - KP Form 20) &rarr;
                </a>
            `;
        } else {
            cfaActionHtml = `
                <button type="button" class="btn-secondary" disabled title="${escapeGpsHtml(cfaElig.reason || 'CFA is locked until all 3 summons attempts have resulted in failure.')}" style="opacity: 0.6; cursor: not-allowed; display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; font-size: 0.88rem;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    Print CFA (Locked — ${failedCount}/3 Failed)
                </button>
            `;
        }

        const cfaCardHtml = `
            <div class="cfa-status-card" style="grid-column: 1 / -1; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 14px;">
                    <div>
                        <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #2563eb;">Statutory Dispute Progression</span>
                        <h3 style="margin: 2px 0 0; font-size: 1.15rem; font-weight: 700; color: #1e293b;">Certificate to File Action (CFA - KP Form 20)</h3>
                    </div>
                    <div>
                        ${isCfaEligible
                            ? '<span class="service-result-badge badge-served" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:0.84rem; padding:5px 12px;">✅ CFA Unlocked</span>'
                            : '<span class="service-result-badge badge-warning" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-size:0.84rem; padding:5px 12px;">🔒 CFA Locked</span>'
                        }
                    </div>
                </div>

                <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
                    ${renderStepBadge(att1, 1)}
                    ${renderStepBadge(att2, 2)}
                    ${renderStepBadge(att3, 3)}
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; border-top: 1px solid #f1f5f9; padding-top: 14px;">
                    <div style="font-size: 0.88rem; color: #475569; display: flex; align-items: center; gap: 8px;">
                        <strong>Summons Attempts:</strong>
                        <span style="font-weight: 700; color: ${failedCount >= 3 ? '#16a34a' : (failedCount > 0 ? '#d97706' : '#64748b')};">${failedCount}/3 Failed</span>
                        <span style="color: #94a3b8;">·</span>
                        <span>${escapeGpsHtml(cfaElig.reason || '')}</span>
                    </div>
                    <div>
                        ${cfaActionHtml}
                    </div>
                </div>
            </div>
        `;

        // 2. Complainant Notice of Hearing (KP Form 8) Card
        const compDeliv = deliveries.find(d => d.party_type === 'Complainant') || null;
        const compProfile = compDeliv?.party_profile || {};
        const compName = compDeliv?.recipient_name || compProfile.resident_name || 'Complainant';
        const compStatus = compDeliv?.delivery_status || 'Pending';
        const compIsServed = compStatus.startsWith('Served');
        const compIsAutoServed = compIsServed && (compDeliv?.remarks?.includes('print') || compDeliv?.remarks?.includes('First Mediation') || compDeliv?.delivery_status === 'Served');

        let compBadgeHtml = '';
        if (compIsServed) {
            compBadgeHtml = `<span class="service-result-badge badge-served">Served ${compIsAutoServed ? '(Print Workflow)' : ''}</span>`;
        } else if (compStatus === 'Unserved') {
            compBadgeHtml = `<span class="service-result-badge badge-not-served">Unserved / Failed</span>`;
        } else {
            compBadgeHtml = `<span class="service-result-badge badge-warning">Pending Service</span>`;
        }

        const compResidentId = compDeliv?.resident_id || compProfile.resident_id || 0;

        const compCardHtml = `
            <div class="delivery-card">
                <div class="delivery-card-header">
                    <div>
                        <span class="delivery-party-role">Complainant</span>
                        <div class="delivery-party-name">
                            <span>${escapeGpsHtml(compName)}</span>
                        </div>
                        <span class="delivery-form-type">KP Form No. 8 – Notice of Hearing</span>
                    </div>
                    <div>
                        ${compBadgeHtml}
                    </div>
                </div>

                <div class="delivery-card-body">
                    <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 8px;">
                        Hearing: <strong>${escapeGpsHtml(activeHearing.hearing_type)}</strong> (${escapeGpsHtml(hearingDateFormatted)})
                    </div>

                    ${compIsAutoServed ? `
                        <div style="margin-bottom: 10px; padding: 8px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; font-size: 0.8rem; color: #166534;">
                            💡 <strong>Auto-Served via Print:</strong> Automatically marked as Served upon printing for First Mediation stage.
                        </div>
                    ` : ''}

                    <ul class="delivery-detail-list">
                        ${compDeliv?.served_at ? `
                            <li>
                                <span class="delivery-detail-label">Date/Time Served</span>
                                <span class="delivery-detail-val">${escapeGpsHtml(formatDateTime(compDeliv.served_at))}</span>
                            </li>
                        ` : ''}
                        ${compDeliv?.served_by_name ? `
                            <li>
                                <span class="delivery-detail-label">Service Source / Server</span>
                                <span class="delivery-detail-val">${escapeGpsHtml(compDeliv.served_by_name)}</span>
                            </li>
                        ` : ''}
                        ${compDeliv?.remarks ? `
                            <li>
                                <span class="delivery-detail-label">Remarks</span>
                                <span class="delivery-detail-val">${escapeGpsHtml(compDeliv.remarks)}</span>
                            </li>
                        ` : ''}
                    </ul>
                </div>

                <div class="delivery-card-footer" style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn-secondary" onclick="printKp8Notice(${caseId}, ${activeHearing.hearing_id}, ${compDeliv?.document_id || 'null'})" style="font-size: 0.82rem; padding: 6px 12px; display: inline-flex; align-items: center; gap: 4px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                        Print KP Form 8
                    </button>
                    <button type="button" class="btn-log-return" onclick="openOfficerReturnModal(
                        'Complainant',
                        ${compResidentId},
                        ${activeHearing.hearing_id},
                        ${caseId},
                        '${escapeGpsHtml(compDeliv?.delivery_status || 'Served Personal')}',
                        '${escapeGpsHtml(compDeliv?.recipient_name || compName || '')}',
                        '${escapeGpsHtml(compDeliv?.relationship || '')}',
                        '${escapeGpsHtml(compDeliv?.remarks || '')}',
                        '${escapeGpsHtml(compDeliv?.unserved_reason || '')}',
                        1,
                        ${compDeliv?.delivery_id || 'null'}
                    )">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        Log Officer's Return
                    </button>
                </div>
            </div>
        `;

        // 3. Respondent Summons (KP Form 9) Attempts Section
        const canNext = Boolean(activeHearing.can_initiate_next_attempt);
        const nextNum = activeHearing.next_attempt_number || (respAttempts.length + 1);
        const ordinals = ['', '1st', '2nd', '3rd'];
        const nextOrd = ordinals[nextNum] || nextNum + 'th';
        const anyRespServed = respAttempts.some(a => a.is_served);

        let respActionBtnHtml = '';
        if (canNext && nextNum <= 3) {
            respActionBtnHtml = `
                <button type="button" class="btn-create" onclick="initiateNextSummonAttempt(${caseId}, ${activeHearing.hearing_id})" style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; padding: 7px 14px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Issue Summon ${nextOrd} Attempt
                </button>
            `;
        } else if (anyRespServed) {
            respActionBtnHtml = `<span style="background: #dcfce7; color: #166534; font-weight: 700; font-size: 0.8rem; padding: 5px 12px; border-radius: 6px; border: 1px solid #bbf7d0;">✓ Summons Successfully Served</span>`;
        } else if (respAttempts.length >= 3 && failedCount >= 3) {
            respActionBtnHtml = `<span style="background: #fee2e2; color: #991b1b; font-weight: 700; font-size: 0.8rem; padding: 5px 12px; border-radius: 6px; border: 1px solid #fecaca;">3 Attempts Failed — Max Reached</span>`;
        } else {
            const lockReason = respAttempts.length === 0
                ? 'Issue Summon 1st Attempt'
                : (respAttempts[respAttempts.length - 1]?.is_pending ? `Record Officer's Return for Attempt #${respAttempts.length} before next attempt` : 'Next attempt unavailable');
            respActionBtnHtml = `
                <button type="button" class="btn-secondary" disabled title="${escapeGpsHtml(lockReason)}" style="opacity: 0.6; cursor: not-allowed; display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; padding: 7px 14px;">
                    Issue Summon ${nextOrd} Attempt
                </button>
            `;
        }

        // Build Respondent Attempts Rows
        let respRowsHtml = '';
        if (respAttempts.length === 0) {
            respRowsHtml = `
                <tr>
                    <td colspan="6" style="text-align: center; padding: 24px 16px; color: #94a3b8; font-size: 0.88rem;">
                        No delivery attempts recorded yet. Click <strong>Issue Summon 1st Attempt</strong> above to initiate summons delivery.
                    </td>
                </tr>
            `;
        } else {
            respRowsHtml = respAttempts.map(att => {
                const ord = att.attempt_ordinal || att.attempt_number;
                let statusBadge = '';
                if (att.is_served) {
                    statusBadge = `<span class="service-result-badge badge-served">${escapeGpsHtml(att.delivery_status)}</span>`;
                } else if (att.is_failed) {
                    statusBadge = `<span class="service-result-badge badge-not-served">Unserved / Failed</span>`;
                } else {
                    statusBadge = `<span class="service-result-badge badge-warning">Pending Service</span>`;
                }

                let proofLinkHtml = '<span style="color:#94a3b8; font-size:0.8rem;">None</span>';
                const proofPath = att.proof_file_path || att.attachment_path;
                if (proofPath) {
                    proofLinkHtml = `
                        <a href="../../${escapeGpsHtml(proofPath)}" target="_blank" style="color: #2563eb; font-weight: 600; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 3px;">
                            📎 View Proof
                        </a>
                    `;
                }

                const respResidentId = att.resident_id || 0;

                return `
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px 14px; font-weight: 600; color: #1e293b;">
                            ${escapeGpsHtml(ord)} Attempt
                        </td>
                        <td style="padding: 12px 14px; font-size: 0.85rem; color: #475569;">
                            ${escapeGpsHtml(att.attempted_at ? formatDateTime(att.attempted_at) : 'Pending')}
                        </td>
                        <td style="padding: 12px 14px;">
                            ${statusBadge}
                        </td>
                        <td style="padding: 12px 14px; font-size: 0.85rem;">
                            ${att.is_failed
                                ? `<strong style="color: #b91c1c;">${escapeGpsHtml(att.unserved_reason || 'Unserved')}</strong>`
                                : '<span style="color: #94a3b8;">—</span>'
                            }
                        </td>
                        <td style="padding: 12px 14px; font-size: 0.85rem; color: #475569;">
                            ${escapeGpsHtml(att.served_by_name || 'Unassigned')}
                        </td>
                        <td style="padding: 12px 14px;">
                            ${proofLinkHtml}
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <button type="button" class="btn-secondary" style="font-size: 0.8rem; padding: 5px 10px;" onclick="openOfficerReturnModal(
                                'Respondent',
                                ${respResidentId},
                                ${activeHearing.hearing_id},
                                ${caseId},
                                '${escapeGpsHtml(att.delivery_status || 'Served Personal')}',
                                '${escapeGpsHtml(att.recipient_name || '')}',
                                '${escapeGpsHtml(att.relationship || '')}',
                                '${escapeGpsHtml(att.remarks || '')}',
                                '${escapeGpsHtml(att.unserved_reason || '')}',
                                ${att.attempt_number},
                                ${att.delivery_id || 'null'}
                            )">
                                Log Return
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        const respCardHtml = `
            <div class="delivery-card" style="grid-column: 1 / -1; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 14px;">
                    <div>
                        <span class="delivery-party-role" style="color: #ea580c; font-weight: 700;">Respondent</span>
                        <h3 style="margin: 2px 0 0; font-size: 1.15rem; font-weight: 700; color: #1e293b;">KP Form No. 9 – Summons Delivery Tracking</h3>
                        <p style="margin: 2px 0 0; font-size: 0.84rem; color: #64748b;">A maximum of three (3) documented delivery attempts are tracked before CFA eligibility upon failure.</p>
                    </div>
                    <div>
                        ${respActionBtnHtml}
                    </div>
                </div>

                <div class="table-responsive" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 0.8rem; color: #475569; text-transform: uppercase;">
                                <th style="padding: 10px 14px;">Attempt</th>
                                <th style="padding: 10px 14px;">Date &amp; Time</th>
                                <th style="padding: 10px 14px;">Status / Result</th>
                                <th style="padding: 10px 14px;">Failure Reason</th>
                                <th style="padding: 10px 14px;">Process Server</th>
                                <th style="padding: 10px 14px;">Proof / Photo</th>
                                <th style="padding: 10px 14px; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${respRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        container.innerHTML = `
            ${cfaCardHtml}
            ${compCardHtml}
            ${respCardHtml}
        `;

    } catch (err) {
        console.error('Failed to load hearing deliveries:', err);
    }
}

async function initiateNextSummonAttempt(caseId, hearingId) {
    if (!caseId) return;
    if (!window.confirm('Do you want to initiate the next summons delivery attempt for the respondent?')) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('case_id', caseId);
        if (hearingId) formData.append('hearing_id', hearingId);
        formData.append('action', 'next_attempt');

        const res = await fetch('../../../backend/api/summons/next-attempt.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.message || 'Failed to initiate summons attempt.');
        }

        window.agapNotify?.(data.message || 'Next summons attempt initiated successfully.', 'success', 'Summons Attempt');
        await loadHearingDeliveries(caseId);
    } catch (err) {
        window.agapNotify?.(err.message, 'error', 'Summons Attempt Failed');
    }
}

async function printKp8Notice(caseId, hearingId, documentId) {
    if (typeof window.openSummonsPreviewModal === 'function') {
        window.openSummonsPreviewModal(caseId, documentId, hearingId, false);
        return;
    }
    window.location.href = `../complaints/complaint-details.php?id=${encodeURIComponent(caseId)}#print-summons`;
}

function openOfficerReturnModal(partyType, residentId, hearingId, caseId, currentStatus, currentRecipient, currentRelationship, currentNotes, currentUnservedReason, attemptNumber = 1, deliveryId = null) {
    const modal = document.getElementById('officerReturnModal');
    if (!modal) return;

    document.getElementById('returnCaseId').value = caseId || '';
    document.getElementById('returnHearingId').value = hearingId || '';
    document.getElementById('returnPartyType').value = partyType || 'Respondent';
    document.getElementById('returnResidentId').value = residentId || '';

    const attemptInput = document.getElementById('returnAttemptNumber');
    if (attemptInput) attemptInput.value = attemptNumber || 1;

    const deliveryIdInput = document.getElementById('returnDeliveryId');
    if (deliveryIdInput) deliveryIdInput.value = deliveryId || '';

    const titleEl = document.getElementById('officerReturnModalTitle');
    const subTitleEl = document.getElementById('officerReturnModalSubtitle');
    const ordinals = ['', '1st', '2nd', '3rd'];
    const ord = ordinals[attemptNumber] || attemptNumber + 'th';
    const displayParty = (partyType === 'Respondent' && attemptNumber) ? `Respondent (${ord} Attempt)` : partyType;
    if (titleEl) titleEl.textContent = `Log Officer's Return — ${displayParty}`;
    if (subTitleEl) subTitleEl.textContent = `Record delivery result and proof of service for ${displayParty}`;

    const statusSelect = document.getElementById('returnDeliveryStatus');
    if (statusSelect) {
        statusSelect.value = (currentStatus && currentStatus !== 'Pending') ? currentStatus : 'Served Personal';
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
        } else if (currentStatus === 'Unserved') {
            unservedReasonInput.value = currentUnservedReason || 'Moved Out';
        } else {
            unservedReasonInput.value = '';
        }
    }

    const failNotesInput = document.getElementById('returnFailureNotes');
    if (failNotesInput) failNotesInput.value = '';

    const proofImgInput = document.getElementById('returnProofImage');
    if (proofImgInput) proofImgInput.value = '';

    handleDeliveryStatusChange();
    modal.classList.add('is-open');
}

function closeOfficerReturnModal() {
    const modal = document.getElementById('officerReturnModal');
    if (modal) modal.classList.remove('is-open');
}

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

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving Officer\'s Return...';
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

        window.agapNotify?.(result.message || 'Officer return recorded successfully.', 'success');
        closeOfficerReturnModal();

        const caseId = document.getElementById('returnCaseId')?.value;
        if (caseId) {
            await Promise.all([
                loadCaseSummary(caseId),
                loadHearingDeliveries(caseId),
                loadSummonsNotices(caseId),
                loadProofs(caseId)
            ]);
        }
    } catch (err) {
        window.agapNotify?.(err.message, 'error');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Officer\'s Return';
        }
    }
}

window.openOfficerReturnModal = openOfficerReturnModal;
window.closeOfficerReturnModal = closeOfficerReturnModal;
window.handleDeliveryStatusChange = handleDeliveryStatusChange;
window.submitOfficerReturn = submitOfficerReturn;
window.initiateNextSummonAttempt = initiateNextSummonAttempt;
window.printKp8Notice = printKp8Notice;
window.loadHearingDeliveries = loadHearingDeliveries;

document.addEventListener(
    'DOMContentLoaded',
    async () => {
        const select =
            document.getElementById(
                'proofCaseId'
            );

        if (!select) {
            return;
        }

        const requestedCaseId =
            new URLSearchParams(
                window.location.search
            ).get('case_id') || '';

        select.addEventListener(
            'change',
            () => {
                loadCaseView(
                    select.value
                );
            }
        );

        try {
            await loadCases(
                requestedCaseId
            );
        } catch (error) {
            resetCaseView();

            setGpsMessage(
                error.message ||
                'Unable to load available cases.'
            );
        }
    }
);