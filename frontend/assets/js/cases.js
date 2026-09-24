let complaintIds = new Set();
let docketedComplaintIds = new Set();
let complaintsForDocket = [];

document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('caseTable');
    const complaintInput = document.getElementById('complaintId');
    const complaintSelect = document.getElementById('availableComplaints');
    const docketForm = document.getElementById('docketCaseForm');

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
    const fields = ['editHeadId', 'editSecretaryId', 'editMemberId'];
    if (status === 'Conciliation') {
        const selected = fields.map((id) => document.getElementById(id)?.value || '');
        if (selected.some((value) => !value)) {
            window.agapNotify?.('Select a Head, Secretary, and Member for Conciliation.', 'error', 'Check the Lupon team');
            return;
        }
        if (new Set(selected).size !== 3) {
            window.agapNotify?.('Head, Secretary, and Member must be assigned to different active Lupon Members.', 'error', 'Check the Lupon team');
            return;
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

function loadCaseList(table, page = 1) {
    fetch('../../../backend/api/cases/list.php?page=' + encodeURIComponent(page))
        .then((response) => {
            if (!response.ok) {
                throw new Error('Unable to load cases.');
            }

            return response.json();
        })
        .then((result) => {
            const rows = Array.isArray(result.cases)
                ? result.cases
                : [];

            docketedComplaintIds = new Set(
                (result.docketed_complaint_ids || []).map(String)
            );
            renderComplaintOptions();
            renderCasePagination(result.pagination, table);

            if (!rows.length) {
                table.innerHTML = `
                    <tr>
                        <td colspan="8" class="empty-state">
                            No cases have been docketed yet.
                        </td>
                    </tr>
                `;

                return;
            }

            table.innerHTML = rows
                .map((item) => renderCaseRow(item))
                .join('');
        })
        .catch((error) => {
            table.innerHTML = `
                <tr>
                    <td colspan="8" class="empty-state">
                        ${escapeHtml(error.message)}
                        Please refresh and try again.
                    </td>
                </tr>
            `;
            document.getElementById('casePagination').hidden = true;
        });
}

function renderCasePagination(pagination, table) {
    const container = document.getElementById('casePagination');
    const summary = document.getElementById('casePaginationSummary');
    const controls = document.getElementById('casePaginationControls');
    if (!container || !summary || !controls || !pagination) return;

    const total = Number(pagination.total_records) || 0;
    const pageSize = Number(pagination.per_page) || 25;
    const page = Number(pagination.current_page) || 1;
    const totalPages = Number(pagination.total_pages) || 0;
    const first = total ? ((page - 1) * pageSize) + 1 : 0;
    const last = Math.min(page * pageSize, total);

    summary.textContent = `Showing ${first}–${last} of ${total} cases`;
    controls.replaceChildren();
    container.hidden = false;

    if (totalPages <= 1) return;

    const addPageButton = (label, pageNumber, disabled = false, current = false) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = current ? 'btn-create case-page-current' : 'btn-secondary';
        button.textContent = label;
        button.disabled = disabled;
        if (current) button.setAttribute('aria-current', 'page');
        button.addEventListener('click', () => loadCaseList(table, pageNumber));
        controls.appendChild(button);
    };

    addPageButton('Previous', page - 1, page <= 1);

    const firstVisiblePage = Math.max(1, Math.min(page - 2, totalPages - 4));
    const lastVisiblePage = Math.min(totalPages, firstVisiblePage + 4);
    for (let number = firstVisiblePage; number <= lastVisiblePage; number++) {
        addPageButton(String(number), number, number === page, number === page);
    }

    addPageButton('Next', page + 1, page >= totalPages);
}

function renderCaseRow(item) {
    const caseId = Number(item.case_id);

    if (!Number.isInteger(caseId) || caseId < 1) {
        return '';
    }

    const archived = item.case_status === 'Archived';

    const statusClass = String(item.case_status || '')
        .toLowerCase()
        .replaceAll(' ', '-');

    const activeCaseActions = archived
        ? ''
        : `
            <button
                type="button"
                onclick="openCaseAssignments(${caseId})"
            >
                Assign
            </button>

            <button
                type="button"
                class="archive-button"
                onclick="archiveCase(${caseId})"
            >
                Archive
            </button>
        `;

    return `
        <tr>
            <td>
                ${escapeHtml(
                    item.case_number || 'Not assigned'
                )}
            </td>

            <td>
                <strong>
                    ${escapeHtml(
                        item.complaint_number ||
                        'No complaint number'
                    )}
                </strong>

                <div class="case-table-secondary">
                    ${escapeHtml(
                        item.complaint_title ||
                        'Untitled complaint'
                    )}
                </div>
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
                ${escapeHtml(
                    item.docket_date ||
                    'Not recorded'
                )}
            </td>

            <td>
                <span class="status status-${statusClass}">
                    ${escapeHtml(
                        item.case_status ||
                        'Unknown'
                    )}
                </span>
            </td>

            <td class="action-buttons">
                <button
                    type="button"
                    onclick="openCaseWorkspace(${caseId})"
                >
                    Open Workspace
                </button>

                <button
                    type="button"
                    onclick="editCase(${caseId})"
                >
                    Edit
                </button>

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

function openCaseWorkspace(id) {
    const caseId = Number(id);

    if (!Number.isInteger(caseId) || caseId < 1) {
        window.agapNotify?.(
            'A valid case is required.',
            'error',
            'Unable to open case'
        );

        return;
    }

    window.location.href =
        'case-details.php?id=' +
        encodeURIComponent(caseId);
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

            details.innerHTML = `
                <dl class="case-details">
                    <dt>Case Number</dt>
                    <dd>
                        ${escapeHtml(
                            item.case_number ||
                            'Not assigned'
                        )}
                    </dd>

                    <dt>Complaint</dt>
                    <dd>
                        ${escapeHtml(
                            item.complaint_number ||
                            'No complaint number'
                        )}
                        -
                        ${escapeHtml(
                            item.complaint_title ||
                            'Untitled complaint'
                        )}
                    </dd>

                    <dt>Case Type</dt>
                    <dd>
                        ${escapeHtml(
                            item.case_type ||
                            'Not recorded'
                        )}
                    </dd>

                    <dt>Status</dt>
                    <dd>
                        ${escapeHtml(
                            item.case_status ||
                            'Unknown'
                        )}
                    </dd>

                    <dt>Docket Date</dt>
                    <dd>
                        ${escapeHtml(
                            item.docket_date ||
                            'Not recorded'
                        )}
                    </dd>

                    <dt>Incident Date</dt>
                    <dd>
                        ${escapeHtml(
                            item.incident_date ||
                            'Not recorded'
                        )}
                    </dd>

                    <dt>Narrative</dt>
                    <dd>
                        ${escapeHtml(
                            item.narrative ||
                            'Not recorded'
                        )}
                    </dd>
                </dl>
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

function openCaseAssignments(caseId) {
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

    window.openCaseAssignments(id);
}
