let currentPage = 1;
const pageSize = 25;
let currentSettlements = [];

document.addEventListener('DOMContentLoaded', async () => {
    await loadSettlements(1);
    await loadCasesForDropdown();
});

async function api(url, options = {}) {
    const res = await fetch(url, options);
    const data = await res.json().catch(() => ({ success: false, message: 'Invalid response from server.' }));
    if (!res.ok || data.success === false) {
        throw new Error(data.message || 'Operation failed.');
    }
    return data;
}

function showAlert(msg, isSuccess = false) {
    const el = document.getElementById('settlementAlert');
    if (!el) return;
    el.textContent = msg;
    el.className = isSuccess ? 'alert alert-success' : 'alert alert-danger';
    el.style.display = 'block';
    setTimeout(() => {
        el.style.display = 'none';
    }, 6000);
}

function showModalAlert(modalAlertId, msg, isSuccess = false) {
    const el = document.getElementById(modalAlertId);
    if (!el) return;
    el.textContent = msg;
    el.className = isSuccess ? 'alert alert-success' : 'alert alert-danger';
    el.style.display = 'block';
}

function clearModalAlert(modalAlertId) {
    const el = document.getElementById(modalAlertId);
    if (el) el.style.display = 'none';
}

function showModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('show');
}

function closeModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('show');
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function formatCurrency(val) {
    return '₱' + parseFloat(val || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

async function loadSettlements(page = 1) {
    currentPage = page;
    const tbody = document.getElementById('settlementsTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="7" class="empty-state">Loading amicable settlements...</td></tr>';

    const q = document.getElementById('filterSearch')?.value.trim() || '';
    const comp = document.getElementById('filterCompliance')?.value || '';
    const rep = document.getElementById('filterRepudiation')?.value || '';

    const params = new URLSearchParams();
    params.set('page', page);
    if (q) params.set('q', q);
    if (comp) params.set('compliance_status', comp);
    if (rep) params.set('repudiation_status', rep);

    try {
        const res = await api(`../../../backend/api/settlements/list.php?${params.toString()}`);
        currentSettlements = res.data || [];
        const pagination = res.pagination || { total_records: currentSettlements.length, per_page: pageSize, current_page: page, total_pages: 1 };

        updateKPIs(currentSettlements);

        if (!currentSettlements.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-state">No amicable settlements found matching criteria.</td></tr>';
            renderPagination(pagination);
            return;
        }

        tbody.innerHTML = currentSettlements.map((item) => renderSettlementRow(item)).join('');
        renderPagination(pagination);
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-state" style="color:#dc2626;">Error: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function updateKPIs(items) {
    const total = items.length;
    let repActive = 0;
    let fullyComplied = 0;
    let overdueBreached = 0;

    items.forEach(it => {
        if (it.repudiation_status === 'Within Repudiation Period') repActive++;
        if (['Fully Paid', 'Complied'].includes(it.compliance_status)) fullyComplied++;
        if (['Overdue', 'Breached', 'Violated'].includes(it.compliance_status)) overdueBreached++;
    });

    document.getElementById('kpiTotalSettlements').textContent = total;
    document.getElementById('kpiRepudiationActive').textContent = repActive;
    document.getElementById('kpiFullyComplied').textContent = fullyComplied;
    document.getElementById('kpiOverdueBreached').textContent = overdueBreached;
}

function renderSettlementRow(item) {
    const id = item.settlement_id;
    const daysLeft = Number(item.repudiation_days_left);

    // Repudiation badge
    let repBadge = '';
    if (item.repudiation_status === 'Repudiated') {
        repBadge = `<span class="badge" style="background:#fee2e2; color:#b91c1c; font-weight:700;">✕ Repudiated</span>`;
    } else if (item.repudiation_status === 'Within Repudiation Period' && daysLeft >= 0) {
        repBadge = `<span class="badge" style="background:#dbeafe; color:#1e40af; font-weight:700;" title="Sec. 418 window closes in ${daysLeft} days">⏳ ${daysLeft}d left (10-Day Window)</span>`;
    } else {
        repBadge = `<span class="badge" style="background:#f1f5f9; color:#475569; font-weight:600;">✓ Enforceable / Expired</span>`;
    }

    // Milestones progress
    let milestonesScore = 0;
    if (Number(item.terms_read_to_parties) === 1) milestonesScore++;
    if (Number(item.complainant_signed) === 1) milestonesScore++;
    if (Number(item.respondent_signed) === 1) milestonesScore++;
    if (Number(item.pb_attested) === 1) milestonesScore++;
    if (Number(item.barangay_sealed) === 1) milestonesScore++;
    if (Number(item.original_in_case_folder) === 1) milestonesScore++;
    if (Number(item.certified_copies_issued) === 1) milestonesScore++;

    const milestoneBadge = milestonesScore === 7
        ? `<span class="badge" style="background:#dcfce7; color:#15803d; font-weight:700;">✓ All 7 Verified</span>`
        : `<span class="badge" style="background:#fef9c3; color:#a16207; font-weight:700;">${milestonesScore}/7 Complete</span>`;

    // Compliance Badge
    let compBadgeClass = 'badge-secondary';
    if (['Fully Paid', 'Complied'].includes(item.compliance_status)) compBadgeClass = 'badge-success';
    else if (['Partially Paid'].includes(item.compliance_status)) compBadgeClass = 'badge-info';
    else if (['Overdue', 'Breached', 'Violated'].includes(item.compliance_status)) compBadgeClass = 'badge-danger';
    const compBadge = `<span class="badge ${compBadgeClass}">${escapeHtml(item.compliance_status)}</span>`;

    // Obligation info
    const amountStr = formatCurrency(item.total_amount);
    const instStr = Number(item.has_installment) === 1
        ? `<br><small style="color:#64748b;">${item.paid_installments || 0}/${item.total_installments || 0} Installments Paid</small>`
        : '';

    return `
        <tr>
            <td>
                <strong>${escapeHtml(item.case_number)}</strong>
                <div style="font-size:0.8rem; color:#64748b;">${escapeHtml(item.complaint_title || item.complaint_number || 'Dispute')}</div>
                <div style="font-size:0.75rem; color:#94a3b8;">${escapeHtml(item.complainant_name || 'Complainant')} vs. ${escapeHtml(item.respondent_name || 'Respondent')}</div>
            </td>
            <td>${formatDate(item.settlement_date)}</td>
            <td>${repBadge}</td>
            <td>${milestoneBadge}</td>
            <td><strong>${amountStr}</strong>${instStr}</td>
            <td>${compBadge}</td>
            <td>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" class="btn-secondary" style="padding:4px 8px; font-size:0.78rem;" onclick="viewSettlementDetails(${id})">Details</button>
                    ${Number(item.has_installment) === 1 && item.compliance_status !== 'Fully Paid' ? `
                    <button type="button" class="btn-create" style="padding:4px 8px; font-size:0.78rem;" onclick="openPaymentModal(${id})">Pay</button>
                    ` : ''}
                    ${item.repudiation_status === 'Within Repudiation Period' ? `
                    <button type="button" class="btn-secondary" style="padding:4px 8px; font-size:0.78rem; color:#dc2626;" onclick="openRepudiationModal(${id})" title="File Sworn Repudiation (within 10 days)">Repudiate</button>
                    ` : ''}
                    ${['Overdue', 'Breached'].includes(item.compliance_status) || (daysLeft < 0 && item.compliance_status !== 'Fully Paid') ? `
                    <button type="button" class="btn-secondary" style="padding:4px 8px; font-size:0.78rem; color:#b91c1c;" onclick="openExecutionModal(${id})" title="File Motion for Execution">Execute</button>
                    ` : ''}
                </div>
            </td>
        </tr>
    `;
}

function resetFilters() {
    const s = document.getElementById('filterSearch');
    const c = document.getElementById('filterCompliance');
    const r = document.getElementById('filterRepudiation');
    if (s) s.value = '';
    if (c) c.value = '';
    if (r) r.value = '';
    loadSettlements(1);
}

function renderPagination(pagination) {
    const container = document.getElementById('settlementPagination');
    const summary = document.getElementById('settlementPaginationSummary');
    const controls = document.getElementById('settlementPaginationControls');
    if (!container || !summary || !controls || !pagination) return;

    const total = Number(pagination.total_records) || 0;
    const limit = Number(pagination.per_page) || pageSize;
    const page = Number(pagination.current_page) || 1;
    const totalPages = Number(pagination.total_pages) || 1;

    if (total === 0) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'flex';
    const first = total > 0 ? ((page - 1) * limit) + 1 : 0;
    const last = Math.min(page * limit, total);
    summary.textContent = `Showing ${first}–${last} of ${total} record${total === 1 ? '' : 's'}`;

    controls.replaceChildren();

    const createBtn = (label, pageNum, disabled = false, current = false) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `complaints-page-btn ${current ? 'active current' : ''}`;
        btn.textContent = label;
        btn.disabled = disabled;
        btn.addEventListener('click', () => {
            if (page !== pageNum && !disabled) {
                loadSettlements(pageNum);
            }
        });
        return btn;
    };

    controls.appendChild(createBtn('Previous', page - 1, page <= 1));
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= page - 2 && i <= page + 2)) {
            controls.appendChild(createBtn(String(i), i, false, page === i));
        }
    }
    controls.appendChild(createBtn('Next', page + 1, page >= totalPages));
}

