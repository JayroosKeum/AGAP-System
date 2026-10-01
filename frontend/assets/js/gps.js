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

        // Render alert banner
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

        // Render delivery cards
        container.innerHTML = deliveries.map(deliv => {
            const pType = deliv.party_type;
            const profile = deliv.party_profile || {};
            const name = deliv.recipient_name || profile.resident_name || `${pType}`;
            const formType = deliv.form_type || (pType === 'Complainant' ? 'Notice of Hearing' : 'Summon');
            const status = deliv.delivery_status || 'Pending';

            let badgeHtml = '';
            if (status.startsWith('Served')) {
                const sublabel = status === 'Served Personal' ? 'Served - Personal'
                               : (status === 'Served Substituted' ? 'Served - Substituted'
                               : (status === 'Served Refused' ? 'Refused to Receive' : 'Served'));
                badgeHtml = `<span class="service-result-badge badge-served">${escapeGpsHtml(sublabel)}</span>`;
            } else if (status === 'Unserved') {
                badgeHtml = `<span class="service-result-badge badge-not-served">Unserved / Failed</span>`;
            } else {
                badgeHtml = `<span class="service-result-badge badge-warning">Pending Service</span>`;
            }

            let resBadge = '';
            if (profile.is_tenant !== undefined) {
                const isTenant = Number(profile.is_tenant);
                if (isTenant === 1) resBadge = `<span class="residency-badge badge-tenant"><span class="residency-dot"></span>Tenant</span>`;
                else if (isTenant === 2) resBadge = `<span class="residency-badge badge-non-resident"><span class="residency-dot"></span>Non-Resident</span>`;
                else resBadge = `<span class="residency-badge badge-resident"><span class="residency-dot"></span>Resident</span>`;
            }

            const hearingDateFormatted = activeHearing.hearing_date
                ? formatDateTime(activeHearing.hearing_date)
                : 'Mediation Session';

            const residentIdVal = deliv.resident_id || profile.resident_id || 0;
            const hearingIdVal = activeHearing.hearing_id;

            return `
                <div class="delivery-card">
                    <div class="delivery-card-header">
                        <div>
                            <span class="delivery-party-role">${escapeGpsHtml(pType)}</span>
                            <div class="delivery-party-name">
                                <span>${escapeGpsHtml(name)}</span>
                                ${resBadge}
                            </div>
                            <span class="delivery-form-type">${escapeGpsHtml(formType)}</span>
                        </div>
                        <div>
                            ${badgeHtml}
                        </div>
                    </div>

                    <div class="delivery-card-body">
                        <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 8px;">
                            Hearing: <strong>${escapeGpsHtml(activeHearing.hearing_type)}</strong> (${escapeGpsHtml(hearingDateFormatted)})
                        </div>

                        <ul class="delivery-detail-list">
                            ${deliv.served_at ? `
                                <li>
                                    <span class="delivery-detail-label">Date/Time Served</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(formatDateTime(deliv.served_at))}</span>
                                </li>
                            ` : ''}
                            ${deliv.recipient_name ? `
                                <li>
                                    <span class="delivery-detail-label">Recipient Name</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(deliv.recipient_name)}</span>
                                </li>
                            ` : ''}
                            ${deliv.relationship ? `
                                <li>
                                    <span class="delivery-detail-label">Relationship</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(deliv.relationship)}</span>
                                </li>
                            ` : ''}
                            ${deliv.served_by_name ? `
                                <li>
                                    <span class="delivery-detail-label">Process Server</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(deliv.served_by_name)}</span>
                                </li>
                            ` : ''}
                            ${deliv.unserved_reason ? `
                                <li>
                                    <span class="delivery-detail-label" style="color:#b91c1c;">Failure Reason</span>
                                    <span class="delivery-detail-val" style="color:#b91c1c; font-weight:700;">${escapeGpsHtml(deliv.unserved_reason)}</span>
                                </li>
                            ` : ''}
                            ${deliv.failure_notes ? `
                                <li>
                                    <span class="delivery-detail-label" style="color:#b91c1c;">Failure Notes</span>
                                    <span class="delivery-detail-val" style="color:#b91c1c;">${escapeGpsHtml(deliv.failure_notes)}</span>
                                </li>
                            ` : ''}
                            ${deliv.remarks ? `
                                <li>
                                    <span class="delivery-detail-label">Remarks</span>
                                    <span class="delivery-detail-val">${escapeGpsHtml(deliv.remarks)}</span>
                                </li>
                            ` : ''}
                        </ul>
                    </div>

                    <div class="delivery-card-footer">
                        <button type="button" class="btn-log-return" onclick="openOfficerReturnModal(
                            '${escapeGpsHtml(pType)}',
                            ${residentIdVal},
                            ${hearingIdVal},
                            ${caseId},
                            '${escapeGpsHtml(deliv.delivery_status || 'Served Personal')}',
                            '${escapeGpsHtml(deliv.recipient_name || profile.resident_name || '')}',
                            '${escapeGpsHtml(deliv.relationship || '')}',
                            '${escapeGpsHtml(deliv.remarks || '')}'
                        )">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                            Log Officer's Return
                        </button>
                    </div>
                </div>
            `;
        }).join('');

    } catch (err) {
        console.error('Failed to load hearing deliveries:', err);
    }
}

