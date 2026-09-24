(() => {
    const assignmentTable = document.getElementById('assignmentTable');
    const caseSelect = document.getElementById('caseId');
    const form = document.getElementById('teamForm');
    const message = document.getElementById('teamMessage');
    const headSelect = document.getElementById('headId');
    const secretarySelect = document.getElementById('secretaryId');
    const memberSelect = document.getElementById('teamMemberId');
    const automaticHeadDisplay = document.getElementById('automaticHeadDisplay');
    const automaticHeadName = document.getElementById('automaticHeadName');
    const assignmentHelp = document.getElementById('assignmentHelp');
    const assignmentRuleMessage = document.getElementById('assignmentRuleMessage');

    if (
        !assignmentTable ||
        !caseSelect ||
        !form ||
        !message ||
        !headSelect ||
        !secretarySelect ||
        !memberSelect ||
        !automaticHeadDisplay ||
        !automaticHeadName
    ) {
        return;
    }

    let casesById = new Map();
    let luponMembers = [];
    let automaticHead = null;
    let currentAssignments = [];

    const api = (url, options = {}) =>
        fetch(url, options).then(async (response) => {
            const data = await response.json().catch(() => ({
                success: false,
                message: 'Invalid server response.'
            }));

            if (!response.ok || data.success === false) {
                throw new Error(data.message || 'Request failed.');
            }

            return data;
        });

    const escapeHtml = (value) => {
        const node =
            document.createElement('div');

        node.textContent = value ?? '';
        return node.innerHTML;
    };

    const fullName = (person) => {
        return [
            person?.first_name,
            person?.middle_name,
            person?.last_name
        ]
            .filter(Boolean)
            .join(' ');
    };

    const showMessage = (text, success = false) => {
        message.textContent = text;
        message.className = text
            ? `assignment-message ${success ? 'success' : 'error'}`
            : '';

        if (text) {
            window.agapNotify?.(
                text,
                success ? 'success' : 'error',
                success ? 'Case team updated' : 'Assignment error'
            );
        }
    };

    function selectedCase() {
        return casesById.get(String(caseSelect.value)) || null;
    }

    function isMediationCase() {
        const currentCase = selectedCase();
        if (!currentCase) return false;
        return currentCase.case_status === 'Mediation' || currentCase.case_status === 'Docketed';
    }

    function isCurrentMediationCase() {
        return ['Docketed', 'Mediation'].includes(selectedCase()?.case_status);
    }

    function configureAssignmentMode(rows = currentAssignments) {
        const status = selectedCase()?.case_status;
        const mediation = ['Docketed', 'Mediation'].includes(status);
        const hasConciliationTeam = status === 'Conciliation' && rows.some(
            (item) => ['Head', 'Secretary', 'Member'].includes(item.assignment_role)
        );
        const locked = mediation || hasConciliationTeam;

        headSelect.disabled = mediation || hasConciliationTeam;
        headSelect.required = !mediation && !hasConciliationTeam;
        secretarySelect.disabled = locked;
        memberSelect.disabled = locked;
        if (mediation) {
            secretarySelect.replaceChildren(new Option('Not available during Mediation', ''));
            memberSelect.replaceChildren(new Option('Not available during Mediation', ''));
        } else {
            populateMemberSelect(secretarySelect, 'Select a Lupon Member');
            populateMemberSelect(memberSelect, 'Select a Lupon Member');
            const byRole = Object.fromEntries(rows.map((item) => [item.assignment_role, String(item.member_id)]));
            secretarySelect.value = byRole.Secretary || '';
            memberSelect.value = byRole.Member || '';
        }

        const saveButton = form.querySelector('button[type="submit"]');
        if (saveButton) saveButton.disabled = locked;
        if (assignmentHelp) {
            assignmentHelp.textContent = mediation
                ? 'Secretary and Member are not manually assigned during Mediation.'
                : hasConciliationTeam
                    ? 'This assigned Conciliation team is read-only here; use Edit to make changes.'
                    : 'All three roles are required and must be assigned to different active Lupon Members.';
        }
        if (assignmentRuleMessage) {
            assignmentRuleMessage.hidden = !locked;
            assignmentRuleMessage.textContent = mediation
                ? 'Lupon assignment is automatic for Docketed and Mediation cases. The Barangay Captain is automatically assigned as Head.'
                : hasConciliationTeam
                    ? 'This Conciliation case already has an assigned Lupon team. Use Edit to modify the assigned members.'
                    : '';
        }
    }

    function showAutomaticHead() {
        const name = fullName(automaticHead) || 'Administrator';
        automaticHeadName.textContent = name;
        automaticHeadDisplay.hidden = false;

        headSelect.hidden = true;
        headSelect.disabled = true;
        headSelect.required = false;
    }

    function hideAutomaticHead() {
        automaticHeadDisplay.hidden = true;

        headSelect.hidden = false;
        headSelect.disabled = false;
        headSelect.required = true;
    }

    function configureHeadField() {
        if (!isMediationCase()) {
            hideAutomaticHead();
            return;
        }

        /*
         * For Mediation, the Head dropdown must never be used.
         */
        headSelect.hidden = true;
        headSelect.disabled = true;
        headSelect.required = false;

        showAutomaticHead();
    }

    function populateMemberSelect(select, placeholder) {
        select.replaceChildren(new Option(placeholder, ''));

        luponMembers.forEach((member) => {
            const memberId = Number(member.member_id);
            if (!Number.isInteger(memberId) || memberId < 1) {
                return;
            }

            const name = [
                member.last_name,
                member.first_name,
                member.middle_name
            ]
                .filter(Boolean)
                .join(', ');

            select.add(new Option(name || 'Unnamed Lupon Member', memberId));
        });
    }

    async function loadCases() {
        const cases = await api('../../../backend/api/cases/list.php');
        const rows = Array.isArray(cases) ? cases : [];

        casesById = new Map(rows.map((item) => [String(item.case_id), item]));

        caseSelect.replaceChildren(new Option('Select a case', ''));

        rows
            .filter((item) => item.case_status !== 'Archived')
            .forEach((item) => {
                const caseId = Number(item.case_id);
                if (!Number.isInteger(caseId) || caseId < 1) {
                    return;
                }

                const caseNumber = item.case_number || 'No case number';
                const complaintTitle = item.complaint_title || 'Untitled complaint';
                const complainants = item.complainant_names || 'No complainant recorded';
                const respondents = item.respondent_names || 'No respondent recorded';

                const complainants =
                    item.complainant_names ||
                    'No complainant recorded';

                const respondents =
                    item.respondent_names ||
                    'No respondent recorded';

                const label = [
                    caseNumber,
                    complaintTitle,
                    `Complainant: ${complainants}`,
                    `Respondent: ${respondents}`
                ].join(' | ');

                caseSelect.add(new Option(label, caseId));
            });
    }

    async function loadMembers() {
        const members = await api('../../../backend/api/assignments/lupon-members.php');
        luponMembers = Array.isArray(members) ? members : [];

        populateMemberSelect(headSelect, 'Select a Lupon Member');
        populateMemberSelect(secretarySelect, 'Select a Lupon Member');
        populateMemberSelect(memberSelect, 'Select a Lupon Member');
    }

    function renderAssignmentTable(rows) {
        if (!rows.length) {
            assignmentTable.innerHTML = `
                <tr>
                    <td colspan="3" class="empty-state">
                        No case team has been assigned.
                    </td>
                </tr>
            `;
            return;
        }

        assignmentTable.innerHTML = rows
            .map((item) => {
                const name = [
                    item.last_name,
                    item.first_name,
                    item.middle_name
                ]
                    .filter(Boolean)
                    .join(', ');

                return `
                    <tr>
                        <td>${escapeHtml(name || 'Unnamed user')}</td>
                        <td>${escapeHtml(item.assignment_role || 'Not assigned')}</td>
                        <td>${escapeHtml(item.assigned_date || 'Not recorded')}</td>
                    </tr>
                `;
            })
            .join('');
    }

    async function loadAssignments() {
        headSelect.value = '';
        secretarySelect.value = '';
        memberSelect.value = '';
        automaticHead = null;
        currentAssignments = [];
        configureAssignmentMode([]);

        if (!caseSelect.value) {
            hideAutomaticHead();
            assignmentTable.innerHTML = `
                <tr>
                    <td colspan="3" class="empty-state">
                        Select a case to view its team.
                    </td>
                </tr>
            `;
            return;
        }

        /*
         * Hide the Head dropdown immediately when the selected
         * case is Mediation, even before the API finishes.
         */
        configureHeadField();

        try {
            const assignments = await api(
                '../../../backend/api/assignments/list.php?case_id=' +
                encodeURIComponent(caseSelect.value)
            );

            const rows = Array.isArray(assignments) ? assignments : [];
            currentAssignments = rows;
            renderAssignmentTable(isCurrentMediationCase()
                ? rows.filter((item) => item.assignment_role === 'Head')
                : rows);

            const byRole = Object.fromEntries(
                rows.map((item) => [item.assignment_role, String(item.member_id)])
            );

            if (isMediationCase()) {
                const headAssignment = rows.find(
                    (item) => item.assignment_role === 'Head'
                );

                automaticHead = headAssignment
                    ? {
                        member_id: headAssignment.member_id,
                        first_name: headAssignment.first_name,
                        middle_name: headAssignment.middle_name,
                        last_name: headAssignment.last_name,
                        username: headAssignment.username,
                        role_name: headAssignment.role_name
                    }
                    : null;
            } else {
                automaticHead = null;
                headSelect.value = byRole.Head || '';
            }

            secretarySelect.value = byRole.Secretary || '';
            memberSelect.value = byRole.Member || '';

            configureHeadField();
            configureAssignmentMode(rows);

            if (isMediationCase() && !automaticHead) {
                showMessage(
                    'The active Administrator or Barangay Captain Head assignment could not be found.'
                );
            }
        } catch (error) {
            automaticHead = null;
            currentAssignments = [];
            configureHeadField();
            configureAssignmentMode([]);

            assignmentTable.innerHTML = `
                <tr>
                    <td colspan="3" class="empty-state">
                        ${escapeHtml(error.message)}
                    </td>
                </tr>
            `;
        }
    }

    window.openCaseAssignments = (caseId) => {
        const id = String(caseId);
        const matchingOption = Array.from(caseSelect.options).find(
            (option) => option.value === id
        );

        if (!matchingOption) {
            showMessage('The selected case is not available for assignment.');
            return;
        }

        caseSelect.value = id;
        document.getElementById('caseAssignments')?.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });

        loadAssignments();
    };

    window.refreshCaseAssignments = async (caseId) => {
        const previousSelection = caseSelect.value;
        try {
            await loadCases();
            const nextId = String(caseId || previousSelection);
            if (Array.from(caseSelect.options).some((option) => option.value === nextId)) {
                caseSelect.value = nextId;
            }
            await loadAssignments();
        } catch (error) {
            showMessage(error.message);
        }
    };

    caseSelect.addEventListener('change', loadAssignments);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        showMessage('');

        if (isCurrentMediationCase()) {
            showMessage('Lupon assignment is automatic for Docketed and Mediation cases. The Barangay Captain is automatically assigned as Head.');
            return;
        }

        if (selectedCase()?.case_status === 'Conciliation' && currentAssignments.some(
            (item) => ['Head', 'Secretary', 'Member'].includes(item.assignment_role)
        )) {
            showMessage('This Conciliation case already has an assigned Lupon team. Use Edit to modify the assigned members.');
            return;
        }

        if (!caseSelect.value) {
            showMessage('Select a case before saving.');
            return;
        }

        if (isMediationCase() && !automaticHead) {
            showMessage(
                'The active Administrator or Barangay Captain Head assignment could not be found.'
            );
            return;
        }

        const headId = isMediationCase()
            ? String(automaticHead.member_id)
            : headSelect.value;
        const secretaryId = secretarySelect.value;
        const memberId = memberSelect.value;

        if (!headId || !secretaryId || !memberId) {
            showMessage(
                isMediationCase()
                    ? 'Select the Secretary and Member.'
                    : 'Select the Head, Secretary, and Member.'
            );
            return;
        }

        const selectedIds = [headId, secretaryId, memberId];
        if (new Set(selectedIds).size !== 3) {
            showMessage('Head, Secretary, and Member must be different users.');
            return;
        }

        const data = new FormData(form);
        data.set('case_id', caseSelect.value);
        /*
         * The Head field is disabled for Mediation and is
         * therefore not included in FormData automatically.
         */
        data.set('head_id', headId);
        data.set('secretary_id', secretaryId);
        data.set('member_id', memberId);

        try {
            const result = await api('../../../backend/api/assignments/team.php', {
                method: 'POST',
                body: data
            });

            showMessage(result.message || 'Case team saved successfully.', true);
            await loadAssignments();
        } catch (error) {
            showMessage(error.message);
        }
    });

    Promise.all([loadCases(), loadMembers()])
        .then(() => {
            hideAutomaticHead();
        })
        .catch((error) => {
            showMessage(error.message);
        });
})();