async function loadCasesForDropdown() {
    const sel = document.getElementById('formCaseId');
    if (!sel) return;
    try {
        const res = await api('../../../backend/api/hearings/calendar.php');
        const cases = res.data || [];
        const seen = new Set();
        sel.innerHTML = '<option value="">Select a case docket</option>';
        cases.forEach(h => {
            if (h.case_id && !seen.has(h.case_id)) {
                seen.add(h.case_id);
                sel.add(new Option(`${h.case_number} - ${h.complaint_title || h.hearing_type}`, h.case_id));
            }
        });
    } catch (e) {
        console.warn('Could not load case calendar list:', e);
    }
}

function updateRepudiationHelpDate(settlementDate) {
    if (!settlementDate) return;
    const d = new Date(settlementDate);
    d.setDate(d.getDate() + 10);
    const deadlineStr = d.toISOString().slice(0, 10);
    const deadInput = document.getElementById('formRepudiationDeadline');
    if (deadInput) deadInput.value = deadlineStr;
}

function handleCaseSelectionChange(caseId) {
    if (!caseId) return;
    updateRepudiationHelpDate(document.getElementById('formSettlementDate')?.value);
}

function handleAmountChange() {
    const hasInst = document.getElementById('formHasInstallment')?.checked;
    if (hasInst) {
        recalculateInstallmentsFromTotal();
    }
}

