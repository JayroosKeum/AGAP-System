let complaintIds = new Set();
let docketedComplaintIds = new Set();
let complaintsForDocket = [];
let allLoadedCases = [];
let filteredCases = [];
let activeSelectedCaseId = null;
const casesPageSize = 10;
let casesCurrentPage = 1;
let caseSearchDebounceTimer = null;

document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('caseTable');
    const complaintInput = document.getElementById('complaintId');
    const complaintSelect = document.getElementById('availableComplaints');
    const docketForm = document.getElementById('docketCaseForm');

    initCaseSearchToolbar();

    if (table) {
        loadCaseList(table);
    }

    loadComplaintsForDocketing();

    complaintInput?.addEventListener(
        'input',
        validateComplaintId
    );

    complaintSelect?.addEventListener('change', () => {
        if (complaintInput) {
            complaintInput.value = complaintSelect.value;
        }

        validateComplaintId();
    });

    docketForm?.addEventListener('submit', (event) => {
        if (!validateComplaintId()) {
            event.preventDefault();
        }
    });

    document.getElementById('editCaseStatus')?.addEventListener('change', configureEditTeamFields);
    document.getElementById('editCaseForm')?.addEventListener('submit', saveCaseEdit);
});

function configureEditTeamFields() {
    const section = document.getElementById('editTeamSection');
    const fields = document.getElementById('editTeamFields');
    const guidance = document.getElementById('editTeamGuidance');
    const automaticHead = document.getElementById('editAutomaticHead');
    const status = document.getElementById('editCaseStatus')?.value;
    const selects = ['editHeadId', 'editSecretaryId', 'editMemberId']
        .map((id) => document.getElementById(id)).filter(Boolean);
    if (!section || !fields || !guidance || !automaticHead) return;

    const automaticHeadStage = ['Docketed', 'Mediation'].includes(status);
    section.hidden = !automaticHeadStage && status !== 'Conciliation';
    if (automaticHeadStage) {
        fields.hidden = true;
        automaticHead.hidden = false;
        guidance.textContent = 'Lupon assignment is automatic for Docketed and Mediation cases. The Barangay Captain is automatically assigned as Head.';
        selects.forEach((select) => { select.disabled = true; select.required = false; });
    } else if (status === 'Conciliation') {
        fields.hidden = false;
        automaticHead.hidden = true;
        guidance.textContent = 'Assign three different active Lupon Members. Use this Edit form to modify an existing Conciliation team.';
        selects.forEach((select) => { select.disabled = false; select.required = true; });
    } else {
        fields.hidden = true;
        automaticHead.hidden = true;
        guidance.textContent = '';
        selects.forEach((select) => { select.disabled = true; select.required = false; });
    }
}

async function saveCaseEdit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const status = document.getElementById('editCaseStatus')?.value;
    const quorumSize = document.getElementById('editQuorumSize')?.value || '3';
    const quorumReason = document.getElementById('editQuorumExceptionReason')?.value?.trim() || '';
    if (status === 'Conciliation') {
        const headVal = document.getElementById('editHeadId')?.value || '';
        const secVal = document.getElementById('editSecretaryId')?.value || '';
        const memVal = document.getElementById('editMemberId')?.value || '';
        if (quorumSize === '2') {
            if (!headVal || !secVal) {
                window.agapNotify?.('Select a Head and Secretary for a 2-member quorum.', 'error', 'Check the Lupon team');
                return;
            }
            if (!quorumReason) {
                window.agapNotify?.('Quorum exception justification is required for a 2-member team.', 'error', 'Quorum justification needed');
                return;
            }
            if (headVal === secVal) {
                window.agapNotify?.('Head and Secretary must be different active Lupon Members.', 'error', 'Check the Lupon team');
                return;
            }
        } else {
            const selected = [headVal, secVal, memVal];
            if (selected.some((value) => !value)) {
                window.agapNotify?.('Select a Head, Secretary, and Member for Conciliation.', 'error', 'Check the Lupon team');
                return;
            }
            if (new Set(selected).size !== 3) {
                window.agapNotify?.('Head, Secretary, and Member must be assigned to different active Lupon Members.', 'error', 'Check the Lupon team');
                return;
            }
        }
    }

    const submit = form.querySelector('button[type="submit"]');
    if (submit) submit.disabled = true;
    try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result?.success) throw new Error(result?.message || 'Unable to update the case.');
        window.agapNotify?.(result.message || 'Case updated successfully.', 'success', 'Case updated');
        const caseId = document.getElementById('editCaseId')?.value;
        closeEditCaseModal();
        const table = document.getElementById('caseTable');
        if (table) loadCaseList(table);
        if (caseId) window.refreshCaseAssignments?.(caseId);
    } catch (error) {
        window.agapNotify?.(error.message, 'error', 'Unable to update case');
    } finally {
        if (submit) submit.disabled = false;
    }
}

