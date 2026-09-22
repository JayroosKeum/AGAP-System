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
});

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
        .then((cases) => {
            const rows = Array.isArray(cases)
                ? cases
                : [];

            docketedComplaintIds = new Set(
                rows.map(
                    (item) => String(item.complaint_id)
                )
            );

            renderComplaintOptions();

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
        });
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

function editCase(id) {
    getCase(id)
        .then((item) => {
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

            showModal('editCaseModal');
        })
        .catch((error) => {
            window.agapNotify?.(
                error.message,
                'error',
                'Unable to edit case'
            );
        });
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