function toggleInstallmentSchedule(checked) {
    const box = document.getElementById('installmentScheduleContainer');
    if (box) box.style.display = checked ? 'block' : 'none';
    if (checked) {
        const list = document.getElementById('installmentRowsList');
        if (list && list.children.length === 0) {
            addInstallmentRow();
            addInstallmentRow();
        }
    }
}

function addInstallmentRow(dueDate = '', amount = '', notes = '') {
    const container = document.getElementById('installmentRowsList');
    if (!container) return;
    const idx = container.children.length + 1;

    const row = document.createElement('div');
    row.className = 'installment-row';
    row.style = 'display:flex; gap:10px; align-items:center; margin-bottom:8px;';
    row.innerHTML = `
        <span style="font-size:0.8rem; font-weight:700; color:#64748b; width:24px;">#${idx}</span>
        <input type="date" class="inst-due-date" style="flex:1; padding:6px 8px; border:1px solid #cbd5e1; border-radius:4px;" value="${dueDate}" required>
        <input type="number" step="0.01" min="0.01" class="inst-amount" style="width:130px; padding:6px 8px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="Amount (₱)" value="${amount}" required>
        <input type="text" class="inst-notes" style="flex:1; padding:6px 8px; border:1px solid #cbd5e1; border-radius:4px;" placeholder="Notes (e.g. 1st Tranche)" value="${notes}">
        <button type="button" style="background:#fee2e2; color:#b91c1c; border:none; border-radius:4px; padding:6px 10px; cursor:pointer;" onclick="this.parentElement.remove(); renumberInstallmentRows();">&times;</button>
    `;
    container.appendChild(row);
}

