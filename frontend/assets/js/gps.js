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

    if (summary) {
        summary.hidden = true;
    }

    if (backLink) {
        backLink.hidden = true;
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

async function loadCases(
    preselectedCaseId = ''
) {
    const select =
        document.getElementById('proofCaseId');

    if (!select) {
        return;
    }

    const result =
        await gpsApi(proofApiUrl);

    const cases =
        Array.isArray(result.data)
            ? result.data
            : [];

    select.innerHTML =
        '<option value="">Select a case</option>' +
        cases
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

    const validPreselectedCase =
        preselectedCaseId &&
        cases.some(
            (item) =>
                Number(item.case_id) ===
                Number(preselectedCaseId)
        );

    if (validPreselectedCase) {
        select.value =
            String(preselectedCaseId);

        await loadCaseView(
            preselectedCaseId
        );
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