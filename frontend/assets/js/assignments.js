(() => {
    const assignmentTable =
        document.getElementById('assignmentTable');

    const caseSelect =
        document.getElementById('caseId');

    const form =
        document.getElementById('teamForm');

    const message =
        document.getElementById('teamMessage');

    const selects = [
        'headId',
        'secretaryId',
        'teamMemberId'
    ].map((id) => document.getElementById(id));

    if (
        !assignmentTable ||
        !caseSelect ||
        !form ||
        !message ||
        selects.some((select) => !select)
    ) {
        return;
    }

    const api = (
        url,
        options = {}
    ) => fetch(url, options).then(async (response) => {
        const data = await response
            .json()
            .catch(() => ({
                message: 'Invalid server response.'
            }));

        if (!response.ok || data.success === false) {
            throw new Error(
                data.message || 'Request failed.'
            );
        }

        return data;
    });

    const escapeHtml = (value) => {
        const node = document.createElement('div');
        node.textContent = value ?? '';

        return node.innerHTML;
    };

    const showMessage = (
        text,
        success = false
    ) => {
        message.textContent = text;

        message.className = text
            ? `assignment-message ${
                success ? 'success' : 'error'
            }`
            : '';

        if (text) {
            window.agapNotify?.(
                text,
                success ? 'success' : 'error',
                success
                    ? 'Case team updated'
                    : 'Assignment error'
            );
        }
    };

    async function loadCases() {
        const cases = await api(
            '../../../backend/api/cases/list.php'
        );

        const rows = Array.isArray(cases)
            ? cases
            : [];

        caseSelect.replaceChildren(
            new Option('Select a case', '')
        );

        rows
            .filter(
                (item) =>
                    item.case_status !== 'Archived'
            )
            .forEach((item) => {
                const caseId = Number(item.case_id);

                if (
                    !Number.isInteger(caseId) ||
                    caseId < 1
                ) {
                    return;
                }

                const complainants =
                    item.complainant_names ||
                    'No complainant recorded';

                const respondents =
                    item.respondent_names ||
                    'No respondent recorded';

                const caseNumber =
                    item.case_number ||
                    'No case number';

                const complaintTitle =
                    item.complaint_title ||
                    'Untitled complaint';

                const label = [
                    caseNumber,
                    complaintTitle,
                    `Complainant: ${complainants}`,
                    `Respondent: ${respondents}`
                ].join(' | ');

                caseSelect.add(
                    new Option(label, caseId)
                );
            });
    }

    async function loadMembers() {
        const members = await api(
            '../../../backend/api/' +
            'assignments/lupon-members.php'
        );

        const rows = Array.isArray(members)
            ? members
            : [];

        selects.forEach((select) => {
            select.replaceChildren(
                new Option(
                    'Select a Lupon Member',
                    ''
                )
            );

            rows.forEach((member) => {
                const memberId =
                    Number(member.member_id);

                if (
                    !Number.isInteger(memberId) ||
                    memberId < 1
                ) {
                    return;
                }

                const fullName = [
                    member.last_name,
                    member.first_name
                ]
                    .filter(Boolean)
                    .join(', ');

                select.add(
                    new Option(
                        fullName ||
                            'Unnamed Lupon Member',
                        memberId
                    )
                );
            });
        });
    }

    async function loadAssignments() {
        clearAssignmentSelections();

        if (!caseSelect.value) {
            assignmentTable.innerHTML = `
                <tr>
                    <td
                        colspan="3"
                        class="empty-state"
                    >
                        Select a case to view its team.
                    </td>
                </tr>
            `;

            return;
        }

        try {
            const assignments = await api(
                '../../../backend/api/' +
                'assignments/list.php?case_id=' +
                encodeURIComponent(caseSelect.value)
            );

            const rows = Array.isArray(assignments)
                ? assignments
                : [];

            assignmentTable.innerHTML = rows.length
                ? rows.map((item) => `
                    <tr>
                        <td>
                            ${escapeHtml(
                                item.last_name ||
                                ''
                            )}${
                                item.last_name &&
                                item.first_name
                                    ? ', '
                                    : ''
                            }${escapeHtml(
                                item.first_name ||
                                'Unnamed member'
                            )}
                        </td>

                        <td>
                            ${escapeHtml(
                                item.assignment_role ||
                                'Not assigned'
                            )}
                        </td>

                        <td>
                            ${escapeHtml(
                                item.assigned_date ||
                                'Not recorded'
                            )}
                        </td>
                    </tr>
                `).join('')
                : `
                    <tr>
                        <td
                            colspan="3"
                            class="empty-state"
                        >
                            No case team has been assigned.
                        </td>
                    </tr>
                `;

            const byRole = Object.fromEntries(
                rows.map((item) => [
                    item.assignment_role,
                    String(item.member_id)
                ])
            );

            document.getElementById('headId').value =
                byRole.Head || '';

            document.getElementById(
                'secretaryId'
            ).value = byRole.Secretary || '';

            document.getElementById(
                'teamMemberId'
            ).value = byRole.Member || '';
        } catch (error) {
            assignmentTable.innerHTML = `
                <tr>
                    <td
                        colspan="3"
                        class="empty-state"
                    >
                        ${escapeHtml(error.message)}
                    </td>
                </tr>
            `;

            showMessage(error.message);
        }
    }

    function clearAssignmentSelections() {
        selects.forEach((select) => {
            select.value = '';
        });
    }

    window.openCaseAssignments = (caseId) => {
        const id = String(caseId);

        const matchingOption = Array.from(
            caseSelect.options
        ).find((option) => option.value === id);

        if (!matchingOption) {
            showMessage(
                'The selected case is not available for assignment.'
            );

            return;
        }

        caseSelect.value = id;

        document
            .getElementById('caseAssignments')
            ?.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });

        loadAssignments();
    };

    caseSelect.addEventListener(
        'change',
        loadAssignments
    );

    form.addEventListener(
        'submit',
        async (event) => {
            event.preventDefault();
            showMessage('');

            const ids = selects
                .map((select) => select.value)
                .filter(Boolean);

            if (
                !caseSelect.value ||
                ids.length !== 3
            ) {
                showMessage(
                    'Select a case and all three team ' +
                    'members before saving.'
                );

                return;
            }

            if (new Set(ids).size !== 3) {
                showMessage(
                    'Head, Secretary, and Member must ' +
                    'be different Lupon Members.'
                );

                return;
            }

            try {
                const result = await api(
                    '../../../backend/api/' +
                    'assignments/team.php',
                    {
                        method: 'POST',
                        body: new FormData(form)
                    }
                );

                showMessage(
                    result.message ||
                        'Case team saved successfully.',
                    true
                );

                await loadAssignments();
            } catch (error) {
                showMessage(error.message);
            }
        }
    );

    Promise.all([
        loadCases(),
        loadMembers()
    ]).catch((error) => {
        showMessage(error.message);
    });
})();