function renumberInstallmentRows() {
    const container = document.getElementById('installmentRowsList');
    if (!container) return;
    Array.from(container.children).forEach((row, i) => {
        const span = row.querySelector('span');
        if (span) span.textContent = `#${i + 1}`;
    });
}

function recalculateInstallmentsFromTotal() {
    const total = parseFloat(document.getElementById('formTotalAmount')?.value || 0);
    const rows = document.querySelectorAll('#installmentRowsList .installment-row');
    if (total > 0 && rows.length > 0) {
        const perRow = (total / rows.length).toFixed(2);
        rows.forEach(r => {
            const amtInput = r.querySelector('.inst-amount');
            if (amtInput && !amtInput.value) amtInput.value = perRow;
        });
    }
}

function openNewSettlementModal() {
    clearModalAlert('settlementFormAlert');
    document.getElementById('formSettlementId').value = '';
    document.getElementById('formSettlementDate').value = new Date().toISOString().slice(0, 10);
    updateRepudiationHelpDate(new Date().toISOString().slice(0, 10));
    document.getElementById('formAgreementDetails').value = '';
    document.getElementById('formTotalAmount').value = '0.00';
    document.getElementById('formHasInstallment').checked = false;
    toggleInstallmentSchedule(false);

    // Reset checklist
    ['m_terms_read', 'm_comp_signed', 'm_resp_signed', 'm_pb_attested', 'm_sealed', 'm_case_folder', 'm_copies_issued'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.checked = false;
    });

    document.getElementById('settlementModalTitle').textContent = 'Record Amicable Settlement (KP Form 16)';
    showModal('settlementModal');
}