function loadComplaintsForDocketing() {
    fetch('../../../backend/api/complaints/list.php')
        .then((response) => {
            if (!response.ok) {
                throw new Error('Unable to load complaints.');
            }

            return response.json();
        })
        .then((complaints) => {
            complaintsForDocket = Array.isArray(complaints)
                ? complaints
                : [];

            complaintIds = new Set(
                complaintsForDocket.map(
                    (item) => String(item.complaint_id)
                )
            );

            renderComplaintOptions();
        })
        .catch((error) => {
            complaintsForDocket = [];
            complaintIds = new Set();

            renderComplaintOptions();

            window.agapNotify?.(
                error.message,
                'error',
                'Unable to load complaints'
            );
        });
}

function loadCaseList(table) {
    fetch('../../../backend/api/cases/list.php')
        .then((response) => {
            if (!response.ok) {
                throw new Error('Unable to load cases.');
            }
            return response.json();
        })
        .then((result) => {
            const rawCases = Array.isArray(result)
                ? result
                : (Array.isArray(result.cases) ? result.cases : []);

            docketedComplaintIds = new Set(
                rawCases.map((item) => String(item.complaint_id || ''))
            );
            renderComplaintOptions();

            // ONLY show ongoing cases: exclude Settled, Dismissed, DISMISSED_BARRED, Archived
            allLoadedCases = rawCases.filter((item) => {
                const status = String(item.case_status || '').trim();
                return !['Settled', 'Dismissed', 'DISMISSED_BARRED', 'Archived'].includes(status);
            });

            const urlParams = new URLSearchParams(window.location.search);
            const initialCaseId = urlParams.get('assign_case_id') || urlParams.get('case_id') || window.activeAssignedCaseId;
            if (initialCaseId) {
                activeSelectedCaseId = String(initialCaseId);
            }

            applyCaseFiltersAndRender();
        })
        .catch((error) => {
            table.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-state">
                        ${escapeHtml(error.message)}
                        Please refresh and try again.
                    </td>
                </tr>
            `;
            const summary = document.getElementById('caseResultSummary');
            if (summary) summary.textContent = 'Error loading cases';
            const paginationEl = document.getElementById('casePagination');
            if (paginationEl) paginationEl.hidden = true;
        });
}

function initCaseSearchToolbar() {
    const form = document.getElementById('caseRecordsSearchForm');
    const queryInput = document.getElementById('caseSearchQuery');
    const clearInputBtn = document.getElementById('clearCaseSearchInput');
    const typeSelect = document.getElementById('caseSearchType');
    const statusSelect = document.getElementById('caseSearchStatus');
    const sortSelect = document.getElementById('caseSearchSort');
    const clearFiltersBtn = document.getElementById('clearCaseFilters');

    if (!form && !queryInput) return;

    if (queryInput) {
        const updateClearVisibility = () => {
            if (clearInputBtn) {
                clearInputBtn.style.display = queryInput.value.trim() ? 'block' : 'none';
            }
        };

        queryInput.addEventListener('input', () => {
            updateClearVisibility();
            clearTimeout(caseSearchDebounceTimer);
            caseSearchDebounceTimer = setTimeout(() => {
                casesCurrentPage = 1;
                applyCaseFiltersAndRender();
            }, 250);
        });

        if (clearInputBtn) {
            clearInputBtn.addEventListener('click', () => {
                queryInput.value = '';
                updateClearVisibility();
                casesCurrentPage = 1;
                applyCaseFiltersAndRender();
            });
        }
    }

    [typeSelect, statusSelect, sortSelect].forEach((sel) => {
        sel?.addEventListener('change', () => {
            casesCurrentPage = 1;
            applyCaseFiltersAndRender();
        });
    });

    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', () => {
            if (queryInput) queryInput.value = '';
            if (clearInputBtn) clearInputBtn.style.display = 'none';
            if (typeSelect) typeSelect.value = '';
            if (statusSelect) statusSelect.value = '';
            if (sortSelect) sortSelect.value = 'case_number_desc';
            casesCurrentPage = 1;
            applyCaseFiltersAndRender();
        });
    }
}

function applyCaseFiltersAndRender() {
    const table = document.getElementById('caseTable');
    if (!table) return;

    const query = (document.getElementById('caseSearchQuery')?.value || '').trim().toLowerCase();
    const type = document.getElementById('caseSearchType')?.value || '';
    const status = document.getElementById('caseSearchStatus')?.value || '';
    const sortBy = document.getElementById('caseSearchSort')?.value || 'case_number_desc';
    const clearFiltersBtn = document.getElementById('clearCaseFilters');
    const clearInputBtn = document.getElementById('clearCaseSearchInput');

    if (clearInputBtn) {
        clearInputBtn.style.display = query ? 'block' : 'none';
    }

    const hasActiveFilters = Boolean(query || type || status);
    if (clearFiltersBtn) {
        clearFiltersBtn.style.display = hasActiveFilters ? 'inline-block' : 'none';
    }

    filteredCases = allLoadedCases.filter((item) => {
        if (type && item.case_type !== type) return false;
        if (status && item.case_status !== status) return false;
        if (query) {
            const haystacks = [
                item.case_number,
                item.complaint_number,
                item.complaint_title,
                item.complainant_names,
                item.respondent_names
            ].map((v) => String(v || '').toLowerCase());

            const matches = haystacks.some((text) => text.includes(query));
            if (!matches) return false;
        }
        return true;
    });

    filteredCases.sort((a, b) => {
        if (sortBy === 'case_number_asc') {
            return String(a.case_number || '').localeCompare(String(b.case_number || ''));
        }
        if (sortBy === 'case_number_desc') {
            return String(b.case_number || '').localeCompare(String(a.case_number || ''));
        }
        if (sortBy === 'docket_date_asc') {
            return new Date(a.docket_date || 0) - new Date(b.docket_date || 0);
        }
        if (sortBy === 'docket_date_desc') {
            return new Date(b.docket_date || 0) - new Date(a.docket_date || 0);
        }
        return 0;
    });

    if (hasActiveFilters && filteredCases.length === 1) {
        const singleCase = filteredCases[0];
        activeSelectedCaseId = String(singleCase.case_id);
        if (typeof window.openCaseAssignments === 'function') {
            window.openCaseAssignments(singleCase.case_id, false);
        }
    }

    renderCurrentCasesPage(table);
}

function renderCurrentCasesPage(table) {
    const total = filteredCases.length;
    const summary = document.getElementById('caseResultSummary');
    const paginationEl = document.getElementById('casePagination');

    if (!total) {
        table.innerHTML = `
            <tr>
                <td colspan="6" class="empty-state">
                    No ongoing cases found matching your search criteria.
                </td>
            </tr>
        `;
        if (summary) summary.textContent = 'No matching ongoing cases';
        if (paginationEl) paginationEl.hidden = true;
        return;
    }

    const totalPages = Math.ceil(total / casesPageSize);
    if (casesCurrentPage > totalPages) casesCurrentPage = totalPages;
    if (casesCurrentPage < 1) casesCurrentPage = 1;

    const startIdx = (casesCurrentPage - 1) * casesPageSize;
    const endIdx = Math.min(startIdx + casesPageSize, total);
    const pageRows = filteredCases.slice(startIdx, endIdx);

    table.innerHTML = pageRows
        .map((item) => {
            const isSelected = String(item.case_id) === String(activeSelectedCaseId);
            return renderCaseRow(item, isSelected);
        })
        .join('');

    const query = document.getElementById('caseSearchQuery')?.value.trim();
    const type = document.getElementById('caseSearchType')?.value;
    const status = document.getElementById('caseSearchStatus')?.value;
    const isFiltered = Boolean(query || type || status);

    if (summary) {
        if (isFiltered) {
            summary.textContent = `Showing ${total === 1 ? '1 ongoing case' : `${startIdx + 1}–${endIdx} of ${total} ongoing cases`} (filtered from ${allLoadedCases.length} total ongoing cases)`;
        } else {
            summary.textContent = `Showing ${startIdx + 1}–${endIdx} of ${total} ongoing cases`;
        }
    }

    renderClientCasePagination(total, totalPages);
}

function renderClientCasePagination(totalRecords, totalPages) {
    const container = document.getElementById('casePagination');
    const summary = document.getElementById('casePaginationSummary');
    const controls = document.getElementById('casePaginationControls');
    if (!container || !summary || !controls) return;

    if (totalPages <= 1) {
        container.hidden = true;
        return;
    }

    container.hidden = false;
    summary.textContent = `Page ${casesCurrentPage} of ${totalPages} (${totalRecords} cases)`;
    controls.replaceChildren();

    const addBtn = (label, pageNum, disabled = false, isCurrent = false) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = isCurrent ? 'btn-create case-page-current' : 'btn-secondary';
        btn.textContent = label;
        btn.disabled = disabled;
        btn.addEventListener('click', () => {
            casesCurrentPage = pageNum;
            const table = document.getElementById('caseTable');
            if (table) renderCurrentCasesPage(table);
        });
        controls.appendChild(btn);
    };

    addBtn('Previous', casesCurrentPage - 1, casesCurrentPage <= 1);
    const startPage = Math.max(1, Math.min(casesCurrentPage - 2, totalPages - 4));
    const endPage = Math.min(totalPages, startPage + 4);
    for (let p = startPage; p <= endPage; p++) {
        addBtn(String(p), p, p === casesCurrentPage, p === casesCurrentPage);
    }
    addBtn('Next', casesCurrentPage + 1, casesCurrentPage >= totalPages);
}

window.highlightActiveCaseRow = (caseId) => {
    activeSelectedCaseId = String(caseId);
    document.querySelectorAll('#caseTable tr').forEach((tr) => {
        if (tr.id === `case-row-${caseId}`) {
            tr.classList.add('case-table-row-selected');
        } else {
            tr.classList.remove('case-table-row-selected');
        }
    });
};

function renderCaseRow(item, isSelected = false) {
    const caseId = Number(item.case_id);

    if (!Number.isInteger(caseId) || caseId < 1) {
        return '';
    }

    const archived = item.case_status === 'Archived';

    const statusClass = String(item.case_status || '')
        .toLowerCase()
        .replaceAll(' ', '-');

    const selectedClass = isSelected ? ' case-table-row-selected' : '';

    const isMediation = item.case_status === 'Mediation';
    const isLapsed = Boolean(item.mediation_timer?.is_lapsed || item.mediation_timer?.is_expired);
    const hasImpasse = ['unsuccessful', 'referred to pangkat', 'impasse'].includes(String(item.stage_outcome || item.latest_outcome || item.outcome || '').toLowerCase());
    const showElevateBtn = isMediation && (isLapsed || hasImpasse);

    let stageWorkspaceUrl = '';
    let stageWorkspaceLabel = '';
    if (item.case_status === 'Mediation') {
        stageWorkspaceUrl = `mediation-workspace.php?case_id=${caseId}`;
        stageWorkspaceLabel = 'Mediation';
    } else if (item.case_status === 'Conciliation') {
        stageWorkspaceUrl = `pangkat-workspace.php?case_id=${caseId}`;
        stageWorkspaceLabel = 'Conciliation';
    } else if (item.case_status === 'Arbitration') {
        stageWorkspaceUrl = `arbitration-workspace.php?case_id=${caseId}`;
        stageWorkspaceLabel = 'Arbitration';
    }

    const activeCaseActions = archived || !stageWorkspaceUrl
        ? ''
        : `
            <a
                href="${stageWorkspaceUrl}"
                class="btn-table-action"
                style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; text-decoration: none; font-size: 0.78rem; font-weight: 600; margin-left: 4px;"
                title="Open ${stageWorkspaceLabel} Workspace"
            >
                ${stageWorkspaceLabel} &rarr;
            </a>
        `;

    return `
        <tr id="case-row-${caseId}" class="${selectedClass}">
            <td>
                <a
                    href="../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}"
                    style="color: #2563eb; font-weight: 700; text-decoration: none;"
                    onmouseover="this.style.textDecoration='underline'"
                    onmouseout="this.style.textDecoration='none'"
                    title="View complaint details for ${escapeHtml(item.case_number || '')}"
                >
                    ${escapeHtml(item.case_number || 'Case #' + item.case_id)}
                </a>
            </td>

            <td>
                ${escapeHtml(
                    item.complainant_names ||
                    'Not recorded'
                )}
            </td>

            <td>
                ${escapeHtml(
                    item.respondent_names ||
                    'Not recorded'
                )}
            </td>

            <td>
                ${escapeHtml(
                    item.case_type ||
                    'Not recorded'
                )}
            </td>

            <td>
                <span class="status status-${escapeHtml(statusClass)}">
                    ${escapeHtml(
                        item.case_status ||
                        'Unknown'
                    )}
                </span>
                ${item.case_status === 'Mediation' && item.mediation_timer?.badge_label ? `
                    <div style="margin-top: 4px;">
                        <span class="badge-timer ${escapeHtml(item.mediation_timer.badge_class)}">
                            ${escapeHtml(item.mediation_timer.badge_label)}
                        </span>
                    </div>
                ` : ''}
            </td>

            <td class="action-buttons">
                <a
                    href="../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}"
                    class="btn-table-action"
                    style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; text-decoration: none; font-size: 0.78rem; font-weight: 600;"
                    title="View complete complaint and case workspace"
                >
                    View Details
                </a>

                ${activeCaseActions}
            </td>
        </tr>
    `;
}

function renderComplaintOptions() {
    const select = document.getElementById(
        'availableComplaints'
    );

    if (!select) {
        return;
    }

    const previouslySelectedValue = select.value;

    select.replaceChildren(
        new Option('Select a complaint', '')
    );

    complaintsForDocket.forEach((item) => {
        const complaintId = String(
            item.complaint_id || ''
        );

        if (!complaintId) {
            return;
        }

        const docketed = docketedComplaintIds.has(
            complaintId
        );

        const accepted = item.status === 'Accepted';
        const unavailable = docketed || !accepted;

        let reason = '';

        if (docketed) {
            reason = ' (Already docketed)';
        } else if (!accepted) {
            reason = ' (Awaiting review acceptance)';
        }

        const complaintNumber =
            item.complaint_number ||
            'No complaint number';

        const complaintTitle =
            item.complaint_title ||
            'Untitled complaint';

        const label = [
            complaintId,
            complaintNumber,
            `${complaintTitle}${reason}`
        ].join(' - ');

        const option = new Option(
            label,
            complaintId
        );

        option.disabled = unavailable;

        select.add(option);
    });

    const previousOption = Array.from(
        select.options
    ).find(
        (option) =>
            option.value === previouslySelectedValue &&
            !option.disabled
    );

    if (previousOption) {
        select.value = previouslySelectedValue;
    }
}

function validateComplaintId() {
    const input = document.getElementById(
        'complaintId'
    );

    const warning = document.getElementById(
        'complaintIdWarning'
    );

    if (!input || !warning) {
        return true;
    }

    const complaintId = input.value.trim();

    warning.hidden = true;
    warning.textContent = '';
    input.setCustomValidity('');

    if (!complaintId) {
        const message =
            'Select or enter a complaint.';

        warning.textContent = message;
        warning.hidden = false;
        input.setCustomValidity(message);

        return false;
    }

    if (!complaintIds.has(complaintId)) {
        warning.textContent =
            'This Complaint ID does not exist. ' +
            'Please select a complaint from the list.';
    } else if (
        docketedComplaintIds.has(complaintId)
    ) {
        warning.textContent =
            'This complaint has already been docketed.';
    } else {
        const complaint = complaintsForDocket.find(
            (item) =>
                String(item.complaint_id) === complaintId
        );

        if (complaint?.status !== 'Accepted') {
            warning.textContent =
                'This complaint must be reviewed and ' +
                'accepted before it can be docketed.';
        } else {
            return true;
        }
    }

    warning.hidden = false;
    input.setCustomValidity(warning.textContent);

    return false;
}

function escapeHtml(value) {
    const node = document.createElement('div');

    node.textContent = value ?? '';

    return node.innerHTML;
}

function showModal(id) {
    const modal = document.getElementById(id);

    if (modal) {
        modal.style.display = 'flex';
    }
}

function hideModal(id) {
    const modal = document.getElementById(id);

    if (modal) {
        modal.style.display = 'none';
    }
}

function openAddCaseModal() {
    showModal('addCaseModal');
}

function closeAddCaseModal() {
    hideModal('addCaseModal');
}

function closeViewCaseModal() {
    hideModal('viewCaseModal');
}

function closeEditCaseModal() {
    hideModal('editCaseModal');
}

function closeArchiveCaseModal() {
    hideModal('archiveCaseModal');
}

function getCase(id) {
    const caseId = Number(id);

    if (!Number.isInteger(caseId) || caseId < 1) {
        return Promise.reject(
            new Error('A valid case is required.')
        );
    }

    return fetch(
        '../../../backend/api/cases/view.php?id=' +
        encodeURIComponent(caseId)
    ).then(async (response) => {
        const data = await response
            .json()
            .catch(() => null);

        if (!response.ok || !data) {
            throw new Error(
                data?.message ||
                'Unable to load the case.'
            );
        }

        return data;
    });
}

function viewCase(id) {
    getCase(id)
        .then((item) => {
            const details =
                document.getElementById('caseDetails');

            if (!details) {
                throw new Error(
                    'The case details area is unavailable.'
                );
            }

            const caseNumber = item.case_number || ('Case #' + item.case_id);
            const statusClass = (item.case_status || 'Docketed').toLowerCase().replace(/[^a-z0-9]/g, '-');
            const typeClass = (item.case_type || 'Civil').toLowerCase();

            details.innerHTML = `
                <div class="case-summary-modal-content" style="display:flex; flex-direction:column; gap:16px;">
                    <!-- Case Summary Header -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                            <div>
                                <span style="font-size:0.75rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; display:block; margin-bottom:2px;">
                                    ${escapeHtml(item.category_name || 'Dispute Case')}
                                </span>
                                <h3 style="margin:0; font-size:1.2rem; color:#0f172a; font-weight:800;">
                                    ${escapeHtml(item.complaint_title || 'Untitled Complaint')}
                                </h3>
                                <span style="font-size:0.85rem; color:#3b82f6; font-weight:700;">
                                    ${escapeHtml(caseNumber)}
                                </span>
                            </div>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <span class="type-pill type-${typeClass}">${escapeHtml(item.case_type || 'Civil')}</span>
                                <span class="case-stage-pill status-${statusClass}">${escapeHtml(item.case_status || 'Active')}</span>
                            </div>
                        </div>

                        <!-- Core Metadata Grid -->
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:10px; margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0; font-size:0.82rem;">
                            <div>
                                <span style="color:#64748b; display:block; font-size:0.72rem; text-transform:uppercase; font-weight:700;">Docket Date</span>
                                <strong style="color:#0f172a;">${escapeHtml(item.docket_date || 'Not recorded')}</strong>
                            </div>
                            <div>
                                <span style="color:#64748b; display:block; font-size:0.72rem; text-transform:uppercase; font-weight:700;">Incident Date</span>
                                <strong style="color:#0f172a;">${escapeHtml(item.incident_date || 'Not recorded')}</strong>
                            </div>
                            <div>
                                <span style="color:#64748b; display:block; font-size:0.72rem; text-transform:uppercase; font-weight:700;">Incident Location</span>
                                <strong style="color:#0f172a;" title="${escapeHtml(item.incident_location || '')}">${escapeHtml(item.incident_location ? (item.incident_location.length > 25 ? item.incident_location.slice(0, 25) + '…' : item.incident_location) : 'Barangay')}</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Parties Summary -->
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:12px;">
                            <span style="font-size:0.75rem; font-weight:800; color:#0284c7; text-transform:uppercase; display:block; margin-bottom:4px;">Complainant(s)</span>
                            <div style="font-size:0.88rem; color:#0f172a; font-weight:600;">${escapeHtml(item.complainant_names || 'Complainant')}</div>
                        </div>
                        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:12px;">
                            <span style="font-size:0.75rem; font-weight:800; color:#e11d48; text-transform:uppercase; display:block; margin-bottom:4px;">Respondent(s)</span>
                            <div style="font-size:0.88rem; color:#0f172a; font-weight:600;">${escapeHtml(item.respondent_names || 'Respondent')}</div>
                        </div>
                    </div>

                    <!-- Narrative & Relief Summary -->
                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:14px;">
                        <span style="font-size:0.75rem; font-weight:800; color:#475569; text-transform:uppercase; display:block; margin-bottom:6px;">Statement of Facts</span>
                        <div style="font-size:0.86rem; color:#334155; line-height:1.55; max-height:160px; overflow-y:auto; background:#f8fafc; padding:10px 12px; border-radius:6px; border:1px solid #f1f5f9; white-space:pre-wrap;">${escapeHtml(item.narrative || 'No statement narrative provided.')}</div>
                    </div>

                    <!-- Modal Action Footer -->
                    <div style="display:flex; justify-content:space-between; align-items:center; padding-top:12px; border-top:1px solid #e2e8f0; flex-wrap:wrap; gap:10px;">
                        <button type="button" class="btn-secondary" onclick="closeViewCaseModal()" style="font-size:0.85rem; padding:8px 14px;">Close</button>
                        <a href="../complaints/complaint-details.php?id=${encodeURIComponent(item.complaint_id)}#tab-summary" class="btn-create" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; padding:8px 16px;">
                            <span>Open Full Case Summary &rarr;</span>
                        </a>
                    </div>
                </div>
            `;

            showModal('viewCaseModal');
        })
        .catch((error) => {
            window.agapNotify?.(
                error.message,
                'error',
                'Unable to open case'
            );
        });
}

async function editCase(id) {
    try {
            const [item, assignments, members] = await Promise.all([
                getCase(id),
                fetch('../../../backend/api/assignments/list.php?case_id=' + encodeURIComponent(id)).then(async (response) => {
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'Unable to load the current team.');
                    return data;
                }),
                fetch('../../../backend/api/assignments/lupon-members.php').then(async (response) => {
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'Unable to load Lupon Members.');
                    return data;
                })
            ]);
            const idField =
                document.getElementById('editCaseId');

            const typeField =
                document.getElementById('editCaseType');

            const statusField =
                document.getElementById(
                    'editCaseStatus'
                );

            if (
                !idField ||
                !typeField ||
                !statusField
            ) {
                throw new Error(
                    'The case edit form is unavailable.'
                );
            }

            idField.value = item.case_id;
            typeField.value = item.case_type;
            statusField.value = item.case_status;

            const memberByRole = Object.fromEntries((Array.isArray(assignments) ? assignments : [])
                .filter((assignment) => assignment.role_name === 'Lupon Member')
                .map((assignment) => [assignment.assignment_role, String(assignment.member_id)]));
            ['Head', 'Secretary', 'Member'].forEach((role) => {
                const select = document.getElementById(`edit${role}Id`);
                if (!select) return;
                select.replaceChildren(new Option('Select a Lupon Member', ''));
                (Array.isArray(members) ? members : []).forEach((member) => {
                    const label = [member.last_name, member.first_name, member.middle_name].filter(Boolean).join(', ');
                    select.add(new Option(label || 'Unnamed Lupon Member', member.member_id));
                });
                select.value = memberByRole[role] || '';
            });
            configureEditTeamFields();

            showModal('editCaseModal');
    } catch (error) {
        window.agapNotify?.(error.message, 'error', 'Unable to edit case');
    }
}

function archiveCase(id) {
    const caseId = Number(id);

    const field = document.getElementById(
        'archiveCaseId'
    );

    if (
        !Number.isInteger(caseId) ||
        caseId < 1 ||
        !field
    ) {
        window.agapNotify?.(
            'The archive form is unavailable or the case is invalid.',
            'error',
            'Unable to archive case'
        );

        return;
    }

    field.value = caseId;

    showModal('archiveCaseModal');
}

function openCaseAssignments(caseId, shouldScroll = true, elevate = false) {
    const id = Number(caseId);

    if (!Number.isInteger(id) || id < 1) {
        window.agapNotify?.(
            'A valid case is required.',
            'error',
            'Unable to open assignments'
        );

        return;
    }

    if (
        typeof window.openCaseAssignments !==
        'function'
    ) {
        window.agapNotify?.(
            'The case assignment section is unavailable.',
            'error',
            'Unable to open assignments'
        );

        return;
    }

    window.openCaseAssignments(id, shouldScroll, elevate);
}


// AGAP_UNIFIED_SYNC_CASES
window.addEventListener('agap:data-changed', async (event) => {
    const changed = event.detail?.modules || [];
    if (!changed.some((name) => ['complaints', 'cases', 'assignments', 'pangkat', 'hearings', 'deadlines', 'history'].includes(name))) {
        return;
    }
    if (document.body.dataset.caseSyncRefreshing === '1') return;
    document.body.dataset.caseSyncRefreshing = '1';
    try {
        const table = document.getElementById('caseTable');
        const jobs = [loadComplaintsForDocketing()];
        if (table) jobs.push(loadCaseList(table));
        await Promise.allSettled(jobs);
    } finally {
        window.setTimeout(() => delete document.body.dataset.caseSyncRefreshing, 400);
    }
});
