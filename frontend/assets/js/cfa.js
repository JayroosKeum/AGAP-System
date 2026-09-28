const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
};

const cfaApi = async (url, options = {}) => {
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'Invalid response from server.' }));
    if (!response.ok || !result.success) {
        throw new Error(result.message || 'Request failed.');
    }
    return result;
};

document.addEventListener('DOMContentLoaded', () => {
    loadCases();
    loadCfaRecords();

    const caseSelect = document.getElementById('cfaCaseId');
    const presetSelect = document.getElementById('cfaPresetReason');
    const reasonText = document.getElementById('cfaReasonText');
    const form = document.getElementById('cfaIssueForm');

    caseSelect?.addEventListener('change', () => {
        loadCasePreview(caseSelect.value);
    });

    presetSelect?.addEventListener('change', () => {
        const val = presetSelect.value;
        if (val && val !== 'custom') {
            reasonText.value = val;
        } else if (val === 'custom') {
            reasonText.value = '';
            reasonText.focus();
        }
    });

    form?.addEventListener('submit', handleCfaSubmit);
});

async function loadCases() {
    const select = document.getElementById('cfaCaseId');
    if (!select) return;

    try {
        const res = await cfaApi('../../../backend/api/documents/cases.php');
        const cases = res.data || [];
        select.replaceChildren(new Option('Select an eligible case', ''));

        cases.forEach((item) => {
            const opt = new Option(`${item.case_number} — ${item.complaint_title} (${item.case_status})`, item.case_id);
            select.add(opt);
        });

        const preselected = window.AGAP_CFA?.preselectedCaseId;
        if (preselected && cases.some((c) => Number(c.case_id) === Number(preselected))) {
            select.value = String(preselected);
            loadCasePreview(preselected);
        }
    } catch (err) {
        select.replaceChildren(new Option('Unable to load cases', ''));
        setMessage(err.message, false);
    }
}

async function loadCasePreview(caseId) {
    const preview = document.getElementById('cfaCaseCasePreview') || document.getElementById('cfaCasePreview');
    const presetSelect = document.getElementById('cfaPresetReason');
    const reasonText = document.getElementById('cfaReasonText');
    if (!preview) return;

    if (!caseId) {
        preview.textContent = 'Select a case above to review its current status and mediation timeline.';
        preview.className = 'document-preview muted';
        return;
    }

    try {
        preview.textContent = 'Loading case details...';
        const res = await cfaApi(`../../../backend/api/cases/workspace.php?id=${encodeURIComponent(caseId)}`);
        const item = res.data.case;
        const timer = item.mediation_timer || {};

        let timerHtml = '';
        if (item.case_status === 'Mediation' && timer.timer_status) {
            timerHtml = `
                <div style="margin-top: 0.5rem;">
                    <strong>Mediation statutory deadline:</strong>
                    <span class="badge ${escapeHtml(timer.badge_class)}" style="margin-left: 0.4rem;">
                        ${escapeHtml(timer.badge_label)}
                    </span>
                    <span style="font-size: 0.85rem; color: #64748b; margin-left: 0.5rem;">
                        (Due: ${escapeHtml(timer.mediation_deadline_date || 'N/A')})
                    </span>
                </div>
            `;
        }

        preview.className = 'document-preview';
        preview.innerHTML = `
            <div><strong>Case:</strong> ${escapeHtml(item.case_number)} — ${escapeHtml(item.complaint_title)}</div>
            <div><strong>Stage / Status:</strong> ${escapeHtml(item.case_status)}</div>
            <div><strong>Docket Date:</strong> ${escapeHtml(item.docket_date)}</div>
            ${timerHtml}
        `;

        // If the case mediation has lapsed, prefill the reason automatically!
        if (timer.is_lapsed && presetSelect && reasonText) {
            presetSelect.value = '15-day statutory mediation period has lapsed without settlement or constitution of Pangkat Tagapagkasundo (RA 7160 Sec. 410(b)).';
            reasonText.value = presetSelect.value;
        }
    } catch (err) {
        preview.textContent = 'Unable to load case preview: ' + err.message;
        preview.className = 'document-preview text-danger';
    }
}

async function handleCfaSubmit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submitBtn = document.getElementById('btnSubmitCfa');
    setMessage('', false);

    const caseId = document.getElementById('cfaCaseId')?.value;
    const reason = document.getElementById('cfaReasonText')?.value?.trim();

    if (!caseId) {
        setMessage('Please select a case.', false);
        return;
    }

    if (!reason) {
        setMessage('Please specify the ground or findings for issuing the CFA.', false);
        return;
    }

    if (!window.confirm('Are you sure you want to issue a Certificate to File Action (CFA) for this case? This will mark the case as CFA Issued.')) {
        return;
    }

    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(form);
        const res = await cfaApi('../../../backend/api/documents/cfa.php', {
            method: 'POST',
            body: formData
        });

        setMessage(res.message || 'CFA issued successfully!', true);
        window.agapNotify?.(res.message || 'CFA issued successfully.', 'success', 'Certificate to File Action Issued');
        form.reset();
        await Promise.all([loadCases(), loadCfaRecords()]);
        const preview = document.getElementById('cfaCasePreview');
        if (preview) {
            preview.textContent = 'CFA was successfully issued. Select another case to issue another CFA.';
            preview.className = 'document-preview muted';
        }
    } catch (err) {
        setMessage(err.message, false);
        window.agapNotify?.(err.message, 'error', 'Unable to Issue CFA');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

async function loadCfaRecords() {
    const table = document.getElementById('cfaRecordsTable');
    if (!table) return;

    try {
        const res = await cfaApi('../../../backend/api/documents/cfa.php');
        const records = res.data || [];

        if (!records.length) {
            table.innerHTML = '<tr><td colspan="5" class="empty-state">No Certificate to File Action (CFA) records found.</td></tr>';
            return;
        }

        table.innerHTML = records.map((r) => `
            <tr>
                <td><strong>${escapeHtml(r.case_number)}</strong></td>
                <td>${escapeHtml(r.complaint_title || 'Case ' + r.case_number)}</td>
                <td>${escapeHtml(r.issuance_date)}</td>
                <td style="max-width: 320px; white-space: normal; font-size: 0.875rem;">${escapeHtml(r.reason)}</td>
                <td><span class="status status-cfa-issued">CFA Issued</span></td>
            </tr>
        `).join('');
    } catch (err) {
        table.innerHTML = `<tr><td colspan="5" class="empty-state text-danger">${escapeHtml(err.message)}</td></tr>`;
    }
}

function setMessage(msg, isSuccess = false) {
    const el = document.getElementById('cfaMessage');
    if (!el) return;
    el.textContent = msg;
    el.className = msg ? (isSuccess ? 'page-alert page-alert-success' : 'page-alert page-alert-danger') : '';
}