async function handleSaveSettlement(event) {
    event.preventDefault();
    clearModalAlert('settlementFormAlert');

    const form = document.getElementById('settlementForm');
    const caseId = form.case_id.value;
    const settlementDate = form.settlement_date.value;
    const agreementDetails = form.agreement_details.value;
    const totalAmount = form.total_amount.value;
    const responsibleParty = form.responsible_party.value;
    const complianceDueDate = form.compliance_due_date.value;
    const hasInstallment = form.has_installment.checked ? 1 : 0;

    const installments = [];
    if (hasInstallment) {
        const rows = document.querySelectorAll('#installmentRowsList .installment-row');
        rows.forEach((r, idx) => {
            const due = r.querySelector('.inst-due-date')?.value;
            const amt = r.querySelector('.inst-amount')?.value;
            const notes = r.querySelector('.inst-notes')?.value;
            if (due && amt) {
                installments.push({ installment_number: idx + 1, due_date: due, amount_due: amt, notes: notes });
            }
        });
    }

    const payload = {
        case_id: caseId,
        settlement_date: settlementDate,
        agreement_details: agreementDetails,
        total_amount: totalAmount,
        responsible_party: responsibleParty,
        compliance_due_date: complianceDueDate,
        has_installment: hasInstallment,
        installments: installments,
        terms_read_to_parties: document.getElementById('m_terms_read').checked ? 1 : 0,
        complainant_signed: document.getElementById('m_comp_signed').checked ? 1 : 0,
        respondent_signed: document.getElementById('m_resp_signed').checked ? 1 : 0,
        pb_attested: document.getElementById('m_pb_attested').checked ? 1 : 0,
        barangay_sealed: document.getElementById('m_sealed').checked ? 1 : 0,
        original_in_case_folder: document.getElementById('m_case_folder').checked ? 1 : 0,
        certified_copies_issued: document.getElementById('m_copies_issued').checked ? 1 : 0,
    };

    try {
        const res = await api('../../../backend/api/settlements/save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        showAlert(res.message, true);
        closeModal('settlementModal');
        await loadSettlements(currentPage);
    } catch (err) {
        showModalAlert('settlementFormAlert', err.message);
    }
}

async function viewSettlementDetails(id) {
    const body = document.getElementById('viewSettlementBody');
    if (!body) return;
    body.innerHTML = '<div style="text-align:center; padding:20px; color:#64748b;">Loading settlement details...</div>';
    showModal('viewSettlementModal');

    try {
        const res = await api(`../../../backend/api/settlements/view.php?id=${encodeURIComponent(id)}`);
        const item = res.data;

        const installmentsList = (item.installments || []).length
            ? item.installments.map(ins => `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:6px;">
                    <div>
                        <strong>Tranche #${ins.installment_number}</strong> — Due: ${formatDate(ins.due_date)}
                        <br><span style="font-size:0.8rem; color:#64748b;">${ins.notes ? escapeHtml(ins.notes) : ''}</span>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-weight:700;">${formatCurrency(ins.amount_due)}</span>
                        <br><span class="badge ${ins.payment_status === 'Paid' ? 'badge-success' : (ins.payment_status === 'Overdue' ? 'badge-danger' : 'badge-warning')}">${escapeHtml(ins.payment_status)}</span>
                        ${ins.receipt_number ? `<span style="font-size:0.75rem; color:#475569;"><br>OR: ${escapeHtml(ins.receipt_number)}</span>` : ''}
                    </div>
                </div>
            `).join('')
            : '<div style="color:#64748b; font-size:0.85rem;">No separate installment tranches defined (lump-sum commitment).</div>';

        const executionsList = (item.executions || []).length
            ? item.executions.map(ex => `
                <div style="padding:10px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; margin-bottom:8px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <strong style="color:#b91c1c;">Motion for Execution (Sec. 417)</strong>
                        <span class="badge badge-danger">${escapeHtml(ex.execution_status)}</span>
                    </div>
                    <div style="font-size:0.84rem; color:#1e293b; margin-top:4px;">
                        <strong>Violated Obligation:</strong> ${escapeHtml(ex.obligation_violated)}
                    </div>
                    <div style="font-size:0.8rem; color:#64748b; margin-top:2px;">
                        Remedy: ${escapeHtml(ex.amount_or_requirement)} · Filed: ${formatDate(ex.motion_date)} by ${escapeHtml(ex.motion_filed_by_name || 'Party')}
                    </div>
                </div>
            `).join('')
            : '<div style="color:#64748b; font-size:0.85rem;">No execution motions filed.</div>';

        body.innerHTML = `
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                <div style="background:#f8fafc; padding:12px 16px; border-radius:8px; border:1px solid #e2e8f0;">
                    <div style="font-size:0.78rem; font-weight:700; color:#64748b; text-transform:uppercase;">Case Docket</div>
                    <div style="font-size:1.1rem; font-weight:700; color:#1e293b; margin-top:2px;">${escapeHtml(item.case_number)}</div>
                    <div style="font-size:0.85rem; color:#475569; margin-top:4px;">${escapeHtml(item.complaint_title)}</div>
                    <div style="font-size:0.8rem; color:#64748b; margin-top:6px;">
                        Complainant: <strong>${escapeHtml(item.complainant_name)}</strong><br>
                        Respondent: <strong>${escapeHtml(item.respondent_name)}</strong>
                    </div>
                </div>
                <div style="background:#eff6ff; padding:12px 16px; border-radius:8px; border:1px solid #bfdbfe;">
                    <div style="font-size:0.78rem; font-weight:700; color:#1e40af; text-transform:uppercase;">10-Day Repudiation Tracker</div>
                    <div style="font-size:1rem; font-weight:700; color:#1e3a8a; margin-top:2px;">${escapeHtml(item.repudiation_status)}</div>
                    <div style="font-size:0.84rem; color:#1d4ed8; margin-top:4px;">
                        Settlement Date: ${formatDate(item.settlement_date)}<br>
                        Repudiation Deadline: ${formatDate(item.repudiation_deadline)}
                        ${Number(item.repudiation_days_left) >= 0 ? `<br><strong>⏳ ${item.repudiation_days_left} calendar days remaining to repudiate</strong>` : '<br><strong>✓ Repudiation window has elapsed. Enforceable by execution.</strong>'}
                    </div>
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <h4 style="margin:0 0 6px; font-size:0.9rem; color:#1e293b;">Mutual Terms &amp; Settlement Language</h4>
                <div style="background:#fff; border:1px solid #cbd5e1; border-radius:6px; padding:12px 14px; font-size:0.88rem; color:#334155; line-height:1.6; max-height:160px; overflow-y:auto;">
                    ${escapeHtml(item.agreement_details)}
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <h4 style="margin:0 0 6px; font-size:0.9rem; color:#1e293b;">Milestone Verifications (Section H Checklist)</h4>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:0.82rem; color:#334155;">
                    <div>${Number(item.terms_read_to_parties) === 1 ? '✅' : '❌'} Terms read &amp; explained</div>
                    <div>${Number(item.complainant_signed) === 1 ? '✅' : '❌'} Complainant signed</div>
                    <div>${Number(item.respondent_signed) === 1 ? '✅' : '❌'} Respondent signed</div>
                    <div>${Number(item.pb_attested) === 1 ? '✅' : '❌'} PB Attestation (${escapeHtml(item.attested_by_name || 'PB')})</div>
                    <div>${Number(item.barangay_sealed) === 1 ? '✅' : '❌'} Barangay Seal Affixed</div>
                    <div>${Number(item.original_in_case_folder) === 1 ? '✅' : '❌'} Original filed in case folder</div>
                    <div>${Number(item.certified_copies_issued) === 1 ? '✅' : '❌'} Certified copies issued to parties</div>
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <h4 style="margin:0 0 6px; font-size:0.9rem; color:#1e293b;">Installment Schedule &amp; Payments (Section I)</h4>
                ${installmentsList}
            </div>

            <div>
                <h4 style="margin:0 0 6px; font-size:0.9rem; color:#1e293b;">Execution &amp; Enforcement Records (Section K)</h4>
                ${executionsList}
            </div>
        `;
    } catch (err) {
        body.innerHTML = `<div style="color:#dc2626; padding:20px;">Error: ${escapeHtml(err.message)}</div>`;
    }
}

async function openPaymentModal(settlementId) {
    clearModalAlert('paymentFormAlert');
    showModal('paymentModal');
    const summary = document.getElementById('payInstallmentSummary');
    summary.innerHTML = 'Loading tranches...';

    try {
        const res = await api(`../../../backend/api/settlements/view.php?id=${encodeURIComponent(settlementId)}`);
        const s = res.data;
        const unpaid = (s.installments || []).find(i => i.payment_status !== 'Paid');
        if (!unpaid) {
            summary.innerHTML = '<span style="color:#166534;">All installments are already paid in full!</span>';
            return;
        }

        document.getElementById('payInstallmentId').value = unpaid.installment_id;
        document.getElementById('payAmount').value = unpaid.amount_due;
        document.getElementById('payDate').value = new Date().toISOString().slice(0, 16);
        document.getElementById('payReceipt').value = '';
        document.getElementById('payNotes').value = '';

        summary.innerHTML = `
            <strong>Case ${escapeHtml(s.case_number)}:</strong> Tranche #${unpaid.installment_number} due on <strong>${formatDate(unpaid.due_date)}</strong>
            <br>Amount due: <strong>${formatCurrency(unpaid.amount_due)}</strong>
        `;
    } catch (err) {
        summary.innerHTML = `<span style="color:#dc2626;">Error loading tranche: ${escapeHtml(err.message)}</span>`;
    }
}

async function handleRecordPayment(event) {
    event.preventDefault();
    clearModalAlert('paymentFormAlert');

    const form = document.getElementById('paymentForm');
    const installmentId = form.installment_id.value;
    const amountPaid = form.amount_paid.value;
    const paymentDate = form.payment_date.value;
    const receiptNumber = form.receipt_number.value;
    const notes = form.notes.value;

    try {
        const res = await api('../../../backend/api/settlements/payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                installment_id: installmentId,
                amount_paid: amountPaid,
                payment_date: paymentDate,
                receipt_number: receiptNumber,
                notes: notes
            })
        });

        showAlert(res.message, true);
        closeModal('paymentModal');
        await loadSettlements(currentPage);
    } catch (err) {
        showModalAlert('paymentFormAlert', err.message);
    }
}