function openOfficerReturnModal(partyType, residentId, hearingId, caseId, currentStatus, currentRecipient, currentRelationship, currentNotes) {
    const modal = document.getElementById('officerReturnModal');
    if (!modal) return;

    document.getElementById('returnCaseId').value = caseId || '';
    document.getElementById('returnHearingId').value = hearingId || '';
    document.getElementById('returnPartyType').value = partyType || 'Respondent';
    document.getElementById('returnResidentId').value = residentId || '';

    const titleEl = document.getElementById('officerReturnModalTitle');
    const subTitleEl = document.getElementById('officerReturnModalSubtitle');
    if (titleEl) titleEl.textContent = `Log Officer's Return — ${partyType}`;
    if (subTitleEl) subTitleEl.textContent = `Record delivery result and proof of service for ${partyType}`;

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

    if (status === 'Unserved') {
        if (servedAtGroup) servedAtGroup.style.display = 'none';
        if (recipientGroup) recipientGroup.style.display = 'none';
        if (relGroup) relGroup.style.display = 'none';
        if (unservedReasonGroup) unservedReasonGroup.style.display = 'block';
        if (failNotesGroup) failNotesGroup.style.display = 'block';
    } else if (status === 'Served Substituted') {
        if (servedAtGroup) servedAtGroup.style.display = 'block';
        if (recipientGroup) recipientGroup.style.display = 'block';
        if (relGroup) relGroup.style.display = 'block';
        if (unservedReasonGroup) unservedReasonGroup.style.display = 'none';
        if (failNotesGroup) failNotesGroup.style.display = 'none';
        if (recipientReqStar) recipientReqStar.style.display = 'inline';
    } else if (status === 'Served Refused') {
        if (servedAtGroup) servedAtGroup.style.display = 'block';
        if (recipientGroup) recipientGroup.style.display = 'block';
        if (relGroup) relGroup.style.display = 'none';
        if (unservedReasonGroup) unservedReasonGroup.style.display = 'none';
        if (failNotesGroup) failNotesGroup.style.display = 'none';
        if (recipientReqStar) recipientReqStar.style.display = 'none';
    } else {
        // Served Personal
        if (servedAtGroup) servedAtGroup.style.display = 'block';
        if (recipientGroup) recipientGroup.style.display = 'block';
        if (relGroup) relGroup.style.display = 'none';
        if (unservedReasonGroup) unservedReasonGroup.style.display = 'none';
        if (failNotesGroup) failNotesGroup.style.display = 'none';
        if (recipientReqStar) recipientReqStar.style.display = 'inline';
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