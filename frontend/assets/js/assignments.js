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
        if (currentCase.case_status === 'Conciliation') return false;
        if (currentCase.mediation_timer?.is_lapsed) return false;
        return currentCase.case_status === 'Mediation' || currentCase.case_status === 'Docketed';
    }

    function isCurrentMediationCase() {
        return isMediationCase();
    }

    function configureAssignmentMode(rows = currentAssignments) {
        const status = selectedCase()?.case_status;
        const curCase = selectedCase();
        const mediation = isMediationCase();

        // A complete Conciliation Lupon team has 3 distinct members with role_name === 'Lupon Member'
        const luponMembersAssigned = rows.filter(
            (item) => ['Head', 'Secretary', 'Member'].includes(item.assignment_role) &&
                      item.role_name === 'Lupon Member'
        );
        const hasCompleteConciliationTeam = (status === 'Conciliation' || !mediation) && luponMembersAssigned.length >= 2;
        const locked = (mediation && !curCase?.mediation_timer?.is_lapsed) || hasCompleteConciliationTeam;

        const pangkatSection = document.getElementById('pangkatConfigSection');
        if (pangkatSection) {
            pangkatSection.style.display = (mediation && !curCase?.mediation_timer?.is_lapsed) ? 'none' : 'block';
        }

        headSelect.disabled = locked;
        headSelect.required = !locked;
        secretarySelect.disabled = locked;
        memberSelect.disabled = locked;

        if (mediation && !curCase?.mediation_timer?.is_lapsed) {
            secretarySelect.replaceChildren(new Option('Not available during Mediation', ''));
            memberSelect.replaceChildren(new Option('Not available during Mediation', ''));
        } else {
            populateMemberSelect(secretarySelect, 'Select a Lupon Member');
            populateMemberSelect(memberSelect, 'Select a Lupon Member');
            const byRole = Object.fromEntries(rows.filter(item => item.role_name === 'Lupon Member').map((item) => [item.assignment_role, String(item.member_id)]));
            headSelect.value = byRole.Head || '';
            secretarySelect.value = byRole.Secretary || '';
            memberSelect.value = byRole.Member || '';
        }

        const saveButton = form.querySelector('button[type="submit"]');
        if (saveButton) saveButton.disabled = locked;
        if (assignmentHelp) {
            assignmentHelp.textContent = (mediation && !curCase?.mediation_timer?.is_lapsed)
                ? 'The Administrator is automatically assigned as Head during Mediation. Secretary and Member are not manually assigned.'
                : hasCompleteConciliationTeam
                    ? 'This assigned Conciliation team is read-only here; use Edit to make changes.'
                    : 'Choose the Lupon team (Head, Secretary, Member) for Conciliation. All members must be active Lupon Members. The Administrator is for Mediation only.';
        }
        if (assignmentRuleMessage) {
            assignmentRuleMessage.hidden = !locked;
            assignmentRuleMessage.textContent = (mediation && !curCase?.mediation_timer?.is_lapsed)
                ? 'Lupon assignment is automatic for Docketed and Mediation cases. The Barangay Captain is automatically assigned as Head.'
                : hasCompleteConciliationTeam
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

        const ongoingRows = rows.filter((item) =>
            !['Settled', 'Dismissed', 'DISMISSED_BARRED', 'Archived'].includes(String(item.case_status || '').trim())
        );

        casesById = new Map(ongoingRows.map((item) => [String(item.case_id), item]));

        caseSelect.replaceChildren(new Option('Select a case', ''));

        ongoingRows.forEach((item) => {
            const caseId = Number(item.case_id);
            if (!Number.isInteger(caseId) || caseId < 1) {
                return;
            }

            const caseNumber = item.case_number || 'No case number';
            const complaintTitle = item.complaint_title || 'Untitled complaint';
            const complainants = item.complainant_names || 'No complainant recorded';
            const respondents = item.respondent_names || 'No respondent recorded';

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
                    <td colspan="4" class="empty-state">
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

                const isHead = item.assignment_role === 'Head';
                const hasSubstitute = isHead && Boolean(item.substitute_presider_id);
                const subName = hasSubstitute
                    ? [item.substitute_last_name, item.substitute_first_name, item.substitute_middle_name].filter(Boolean).join(', ')
                    : '';

                let statusBadge = '<span class="badge" style="background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 12px; font-size: 0.76rem; font-weight: 600;">Active</span>';
                if (isHead) {
                    if (hasSubstitute) {
                        statusBadge = `
                            <span class="badge" style="background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 12px; font-size: 0.76rem; font-weight: 600;">Substituted</span>
                            <div style="font-size: 0.72rem; color: #475569; margin-top: 3px;">Sub: <strong>${escapeHtml(subName || 'Assigned')}</strong></div>
                        `;
                    } else {
                        statusBadge = '<span class="badge" style="background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 12px; font-size: 0.76rem; font-weight: 600;">Active Presider</span>';
                    }
                }

                let actionCell = '<span class="empty-cell" style="color: #94a3b8;">—</span>';
                if (isHead) {
                    actionCell = `
                        <button type="button" class="btn-action-view btn-substitute-trigger" data-case-id="${escapeHtml(item.case_id || '')}" data-hearing-id="${escapeHtml(item.hearing_id || '')}" style="background: #2563eb; color: #ffffff; border: none; padding: 5px 11px; border-radius: 5px; font-size: 0.78rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="Designate or Update Substitute Presider">
                            👤 Substitute Presider
                        </button>
                    `;
                }

                return `
                    <tr>
                        <td><strong>${escapeHtml(name || 'Unnamed user')}</strong></td>
                        <td><span class="badge-role" style="font-size: 0.82rem;">${escapeHtml(item.assignment_role || 'Not assigned')}</span></td>
                        <td>${statusBadge}</td>
                        <td>${actionCell}</td>
                    </tr>
                `;
            })
            .join('');

        assignmentTable.querySelectorAll('.btn-substitute-trigger').forEach((btn) => {
            btn.addEventListener('click', () => {
                openSubstitutePresiderForCase();
            });
        });
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
                    <td colspan="4" class="empty-state">
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
                    <td colspan="4" class="empty-state">
                        ${escapeHtml(error.message)}
                    </td>
                </tr>
            `;
        }
    }

    window.openCaseAssignments = (caseId, shouldScroll = true) => {
        const id = String(caseId);
        const matchingOption = Array.from(caseSelect.options).find(
            (option) => option.value === id
        );

        if (!matchingOption) {
            showMessage('The selected case is not available for assignment.');
            return;
        }

        caseSelect.value = id;
        window.activeAssignedCaseId = id;
        if (typeof window.highlightActiveCaseRow === 'function') {
            window.highlightActiveCaseRow(id);
        }

        if (shouldScroll) {
            document.getElementById('teamForm')?.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

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

    caseSelect.addEventListener('change', () => {
        window.activeAssignedCaseId = caseSelect.value;
        if (typeof window.highlightActiveCaseRow === 'function') {
            window.highlightActiveCaseRow(caseSelect.value);
        }
        loadAssignments();
    });

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

        const quorumSelect = document.getElementById('quorumSize');
        const quorumSize = quorumSelect ? quorumSelect.value : '3';
        const quorumReason = document.getElementById('quorumExceptionReason')?.value?.trim() || '';
        const selectionMethod = document.getElementById('selectionMethod')?.value || 'Party Agreement';
        const selectionNotes = document.getElementById('selectionNotes')?.value?.trim() || '';

        if (quorumSize === '2') {
            if (!headId || !secretaryId) {
                showMessage('Select the Head and Secretary for a 2-member quorum.');
                return;
            }
            if (!quorumReason) {
                showMessage('A valid justification / party consent is required when proceeding with a 2-member quorum.');
                return;
            }
            if (headId === secretaryId) {
                showMessage('Head and Secretary must be different users.');
                return;
            }
        } else {
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
        }

        const data = new FormData(form);
        data.set('case_id', caseSelect.value);
        data.set('head_id', headId);
        data.set('secretary_id', secretaryId);
        data.set('member_id', memberId || '');
        data.set('quorum_size', quorumSize);
        data.set('quorum_exception_reason', quorumReason);
        data.set('selection_method', selectionMethod);
        data.set('selection_notes', selectionNotes);

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
            initCaseTeamSearch();
            const urlParams = new URLSearchParams(window.location.search);
            const assignCaseId = urlParams.get('assign_case_id') || urlParams.get('case_id');
            if (assignCaseId && casesById.has(String(assignCaseId))) {
                window.openCaseAssignments(assignCaseId, false);
            }
        })
        .catch((error) => {
            showMessage(error.message);
        });

    function initCaseTeamSearch() {
        const searchInput = document.getElementById('teamCaseSearchInput');
        const searchBtn = document.getElementById('btnTeamCaseSearch');
        const clearBtn = document.getElementById('btnTeamCaseSearchClear');
        const resultsBox = document.getElementById('teamCaseSearchResults');
        const feedback = document.getElementById('teamCaseSearchFeedback');

        if (!searchInput || !searchBtn || !resultsBox) return;

        function closeResults() {
            resultsBox.style.display = 'none';
            resultsBox.replaceChildren();
        }

        function filterCases(query) {
            const q = query.trim().toLowerCase();
            if (!q) return [];
            return Array.from(casesById.values()).filter((c) => {
                if (c.case_status === 'Archived') return false;
                const caseNum = (c.case_number || '').toLowerCase();
                const title = (c.complaint_title || '').toLowerCase();
                const comp = (c.complainant_names || '').toLowerCase();
                const resp = (c.respondent_names || '').toLowerCase();
                const cid = String(c.case_id || '');
                return caseNum.includes(q) || title.includes(q) || comp.includes(q) || resp.includes(q) || cid === q;
            });
        }

        function selectSearchedCase(item) {
            searchInput.value = item.case_number || `Case #${item.case_id}`;
            closeResults();
            if (clearBtn) clearBtn.style.display = 'inline-block';

            if (caseSelect) {
                caseSelect.value = String(item.case_id);
                loadAssignments();
            }

            if (feedback) {
                feedback.innerHTML = `✓ Generated case team assignment for <strong>${escapeHtml(item.case_number || '')}</strong> &mdash; ${escapeHtml(item.complaint_title || '')} (${escapeHtml(item.case_status || 'Active')})`;
                feedback.style.color = '#0284c7';
                feedback.style.display = 'block';
            }
        }

        searchInput.addEventListener('input', () => {
            const query = searchInput.value;
            if (!query.trim()) {
                closeResults();
                if (clearBtn) clearBtn.style.display = 'none';
                if (feedback) feedback.style.display = 'none';
                return;
            }
            if (clearBtn) clearBtn.style.display = 'inline-block';

            const matches = filterCases(query);
            if (!matches.length) {
                resultsBox.innerHTML = '<div style="padding: 10px 14px; color: #64748b; font-size: 0.84rem;">No matching cases found.</div>';
                resultsBox.style.display = 'block';
                return;
            }

            resultsBox.innerHTML = matches.slice(0, 8).map((item) => `
                <div class="search-result-item" data-case-id="${item.case_id}" style="padding: 9px 12px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background 0.15s ease;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <strong style="color: #0f172a; font-size: 0.86rem;">${escapeHtml(item.case_number || `Case #${item.case_id}`)}</strong>
                        <span style="font-size: 0.72rem; padding: 2px 7px; border-radius: 999px; background: #e0f2fe; color: #0369a1; font-weight: 600;">${escapeHtml(item.case_status || '')}</span>
                    </div>
                    <div style="font-size: 0.8rem; color: #334155; margin-top: 2px;">${escapeHtml(item.complaint_title || 'Untitled')}</div>
                    <div style="font-size: 0.75rem; color: #64748b;">Complainant: ${escapeHtml(item.complainant_names || '—')} | Resp: ${escapeHtml(item.respondent_names || '—')}</div>
                </div>
            `).join('');

            resultsBox.querySelectorAll('.search-result-item').forEach((elem) => {
                elem.addEventListener('mouseenter', () => { elem.style.background = '#f8fafc'; });
                elem.addEventListener('mouseleave', () => { elem.style.background = '#ffffff'; });
                elem.addEventListener('click', () => {
                    const cid = elem.dataset.caseId;
                    const c = casesById.get(String(cid));
                    if (c) selectSearchedCase(c);
                });
            });

            resultsBox.style.display = 'block';
        });

        searchBtn.addEventListener('click', () => {
            const query = searchInput.value;
            const matches = filterCases(query);
            if (matches.length > 0) {
                selectSearchedCase(matches[0]);
            } else if (query.trim()) {
                if (feedback) {
                    feedback.textContent = 'No matching case found for "' + query.trim() + '".';
                    feedback.style.color = '#dc2626';
                    feedback.style.display = 'block';
                }
            }
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchBtn.click();
            }
        });

        clearBtn?.addEventListener('click', () => {
            searchInput.value = '';
            closeResults();
            clearBtn.style.display = 'none';
            if (feedback) feedback.style.display = 'none';
        });

        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) {
                closeResults();
            }
        });
    }

    function openSubstitutePresiderForCase() {
        const modal = document.getElementById('substitutePresiderModal');
        const caseInput = document.getElementById('substituteCaseId');
        const hearingInput = document.getElementById('substituteHearingId');
        const presiderSelect = document.getElementById('substitutePresiderId');
        const reasonInput = document.getElementById('substituteReason');
        const consentCheckbox = document.getElementById('partiesConsentCheckbox');
        const alertBox = document.getElementById('substituteAlert');

        if (!modal) return;
        if (!caseSelect.value) {
            showMessage('Select a case first before assigning a substitute presider.');
            return;
        }

        if (caseInput) caseInput.value = caseSelect.value;
        const headAssignment = currentAssignments.find((a) => a.assignment_role === 'Head') || currentAssignments[0];
        if (hearingInput) hearingInput.value = headAssignment?.hearing_id || '';

        if (presiderSelect) {
            presiderSelect.replaceChildren(new Option('Select an active Lupon Member', ''));
            luponMembers.forEach((m) => {
                const name = [m.last_name, m.first_name, m.middle_name].filter(Boolean).join(', ');
                presiderSelect.add(new Option(name, m.member_id));
            });
            if (headAssignment?.substitute_presider_id) {
                presiderSelect.value = String(headAssignment.substitute_presider_id);
            } else {
                presiderSelect.value = '';
            }
        }

        if (reasonInput) {
            reasonInput.value = headAssignment?.substitute_reason || '';
        }

        if (consentCheckbox) {
            consentCheckbox.checked = Boolean(headAssignment?.parties_consent_to_substitute);
        }

        if (alertBox) alertBox.style.display = 'none';
        modal.style.display = 'flex';
    }
    window.openSubstitutePresiderForCase = openSubstitutePresiderForCase;

    function closeSubstitutePresiderModal() {
        const modal = document.getElementById('substitutePresiderModal');
        if (modal) modal.style.display = 'none';
    }
    window.closeSubstitutePresiderModal = closeSubstitutePresiderModal;

    async function handleSubstituteSubmit(event) {
        if (event) event.preventDefault();
        const form = document.getElementById('substitutePresiderForm');
        const alertBox = document.getElementById('substituteAlert');
        const consent = document.getElementById('partiesConsentCheckbox');

        if (!form) return;
        if (!consent || !consent.checked) {
            if (alertBox) {
                alertBox.textContent = 'Mandatory mutual party consent must be confirmed before assigning a substitute.';
                alertBox.className = 'alert error';
                alertBox.style.display = 'block';
            }
            return;
        }

        try {
            const formData = new FormData(form);
            const result = await api('../../../backend/api/hearings/substitute.php', {
                method: 'POST',
                body: formData
            });

            closeSubstitutePresiderModal();
            showMessage(result.message || 'Substitute presider designated with mutual party consent.', true);
            await loadAssignments();
        } catch (err) {
            if (alertBox) {
                alertBox.textContent = err.message || 'Failed to designate substitute presider.';
                alertBox.className = 'alert error';
                alertBox.style.display = 'block';
            }
        }
    }
    window.handleSubstituteSubmit = handleSubstituteSubmit;

    // AGAP_UNIFIED_SYNC_ASSIGNMENTS
    window.addEventListener('agap:data-changed', async (event) => {
        const changed = event.detail?.modules || [];
        if (!changed.some((name) => ['complaints', 'cases', 'assignments', 'pangkat', 'hearings', 'deadlines', 'history'].includes(name))) {
            return;
        }
        if (form.dataset.syncRefreshing === '1') return;
        form.dataset.syncRefreshing = '1';
        const selectedCaseId = caseSelect.value;
        try {
            await Promise.allSettled([loadCases(), loadMembers()]);
            if (selectedCaseId && casesById.has(String(selectedCaseId))) {
                caseSelect.value = String(selectedCaseId);
            }
            await loadAssignments();
        } finally {
            window.setTimeout(() => delete form.dataset.syncRefreshing, 400);
        }
    });

    window.toggleQuorumReason = (size) => {
        const group = document.getElementById('quorumReasonGroup');
        const memberSelect = document.getElementById('teamMemberId');
        if (group) group.style.display = size === '2' ? 'block' : 'none';
        if (memberSelect) memberSelect.required = size !== '2';
    };

    window.toggleEditQuorumReason = (size) => {
        const group = document.getElementById('editQuorumReasonGroup');
        const memberSelect = document.getElementById('editMemberId');
        if (group) group.style.display = size === '2' ? 'block' : 'none';
        if (memberSelect) memberSelect.required = size !== '2';
    };

})();