async function openRepudiationModal(settlementId) {
    clearModalAlert('repudiationFormAlert');
    document.getElementById('repSettlementId').value = settlementId;
    document.getElementById('repReason').value = '';
    document.getElementById('repDocPath').value = '';
    showModal('repudiationModal');

    const sel = document.getElementById('repParty');
    sel.innerHTML = '<option value="">Loading parties...</option>';

    try {
        const res = await api(`../../../backend/api/settlements/view.php?id=${encodeURIComponent(settlementId)}`);
        const s = res.data;
        sel.innerHTML = '<option value="">Select party filing repudiation</option>';
        if (s.case_id) {
            // Load parties for this case
            const pRes = await api(`../../../backend/api/hearings/attendance.php?hearing_id=1&case_id=${encodeURIComponent(s.case_id)}`).catch(() => null);
            // Fallback options
            sel.add(new Option(`Respondent: ${s.respondent_name || 'Respondent'}`, s.respondent_id || '2'));
            sel.add(new Option(`Complainant: ${s.complainant_name || 'Complainant'}`, s.complainant_id || '1'));
        }
    } catch (e) {
        sel.innerHTML = '<option value="1">Complainant / Respondent</option>';
    }
}

async function handleFileRepudiation(event) {
    event.preventDefault();
    clearModalAlert('repudiationFormAlert');

    const form = document.getElementById('repudiationForm');
    const settlementId = form.settlement_id.value;
    const repudiatedBy = form.repudiated_by.value;
    const repudiationReason = form.repudiation_reason.value;
    const supportingDoc = form.supporting_document_path.value;

    if (!confirm('Are you sure you want to file a sworn statement of repudiation? This will invalidate the settlement and reopen proceedings.')) {
        return;
    }

    try {
        const res = await api('../../../backend/api/settlements/repudiate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                settlement_id: settlementId,
                repudiated_by: repudiatedBy,
                repudiation_reason: repudiationReason,
                supporting_document_path: supportingDoc
            })
        });

        showAlert(res.message, true);
        closeModal('repudiationModal');
        await loadSettlements(currentPage);
    } catch (err) {
        showModalAlert('repudiationFormAlert', err.message);
    }
}

