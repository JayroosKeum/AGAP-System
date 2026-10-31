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
    const cfaTypeSelect = document.getElementById('cfaType');
    const form = document.getElementById('cfaIssueForm');

    caseSelect?.addEventListener('change', () => {
        loadCasePreview(caseSelect.value);
    });

    cfaTypeSelect?.addEventListener('change', () => {
        const btn = document.getElementById('btnSubmitCfa');
        if (btn) btn.textContent = `Issue ${cfaTypeSelect.value || 'CFA'}`;
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
    const cfaTypeSelect = document.getElementById('cfaType');
    const submitBtn = document.getElementById('btnSubmitCfa');
    if (!preview) return;

    if (!caseId) {
        preview.textContent = 'Select a case above to review its current status, proceedings outcome, and eligibility.';
        preview.className = 'document-preview muted';
        return;
    }

    try {
        preview.textContent = 'Loading case details and evaluating eligibility...';
        const [res, cfaStatusRes] = await Promise.all([
            cfaApi(`../../../backend/api/cases/workspace.php?id=${encodeURIComponent(caseId)}`),
            cfaApi(`../../../backend/api/documents/cfa.php?case_id=${encodeURIComponent(caseId)}`).catch(() => ({ success: true, cfa_eligibility: null }))
        ]);

        const item = res.data.case;
        const timer = item.mediation_timer || {};
        const cfaElig = cfaStatusRes.cfa_eligibility || null;
        window.currentCaseCfaEligibility = cfaElig;

        // Populate cfaType options based on backend determination
        if (cfaTypeSelect && cfaElig) {
            const avail = cfaElig.available_forms || ['KP Form 20'];
            cfaTypeSelect.innerHTML = '';

            const formLabels = {
                'KP Form 20': 'KP Form 20 — Certificate to File Action (Katibayan Upang Makadulog sa Hukuman)',
                'KP Form 20-A': 'KP Form 20-A — Certificate to Bar Action (Katibayan Upang Hadlangan ang Paghahain ng Aksyon)',
                'KP Form 20-B': 'KP Form 20-B — Certificate to Bar Counterclaim (Katibayan Upang Hadlangan ang Ganting-Sakdal)'
            };

            avail.forEach((formCode) => {
                const opt = document.createElement('option');
                opt.value = formCode;
                opt.textContent = formLabels[formCode] || formCode;
                cfaTypeSelect.appendChild(opt);
            });

            if (cfaElig.recommended_form && avail.includes(cfaElig.recommended_form)) {
                cfaTypeSelect.value = cfaElig.recommended_form;
            }
            if (submitBtn) {
                submitBtn.textContent = `Issue ${cfaTypeSelect.value || 'CFA'}`;
            }
        }

        let statusBannerHtml = '';
        if (cfaElig) {
            if (cfaElig.eligible) {
                const isBarredComp = cfaElig.barred_complainant;
                const isRespDef = cfaElig.defaulted_respondent;

                if (isBarredComp) {
                    statusBannerHtml = `
                        <div style="margin-top: 0.75rem; padding: 12px 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-size: 0.88rem;">
                            <strong>Complainant Barred from Action (KP Form 20-A):</strong>
                            <p style="margin: 4px 0 0;">${escapeHtml(cfaElig.reason || 'Complainant willfully failed to appear. KP Form 20 is barred; only KP Form 20-A can be issued.')}</p>
                        </div>
                    `;
                    if (presetSelect && reasonText) {
                        presetSelect.value = 'Complainant willfully failed or refused to appear for hearing after summons without justifiable cause. Bar action issued pursuant to Sec. 412(b)(1).';
                        reasonText.value = presetSelect.value;
                    }
                } else if (isRespDef) {
                    statusBannerHtml = `
                        <div style="margin-top: 0.75rem; padding: 12px 16px; background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; color: #854d0e; font-size: 0.88rem;">
                            <strong>Respondent Default Established:</strong>
                            <p style="margin: 4px 0 0;">${escapeHtml(cfaElig.reason || 'Respondent willfully failed to appear. Counterclaim is barred (KP Form 20-B) and Complainant may receive KP Form 20.')}</p>
                        </div>
                    `;
                    if (presetSelect && reasonText) {
                        presetSelect.value = 'Respondent willfully failed or refused to appear for mediation after repeated summons without justifiable cause.';
                        reasonText.value = presetSelect.value;
                    }
                } else {
                    statusBannerHtml = `
                        <div style="margin-top: 0.75rem; padding: 12px 16px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; color: #166534; font-size: 0.88rem;">
                            <strong>CFA Grounds Met (${escapeHtml(cfaElig.recommended_form || 'KP Form 20')}):</strong>
                            <p style="margin: 4px 0 0;">${escapeHtml(cfaElig.reason || 'Case has satisfied statutory requirements for Certificate to File Action issuance.')}</p>
                        </div>
                    `;
                    if (presetSelect && reasonText && !reasonText.value) {
                        if (cfaElig.ground === 'CONCILIATION_IMPASSE') {
                            presetSelect.value = 'Conciliation proceedings before the Pangkat Tagapagkasundo failed to reach an amicable settlement within the statutory period.';
                        } else if (cfaElig.ground === 'REPUDIATED_SETTLEMENT') {
                            presetSelect.value = 'Amicable settlement was formally repudiated by a party within the 10-day statutory period.';
                        } else if (cfaElig.ground === 'SUMMONS_DELIVERY_FAILURE') {
                            presetSelect.value = 'Respondent summons unserved after documented delivery attempts (Delivery Failure).';
                        }
                        if (presetSelect.value && presetSelect.value !== 'custom') {
                            reasonText.value = presetSelect.value;
                        }
                    }
                }
            } else {
                statusBannerHtml = `
                    <div style="margin-top: 0.75rem; padding: 12px 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; color: #92400e; font-size: 0.88rem;">
                        <strong>CFA Not Yet Eligible:</strong>
                        <p style="margin: 4px 0 0;">${escapeHtml(cfaElig.reason || 'Dispute is currently active and has not met statutory conditions for CFA issuance.')}</p>
                    </div>
                `;
            }
        }

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
            ${statusBannerHtml}
        `;
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
    const cfaType = document.getElementById('cfaType')?.value || 'KP Form 20';
    const reason = document.getElementById('cfaReasonText')?.value?.trim();

    if (!caseId) {
        setMessage('Please select a case.', false);
        return;
    }

    if (!reason) {
        setMessage('Please specify the ground or findings for issuing this certification.', false);
        return;
    }

    const elig = window.currentCaseCfaEligibility;
    if (elig && !elig.eligible) {
        setMessage(elig.reason || 'Case is not eligible for CFA issuance under KP rules.', false);
        window.agapNotify?.(elig.reason || 'CFA issuance blocked by procedural rules.', 'warning', 'CFA Ineligible');
        return;
    }

    if (elig?.barred_complainant && cfaType !== 'KP Form 20-A') {
        const msg = 'Complainant is barred from action: Only KP Form 20-A can be issued.';
        setMessage(msg, false);
        window.agapNotify?.(msg, 'error', 'Invalid Form Selection');
        return;
    }

    if (!window.confirm(`Are you sure you want to issue ${cfaType} for this case? This action will formally record the certification and generate the official document.`)) {
        return;
    }

    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(form);
        const res = await cfaApi('../../../backend/api/documents/cfa.php', {
            method: 'POST',
            body: formData
        });

        setMessage(res.message || `${cfaType} issued successfully!`, true);
        window.agapNotify?.(res.message || `${cfaType} issued successfully.`, 'success', 'Certificate Issued');
        form.reset();
        await Promise.all([loadCases(), loadCfaRecords()]);
        const preview = document.getElementById('cfaCasePreview');
        if (preview) {
            let printActionHtml = '';
            if (res.pdf_url) {
                printActionHtml = `
                    <div style="margin-top: 10px;">
                        <a href="${escapeHtml(res.pdf_url)}" target="_blank" class="btn-create" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                            Print ${escapeHtml(res.cfa_type || 'CFA Document')}
                        </a>
                    </div>
                `;
            }
            preview.innerHTML = `
                <div style="color: #166534; font-weight: 600;">
                    ${escapeHtml(res.cfa_type || 'Certificate')} was successfully issued.
                </div>
                ${printActionHtml}
            `;
            preview.className = 'document-preview';
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
            table.innerHTML = '<tr><td colspan="6" class="empty-state">No Certificate to File Action (CFA) or Bar records found.</td></tr>';
            return;
        }

        table.innerHTML = records.map((r) => {
            const formType = r.cfa_type || 'KP Form 20';
            const badgeClass = formType === 'KP Form 20-A' ? 'status-barred' : (formType === 'KP Form 20-B' ? 'status-default' : 'status-cfa-issued');
            return `
                <tr>
                    <td><strong>${escapeHtml(r.case_number)}</strong></td>
                    <td>${escapeHtml(r.complaint_title || 'Case ' + r.case_number)}</td>
                    <td><span class="badge ${badgeClass}" style="font-weight: 700; font-size: 0.8rem;">${escapeHtml(formType)}</span></td>
                    <td>${escapeHtml(r.issuance_date)}</td>
                    <td style="max-width: 320px; white-space: normal; font-size: 0.875rem;">${escapeHtml(r.reason)}</td>
                    <td><span class="status status-cfa-issued">Issued</span></td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        table.innerHTML = `<tr><td colspan="6" class="empty-state text-danger">${escapeHtml(err.message)}</td></tr>`;
    }
}

function setMessage(msg, isSuccess = false) {
    if (window.agapNotify && msg) {
        window.agapNotify(msg, isSuccess ? 'success' : 'error', isSuccess ? 'CFA Document' : 'CFA Warning');
        return;
    }
    const el = document.getElementById('cfaMessage');
    if (!el) return;
    el.textContent = msg;
    el.className = msg ? (isSuccess ? 'page-alert page-alert-success' : 'page-alert page-alert-danger') : '';
}