async function openExecutionModal(settlementId) {
    clearModalAlert('executionFormAlert');
    document.getElementById('execSettlementId').value = settlementId;
    document.getElementById('execObligation').value = '';
    document.getElementById('execAmount').value = '';
    document.getElementById('execNotes').value = '';
    showModal('executionModal');

    const sel = document.getElementById('execParty');
    sel.innerHTML = '<option value="">Loading parties...</option>';

    try {
        const res = await api(`../../../backend/api/settlements/view.php?id=${encodeURIComponent(settlementId)}`);
        const s = res.data;
        sel.innerHTML = '<option value="">Select party filing execution motion</option>';
        sel.add(new Option(`Complainant: ${s.complainant_name || 'Complainant'}`, s.complainant_id || '1'));
        sel.add(new Option(`Respondent: ${s.respondent_name || 'Respondent'}`, s.respondent_id || '2'));
    } catch (e) {
        sel.innerHTML = '<option value="1">Complainant / Moving Party</option>';
    }
}

async function handleFileExecution(event) {
    event.preventDefault();
    clearModalAlert('executionFormAlert');

    const form = document.getElementById('executionForm');
    const settlementId = form.settlement_id.value;
    const motionFiledBy = form.motion_filed_by.value;
    const obligation = form.obligation_violated.value;
    const amountOrReq = form.amount_or_requirement.value;
    const notes = form.evidence_notes.value;

    try {
        const res = await api('../../../backend/api/settlements/execute.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                settlement_id: settlementId,
                motion_filed_by: motionFiledBy,
                obligation_violated: obligation,
                amount_or_requirement: amountOrReq,
                evidence_notes: notes
            })
        });

        showAlert(res.message, true);
        closeModal('executionModal');
        await loadSettlements(currentPage);
    } catch (err) {
        showModalAlert('executionFormAlert', err.message);
    }
}
